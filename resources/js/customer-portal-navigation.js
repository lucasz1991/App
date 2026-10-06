// One short-lived owner for the portal drawer; no touch/scroll interception.
export function portalNavigation() {
    return {
        open: false,
        mobile: false,
        mediaQuery: null,
        mediaListener: null,
        previousOverflow: null,
        returnTarget: null,
        focusFrame: null,

        init() {
            this.mediaQuery = window.matchMedia('(max-width: 1023px)');
            this.mobile = this.mediaQuery.matches;
            this.mediaListener = (event) => {
                if (event.matches && !this.open && this.$refs.navigation?.contains(document.activeElement)) {
                    this.$root.querySelector('#portal-mobile-menu')?.focus({ preventScroll: true });
                }
                this.mobile = event.matches;
                if (!this.mobile) this.close(false, false);
            };
            this.mediaQuery.addEventListener('change', this.mediaListener);
        },

        toggle(event) {
            if (this.open) return this.close();
            if (!this.mobile) return;
            this.returnTarget = event?.currentTarget || document.activeElement;
            this.previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            this.open = true;
            if (this.$refs.navigation) this.$refs.navigation.inert = false;
            this.$nextTick(() => {
                if (!this.open || !this.mobile) return;
                this.focusFrame = window.requestAnimationFrame(() => {
                    this.focusFrame = null;
                    if (this.open && this.mobile) this.focusable()[0]?.focus({ preventScroll: true });
                });
            });
        },

        close(returnFocus = true, focusContent = true) {
            const wasOpen = this.open;
            if (this.focusFrame !== null) window.cancelAnimationFrame(this.focusFrame);
            this.focusFrame = null;
            // Move focus before aria-hidden/inert closes the drawer. The main
            // surface is released first when navigation targets its heading.
            if (wasOpen && focusContent) {
                const target = returnFocus ? this.returnTarget : this.$root.querySelector('#customer-portal-title');
                if (!returnFocus) {
                    const content = this.$root.querySelector('#customer-portal-content');
                    if (content) content.inert = false;
                }
                if (target?.isConnected) target.focus({ preventScroll: true });
            }
            this.open = false;
            if (this.previousOverflow !== null) {
                document.body.style.overflow = this.previousOverflow;
                this.previousOverflow = null;
            }
        },

        focusable() {
            return [...(this.$refs.navigation?.querySelectorAll('button:not([disabled]), a[href], select:not([disabled]), [tabindex="0"]') || [])]
                .filter((element) => element.getClientRects().length > 0 && !element.closest('[inert]'));
        },

        handleKey(event) {
            if (!this.open || !this.mobile) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.close();
                return;
            }
            if (event.key !== 'Tab') return;
            const buttons = this.focusable();
            if (!buttons.length) return;
            const first = buttons[0];
            const last = buttons[buttons.length - 1];
            if (event.shiftKey && (document.activeElement === first || !buttons.includes(document.activeElement))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !buttons.includes(document.activeElement))) {
                event.preventDefault();
                first.focus();
            }
        },

        destroy() {
            this.close(false, false);
            if (this.mediaListener) this.mediaQuery?.removeEventListener('change', this.mediaListener);
            this.mediaQuery = null;
            this.mediaListener = null;
            this.returnTarget = null;
        },
    };
}
