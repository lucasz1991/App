/** Shared controller for the native anchored dropdown; each Blade instance passes only configuration. */
export function anchorDropdown(config) {
    return {
    open: false,
    pinned: false,
    externalAnchor: null,
    pointerOverTrigger: false,
    pointerOverPanel: false,
    hoverOpenTimer: null,
    hoverCloseTimer: null,
    openOnHover: config.openOnHover,
    hoverOpenDelay: config.hoverOpenDelay,
    hoverCloseDelay: config.hoverCloseDelay,
    placement: 'bottom',
    positionFrame: null,
    positionObserver: null,
    positionMutationObserver: null,
    positionListener: null,
    scrollListener: null,
    panelLayer: 180,
    layerGroup: config.layerGroup,
    layerId: config.layerId,
    anchorSelector: config.anchorSelector,
    horizontalAlign: config.horizontalAlign,
    preferredPlacement: config.preferredPlacement,
    offset: config.offset,
    maximumHeight: config.maximumHeight,
    fixedHeight: config.fixedHeight,
    scrollOnOpen: config.scrollOnOpen,
    scrollOnTrigger: config.scrollOnTrigger,
    headerOffset: config.headerOffset,
    matchTriggerWidth: config.matchTriggerWidth,

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
      this.clearHoverTimers();
      this.clearExternalAnchorAccessibility();
      this.stopPositionTracking();
    },

    clamp(value, minimum, maximum) {
      return Math.min(Math.max(value, minimum), Math.max(minimum, maximum));
    },

    resolvePositionAnchor() {
      if (this.externalAnchor?.isConnected) return this.externalAnchor;
      if (this.anchorSelector && this.$root instanceof Element) {
        const externalAnchor = this.$root.closest(this.anchorSelector);
        if (externalAnchor) return externalAnchor;
      }

      const trigger = this.$refs.trigger;
      return trigger?.querySelector('button, a, [role=button]') || trigger || null;
    },

    clearExternalAnchorAccessibility() {
      if (!this.anchorSelector && !this.externalAnchor) return;

      const externalAnchor = this.resolvePositionAnchor();
      if (externalAnchor?.getAttribute('aria-controls') !== config.panelId) return;

      externalAnchor.removeAttribute('aria-expanded');
      externalAnchor.removeAttribute('aria-controls');
      externalAnchor.removeAttribute('aria-haspopup');
    },

    toggle() {
      this.clearHoverTimers();

      // A click pins an already visible hover preview; a second click closes it.
      if (this.open && this.openOnHover && !this.pinned) {
        this.pinned = true;
        return;
      }

      if (this.open) {
        this.close();
        return;
      }

      this.openDropdown(true);
    },

    openFromAnchor(event) {
      if (event.detail?.id !== this.layerId || !(event.detail.anchor instanceof Element)) return;
      this.clearExternalAnchorAccessibility();
      this.externalAnchor = event.detail.anchor;
      this.openDropdown(true);
      this.$nextTick(() => {
        this.syncTriggerAccessibility();
        this.startPositionTracking();
      });
    },

    focusExternalPanel(event) {
      if (event.detail?.id !== this.layerId || !this.open) return;
      this.$nextTick(() => this.$refs.panel?.querySelector('[data-dropdown-initial-focus]')?.focus({ preventScroll: true }));
    },

    openDropdown(pinned = false) {
      this.pinned = pinned;
      if (this.open) return;

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

    focusHoverPanel() {
      if (!this.openOnHover) return;
      this.clearHoverTimers();
      this.openDropdown(true);
      this.$nextTick(() => {
        this.$refs.panel?.querySelector('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus({ preventScroll: true });
      });
    },

    clearHoverTimers() {
      if (this.hoverOpenTimer !== null) window.clearTimeout(this.hoverOpenTimer);
      if (this.hoverCloseTimer !== null) window.clearTimeout(this.hoverCloseTimer);
      this.hoverOpenTimer = null;
      this.hoverCloseTimer = null;
    },

    enterHover(event, area) {
      // Touch keeps normal click semantics and never opens a ghost preview.
      if (!this.openOnHover || event.pointerType !== 'mouse') return;

      this.clearHoverTimers();
      if (area === 'trigger') this.pointerOverTrigger = true;
      if (area === 'panel') this.pointerOverPanel = true;
      if (this.open || area !== 'trigger') return;

      this.hoverOpenTimer = window.setTimeout(() => {
        this.hoverOpenTimer = null;
        if (this.pointerOverTrigger) this.openDropdown(false);
      }, this.hoverOpenDelay);
    },

    leaveHover(event, area) {
      if (!this.openOnHover || event.pointerType !== 'mouse') return;

      if (area === 'trigger') this.pointerOverTrigger = false;
      if (area === 'panel') this.pointerOverPanel = false;
      this.scheduleHoverClose();
    },

    hasHoverFocus() {
      const active = document.activeElement;
      return Boolean(active && (
        this.$refs.trigger?.contains(active)
        || this.$refs.panel?.contains(active)
        || this.ownsNestedTeleportedTarget(active)
      ));
    },

    retainHoverFocus() {
      if (!this.openOnHover || this.hoverCloseTimer === null) return;
      window.clearTimeout(this.hoverCloseTimer);
      this.hoverCloseTimer = null;
    },

    scheduleHoverClose() {
      if (!this.openOnHover) return;
      this.clearHoverTimers();
      if (this.pinned) return;

      // The panel is teleported and separated from its trigger by an anchor
      // gap. Give the pointer time to cross it, and retain focused controls.
      this.hoverCloseTimer = window.setTimeout(() => {
        this.hoverCloseTimer = null;
        if (!this.pinned && !this.pointerOverTrigger && !this.pointerOverPanel && !this.hasHoverFocus()) {
          this.close();
        }
      }, this.hoverCloseDelay);
    },

    close(restoreFocus = false) {
      this.clearHoverTimers();
      this.pinned = false;
      this.pointerOverTrigger = false;
      this.pointerOverPanel = false;
      if (!this.open) return;

      this.$refs.panel
        ?.querySelectorAll('[data-rt-dropdown-root]')
        .forEach((dropdown) => {
          dropdown.dispatchEvent(new CustomEvent('rt-dropdown-parent-close'));
        });

      this.open = false;
      this.stopPositionTracking();
      this.$dispatch('dropdown-closed', { id: this.layerId });

      if (restoreFocus) {
        this.$nextTick(() => {
          const control = this.resolvePositionAnchor();
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
      const positionAnchor = this.resolvePositionAnchor();
      if (!this.open || !panel || !trigger || !positionAnchor) return;

      this.positionListener = () => this.schedulePosition(panel);
      this.scrollListener = (event) => this.handleTrackedScroll(event);

      window.addEventListener('scroll', this.scrollListener, true);
      window.addEventListener('resize', this.positionListener, { passive: true });
      window.visualViewport?.addEventListener('scroll', this.scrollListener, { passive: true });
      window.visualViewport?.addEventListener('resize', this.positionListener, { passive: true });

      if (typeof ResizeObserver === 'function') {
        this.positionObserver = new ResizeObserver(() => this.schedulePosition(panel));
        this.positionObserver.observe(positionAnchor);
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

      // A timeline can re-snap after Livewire morphs its content. Follow the
      // selected cell while it remains visible instead of dismissing its result.
      const scrollRoot = this.externalAnchor?.closest('[data-rt-dropdown-scroll-root]');
      if (this.externalAnchor?.isConnected && target instanceof Element
        && (target.contains(this.externalAnchor) || scrollRoot?.contains(target))) {
        const anchorRect = this.externalAnchor.getBoundingClientRect();
        const viewportRect = (scrollRoot || target).getBoundingClientRect();
        if (anchorRect.right > viewportRect.left && anchorRect.left < viewportRect.right
          && anchorRect.bottom > viewportRect.top && anchorRect.top < viewportRect.bottom) {
          this.schedulePosition(panel);
          return;
        }
      }

      this.close();
    },

    syncAnchoredPanel(panel) {
      const trigger = this.$refs.trigger;
      const positionAnchor = this.resolvePositionAnchor();
      if (!this.open || !trigger || !positionAnchor || !panel) return;

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
      const triggerRect = positionAnchor.getBoundingClientRect();
      const triggerIsVisible = positionAnchor.isConnected
        && positionAnchor.getClientRects().length > 0
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
      const caret = panel.querySelector('[data-rt-dropdown-caret]');
      const surfaceStyle = window.getComputedStyle(panelScroll || panel);
      const horizontalRadius = (value) => {
        const horizontal = String(value || '').trim().split(/\s+/)[0];
        const radius = Number.parseFloat(horizontal);
        if (!Number.isFinite(radius)) return 16;
        return Math.max(0, horizontal.endsWith('%') ? panelWidth * radius / 100 : radius);
      };
      const caretWidth = caret
        ? (caret.offsetWidth || Number.parseFloat(window.getComputedStyle(caret).width) || 18)
        : 18;
      // Keep the whole caret base on the straight card edge, not just its
      // tip inside the panel. Use both corner pairs so flipping above/below
      // does not shift a mobile panel; viewport-edge anchors may be offset.
      const safeCaretLeft = Math.ceil(Math.max(
        horizontalRadius(surfaceStyle.borderTopLeftRadius),
        horizontalRadius(surfaceStyle.borderBottomLeftRadius),
      ) + caretWidth / 2 + 2);
      const safeCaretRight = Math.ceil(Math.max(
        horizontalRadius(surfaceStyle.borderTopRightRadius),
        horizontalRadius(surfaceStyle.borderBottomRightRadius),
      ) + caretWidth / 2 + 2);
      const minimumCaretLeftInset = Math.min(safeCaretLeft, panelWidth / 2);
      const minimumCaretRightInset = Math.min(safeCaretRight, panelWidth / 2);
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
        const minimumCaretLeft = triggerCenter - (panelWidth - minimumCaretRightInset);
        const maximumCaretLeft = triggerCenter - minimumCaretLeftInset;
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
        this.fixedHeight ? this.maximumHeight : (panelScroll?.scrollHeight || panel.offsetHeight || panelRect.height) + borderHeight,
        this.maximumHeight,
        maximumViewportHeight,
      );
      let resolvedPlacement = this.preferredPlacement;
      const anchoredSpace = resolvedPlacement === 'top' ? availableAbove : availableBelow;
      const alternateSpace = resolvedPlacement === 'top' ? availableBelow : availableAbove;

      if (naturalPanelHeight > anchoredSpace && alternateSpace > anchoredSpace) {
        resolvedPlacement = resolvedPlacement === 'top' ? 'bottom' : 'top';
      }

      const availableHeight = resolvedPlacement === 'top' ? availableAbove : availableBelow;
      // Paged cards need a definite body height. On short viewports they may
      // overlap the anchor, but remain wholly inside the visible viewport.
      // Ordinary dropdowns retain the existing anchor-side height limit.
      const fixedPanelHeight = Math.floor(Math.min(this.maximumHeight, maximumViewportHeight));
      if (panelScroll) {
        panelScroll.style.maxHeight = `${Math.max(0, this.fixedHeight ? fixedPanelHeight : Math.floor(Math.min(this.maximumHeight, availableHeight)))}px`;
        if (this.fixedHeight) panelScroll.style.height = `${fixedPanelHeight}px`;
      }

      const renderedPanelHeight = Math.min(
        panel.offsetHeight || panel.getBoundingClientRect().height,
        this.fixedHeight ? fixedPanelHeight : availableHeight,
      );
      const anchoredTop = resolvedPlacement === 'top'
        ? aboveBottom - renderedPanelHeight
        : belowTop;
      const resolvedTop = this.fixedHeight
        ? this.clamp(anchoredTop, viewportTop + viewportInset, viewportBottom - viewportInset - renderedPanelHeight)
        : anchoredTop;
      const detached = this.fixedHeight && Math.abs(resolvedTop - anchoredTop) > 1;
      caret?.toggleAttribute('hidden', detached || safeCaretLeft + safeCaretRight > panelWidth);
      const triggerX = triggerCenter - resolvedLeft;
      const caretX = this.clamp(
        triggerX,
        minimumCaretLeftInset,
        panelWidth - minimumCaretRightInset,
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

      if (portal) {
        if (panel.parentElement !== portal) {
          portal.appendChild(panel);
          panel.dataset.rtDropdownPortaled = 'true';
        }

        // x-trap.inert kann das noch am body liegende Teleport-Ziel beim
        // Oeffnen des Modals als Geschwister ausblenden. Nach dem Reparenting
        // gehoert das Panel zum Trap und darf fuer Screenreader nicht mehr
        // aria-hidden sein.
        panel.removeAttribute('aria-hidden');
      }

      // Ein optionales Dropdown-Backdrop muss in derselben Stacking-Context
      // wie das Panel liegen. Als body-Geschwister wuerde es mit z-index 199
      // ueber einem Modal (z-index 190) liegen, waehrend das im Modal-Portal
      // gerenderte Panel diese aeussere Ebene nicht ueberholen kann.
      const dropdownOverlay = this.$refs.overlay;
      if (portal && dropdownOverlay && dropdownOverlay.parentElement !== portal) {
        portal.appendChild(dropdownOverlay);
        dropdownOverlay.dataset.rtDropdownPortaled = 'true';
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
      if (target instanceof Element && target.closest('[data-rt-dropdown-keep-open]')) return;
      const positionAnchor = this.resolvePositionAnchor();
      if (
        !this.$refs.trigger?.contains(target)
        && !positionAnchor?.contains(target)
        && !this.ownsNestedTeleportedTarget(target)
      ) {
        this.close();
      }
    },

    ownsNestedTeleportedTarget(target) {
      if (!(target instanceof Element) || !this.$refs.panel) return false;

      let dropdownPanel = target.closest('[data-rt-dropdown-owner]');
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

      // Controls such as an emoji-palette chevron update content inside the
      // current panel and must not be treated as a completed dropdown action.
      if (action.closest('[data-rt-dropdown-keep-open]')) return;

      const nestedDropdown = action.closest('[data-rt-dropdown-root]');
      if (nestedDropdown && nestedDropdown.dataset.rtDropdownId !== config.layerId) return;

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
        control.setAttribute('aria-controls', config.panelId);
      }
      if (!control.hasAttribute('aria-haspopup')) {
        control.setAttribute('aria-haspopup', config.popupRole);
      }

      const positionAnchor = this.resolvePositionAnchor();
      if (positionAnchor && positionAnchor !== control) {
        if (positionAnchor.getAttribute('aria-expanded') !== expanded) {
          positionAnchor.setAttribute('aria-expanded', expanded);
        }
        if (!positionAnchor.hasAttribute('aria-controls')) {
          positionAnchor.setAttribute('aria-controls', config.panelId);
        }
        if (!positionAnchor.hasAttribute('aria-haspopup')) {
          positionAnchor.setAttribute('aria-haspopup', config.popupRole);
        }
      }
    },

    scrollToTrigger() {
      const trigger = this.resolvePositionAnchor();
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
  };
}
