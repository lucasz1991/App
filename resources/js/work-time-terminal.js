const actionLabels = {
    start: 'Start erfassen', pause: 'Pause erfassen', resume: 'Fortsetzen erfassen',
    stop: 'Ende erfassen', submit: 'Zeit einreichen',
};

async function request(url, payload, token, signal) {
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (token) headers.Authorization = 'Bearer ' + token;
    const response = await fetch(url, {
        method: 'POST', credentials: 'omit', cache: 'no-store', headers,
        body: JSON.stringify(payload), signal,
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 404) throw new Error('Terminal nicht freigegeben.');
        if (response.status === 429) throw new Error('Bitte später erneut versuchen.');
        throw new Error(response.status < 500 && result.message
            ? result.message : 'Anfrage fehlgeschlagen. Bitte erneut am Terminal anmelden.');
    }
    return result;
}

function currentLocation() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) return reject(new Error('Standort nicht verfügbar.'));
        navigator.geolocation.getCurrentPosition(({ coords }) => {
            resolve({ latitude: coords.latitude, longitude: coords.longitude, accuracy: coords.accuracy });
        }, error => reject(new Error(error.code === 1
            ? 'Standortfreigabe erforderlich. Bitte im Browser erlauben und erneut anmelden.'
            : 'Standort nicht verfügbar. Bitte erneut anmelden.')),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });
}

export function workTimeTerminal({ identifyUrl, captureUrl }) {
    let expiry;
    let pending;
    return {
        userId: '', pin: '', terminalId: '', token: null, active: null, choices: [],
        choice: 'internal', action: 'start', stage: '', message: '', tone: 'neutral', locationRequired: false,
        get busy() { return this.stage !== ''; },
        get stateLabel() {
            return { running: 'Arbeitszeit läuft', paused: 'Pause läuft', completed: 'Arbeitszeit beendet' }[this.active?.status] || 'Bereit zum Start';
        },
        get submitLabel() {
            if (this.stage === 'locating') return 'Standort wird geprüft …';
            if (this.stage === 'sending') return 'Wird übertragen …';
            return actionLabels[this.action];
        },
        focus(selector) {
            this.$nextTick(() => this.$root.querySelector(selector)?.focus());
        },
        clear() {
            clearTimeout(expiry);
            pending?.abort();
            pending = null;
            Object.assign(this, {
                userId: '', pin: '', token: null, active: null, choices: [], choice: 'internal',
                action: 'start', locationRequired: false, stage: '', message: '', tone: 'neutral',
            });
        },
        finish(message = '', tone = 'neutral') {
            this.clear();
            this.message = message;
            this.tone = tone;
            this.focus('#terminal-employee');
        },
        destroy() { this.clear(); },
        async identify() {
            if (this.busy || this.token) return;
            pending = new AbortController();
            const { signal } = pending;
            this.message = '';
            this.stage = 'identifying';
            try {
                const result = await request(identifyUrl, {
                    user_id: Number(this.userId), pin: this.pin, terminal_id: this.terminalId,
                }, null, signal);
                if (signal.aborted) return;
                this.userId = '';
                this.pin = '';
                this.token = result.token;
                this.active = result.active;
                this.choices = result.choices || [];
                this.locationRequired = !!result.location_required;
                this.action = { running: 'pause', paused: 'resume', completed: 'submit' }[this.active?.status] || 'start';
                expiry = setTimeout(() => this.finish('Zeit zum Erfassen abgelaufen. Bitte erneut freigeben.'), (result.expires_in || 60) * 1000);
                this.stage = '';
                this.focus('#terminal-state');
            } catch (error) {
                if (!signal.aborted) this.finish(error instanceof TypeError ? 'Verbindung fehlgeschlagen. Bitte erneut freigeben.' : error.message, 'error');
            }
        },
        async capture() {
            if (this.busy || !this.token) return;
            clearTimeout(expiry);
            pending = new AbortController();
            const { signal } = pending;
            this.message = '';
            this.stage = this.locationRequired ? 'locating' : 'sending';
            try {
                const location = this.locationRequired ? await currentLocation() : {};
                if (signal.aborted) return;
                this.stage = 'sending';
                const event = { event_key: crypto.randomUUID(), action: this.action, revision: this.active?.revision || 0 };
                if (this.action === 'start') {
                    const duty = this.choices.find(item => String(item.id) === this.choice);
                    Object.assign(event, duty
                        ? { assignment_id: duty.id, plan_revision: duty.plan_revision, work_context: 'shift' }
                        : { work_context: 'internal', title: 'Interne Arbeit' });
                }
                const result = await request(captureUrl, { terminal_id: this.terminalId, event, location }, this.token, signal);
                if (signal.aborted) return;
                const accepted = result.status === 'accepted';
                this.finish(accepted ? 'Erfassung bestätigt.' : (result.reason || 'Erfassung zur Prüfung erhalten.'), accepted ? 'ok' : 'warning');
            } catch (error) {
                if (!signal.aborted) this.finish(error instanceof TypeError ? 'Übertragung unbestätigt. Bitte erneut anmelden und den Arbeitszustand prüfen.' : error.message, 'error');
            }
        },
    };
}
