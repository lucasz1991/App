// AI-Assist der Disposition: Öffnen/Schließen, Aktionsliste („/“), Hinweisblase und persönliche
// Einstellungen. Inhalte und Aktionen kommen aus Livewire (App\Livewire\Operations\AiAssist).
export const AI_ASSIST_PREFS_KEY = 'rt-ai-assist-prefs';
export const AI_ASSIST_BUBBLE_KEY = 'rt-ai-assist-bubble';
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
        init() {
            root = this.$el;
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
            this.open = open;
            this.bubble = false;
            if (!open) {
                this.settingsOpen = false;
            }
            this.$nextTick(() => {
                if (this.disposed || this.open !== open) return;
                if (open) {
                    this.scrollDown();
                    this.resize();
                    this.$refs.input?.focus();
                } else {
                    this.$refs.launcher?.focus();
                }
            });
            if (open) void this.ensureLoaded();
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
