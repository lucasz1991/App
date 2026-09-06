const STATUS_LABELS = Object.freeze({
    ok: 'OK', warning: 'Warnung', error: 'Fehler', disabled: 'Deaktiviert',
    not_configured: 'Nicht eingerichtet', not_checked: 'Nicht geprüft', running: 'Läuft',
});
const EVIDENCE_LABELS = Object.freeze({
    configuration: 'Konfiguration', connection: 'Verbindung', runtime: 'Verarbeitung',
});
const POLL_WINDOW = 120_000;
const POLL_INTERVAL = 3_000;

/** Presentation only: the registry, permissions, cache and locks live on the server. */
export function systemHealth(options = {}) {
    return {
        rows: options.initialRows ?? [],
        overviewActive: false,
        visible: true,
        destroyed: false,
        busy: false,
        currentId: null,
        error: '',
        observationEnded: false,
        completed: 0,
        planned: 0,
        epoch: 0,
        timer: null,
        deadlines: {},
        requestTail: Promise.resolve(),
        visibilityHandler: null,

        init() {
            this.visible = document.visibilityState !== 'hidden';
            this.visibilityHandler = () => {
                this.visible = document.visibilityState !== 'hidden';
                if (!this.visible) this.stop();
                // A browser visibility change is NOT an overview activation.
                // Only resume an existing, bounded worker observation.
                else if (this.overviewActive) this.schedulePoll(this.epoch);
            };
            document.addEventListener('visibilitychange', this.visibilityHandler);
        },

        destroy() {
            this.destroyed = true;
            this.stop();
            document.removeEventListener('visibilitychange', this.visibilityHandler);
        },

        setOverviewActive(active) {
            active = Boolean(active);
            if (this.overviewActive === active || this.destroyed) return;
            this.overviewActive = active;
            if (!active) this.stop();
            else if (this.visible) void this.run(false);
        },

        stop() {
            this.epoch += 1;
            clearTimeout(this.timer);
            this.timer = null;
            this.busy = false;
            this.currentId = null;
        },

        isCurrent(epoch) {
            return !this.destroyed && this.overviewActive && this.visible && this.epoch === epoch;
        },

        async request(action, args, epoch) {
            // Rapid tab changes must not turn the sequential run into parallel requests.
            const request = this.requestTail.catch(() => null).then(async () => {
                if (!this.isCurrent(epoch)) return null;
                const value = await this.$wire[action](...args);
                return this.isCurrent(epoch) ? value : null;
            });
            this.requestTail = request.catch(() => null);
            return request;
        },

        async run(force = false, onlyId = null) {
            if (!this.overviewActive || !this.visible || this.destroyed || this.busy) return;
            clearTimeout(this.timer);
            this.timer = null;
            const epoch = ++this.epoch;
            this.busy = true;
            this.error = '';
            this.observationEnded = false;
            this.completed = 0;
            try {
                const snapshot = await this.request('refreshSnapshot', [], epoch);
                if (!snapshot) return;
                this.rows = snapshot;
                const targets = snapshot.filter(row => (!onlyId || row.id === onlyId)
                    && (force || (!row.fresh && !row.pending)));
                this.planned = targets.length;
                for (const target of targets) {
                    if (!this.isCurrent(epoch)) return;
                    this.currentId = target.id;
                    try {
                        const row = await this.request('checkOne', [target.id, force], epoch);
                        if (!row) return;
                        this.merge(row);
                    } catch {
                        if (!this.isCurrent(epoch)) return;
                        this.error = 'Mindestens eine Prüfung konnte nicht geladen werden. Die übrigen Ergebnisse bleiben erhalten; betroffene Prüfungen lassen sich einzeln wiederholen.';
                    }
                    this.completed += 1;
                }
            } catch {
                if (this.isCurrent(epoch)) {
                    this.error = 'Die Diagnose ist gerade nicht erreichbar. Bestehende Ergebnisse sind keine neue Betriebsbestätigung. Bitte erneut prüfen.';
                }
            } finally {
                if (this.isCurrent(epoch)) {
                    this.busy = false;
                    this.currentId = null;
                    this.schedulePoll(epoch);
                }
            }
        },

        merge(row) {
            const index = this.rows.findIndex(existing => existing.id === row.id);
            if (index !== -1) this.rows.splice(index, 1, row);
        },

        pendingRows() {
            const now = Date.now();
            return this.rows.filter(row => {
                if (!row.pending || !row.run_id) return false;
                const key = `${row.id}:${row.run_id}`;
                if (!this.deadlines[key]) {
                    const started = Date.parse(row.checked_at ?? '');
                    this.deadlines[key] = Number.isFinite(started)
                        ? Math.min(now + POLL_WINDOW, started + POLL_WINDOW) : now + POLL_WINDOW;
                }
                if (this.deadlines[key] <= now) {
                    this.observationEnded = true;
                    return false;
                }
                return true;
            });
        },

        schedulePoll(epoch) {
            clearTimeout(this.timer);
            this.timer = null;
            if (!this.isCurrent(epoch) || this.busy || this.pendingRows().length === 0) return;
            this.timer = setTimeout(() => { void this.pollPending(epoch); }, POLL_INTERVAL);
        },

        async pollPending(epoch) {
            if (!this.isCurrent(epoch) || this.busy) return;
            try {
                for (const pending of this.pendingRows()) {
                    if (!this.isCurrent(epoch)) return;
                    const row = await this.request('pollCheck', [pending.id, pending.run_id], epoch);
                    if (!row) return;
                    this.merge(row);
                }
            } catch {
                if (this.isCurrent(epoch)) {
                    this.error = 'Der Auftragsstatus konnte nicht aktualisiert werden. Das ist kein Nachweis eines ausgefallenen Workers.';
                }
            } finally {
                this.schedulePoll(epoch);
            }
        },

        get groups() {
            const groups = new Map();
            for (const row of this.rows) {
                if (!groups.has(row.group)) groups.set(row.group, []);
                groups.get(row.group).push(row);
            }
            return [...groups].map(([label, rows]) => ({ label, rows }));
        },

        get counts() {
            return this.rows.reduce((counts, row) => {
                counts[row.status] = (counts[row.status] ?? 0) + 1;
                if (!row.fresh) counts.stale += 1;
                return counts;
            }, { stale: 0 });
        },

        get summary() {
            if (this.busy) return { status: 'running', label: 'Systeme werden geprüft' };
            if (this.error || this.counts.error) return { status: 'error', label: 'Handlungsbedarf erkannt' };
            if (this.counts.running) return { status: 'running', label: this.observationEnded ? 'Ausführung noch offen' : 'Warte auf Ausführungsnachweis' };
            if (!this.rows.length || this.counts.stale || this.counts.not_checked) return { status: 'not_checked', label: 'Betriebsbild noch unvollständig' };
            if (this.counts.warning || this.counts.not_configured) return { status: 'warning', label: 'Hinweise zur Betriebsbereitschaft' };
            return { status: 'ok', label: 'Geprüfte Systeme ohne Befund' };
        },

        get progress() {
            return this.planned ? Math.min(100, Math.round(this.completed / this.planned * 100)) : 0;
        },

        get latestCheck() {
            const times = this.rows.map(row => row.checked_at).filter(Boolean).sort();
            return times.at(-1) ?? null;
        },

        statusLabel(status) { return STATUS_LABELS[status] ?? STATUS_LABELS.not_checked; },
        evidenceLabel(evidence) { return EVIDENCE_LABELS[evidence] ?? 'Nicht nachgewiesen'; },
        timeLabel(value) {
            const date = new Date(value);
            if (!value || Number.isNaN(date.getTime())) return 'Noch kein Prüfzeitpunkt';
            return new Intl.DateTimeFormat('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(date);
        },
        durationLabel(value) {
            if (!Number.isFinite(value)) return 'Dauer offen';
            return value < 1000 ? `${value} ms` : `${(value / 1000).toFixed(1)} s`;
        },
    };
}
