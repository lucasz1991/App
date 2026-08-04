import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    LIVE_LOCATION_DURATIONS,
    LiveLocationCoordinator,
    isLiveLocationFresh,
    liveLocationPositionPayload,
    normalizeLiveLocationList,
    normalizeLiveLocationResponse,
    railtimeLiveLocationCard,
    railtimeLiveLocationPreview,
    railtimeLiveLocationShare,
    shouldPublishLiveLocation,
} from '../../resources/js/live-location.js';
import {
    createLiveLocationMap,
    validLiveLocationCoordinates,
} from '../../resources/js/live-location-map.js';

function responseShare({
    id = '018f-live-location-a',
    chatId = 17,
    messageId = 42,
    latitude = 50.1109,
    longitude = 8.6821,
    status = 'active',
    expiresAt = '2099-01-01T00:00:00+00:00',
} = {}) {
    return {
        live_location: {
            id,
            chat_id: chatId,
            message_id: messageId,
            status,
            expires_at: expiresAt,
            last_position_at: '2026-08-03T10:00:00+00:00',
            position: {
                latitude,
                longitude,
                accuracy: 8.5,
                altitude: 112,
                altitude_accuracy: 5,
                heading: 87,
                speed: 1.4,
            },
            routes: {
                update: `/chat/live-locations/${id}`,
                stop: `/chat/live-locations/${id}`,
            },
        },
    };
}

function geolocationHarness(position = null) {
    const current = position || {
        coords: {
            latitude: 50.1109,
            longitude: 8.6821,
            accuracy: 8.5,
            altitude: null,
            altitudeAccuracy: null,
            heading: null,
            speed: null,
        },
    };
    let watchCalls = 0;
    let clearCalls = 0;
    let currentCalls = 0;
    let watchSuccess = null;

    return {
        api: {
            getCurrentPosition(success) {
                currentCalls += 1;
                success(current);
            },
            watchPosition(success) {
                watchCalls += 1;
                watchSuccess = success;
                return 73;
            },
            clearWatch(id) {
                assert.equal(id, 73);
                clearCalls += 1;
            },
        },
        emit(next = current) {
            watchSuccess?.(next);
        },
        watchCalls: () => watchCalls,
        clearCalls: () => clearCalls,
        currentCalls: () => currentCalls,
    };
}

function coordinatorWindow() {
    const events = [];

    return {
        isSecureContext: true,
        CustomEvent: class CustomEvent {
            constructor(type, options = {}) {
                this.type = type;
                this.detail = options.detail;
            }
        },
        dispatchEvent(event) {
            events.push(event);
        },
        events,
    };
}

function coordinatorTimers() {
    let nextId = 0;
    const timers = new Map();

    return {
        setTimeoutLike(callback, delay) {
            nextId += 1;
            timers.set(nextId, { callback, delay });
            return nextId;
        },
        clearTimeoutLike(id) {
            timers.delete(id);
        },
        timers,
    };
}

test('uses only the five allowed durations and understands the backend response envelope', () => {
    assert.deepEqual(LIVE_LOCATION_DURATIONS, [15, 30, 45, 60, 90]);

    const share = normalizeLiveLocationResponse(responseShare());

    assert.equal(share.id, '018f-live-location-a');
    assert.equal(share.chatId, 17);
    assert.equal(share.messageId, 42);
    assert.equal(share.latitude, 50.1109);
    assert.equal(share.longitude, 8.6821);
    assert.equal(share.altitudeAccuracy, 5);
    assert.equal(share.updateUrl, '/chat/live-locations/018f-live-location-a');
    assert.equal(share.stopUrl, '/chat/live-locations/018f-live-location-a');

    assert.deepEqual(
        normalizeLiveLocationList({
            live_locations: [responseShare().live_location],
        }).map((item) => item.id),
        ['018f-live-location-a'],
    );
});

test('also accepts snake_case cards with flat coordinates', () => {
    const share = normalizeLiveLocationResponse({
        share_id: 'flat-location',
        chat_id: 3,
        message_id: 9,
        latitude: '51.05',
        longitude: '13.74',
        accuracy: '11',
        located_at: '2026-08-03T10:00:00Z',
        expires_at: '2026-08-03T11:00:00Z',
        update_url: '/update',
        stop_url: '/stop',
        can_stop: 'true',
    });

    assert.equal(share.id, 'flat-location');
    assert.equal(share.latitude, 51.05);
    assert.equal(share.longitude, 13.74);
    assert.equal(share.canStop, true);
});

test('sends the geolocation contract flat and omits unavailable optional sensor values', () => {
    assert.deepEqual(liveLocationPositionPayload({
        coords: {
            latitude: 50.11,
            longitude: 8.68,
            accuracy: 7,
            altitude: null,
            altitudeAccuracy: null,
            heading: 90,
            speed: null,
        },
    }), {
        latitude: 50.11,
        longitude: 8.68,
        accuracy: 7,
        heading: 90,
    });
});

test('throttles noisy fixes while allowing distance updates and a heartbeat', () => {
    const previous = {
        latitude: 50,
        longitude: 8,
        publishedAt: 10_000,
    };

    assert.equal(shouldPublishLiveLocation(previous, {
        latitude: 50.01,
        longitude: 8.01,
    }, 12_000), false);
    assert.equal(shouldPublishLiveLocation(previous, {
        latitude: 50.00001,
        longitude: 8.00001,
    }, 45_000), true);
    assert.equal(shouldPublishLiveLocation(previous, {
        latitude: 50.001,
        longitude: 8.001,
    }, 16_000), true);
});

test('marks a location as paused after the bounded heartbeat freshness window', () => {
    const locatedAt = '2026-08-03T10:00:00Z';

    assert.equal(isLiveLocationFresh(locatedAt, Date.parse('2026-08-03T10:01:14Z')), true);
    assert.equal(isLiveLocationFresh(locatedAt, Date.parse('2026-08-03T10:01:16Z')), false);
    assert.equal(isLiveLocationFresh('', Date.parse('2026-08-03T10:00:00Z')), false);
});

test('starts multiple shares with one watcher and uses only server-authored update and stop URLs', async () => {
    let now = 100_000;
    const geo = geolocationHarness();
    const windowLike = coordinatorWindow();
    const timers = coordinatorTimers();
    const requests = [];
    let starts = 0;
    const coordinator = new LiveLocationCoordinator({
        windowLike,
        navigatorLike: { geolocation: geo.api, onLine: true },
        documentLike: null,
        now: () => now,
        ...timers,
        request: async (url, options) => {
            const body = options.body ? JSON.parse(options.body) : null;
            requests.push({ url, method: options.method, body });

            if (options.method === 'POST') {
                starts += 1;
                return responseShare({
                    id: `location-${starts}`,
                    chatId: starts === 1 ? 17 : 18,
                    messageId: 40 + starts,
                });
            }

            if (options.method === 'PATCH') {
                return responseShare({
                    id: url.endsWith('location-1') ? 'location-1' : 'location-2',
                    chatId: url.endsWith('location-1') ? 17 : 18,
                });
            }

            return responseShare({
                id: url.endsWith('location-1') ? 'location-1' : 'location-2',
                status: 'stopped',
            });
        },
    });

    const first = await coordinator.start({
        chatId: 17,
        durationMinutes: 15,
        startUrl: '/chat/17/live-locations',
        replyToMessageId: 99,
    });
    await coordinator.start({
        chatId: 18,
        durationMinutes: 90,
        startUrl: '/chat/18/live-locations',
    });

    assert.equal(first.id, 'location-1');
    assert.equal(geo.watchCalls(), 1);
    assert.equal(coordinator.snapshot().activeCount, 2);
    assert.deepEqual(requests[0], {
        url: '/chat/17/live-locations',
        method: 'POST',
        body: {
            chat_id: 17,
            duration_minutes: 15,
            latitude: 50.1109,
            longitude: 8.6821,
            accuracy: 8.5,
            reply_to_message_id: 99,
        },
    });
    assert.equal('position' in requests[0].body, false);
    assert.equal('position_timestamp' in requests[0].body, false);
    assert.deepEqual(windowLike.events[0].detail, {
        shareId: 'location-1',
        chatId: 17,
        messageId: 41,
        status: 'active',
    });

    now += 31_000;
    await coordinator._handlePosition({
        coords: {
            latitude: 50.112,
            longitude: 8.684,
            accuracy: 6,
        },
    });
    const updates = requests.filter((request) => request.method === 'PATCH');
    assert.equal(updates.length, 2);
    assert.deepEqual(updates.map((request) => request.url).sort(), [
        '/chat/live-locations/location-1',
        '/chat/live-locations/location-2',
    ]);

    await coordinator.stop('location-1');
    assert.equal(geo.clearCalls(), 0);
    await coordinator.stop('location-2');
    assert.equal(geo.clearCalls(), 1);
    assert.deepEqual(
        requests.filter((request) => request.method === 'DELETE').map((request) => request.url),
        ['/chat/live-locations/location-1', '/chat/live-locations/location-2'],
    );
});

test('rehydrates an active session share without prompting and keeps a dismissed resume notice closed', async () => {
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const coordinator = new LiveLocationCoordinator({
        activeUrl: '/chat/live-locations/active',
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: {
            geolocation: geo.api,
            onLine: true,
            permissions: {
                query: async () => ({
                    state: 'prompt',
                    addEventListener() {},
                    removeEventListener() {},
                }),
            },
        },
        ...timers,
        request: async () => ({ live_locations: [responseShare().live_location] }),
    });

    await coordinator.initialize();

    assert.equal(coordinator.snapshot().activeCount, 1);
    assert.equal(coordinator.snapshot().needsResume, true);
    assert.equal(coordinator.snapshot().resumePromptVisible, true);
    assert.equal(coordinator.snapshot().canResume, true);
    assert.equal(geo.currentCalls(), 0);
    assert.equal(geo.watchCalls(), 0);

    assert.equal(coordinator.dismissResumePrompt(), true);
    assert.equal(coordinator.snapshot().resumePromptVisible, false);
    assert.equal(coordinator.snapshot().needsResume, true);

    await coordinator.reconcile({ quiet: true });
    assert.equal(coordinator.snapshot().resumePromptVisible, false);
    coordinator.destroy();
});

test('permission denial leaves a closeable notice instead of an endless resume loop', async () => {
    const timers = coordinatorTimers();
    const coordinator = new LiveLocationCoordinator({
        activeUrl: '/chat/live-locations/active',
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: {
            onLine: true,
            permissions: {
                query: async () => ({
                    state: 'prompt',
                    addEventListener() {},
                    removeEventListener() {},
                }),
            },
            geolocation: {
                getCurrentPosition(_success, failure) {
                    failure({ code: 1, message: 'Permission denied' });
                },
                watchPosition() {
                    throw new Error('A denied permission must not start a watcher.');
                },
                clearWatch() {},
            },
        },
        ...timers,
        request: async () => ({ live_locations: [responseShare().live_location] }),
    });

    await coordinator.initialize();
    await assert.rejects(
        () => coordinator.resume(),
        (error) => error?.code === 1 && error?.message === 'Permission denied',
    );

    assert.equal(coordinator.snapshot().permissionState, 'denied');
    assert.equal(coordinator.snapshot().needsResume, true);
    assert.equal(coordinator.snapshot().resumePromptVisible, true);
    assert.equal(coordinator.snapshot().canResume, false);
    assert.equal(coordinator.snapshot().resumeErrorCode, 'permission_denied');
    assert.equal(coordinator.snapshot().busy, null);

    coordinator.dismissResumePrompt();
    assert.equal(coordinator.snapshot().resumePromptVisible, false);
    coordinator.destroy();
});

test('an insecure context never exposes an impossible resume action', async () => {
    const timers = coordinatorTimers();
    const windowLike = coordinatorWindow();
    windowLike.isSecureContext = false;
    const coordinator = new LiveLocationCoordinator({
        activeUrl: '/chat/live-locations/active',
        windowLike,
        documentLike: null,
        navigatorLike: { geolocation: geolocationHarness().api, onLine: true },
        ...timers,
        request: async () => ({ live_locations: [responseShare().live_location] }),
    });

    await coordinator.initialize();

    assert.equal(coordinator.snapshot().permissionState, 'insecure');
    assert.equal(coordinator.snapshot().needsResume, false);
    assert.equal(coordinator.snapshot().resumePromptVisible, false);
    assert.equal(coordinator.snapshot().canResume, false);
    coordinator.destroy();
});

test('foreground recovery recreates a potentially stale native watcher exactly once', async () => {
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const coordinator = new LiveLocationCoordinator({
        activeUrl: '/chat/live-locations/active',
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: {
            geolocation: geo.api,
            onLine: true,
            permissions: {
                query: async () => ({
                    state: 'granted',
                    addEventListener() {},
                    removeEventListener() {},
                }),
            },
        },
        ...timers,
        request: async (url, options) => (options.method === 'PATCH'
            ? responseShare()
            : { live_locations: [responseShare().live_location] }),
    });

    await coordinator.initialize();
    assert.equal(geo.watchCalls(), 1);

    await Promise.all([
        coordinator._resumeAfterVisibility(),
        coordinator._resumeAfterVisibility(),
    ]);

    assert.equal(geo.clearCalls(), 1);
    assert.equal(geo.watchCalls(), 2);
    assert.equal(geo.currentCalls(), 1);
    coordinator.destroy();
});

test('publishes the latest already known fix once with keepalive before suspension', async () => {
    let now = 100_000;
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const requests = [];
    const coordinator = new LiveLocationCoordinator({
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: { geolocation: geo.api, onLine: true },
        now: () => now,
        ...timers,
        request: async (url, options) => {
            requests.push({ url, ...options });

            return responseShare();
        },
    });

    await coordinator.start({
        chatId: 17,
        durationMinutes: 30,
        startUrl: '/chat/17/live-locations',
    });
    requests.length = 0;
    now += 2_000;

    assert.equal(await coordinator._flushLatestPosition({ keepalive: true }), true);
    assert.equal(await coordinator._flushLatestPosition({ keepalive: true }), false);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'PATCH');
    assert.equal(requests[0].keepalive, true);
    assert.equal(requests[0].url, '/chat/live-locations/018f-live-location-a');

    coordinator.destroy();
});

test('keeps both the active update and a queued suspension fix unload-safe', async () => {
    let now = 100_000;
    let releaseUpdate;
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const requests = [];
    const updateGate = new Promise((resolve) => {
        releaseUpdate = resolve;
    });
    const coordinator = new LiveLocationCoordinator({
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: { geolocation: geo.api, onLine: true },
        now: () => now,
        ...timers,
        request: async (url, options) => {
            requests.push({ url, ...options });

            if (options.method === 'PATCH' && requests.filter((item) => item.method === 'PATCH').length === 1) {
                await updateGate;
            }

            return responseShare();
        },
    });

    await coordinator.start({
        chatId: 17,
        durationMinutes: 30,
        startUrl: '/chat/17/live-locations',
    });
    requests.length = 0;
    now += 31_000;

    const activeUpdate = coordinator._handlePosition({
        coords: { latitude: 50.112, longitude: 8.684, accuracy: 6 },
    }, { force: true });
    await Promise.resolve();

    assert.equal(await coordinator._flushLatestPosition({ keepalive: true }), false);
    releaseUpdate();
    await activeUpdate;

    const updates = requests.filter((request) => request.method === 'PATCH');
    assert.equal(updates.length, 2);
    assert.equal(updates[0].keepalive, true);
    assert.equal(updates[1].keepalive, true);

    coordinator.destroy();
});

test('a failed first share never retains its precise position', async () => {
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const coordinator = new LiveLocationCoordinator({
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: { geolocation: geo.api, onLine: true },
        ...timers,
        request: async () => {
            throw new Error('Server rejected the share');
        },
    });

    await assert.rejects(() => coordinator.start({
        chatId: 17,
        durationMinutes: 30,
        startUrl: '/chat/17/live-locations',
    }), /Server rejected the share/);

    assert.equal(coordinator._latestPosition, null);
    assert.equal(coordinator._pendingPosition, null);
    assert.equal(coordinator.snapshot().activeCount, 0);
    coordinator.destroy();
});

test('ending the last share clears every precise in-memory position', async () => {
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const coordinator = new LiveLocationCoordinator({
        windowLike: coordinatorWindow(),
        documentLike: null,
        navigatorLike: { geolocation: geo.api, onLine: true },
        ...timers,
        request: async () => responseShare(),
    });

    await coordinator.start({
        chatId: 17,
        durationMinutes: 30,
        startUrl: '/chat/17/live-locations',
    });
    coordinator._pendingPosition = { latitude: 50.12, longitude: 8.69, accuracy: 4 };
    coordinator._pendingPositionForce = true;
    coordinator._pendingPositionKeepalive = true;

    await coordinator.stop('018f-live-location-a');

    assert.equal(coordinator._latestPosition, null);
    assert.equal(coordinator._pendingPosition, null);
    assert.equal(coordinator._pendingPositionForce, false);
    assert.equal(coordinator._pendingPositionKeepalive, false);
    coordinator.destroy();
});

test('logout immediately clears in-memory coordinates and the watcher without browser storage', async () => {
    const geo = geolocationHarness();
    const timers = coordinatorTimers();
    const windowLike = coordinatorWindow();
    Object.defineProperty(windowLike, 'localStorage', {
        get() {
            throw new Error('Live location must not read localStorage');
        },
    });
    const coordinator = new LiveLocationCoordinator({
        windowLike,
        navigatorLike: { geolocation: geo.api, onLine: true },
        documentLike: null,
        ...timers,
        request: async () => responseShare(),
    });

    await coordinator.start({
        chatId: 17,
        durationMinutes: 30,
        startUrl: '/chat/17/live-locations',
    });
    coordinator.handleLogout();

    assert.equal(coordinator.snapshot().activeCount, 0);
    assert.equal(coordinator.snapshot().watching, false);
    assert.equal(geo.clearCalls(), 1);
});

function fakeLeaflet() {
    const calls = [];
    const layer = (kind) => ({
        addTo() { calls.push(`${kind}:add`); return this; },
        setLatLng(value) { calls.push([`${kind}:position`, value]); },
        setRadius(value) { calls.push([`${kind}:radius`, value]); },
        remove() { calls.push(`${kind}:remove`); },
    });
    const map = {
        setView(value, zoom, options) { calls.push(['map:view', value, zoom, options]); return this; },
        getZoom() { return 15; },
        panTo(value) { calls.push(['map:pan', value]); },
        invalidateSize(options) { calls.push(['map:invalidate', options]); },
        remove() { calls.push('map:remove'); },
    };

    return {
        calls,
        api: {
            map(element, options) { calls.push(['map:create', element, options]); return map; },
            tileLayer(url, options) { calls.push(['tiles:create', url, options]); return layer('tiles'); },
            divIcon(options) { calls.push(['marker:icon', options]); return options; },
            marker() { return layer('marker'); },
            circle() { return layer('accuracy'); },
        },
    };
}

test('lazy map adapter updates, recenters and completely removes Leaflet state', async () => {
    const leaflet = fakeLeaflet();
    const element = {};
    const adapter = await createLiveLocationMap({
        element,
        latitude: 50,
        longitude: 8,
        accuracy: 12,
        interactive: false,
        mapConfig: {
            tileUrl: 'https://tiles.example/{z}/{x}/{y}.png',
            attribution: 'Test tiles',
            maxZoom: 18,
        },
        leafletLoader: async () => leaflet.api,
    });

    assert.equal(validLiveLocationCoordinates(50, 8), true);
    assert.equal(validLiveLocationCoordinates(100, 8), false);
    assert.equal(leaflet.calls[0][2].dragging, false);
    assert.equal(adapter.update(
        { latitude: 50.1, longitude: 8.1, accuracy: 5 },
        { recenter: true },
    ), true);
    adapter.recenter();
    adapter.invalidate();
    assert.ok(leaflet.calls.some((call) => Array.isArray(call)
        && call[0] === 'map:invalidate'
        && call[1].pan === true
        && call[1].debounceMoveend === true));
    assert.ok(leaflet.calls.some((call) => Array.isArray(call)
        && call[0] === 'map:view'
        && call[1][0] === 50.1
        && call[1][1] === 8.1));
    adapter.destroy();
    assert.ok(leaflet.calls.includes('map:remove'));
});

test('map adapter repairs hidden-to-visible resizes, recenters, and disconnects its observer', async () => {
    const leaflet = fakeLeaflet();
    const frames = [];
    let resizeCallback = null;
    let disconnected = 0;
    let rectangle = { width: 0, height: 0 };
    const element = {
        getBoundingClientRect() { return rectangle; },
    };
    const windowLike = {
        requestAnimationFrame(callback) {
            frames.push(callback);
            return frames.length;
        },
        cancelAnimationFrame() {},
        ResizeObserver: class ResizeObserver {
            constructor(callback) { resizeCallback = callback; }
            observe(target) { assert.equal(target, element); }
            disconnect() { disconnected += 1; }
        },
    };
    const adapter = await createLiveLocationMap({
        element,
        latitude: 52.52,
        longitude: 13.405,
        accuracy: 6,
        windowLike,
        leafletLoader: async () => leaflet.api,
    });

    frames.shift()?.();
    assert.equal(leaflet.calls.some((call) => call[0] === 'map:invalidate'), false);

    rectangle = { width: 320, height: 160 };
    resizeCallback();
    frames.shift()?.();

    const invalidation = leaflet.calls.find((call) => call[0] === 'map:invalidate');
    assert.deepEqual(invalidation[1], {
        animate: false,
        debounceMoveend: true,
        pan: true,
    });
    assert.ok(leaflet.calls.some((call) => call[0] === 'map:view'
        && call[1][0] === 52.52
        && call[1][1] === 13.405));

    adapter.destroy();
    assert.equal(disconnected, 1);
});

test('mini-card and preview apply the latest coordinates after an asynchronous map load', async () => {
    const calls = [];
    let resolveCardMap;
    const cardMap = {
        update(position, options) { calls.push(['card:update', position, options]); },
        invalidate(options) { calls.push(['card:invalidate', options]); },
        destroy() { calls.push(['card:destroy']); },
    };
    const card = railtimeLiveLocationCard({
        share_id: 'card-location',
        latitude: 50,
        longitude: 8,
        mapFactory: () => new Promise((resolve) => { resolveCardMap = resolve; }),
    });
    card.$refs = { map: {} };
    const cardLoading = card.ensureMap();
    card.latitude = 51;
    card.longitude = 9;
    resolveCardMap(cardMap);
    await cardLoading;

    assert.deepEqual(calls.find((call) => call[0] === 'card:update'), [
        'card:update',
        { latitude: 51, longitude: 9, accuracy: null },
        { recenter: true },
    ]);

    let resolvePreviewMap;
    const previewMap = {
        update(position, options) { calls.push(['preview:update', position, options]); },
        invalidate(options) { calls.push(['preview:invalidate', options]); },
        destroy() { calls.push(['preview:destroy']); },
    };
    const preview = railtimeLiveLocationPreview({
        mapFactory: () => new Promise((resolve) => { resolvePreviewMap = resolve; }),
    });
    preview.$refs = { map: {} };
    preview.open = true;
    preview.applyShare(responseShare({ id: 'share-a', latitude: 50, longitude: 8 }));
    const previewLoading = preview.ensureMap();
    preview.applyShare(responseShare({ id: 'share-b', latitude: 53, longitude: 10 }));
    resolvePreviewMap(previewMap);
    await previewLoading;

    assert.equal(preview._mappedShareId, 'share-b');
    assert.deepEqual(calls.find((call) => call[0] === 'preview:update'), [
        'preview:update',
        { latitude: 53, longitude: 10, accuracy: 8.5 },
        { recenter: true },
    ]);
});

test('preview recenter and stop actions always use the currently displayed share', async () => {
    const calls = [];
    const preview = railtimeLiveLocationPreview({});
    const share = responseShare({ id: 'share-current', latitude: 51, longitude: 9 }).live_location;
    share.can_stop = true;
    preview.applyShare(share);
    preview._map = {
        recenter() { calls.push(['recenter', preview.shareId]); },
        update() {},
    };
    preview.$store = {
        liveLocation: {
            async stop(id, url) {
                calls.push(['stop', id, url]);

                return { id, status: 'stopped' };
            },
        },
    };

    preview.recenter();
    await preview.stopSharing();

    assert.deepEqual(calls, [
        ['recenter', 'share-current'],
        ['stop', 'share-current', '/chat/live-locations/share-current'],
    ]);
    assert.equal(preview.status, 'stopped');
    assert.equal(preview.stopping, false);
    assert.equal(preview.busy, false);
});

test('Alpine factories expose the stable launcher, mini-card and global preview contract', () => {
    const launcher = railtimeLiveLocationShare({
        chat_id: 17,
        start_url: '/start',
        reply_to_message_id: 44,
        messages: { unavailable: 'Nicht verfuegbar' },
    });
    assert.equal(launcher.open, false);
    assert.equal(launcher.busy, false);
    assert.deepEqual(launcher.durations, [15, 30, 45, 60, 90]);
    launcher.openPicker();
    assert.equal(launcher.open, true);
    launcher.closePicker();
    assert.equal(launcher.open, false);

    const card = railtimeLiveLocationCard({
        share_id: 'card-location',
        chat_id: 17,
        message_id: 55,
        latitude: 51,
        longitude: 9,
        accuracy: 13,
        can_stop: true,
        located_at: '2026-08-03T10:00:00Z',
        messages: {
            live: 'Live',
            paused: 'Pausiert',
            updatedMinutes: 'Vor :minutes Minuten',
        },
    });
    assert.equal(card.shareId, 'card-location');
    assert.equal(card.latitude, 51);
    assert.equal(card.longitude, 9);
    assert.equal(card.canStop, true);
    assert.equal(card.stopping, false);
    card.nowMs = Date.parse('2026-08-03T10:02:00Z');
    assert.equal(card.lastUpdatedLabel, 'Vor 2 Minuten');
    assert.equal(card.isFresh, false);
    assert.equal(card.activityLabel, 'Pausiert');
    card.nowMs = Date.parse('2026-08-03T10:00:30Z');
    assert.equal(card.isFresh, true);
    assert.equal(card.activityLabel, 'Live');

    const windowLike = {
        requestAnimationFrame(callback) { callback(); },
    };
    const preview = railtimeLiveLocationPreview({ windowLike, messages: {} });
    assert.equal(preview.open, false);
    assert.equal(preview.share, null);
    assert.equal(preview.stopping, false);
    assert.equal(typeof preview.close, 'function');
    assert.equal(typeof preview.recenter, 'function');
    assert.equal(typeof preview.stopSharing, 'function');
    preview.openWith(responseShare({ id: 'preview-location' }));
    assert.equal(preview.open, true);
    assert.equal(preview.share.id, 'preview-location');
    assert.equal(preview.senderName, '');
    preview.close();
    assert.equal(preview.open, false);
});

test('the Echo listener forwards only identifiers and status before authorized refresh', async () => {
    const source = await readFile(
        new URL('../../resources/js/app.js', import.meta.url),
        'utf8',
    );
    const start = source.indexOf(".listen('.chat.live-location.changed'");
    const end = source.indexOf(".listen('.chat.read'", start);
    const listener = source.slice(start, end);

    assert.notEqual(start, -1);
    assert.notEqual(end, -1);
    assert.match(listener, /Livewire\.dispatch\('chat:refresh'/);
    assert.match(listener, /liveLocationId/);
    assert.doesNotMatch(listener, /event\.(?:latitude|longitude|position|accuracy)/);
});

test('live-location markup uses full-map overlays, a compact mini-map, and a flush modal body', async () => {
    const [preview, card, sharePicker, stateModal, chatCss] = await Promise.all([
        readFile(new URL(
            '../../resources/views/components/chat/live-location-preview.blade.php',
            import.meta.url,
        ), 'utf8'),
        readFile(new URL(
            '../../resources/views/components/chat/live-location-card.blade.php',
            import.meta.url,
        ), 'utf8'),
        readFile(new URL(
            '../../resources/views/components/chat/live-location-share.blade.php',
            import.meta.url,
        ), 'utf8'),
        readFile(new URL(
            '../../resources/views/components/ui/state-modal.blade.php',
            import.meta.url,
        ), 'utf8'),
        readFile(new URL(
            '../../resources/css/chat-redesign.css',
            import.meta.url,
        ), 'utf8'),
    ]);

    assert.match(preview, /x-show\.important="\$store\.liveLocation\.resumePromptVisible"/);
    assert.match(preview, /data-live-location-resume-banner/);
    assert.match(preview, /dismissResumePrompt\(\)/);
    assert.match(preview, /chat_live_location_resume_dismiss/);
    assert.match(preview, /:layer="230"/);
    assert.match(preview, /body-class="min-h-0 min-w-0 flex-1 overflow-hidden p-0"/);
    assert.match(preview, /data-live-location-map-stage/);
    assert.match(preview, /h-full min-h-0 overflow-hidden/);
    assert.match(preview, /absolute right-3 top-3/);
    assert.doesNotMatch(preview, /min-h-\[24rem\]/);
    assert.match(preview, /data-live-location-map-overlay/);
    assert.match(preview, /x-on:click="recenter\(\)"/);
    assert.match(preview, /x-on:click="stopSharing\(\)"/);
    assert.match(preview, /chat_live_location_last_update/);
    assert.match(preview, /chat_live_location_accuracy/);
    assert.doesNotMatch(preview, /<aside\b/);
    assert.doesNotMatch(preview, /lg:grid-cols/);
    assert.match(card, /data-live-location-mini-map/);
    assert.match(card, /data-live-location-mini-overlay/);
    assert.match(card, /class="rt-live-location-map-button relative isolate h-36/);
    assert.match(card, /closest\('a'\)/);
    assert.equal(card.match(/x-on:click\.stop="openPreview\(\)"/g)?.length, 1);
    assert.doesNotMatch(card, /class="absolute inset-0 z-20 cursor-pointer/);
    assert.match(card, /h-11 w-11/);
    assert.match(card, /x-show\.important="mapError \|\| error"/);
    assert.doesNotMatch(card, /rt-live-location-copy/);
    assert.doesNotMatch(card, /min-w-\[15rem\]/);
    assert.match(sharePicker, /x-show\.important="!\$store\.liveLocation\.supported"/);
    assert.match(sharePicker, /chat_live_location_foreground_hint/);
    assert.match(stateModal, /'bodyClass' => 'min-h-0 min-w-0 flex-1/);
    assert.match(stateModal, /<div class="\{\{ \$bodyClass \}\}">/);
    assert.match(stateModal, /z-index: \{\{ \$resolvedLayer \}\} !important/);
    assert.match(stateModal, /data-rt-overlay-base="\{\{ \$resolvedLayer \}\}"/);
    assert.match(chatCss, /\.rt-live-location-pulse\.is-paused/);
    assert.match(chatCss, /width: min\(18rem, calc\(100vw - 5\.75rem\)\)/);
});

test('live-location runtime never writes coordinates to local or session storage', async () => {
    const source = await readFile(
        new URL('../../resources/js/live-location.js', import.meta.url),
        'utf8',
    );

    assert.doesNotMatch(source, /localStorage|sessionStorage/);
});
