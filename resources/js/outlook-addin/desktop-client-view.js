// „RailTime-App auf diesem PC“ im Kontoeinstellungs-Aufgabenbereich.
// Liest nur den Zustand des eigenen Kontos und koppelt auf Wunsch diesen PC.
// Keine Funktion des Add-ins hängt davon ab: Fehler zeigen höchstens einen ruhigen Satz.

export const DESKTOP_PAIRING_STORAGE_KEY = 'railtime.desktopPairing.v1';

const PAIRING_KEY = /^rtop_[A-Za-z0-9_-]{43}$/;
const UUID = /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i;
const ACCOUNT_STATES = ['none', 'installed', 'running'];
const PC_STATES = ['unknown', 'paired', 'revoked'];
const RESULT_STATES = ['pending', 'paired', 'expired', 'rejected'];
const DEFAULT_TTL_MS = 120000;
const MAX_TTL_MS = 10 * 60000;
const MAX_POLL_FAILURES = 3;
const fail = code => Object.assign(new Error(code), { code });

const TEXT = Object.freeze({
    install: 'Die RailTime-App ist auf keinem deiner PCs eingerichtet.',
    pair: 'Die RailTime-App ist auf einem deiner PCs eingerichtet. Ist es dieser PC, kannst du ihn einmalig koppeln.',
    pairUnavailable: 'Die RailTime-App ist auf einem deiner PCs eingerichtet.',
    pairing: 'Bestätige die Kopplung in der RailTime-App auf diesem PC.',
    running: 'App läuft',
    stopped: 'App ist gerade nicht gestartet',
    preparing: 'Kopplung wird vorbereitet …',
    loadFailed: 'Der Zustand der RailTime-App konnte gerade nicht geprüft werden.',
    refreshFailed: 'Der Zustand der RailTime-App konnte gerade nicht aktualisiert werden.',
    startFailed: 'Die Kopplung konnte gerade nicht gestartet werden. Bitte später erneut versuchen.',
    throttled: 'Bitte einen Moment warten und dann erneut koppeln.',
    noClient: 'Auf deinem Konto ist gerade keine RailTime-App eingerichtet.',
    rejected: 'Die Kopplung wurde abgelehnt: Dieser PC ist nicht deinem RailTime-Konto zugeordnet.',
    expired: 'Die Kopplungsanfrage ist abgelaufen. Du kannst es erneut versuchen.',
    pollFailed: 'Die Kopplung konnte gerade nicht geprüft werden. Bitte erneut versuchen.',
    notStored: 'Die Kopplung wurde bestätigt, konnte in diesem Outlook aber nicht gespeichert werden.',
    takenElsewhere: 'Die Kopplung wurde in einem anderen Outlook-Fenster übernommen.',
    forgotten: 'Die Kopplung wurde auf diesem PC vergessen.',
});

function httpsUrl(value) {
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
        const url = new URL(value.trim());
        return url.protocol === 'https:' ? url.toString() : null;
    } catch {
        return null;
    }
}

function displayName(value) {
    if (typeof value !== 'string') return null;
    const text = value.replace(/[\u0000-\u001f\u007f-\u009f]/g, ' ').replace(/\s+/g, ' ').trim();
    return text === '' ? null : [...text].slice(0, 80).join('');
}

export function normalizeDesktopClientState(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value) || value.schema !== 1
        || !ACCOUNT_STATES.includes(value.account) || !PC_STATES.includes(value.thisPc)) return null;
    const paired = value.thisPc === 'paired';
    return Object.freeze({
        account: value.account,
        thisPc: value.thisPc,
        deviceName: paired ? displayName(value.deviceName) : null,
        running: paired && value.running === true,
        installUrl: httpsUrl(value.installUrl),
        pairingAvailable: value.account !== 'none' && value.pairingAvailable === true,
    });
}

export function normalizePairingRequest(value, now = Date.now()) {
    if (!value || typeof value !== 'object' || typeof value.id !== 'string' || !UUID.test(value.id)) return null;
    const url = httpsUrl(value.url);
    if (!url) return null;
    const pollAfterMs = typeof value.pollAfterMs === 'number' && Number.isFinite(value.pollAfterMs)
        ? Math.min(Math.max(Math.round(value.pollAfterMs), 1000), 10000) : 2000;
    // Eine abweichende PC-Uhr darf die Anfrage weder sofort beenden noch endlos verlängern.
    const remaining = typeof value.expiresAt === 'string' ? Date.parse(value.expiresAt) - now : Number.NaN;
    const ttl = Number.isFinite(remaining) && remaining > 0 && remaining <= MAX_TTL_MS ? remaining : DEFAULT_TTL_MS;
    return Object.freeze({ id: value.id, url, pollAfterMs, deadline: now + ttl });
}

export function normalizePairingResult(value) {
    if (!value || typeof value !== 'object' || !RESULT_STATES.includes(value.state)) return null;
    const pairingKey = value.state === 'paired' && typeof value.pairingKey === 'string' && PAIRING_KEY.test(value.pairingKey)
        ? value.pairingKey : null;
    return Object.freeze({ state: value.state, pairingKey, deviceName: displayName(value.deviceName) });
}

/** Ohne nutzbaren Speicher gilt nur die Konto-Erkennung; Koppeln wird dann erneut angeboten. */
export function createDesktopPairingStore(resolveStorage = () => globalThis.localStorage) {
    const storage = () => {
        try {
            return (typeof resolveStorage === 'function' ? resolveStorage() : resolveStorage) || null;
        } catch {
            return null;
        }
    };
    const parse = (raw) => {
        try {
            return typeof raw === 'string' ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };
    return Object.freeze({
        read(mailbox) {
            try {
                const entry = parse(storage()?.getItem(DESKTOP_PAIRING_STORAGE_KEY));
                return entry?.mailbox === mailbox && typeof entry.key === 'string' && PAIRING_KEY.test(entry.key) ? entry.key : null;
            } catch {
                return null;
            }
        },
        write(mailbox, key) {
            if (typeof mailbox !== 'string' || mailbox === '' || typeof key !== 'string' || !PAIRING_KEY.test(key)) return false;
            try {
                const target = storage();
                if (!target) return false;
                target.setItem(DESKTOP_PAIRING_STORAGE_KEY, JSON.stringify({ mailbox, key }));
                return true;
            } catch {
                return false;
            }
        },
        /** Mit Postfach wird nur dessen eigener Eintrag (oder ein unlesbarer) entfernt. */
        clear(mailbox = null) {
            try {
                const target = storage();
                if (!target) return;
                if (mailbox !== null) {
                    const entry = parse(target.getItem(DESKTOP_PAIRING_STORAGE_KEY));
                    if (typeof entry?.mailbox === 'string' && entry.mailbox !== mailbox) return;
                }
                target.removeItem(DESKTOP_PAIRING_STORAGE_KEY);
            } catch { /* Ohne Speicher gibt es nichts zu löschen. */ }
        },
    });
}

/** Nur das angemeldete Office-Postfach wird gelesen; keine Nachricht, kein Body. */
export function createDesktopClientSession({ readMailbox, authenticate, request, endpoints, store, now = () => Date.now() }) {
    const stateUrl = httpsUrl(endpoints?.state);
    const pairingsUrl = httpsUrl(endpoints?.pairings);
    if (!stateUrl || !pairingsUrl || typeof request !== 'function' || typeof authenticate !== 'function') {
        throw fail('DESKTOP_CLIENT_UNAVAILABLE');
    }
    const keys = store || createDesktopPairingStore(null);
    const mailbox = () => {
        const raw = readMailbox();
        if (typeof raw !== 'string' || /[\u0000-\u001f\u007f]/.test(raw)) throw fail('MAILBOX_ADDRESS_INVALID');
        const value = raw.trim().toLowerCase();
        if (value.length > 254 || !/^[^\s@<>,;:"\\()[\]*]+@[^\s@<>,;:"\\()[\]*]+$/u.test(value)) throw fail('MAILBOX_ADDRESS_INVALID');
        return value;
    };
    const send = async (url, method, { account = mailbox(), withKey = false, allowPopup = false } = {}) => {
        if (mailbox() !== account) throw fail('MAILBOX_MISMATCH');
        const token = await authenticate(allowPopup);
        if (mailbox() !== account) throw fail('MAILBOX_MISMATCH');
        if (typeof token !== 'string' || !token.trim()) throw fail('ACCESS_TOKEN_MISSING');
        const headers = { Accept: 'application/json', Authorization: `Bearer ${token}`,
            'X-RailTime-Outlook-Context': 'account-settings',
            'X-RailTime-Compose-Contract': 'native-signature-v1', 'X-RailTime-Outlook-Mailbox': account };
        const key = withKey ? keys.read(account) : null;
        if (key) headers['X-RailTime-Desktop-Pairing'] = key;
        const options = { method, cache: 'no-store', credentials: 'omit', headers };
        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            options.body = '{}';
        }
        const payload = await request(url, options);
        if (mailbox() !== account) throw fail('MAILBOX_MISMATCH');
        return { account, payload };
    };
    return Object.freeze({
        async load(allowPopup = false) {
            const { account, payload } = await send(stateUrl, 'GET', { withKey: true, allowPopup });
            const state = normalizeDesktopClientState(payload?.desktopClient);
            if (!state) throw fail('DESKTOP_CLIENT_RESPONSE_INVALID');
            if (state.thisPc === 'revoked') keys.clear(account);
            return state;
        },
        async requestPairing() {
            const { account, payload } = await send(pairingsUrl, 'POST');
            const pairing = normalizePairingRequest(payload?.pairing, now());
            if (!pairing) throw fail('DESKTOP_PAIRING_RESPONSE_INVALID');
            return Object.freeze({ ...pairing, account });
        },
        /** Der Schlüssel verlässt die Sitzung nie: er wird nur gespeichert, nicht zurückgegeben. */
        async pollPairing(pairing) {
            if (typeof pairing?.id !== 'string' || !UUID.test(pairing.id) || typeof pairing.account !== 'string') {
                throw fail('DESKTOP_PAIRING_INVALID');
            }
            const url = `${pairingsUrl.replace(/\/+$/, '')}/${encodeURIComponent(pairing.id)}`;
            const { account, payload } = await send(url, 'GET', { account: pairing.account });
            const result = normalizePairingResult(payload?.pairing);
            if (!result) throw fail('DESKTOP_PAIRING_RESPONSE_INVALID');
            const stored = result.pairingKey !== null && keys.write(account, result.pairingKey);
            return Object.freeze({ state: result.state, deviceName: result.deviceName,
                keyReceived: result.pairingKey !== null, stored });
        },
        forget() {
            let account = null;
            try { account = mailbox(); } catch { /* Ohne Postfach wird der einzige Eintrag entfernt. */ }
            keys.clear(account);
        },
    });
}

function pollFailureIsFinal(error) {
    const match = /^HTTP_(\d{3})$/.exec(String(error?.code || ''));
    if (!match) return ['MAILBOX_MISMATCH', 'MAILBOX_ADDRESS_INVALID', 'DESKTOP_PAIRING_INVALID'].includes(error?.code);
    const status = Number(match[1]);
    return status >= 400 && status < 500 && ![408, 429].includes(status);
}

export function bindDesktopClientView(root, { session, installUrl = null, now = () => Date.now(),
    schedule = (callback, delay) => setTimeout(callback, delay), cancel = handle => clearTimeout(handle) } = {}) {
    const region = root?.querySelector?.('[data-outlook-desktop-client]');
    if (!region || !session) return Object.freeze({ refresh: async () => {}, destroy() {}, state: () => 'hidden' });
    const doc = region.ownerDocument;
    const part = name => region.querySelector(`[data-desktop-client-${name}]`);
    const status = part('status');
    const running = part('running');
    const install = part('install');
    const pair = part('pair');
    const pending = part('pending');
    const confirm = part('confirm');
    const forget = part('forget');
    const feedback = part('feedback');
    const fallbackInstallUrl = httpsUrl(installUrl);

    let snapshot = null;
    let pairing = null;
    let timer = null;
    let busy = false;
    let polling = false;
    let failures = 0;
    let loadNotice = '';
    let pairNotice = '';
    let destroyed = false;
    let view = 'hidden';

    const setText = (element, text) => {
        if (element && element.textContent !== text) element.textContent = text;
    };
    const setHidden = (element, hidden) => {
        if (element) element.hidden = hidden;
    };
    const windowHidden = () => doc?.visibilityState === 'hidden';
    const focusIfMoved = (from, to) => {
        if (doc?.activeElement === from && to && !to.hidden) to.focus?.();
    };
    const render = () => {
        view = pairing ? 'pairing' : !snapshot ? (loadNotice || pairNotice ? 'error' : 'hidden')
            : snapshot.thisPc === 'paired' ? 'paired' : snapshot.account === 'none' ? 'install' : 'pair';
        region.hidden = view === 'hidden';
        region.dataset.state = view;
        region.setAttribute('aria-busy', busy || polling ? 'true' : 'false');
        setText(status, view === 'install' ? TEXT.install
            : view === 'pair' ? (snapshot.pairingAvailable ? TEXT.pair : TEXT.pairUnavailable)
                : view === 'paired' ? `Dieser PC ist gekoppelt: ${snapshot.deviceName || 'Name nicht bekannt'}`
                    : view === 'pairing' ? TEXT.pairing : '');
        setText(running, view === 'paired' ? (snapshot.running ? TEXT.running : TEXT.stopped) : '');
        setHidden(running, view !== 'paired');
        const installHref = snapshot?.installUrl || fallbackInstallUrl;
        if (install) {
            if (installHref) install.href = installHref;
            install.hidden = view !== 'install' || !installHref;
        }
        if (pair) {
            pair.hidden = view !== 'pair' || !snapshot?.pairingAvailable;
            pair.disabled = busy;
        }
        setHidden(pending, view !== 'pairing');
        if (confirm && pairing) confirm.href = pairing.url;
        if (forget) {
            forget.hidden = view !== 'paired';
            forget.disabled = busy;
        }
        setText(feedback, pairNotice || loadNotice);
    };

    const cancelPoll = () => {
        if (timer !== null) cancel(timer);
        timer = null;
    };

    const refresh = async () => {
        if (destroyed || busy || pairing) return;
        busy = true;
        render();
        try {
            snapshot = await session.load(false);
            loadNotice = '';
            if (snapshot.thisPc === 'paired') pairNotice = '';
        } catch (error) {
            if (/MAILBOX/.test(String(error?.code || ''))) snapshot = null;
            loadNotice = snapshot ? TEXT.refreshFailed : TEXT.loadFailed;
        } finally {
            busy = false;
            if (!destroyed) render();
        }
    };

    const finish = async (outcome, result = null) => {
        cancelPoll();
        const hadFocus = doc?.activeElement === confirm;
        pairing = null;
        failures = 0;
        pairNotice = outcome === 'rejected' ? TEXT.rejected : outcome === 'expired' ? TEXT.expired
            : outcome === 'failed' ? TEXT.pollFailed : result?.keyReceived && !result.stored ? TEXT.notStored : '';
        // Gespeicherte Kopplung sofort zeigen; der Server-Zustand wird gleich danach neu gelesen.
        if (outcome === 'paired' && result?.stored && snapshot) {
            snapshot = Object.freeze({ ...snapshot, thisPc: 'paired', deviceName: result.deviceName, running: true });
        }
        render();
        if (outcome === 'paired') {
            await refresh();
            if (!destroyed && result && !result.keyReceived && snapshot?.thisPc !== 'paired') {
                pairNotice = TEXT.takenElsewhere;
                render();
            }
        }
        if (hadFocus) {
            const target = [pair, forget].find(element => element && !element.hidden);
            target?.focus?.();
        }
    };

    const poll = async () => {
        timer = null;
        const active = pairing;
        if (!active || destroyed || polling) return;
        if (now() >= active.deadline) return finish('expired');
        if (windowHidden()) return;
        polling = true;
        render();
        let result = null;
        let failure = null;
        try {
            result = await session.pollPairing(active);
        } catch (error) {
            failure = error || fail('DESKTOP_PAIRING_FAILED');
        }
        polling = false;
        if (pairing !== active || destroyed) return;
        if (failure) {
            if (['HTTP_404', 'HTTP_410'].includes(failure.code)) return finish('expired');
            failures += 1;
            if (pollFailureIsFinal(failure) || failures >= MAX_POLL_FAILURES) return finish('failed');
            render();
            return schedulePoll(active.pollAfterMs);
        }
        failures = 0;
        if (result.state === 'pending') {
            render();
            return schedulePoll(active.pollAfterMs);
        }
        return finish(result.state, result);
    };

    // Abfrage nur bei sichtbarem Fenster und höchstens bis expiresAt.
    function schedulePoll(delay) {
        cancelPoll();
        if (!pairing || destroyed) return;
        if (now() >= pairing.deadline) {
            finish('expired');
            return;
        }
        if (windowHidden()) return;
        timer = schedule(poll, Math.max(0, Math.min(delay, pairing.deadline - now())));
    }

    const onVisibilityChange = () => {
        if (!pairing || destroyed) return;
        if (windowHidden()) cancelPoll();
        else if (timer === null && !polling) schedulePoll(0);
    };
    doc?.addEventListener?.('visibilitychange', onVisibilityChange);

    pair?.addEventListener('click', async () => {
        if (pair.disabled || pair.hidden || busy || pairing || destroyed) return;
        const hadFocus = doc?.activeElement === pair;
        busy = true;
        loadNotice = '';
        pairNotice = TEXT.preparing;
        render();
        let started = null;
        let refreshNeeded = false;
        try {
            started = await session.requestPairing();
            pairNotice = '';
        } catch (error) {
            refreshNeeded = error?.code === 'HTTP_409';
            pairNotice = error?.code === 'HTTP_429' ? TEXT.throttled : refreshNeeded ? TEXT.noClient : TEXT.startFailed;
        } finally {
            busy = false;
        }
        if (destroyed) return;
        pairing = started;
        failures = 0;
        render();
        if (pairing) {
            if (hadFocus) confirm?.focus?.();
            schedulePoll(pairing.pollAfterMs);
        } else if (refreshNeeded) {
            await refresh();
        }
    });

    forget?.addEventListener('click', async () => {
        if (forget.disabled || forget.hidden || busy || pairing || destroyed) return;
        session.forget();
        // Nur der lokale Schlüssel wird gelöscht; der Server-Zustand wird danach neu gelesen.
        if (snapshot) snapshot = Object.freeze({ ...snapshot, thisPc: 'unknown', deviceName: null, running: false });
        pairNotice = TEXT.forgotten;
        render();
        focusIfMoved(forget, pair);
        await refresh();
    });

    render();
    return Object.freeze({
        refresh,
        state: () => view,
        destroy() {
            destroyed = true;
            cancelPoll();
            pairing = null;
            doc?.removeEventListener?.('visibilitychange', onVisibilityChange);
        },
    });
}

/**
 * Verdrahtet Sitzung, Speicher und Ansicht. Fehlt eine Adresse oder läuft Outlook mobil,
 * bleibt der Bereich verborgen und es wird nichts angefragt.
 */
export function startDesktopClientArea(root, { endpoints, installUrl, readMailbox, authenticate, request,
    isMobileHost = () => false, storage = () => globalThis.localStorage, now, schedule, cancel } = {}) {
    const region = root?.querySelector?.('[data-outlook-desktop-client]');
    if (!region) return null;
    region.hidden = true;
    if (!httpsUrl(endpoints?.state) || !httpsUrl(endpoints?.pairings) || !httpsUrl(installUrl)) return null;
    try {
        if (isMobileHost()) return null;
    } catch {
        return null;
    }
    const session = createDesktopClientSession({ readMailbox, authenticate, request, endpoints,
        store: createDesktopPairingStore(storage), ...(now ? { now } : {}) });
    return bindDesktopClientView(root, { session, installUrl, ...(now ? { now } : {}),
        ...(schedule ? { schedule } : {}), ...(cancel ? { cancel } : {}) });
}
