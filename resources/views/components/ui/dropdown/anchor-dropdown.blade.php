@props([
  'align'             => 'right',
  'width'             => '48',
  'contentClasses'    => 'py-1 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-white',
  'dropdownClasses'   => '',
  'offset'            => 8,
  'overlay'           => false,
  'trap'              => false,
  'scrollOnOpen'      => false,
  'scrollOnTrigger'   => false,
  'headerOffset'      => 0,
  'matchTriggerWidth' => false,
  'triggerClasses'    => 'inline-flex',
  'contentRole'       => 'menu',
  'dropdownId'        => null,
  'layerGroup'        => null,
])

@php
  $widthClass = match((string) $width) {
    '40', 'w-40' => 'w-40',
    '48', 'w-48' => 'w-48',
    '56', 'w-56' => 'w-56',
    '64', 'w-64' => 'w-64',
    '72', 'w-72' => 'w-72',
    '80', 'w-80' => 'w-80',
    '96', 'w-96' => 'w-96',
    'auto', 'w-auto' => 'w-auto',
    'min', 'w-min' => 'w-min',
    'max', 'w-max' => 'w-max',
    'full', 'w-full' => 'w-full',
    default => 'w-48',
  };
  $matchesTriggerWidth = (bool) $matchTriggerWidth || $widthClass === 'w-full';
  // A teleported fixed panel cannot use Tailwind's viewport-relative w-full.
  // Its exact width is assigned from the trigger after Alpine anchored it.
  $panelWidthClass = $matchesTriggerWidth ? 'w-auto' : $widthClass;
  $anchorPlacement = match((string) $align) {
    'left' => 'bottom-start',
    'top' => 'top-end',
    default => 'bottom-end',
  };
  $anchorOffset = max(0, (int) $offset);
  $anchorCaretX = str_ends_with($anchorPlacement, '-start')
    ? '1.75rem'
    : 'calc(100% - 1.75rem)';
  $anchorConnectorSize = max(6, $anchorOffset + 2);
  $requestedDropdownId = trim((string) $dropdownId);
  $safeDropdownId = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $requestedDropdownId), '-');
  $resolvedDropdownId = filled($safeDropdownId)
    ? 'rt-dropdown-'.$safeDropdownId
    : 'rt-dropdown-'.\Illuminate\Support\Str::uuid();
  $dropdownPanelId = $resolvedDropdownId.'-content';
  $resolvedLayerGroup = trim((string) $layerGroup);
@endphp

<div
  {{ $attributes->class('relative inline-flex') }}
  data-rt-dropdown-root
  data-rt-dropdown-id="{{ $resolvedDropdownId }}"
  x-data="{
    open: false,
    placement: 'bottom',
    positionFrame: null,
    positionObserver: null,
    positionMutationObserver: null,
    positionListener: null,
    scrollListener: null,
    panelLayer: 180,
    layerGroup: @js($resolvedLayerGroup),
    layerId: @js($resolvedDropdownId),
    horizontalAlign: @js(str_ends_with($anchorPlacement, '-start') ? 'left' : 'right'),
    preferredPlacement: @js(str_starts_with($anchorPlacement, 'top') ? 'top' : 'bottom'),
    offset: @js($anchorOffset),
    scrollOnOpen: @js((bool) $scrollOnOpen),
    scrollOnTrigger: @js((bool) $scrollOnTrigger),
    headerOffset: @js((int) $headerOffset),
    matchTriggerWidth: @js($matchesTriggerWidth),

    init() {
      this.$watch('open', (isOpen) => {
        this.syncTriggerAccessibility();

        if (!isOpen) {
          this.stopPositionTracking();
          return;
        }

        this.$nextTick(() => {
          this.startPositionTracking();

          if (this.$refs.panelScroll) {
            this.$refs.panelScroll.scrollTo({ top: 0, behavior: 'auto' });
          }

          if (this.scrollOnOpen) {
            this.scrollOnTrigger ? this.scrollToTrigger() : this.scrollPanelCentered();
          }
        });
      });

      this.$nextTick(() => this.syncTriggerAccessibility());
    },

    destroy() {
      this.stopPositionTracking();
    },

    clamp(value, minimum, maximum) {
      return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
    },

    toggle() {
      if (this.open) {
        this.close();
        return;
      }

      if (this.$refs.panel) {
        this.$refs.panel.style.visibility = 'hidden';
      }
      this.open = true;
      this.$dispatch('dropdown-open');
      if (this.layerGroup) {
        this.$dispatch('rt-topbar-layer-open', {
          group: this.layerGroup,
          id: this.layerId,
        });
      }
    },

    close(restoreFocus = false) {
      if (!this.open) return;

      this.$refs.panel
        ?.querySelectorAll('[data-rt-dropdown-root]')
        .forEach((dropdown) => {
          dropdown.dispatchEvent(new CustomEvent('rt-dropdown-parent-close'));
        });

      this.open = false;
      this.stopPositionTracking();

      if (restoreFocus) {
        this.$nextTick(() => {
          const control = this.$refs.trigger?.querySelector('button, a, [role=button]');
          control?.focus({ preventScroll: true });
        });
      }
    },

    schedulePosition(panel = this.$refs.panel) {
      if (!this.open || !panel) return;

      if (this.positionFrame !== null) {
        window.cancelAnimationFrame(this.positionFrame);
      }

      this.positionFrame = window.requestAnimationFrame(() => {
        this.positionFrame = null;
        this.syncAnchoredPanel(panel);
      });
    },

    startPositionTracking() {
      this.stopPositionTracking();

      const panel = this.$refs.panel;
      const trigger = this.$refs.trigger;
      if (!this.open || !panel || !trigger) return;

      this.positionListener = () => this.schedulePosition(panel);
      this.scrollListener = (event) => this.handleTrackedScroll(event);

      window.addEventListener('scroll', this.scrollListener, true);
      window.addEventListener('resize', this.positionListener, { passive: true });
      window.visualViewport?.addEventListener('scroll', this.scrollListener, { passive: true });
      window.visualViewport?.addEventListener('resize', this.positionListener, { passive: true });

      if (typeof ResizeObserver === 'function') {
        this.positionObserver = new ResizeObserver(() => this.schedulePosition(panel));
        this.positionObserver.observe(trigger);
        this.positionObserver.observe(panel);
      }

      // Livewire kann den eigentlichen Button innerhalb des stabilen
      // Trigger-Wrappers morphen. Nur solange das Dropdown offen ist, werden
      // solche Ersetzungen beobachtet und ARIA plus Geometrie neu gebunden.
      if (typeof MutationObserver === 'function') {
        this.positionMutationObserver = new MutationObserver(() => {
          this.syncTriggerAccessibility();
          this.schedulePosition(panel);
        });
        this.positionMutationObserver.observe(trigger, {
          attributes: true,
          attributeFilter: ['aria-expanded', 'aria-controls', 'aria-haspopup'],
          childList: true,
          subtree: true,
        });
      }

      this.schedulePosition(panel);
    },

    stopPositionTracking() {
      if (this.positionFrame !== null) {
        window.cancelAnimationFrame(this.positionFrame);
        this.positionFrame = null;
      }

      this.positionObserver?.disconnect();
      this.positionObserver = null;

      this.positionMutationObserver?.disconnect();
      this.positionMutationObserver = null;

      if (this.scrollListener) {
        window.removeEventListener('scroll', this.scrollListener, true);
        window.visualViewport?.removeEventListener('scroll', this.scrollListener);
      }

      if (this.positionListener) {
        window.removeEventListener('resize', this.positionListener);
        window.visualViewport?.removeEventListener('resize', this.positionListener);
      }

      this.scrollListener = null;
      this.positionListener = null;
    },

    handleTrackedScroll(event) {
      const target = event.target;
      const panel = this.$refs.panel;

      // Das eigene scrollbare Panel bleibt bedienbar. Teleportierte Kind-
      // Dropdowns zaehlen logisch ebenfalls zum Parent: Scrollt das Kind,
      // bleiben Kind und Parent offen. Scrollt dagegen der Parent-Container,
      // liegt das Ziel ausserhalb des Kind-Panels und nur das Kind schliesst.
      if (
        target instanceof Node
        && (
          panel?.contains(target)
          || (target instanceof Element && this.ownsNestedTeleportedTarget(target))
        )
      ) {
        return;
      }

      this.close();
    },

    syncAnchoredPanel(panel) {
      const trigger = this.$refs.trigger;
      if (!this.open || !trigger || !panel) return;

      this.attachToOverlayPortal(panel);
      this.syncPanelLayer(panel);

      const visualViewport = window.visualViewport;
      const viewportWidth = visualViewport ? visualViewport.width : (document.documentElement.clientWidth || window.innerWidth);
      const viewportHeight = visualViewport ? visualViewport.height : (document.documentElement.clientHeight || window.innerHeight);
      const viewportLeft = Number.isFinite(Number(visualViewport?.offsetLeft))
        ? Number(visualViewport.offsetLeft)
        : 0;
      const viewportTop = Number.isFinite(Number(visualViewport?.offsetTop))
        ? Number(visualViewport.offsetTop)
        : 0;
      const viewportInset = 12;
      const maximumViewportWidth = Math.max(0, viewportWidth - 24);
      const maximumViewportHeight = Math.max(0, viewportHeight - 24);
      const triggerControl = trigger.querySelector('button, a, [role=button]');
      const triggerRect = (triggerControl || trigger).getBoundingClientRect();
      const triggerIsVisible = trigger.isConnected
        && trigger.getClientRects().length > 0
        && triggerRect.width > 0
        && triggerRect.height > 0
        && triggerRect.right > viewportLeft
        && triggerRect.left < viewportLeft + viewportWidth
        && triggerRect.bottom > viewportTop
        && triggerRect.top < viewportTop + viewportHeight;
      if (!triggerIsVisible) {
        this.close();
        return;
      }

      if (this.matchTriggerWidth) {
        const triggerWidth = `${Math.min(triggerRect.width, maximumViewportWidth)}px`;
        if (panel.style.width !== triggerWidth) {
          panel.style.width = triggerWidth;
        }
      }

      const panelScroll = this.$refs.panelScroll;
      panelScroll?.style.removeProperty('max-height');
      const panelRect = panel.getBoundingClientRect();
      // Die Enter-Transition skaliert das Panel kurz auf 98.5 %. Fuer die
      // Viewportgrenzen muss trotzdem die untransformierte Layoutbreite
      // gelten, sonst verliert die fertige Karte rechts einige Pixel Abstand.
      const panelWidth = Math.min(panel.offsetWidth || panelRect.width, maximumViewportWidth);
      const anchoredLeft = this.horizontalAlign === 'left'
        ? triggerRect.left
        : triggerRect.right - panelWidth;
      const triggerCenter = triggerRect.left + (triggerRect.width / 2);
      // Normalerweise bleibt der Indikator deutlich ausserhalb der
      // abgerundeten Eckbereiche. Nur wenn ein randnaher Trigger sonst nicht
      // mehr exakt getroffen werden koennte, darf er bis auf 18px einruecken.
      const preferredCaretInset = Math.min(30, Math.max(18, panelWidth / 2));
      // 14px halten den 10px-Caret sicher ausserhalb des 12px-Eckradius,
      // erlauben bei randnahen mobilen Triggern aber weiterhin eine exakt
      // mittige Verbindung statt eines sichtbar versetzten Indikators.
      const minimumCaretInset = Math.min(14, panelWidth / 2);
      const minimumViewportLeft = viewportLeft + viewportInset;
      const maximumViewportLeft = viewportLeft + viewportWidth - viewportInset - panelWidth;
      let resolvedLeft = this.clamp(
        anchoredLeft,
        minimumViewportLeft,
        maximumViewportLeft,
      );
      const isMobileViewport = window.matchMedia('(max-width: 767.98px)').matches;
      const isWideMobilePanel = isMobileViewport && panelWidth > (viewportWidth * 0.75);

      if (isWideMobilePanel) {
        const centeredLeft = viewportLeft + ((viewportWidth - panelWidth) / 2);
        // So nah wie moeglich an der Viewport-Mitte bleiben. Wenn der Trigger
        // es zulaesst, bleibt der Caret dabei exakt auf dessen Mittelpunkt.
        const minimumCaretLeft = triggerCenter - (panelWidth - minimumCaretInset);
        const maximumCaretLeft = triggerCenter - minimumCaretInset;
        const feasibleMinimumLeft = Math.max(minimumViewportLeft, minimumCaretLeft);
        const feasibleMaximumLeft = Math.min(maximumViewportLeft, maximumCaretLeft);

        resolvedLeft = feasibleMinimumLeft <= feasibleMaximumLeft
          ? this.clamp(centeredLeft, feasibleMinimumLeft, feasibleMaximumLeft)
          : this.clamp(centeredLeft, minimumViewportLeft, maximumViewportLeft);
      }

      const viewportBottom = viewportTop + viewportHeight;
      const belowTop = triggerRect.bottom + this.offset;
      const aboveBottom = triggerRect.top - this.offset;
      const availableBelow = Math.max(0, viewportBottom - viewportInset - belowTop);
      const availableAbove = Math.max(0, aboveBottom - (viewportTop + viewportInset));
      const borderHeight = panelScroll
        ? Math.max(0, panelScroll.offsetHeight - panelScroll.clientHeight)
        : 0;
      const naturalPanelHeight = Math.min(
        (panelScroll?.scrollHeight || panel.offsetHeight || panelRect.height) + borderHeight,
        448,
        maximumViewportHeight,
      );
      let resolvedPlacement = this.preferredPlacement;
      const anchoredSpace = resolvedPlacement === 'top' ? availableAbove : availableBelow;
      const alternateSpace = resolvedPlacement === 'top' ? availableBelow : availableAbove;

      if (naturalPanelHeight > anchoredSpace && alternateSpace > anchoredSpace) {
        resolvedPlacement = resolvedPlacement === 'top' ? 'bottom' : 'top';
      }

      const availableHeight = resolvedPlacement === 'top' ? availableAbove : availableBelow;
      if (panelScroll) {
        panelScroll.style.maxHeight = `${Math.max(0, Math.floor(Math.min(448, availableHeight)))}px`;
      }

      const renderedPanelHeight = Math.min(
        panel.offsetHeight || panel.getBoundingClientRect().height,
        availableHeight,
      );
      const resolvedTop = resolvedPlacement === 'top'
        ? aboveBottom - renderedPanelHeight
        : belowTop;
      const triggerX = triggerCenter - resolvedLeft;
      const availableCaretInset = Math.min(triggerX, panelWidth - triggerX);
      const caretInset = Math.min(
        preferredCaretInset,
        Math.max(minimumCaretInset, availableCaretInset),
      );
      const caretX = this.clamp(
        triggerX,
        caretInset,
        panelWidth - caretInset,
      );

      this.placement = resolvedPlacement;
      Object.assign(panel.style, {
        left: `${resolvedLeft}px`,
        top: `${resolvedTop}px`,
        visibility: '',
      });
      panel.dataset.wideCentered = isWideMobilePanel ? 'true' : 'false';
      panel.style.setProperty('--rt-dropdown-caret-x', `${Math.round(caretX)}px`);
      panel.style.setProperty('--rt-dropdown-connector-size', `${Math.max(6, this.offset + 2)}px`);
      panel.style.removeProperty('visibility');
    },

    numericLayer(element) {
      if (!(element instanceof Element)) return null;

      const declared = Number.parseInt(element.dataset.rtOverlayBase || '', 10);
      if (Number.isFinite(declared)) return declared;

      const inlineLayer = Number.parseInt(element.style.zIndex || '', 10);
      if (Number.isFinite(inlineLayer)) return inlineLayer;

      const computedLayer = Number.parseInt(window.getComputedStyle(element).zIndex || '', 10);
      return Number.isFinite(computedLayer) ? computedLayer : null;
    },

    owningOverlay() {
      return this.$root.closest('[data-rt-overlay-layer]');
    },

    attachToOverlayPortal(panel) {
      const overlayRoot = this.owningOverlay();
      const portal = overlayRoot?.querySelector(':scope > [data-rt-overlay-portal]');

      if (portal && panel.parentElement !== portal) {
        portal.appendChild(panel);
        panel.dataset.rtDropdownPortaled = 'true';
      }
    },

    syncPanelLayer(panel) {
      const parentPanel = this.$root.closest('[data-rt-dropdown-panel]');
      const overlayRoot = this.owningOverlay();
      const parentLayer = this.numericLayer(parentPanel);
      const overlayLayer = this.numericLayer(overlayRoot);
      const contextualLayer = Math.max(
        170,
        Number.isFinite(parentLayer) ? parentLayer : 0,
        Number.isFinite(overlayLayer) ? overlayLayer : 0,
      );

      this.panelLayer = contextualLayer > 170 ? contextualLayer + 10 : 180;
      panel.style.zIndex = String(this.panelLayer);
      this.$refs.overlay?.style.setProperty('z-index', String(this.panelLayer - 1));
    },

    handleLayerOpen(event) {
      if (
        !this.layerGroup
        || event.detail?.group !== this.layerGroup
        || event.detail?.id === this.layerId
      ) {
        return;
      }

      // Beim Wechsel zwischen Topbar-Layern darf die 150-ms-Leave-Transition
      // den neu geoeffneten Layer weder ueberdecken noch Klicks abfangen.
      this.$refs.panel?.style.setProperty('display', 'none');
      this.close();
    },

    handleWindowEscape(event) {
      const target = event.target instanceof Element ? event.target : null;
      if (!target?.closest('[data-rt-dropdown-root], [data-rt-dropdown-panel]')) {
        this.close();
      }
    },

    handleOutsideClick(event) {
      const target = event.target;
      if (
        !this.$refs.trigger?.contains(target)
        && !this.ownsNestedTeleportedTarget(target)
      ) {
        this.close();
      }
    },

    ownsNestedTeleportedTarget(target) {
      if (!(target instanceof Element) || !this.$refs.panel) return false;

      let dropdownPanel = target.closest('[data-rt-dropdown-panel][data-rt-dropdown-owner]');
      const visitedOwners = new Set();

      while (dropdownPanel) {
        const ownerId = dropdownPanel.dataset.rtDropdownOwner;
        if (!ownerId || visitedOwners.has(ownerId)) return false;

        visitedOwners.add(ownerId);

        const ownerRoot = Array.from(document.querySelectorAll('[data-rt-dropdown-root]'))
          .find((dropdown) => dropdown.dataset.rtDropdownId === ownerId);

        if (!ownerRoot) return false;
        if (this.$refs.panel.contains(ownerRoot)) return true;

        dropdownPanel = ownerRoot.closest('[data-rt-dropdown-panel][data-rt-dropdown-owner]');
      }

      return false;
    },

    handlePanelAction(event) {
      if (!(event.target instanceof Element)) return;

      const action = event.target.closest('a, button, [role=menuitem]');
      if (!action) return;

      const nestedDropdown = action.closest('[data-rt-dropdown-root]');
      if (nestedDropdown && nestedDropdown.dataset.rtDropdownId !== @js($resolvedDropdownId)) return;

      this.close();
    },

    syncTriggerAccessibility() {
      const trigger = this.$refs.trigger;
      if (!trigger) return;

      const control = trigger.querySelector('button, a, [role=button]');
      if (!control) return;

      const expanded = this.open.toString();
      if (control.getAttribute('aria-expanded') !== expanded) {
        control.setAttribute('aria-expanded', expanded);
      }
      if (!control.hasAttribute('aria-controls')) {
        control.setAttribute('aria-controls', @js($dropdownPanelId));
      }
      if (!control.hasAttribute('aria-haspopup')) {
        control.setAttribute('aria-haspopup', @js($contentRole === 'dialog' ? 'dialog' : 'menu'));
      }
    },

    scrollToTrigger() {
      const trigger = this.$refs.trigger;
      if (!trigger) return;

      const y = trigger.getBoundingClientRect().top + window.scrollY - this.headerOffset;
      window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    },

    scrollPanelCentered() {
      const panel = this.$refs.panel;
      if (!panel) return;

      window.requestAnimationFrame(() => {
        const rect = panel.getBoundingClientRect();
        const centerOffset = (window.innerHeight - rect.height) / 2;
        const target = rect.top + window.scrollY - Math.max(0, this.headerOffset - centerOffset);
        window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
      });
    },
  }"
  x-cloak
  @keydown.escape.window="handleWindowEscape($event)"
  @close.window.stop="close()"
  @rt-navigation:prepare.window="close()"
  @rt-dropdown-parent-close.stop="close()"
  @rt-topbar-layer-open.window="handleLayerOpen($event)"
>
  <div
    class="rt-ui-dropdown-trigger {{ $triggerClasses }}"
    x-ref="trigger"
    data-rt-dropdown-trigger
    @click="toggle()"
    @keydown.escape.stop.prevent="close(true)"
  >
    {{ $trigger }}
  </div>

  @if($overlay)
    <template x-teleport="body">
      <div x-show="open" x-ref="overlay" x-transition.opacity class="fixed inset-0 z-[170] bg-black/40" @click="close()" style="display:none;"></div>
    </template>
  @endif

  <template x-teleport="body">
    <div
      x-show="open"
      x-effect="open && schedulePosition($el)"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-y-1.5 scale-[0.985] opacity-0"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-start="translate-y-0 scale-100 opacity-100"
        x-transition:leave-end="translate-y-1 scale-[0.99] opacity-0"
        x-bind:data-placement="placement"
        data-rt-dropdown-owner="{{ $resolvedDropdownId }}"
        class="rt-viewport-dropdown pointer-events-auto fixed z-[180] {{ $panelWidthClass }} rounded-xl shadow-rt-md {{ $dropdownClasses }}"
        style="display:none; margin:0; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px); --rt-dropdown-caret-x:{{ $anchorCaretX }}; --rt-dropdown-connector-size:{{ $anchorConnectorSize }}px;"
        data-rt-dropdown-panel
        @click.outside="handleOutsideClick($event)"
        @keydown.escape.stop.prevent="close(true)"
        @if($trap) x-trap.inert.noscroll="open" @endif
      x-ref="panel"
    >
        <span
          aria-hidden="true"
          class="rt-ui-dropdown-caret pointer-events-none absolute z-[1]"
          data-rt-dropdown-caret
        ></span>

        <div
          id="{{ $dropdownPanelId }}"
          x-ref="panelScroll"
          @if(filled($contentRole)) role="{{ $contentRole }}" @endif
          class="rt-ui-surface rt-ui-dropdown-panel relative z-[2] max-h-[min(28rem,calc(100dvh-2rem))] overflow-y-auto rounded-xl border border-rt-border shadow-rt-md dark:border-rt-dark-border {{ $contentClasses }}"
          @click="handlePanelAction($event)"
        >
          {{ $content }}
        </div>
    </div>
  </template>
</div>
