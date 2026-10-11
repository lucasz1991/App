import assert from 'node:assert/strict';
import test from 'node:test';
import { createRuntimeStatusReporter, runtimeStatusPayload } from '../../resources/js/pwa/runtime-status.js';

function eventTarget() {
    const listeners = new Map();
    return {
        addEventListener(name, callback) {
            if (!listeners.has(name)) listeners.set(name, new Set());
            listeners.get(name).add(callback);
        },
        removeEventListener(name, callback) { listeners.get(name)?.delete(callback); },
        dispatch(name) { for (const callback of listeners.get(name) || []) callback({ type: name }); },
        listenerCount() { return [...listeners.values()].reduce((sum, entries) => sum + entries.size, 0); },
    };
}

function harness({ status = 204, fetchImpl = null, permissionApi = true } = {}) {
    const time = { value: 0 };
    const calls = [];
    const queries = [];
    const timers = new Map();
    let nextTimer = 0;
    let sensorCalls = 0;
    let promptCalls = 0;
    const geo = Object.assign(eventTarget(), { state: 'granted' });
    const notifications = Object.assign(eventTarget(), { state: 'prompt' });
    const navigatorLike = {
        userAgent: 'Mozilla/5.0 (Linux; Android 16; Pixel) Chrome/140', platform: 'Linux armv8l', onLine: true,
        geolocation: { getCurrentPosition() { sensorCalls += 1; }, watchPosition() { sensorCalls += 1; } },
        ...(permissionApi ? { permissions: { async query({ name }) {
            queries.push(name);
            return name === 'geolocation' ? geo : notifications;
        } } } : {}),
    };
    const windowLike = Object.assign(eventTarget(), {
        location: { href: 'https://app.example.test/mein-arbeitstag', origin: 'https://app.example.test' },
        isSecureContext: true,
        matchMedia: () => ({ matches: true }),
        Notification: { permission: 'default', requestPermission() { promptCalls += 1; } },
        setInterval(callback, delay) { const id = ++nextTimer; timers.set(id, { callback, delay, interval: true }); return id; },
        clearInterval(id) { timers.delete(id); },
        setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, { callback, delay, interval: false }); return id; },
        clearTimeout(id) { timers.delete(id); },
    });
    const documentLike = Object.assign(eventTarget(), {
        visibilityState: 'visible', readyState: 'complete', endpoint: '/app/runtime-status',
        querySelector(selector) {
            if (selector.includes('rt-app-runtime-status-url')) return this.endpoint ? { content: this.endpoint } : null;
            if (selector.includes('csrf-token')) return { content: 'test-csrf' };
            return null;
        },
    });
    const reporter = createRuntimeStatusReporter({
        windowLike, navigatorLike, documentLike, now: () => time.value,
        fetchLike: async (...args) => {
            calls.push(args);
            return fetchImpl ? fetchImpl(...args) : { status, ok: status >= 200 && status < 300 };
        },
    });
    return { reporter, calls, queries, time, timers, windowLike, navigatorLike, documentLike, geo, notifications,
        sensorCalls: () => sensorCalls, promptCalls: () => promptCalls };
}

test('reports only four enum fields for the actual app context with same-origin CSRF', async () => {
    const h = harness();
    assert.equal(await h.reporter.report(), true);
    assert.deepEqual(JSON.parse(h.calls[0][1].body), {
        platform: 'android', display_mode: 'standalone', notification_permission: 'default', geolocation_permission: 'granted',
    });
    assert.equal(h.calls[0][0], 'https://app.example.test/app/runtime-status');
    assert.equal(h.calls[0][1].method, 'POST');
    assert.equal(h.calls[0][1].credentials, 'same-origin');
    assert.equal(h.calls[0][1].cache, 'no-store');
    assert.equal(h.calls[0][1].headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.deepEqual(h.queries, ['geolocation', 'notifications']);
    assert.equal(h.sensorCalls(), 0);
    assert.equal(h.promptCalls(), 0);
    assert.doesNotMatch(h.calls[0][1].body, /latitude|longitude|Pixel|Chrome|userAgent|fingerprint|endpoint|session|token/);
});

test('browser mode remains a browser observation and unsupported APIs remain explicit', () => {
    assert.deepEqual(runtimeStatusPayload({
        windowLike: { matchMedia: () => ({ matches: false }) }, navigatorLike: { userAgent: 'Windows NT' },
    }), {
        platform: 'desktop', display_mode: 'browser', notification_permission: 'unsupported', geolocation_permission: 'unsupported',
    });
    assert.equal(runtimeStatusPayload({ windowLike: {}, navigatorLike: {} }).display_mode, 'unknown');
    assert.equal(runtimeStatusPayload({ windowLike: {}, navigatorLike: { geolocation: {} } }).geolocation_permission, 'unsupported');
    assert.equal(runtimeStatusPayload({ windowLike: {}, navigatorLike: { platform: 'MacIntel', maxTouchPoints: 5, standalone: true } }).platform, 'ios');
});

test('unreadable geolocation permission stays unknown even though the browser supports location', async () => {
    const h = harness();
    h.navigatorLike.permissions.query = async () => { throw new Error('Unsupported permission query'); };
    assert.equal(await h.reporter.report(), true);
    assert.equal(JSON.parse(h.calls[0][1].body).geolocation_permission, 'unknown');
    assert.equal(h.sensorCalls(), 0);
    assert.equal(h.promptCalls(), 0);
});

test('an installation event does not fabricate standalone mode while the browser remains open', async () => {
    const h = harness();
    h.windowLike.matchMedia = () => ({ matches: false });
    h.reporter.start();
    await h.reporter.report();
    h.time.value = 300_000;
    h.windowLike.dispatch('appinstalled');
    await h.reporter.report();
    assert.equal(h.calls.length, 2);
    assert.equal(JSON.parse(h.calls[1][1].body).display_mode, 'browser');
    assert.equal(h.sensorCalls(), 0);
});

test('no reporting or permission query without authentication metadata, in background, offline or to another origin', async () => {
    const h = harness();
    h.documentLike.endpoint = null;
    assert.equal(await h.reporter.report(), false);
    h.documentLike.endpoint = 'https://other.example.test/app/runtime-status';
    assert.equal(await h.reporter.report(), false);
    h.documentLike.endpoint = '/app/runtime-status';
    h.documentLike.visibilityState = 'hidden';
    assert.equal(await h.reporter.report(), false);
    h.documentLike.visibilityState = 'visible';
    h.navigatorLike.onLine = false;
    assert.equal(await h.reporter.report(), false);
    assert.equal(h.calls.length, 0);
    assert.equal(h.queries.length, 0);
});

test('unchanged status refreshes after five minutes while burst events are collapsed', async () => {
    const h = harness();
    assert.equal(await h.reporter.report(), true);
    await h.reporter.report();
    h.time.value = 6_000;
    assert.equal(await h.reporter.report(), false);
    assert.equal(h.calls.length, 1);
    h.time.value = 300_000;
    assert.equal(await h.reporter.report(), true);
    assert.equal(h.calls.length, 2);
    assert.equal(h.sensorCalls(), 0);
});

test('visible lifecycle and permission events update operational status and clean up listeners', async () => {
    const h = harness();
    h.reporter.start();
    await h.reporter.report();
    assert.equal(h.calls.length, 1);
    h.time.value = 6_000;
    h.geo.state = 'denied';
    h.geo.dispatch('change');
    await h.reporter.report();
    assert.equal(JSON.parse(h.calls.at(-1)[1].body).geolocation_permission, 'denied');
    h.time.value = 12_000;
    h.windowLike.Notification.permission = 'granted';
    h.windowLike.dispatch('railtime-session-permissions-changed');
    await h.reporter.report();
    assert.equal(JSON.parse(h.calls.at(-1)[1].body).notification_permission, 'granted');
    assert.ok([...h.timers.values()].some((timer) => timer.interval && timer.delay === 300_000));
    h.reporter.destroy();
    assert.equal(h.geo.listenerCount(), 0);
    assert.equal(h.windowLike.listenerCount(), 0);
    assert.equal(h.documentLike.listenerCount(), 0);
    assert.equal(h.timers.size, 0);
    assert.equal(await h.reporter.report(), false);
});

test('absent Permissions API stays unknown until an explicit successful permission action supplies evidence', async () => {
    const h = harness({ permissionApi: false });
    await h.reporter.report();
    assert.equal(JSON.parse(h.calls[0][1].body).geolocation_permission, 'unknown');
    h.time.value = 6_000;
    h.reporter.noteGeolocationPermission('granted');
    await h.reporter.report();
    assert.equal(JSON.parse(h.calls.at(-1)[1].body).geolocation_permission, 'granted');
    assert.equal(h.sensorCalls(), 0);
    assert.equal(h.promptCalls(), 0);
});

for (const status of [401, 403, 419]) {
    test(`HTTP ${status} stops automatic reporting for the expired authentication context`, async () => {
        const h = harness({ status });
        assert.equal(await h.reporter.report(), false);
        h.time.value = 600_000;
        assert.equal(await h.reporter.report(), false);
        assert.equal(h.calls.length, 1);
    });
}

test('temporary server failures wait for a bounded cooldown instead of immediate repeated requests', async () => {
    const h = harness({ status: 503 });
    await h.reporter.report();
    h.time.value = 6_000;
    assert.equal(await h.reporter.report(), false);
    assert.equal(h.calls.length, 1);
    h.time.value = 60_000;
    assert.equal(await h.reporter.report(), false);
    assert.equal(h.calls.length, 2);
});
