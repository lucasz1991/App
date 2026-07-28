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
  $anchorDirective = 'x-anchor.' . $anchorPlacement . '.offset.' . $anchorOffset . '.fixed';
  $anchorCaretX = str_ends_with($anchorPlacement, '-start')
    ? '1.75rem'
    : 'calc(100% - 1.75rem)';
  $anchorConnectorSize = max(6, $anchorOffset + 2);
  $dropdownId = 'rt-dropdown-'.\Illuminate\Support\Str::uuid();
@endphp

<div
  {{ $attributes->class('relative inline-flex') }}
  data-rt-dropdown-root
  data-rt-dropdown-id="{{ $dropdownId }}"
  x-data="{
    open: false,
    placement: 'bottom',
    hasAnchoredPosition: false,
    lastAnchorX: null,
    lastAnchorY: null,
    offset: @js($anchorOffset),
    scrollOnOpen: @js((bool) $scrollOnOpen),
    scrollOnTrigger: @js((bool) $scrollOnTrigger),
    headerOffset: @js((int) $headerOffset),
    matchTriggerWidth: @js($matchesTriggerWidth),

    init() {
      this.$watch('open', (isOpen) => {
        this.syncTriggerAccessibility();

        if (!isOpen) return;

        this.$nextTick(() => {
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

    clamp(value, minimum, maximum) {
      return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
    },

    toggle() {
      if (this.open) {
        this.close();
        return;
      }

      this.open = true;
      this.$dispatch('dropdown-open');
    },

    close() {
      if (!this.open) return;

      this.$refs.panel
        ?.querySelectorAll('[data-rt-dropdown-root]')
        .forEach((dropdown) => {
          dropdown.dispatchEvent(new CustomEvent('rt-dropdown-parent-close'));
        });

      this.open = false;
    },

    syncAnchoredPanel(panel, anchorX, anchorY) {
      const trigger = this.$refs.trigger;
      if (!this.open || !trigger || !panel) return;

      const numericAnchorX = Number(anchorX);
      const numericAnchorY = Number(anchorY);
      const visualViewport = window.visualViewport;
      const viewportWidth = visualViewport ? visualViewport.width : (document.documentElement.clientWidth || window.innerWidth);
      const maximumViewportWidth = Math.max(0, viewportWidth - 24);
      const triggerControl = trigger.querySelector('button, a, [role=button]');
      const triggerRect = (triggerControl || trigger).getBoundingClientRect();
      const triggerIsVisible = trigger.isConnected
        && trigger.getClientRects().length > 0
        && triggerRect.width > 0
        && triggerRect.height > 0;
      const anchorIsReady = Number.isFinite(numericAnchorX)
        && Number.isFinite(numericAnchorY)
        && (this.hasAnchoredPosition || numericAnchorX !== 0 || numericAnchorY !== 0);

      if (!triggerIsVisible || !anchorIsReady) {
        if (Number.isFinite(this.lastAnchorX) && Number.isFinite(this.lastAnchorY)) {
          panel.style.left = `${this.lastAnchorX}px`;
          panel.style.top = `${this.lastAnchorY}px`;
        } else {
          panel.style.visibility = 'hidden';
        }

        return;
      }

      panel.style.removeProperty('visibility');

      if (this.matchTriggerWidth) {
        const triggerWidth = `${Math.min(triggerRect.width, maximumViewportWidth)}px`;
        if (panel.style.width !== triggerWidth) {
          panel.style.width = triggerWidth;
        }
      }

      const panelRect = panel.getBoundingClientRect();
      const panelWidth = Math.min(panelRect.width, maximumViewportWidth);
      const anchoredLeft = numericAnchorX;
      const anchoredTop = numericAnchorY;
      const triggerCenter = triggerRect.left + (triggerRect.width / 2);
      // Der Indikator bleibt ausserhalb der abgerundeten Eckbereiche.
      const caretInset = Math.min(30, Math.max(18, panelWidth / 2));
      const caretX = this.clamp(triggerCenter - anchoredLeft, caretInset, panelWidth - caretInset);

      this.placement = anchoredTop + panelRect.height <= triggerRect.top + 1 ? 'top' : 'bottom';
      this.hasAnchoredPosition = true;
      this.lastAnchorX = anchoredLeft;
      this.lastAnchorY = anchoredTop;
      panel.style.setProperty('--rt-dropdown-caret-x', `${Math.round(caretX)}px`);
      panel.style.setProperty('--rt-dropdown-connector-size', `${Math.max(6, this.offset + 2)}px`);
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
      if (nestedDropdown && nestedDropdown.dataset.rtDropdownId !== @js($dropdownId)) return;

      this.close();
    },

    syncTriggerAccessibility() {
      const trigger = this.$refs.trigger;
      if (!trigger) return;

      const control = trigger.querySelector('button, a, [role=button]');
      if (control) control.setAttribute('aria-expanded', this.open.toString());
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
  @keydown.escape.window="close()"
  @close.window.stop="close()"
  @rt-dropdown-parent-close.stop="close()"
>
  <div
    class="rt-ui-dropdown-trigger {{ $triggerClasses }}"
    x-ref="trigger"
    data-rt-dropdown-trigger
    @click="toggle()"
  >
    {{ $trigger }}
  </div>

  @if($overlay)
    <template x-teleport="body">
      <div x-show="open" x-transition.opacity class="fixed inset-0 z-[170] bg-black/40" @click="close()" style="display:none;"></div>
    </template>
  @endif

  <template x-teleport="body">
    <div
      x-show="open"
      {!! $anchorDirective !!}="$refs.trigger"
      x-effect="
        if (open) {
          const anchorX = $anchor.x;
          const anchorY = $anchor.y;
          syncAnchoredPanel($el, anchorX, anchorY);
        }
      "
      x-transition:enter="transition duration-200 ease-out"
      x-transition:enter-start="translate-y-1.5 scale-[0.985] opacity-0"
      x-transition:enter-end="translate-y-0 scale-100 opacity-100"
      x-transition:leave="transition duration-150 ease-in"
      x-transition:leave-start="translate-y-0 scale-100 opacity-100"
      x-transition:leave-end="translate-y-1 scale-[0.99] opacity-0"
      x-bind:data-placement="placement"
      data-rt-dropdown-owner="{{ $dropdownId }}"
      class="rt-viewport-dropdown fixed z-[180] {{ $panelWidthClass }} rounded-xl shadow-rt-md {{ $dropdownClasses }}"
      style="display:none; margin:0; max-width:calc(100vw - 24px); max-height:calc(100dvh - 24px); --rt-dropdown-caret-x:{{ $anchorCaretX }}; --rt-dropdown-connector-size:{{ $anchorConnectorSize }}px;"
      data-rt-dropdown-panel
      @click.outside="if (!$refs.trigger.contains($event.target) && !ownsNestedTeleportedTarget($event.target)) close()"
      @if($trap) x-trap.inert.noscroll="open" @endif
      x-ref="panel"
    >
      <span
        aria-hidden="true"
        class="rt-ui-dropdown-caret pointer-events-none absolute z-[1]"
        data-rt-dropdown-caret
      ></span>

      <div
        x-ref="panelScroll"
        role="{{ $contentRole }}"
        class="rt-ui-surface rt-ui-dropdown-panel relative z-[2] max-h-[min(28rem,calc(100dvh-2rem))] overflow-y-auto rounded-xl border border-rt-border shadow-rt-md dark:border-rt-dark-border {{ $contentClasses }}"
        @click="handlePanelAction($event)"
      >
        {{ $content }}
      </div>
    </div>
  </template>
</div>
