// AI-Assist der Disposition: Öffnen/Schließen, Aktionsliste („/“), Hinweisblase und persönliche
// Einstellungen. Inhalte und Aktionen kommen aus Livewire (App\Livewire\Operations\AiAssist).
import { animateFloatingWindowGeometry } from './floating-window-motion.js';

export const AI_ASSIST_PREFS_KEY = 'rt-ai-assist-prefs';
export const AI_ASSIST_BUBBLE_KEY = 'rt-ai-assist-bubble';
const DEFAULT_PREFS = Object.freeze({ context: true, proactive: true });
const FLOATING_WINDOW_INTERACTIVE = 'button,a,input,textarea,select,[role="tab"],[role="dialog"],[contenteditable="true"]';

function dragTargetIsInteractive(target, panel) {
    const interactive = target?.closest?.(FLOATING_WINDOW_INTERACTIVE);
    return Boolean(interactive && interactive !== panel);
}

export function readAiAssistPrefs(storage) {
    try {
        const stored = JSON.parse(storage?.getItem(AI_ASSIST_PREFS_KEY) || '{}');
        return {
            context: typeof stored.context === 'boolean' ? stored.context : DEFAULT_PREFS.context,
            proactive: typeof stored.proactive === 'boolean' ? stored.proactive : DEFAULT_PREFS.proactive,
        };
    } catch {
        return { ...DEFAULT_PREFS };
    }
}

export function filterAiAssistActions(actions, text) {
    const query = String(text || '').replace(/^\//, '').trim().toLowerCase();
    return (Array.isArray(actions) ? actions : []).filter(action => !query || String(action.title).toLowerCase().includes(query));
}

function parse(json) {
    try {
        const value = JSON.parse(json || '[]');
        return Array.isArray(value) ? value : [];
    } catch {
        return [];
    }
}

export function aiAssist({ embedded = false } = {}) {
    // Wurzel festhalten: in der verschachtelten Orb-Ebene zeigt $root auf das innere Element.
    let root = null;
    let drag = null;
    let motionTimer = null;
    let geometryAnimation = null;
    return {
        open: false,
        visible: false,
        mode: 'mini',
        motion: 'idle',
        corner: 'bottom-right',
        dragging: false,
        wide: false,
        settingsOpen: false,
        busy: false,
        loaded: false,
        disposed: false,
        callVersion: 0,
        callError: '',
        messagesPinned: true,
        unseenOutput: false,
        bubble: false,
        text: '',
        slashIndex: 0,
        prefs: { ...DEFAULT_PREFS },
        bubbleTimer: null,
        mobile: false,
        _resizeHandler: null,
        init() {
            root = this.$el;
            this._resizeHandler = () => this.syncLayout();
            window.addEventListener?.('resize', this._resizeHandler, { passive: true });
            window.visualViewport?.addEventListener?.('resize', this._resizeHandler, { passive: true });
            this.syncLayout();
            if (root?.dataset.intakeOpen === 'true') {
                this.open = true;
                this.visible = true;
                this.loaded = true;
                this.syncLayout();
            }
            try {
                this.prefs = readAiAssistPrefs(window.localStorage);
            } catch {
                this.prefs = { ...DEFAULT_PREFS };
            }
            // Einmal je Sitzung meldet sich der Orb mit dem wichtigsten Hinweis.
            this.bubbleTimer = setTimeout(() => {
                if (this.disposed || this.open || !this.prefs.proactive || !this.hints.length) return;
                try {
                    if (window.sessionStorage.getItem(AI_ASSIST_BUBBLE_KEY)) return;
                    window.sessionStorage.setItem(AI_ASSIST_BUBBLE_KEY, '1');
                } catch {
                    return;
                }
                this.bubble = true;
                this.bubbleTimer = setTimeout(() => { if (!this.disposed) this.bubble = false; }, 9000);
            }, 1400);
        },
        destroy() {
            this.disposed = true;
            this.callVersion++;
            clearTimeout(this.bubbleTimer);
            window.removeEventListener?.('resize', this._resizeHandler);
            window.visualViewport?.removeEventListener?.('resize', this._resizeHandler);
            clearTimeout(motionTimer);
            geometryAnimation?.cancel?.();
            geometryAnimation = null;
            this.cancelDrag();
            if (typeof document !== 'undefined') document.body?.classList?.remove('rt-ai-assist-fullscreen');
            root = null;
        },
        get hints() {
            return parse(root?.dataset.hints);
        },
        get actions() {
            return parse(root?.dataset.actions);
        },
        get orbState() {
            return this.busy ? 'thinking' : 'idle';
        },
        get slash() {
            return this.text.startsWith('/');
        },
        get slashList() {
            return filterAiAssistActions(this.actions, this.text);
        },
        toggle(open = !this.open) {
            if (this.disposed) return;
            const next = Boolean(open);
            if (next === this.open) return;
            const opening = next && !this.open;
            const from = this.visible
                ? this.$refs.panel?.getBoundingClientRect?.()
                : this.$refs.launcher?.getBoundingClientRect?.();
            if (opening) this.motion = 'preparing';
            this.open = next;
            this.visible = true;
            this.bubble = false;
            this.syncLayout();
            if (!next) {
                this.settingsOpen = false;
                this.cancelDrag();
                window.dispatchEvent(new CustomEvent('ai-intake-panel-hidden'));
            }
            this.$nextTick(() => {
                if (this.disposed || this.open !== next) return;
                const panel = this.$refs.panel;
                const to = panel?.getBoundingClientRect?.();
                this.animateGeometry(from, to, opening ? 340 : 280, opening ? 'opening' : 'closing');
                if (next) {
                    this.scrollDown();
                    this.resize();
                    this.$refs.input?.focus();
                } else {
                    this.$refs.launcher?.focus();
                }
            });
            if (opening) {
                window.dispatchEvent(new CustomEvent('rt-floating-surface-opened', { detail: { surface: 'assist' } }));
                window.dispatchEvent(new CustomEvent('railtime-assistant-opened'));
                void this.ensureLoaded();
            }
        },
        syncLayout() {
            if (!root) return;
            this.mobile = window.innerWidth <= 760;
            this.wide = !this.mobile;
            if (this.mobile) { this.mode = 'fullscreen'; this.cancelDrag(); }
            const viewport = window.visualViewport;
            const viewportTop = Math.max(0, viewport?.offsetTop || 0);
            const viewportHeight = viewport?.height || window.innerHeight;
            const documentRef = typeof document === 'undefined' ? null : document;
            const topbar = documentRef?.querySelector?.('[data-rt-shell-topbar]')?.getBoundingClientRect?.();
            root.style?.setProperty?.('--rt-assistant-top', `${Math.max(viewportTop, topbar?.bottom || 70)}px`);
            root.style?.setProperty?.('--rt-assistant-bottom', `${Math.max(0, window.innerHeight - viewportTop - viewportHeight)}px`);
            const page = documentRef?.querySelector?.('.rt-app-page-frame')?.getBoundingClientRect?.();
            root.style?.setProperty?.('--rt-assistant-left', `${Math.max(0, page?.left || 0)}px`);
            documentRef?.body?.classList?.toggle('rt-ai-assist-fullscreen', this.open && this.mode === 'fullscreen');
        },
        get canDrag() {
            return this.open && this.mode === 'mini' && this.wide;
        },
        resizeSurface() {
            if (!this.wide) return;
            const from = this.$refs.panel?.getBoundingClientRect?.();
            this.cancelDrag();
            this.mode = this.mode === 'mini' ? 'fullscreen' : 'mini';
            this.syncLayout();
            this.$nextTick(() => this.animateGeometry(from, this.$refs.panel?.getBoundingClientRect?.(), 380));
        },
        dragStart(event) {
            if (!this.canDrag || event.isPrimary === false || event.button !== 0 || dragTargetIsInteractive(event.target, this.$refs?.panel)) return;
            drag = { id: event.pointerId, x: event.clientX, y: event.clientY, locked: false, rect: null };
        },
        dragMove(event) {
            if (!drag || drag.id !== event.pointerId) return;
            if (!this.canDrag) { this.cancelDrag(); return; }
            const panel = this.$refs.panel;
            const dx = event.clientX - drag.x;
            const dy = event.clientY - drag.y;
            if (!drag.locked) {
                if (Math.hypot(dx, dy) < 6 || !panel) return;
                drag.rect = panel.getBoundingClientRect();
                drag.locked = true;
                this.dragging = true;
                panel.setPointerCapture?.(event.pointerId);
            }
            event.preventDefault?.();
            const top = parseFloat(root?.style?.getPropertyValue?.('--rt-assistant-top')) || 70;
            const x = Math.max(12, Math.min(window.innerWidth - drag.rect.width - 12, drag.rect.left + dx));
            const y = Math.max(top + 12, Math.min(window.innerHeight - drag.rect.height - 12, drag.rect.top + dy));
            Object.assign(panel.style, { left: `${x}px`, top: `${y}px`, right: 'auto', bottom: 'auto' });
        },
        cancelDrag() {
            const previous = drag;
            drag = null;
            this.dragging = false;
            const panel = this.$refs?.panel;
            if (!panel) return;
            for (const property of ['left', 'top', 'right', 'bottom']) panel.style.removeProperty(property);
            if (previous && panel.hasPointerCapture?.(previous.id)) panel.releasePointerCapture(previous.id);
        },
        dragEnd(event) {
            if (!drag || drag.id !== event.pointerId) return;
            const panel = this.$refs?.panel;
            const rect = panel?.getBoundingClientRect?.();
            const locked = drag.locked;
            this.cancelDrag();
            if (!locked || !rect) return;
            const top = parseFloat(root?.style?.getPropertyValue?.('--rt-assistant-top')) || 70;
            this.corner = `${rect.top + rect.height / 2 < (top + window.innerHeight) / 2 ? 'top' : 'bottom'}-${rect.left + rect.width / 2 < window.innerWidth / 2 ? 'left' : 'right'}`;
            this.$nextTick(() => this.animateGeometry(rect, panel.getBoundingClientRect(), 240));
        },
        lostPointerCapture(event) {
            if (event.target === this.$refs?.panel && drag?.id === event.pointerId) this.cancelDrag();
        },
        cornerKey(event) {
            if (!this.canDrag || !event.altKey || !['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const from = this.$refs.panel?.getBoundingClientRect?.();
            const [vertical, horizontal] = this.corner.split('-');
            this.corner = `${event.key === 'ArrowUp' ? 'top' : event.key === 'ArrowDown' ? 'bottom' : vertical}-${event.key === 'ArrowLeft' ? 'left' : event.key === 'ArrowRight' ? 'right' : horizontal}`;
            this.$nextTick(() => this.animateGeometry(from, this.$refs.panel?.getBoundingClientRect?.(), 240));
        },
        animateGeometry(from, to, duration, kind = 'resizing') {
            clearTimeout(motionTimer);
            geometryAnimation?.cancel?.();
            geometryAnimation = null;
            if (!from || !to || !to.width || !to.height || window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches) {
                this.motion = 'idle';
                if (!this.open) this.visible = false;
                return;
            }
            const panel = this.$refs.panel;
            if (kind === 'resizing' && panel?.animate) {
                this.motion = 'resizing';
                const currentMode = this.mode;
                geometryAnimation = animateFloatingWindowGeometry(panel, from, to, {
                    duration,
                    fromRadius: currentMode === 'fullscreen' ? '18px' : '20px',
                    toRadius: currentMode === 'fullscreen' ? '20px' : '18px',
                    onFinish: animation => {
                        if (geometryAnimation !== animation) return;
                        geometryAnimation = null;
                        this.motion = 'idle';
                    },
                });
                if (geometryAnimation) return;
            }
            const closing = kind === 'closing' || !this.open;
            const source = from;
            const target = closing ? this.$refs.launcher?.getBoundingClientRect?.() || to : to;
            const sourceCenter = { x: source.left + source.width / 2, y: source.top + source.height / 2 };
            const targetCenter = { x: target.left + target.width / 2, y: target.top + target.height / 2 };
            const x = closing ? targetCenter.x - sourceCenter.x : sourceCenter.x - targetCenter.x;
            const y = closing ? targetCenter.y - sourceCenter.y : sourceCenter.y - targetCenter.y;
            let sx = Math.max(.08, Math.min(1, closing ? target.width / source.width : source.width / target.width));
            let sy = Math.max(.08, Math.min(1, closing ? target.height / source.height : source.height / target.height));
            if (kind === 'opening' || closing) {
                sx = .96;
                sy = .96;
            }
            panel.style.setProperty('--rt-ai-motion-x', `${kind === 'opening' || closing ? Math.max(-24, Math.min(24, x * .08)) : x}px`);
            panel.style.setProperty('--rt-ai-motion-y', `${kind === 'opening' || closing ? Math.max(-30, Math.min(30, y * .08)) : y}px`);
            panel.style.setProperty('--rt-ai-motion-scale-x', String(sx));
            panel.style.setProperty('--rt-ai-motion-scale-y', String(sy));
            this.motion = closing ? 'closing' : (kind === 'opening' ? 'opening' : 'resizing');
            motionTimer = setTimeout(() => {
                this.motion = 'idle';
                panel.style.removeProperty('--rt-ai-motion-x');
                panel.style.removeProperty('--rt-ai-motion-y');
                panel.style.removeProperty('--rt-ai-motion-scale-x');
                panel.style.removeProperty('--rt-ai-motion-scale-y');
                if (!this.open) this.visible = false;
            }, duration + 10);
        },
        async openIntake(detail = {}) {
            if (this.disposed || this.busy) return false;
            this.toggle(true);
            this.bubble = false;
            this.settingsOpen = false;
            this.syncLayout();
            const success = await this.call('openIntake', detail.intakeId ?? null, detail.create === true, detail.customerId ?? null);
            if (success && !this.disposed) this.loaded = true;
            return success;
        },
        async ensureLoaded() {
            if (this.loaded || this.busy || this.disposed) return false;
            const success = await this.call('load');
            if (success && !this.disposed) this.loaded = true;
            return success;
        },
        async call(method, ...args) {
            if (this.busy || this.disposed) return false;
            this.handleMessagesScroll();
            const height = this.$refs.messages?.scrollHeight ?? 0;
            const version = ++this.callVersion;
            this.busy = true;
            this.callError = '';
            let success = false;
            this.$nextTick(() => { if (!this.disposed && version === this.callVersion) this.scrollDown(); });
            try {
                await this.request(method, ...args);
                success = !this.disposed && version === this.callVersion;
                return success;
            } catch {
                if (!this.disposed && version === this.callVersion) {
                    this.callError = 'Die Aktion konnte nicht abgeschlossen werden. Deine Eingabe bleibt erhalten. Bitte erneut versuchen.';
                }
                return false;
            } finally {
                if (this.disposed || version !== this.callVersion) return false;
                this.busy = false;
                this.$nextTick(() => {
                    if (this.disposed || version !== this.callVersion) return;
                    const box = this.$refs.messages;
                    if (success && method !== 'load' && box && box.scrollHeight > height && !this.messagesPinned) this.unseenOutput = true;
                    this.scrollDown();
                });
            }
        },
        request(method, ...args) {
            let cleanup;
            return new Promise((resolve, reject) => {
                if (typeof this.$wire?.$hook === 'function') {
                    cleanup = this.$wire.$hook('commit', ({ commit, fail }) => {
                        if (commit?.calls?.some(call => call.method === method)) fail(() => reject(new Error('Assistant request failed')));
                    });
                }
                Promise.resolve(this.$wire[method](...args)).then(resolve, reject);
            }).finally(() => { if (typeof cleanup === 'function') cleanup(); });
        },
        run(key) {
            if (this.busy || this.disposed) return Promise.resolve(false);
            const draft = this.text;
            this.text = '';
            this.slashIndex = 0;
            return this.call('run', key).then(success => {
                this.restoreDraft(success, draft);
                return success;
            });
        },
        ask(question) {
            return this.call('ask', question);
        },
        submit() {
            const question = this.text.trim();
            if (!question || this.busy || this.disposed) return Promise.resolve(false);
            if (this.slash) {
                const action = this.slashList[this.slashIndex];
                return action ? this.run(action.key) : Promise.resolve(false);
            }
            const draft = this.text;
            this.text = '';
            this.$nextTick(() => this.resize());
            return this.call('ask', question).then(success => {
                this.restoreDraft(success, draft);
                return success;
            });
        },
        restoreDraft(success, draft) {
            if (!success && !this.disposed && this.text === '') {
                this.text = draft;
                this.$nextTick(() => { if (!this.disposed) this.resize(); });
            }
        },
        keydown(event) {
            if (this.disposed || event.isComposing || event.keyCode === 229) return;
            if (this.slash && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                const count = Math.max(1, this.slashList.length);
                this.slashIndex = (this.slashIndex + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
                return;
            }
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                return this.submit();
            }
        },
        typed() {
            this.slashIndex = 0;
            this.resize();
        },
        resize() {
            const input = this.$refs.input;
            if (!input) return;
            input.style.height = 'auto';
            input.style.height = `${Math.min(120, input.scrollHeight)}px`;
        },
        escape(event) {
            if (!this.open) return;
            event.stopPropagation();
            if (this.slash) this.text = '';
            else if (this.settingsOpen) this.settingsOpen = false;
            else if (this.mode === 'fullscreen' && this.wide) this.resizeSurface();
            else this.toggle(false);
        },
        shortcut(event) {
            if (embedded || this.disposed || event.defaultPrevented || event.isComposing || event.repeat) return;
            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.key.toLowerCase() !== 'j') return;
            event.preventDefault();
            this.toggle();
        },
        hover() {
            if (!this.disposed && !this.open && this.prefs.proactive && this.hints.length) this.bubble = true;
        },
        bubbleRun(run) {
            this.bubble = false;
            this.toggle(true);
            this.run(run);
        },
        setPref(key, value) {
            this.prefs = { ...this.prefs, [key]: Boolean(value) };
            try {
                window.localStorage.setItem(AI_ASSIST_PREFS_KEY, JSON.stringify(this.prefs));
            } catch {
                // Private Fenster: Einstellung gilt nur bis zum Neuladen.
            }
        },
        handleMessagesScroll() {
            if (this.disposed) return;
            const box = this.$refs.messages;
            if (!box || box.isConnected === false) return;
            this.messagesPinned = box.scrollHeight - box.scrollTop - box.clientHeight <= 72;
            if (this.messagesPinned) this.unseenOutput = false;
        },
        jumpToLatest() {
            this.scrollDown(true);
        },
        scrollDown(force = false) {
            if (this.disposed || (!force && !this.messagesPinned)) return;
            const box = this.$refs.messages;
            if (!box || box.isConnected === false) return;
            box.scrollTop = box.scrollHeight;
            this.messagesPinned = true;
            this.unseenOutput = false;
        },
    };
}
