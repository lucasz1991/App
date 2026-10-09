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

export function aiAssist() {
    // Wurzel festhalten: in der verschachtelten Orb-Ebene zeigt $root auf das innere Element.
    let root = null;
    return {
        open: false,
        settingsOpen: false,
        busy: false,
        bubble: false,
        text: '',
        slashIndex: 0,
        prefs: { ...DEFAULT_PREFS },
        bubbleTimer: null,
        init() {
            root = this.$el;
            this.prefs = readAiAssistPrefs(window.localStorage);
            // Einmal je Sitzung meldet sich der Orb mit dem wichtigsten Hinweis.
            this.bubbleTimer = setTimeout(() => {
                if (this.open || !this.prefs.proactive || !this.hints.length) return;
                try {
                    if (window.sessionStorage.getItem(AI_ASSIST_BUBBLE_KEY)) return;
                    window.sessionStorage.setItem(AI_ASSIST_BUBBLE_KEY, '1');
                } catch {
                    return;
                }
                this.bubble = true;
                this.bubbleTimer = setTimeout(() => { this.bubble = false; }, 9000);
            }, 1400);
        },
        destroy() {
            clearTimeout(this.bubbleTimer);
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
            this.open = open;
            this.bubble = false;
            if (!open) {
                this.settingsOpen = false;
                this.text = '';
            }
            this.$nextTick(() => {
                if (open) {
                    this.scrollDown();
                    this.$refs.input?.focus();
                } else {
                    this.$refs.launcher?.focus();
                }
            });
        },
        async call(method, ...args) {
            if (this.busy) return;
            this.busy = true;
            this.$nextTick(() => this.scrollDown());
            try {
                await this.$wire[method](...args);
            } finally {
                this.busy = false;
                this.$nextTick(() => this.scrollDown());
            }
        },
        run(key) {
            this.text = '';
            this.slashIndex = 0;
            this.call('run', key);
        },
        ask(question) {
            this.call('ask', question);
        },
        submit() {
            const question = this.text.trim();
            if (!question || this.busy) return;
            if (this.slash) {
                const action = this.slashList[this.slashIndex];
                if (action) this.run(action.key);
                return;
            }
            this.text = '';
            this.$nextTick(() => this.resize());
            this.call('ask', question);
        },
        keydown(event) {
            if (this.slash && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                const count = Math.max(1, this.slashList.length);
                this.slashIndex = (this.slashIndex + (event.key === 'ArrowDown' ? 1 : count - 1)) % count;
                return;
            }
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.submit();
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
            if (!(event.ctrlKey || event.metaKey) || event.altKey || event.key.toLowerCase() !== 'j') return;
            event.preventDefault();
            this.toggle();
        },
        hover() {
            if (!this.open && this.prefs.proactive && this.hints.length) this.bubble = true;
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
        scrollDown() {
            const box = this.$refs.messages;
            if (box) box.scrollTop = box.scrollHeight;
        },
    };
}
