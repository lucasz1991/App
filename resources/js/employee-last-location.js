const CAPTURE_INTERVAL = 60_000;
const MAXIMUM_FIX_AGE = 30_000;

/** Voluntary last point during actual foreground use; no background watch or offline queue. */
export function createEmployeeLastLocation({
    windowLike = globalThis.window,
    documentLike = globalThis.document,
    navigatorLike = globalThis.navigator,
    fetchLike = windowLike?.fetch?.bind(windowLike),
    now = () => Date.now(),
    coordinator = () => windowLike?.RailTimeLiveLocation,
} = {}) {
    const listeners = [];
    let started = false;
    let destroyed = false;
    let terminal = false;
    let recoverableForbidden = false;
    let verified = true;
    let generation = 0;
    let consented = false;
    let permission = 'unknown';
    let permissionStatus = null;
    let permissionListener = null;
    let permissionQueryUnavailable = !navigatorLike?.permissions?.query;
    const shownLocationNotices = new Set();
    let nextCaptureAt = 0;
    let nextStatusAt = 0;
    let capture = null;
    let refreshing = null;
    const requests = new Set();

    const meta = (name) => documentLike?.querySelector?.(`meta[name="${name}"]`)?.content || '';
    const usable = () => !destroyed && !terminal && verified
        && documentLike?.visibilityState !== 'hidden' && navigatorLike?.onLine !== false;

    function endpoint(name) {
        try {
            const value = meta(name);
            if (!value) return null;
            const url = new URL(value, windowLike?.location?.href);
            return url.origin === windowLike?.location?.origin ? url.href : null;
        } catch (_) { return null; }
    }

    function listen(target, name, callback, options) {
        target?.addEventListener?.(name, callback, options);
        listeners.push(() => target?.removeEventListener?.(name, callback, options));
    }

    function suspend({ end = false, recoverable = false } = {}) {
        generation += 1;
        verified = false;
        consented = false;
        if (end) {
            terminal = true;
            recoverableForbidden = recoverable;
        }
        requests.forEach((controller) => controller.abort());
        requests.clear();
        refreshing = null;
    }

    async function request(url, { body, method = 'GET' } = {}) {
        const requestGeneration = generation;
        const Controller = windowLike?.AbortController ?? globalThis.AbortController;
        const controller = Controller ? new Controller() : null;
        if (controller) requests.add(controller);
        const timeout = windowLike?.setTimeout?.(() => controller?.abort(), 10_000);
        try {
            const response = await fetchLike(url, {
                method, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: {
                    Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                    ...(body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': meta('csrf-token') } : {}),
                },
                ...(controller ? { signal: controller.signal } : {}),
                ...(body ? { body: JSON.stringify(body) } : {}),
            });
            if (requestGeneration !== generation || destroyed) return null;
            if ([401, 403, 419].includes(response.status)) {
                suspend({ end: true, recoverable: response.status === 403 });
                return null;
            }
            if (!response.ok) return null;
            return await response.json();
        } catch (_) {
            // Never queue or replay location writes after a network failure.
            return null;
        } finally {
            if (controller) requests.delete(controller);
            if (timeout !== undefined) windowLike?.clearTimeout?.(timeout);
        }
    }

    async function readPermission() {
        if (!navigatorLike?.permissions?.query) return permission;
        try {
            if (!permissionStatus) {
                permissionStatus = await navigatorLike.permissions.query({ name: 'geolocation' });
                permissionListener = () => {
                    permission = permissionStatus.state || 'unknown';
                    generation += 1;
                    if (permission !== 'granted') requests.forEach((controller) => controller.abort());
                };
                permissionStatus.addEventListener?.('change', permissionListener);
            }
            permission = permissionStatus.state || 'unknown';
        } catch (_) {
            // Older WebKit cannot report a permission state. A real foreground
            // sensor request, after server consent, lets the OS decide it.
            permissionQueryUnavailable = true;
        }
        return permission;
    }

    function locationError(error) {
        const denied = Number(error?.code) === 1;
        if (denied) permission = 'denied';
        const code = denied ? 'permission-denied' : 'unavailable';
        if (shownLocationNotices.has(code) || !usable()) return;
        shownLocationNotices.add(code);
        const Event = windowLike?.CustomEvent ?? globalThis.CustomEvent;
        if (typeof Event !== 'function') return;
        windowLike?.dispatchEvent?.(new Event('swal:toast', { detail: {
            type: 'info', sound: false, timer: 8_000,
            text: denied
                ? 'Der Standortzugriff ist im Browser blockiert. RailTime bleibt nutzbar. Du kannst die Berechtigung unter deinen Profil-Freigaben erneut einrichten.'
                : 'Der Standort konnte gerade nicht bestimmt werden. RailTime bleibt nutzbar; es wurde kein neuer Standort gespeichert.',
        } }));
    }

    async function measure() {
        const shared = typeof coordinator === 'function' ? coordinator() : coordinator;
        if (shared?.capturePosition) return shared.capturePosition();
        return new Promise((resolve, reject) => {
            const timeout = windowLike?.setTimeout?.(() => reject(new Error('Location timeout')), 16_000);
            navigatorLike?.geolocation?.getCurrentPosition((position) => {
                windowLike?.clearTimeout?.(timeout);
                resolve({ latitude: position.coords.latitude, longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy, measured_at: new Date(position.timestamp).toISOString() });
            }, (error) => {
                windowLike?.clearTimeout?.(timeout);
                reject(error);
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 15_000 });
        });
    }

    async function captureNow() {
        const url = endpoint('rt-employee-location-browser-point-url');
        if (!usable() || !consented || !url || capture || now() < nextCaptureAt || !navigatorLike?.geolocation) return false;
        const version = generation;
        nextCaptureAt = now() + CAPTURE_INTERVAL;
        capture = (async () => {
            const actualPermission = await readPermission();
            const mayAskOperatingSystem = permissionQueryUnavailable && actualPermission === 'unknown';
            if ((actualPermission !== 'granted' && !mayAskOperatingSystem) || version !== generation || !usable()) return false;
            try {
                const point = await measure();
                if (version !== generation || !usable() || !consented) return false;
                // Success is real device evidence, never inferred from RailTime consent.
                if (mayAskOperatingSystem) permission = 'granted';
                const measured = Date.parse(point.measured_at || '');
                const age = now() - measured;
                if (version !== generation || !usable() || !consented || permission !== 'granted'
                    || !Number.isFinite(measured) || age < -5_000 || age > MAXIMUM_FIX_AGE
                    || !Number.isFinite(point.latitude) || Math.abs(point.latitude) > 90
                    || !Number.isFinite(point.longitude) || Math.abs(point.longitude) > 180
                    || !Number.isFinite(point.accuracy) || point.accuracy < 0 || point.accuracy > 50) return false;
                const eventId = windowLike?.crypto?.randomUUID?.();
                if (!eventId) return false;
                const result = await request(url, { method: 'POST', body: {
                    latitude: point.latitude, longitude: point.longitude, accuracy_m: point.accuracy,
                    measured_at: point.measured_at, event_id: eventId,
                } });
                if (version !== generation || !usable()) return false;
                const next = Date.parse(result?.next_capture_at || '');
                if (Number.isFinite(next)) nextCaptureAt = Math.max(nextCaptureAt, next);
                if (result?.accepted === true && result?.stored === true) {
                    const Event = windowLike?.CustomEvent ?? globalThis.CustomEvent;
                    if (typeof Event === 'function') windowLike?.dispatchEvent?.(new Event('railtime-app-location-updated', { detail: { stored: true } }));
                }
                return result?.accepted === true;
            } catch (error) {
                if (version === generation && usable()) locationError(error);
                return false;
            }
        })();
        try { return await capture; } finally { capture = null; }
    }

    async function refresh({ captureAfter = true, force = false } = {}) {
        const url = endpoint('rt-employee-location-browser-status-url');
        if (!usable() || !url) return false;
        if (refreshing) return refreshing;
        if (!force && now() < nextStatusAt) return consented && captureAfter ? captureNow() : consented;
        const version = generation;
        nextStatusAt = now() + CAPTURE_INTERVAL;
        refreshing = (async () => {
            const status = await request(url);
            if (version !== generation || !usable()) return false;
            consented = status?.enabled === true && status?.consented === true;
            const next = Date.parse(status?.next_capture_at || '');
            if (Number.isFinite(next)) nextCaptureAt = Math.max(nextCaptureAt, next);
            return consented && captureAfter ? captureNow() : consented;
        })();
        const pending = refreshing;
        try { return await pending; } finally { if (refreshing === pending) refreshing = null; }
    }

    function used(event) {
        if (event?.isTrusted !== true || !usable()) return;
        if (now() >= nextCaptureAt) refresh();
    }

    function start() {
        if (started || destroyed || !endpoint('rt-employee-location-browser-status-url')) return Promise.resolve(false);
        started = true;
        listen(documentLike, 'pointerdown', used, { passive: true });
        listen(documentLike, 'keydown', used, { passive: true });
        listen(documentLike, 'scroll', used, { passive: true, capture: true });
        listen(documentLike, 'visibilitychange', () => {
            if (documentLike.visibilityState === 'hidden') suspend();
            else if (!meta('rt-pwa-session-status-url')) { verified = true; refresh({ force: true }); }
        });
        listen(windowLike, 'pagehide', () => suspend());
        listen(windowLike, 'offline', () => suspend());
        listen(windowLike, 'railtime:logout', () => suspend({ end: true }));
        listen(documentLike, 'submit', (event) => {
            if (/(?:^|\/)logout(?:\?|$)/i.test(String(event?.target?.action || ''))) suspend({ end: true });
        }, true);
        listen(windowLike, 'railtime:app-connection-state', (event) => {
            const state = event?.detail?.state;
            if (state === 'ready' && !terminal) { verified = true; refresh({ force: true }); }
            else if (['session-required', 'account-changed', 'session-expired'].includes(state)) suspend({ end: true });
            else if (['recovering', 'offline'].includes(state)) suspend();
        });
        listen(windowLike, 'railtime-session-permissions-changed', () => {
            generation += 1;
            consented = false;
            refreshing = null;
            requests.forEach((controller) => controller.abort());
            if (terminal && recoverableForbidden) {
                // A revoked location purpose may be explicitly allowed again.
                // Re-verify the original account first; logout/account changes stay terminal.
                terminal = false;
                recoverableForbidden = false;
                if (typeof windowLike?.RailtimeAppLifecycle?.recover === 'function') {
                    windowLike.RailtimeAppLifecycle.recover({ force: true });
                } else if (!meta('rt-pwa-session-status-url')) {
                    verified = true;
                    refresh({ force: true });
                }
                return;
            }
            refresh({ force: true });
        });
        listen(windowLike, 'railtime-location-browser-permission-granted', () => {
            permission = 'granted';
            nextCaptureAt = Math.min(nextCaptureAt, now());
            refresh({ force: true });
        });
        listen(documentLike, 'livewire:navigated', () => refresh());
        return refresh();
    }

    function destroy() {
        suspend({ end: true });
        destroyed = true;
        listeners.splice(0).forEach((remove) => remove());
        permissionStatus?.removeEventListener?.('change', permissionListener);
    }

    return { start, destroy, refresh, captureNow };
}
