const REPORT_INTERVAL_MS = 5 * 60 * 1000;
const RETRY_COOLDOWN_MS = 60 * 1000;
const BURST_COOLDOWN_MS = 5 * 1000;
const REQUEST_TIMEOUT_MS = 10 * 1000;

function validPermission(value, allowed) {
    return allowed.includes(value) ? value : 'unknown';
}

/** Only operational enum values leave the browser, never coordinates or identifiers. */
export function runtimeStatusPayload({ windowLike, navigatorLike, geolocationPermission = 'unknown' }) {
    const agent = String(navigatorLike?.userAgent || '');
    const ios = /iPad|iPhone|iPod/i.test(agent)
        || (navigatorLike?.platform === 'MacIntel' && Number(navigatorLike?.maxTouchPoints || 0) > 1);
    const android = /Android/i.test(agent);
    const desktop = /Windows|Macintosh|Mac OS|Linux|CrOS/i.test(agent);
    const displayQuery = windowLike?.matchMedia?.('(display-mode: standalone)');
    const standalone = navigatorLike?.standalone === true || displayQuery?.matches === true;
    const displayKnown = typeof navigatorLike?.standalone === 'boolean' || typeof displayQuery?.matches === 'boolean';

    return {
        platform: ios ? 'ios' : android ? 'android' : desktop ? 'desktop' : 'unknown',
        display_mode: standalone ? 'standalone' : displayKnown ? 'browser' : 'unknown',
        notification_permission: windowLike?.Notification
            ? validPermission(windowLike.Notification.permission, ['granted', 'denied', 'default'])
            : 'unsupported',
        geolocation_permission: typeof navigatorLike?.geolocation?.getCurrentPosition === 'function'
            ? validPermission(geolocationPermission, ['granted', 'denied', 'prompt', 'unsupported'])
            : 'unsupported',
    };
}

export function createRuntimeStatusReporter({
    windowLike = typeof window === 'undefined' ? null : window,
    navigatorLike = typeof navigator === 'undefined' ? null : navigator,
    documentLike = typeof document === 'undefined' ? null : document,
    fetchLike = windowLike?.fetch?.bind(windowLike),
    now = () => Date.now(),
    intervalMs = REPORT_INTERVAL_MS,
} = {}) {
    const listeners = [];
    const permissionListeners = [];
    const permissionStatuses = new Map();
    let permissionSetup = null;
    let geolocationHint = 'unknown';
    let interval = null;
    let deferred = null;
    let requestTimeout = null;
    let controller = null;
    let inFlight = null;
    let pending = false;
    let started = false;
    let destroyed = false;
    let authenticationExpired = false;
    let lastAttempt = Number.NEGATIVE_INFINITY;
    let lastSuccess = Number.NEGATIVE_INFINITY;
    let retryAfter = Number.NEGATIVE_INFINITY;
    let lastPayload = null;

    function endpoint() {
        const configured = documentLike?.querySelector?.('meta[name="rt-app-runtime-status-url"]')?.content;
        if (!configured || !windowLike?.location?.href) return null;
        try {
            const url = new URL(configured, windowLike.location.href);
            return url.origin === windowLike.location.origin ? url.href : null;
        } catch (_) {
            return null;
        }
    }

    function canReport() {
        return !destroyed && !authenticationExpired && Boolean(endpoint())
            && documentLike?.visibilityState !== 'hidden' && navigatorLike?.onLine !== false
            && typeof fetchLike === 'function';
    }

    function listen(target, event, callback) {
        if (!target?.addEventListener) return;
        target.addEventListener(event, callback);
        listeners.push(() => target.removeEventListener(event, callback));
    }

    function deferReport(delay) {
        if (deferred !== null || destroyed || authenticationExpired) return;
        deferred = windowLike.setTimeout(() => {
            deferred = null;
            void report();
        }, Math.max(100, delay));
    }

    async function observePermissions() {
        if (permissionSetup) return permissionSetup;
        permissionSetup = Promise.allSettled(['geolocation', 'notifications'].map(async (name) => {
            if (!navigatorLike?.permissions?.query) return;
            const status = await navigatorLike.permissions.query({ name });
            if (destroyed) return;
            permissionStatuses.set(name, status);
            const changed = () => { void report(); };
            if (status.addEventListener) {
                status.addEventListener('change', changed);
                permissionListeners.push(() => status.removeEventListener('change', changed));
            } else {
                const previous = status.onchange;
                const handler = (...args) => {
                    if (typeof previous === 'function') previous.apply(status, args);
                    changed();
                };
                status.onchange = handler;
                permissionListeners.push(() => {
                    if (status.onchange === handler) status.onchange = previous;
                });
            }
        }));

        return permissionSetup;
    }

    async function report() {
        if (!canReport()) return false;
        if (inFlight) {
            pending = true;
            return inFlight;
        }
        const cooldown = Math.max(lastAttempt + BURST_COOLDOWN_MS, retryAfter) - now();
        if (cooldown > 0) {
            deferReport(cooldown);
            return false;
        }

        inFlight = (async () => {
            await observePermissions();
            if (!canReport()) return false;
            const payload = runtimeStatusPayload({
                windowLike, navigatorLike,
                geolocationPermission: permissionStatuses.get('geolocation')?.state ?? geolocationHint,
            });
            const serialized = JSON.stringify(payload);
            if (serialized === lastPayload && now() - lastSuccess < intervalMs) return false;

            lastAttempt = now();
            const Abort = windowLike?.AbortController ?? globalThis.AbortController;
            controller = typeof Abort === 'function' ? new Abort() : null;
            requestTimeout = windowLike.setTimeout(() => controller?.abort(), REQUEST_TIMEOUT_MS);
            try {
                const response = await fetchLike(endpoint(), {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: {
                        Accept: 'application/json', 'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': documentLike.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: serialized,
                    ...(controller ? { signal: controller.signal } : {}),
                });
                if ([401, 403, 419].includes(response.status)) {
                    authenticationExpired = true;
                    return false;
                }
                if (!response.ok) {
                    retryAfter = now() + RETRY_COOLDOWN_MS;
                    return false;
                }
                lastPayload = serialized;
                lastSuccess = now();
                retryAfter = Number.NEGATIVE_INFINITY;
                return true;
            } catch (_) {
                retryAfter = now() + RETRY_COOLDOWN_MS;
                return false;
            } finally {
                windowLike.clearTimeout(requestTimeout);
                requestTimeout = null;
                controller = null;
            }
        })();
        try {
            return await inFlight;
        } finally {
            inFlight = null;
            if (pending) {
                pending = false;
                if (canReport()) deferReport(Math.max(lastAttempt + BURST_COOLDOWN_MS, retryAfter) - now());
            }
        }
    }

    function start() {
        if (started || destroyed || !windowLike || !documentLike) return;
        started = true;
        const refresh = () => { void report(); };
        listen(windowLike, 'pageshow', refresh);
        listen(windowLike, 'online', refresh);
        listen(windowLike, 'appinstalled', refresh);
        listen(windowLike, 'railtime:pwa-state-changed', refresh);
        listen(windowLike, 'railtime-session-permissions-changed', refresh);
        listen(documentLike, 'visibilitychange', refresh);
        listen(documentLike, 'livewire:navigated', refresh);
        interval = windowLike.setInterval(refresh, intervalMs);
        if (documentLike.readyState === 'loading') {
            listen(documentLike, 'DOMContentLoaded', refresh);
        } else {
            refresh();
        }
    }

    function noteGeolocationPermission(state) {
        if (['granted', 'denied'].includes(state)) {
            geolocationHint = state;
            void report();
        }
    }

    /** Called only after the lifecycle probe confirms the same authenticated account. */
    function resumeAfterSessionCheck() {
        authenticationExpired = false;
        retryAfter = Number.NEGATIVE_INFINITY;
        return report();
    }

    function destroy() {
        destroyed = true;
        listeners.forEach((remove) => remove());
        permissionListeners.forEach((remove) => remove());
        if (interval !== null) windowLike.clearInterval(interval);
        if (deferred !== null) windowLike.clearTimeout(deferred);
        if (requestTimeout !== null) windowLike.clearTimeout(requestTimeout);
        controller?.abort();
    }

    return { start, report, noteGeolocationPermission, resumeAfterSessionCheck, destroy };
}
