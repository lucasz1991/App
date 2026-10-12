import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createAppLifecycle } from '../../resources/js/pwa/app-lifecycle.js';

function eventTarget() {
    const listeners = new Map();
    return {
        addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener(name, fn) { listeners.get(name)?.delete(fn); },
        dispatchEvent(event) { for (const fn of listeners.get(event.type) || []) fn(event); },
        dispatch(name, extra = {}) { this.dispatchEvent({ type: name, ...extra }); },
        listenerCount() { return [...listeners.values()].reduce((sum, items) => sum + items.size, 0); },
    };
}

const flush = async () => { for (let i = 0; i < 16; i += 1) await Promise.resolve(); };
const goodSession = () => ({ authenticated: true, csrf_token: 'fresh-csrf', account_binding: 'same-account' });
const response = (status = 200, data = goodSession()) => ({ status, ok: status >= 200 && status < 300, json: async () => data });

function harness({ fetchImpl = null, status = 200, data = goodSession() } = {}) {
    const time = { value: 0 };
    const requests = [];
    const events = [];
    const socket = [];
    const refreshes = [];
    const recovered = [];
    const dialogs = [];
    const timers = new Map();
    let nextTimer = 0;
    let reloads = 0;
    const metadata = {
        'rt-pwa-session-status-url': { content: '/pwa/session-status' },
        'rt-push-account-binding': { content: 'same-account' },
        'csrf-token': { content: 'old-csrf' },
    };
    const documentLike = Object.assign(eventTarget(), {
        visibilityState: 'visible',
        querySelector(selector) { return metadata[selector.match(/name="([^"]+)"/)?.[1]] || null; },
    });
    const navigatorLike = { onLine: true };
    const auth = { headers: { 'X-CSRF-TOKEN': 'old-csrf', Authorization: 'preserve-existing' } };
    const pusher = {
        connection: { state: 'connected' },
        config: { channelAuthorization: { headers: {} }, userAuthentication: { headers: {} } },
        disconnect() { socket.push('disconnect'); this.connection.state = 'disconnected'; },
        connect() { socket.push('connect'); this.connection.state = 'connecting'; },
        channels: { existing: { retained: true } },
    };
    const windowLike = Object.assign(eventTarget(), {
        location: { origin: 'https://railtime.example', href: 'https://railtime.example/profile', reload() { reloads += 1; } },
        AbortController,
        CustomEvent: class { constructor(type, { detail }) { this.type = type; this.detail = detail; } },
        setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        livewireScriptConfig: { csrf: 'old-csrf' }, Laravel: { csrfToken: 'old-csrf' },
        axios: { defaults: { headers: { common: {} } } },
        Echo: { connector: { pusher, options: { auth, userAuthentication: { headers: {} } } }, disconnect() { pusher.disconnect(); } },
        Livewire: { dispatchTo(...args) { refreshes.push(args); } },
        Swal: { async fire(options) { dialogs.push(options); return { isConfirmed: false }; } },
    });
    windowLike.addEventListener('railtime:app-connection-state', (event) => events.push(event));
    windowLike.addEventListener('swal:toast', (event) => events.push(event));
    const lifecycle = createAppLifecycle({
        windowLike, documentLike, navigatorLike, now: () => time.value,
        fetchLike: async (...args) => { requests.push(args); return fetchImpl ? fetchImpl(...args) : response(status, data); },
        onRecovered() { recovered.push(metadata['csrf-token'].content); },
    });
    return {
        lifecycle, documentLike, navigatorLike, windowLike, time, requests, events, socket, refreshes, recovered, metadata, timers, dialogs,
        reloads: () => reloads,
        hide() { documentLike.visibilityState = 'hidden'; documentLike.dispatch('visibilitychange'); },
        show() { documentLike.visibilityState = 'visible'; documentLike.dispatch('visibilitychange'); },
        async runTimer(delay) {
            const entry = [...timers].find(([, timer]) => timer.delay === delay);
            assert.ok(entry, `Expected ${delay}ms timer`);
            timers.delete(entry[0]); entry[1].callback(); await flush();
        },
    };
}

test('suspended socket reconnects on foreground using current session without a page reload or action replay', async () => {
    const h = harness();
    h.lifecycle.start();
    assert.equal(h.requests.length, 0);
    assert.equal(h.timers.size, 0);
    h.hide(); h.time.value = 180_000; h.show();
    await h.lifecycle.recover();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0][0], 'https://railtime.example/pwa/session-status');
    assert.equal(h.requests[0][1].method, 'GET');
    assert.equal(h.requests[0][1].cache, 'no-store');
    assert.equal(h.requests[0][1].redirect, 'error');
    assert.equal(h.requests[0][1].credentials, 'same-origin');
    assert.equal(h.requests[0][1].body, undefined);
    assert.deepEqual(h.socket, ['disconnect', 'connect']);
    assert.deepEqual(h.refreshes, [['tools.header-inbox', 'inbox:refresh', { notify: false }]]);
    assert.deepEqual(h.recovered, ['fresh-csrf']);
    assert.deepEqual(h.events.find((event) => event.detail.state === 'ready').detail,
        { state: 'ready', account_binding: 'same-account' });
    assert.equal(h.windowLike.Echo.connector.pusher.channels.existing.retained, true);
    assert.equal(h.reloads(), 0);
    assert.equal(h.timers.size, 0);
});

test('fresh CSRF reaches Livewire, axios and captured Echo/Pusher headers before reconnect', async () => {
    const h = harness();
    await h.lifecycle.recover();
    assert.equal(h.metadata['csrf-token'].content, 'fresh-csrf');
    assert.equal(h.windowLike.livewireScriptConfig.csrf, 'fresh-csrf');
    assert.equal(h.windowLike.Laravel.csrfToken, 'fresh-csrf');
    assert.equal(h.windowLike.axios.defaults.headers.common['X-CSRF-TOKEN'], 'fresh-csrf');
    assert.equal(h.windowLike.Echo.connector.options.auth.headers['X-CSRF-TOKEN'], 'fresh-csrf');
    assert.equal(h.windowLike.Echo.connector.options.auth.headers.Authorization, 'preserve-existing');
    assert.equal(h.windowLike.Echo.connector.pusher.config.channelAuthorization.headers['X-CSRF-TOKEN'], 'fresh-csrf');
    assert.equal(h.windowLike.Echo.connector.pusher.config.userAuthentication.headers['X-CSRF-TOKEN'], 'fresh-csrf');
    assert.deepEqual(h.socket, []);
});

test('healthy short switches do not reset sockets and duplicate foreground signals are coalesced', async () => {
    const h = harness(); h.lifecycle.start(); h.hide(); h.time.value = 2_000; h.show();
    h.windowLike.dispatch('pageshow'); h.windowLike.dispatch('focus');
    await h.lifecycle.recover();
    assert.equal(h.requests.length, 1);
    assert.deepEqual(h.socket, []);
    await h.lifecycle.recover();
    assert.equal(h.requests.length, 1);
});

test('BFCache restores and network returns reset a potentially stale connection', async () => {
    const h = harness(); h.lifecycle.start();
    h.windowLike.dispatch('pageshow', { persisted: true }); await h.lifecycle.recover();
    assert.deepEqual(h.socket, ['disconnect', 'connect']);
    h.navigatorLike.onLine = false; h.windowLike.dispatch('offline');
    h.time.value = 15_000; h.navigatorLike.onLine = true; h.windowLike.dispatch('online'); await h.lifecycle.recover();
    assert.equal(h.requests.length, 2);
    assert.deepEqual(h.socket, ['disconnect', 'connect', 'disconnect', 'connect']);
});

test('background, offline, missing authentication metadata and cross-origin probes are forbidden', async () => {
    const h = harness();
    h.documentLike.visibilityState = 'hidden'; assert.equal(await h.lifecycle.recover(), false);
    h.documentLike.visibilityState = 'visible'; h.navigatorLike.onLine = false; assert.equal(await h.lifecycle.recover(), false);
    h.navigatorLike.onLine = true; h.metadata['rt-pwa-session-status-url'].content = 'https://elsewhere.test/status';
    assert.equal(await h.lifecycle.recover(), false);
    h.metadata['rt-pwa-session-status-url'].content = '/pwa/session-status'; h.metadata['rt-push-account-binding'].content = '';
    assert.equal(await h.lifecycle.recover(), false);
    assert.equal(h.requests.length, 0); assert.equal(h.timers.size, 0);
});

for (const status of [401, 403, 419]) {
    test(`server ${status} ends recovery without renewing or navigating, and only explicit reload can navigate`, async () => {
        const h = harness({ status });
        await h.lifecycle.handleExpiredRequest();
        await h.lifecycle.handleExpiredRequest();
        assert.equal(h.requests.length, 1);
        assert.deepEqual(h.socket, ['disconnect', 'disconnect']);
        assert.equal(h.dialogs.length, 1);
        assert.equal(h.metadata['csrf-token'].content, 'old-csrf');
        assert.equal(h.reloads(), 0); assert.deepEqual(h.refreshes, []); assert.deepEqual(h.recovered, []);
        assert.equal(h.timers.size, 0);
        const accepted = harness({ status });
        accepted.windowLike.Swal.fire = async () => ({ isConfirmed: true });
        await accepted.lifecycle.handleExpiredRequest(); await flush();
        assert.equal(accepted.reloads(), 1);
    });
}

test('another authenticated account cannot update CSRF, resume old subscriptions or report the old page', async () => {
    const h = harness({ data: { ...goodSession(), account_binding: 'different-account' } });
    await h.lifecycle.recover();
    assert.deepEqual(h.socket, ['disconnect']); assert.deepEqual(h.refreshes, []); assert.deepEqual(h.recovered, []);
    assert.equal(h.metadata['csrf-token'].content, 'old-csrf');
    assert.match(h.dialogs[0].text, /anderen Fenster/);
    assert.equal(h.reloads(), 0);
});

test('same-account 419 recovery preserves the page and asks user to repeat the failed action', async () => {
    const h = harness(); await h.lifecycle.handleExpiredRequest();
    assert.equal(h.requests.length, 1); assert.equal(h.requests[0][1].method, 'GET');
    assert.equal(h.reloads(), 0); assert.equal(h.dialogs.length, 0);
    assert.ok(h.events.some((event) => /letzte Aktion erneut/.test(event.detail.text || '')));
});

test('a 419 on a guest page without session metadata still offers an explicit reload', async () => {
    const h = harness(); h.metadata['rt-pwa-session-status-url'].content = '';
    await h.lifecycle.handleExpiredRequest();
    assert.equal(h.requests.length, 0); assert.equal(h.dialogs.length, 1); assert.equal(h.reloads(), 0);
});

test('temporary failures have only one short retry with no background interval', async () => {
    const h = harness({ status: 503 });
    assert.equal(await h.lifecycle.recover(), false);
    assert.equal(h.timers.size, 1);
    await h.runTimer(2_000);
    assert.equal(h.requests.length, 2); assert.equal(h.timers.size, 0);
    assert.deepEqual(h.recovered, []); assert.deepEqual(h.socket, []);
});

test('the timeout includes a stalled response body, not only response headers', async () => {
    const h = harness({ fetchImpl: async () => ({ status: 200, ok: true, json: () => new Promise(() => {}) }) });
    const pending = h.lifecycle.recover(); await flush();
    await h.runTimer(10_000); assert.equal(await pending, false);
    assert.equal(h.requests[0][1].signal.aborted, true);
    assert.equal(h.timers.size, 1);
    h.lifecycle.destroy(); assert.equal(h.timers.size, 0);
});

test('responses from before a new suspension are discarded even if fetch ignores abort', async () => {
    let resolveOld;
    let attempt = 0;
    const h = harness({ fetchImpl: () => ++attempt === 1 ? new Promise((resolve) => { resolveOld = resolve; }) : response() });
    h.lifecycle.start();
    const pending = h.lifecycle.recover(); await flush(); h.hide();
    assert.equal(h.requests[0][1].signal.aborted, true);
    h.time.value = 1_000; h.show(); await h.lifecycle.recover();
    assert.equal(h.requests.length, 2);
    resolveOld(response(200, { ...goodSession(), csrf_token: 'stale-csrf' }));
    await pending;
    assert.equal(h.metadata['csrf-token'].content, 'fresh-csrf'); assert.equal(h.recovered.length, 1);
});

test('hide cancels a scheduled retry; destroy removes event handlers and pending work', async () => {
    const h = harness({ status: 503 }); h.lifecycle.start(); h.lifecycle.start();
    await h.lifecycle.recover(); h.hide(); assert.equal(h.timers.size, 0);
    h.lifecycle.destroy();
    assert.equal(h.documentLike.listenerCount(), 0);
    assert.equal(h.windowLike.listenerCount(), 2); // The test's observers, not lifecycle handlers.
    h.time.value = 30_000; h.show(); h.windowLike.dispatch('online'); await flush();
    assert.equal(h.requests.length, 1);
});

test('Livewire 419 integration no longer reloads or automatically resends failed actions', () => {
    const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
    const hook = source.slice(source.indexOf("Livewire.hook('request', ({ fail })"), source.indexOf('window.Alpine = Alpine'));
    assert.match(hook, /preventDefault\(\)/);
    assert.match(hook, /RailtimeAppLifecycle\?\.handleExpiredRequest\(\)/);
    assert.doesNotMatch(hook, /location\.reload|sessionStorage|fetch\(|\.retry\(/);
});
