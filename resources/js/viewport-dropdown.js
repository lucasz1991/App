const clamp = (value, minimum, maximum) => Math.min(Math.max(value, minimum), Math.max(minimum, maximum));

/**
 * Berechnet eine viewport-sichere Dropdown-Position. Die Funktion ist bewusst
 * DOM-unabhaengig, damit Rand-, Flip- und Pfeilpositionen isoliert pruefbar
 * bleiben.
 */
export function calculateViewportDropdownPosition({
    viewportWidth,
    viewportHeight,
    triggerRect,
    panelWidth,
    panelHeight,
    align = 'right',
    offset = 8,
    gutter = 12,
}) {
    const safeViewportWidth = Math.max(0, viewportWidth);
    const safeViewportHeight = Math.max(0, viewportHeight);
    const safeGutter = Math.max(0, Math.min(gutter, safeViewportWidth / 2, safeViewportHeight / 2));
    const safeOffset = Math.max(0, offset);
    const maxWidth = Math.max(0, safeViewportWidth - (safeGutter * 2));
    const width = Math.min(Math.max(0, panelWidth), maxWidth);

    const preferredLeft = align === 'left'
        ? triggerRect.left
        : triggerRect.right - width;
    const left = clamp(preferredLeft, safeGutter, safeViewportWidth - safeGutter - width);

    const spaceBelow = Math.max(0, safeViewportHeight - safeGutter - triggerRect.bottom - safeOffset);
    const spaceAbove = Math.max(0, triggerRect.top - safeGutter - safeOffset);
    const placement = align === 'top'
        ? ((panelHeight <= spaceAbove || spaceAbove >= spaceBelow) ? 'top' : 'bottom')
        : ((panelHeight <= spaceBelow || spaceBelow >= spaceAbove) ? 'bottom' : 'top');
    const availableHeight = placement === 'bottom' ? spaceBelow : spaceAbove;
    const maxHeight = Math.min(
        Math.max(0, safeViewportHeight - (safeGutter * 2)),
        availableHeight,
    );
    const height = Math.min(Math.max(0, panelHeight), maxHeight);
    const preferredTop = placement === 'bottom'
        ? triggerRect.bottom + safeOffset
        : triggerRect.top - safeOffset - height;
    const top = clamp(preferredTop, safeGutter, safeViewportHeight - safeGutter - height);

    const triggerCenter = triggerRect.left + (triggerRect.width / 2);
    const caretInset = Math.min(22, Math.max(8, width / 2));
    const caretX = clamp(triggerCenter - left, caretInset, width - caretInset);

    return { left, top, maxWidth, maxHeight, placement, caretX };
}

let dropdownSequence = 0;

export function registerViewportDropdown(Alpine) {
    Alpine.data('viewportDropdown', (config = {}) => ({
        open: false,
        placement: 'bottom',
        panelId: `rt-dropdown-${++dropdownSequence}`,
        positionFrame: null,
        resizeObserver: null,
        resizeHandler: null,
        scrollHandler: null,
        align: config.align || 'right',
        offset: Number(config.offset ?? 8),
        gutter: Number(config.gutter ?? 12),
        scrollOnOpen: Boolean(config.scrollOnOpen),
        scrollOnTrigger: Boolean(config.scrollOnTrigger),
        headerOffset: Number(config.headerOffset ?? 0),
        matchTriggerWidth: Boolean(config.matchTriggerWidth),

        init() {
            this.resizeHandler = () => this.schedulePosition();
            this.scrollHandler = () => this.schedulePosition();

            window.addEventListener('resize', this.resizeHandler, { passive: true });
            document.addEventListener('scroll', this.scrollHandler, true);

            this.$watch('open', (isOpen) => {
                this.syncTriggerAccessibility();

                if (!isOpen) {
                    this.stopObservingSize();
                    return;
                }

                this.$nextTick(() => {
                    this.positionPanel();
                    this.observeSize();

                    if (this.$refs.panelScroll) {
                        this.$refs.panelScroll.scrollTo({ top: 0, behavior: 'auto' });
                    }

                    if (this.scrollOnOpen) {
                        this.scrollOnTrigger ? this.scrollToTrigger() : this.scrollPanelCentered();
                    }

                    // Schrift- und Bildgroessen koennen sich direkt nach dem
                    // ersten Layout noch aendern. Der zweite Lauf haelt Pfeil
                    // und Panel trotzdem exakt am Trigger.
                    this.schedulePosition();
                });
            });

            this.$nextTick(() => this.syncTriggerAccessibility());
        },

        destroy() {
            window.removeEventListener('resize', this.resizeHandler);
            document.removeEventListener('scroll', this.scrollHandler, true);
            window.cancelAnimationFrame(this.positionFrame);
            this.stopObservingSize();
        },

        toggle() {
            this.open = !this.open;

            if (this.open) {
                this.$dispatch('dropdown-open', { id: this.panelId });
            }
        },

        close() {
            this.open = false;
        },

        schedulePosition() {
            if (!this.open) {
                return;
            }

            window.cancelAnimationFrame(this.positionFrame);
            this.positionFrame = window.requestAnimationFrame(() => this.positionPanel());
        },

        positionPanel() {
            const trigger = this.$refs.trigger;
            const panel = this.$refs.panel;
            const panelScroll = this.$refs.panelScroll;

            if (!this.open || !trigger || !panel) {
                return;
            }

            const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
            const viewportHeight = window.visualViewport?.height
                || document.documentElement.clientHeight
                || window.innerHeight;
            const maximumViewportWidth = Math.max(0, viewportWidth - (this.gutter * 2));
            const maximumViewportHeight = Math.max(0, viewportHeight - (this.gutter * 2));

            panel.style.maxWidth = `${maximumViewportWidth}px`;
            panel.style.maxHeight = `${maximumViewportHeight}px`;
            panel.style.width = this.matchTriggerWidth
                ? `${Math.min(trigger.getBoundingClientRect().width, maximumViewportWidth)}px`
                : '';

            // Eine vorherige mobile Begrenzung vor der Neuberechnung loesen,
            // damit das Menue nach einem Viewportwechsel wieder wachsen darf.
            if (panelScroll) {
                panelScroll.style.maxHeight = '';
            }

            const triggerRect = trigger.getBoundingClientRect();
            const panelRect = panel.getBoundingClientRect();
            const position = calculateViewportDropdownPosition({
                viewportWidth,
                viewportHeight,
                triggerRect,
                panelWidth: panelRect.width,
                panelHeight: panelRect.height,
                align: this.align,
                offset: this.offset,
                gutter: this.gutter,
            });

            this.placement = position.placement;
            panel.style.left = `${Math.round(position.left)}px`;
            panel.style.top = `${Math.round(position.top)}px`;
            panel.style.maxWidth = `${Math.floor(position.maxWidth)}px`;
            panel.style.maxHeight = `${Math.floor(position.maxHeight)}px`;
            panel.style.setProperty('--rt-dropdown-caret-x', `${Math.round(position.caretX)}px`);

            if (panelScroll) {
                panelScroll.style.maxHeight = `${Math.floor(position.maxHeight)}px`;
            }
        },

        observeSize() {
            this.stopObservingSize();

            if (typeof ResizeObserver === 'undefined') {
                return;
            }

            this.resizeObserver = new ResizeObserver(() => this.schedulePosition());
            this.resizeObserver.observe(this.$refs.trigger);
            this.resizeObserver.observe(this.$refs.panel);
        },

        stopObservingSize() {
            this.resizeObserver?.disconnect();
            this.resizeObserver = null;
        },

        syncTriggerAccessibility() {
            const trigger = this.$refs.trigger;

            if (!trigger) {
                return;
            }

            const control = trigger.matches('button, a, [role="button"]')
                ? trigger
                : trigger.querySelector('button, a, [role="button"]');

            if (!control) {
                return;
            }

            control.setAttribute('aria-expanded', this.open.toString());
            control.setAttribute('aria-controls', this.panelId);
        },

        scrollToTrigger() {
            const trigger = this.$refs.trigger;

            if (!trigger) {
                return;
            }

            const y = trigger.getBoundingClientRect().top + window.scrollY - this.headerOffset;
            window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
        },

        scrollPanelCentered() {
            const panel = this.$refs.panel;

            if (!panel) {
                return;
            }

            window.requestAnimationFrame(() => {
                const rect = panel.getBoundingClientRect();
                const centerOffset = (window.innerHeight - rect.height) / 2;
                const target = rect.top + window.scrollY - Math.max(0, this.headerOffset - centerOffset);
                window.scrollTo({ top: Math.max(0, target), behavior: 'smooth' });
            });
        },
    }));
}
