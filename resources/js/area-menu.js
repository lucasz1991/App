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
        init() {
            this.menu = this.$el.parentElement;
            const previous = navigationContinuity.get(this.menu.id);
            navigationContinuity.delete(this.menu.id);
            const active = this.activeButton();
            if (previous && Date.now() - previous.at < 2000
                && previous.target === active?.getAttribute('data-toggle-value')
                && Math.abs(previous.menuWidth - this.menu.getBoundingClientRect().width) < 1) {
                Object.assign(this.$el.style, previous.style);
                this.menu.setAttribute('data-area-indicator-ready', '');
                // Commit the previous frame before moving to the new destination.
                this.$el.getBoundingClientRect();
            }
            this.onClick = (event) => {
                if (event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                const button = event.target.closest('[data-multi-toggle-option]');
                if (!button || button.disabled || button.getAttribute('aria-disabled') === 'true') return;
                if (this.menu.id && button.hasAttribute('wire:navigate') && this.$el.style.transform) {
                    navigationContinuity.clear();
                    navigationContinuity.set(this.menu.id, {
                        at: Date.now(), target: button.getAttribute('data-toggle-value'),
                        menuWidth: this.menu.getBoundingClientRect().width,
                        style: { width: this.$el.style.width, height: this.$el.style.height, transform: this.$el.style.transform },
                    });
                }
                this.paint(button);
                // A cancelled or failed native navigation must not leave a false marker.
                clearTimeout(this.restoreTimer);
                this.restoreTimer = setTimeout(() => this.schedule(), 1200);
            };
            this.onRestore = () => this.schedule();
            this.menu.addEventListener('click', this.onClick, true);
            this.menu.addEventListener('scroll', this.onRestore, { passive: true });
            window.addEventListener('resize', this.onRestore);
            document.addEventListener('livewire:navigated', this.onRestore);
            window.addEventListener('railtime:navigation-flush-failed', this.onRestore);
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
        schedule() {
            if (this.destroyed) return;
            if (this.frame !== null) cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                const active = this.activeButton();
                if (active) this.resizeObserver?.observe(active);
                this.paint(active);
            });
        },
        paint(button) {
            if (this.destroyed || !button || button.disabled) {
                this.menu?.removeAttribute('data-area-indicator-ready');
                return;
            }
            const parent = this.menu.getBoundingClientRect();
            const bounds = button.getBoundingClientRect();
            if (!bounds.width || !bounds.height) return;
            this.$el.style.width = bounds.width + 'px';
            this.$el.style.height = bounds.height + 'px';
            this.$el.style.transform = `translate(${bounds.left - parent.left - this.menu.clientLeft + this.menu.scrollLeft}px, ${bounds.top - parent.top - this.menu.clientTop + this.menu.scrollTop}px)`;
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
            document.removeEventListener('livewire:navigated', this.onRestore);
            window.removeEventListener('railtime:navigation-flush-failed', this.onRestore);
            this.menu?.removeAttribute('data-area-indicator-ready');
        },
    };
}
