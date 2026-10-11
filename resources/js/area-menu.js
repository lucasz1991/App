// Visual enhancement only: the native multi-toggle owns navigation, focus and tooltips.
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
            this.onClick = (event) => {
                if (event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                const button = event.target.closest('[data-multi-toggle-option]');
                if (!button || button.disabled || button.getAttribute('aria-disabled') === 'true') return;
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
            this.schedule();
        },
        schedule() {
            if (this.destroyed) return;
            if (this.frame !== null) cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => {
                this.frame = null;
                this.paint(this.menu.querySelector('[data-multi-toggle-option][aria-current="page"], [data-multi-toggle-option][aria-pressed="true"]'));
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
