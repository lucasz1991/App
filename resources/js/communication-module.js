const TABS = ['chats', 'calls', 'meetings'];
const LAUNCHER_STACK_OFFSET = 56 + 12;

// Register before Livewire.start(): wire:navigate's delegated click handler must
// not start a page visit for a link that belongs to the persistent module.
document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (!document.querySelector('[data-communication-ready]')) return;
    const link = event.target.closest?.('a[href]');
    if (!link || link.target === '_blank' || link.hasAttribute('download')) return;
    const intent = communicationLink(link.href, window.location.href);
    if (!intent) return;
    event.preventDefault(); event.stopImmediatePropagation();
    window.dispatchEvent(new CustomEvent('communication:open', { detail: { ...intent, mode: 'fullscreen' } }));
}, true);

export function cornerForPoint(x, y, width, height, top = 70) {
    return `${y < top + (height - top) / 2 ? 'top' : 'bottom'}-${x < width / 2 ? 'left' : 'right'}`;
}

export function communicationLink(href, base) {
    let url;
    try { url = new URL(href, base); } catch { return null; }
    if (url.origin !== new URL(base).origin) return null;
    if (url.pathname === '/chat') return { tab: 'chats', chatId: Number(url.searchParams.get('chat')) || null };
    if (url.pathname === '/calls') return { tab: 'calls', list: true };
    if (url.pathname === '/meetings') return { tab: 'meetings' };
    const history = url.pathname.match(/^\/calls\/([a-z0-9-]+)\/history$/i);
    if (history) return { tab: 'calls', historyRoomUuid: history[1] };
    const call = url.pathname.match(/^\/calls\/([a-z0-9-]+)$/i);
    return call ? { tab: 'calls', roomUuid: call[1] } : null;
}

/** Presentation state only. The persistent Livewire child owns the call session. */
export function communicationModule() {
    let clickGuard = null, layoutFrame = null;
    let main = null, mainWasInert = false, mainAria = null, observer = null;
    const cleanups = [];
    let releaseCallLock = null, requestedRoom = null;
    const listen = (target, name, fn, options) => {
        target?.addEventListener(name, fn, options);
        cleanups.push(() => target?.removeEventListener(name, fn, options));
    };

    return {
        open: false, mode: 'mini', corner: 'bottom-right', tab: 'chats',
        activeCall: false, showCall: false, showHistory: false, wide: window.innerWidth > 760,
        init() {
            this.$root.setAttribute('data-communication-ready', '');
            this.$watch('tab', () => this.syncVisibility());
            listen(window, 'communication:open', event => this.openIntent(event.detail || {}, event.detail?.mode));
            listen(window, 'railtime-assistant-opened', event => {
                const detail = event.detail || {};
                if (this.open && (!this.wide || window.innerWidth <= 760 || detail.mobile || !detail.corner || detail.corner === this.corner)) this.close(false);
            });
            listen(window, 'railtime-sidebar-opened', () => { if (this.open) this.close(false); });
            listen(window, 'communication:call', event => this.requestCall(event.detail?.roomUuid));
            listen(window, 'communication:call-ready', () => {
                this.activeCall = true;
                this.showCall = true;
                this.tab = 'calls';
                this.show(this.open ? this.mode : 'mini');
            });
            listen(window, 'communication:call-released', () => {
                this.activeCall = false;
                this.showCall = false;
                releaseCallLock?.(); releaseCallLock = null; requestedRoom = null;
                this.syncVisibility();
            });
            listen(window, 'communication:history-ready', () => {
                this.tab = 'calls'; this.showCall = false; this.showHistory = true;
                this.show(this.open ? this.mode : 'mini');
            });
            listen(window, 'chat:pane-open', () => this.syncVisibility());
            listen(window, 'chat:pane-list', () => this.syncVisibility());
            listen(window, 'communication:chat-pane', () => this.syncVisibility());
            listen(window, 'resize', () => { this.queueLayout(); });
            listen(window.visualViewport, 'resize', () => { this.queueLayout(); });
            listen(window.visualViewport, 'scroll', () => this.queueLayout());
            listen(document, 'visibilitychange', () => this.syncVisibility());
            listen(document, 'livewire:navigating', () => {
                this.setMainInert(false);
                if (this.open && this.mode === 'fullscreen' && this.wide) this.mode = 'mini';
            });
            listen(document, 'livewire:navigated', () => { this.queueLayout(); this.routeIntent(); });
            listen(document, 'click', event => {
                if (clickGuard && event.detail && performance.now() < clickGuard.until
                    && Math.hypot(event.clientX - clickGuard.x, event.clientY - clickGuard.y) < 40) {
                    event.preventDefault(); event.stopImmediatePropagation(); clickGuard = null; return;
                }
            }, true);
            listen(document, 'keydown', event => {
                if (event.key !== 'Escape' || event.defaultPrevented) return;
                if (!this.open || !this.$refs.panel.contains(event.target)) return;
                if (event.target.closest('[role="dialog"],dialog,[data-anchor-dropdown-content]')) return;
                if (this.tab === 'chats' && this.$root.querySelector('[data-mobile-pane="chat"]')) {
                    window.dispatchEvent(new CustomEvent('chat:pane-list')); event.preventDefault();
                } else if (this.showCall || this.showHistory) {
                    this.showHistory = false;
                    this.showCall = false; this.syncVisibility(); event.preventDefault();
                } else if (this.mode === 'fullscreen' && this.wide) { this.resize(); event.preventDefault(); }
                else { this.close(); event.preventDefault(); }
            });
            listen(document, 'pointerdown', event => {
                clickGuard = null;
            }, true);
            observer = new MutationObserver(() => this.queueLayout());
            observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden', 'data-open', 'data-pet-open', 'data-communication-open', 'data-sidebar-expanded'] });
            this.queueLayout();
            this.$nextTick(() => this.routeIntent());
        },
        get canDrag() { return this.open && this.mode === 'mini' && this.wide; },
        dispatchChat(name, payload = {}) { window.Livewire?.dispatchTo('chat-box', name, payload); },
        routeIntent() {
            const entry = document.querySelector('[data-communication-entry]');
            if (entry) this.openIntent({ tab: entry.dataset.tab, chatId: Number(entry.dataset.chatId) || null, roomUuid: entry.dataset.roomUuid || null, historyRoomUuid: entry.dataset.historyRoomUuid || null }, 'fullscreen');
            this.syncVisibility();
        },
        openIntent(intent, size = null) {
            if (TABS.includes(intent.tab)) this.tab = intent.tab;
            this.showCall = !intent.list && this.tab === 'calls' && this.activeCall;
            this.showHistory = false;
            this.show(size || (this.open ? this.mode : 'mini'));
            if (intent.chatId) this.dispatchChat('communication:chat', { chatId: intent.chatId });
            if (intent.newChat) this.dispatchChat('communication:new-chat');
            if (intent.roomUuid) this.requestCall(intent.roomUuid);
            if (intent.historyRoomUuid) this.$wire.openHistory(intent.historyRoomUuid);
        },
        show(size = 'mini') { this.transition(true, this.wide ? size : 'fullscreen'); },
        async requestCall(roomUuid) {
            if (!roomUuid) return;
            if (this.activeCall || requestedRoom) {
                this.resumeCall();
                return;
            }
            requestedRoom = roomUuid;
            const join = async () => {
                try { await this.$wire.openCall(roomUuid); }
                catch (error) { releaseCallLock?.(); releaseCallLock = null; requestedRoom = null; throw error; }
            };
            const locks = window.navigator?.locks;
            if (!locks?.request) { await join(); return; }
            const user = document.querySelector('meta[name="rt-user-id"]')?.content || 'current';
            await locks.request(`railtime-call-${user}`, { ifAvailable: true }, async lock => {
                if (!lock) {
                    requestedRoom = null;
                    window.dispatchEvent(new CustomEvent('swal:toast', { detail: { type: 'warning', text: 'Der Anruf ist bereits in einem anderen RailTime-Tab geöffnet.' } }));
                    return;
                }
                const held = new Promise(resolve => { releaseCallLock = resolve; });
                await join();
                await held;
            });
        },
        close(restoreFocus = true) {
            this.transition(false, this.mode);
            if (restoreFocus) this.$nextTick(() => { if (!this.open) this.$refs.launcher.focus({ preventScroll: true }); });
        },
        resize() {
            if (!this.wide) { this.show('fullscreen'); return; }
            this.transition(true, this.mode === 'mini' ? 'fullscreen' : 'mini');
        },
        selectTab(tab) {
            if (!TABS.includes(tab)) return;
            this.tab = tab;
            this.showHistory = false;
            this.showCall = tab === 'calls' && this.activeCall;
            this.syncVisibility();
        },
        resumeCall() { this.tab = 'calls'; this.showCall = true; this.show(this.open ? this.mode : 'mini'); },
        tabKey(event) {
            const button = event.target.closest('[data-comm-tab]');
            if (!button || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const index = TABS.indexOf(button.dataset.commTab);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? 2 : (index + (event.key === 'ArrowLeft' ? 2 : 1)) % 3;
            this.selectTab(TABS[next]);
            this.$root.querySelector(`[data-comm-tab="${TABS[next]}"]`)?.focus();
        },
        syncVisibility() {
            this.$root.dataset.communicationOpen = String(this.open);
            this.$root.dataset.communicationTab = this.tab;
            this.$root.dataset.communicationCall = String(this.showCall && this.activeCall);
            this.setMainInert(this.open && this.mode === 'fullscreen');
            this.$nextTick(() => {
                const chat = this.$root.querySelector('[data-communication-chat="true"]');
                const visible = this.open && this.tab === 'chats' && chat?.dataset.mobilePane === 'chat' && document.visibilityState === 'visible';
                this.dispatchChat('communication:visibility', { visible });
            });
        },
        setMainInert(inert) {
            document.body.classList.toggle('rt-communication-fullscreen', inert);
            const target = document.querySelector('#main-content');
            if (main && (main !== target || !inert)) {
                main.inert = mainWasInert;
                if (mainAria === null) main.removeAttribute('aria-hidden'); else main.setAttribute('aria-hidden', mainAria);
                main = null;
            }
            if (inert && target && !main) {
                main = target; mainWasInert = target.inert; mainAria = target.getAttribute('aria-hidden');
                target.inert = true; target.setAttribute('aria-hidden', 'true');
            }
        },
        transition(open, mode) {
            const newlyOpen = open && !this.open;
            this.mode = this.wide ? mode : 'fullscreen';
            this.open = open;
            this.syncVisibility(); this.queueLayout();
            if (newlyOpen) {
                const detail = { surface: 'communication', corner: this.corner, mobile: window.innerWidth <= 760 };
                window.dispatchEvent(new CustomEvent('railtime-assistant-close', { detail }));
                window.dispatchEvent(new CustomEvent('rt-floating-surface-opened', { detail }));
                this.$nextTick(() => this.$root.querySelector('[data-comm-tab="'+this.tab+'"]')?.focus({ preventScroll: true }));
            }
        },
        queueLayout() {
            if (layoutFrame !== null) return;
            layoutFrame = requestAnimationFrame(() => { layoutFrame = null; this.measureLayout(); });
        },
        measureLayout() {
            this.wide = window.innerWidth > 760;
            if (!this.wide && this.open && this.mode !== 'fullscreen') {
                this.mode = 'fullscreen';
                this.syncVisibility();
            }
            const viewport = window.visualViewport;
            const top = Math.max(0, viewport?.offsetTop || 0);
            const height = viewport?.height || window.innerHeight;
            const bar = document.querySelector('[data-rt-shell-topbar]')?.getBoundingClientRect();
            const inset = Math.max(top, bar?.bottom || 70);
            const page = document.querySelector('.rt-app-page-frame')?.getBoundingClientRect();
            this.$root.style.setProperty('--comm-top', `${inset}px`);
            this.$root.style.setProperty('--comm-bottom', `${Math.max(0, window.innerHeight - top - height)}px`);
            this.$root.style.setProperty('--comm-left', `${Math.max(0, page?.left || 0)}px`);
            let bottom = 20;
            const right = 16, left = 16;
            if (!this.wide) {
                for (const menu of document.querySelectorAll('[data-area-menu]')) {
                    const rect = menu.getBoundingClientRect();
                    const styles = getComputedStyle(menu);
                    if (!rect.width || !rect.height || styles.display === 'none' || styles.visibility === 'hidden' || Number(styles.opacity) === 0 || rect.top >= window.innerHeight || rect.bottom <= 0) continue;
                    bottom = Math.max(bottom, window.innerHeight - rect.top + 12);
                }
            }
            this.$root.style.setProperty('--comm-launcher-bottom', `${bottom}px`);
            this.$root.style.setProperty('--comm-launcher-right', `${right}px`);
            this.$root.style.setProperty('--comm-launcher-left', `${left}px`);
            const assistBottom = this.open ? bottom : bottom + LAUNCHER_STACK_OFFSET;
            document.documentElement.style.setProperty('--ai-assist-launcher-bottom', `${assistBottom}px`);
        },
        destroy() {
            this.$root.removeAttribute('data-communication-ready');
            document.documentElement.style.removeProperty('--ai-assist-launcher-bottom');
            releaseCallLock?.(); releaseCallLock = null; requestedRoom = null;
            this.setMainInert(false); observer?.disconnect();
            if (layoutFrame !== null) cancelAnimationFrame(layoutFrame);
            cleanups.splice(0).forEach(cleanup => cleanup());
        },
    };
}
