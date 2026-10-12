const REQUEST_TIMEOUT_MS = 10_000;
const RESUME_COOLDOWN_MS = 5_000;
const SOCKET_RESET_AFTER_MS = 30_000;

/** Resume suspended pages without replaying actions, refreshing forms or keeping sessions alive. */
export function createAppLifecycle({
    windowLike = globalThis.window,
    documentLike = globalThis.document,
    navigatorLike = globalThis.navigator,
    fetchLike = windowLike?.fetch?.bind(windowLike),
    now = () => Date.now(),
    onRecovered = () => {},
} = {}) {
    const listeners = [];
    let started = false;
    let destroyed = false;
    let hiddenAt = null;
    let resetSocket = false;
    let recovery = null;
    let controller = null;
    let timeout = null;
    let retry = null;
    let generation = 0;
    let lastAttempt = Number.NEGATIVE_INFINITY;
    let terminal = false;
    let noticePending = false;
    let lastNotice = null;

    const meta = (name) => documentLike?.querySelector?.(`meta[name="${name}"]`);
    const visibleOnline = () => !destroyed && documentLike?.visibilityState !== 'hidden'
        && navigatorLike?.onLine !== false;

    function endpoint() {
        const configured = meta('rt-pwa-session-status-url')?.content;
        if (!configured || !meta('rt-push-account-binding')?.content) return null;
        try {
            const url = new URL(configured, windowLike.location.href);
            return url.origin === windowLike.location.origin ? url.href : null;
        } catch (_) {
            return null;
        }
    }

    function emit(name, detail) {
        const Event = windowLike?.CustomEvent ?? globalThis.CustomEvent;
        if (typeof Event === 'function') windowLike?.dispatchEvent?.(new Event(name, { detail }));
    }

    function state(value, detail = {}) {
        emit('railtime:app-connection-state', { state: value, ...detail });
    }

    function toast(text) {
        emit('swal:toast', { text, type: 'info', sound: false, timer: 8_000 });
    }

    function cancelPending() {
        generation += 1;
        if (controller) lastAttempt = Number.NEGATIVE_INFINITY;
        controller?.abort();
        controller = null;
        if (timeout !== null) windowLike.clearTimeout(timeout);
        if (retry !== null) windowLike.clearTimeout(retry);
        timeout = null;
        retry = null;
        recovery = null;
    }

    function requireSession(reason) {
        terminal = true;
        resetSocket = false;
        try { windowLike.Echo?.disconnect?.(); } catch (_) { /* Optional realtime transport. */ }
        state(reason);
        if (lastNotice === reason || !visibleOnline()) return;
        lastNotice = reason;
        const text = reason === 'account-changed'
            ? 'Die Anmeldung wurde in einem anderen Fenster geändert. Lade die Seite nach dem Sichern deiner Eingaben neu.'
            : 'Deine Anmeldung ist abgelaufen oder wurde beendet. Sichere offene Eingaben und lade die Seite neu, um dich wieder anzumelden.';
        if (typeof windowLike.Swal?.fire !== 'function') {
            toast(text);
            return;
        }
        // Navigation is an explicit user choice. Failed writes are never retried.
        Promise.resolve(windowLike.Swal.fire({
            icon: 'info', title: 'Anmeldung prüfen', text,
            showCancelButton: true, confirmButtonText: 'Neu laden', cancelButtonText: 'Hier bleiben',
            focusCancel: true, reverseButtons: true,
        })).then((result) => {
            if (result?.isConfirmed) windowLike.location.reload();
        }).catch(() => {});
    }

    function applyCsrf(token) {
        const node = meta('csrf-token');
        if (node) node.content = token;
        if (windowLike.livewireScriptConfig) windowLike.livewireScriptConfig.csrf = token;
        if (windowLike.Laravel) windowLike.Laravel.csrfToken = token;
        const axiosHeaders = windowLike.axios?.defaults?.headers?.common;
        if (axiosHeaders) axiosHeaders['X-CSRF-TOKEN'] = token;
        // Echo/Pusher capture the initial CSRF header when creating their client.
        const connector = windowLike.Echo?.connector;
        for (const headers of [
            connector?.options?.auth?.headers, connector?.options?.userAuthentication?.headers,
            connector?.pusher?.config?.channelAuthorization?.headers,
            connector?.pusher?.config?.userAuthentication?.headers,
        ]) {
            if (headers) headers['X-CSRF-TOKEN'] = token;
        }
    }

    function reconnect() {
        const pusher = windowLike.Echo?.connector?.pusher;
        if (!pusher || typeof pusher.connect !== 'function') return;
        try {
            // A socket may still say connected after iOS has suspended its timers.
            // Reuse the client and subscriptions; do not recreate Echo or channel handlers.
            if (resetSocket) pusher.disconnect?.();
            if (resetSocket || !['connected', 'connecting'].includes(pusher.connection?.state)) pusher.connect();
        } catch (_) { /* Pusher and the existing visible polls retain their own retry policy. */ }
    }

    function recover({ force = false, allowRetry = true } = {}) {
        if (!visibleOnline() || terminal || typeof fetchLike !== 'function' || !endpoint()) return Promise.resolve(false);
        if (recovery) return recovery;
        if (!force && now() - lastAttempt < RESUME_COOLDOWN_MS) return Promise.resolve(false);
        if (retry !== null) windowLike.clearTimeout(retry);
        retry = null;
        lastAttempt = now();
        const attempt = ++generation;
        const expectedAccount = meta('rt-push-account-binding')?.content;
        const Abort = windowLike.AbortController ?? globalThis.AbortController;
        const requestController = typeof Abort === 'function' ? new Abort() : null;
        controller = requestController;
        state('recovering');
        const current = () => attempt === generation && visibleOnline();
        let requestTimeout = null;
        const timedOut = new Promise((_, reject) => {
            requestTimeout = windowLike.setTimeout(() => {
                requestController?.abort();
                reject(new Error('timeout'));
            }, REQUEST_TIMEOUT_MS);
            timeout = requestTimeout;
        });
        recovery = (async () => {
            try {
                const { response, session } = await Promise.race([Promise.resolve().then(() => fetchLike(endpoint(), {
                    method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    ...(requestController ? { signal: requestController.signal } : {}),
                })).then(async (response) => ({ response, session: response.ok ? await response.json() : null })), timedOut]);
                if (!current()) return false;
                if ([401, 403, 419].includes(response.status)) {
                    requireSession('session-required');
                    return false;
                }
                if (!response.ok) throw new Error('unavailable');
                if (session?.authenticated !== true || typeof session.csrf_token !== 'string' || !session.csrf_token) {
                    throw new Error('invalid-response');
                }
                if (session.account_binding !== expectedAccount || meta('rt-push-account-binding')?.content !== expectedAccount) {
                    requireSession('account-changed');
                    return false;
                }
                applyCsrf(session.csrf_token);
                reconnect();
                resetSocket = false;
                lastNotice = null;
                state('ready', { account_binding: session.account_binding });
                try { Promise.resolve(onRecovered()).catch(() => {}); } catch (_) { /* Independent status report. */ }
                // Only the compact inbox is refreshed. Form state and chat scroll remain intact.
                windowLike.Livewire?.dispatchTo?.('tools.header-inbox', 'inbox:refresh', { notify: false });
                if (noticePending) {
                    noticePending = false;
                    toast('Die Verbindung ist wiederhergestellt. Bitte führe die letzte Aktion erneut aus.');
                }
                return true;
            } catch (_) {
                if (!current()) return false;
                state('offline');
                // At most one short retry per resume. No heartbeat or permanent keepalive.
                if (allowRetry) {
                    retry = windowLike.setTimeout(() => {
                        retry = null;
                        void recover({ force: true, allowRetry: false });
                    }, 2_000);
                } else if (noticePending) {
                    noticePending = false;
                    toast('Die Verbindung ist unterbrochen. Prüfe deine Internetverbindung und versuche die Aktion erneut.');
                }
                return false;
            } finally {
                windowLike.clearTimeout(requestTimeout);
                if (attempt === generation) {
                    recovery = null;
                    controller = null;
                    timeout = null;
                }
            }
        })();
        return recovery;
    }

    function hidden() {
        if (hiddenAt === null) hiddenAt = now();
        cancelPending();
    }

    function resumed(event = {}) {
        if (!visibleOnline()) return;
        if (hiddenAt === null && !event.persisted) return;
        resetSocket ||= event.persisted === true || (hiddenAt !== null && now() - hiddenAt >= SOCKET_RESET_AFTER_MS);
        hiddenAt = null;
        if (terminal) { requireSession(lastNotice || 'session-required'); return; }
        void recover();
    }

    function listen(target, name, callback) {
        target?.addEventListener?.(name, callback);
        listeners.push(() => target?.removeEventListener?.(name, callback));
    }

    function start() {
        if (started || destroyed || !windowLike || !documentLike) return;
        started = true;
        if (documentLike.visibilityState === 'hidden') hiddenAt = now();
        listen(documentLike, 'visibilitychange', () => documentLike.visibilityState === 'hidden' ? hidden() : resumed());
        listen(windowLike, 'pagehide', hidden);
        listen(windowLike, 'pageshow', resumed);
        listen(documentLike, 'freeze', hidden);
        listen(documentLike, 'resume', resumed);
        listen(windowLike, 'focus', resumed);
        listen(windowLike, 'offline', () => { resetSocket = true; cancelPending(); state('offline'); });
        listen(windowLike, 'online', () => { resetSocket = true; void recover(); });
    }

    function handleExpiredRequest() {
        noticePending = true;
        try { windowLike.RTNavOverlay?.hide?.(); } catch (_) { /* Optional navigation overlay. */ }
        if (terminal) { requireSession(lastNotice || 'session-required'); return Promise.resolve(false); }
        if (!endpoint()) { requireSession('session-required'); return Promise.resolve(false); }
        return recover({ force: true });
    }

    function destroy() {
        destroyed = true;
        cancelPending();
        listeners.forEach((remove) => remove());
    }

    return { start, recover, handleExpiredRequest, destroy };
}
