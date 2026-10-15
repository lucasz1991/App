const TABS = ['chats', 'calls', 'meetings'];
const INTERACTIVE = 'button,a,input,textarea,select,[role="tab"],[role="dialog"],[contenteditable="true"]';

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
    let animation = null, generation = 0, drag = null, clickGuard = null, layoutFrame = null;
    let main = null, mainWasInert = false, mainAria = null, observer = null;
    const cleanups = [];
    const touches = new Set();
    let releaseCallLock = null, requestedRoom = null;
    const listen = (target, name, fn, options) => {
        target?.addEventListener(name, fn, options);
        cleanups.push(() => target?.removeEventListener(name, fn, options));
    };

    return {
        open: false, visible: false, mode: 'mini', corner: 'bottom-right', tab: 'chats',
        activeCall: false, showCall: false, showHistory: false, dragging: false, wide: window.innerWidth > 760,
        init() {
            this.$root.setAttribute('data-communication-ready', '');
            this.$watch('tab', () => this.syncVisibility());
            listen(window, 'communication:open', event => this.openIntent(event.detail || {}, event.detail?.mode));
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
            listen(window, 'resize', () => { this.cancelDrag(); this.finishMotion(); this.queueLayout(); });
            listen(window.visualViewport, 'resize', () => { this.cancelDrag(); this.finishMotion(); this.queueLayout(); });
            listen(window.visualViewport, 'scroll', () => this.queueLayout());
            listen(window, 'blur', () => this.cancelDrag());
            listen(document, 'visibilitychange', () => { this.cancelDrag(); this.finishMotion(); this.syncVisibility(); });
            listen(document, 'livewire:navigating', () => {
                this.setMainInert(false);
                this.cancelDrag();
                this.finishMotion();
                if (this.open && this.mode === 'fullscreen') this.mode = 'mini';
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
                if (drag) { event.preventDefault(); this.cancelDrag(); return; }
                if (!this.open || !this.$refs.panel.contains(event.target)) return;
                if (event.target.closest('[role="dialog"],dialog,[data-anchor-dropdown-content]')) return;
                if (this.tab === 'chats' && this.$root.querySelector('[data-mobile-pane="chat"]')) {
                    window.dispatchEvent(new CustomEvent('chat:pane-list')); event.preventDefault();
                } else if (this.showCall || this.showHistory) {
                    this.showHistory = false;
                    this.showCall = false; this.syncVisibility(); event.preventDefault();
                } else if (this.mode === 'fullscreen') { this.resize(); event.preventDefault(); }
                else { this.close(); event.preventDefault(); }
            });
            listen(document, 'pointerdown', event => {
                clickGuard = null;
                if (event.pointerType === 'touch') { touches.add(event.pointerId); if (touches.size > 1) this.cancelDrag(); }
            }, true);
            listen(document, 'pointermove', event => this.dragMove(event), { passive: false });
            listen(document, 'pointerup', event => { touches.delete(event.pointerId); this.dragEnd(event); });
            listen(document, 'pointercancel', event => { touches.delete(event.pointerId); this.cancelDrag(); });
            listen(document, 'lostpointercapture', event => {
                if (event.target === this.$refs.panel && drag?.id === event.pointerId) this.cancelDrag();
            });
            const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
            listen(preference, 'change', () => this.finishMotion());
            observer = new MutationObserver(() => this.queueLayout());
            observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden', 'data-open', 'data-pet-open', 'data-sidebar-expanded'] });
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
        show(size = 'mini') { this.transition(true, size); },
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
        close() {
            this.transition(false, this.mode);
            this.$nextTick(() => { if (!this.open) this.$refs.launcher.focus({ preventScroll: true }); });
        },
        resize() { this.transition(true, this.mode === 'mini' ? 'fullscreen' : 'mini'); },
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
        finishMotion() {
            generation++;
            animation?.cancel(); animation = null;
            if (!this.open) this.visible = false;
        },
        transition(open, mode) {
            const panel = this.$refs.panel;
            const focusAfterOpen = open && !this.open;
            const from = this.visible ? panel.getBoundingClientRect() : null;
            this.finishMotion(); this.cancelDrag();
            this.mode = mode; this.open = open; this.visible = true;
            this.syncVisibility(); this.queueLayout();
            const revision = ++generation;
            this.$nextTick(() => {
                if (revision !== generation) return;
                if (focusAfterOpen) this.$root.querySelector(`[data-comm-tab="${this.tab}"]`)?.focus({ preventScroll: true });
                const to = panel.getBoundingClientRect();
                if (!panel.animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !to.width || !to.height) {
                    this.visible = open; return;
                }
                const frame = rect => ({ left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px` });
                const small = this.$refs.launcher.getBoundingClientRect();
                const start = from ? { ...frame(from), opacity: 1 } : { ...frame(to), transform: `translate(${small.left + small.width / 2 - to.right}px,${small.top + small.height / 2 - to.bottom}px) scale(.92)`, opacity: 0 };
                const end = open ? { ...frame(to), transform: 'none', opacity: 1 } : { ...frame(from || to), transform: 'translateY(18px) scale(.96)', opacity: 0 };
                animation = panel.animate([start, end], { duration: from && open ? 380 : 280, easing: 'cubic-bezier(.22,.8,.25,1)', fill: 'both' });
                const current = animation;
                current.onfinish = () => {
                    if (revision !== generation || animation !== current) return;
                    animation = null; current.cancel(); this.visible = open;
                };
            });
        },
        queueLayout() {
            if (layoutFrame !== null) return;
            layoutFrame = requestAnimationFrame(() => { layoutFrame = null; this.measureLayout(); });
        },
        measureLayout() {
            this.wide = window.innerWidth > 760;
            const viewport = window.visualViewport;
            const top = Math.max(0, viewport?.offsetTop || 0);
            const height = viewport?.height || window.innerHeight;
            const bar = document.querySelector('[data-rt-shell-topbar]')?.getBoundingClientRect();
            const inset = Math.max(top, bar?.bottom || 70);
            const page = document.querySelector('.rt-app-page-frame')?.getBoundingClientRect();
            this.$root.style.setProperty('--comm-top', `${inset}px`);
            this.$root.style.setProperty('--comm-bottom', `${Math.max(0, window.innerHeight - top - height)}px`);
            this.$root.style.setProperty('--comm-left', `${Math.max(0, page?.left || 0)}px`);
            let bottom = 20, right = 24;
            for (const orb of document.querySelectorAll('.rt-ai-assist__launcher,.rt-chatbot__pet-launcher')) {
                const rect = orb.getBoundingClientRect();
                const styles = getComputedStyle(orb);
                if (!rect.width || !rect.height || styles.display === 'none' || styles.visibility === 'hidden' || Number(styles.opacity) === 0 || rect.top >= window.innerHeight || rect.bottom <= 0) continue;
                if (rect.right > window.innerWidth / 2) {
                    bottom = Math.max(bottom, window.innerHeight - rect.top + 12);
                    right = Math.max(12, window.innerWidth - rect.right + (rect.width - 56) / 2);
                }
            }
            this.$root.style.setProperty('--comm-launcher-bottom', `${bottom}px`);
            this.$root.style.setProperty('--comm-launcher-right', `${right}px`);
        },
        dragStart(event) {
            if (!this.canDrag || touches.size > 1 || event.isPrimary === false || event.button !== 0 || event.target.closest(INTERACTIVE)) return;
            if (Array.from(document.querySelectorAll('dialog[open],[role="dialog"][aria-modal="true"]')).some(dialog => {
                const rect = dialog.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0 && getComputedStyle(dialog).visibility !== 'hidden';
            })) return;
            drag = { id: event.pointerId, x: event.clientX, y: event.clientY, locked: false };
        },
        dragMove(event) {
            if (!drag || drag.id !== event.pointerId) return;
            if (!this.canDrag) { this.cancelDrag(); return; }
            const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
            const panel = this.$refs.panel;
            if (!drag.locked) {
                if (Math.hypot(dx, dy) < 6) return;
                const rect = panel.getBoundingClientRect();
                this.finishMotion(); drag.rect = rect; drag.locked = true; this.dragging = true;
                panel.setPointerCapture?.(event.pointerId);
            }
            event.preventDefault();
            const minTop = parseFloat(this.$root.style.getPropertyValue('--comm-top')) + 12;
            const x = Math.max(12, Math.min(window.innerWidth - drag.rect.width - 12, drag.rect.left + dx));
            const y = Math.max(minTop, Math.min(window.innerHeight - drag.rect.height - 12, drag.rect.top + dy));
            Object.assign(panel.style, { left: `${x}px`, top: `${y}px`, right: 'auto', bottom: 'auto' });
        },
        cancelDrag() {
            const previous = drag; drag = null; this.dragging = false;
            const panel = this.$refs.panel;
            for (const property of ['left', 'top', 'right', 'bottom']) panel.style.removeProperty(property);
            if (previous && panel.hasPointerCapture?.(previous.id)) panel.releasePointerCapture(previous.id);
        },
        dragEnd(event) {
            if (!drag || drag.id !== event.pointerId) return;
            const rect = this.$refs.panel.getBoundingClientRect(), locked = drag.locked;
            this.cancelDrag();
            if (!locked) return;
            clickGuard = { x: event.clientX, y: event.clientY, until: performance.now() + 450 };
            this.corner = cornerForPoint(rect.left + rect.width / 2, rect.top + rect.height / 2, window.innerWidth, window.innerHeight);
            this.animateDock(rect);
        },
        animateDock(from) {
            this.finishMotion();
            const revision = generation;
            this.$nextTick(() => {
                if (revision !== generation) return;
                if (!this.canDrag || !this.$refs.panel.animate || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                const to = this.$refs.panel.getBoundingClientRect();
                animation = this.$refs.panel.animate([{ transform: `translate(${from.left - to.left}px,${from.top - to.top}px)` }, { transform: 'none' }], { duration: 240, easing: 'cubic-bezier(.22,.8,.25,1)' });
                const current = animation;
                current.onfinish = () => { if (animation === current) { animation = null; current.cancel(); } };
            });
        },
        cornerKey(event) {
            if (!this.canDrag || !event.altKey || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const from = this.$refs.panel.getBoundingClientRect();
            const [vertical, horizontal] = this.corner.split('-');
            this.corner = `${event.key === 'ArrowUp' ? 'top' : event.key === 'ArrowDown' ? 'bottom' : vertical}-${event.key === 'ArrowLeft' ? 'left' : event.key === 'ArrowRight' ? 'right' : horizontal}`;
            this.animateDock(from);
        },
        destroy() {
            this.$root.removeAttribute('data-communication-ready');
            releaseCallLock?.(); releaseCallLock = null; requestedRoom = null;
            this.finishMotion(); this.cancelDrag(); this.setMainInert(false); observer?.disconnect();
            if (layoutFrame !== null) cancelAnimationFrame(layoutFrame);
            cleanups.splice(0).forEach(cleanup => cleanup());
        },
    };
}
