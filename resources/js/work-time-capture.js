const encoder = new TextEncoder();
const decoder = new TextDecoder();

function requestValue(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function transactionDone(transaction) {
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve();
        transaction.onabort = transaction.onerror = () => reject(transaction.error || new Error('Zwischenspeicher nicht verfügbar.'));
    });
}

export async function encryptCapture(key, payload, cryptography = globalThis.crypto) {
    const iv = cryptography.getRandomValues(new Uint8Array(12));
    const ciphertext = await cryptography.subtle.encrypt({ name: 'AES-GCM', iv }, key, encoder.encode(JSON.stringify(payload)));
    return { iv: Array.from(iv), ciphertext: Array.from(new Uint8Array(ciphertext)) };
}

export async function decryptCapture(key, record, cryptography = globalThis.crypto) {
    const clear = await cryptography.subtle.decrypt({ name: 'AES-GCM', iv: new Uint8Array(record.iv) }, key, new Uint8Array(record.ciphertext));
    return JSON.parse(decoder.decode(clear));
}

export function projectCapture(base, event) {
    if (event.action === 'start') {
        return { capture_id: event.entry_capture_id, revision: 1, status: 'running', title: event.title || 'Dienst', work_context: event.work_context || 'shift', starts_at: event.occurred_at, timezone: event.timezone, pause_seconds: 0, paused_at: null, kind: event.work_context === 'training' ? 'training' : (event.work_context === 'internal' ? 'internal' : 'work') };
    }
    if (! base || base.capture_id !== event.entry_capture_id || base.revision !== event.revision) return base;
    const closingPause = ['resume', 'stop'].includes(event.action) && base.paused_at;
    return { ...base, revision: base.revision + 1, status: { pause: 'paused', resume: 'running', stop: 'completed', submit: 'submitted' }[event.action] || base.status,
        ends_at: event.action === 'stop' ? event.occurred_at : base.ends_at,
        paused_at: event.action === 'pause' ? event.occurred_at : (closingPause ? null : base.paused_at),
        pause_seconds: (base.pause_seconds || 0) + (closingPause ? Math.max(0, Math.floor((Date.parse(event.occurred_at) - Date.parse(base.paused_at)) / 1000)) : 0),
        kind: event.action === 'activity' ? event.kind : (event.action === 'pause' ? 'break' : (event.action === 'resume' ? (['internal', 'training'].includes(base.work_context) ? base.work_context : 'work') : base.kind)) };
}

export class CaptureStore {
    constructor(database) { this.database = database; }

    static async open(indexed = globalThis.indexedDB) {
        const request = indexed.open('railtime-worktime-v2', 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore('events', { keyPath: 'id' }).createIndex('scope', 'scope');
            request.result.createObjectStore('meta', { keyPath: 'scope' });
        };
        return new CaptureStore(await requestValue(request));
    }

    async rows(scope) {
        const tx = this.database.transaction('events', 'readonly');
        return (await requestValue(tx.objectStore('events').index('scope').getAll(scope))).sort((a, b) => a.sequence - b.sequence);
    }

    async sequence(scope) {
        const tx = this.database.transaction('meta', 'readonly');
        return (await requestValue(tx.objectStore('meta').get(scope)))?.sequence || 0;
    }

    async append(record) {
        const tx = this.database.transaction(['events', 'meta'], 'readwrite');
        const done = transactionDone(tx);
        tx.objectStore('events').add(record);
        const previous = await requestValue(tx.objectStore('meta').get(record.scope));
        tx.objectStore('meta').put({ ...previous, scope: record.scope, sequence: record.sequence });
        await done;
    }

    async state(scope) {
        const tx = this.database.transaction('meta', 'readonly');
        return (await requestValue(tx.objectStore('meta').get(scope)))?.state || null;
    }

    async saveState(scope, state, sequence) {
        const tx = this.database.transaction('meta', 'readwrite');
        const done = transactionDone(tx);
        const previous = await requestValue(tx.objectStore('meta').get(scope));
        tx.objectStore('meta').put({ ...previous, scope, state, sequence: Math.max(previous?.sequence || 0, sequence) });
        await done;
    }

    async remove(id) {
        const tx = this.database.transaction('events', 'readwrite');
        const done = transactionDone(tx);
        tx.objectStore('events').delete(id);
        await done;
    }

    async update(record) {
        const tx = this.database.transaction('events', 'readwrite');
        const done = transactionDone(tx);
        tx.objectStore('events').put(record);
        await done;
    }

    close() { this.database.close(); }
}

export function workTimeCapture(options) {
    return {
        ready: false, busy: false, syncing: false, offline: !navigator.onLine,
        pending: 0, conflicts: 0, error: '', active: null, context: 'internal', title: '', trainingId: '', activityKind: 'work', startDialogOpen: false,
        key: null, store: null, device: null, sequence: 0, scope: '', base: null,
        _online: null, _offline: null, _pagehide: null, _pageshow: null, _visibility: null, _channel: null, _timer: null, _destroyed: false, now: Date.now(),

        async init() {
            this._online = () => { this.offline = false; void this.sync(); };
            this._offline = () => { this.offline = true; };
            this._pagehide = () => { this.key = null; this.ready = false; };
            this._pageshow = event => { if (event.persisted) { if (navigator.onLine) window.location.reload(); else this.error = 'Zum Anmelden online verbinden.'; } };
            this._visibility = () => { if (document.visibilityState === 'visible') void this.sync(); };
            window.addEventListener('online', this._online);
            window.addEventListener('offline', this._offline);
            window.addEventListener('pagehide', this._pagehide);
            window.addEventListener('pageshow', this._pageshow);
            document.addEventListener('visibilitychange', this._visibility);
            if (!navigator.onLine) { this.error = 'Zum Anmelden online verbinden.'; return; }
            if (!globalThis.crypto?.subtle || !globalThis.indexedDB || !navigator.locks) {
                this.error = 'Offline-Erfassung wird von diesem Browser nicht unterstützt.'; return;
            }
            try {
                this.store = await CaptureStore.open();
                await navigator.locks.request('railtime-worktime-identity:' + options.actorId, async () => {
                    const identityKey = 'railtime-worktime-device-' + options.actorId;
                    const response = await this.post(options.bootstrapUrl, { device_id: localStorage.getItem(identityKey) });
                    this.assertIdentity(response);
                    if (this._destroyed) return;
                    this.device = response.device_id;
                    localStorage.setItem(identityKey, this.device);
                    this.scope = options.actorId + ':' + this.device;
                    const raw = Uint8Array.from(atob(response.key), value => value.charCodeAt(0));
                    this.key = await crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
                    raw.fill(0);
                    response.key = null;
                    this.base = response.active;
                    this.sequence = response.last_sequence;
                });
                if (this._destroyed || !this.key) return;
                this.ready = true;
                await navigator.locks.request('railtime-worktime:' + this.scope, async () => {
                    if (!await this.store.state(this.scope)) await this.store.saveState(this.scope, await encryptCapture(this.key, { active: this.base }), this.sequence);
                });
                this._timer = setInterval(() => { this.now = Date.now(); }, 1000);
                if (globalThis.BroadcastChannel) {
                    this._channel = new BroadcastChannel('railtime-worktime:' + this.scope);
                    this._channel.onmessage = () => { void this.restore(); };
                }
                await this.sync();
            } catch (error) { this.error = error.message || 'Zeituhr konnte nicht eingerichtet werden.'; }
        },

        async post(url, payload) {
            const response = await fetch(url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {
                'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            }, body: JSON.stringify(payload) });
            if (!response.ok) {
                if ([401, 403, 419].includes(response.status)) { this.key = null; this.ready = false; }
                const error = new Error([401, 403, 419].includes(response.status) ? 'Erneut online anmelden.' : 'Synchronisation fehlgeschlagen. Ereignisse bleiben gespeichert.');
                error.status = response.status;
                throw error;
            }
            return response.json();
        },

        assertIdentity(state) {
            if (Number(state.actor_id) !== Number(options.actorId) || this.device && state.device_id !== this.device) {
                state.key = null;
                this.key = null;
                this.ready = false;
                const error = new Error('Sitzung geändert. Erneut online anmelden.');
                error.status = 403;
                throw error;
            }
        },

        async restore() {
            if (!this.key || !this.store) return;
            const shared = await this.store.state(this.scope);
            if (shared) this.base = (await decryptCapture(this.key, shared)).active;
            const rows = await this.store.rows(this.scope);
            this.pending = rows.filter(row => row.status !== 'conflict').length;
            this.conflicts = rows.filter(row => row.status === 'conflict').length;
            this.active = this.base;
            for (const row of rows) {
                const event = await decryptCapture(this.key, row);
                if (row.status !== 'conflict') this.active = projectCapture(this.active, event);
            }
            this.activityKind = this.active?.kind || 'work';
        },

        async refreshState() {
            const rows = await this.store.rows(this.scope);
            const batches = rows.length ? Array.from({ length: Math.ceil(rows.length / 500) }, (_, i) => rows.slice(i * 500, (i + 1) * 500)) : [[]];
            let state;
            for (const batch of batches) {
                state = await this.post(options.bootstrapUrl, { device_id: this.device, event_keys: batch.map(row => row.id) });
                this.assertIdentity(state);
                state.key = null;
                if (!this.key || this._destroyed) return;
                for (const result of state.receipts || []) {
                    const row = batch.find(item => item.id === result.event_key);
                    if (!row) continue;
                    if (['accepted', 'reviewed'].includes(result.status)) await this.store.remove(row.id);
                    else if (result.status === 'conflict' && row.status !== 'conflict') {
                        const event = await decryptCapture(this.key, row);
                        await this.store.update({ ...row, ...await encryptCapture(this.key, { ...event, conflict_reason: result.reason }), status: 'conflict' });
                    }
                }
            }
            this.base = state.active;
            this.sequence = Math.max(state.last_sequence, await this.store.sequence(this.scope));
            await this.store.saveState(this.scope, await encryptCapture(this.key, { active: state.active }), this.sequence);
        },

        elapsed() {
            if (!this.active) return '0:00';
            const end = Date.parse(this.active.ends_at || '') || this.now;
            const pause = (this.active.pause_seconds || 0) + (this.active.paused_at ? Math.max(0, Math.floor((end - Date.parse(this.active.paused_at)) / 1000)) : 0);
            const seconds = Math.max(0, Math.floor((end - Date.parse(this.active.starts_at)) / 1000) - pause);
            return Math.floor(seconds / 3600) + ':' + String(Math.floor(seconds / 60) % 60).padStart(2, '0');
        },

        async capture(detail) {
            if (!this.ready || !this.key || this.busy || this.conflicts) return;
            this.busy = true;
            this.error = '';
            try {
                await navigator.locks.request('railtime-worktime:' + this.scope, async () => {
                    if (navigator.onLine) {
                        try { await this.refreshState(); }
                        catch (error) { if (!this.key || error.status && error.status < 500) throw error; this.offline = true; }
                    }
                    await this.restore();
                    const rows = await this.store.rows(this.scope);
                    if (rows.some(row => row.status === 'conflict')) throw new Error('Offene Erfassungen zuerst prüfen.');
                    const sequence = Math.max(this.sequence, await this.store.sequence(this.scope)) + 1;
                    const action = detail.action;
                    if (action === 'start' && this.active && ['running', 'paused'].includes(this.active.status)) throw new Error('Es läuft bereits eine Zeiterfassung.');
                    if (action === 'submit') {
                        for (const row of rows) {
                            const pending = await decryptCapture(this.key, row);
                            if (pending.action === action && pending.entry_capture_id === detail.entry_capture_id && pending.revision === detail.revision) return;
                        }
                    }
                    // Both tabs share the device clock. Per-request server calibration can
                    // reorder rapid actions across tabs; untrusted clock drift is reviewed
                    // server-side rather than rewriting the captured source instant.
                    const at = new Date(Date.now());
                    const timezone = detail.timezone || this.active?.timezone || options.timezone;
                    const offsetText = new Intl.DateTimeFormat('en', { timeZone: timezone, timeZoneName: 'longOffset' }).formatToParts(at).find(part => part.type === 'timeZoneName').value;
                    const offsetMatch = /GMT([+-])(\d{2}):(\d{2})/.exec(offsetText);
                    const offset = offsetMatch ? (offsetMatch[1] === '-' ? -1 : 1) * (Number(offsetMatch[2]) * 60 + Number(offsetMatch[3])) : 0;
                    const event = { ...detail, event_key: crypto.randomUUID(), sequence, occurred_at: at.toISOString(), timezone, offset_minutes: offset,
                        entry_capture_id: action === 'start' ? crypto.randomUUID() : (detail.entry_capture_id || this.active?.capture_id),
                        revision: action === 'start' ? 0 : (detail.revision ?? this.active?.revision) };
                    if (!event.entry_capture_id || !Number.isInteger(event.revision)) throw new Error('Zeitstand neu laden.');
                    const encrypted = await encryptCapture(this.key, event);
                    // Counter and encrypted event commit together; an encryption/quota failure creates no lost sequence.
                    await this.store.append({ ...encrypted, id: event.event_key, scope: this.scope, sequence, status: 'pending' });
                    this.sequence = sequence;
                    this._channel?.postMessage('changed');
                });
                await this.restore();
                await this.sync();
            } catch (error) { this.error = error.message || 'Erfassung nicht gespeichert.'; }
            finally { this.busy = false; }
        },

        async startGeneral() {
            if (this.title.trim().length < 3 || (this.context === 'training' && !this.trainingId)) { this.error = 'Tätigkeit und gegebenenfalls Schulung auswählen.'; return; }
            await this.capture({ action: 'start', work_context: this.context, title: this.title.trim(), ...(this.context === 'training' ? { training_session_id: Number(this.trainingId) } : {}) });
        },

        async sync() {
            if (!this.ready || !this.key || this.syncing || !navigator.onLine) return;
            this.syncing = true;
            try {
                await navigator.locks.request('railtime-worktime:' + this.scope, async () => {
                    await this.refreshState();
                    for (let batch = 0; batch < 100; batch++) {
                    const rows = (await this.store.rows(this.scope)).filter(row => row.status !== 'conflict').slice(0, 50);
                    if (!rows.length) break;
                    const events = [];
                    for (const row of rows) events.push(await decryptCapture(this.key, row));
                    const response = await this.post(options.syncUrl, { device_id: this.device, events });
                    if (!this.key || this._destroyed) return;
                    for (const result of response.results) {
                        const row = rows.find(item => item.id === result.event_key);
                        if (!row) continue;
                        if (result.status === 'accepted') {
                            const event = events.find(item => item.event_key === result.event_key);
                            this.base = { ...projectCapture(this.base, event), id: result.entry_id, revision: result.revision };
                            await this.store.remove(row.id);
                        } else if (result.status === 'conflict') {
                            const event = events.find(item => item.event_key === result.event_key);
                            const encrypted = await encryptCapture(this.key, { ...event, conflict_reason: result.reason });
                            await this.store.update({ ...row, ...encrypted, status: 'conflict' });
                            this.error = result.reason || 'Erfassung muss geprüft werden.';
                        }
                    }
                    if (response.results.some(result => result.status === 'awaiting') || response.results.every(result => !['accepted', 'conflict', 'reviewed'].includes(result.status))) break;
                    }
                    await this.refreshState();
                    this._channel?.postMessage('changed');
                });
                await this.restore();
                window.dispatchEvent(new CustomEvent('worktime-synced'));
            } catch (error) {
                this.offline = !navigator.onLine;
                this.error = navigator.onLine ? (error.status ? error.message : 'Verbindung unterbrochen. Ereignisse bleiben gespeichert.') : '';
                if (this.key) await this.restore();
            } finally { this.syncing = false; }
        },

        destroy() {
            this._destroyed = true;
            window.removeEventListener('online', this._online);
            window.removeEventListener('offline', this._offline);
            window.removeEventListener('pagehide', this._pagehide);
            window.removeEventListener('pageshow', this._pageshow);
            document.removeEventListener('visibilitychange', this._visibility);
            clearInterval(this._timer);
            this._channel?.close();
            this.key = null;
            this.ready = false;
            this.store?.close();
        },
    };
}
