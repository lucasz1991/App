// AI-Assist der Disposition: Öffnen/Schließen, Aktionsliste („/“), Orb-Hinweis und persönliche
// Einstellungen. Inhalte und Aktionen kommen aus Livewire (App\Livewire\Operations\AiAssist).
export const AI_ASSIST_PREFS_KEY = 'rt-ai-assist-prefs';
const DEFAULT_PREFS = Object.freeze({ context: true, proactive: true });
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
    return {
        open: false,
        mode: 'mini',
        corner: 'bottom-right',
        wide: false,
        settingsOpen: false,
        busy: false,
        loaded: false,
        disposed: false,
        callVersion: 0,
        callError: '',
        messagesPinned: true,
        unseenOutput: false,
        text: '',
        slashIndex: 0,
        prefs: { ...DEFAULT_PREFS },
        mobile: false,
        _resizeHandler: null,
        _sidebarHandler: null,
        init() {
            root = this.$el;
            this._resizeHandler = () => this.syncLayout();
            window.addEventListener?.('resize', this._resizeHandler, { passive: true });
            window.visualViewport?.addEventListener?.('resize', this._resizeHandler, { passive: true });
            this._sidebarHandler = () => this.toggle(false, { restoreFocus: false });
            window.addEventListener?.('railtime-sidebar-opened', this._sidebarHandler);
            this.syncLayout();
            if (root?.dataset.intakeOpen === 'true') {
                this.open = true;
                this.loaded = true;
                this.syncLayout();
            }
            try {
                this.prefs = readAiAssistPrefs(window.localStorage);
            } catch {
                this.prefs = { ...DEFAULT_PREFS };
            }
        },
        destroy() {
            this.disposed = true;
            this.callVersion++;
            window.removeEventListener?.('resize', this._resizeHandler);
            window.visualViewport?.removeEventListener?.('resize', this._resizeHandler);
            window.removeEventListener?.('railtime-sidebar-opened', this._sidebarHandler);
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
        toggle(open = !this.open, { load = true, restoreFocus = true } = {}) {
            if (this.disposed) return;
            const next = Boolean(open);
            if (next === this.open) return;
            const opening = next && !this.open;
            this.open = next;
            this.syncLayout();
            if (!next) {
                this.settingsOpen = false;
                window.dispatchEvent(new CustomEvent('ai-intake-panel-hidden'));
            }
            this.$nextTick(() => {
                if (this.disposed || this.open !== next) return;
                if (next) {
                    this.scrollDown();
                    this.resize();
                    this.$refs.input?.focus({ preventScroll: true });
                } else if (restoreFocus) {
                    this.$refs.launcher?.focus({ preventScroll: true });
                }
            });
            if (opening) {
                const detail = { surface: 'assist', corner: this.corner, mobile: window.innerWidth <= 760 };
                window.dispatchEvent(new CustomEvent('rt-floating-surface-opened', { detail }));
                window.dispatchEvent(new CustomEvent('railtime-assistant-opened', { detail }));
                if (load) void this.ensureLoaded();
            }
        },
        syncLayout() {
            if (!root) return;
            this.mobile = window.innerWidth <= 760;
            this.wide = !this.mobile;
            if (this.mobile) this.mode = 'fullscreen';
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
        restoreWindowSession(saved) {
            this.corner = saved.corner;
            this.toggle(saved.open || root?.dataset.intakeOpen === 'true');
            this.mode = this.mobile ? 'fullscreen' : saved.mode;
            this.syncLayout();
        },
        resizeSurface() {
            if (!this.wide) return;
            this.mode = this.mode === 'mini' ? 'fullscreen' : 'mini';
            this.syncLayout();
        },
        async openIntake(detail = {}) {
            if (this.disposed || this.busy) return false;
            // openIntake setzt serverseitig bereits loaded und den richtigen Tab. Ein
            // paralleles load() würde den eigentlichen Öffnungs-Request blockieren.
            this.toggle(true, { load: false });
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
        openHint(hint) {
            if (this.disposed || !hint) return Promise.resolve(false);
            // Die Aktion lädt den Assist selbst. So konkurriert sie nicht mit einem zweiten
            // Load-Request, wenn der Hinweis aus dem Anchor-Dropdown geöffnet wird.
            this.toggle(true, { load: false });
            if (!hint.run) return Promise.resolve(true);
            return this.run(hint.run).then(success => {
                if (success && !this.disposed) this.loaded = true;
                return success;
            });
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
