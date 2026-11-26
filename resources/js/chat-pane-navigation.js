// Livewire aktualisiert den sichtbaren Verlauf alle zwei Sekunden. Wird die
// Chat-Wurzel dabei neu initialisiert, darf der bereits verkleinerte
// VisualViewport nicht zur neuen "stabilen" Hoehe werden. Sonst verliert das
// mobile Layout mitten in der Eingabe nach genau einem Poll den Tastaturmodus.
const rtChatViewportState = {
    stableHeight: 0,
    stableWidth: 0,
    keyboardOpen: false,
};

// iOS kann beim Drehen, beim Resume einer installierten PWA und waehrend
// kurzer Tastatur-Uebergaenge fuer einen Frame 0/NaN im VisualViewport
// liefern. Solche Zwischenwerte duerfen die fixed Chatflaeche nicht auf 0px
// zusammenziehen. Der Layout-Viewport ist in diesem Moment der beste
// belastbare Fallback.
function readChatViewport() {
    const visualViewport = window.visualViewport;
    const layoutHeight = Number(window.innerHeight || document.documentElement?.clientHeight || 0);
    const layoutWidth = Number(window.innerWidth || document.documentElement?.clientWidth || 0);
    const visualHeight = Number(visualViewport?.height);
    const visualWidth = Number(visualViewport?.width);
    const visualTop = Number(visualViewport?.offsetTop);

    return {
        height: Number.isFinite(visualHeight) && visualHeight > 0
            ? visualHeight
            : (Number.isFinite(layoutHeight) && layoutHeight > 0 ? layoutHeight : 1),
        width: Number.isFinite(visualWidth) && visualWidth > 0
            ? visualWidth
            : (Number.isFinite(layoutWidth) && layoutWidth > 0 ? layoutWidth : 1),
        top: Number.isFinite(visualTop) ? Math.max(0, visualTop) : 0,
    };
}

// Bei jedem Seitenwechsel neu messen (vorher Top-Level-Listener in app.js).
export function installChatViewportReset() {
    document.addEventListener('livewire:navigating', () => {
        rtChatViewportState.stableHeight = 0;
        rtChatViewportState.stableWidth = 0;
        rtChatViewportState.keyboardOpen = false;
    });
}

export const chatPaneNavigation = (initialHasSelection = false) => ({
    mobilePane: initialHasSelection ? 'chat' : 'list',
    listCollapsed: localStorage.getItem('rt-chat-list-collapsed') === 'true',
    touchStartX: null,
    touchStartY: null,
    touchChatId: null,
    touchStartedAt: 0,
    swipeClick: null,
    viewportHandler: null,
    viewportFrame: null,
    focusHandler: null,
    stableViewportHeight: 0,
    stableViewportWidth: 0,
    keyboardOpen: false,
    visualViewportHeight: readChatViewport().height,
    visualViewportTop: readChatViewport().top,
    topbarInset: 70,

    init() {
        // Keep x-data stable across Livewire morphs. Read the initial selection
        // once; subsequent list/chat navigation belongs to this Alpine scope.
        this.mobilePane = this.$root.dataset.hasSelectedChat === 'true' ? 'chat' : 'list';
        this.viewportHandler = () => this.queueVisualViewportSync();
        this.focusHandler = () => this.queueVisualViewportSync();

        const viewport = readChatViewport();
        const viewportHeight = viewport.height;
        const viewportWidth = viewport.width;
        const viewportTop = viewport.top;
        this.visualViewportHeight = viewportHeight;
        this.visualViewportTop = viewportTop;
        this.topbarInset = this.resolveVisibleTopbarInset(viewportTop, viewportHeight);

        this.stableViewportHeight = Math.max(
            viewportHeight,
            rtChatViewportState.stableHeight,
        );
        this.stableViewportWidth = Math.max(
            viewportWidth,
            rtChatViewportState.stableWidth,
        );

        const keyboardThreshold = Math.max(104, this.stableViewportHeight * 0.16);
        this.keyboardOpen = rtChatViewportState.keyboardOpen
            && (this.stableViewportHeight - viewportHeight) > keyboardThreshold;
        this.$root.dataset.keyboardOpen = this.keyboardOpen ? 'true' : 'false';

        window.visualViewport?.addEventListener('resize', this.viewportHandler, { passive: true });
        window.visualViewport?.addEventListener('scroll', this.viewportHandler, { passive: true });
        window.addEventListener('resize', this.viewportHandler, { passive: true });
        window.addEventListener('orientationchange', this.viewportHandler, { passive: true });
        window.addEventListener('pageshow', this.viewportHandler, { passive: true });
        document.addEventListener('visibilitychange', this.viewportHandler, { passive: true });
        this.$root.addEventListener('focusin', this.focusHandler);
        this.$root.addEventListener('focusout', this.focusHandler);

        this.viewportHandler();
        if (this.$root.dataset.communicationChat === 'true') {
            this.$watch('mobilePane', () => window.dispatchEvent(new CustomEvent('communication:chat-pane')));
        }
    },

    queueVisualViewportSync() {
        if (this.viewportFrame !== null) {
            window.cancelAnimationFrame(this.viewportFrame);
        }

        this.viewportFrame = window.requestAnimationFrame(() => {
            this.viewportFrame = null;

            if (!this.$root?.isConnected) {
                return;
            }

            const viewport = readChatViewport();
            const viewportHeight = viewport.height;
            const viewportWidth = viewport.width;
            const viewportTop = viewport.top;
            const activeElement = document.activeElement;
            const editableFocused = activeElement instanceof Element
                && this.$root.contains(activeElement)
                && activeElement.matches('input, textarea, [contenteditable="true"]');

            // Bei einer Drehung entspricht die vorherige Breite ungefaehr der
            // neuen unbelasteten Hoehe. So bleibt die Tastaturerkennung auch
            // dann korrekt, wenn Android gleichzeitig VisualViewport,
            // innerHeight und Layout-Viewport verkleinert.
            const previousStableWidth = this.stableViewportWidth || viewportWidth;
            const previousStableHeight = this.stableViewportHeight || viewportHeight;
            const widthChanged = Math.abs(viewportWidth - previousStableWidth)
                > Math.max(48, previousStableWidth * 0.18);
            const orientationChanged = widthChanged
                && (previousStableHeight >= previousStableWidth) !== (viewportHeight >= viewportWidth);

            if (orientationChanged) {
                this.stableViewportHeight = Math.max(viewportHeight, previousStableWidth);
                this.stableViewportWidth = viewportWidth;
            }

            // Im Ruhezustand ist der sichtbare Viewport unsere stabile
            // Referenz. Beim Fokus steht dieser Wert bereits fest, bevor die
            // Tastatur den VisualViewport verkleinert (auch auf Android, wo
            // window.innerHeight parallel schrumpfen kann).
            const keyboardThreshold = Math.max(104, this.stableViewportHeight * 0.16);
            const viewportRecovered = viewportHeight >= this.stableViewportHeight - keyboardThreshold;

            if (!editableFocused && (!this.keyboardOpen || viewportRecovered)) {
                this.stableViewportHeight = viewportHeight;
                this.stableViewportWidth = viewportWidth;
            }

            const keyboardDelta = this.stableViewportHeight - viewportHeight;
            const nextKeyboardOpen = (editableFocused || this.keyboardOpen)
                && keyboardDelta > keyboardThreshold;

            this.visualViewportHeight = viewportHeight;
            this.visualViewportTop = viewportTop;
            this.topbarInset = this.resolveVisibleTopbarInset(viewportTop, viewportHeight);
            this.$root.style.setProperty('--rt-chat-visual-height', `${Math.round(viewportHeight)}px`);
            this.$root.style.setProperty('--rt-chat-visual-top', `${Math.round(viewportTop)}px`);
            this.$root.style.setProperty('--rt-chat-topbar-inset', `${Math.round(this.topbarInset)}px`);

            if (nextKeyboardOpen !== this.keyboardOpen) {
                this.keyboardOpen = nextKeyboardOpen;
                this.$root.dataset.keyboardOpen = nextKeyboardOpen ? 'true' : 'false';
            }

            if (!nextKeyboardOpen) {
                this.stableViewportWidth = viewportWidth;
            }

            rtChatViewportState.stableHeight = this.stableViewportHeight;
            rtChatViewportState.stableWidth = this.stableViewportWidth;
            rtChatViewportState.keyboardOpen = nextKeyboardOpen;
        });
    },

    resolveVisibleTopbarInset(viewportTop, viewportHeight) {
        if (window.innerWidth >= 768) {
            return 0;
        }

        const topbar = document.querySelector('[data-rt-shell-topbar]');

        if (!(topbar instanceof HTMLElement)) {
            return 0;
        }

        const styles = window.getComputedStyle(topbar);

        if (
            styles.display === 'none'
            || styles.visibility === 'hidden'
            || Number.parseFloat(styles.opacity || '1') <= 0
        ) {
            return 0;
        }

        const rect = topbar.getBoundingClientRect();
        const viewportBottom = viewportTop + viewportHeight;
        const visibleTop = Math.max(rect.top, viewportTop);
        const visibleBottom = Math.min(rect.bottom, viewportBottom);

        return Math.max(0, Math.min(rect.height, visibleBottom - visibleTop));
    },

    showList() {
        this.mobilePane = 'list';
    },

    showChat() {
        this.mobilePane = 'chat';
    },

    resumeLastChat() {
        if (this.$root.dataset.hasSelectedChat === 'true') {
            this.showChat();
        }
    },

    toggleList() {
        this.listCollapsed = !this.listCollapsed;

        try {
            localStorage.setItem('rt-chat-list-collapsed', this.listCollapsed ? 'true' : 'false');
        } catch (_) {
            // Die Navigation funktioniert auch, wenn der Browser Storage sperrt.
        }
    },

    touchStart(event) {
        this.swipeClick = null;
        if (this.splitChat || (window.innerWidth >= 768 && this.$root.dataset.communicationChat !== 'true') || event.touches.length !== 1) {
            this.cancelSwipe();
            return;
        }

        const row = this.$root.dataset.communicationChat === 'true' ? event.target.closest('[data-chat-swipe-id]') : null;
        if (event.target.closest('input, textarea, select, a, audio, video, [role="dialog"], [data-no-chat-swipe]')
            || (!row && event.target.closest('button')) || window.getSelection?.()?.toString()) {
            this.cancelSwipe();
            return;
        }

        this.touchStartX = event.touches[0].clientX;
        this.touchStartY = event.touches[0].clientY;
        this.touchChatId = row ? Number(row.dataset.chatSwipeId) : null;
        this.touchStartedAt = performance.now();
    },

    touchMove(event) {
        if (this.touchStartX === null) return;
        if (event.touches.length !== 1) { this.cancelSwipe(); return; }
        const dx = event.touches[0].clientX - this.touchStartX, dy = event.touches[0].clientY - this.touchStartY;
        if (Math.abs(dy) > 12 && Math.abs(dy) > Math.abs(dx)) this.cancelSwipe();
    },

    touchEnd(event) {
        if (this.splitChat || (window.innerWidth >= 768 && this.$root.dataset.communicationChat !== 'true') || this.touchStartX === null || event.changedTouches.length !== 1) {
            this.cancelSwipe();
            return;
        }

        const deltaX = event.changedTouches[0].clientX - this.touchStartX;
        const deltaY = event.changedTouches[0].clientY - this.touchStartY;
        if (performance.now() - this.touchStartedAt > 650 || window.getSelection?.()?.toString()) { this.cancelSwipe(); return; }
        const threshold = this.$root.dataset.communicationChat === 'true' ? 64 : Math.max(72, Math.min(140, window.innerWidth * 0.22));

        if (Math.abs(deltaX) >= threshold && Math.abs(deltaX) > Math.abs(deltaY) * 1.25) {
            if (deltaX > 0 && this.mobilePane === 'chat') {
                this.showList();
                this.swipeClick = { x: event.changedTouches[0].clientX, y: event.changedTouches[0].clientY, until: performance.now() + 450 };
            } else if (deltaX < 0 && this.mobilePane === 'list') {
                if (this.touchChatId) this.$wire.openChat(this.touchChatId); else this.resumeLastChat();
                this.swipeClick = { x: event.changedTouches[0].clientX, y: event.changedTouches[0].clientY, until: performance.now() + 450 };
            }
        }

        this.cancelSwipe();
    },

    cancelSwipe() {
        this.touchStartX = null;
        this.touchStartY = null;
        this.touchChatId = null;
    },

    consumeSwipeClick(event) {
        const pending = this.swipeClick;
        if (!pending || !event.detail || (event.pointerType && event.pointerType !== 'touch')) return;
        if (performance.now() > pending.until) { this.swipeClick = null; return; }
        if (Math.hypot(event.clientX - pending.x, event.clientY - pending.y) > 40) return;
        this.swipeClick = null; event.preventDefault(); event.stopImmediatePropagation();
    },

    destroy() {
        window.visualViewport?.removeEventListener('resize', this.viewportHandler);
        window.visualViewport?.removeEventListener('scroll', this.viewportHandler);
        window.removeEventListener('resize', this.viewportHandler);
        window.removeEventListener('orientationchange', this.viewportHandler);
        window.removeEventListener('pageshow', this.viewportHandler);
        document.removeEventListener('visibilitychange', this.viewportHandler);
        this.$root?.removeEventListener('focusin', this.focusHandler);
        this.$root?.removeEventListener('focusout', this.focusHandler);

        if (this.viewportFrame !== null) {
            window.cancelAnimationFrame(this.viewportFrame);
            this.viewportFrame = null;
        }

        this.$root?.style.removeProperty('--rt-chat-visual-height');
        this.$root?.style.removeProperty('--rt-chat-visual-top');
        this.$root?.style.removeProperty('--rt-chat-topbar-inset');
        this.$root?.removeAttribute('data-keyboard-open');
        this.viewportHandler = null;
        this.focusHandler = null;
    },
});
