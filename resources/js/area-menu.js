// Visual enhancement only: the native multi-toggle owns navigation, focus and tooltips.
// A wire:navigate body swap recreates the indicator. Retain only its last
// geometry briefly, never a second selected destination or persistent setting.
const navigationContinuity = new Map();

export function areaMenu() {
    return {
        menu: null,
        observer: null,
        resizeObserver: null,
        frame: null,
        restoreTimer: null,
        destroyed: false,
        onClick: null,
        onRestore: null,
        onResolved: null,
        onNavigating: null,
        ink: null,
        inkSignature: '',
        pendingButton: null,
        sawBusy: false,
        init() {
            this.menu = this.$el.parentElement;
            this.buildInk();
            const previous = navigationContinuity.get(this.menu.id);
            navigationContinuity.delete(this.menu.id);
            const active = this.activeButton();
            if (previous && Date.now() - previous.at < 2000
                && previous.target === active?.getAttribute('data-toggle-value')
                && Math.abs(previous.menuWidth - this.menu.getBoundingClientRect().width) < 1) {
                Object.assign(this.$el.style, previous.style);
                this.ink.style.transform = previous.inkTransform;
                this.menu.setAttribute('data-area-indicator-ready', '');
                // Commit the previous frame before moving to the new destination.
                this.$el.getBoundingClientRect();
            }
            this.onClick = (event) => {
                if (event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                const button = event.target.closest('[data-multi-toggle-option]');
                if (!button || button.disabled || button.getAttribute('aria-disabled') === 'true') return;
                this.pendingButton = button;
                if (this.menu.id && button.hasAttribute('wire:navigate') && this.$el.style.transform) {
                    navigationContinuity.clear();
                    navigationContinuity.set(this.menu.id, {
                        at: Date.now(), target: button.getAttribute('data-toggle-value'),
                        menuWidth: this.menu.getBoundingClientRect().width,
                        style: { width: this.$el.style.width, height: this.$el.style.height, transform: this.$el.style.transform },
                        inkTransform: this.ink.style.transform,
                    });
                }
                this.paint(button);
                // A cancelled or failed native navigation must not leave a false marker.
                clearTimeout(this.restoreTimer);
                this.restoreTimer = setTimeout(() => {
                    if (this.menu.getAttribute('aria-busy') !== 'true') this.pendingButton = null;
                    this.schedule();
                }, 1200);
            };
            this.onRestore = () => {
                const busy = this.menu.getAttribute('aria-busy') === 'true';
                if (this.sawBusy && !busy) this.pendingButton = null;
                this.sawBusy = busy;
                this.schedule();
            };
            this.onResolved = () => { this.pendingButton = null; this.sawBusy = false; this.schedule(); };
            this.onNavigating = () => {
                const saved = navigationContinuity.get(this.menu.id);
                if (!saved) return;
                // Continue from the actual frame at swap time, not the old
                // departure point (which would replay an already-finished slide).
                const bounds = this.$el.getBoundingClientRect();
                const parent = this.menu.getBoundingClientRect();
                const x = bounds.left - parent.left - this.menu.clientLeft + this.menu.scrollLeft;
                const y = bounds.top - parent.top - this.menu.clientTop + this.menu.scrollTop;
                saved.style = { width: bounds.width + 'px', height: bounds.height + 'px', transform: `translate(${x}px, ${y}px)` };
                saved.inkTransform = `translate(${-x}px, ${-y}px)`;
            };
            this.menu.addEventListener('click', this.onClick, true);
            this.menu.addEventListener('scroll', this.onRestore, { passive: true });
            window.addEventListener('resize', this.onRestore);
            document.addEventListener('livewire:navigating', this.onNavigating);
            document.addEventListener('livewire:navigated', this.onResolved);
            window.addEventListener('railtime:navigation-flush-failed', this.onResolved);
            this.observer = new MutationObserver(this.onRestore);
            this.observer.observe(this.menu, { subtree: true, childList: true, attributes: true, attributeFilter: ['aria-current', 'aria-pressed', 'disabled', 'aria-busy'] });
            this.resizeObserver = new ResizeObserver(this.onRestore);
            this.resizeObserver.observe(this.menu);
            if (active) this.resizeObserver.observe(active);
            this.schedule();
        },
        activeButton() {
            return this.menu.querySelector('[data-multi-toggle-option][aria-current="page"], [data-multi-toggle-option][aria-pressed="true"]');
        },
        buildInk() {
            this.ink = document.createElement('span');
            this.ink.className = 'rt-area-menu__ink';
            this.ink.setAttribute('aria-hidden', 'true');
            this.$el.replaceChildren(this.ink);
        },
        updateInk() {
            const parent = this.menu.getBoundingClientRect();
            const buttons = [...this.menu.querySelectorAll('[data-multi-toggle-option]')];
            const visuals = buttons.map(button => [...button.children].filter(child => child.matches('i, svg, .rt-multi-toggle__label')));
            const signature = visuals.map(children => children.map(child => child.outerHTML).join('')).join('\n');
            // Clone only inert visual children, never links, buttons or Alpine
            // controls. The native originals keep focus, ARIA and all actions.
            if (this.ink.children.length !== buttons.length || signature !== this.inkSignature) {
                this.inkSignature = signature;
                this.ink.replaceChildren(...visuals.map(children => {
                    const item = document.createElement('span');
                    item.className = 'rt-area-menu__ink-item';
                    item.setAttribute('aria-hidden', 'true');
                    item.append(...children.map(child => child.cloneNode(true)));
                    return item;
                }));
            }
            buttons.forEach((button, index) => {
                const bounds = button.getBoundingClientRect();
                Object.assign(this.ink.children[index].style, {
                    left: (bounds.left - parent.left - this.menu.clientLeft + this.menu.scrollLeft) + 'px',
                    top: (bounds.top - parent.top - this.menu.clientTop + this.menu.scrollTop) + 'px',
                    width: bounds.width + 'px', height: bounds.height + 'px',
                });
            });
        },
        schedule() {
            if (this.destroyed) return;
            if (this.frame !== null) cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                const active = this.activeButton();
                if (active === this.pendingButton) this.pendingButton = null;
                if (active) this.resizeObserver?.observe(active);
                if (this.pendingButton?.isConnected === false) this.pendingButton = null;
                this.paint(this.pendingButton || active);
            });
        },
        paint(button) {
            if (this.destroyed || !button || (button.disabled && button !== this.pendingButton && this.menu.getAttribute('aria-busy') !== 'true')) {
                this.menu?.removeAttribute('data-area-indicator-ready');
                return;
            }
            const parent = this.menu.getBoundingClientRect();
            const bounds = button.getBoundingClientRect();
            if (!bounds.width || !bounds.height) return;
            this.updateInk();
            const x = bounds.left - parent.left - this.menu.clientLeft + this.menu.scrollLeft;
            const y = bounds.top - parent.top - this.menu.clientTop + this.menu.scrollTop;
            this.$el.style.width = bounds.width + 'px';
            this.$el.style.height = bounds.height + 'px';
            this.$el.style.transform = `translate(${x}px, ${y}px)`;
            // Opposite transforms with the same timing keep ink stationary
            // in menu coordinates, while the moving surface clips its color.
            this.ink.style.transform = `translate(${-x}px, ${-y}px)`;
            this.menu.setAttribute('data-area-indicator-ready', '');
        },
        destroy() {
            this.destroyed = true;
            if (this.frame !== null) cancelAnimationFrame(this.frame);
            clearTimeout(this.restoreTimer);
            this.observer?.disconnect();
            this.resizeObserver?.disconnect();
            this.menu?.removeEventListener('click', this.onClick, true);
            this.menu?.removeEventListener('scroll', this.onRestore);
            window.removeEventListener('resize', this.onRestore);
            document.removeEventListener('livewire:navigating', this.onNavigating);
            document.removeEventListener('livewire:navigated', this.onResolved);
            window.removeEventListener('railtime:navigation-flush-failed', this.onResolved);
            this.menu?.removeAttribute('data-area-indicator-ready');
            this.pendingButton = null;
        },
    };
}
