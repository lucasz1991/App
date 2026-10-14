import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { registerRoutingMap } from '../../resources/js/routing-map.js';

function leafletFixture() {
    const seen = { urls: [], maps: [], paths: [], layers: [] };
    const leaflet = {
        map: (_, options) => {
            const map = { fitBounds: (...args) => { map.bounds = args; }, invalidateSize: () => { map.resizes = (map.resizes || 0) + 1; }, remove: () => { map.removed = true; } };
            seen.maps.push(map);
            seen.mapOptions = options;
            return map;
        },
        tileLayer: (url, options) => {
            seen.urls.push(url);
            const layer = { on: (_, handler) => { layer.tileError = handler; return layer; }, addTo: (map) => { layer.map = map; return layer; } };
            seen.layers.push(layer);
            seen.tileOptions = options;
            return layer;
        },
        polyline: (path) => { seen.paths.push(path); return { addTo: () => ({ getBounds: () => path }) }; },
        circleMarker: () => ({ addTo: () => {} }),
    };
    return { seen, leaflet };
}

function factoryFor(loader) {
    let factory;
    registerRoutingMap({ data: (name, value) => { if (name === 'rtRoutingMap') factory = value; } }, loader);
    return factory;
}

function stateFor(factory) {
    const state = factory([[52.5, 13.4], [52.6, 13.5]], '/operations/routing/tiles/__z__/__x__/__y__');
    state.$el = { isConnected: true };
    return state;
}

test('route uses direct counted same-origin tiles and provider coordinate pairs; teardown removes map', async () => {
    const f = leafletFixture();
    const previous = globalThis.ResizeObserver;
    let disconnected = false;
    let resize;
    globalThis.ResizeObserver = class { constructor(callback) { resize = callback; } observe() {} disconnect() { disconnected = true; } };
    try {
        const state = stateFor(factoryFor(async () => f.leaflet));
        await state.init();
        assert.deepEqual(f.seen.urls, ['/operations/routing/tiles/{z}/{x}/{y}']);
        assert.deepEqual(f.seen.paths, [[[52.5, 13.4], [52.6, 13.5]]]);
        assert.equal(f.seen.tileOptions.attribution, '&copy; TomTom');
        assert.equal(f.seen.mapOptions.scrollWheelZoom, false);
        resize();
        assert.equal(state.map.resizes, 1);
        f.seen.layers[0].tileError();
        assert.match(state.errorText, /Kartenhintergrund derzeit nicht verfügbar/);
        assert.equal(state.map, f.seen.maps[0]);
        state.destroy();
        assert.equal(f.seen.maps[0].removed, true);
        assert.equal(disconnected, true);
        assert.equal(state.map, null);
        assert.equal(state.tiles, null);
        assert.equal(state.observer, null);
        resize();
        state.errorText = '';
        f.seen.layers[0].tileError();
        assert.equal(state.errorText, '', 'late tile errors cannot update a removed map');
    } finally { globalThis.ResizeObserver = previous; }
});

test('Leaflet load failure produces an accessible status without creating a map', async () => {
    const state = stateFor(factoryFor(async () => { throw new Error('network unavailable'); }));
    await state.init();
    assert.equal(state.map, null);
    assert.match(state.errorText, /Karte konnte nicht geladen werden/);
});

test('route removed while Leaflet loads cannot create a detached map', async () => {
    let resolve;
    let calls = 0;
    const state = stateFor(factoryFor(() => new Promise((done) => { resolve = done; })));
    const loading = state.init();
    state.destroy();
    resolve({ map: () => { calls += 1; } });
    await loading;
    assert.equal(calls, 0);
});

test('a disconnected element cannot mount after the asynchronous library load', async () => {
    let resolve;
    const f = leafletFixture();
    const state = stateFor(factoryFor(() => new Promise((done) => { resolve = done; })));
    const loading = state.init();
    state.$el.isConnected = false;
    resolve(f.leaflet);
    await loading;
    assert.equal(f.seen.maps.length, 0);
});

test('new route instances request their server tile layer directly without a shared client cache', async () => {
    const f = leafletFixture();
    const factory = factoryFor(async () => f.leaflet);
    for (let i = 0; i < 2; i += 1) {
        const state = stateFor(factory);
        await state.init();
        state.destroy();
    }
    assert.deepEqual(f.seen.urls, ['/operations/routing/tiles/{z}/{x}/{y}', '/operations/routing/tiles/{z}/{x}/{y}']);
    assert.equal(f.seen.maps.length, 2);
});

test('map registration on a settings-only page has no fetches or browser lifecycle listeners', () => {
    let loads = 0;
    const factory = factoryFor(() => { loads += 1; });
    assert.equal(typeof factory, 'function');
    assert.equal(loads, 0);
});

test('route display remains usable when ResizeObserver is unavailable', async () => {
    const previous = globalThis.ResizeObserver;
    globalThis.ResizeObserver = undefined;
    try {
        const f = leafletFixture();
        const state = stateFor(factoryFor(async () => f.leaflet));
        await state.init();
        assert.equal(state.map, f.seen.maps[0]);
        assert.equal(state.observer, null);
        state.destroy();
        assert.equal(f.seen.maps[0].removed, true);
    } finally { globalThis.ResizeObserver = previous; }
});

test('routing client contains no browser caching, blob URLs or scope-query hooks', async () => {
    const source = await readFile(new URL('../../resources/js/routing-map.js', import.meta.url), 'utf8');
    assert.doesNotMatch(source, /routing-tile-cache|createObjectURL|revokeObjectURL|cache_scope|BroadcastChannel|localStorage|sessionStorage|indexedDB|caches\.|cacheFactory/);
    const blade = (await Promise.all([
        '../../resources/views/livewire/operations/shift-travel-panel.blade.php',
        '../../resources/views/livewire/operations/partials/travel-route-preview.blade.php',
    ].map((path) => readFile(new URL(path, import.meta.url), 'utf8')))).join('\n');
    assert.doesNotMatch(blade, /RoutingTileContext|cache_scope|tileCache/);
    assert.match(blade, /role="region"/);
    assert.match(blade, /role="status"/);
});

function previewFixture() {
    let factory;
    registerRoutingMap({ data: (name, value) => { if (name === 'rtTravelPreview') factory = value; } });
    const requests = [];
    const state = factory({ showRoute(employeeId, direction) {
        let resolve;
        let reject;
        const promise = new Promise((done, fail) => { resolve = done; reject = fail; });
        requests.push({ employeeId, direction, resolve, reject });
        return promise;
    } });
    return { state, requests };
}

test('route preview is lazy, deduplicates the active popup and revalidates each reopening', async () => {
    const { state, requests } = previewFixture();
    assert.equal(requests.length, 0);
    const first = state.requestRoute(2, 'before');
    state.requestRoute(2, 'before');
    assert.equal(state.activeKey, '2:before');
    assert.equal(state.loading, true);
    assert.equal(requests.length, 1);
    requests[0].resolve();
    await first;
    assert.equal(state.loading, false);
    state.requestRoute(2, 'before');
    assert.equal(requests.length, 1);
    state.dismissRoute('2:before');
    const second = state.requestRoute(2, 'before');
    assert.equal(requests.length, 2, 'reopening calls the server instead of trusting previous geometry');
    requests[1].resolve();
    await second;
});

test('rapid route switches serialize requests and retain only the latest queued selection', async () => {
    const { state, requests } = previewFixture();
    const finished = state.requestRoute(2, 'before');
    state.requestRoute(2, 'after');
    state.requestRoute(3, 'before');
    state.dismissRoute('2:before');
    assert.equal(requests.length, 1);
    assert.equal(state.activeKey, '3:before', 'late old close cannot dismiss the new route');
    requests[0].resolve();
    await Promise.resolve();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests.map(({ employeeId, direction }) => [employeeId, direction]), [[2, 'before'], [3, 'before']]);
    assert.equal(state.loading, true, 'old geometry stays hidden while the selected route is pending');
    requests[1].resolve();
    await finished;
    assert.equal(state.loading, false);
    assert.equal(state.activeKey, '3:before');
});

test('closing during a request discards the queued route and ignores the late result', async () => {
    const { state, requests } = previewFixture();
    const finished = state.requestRoute(2, 'before');
    state.requestRoute(3, 'arrival');
    state.dismissRoute('3:arrival');
    requests[0].reject(new Error('provider details must not be exposed'));
    await finished;
    assert.equal(requests.length, 1);
    assert.equal(state.activeKey, '');
    assert.equal(state.loading, false);
    assert.equal(state.errorText, '');
});

test('closing and reopening the same route does not accept the previous in-flight response', async () => {
    const { state, requests } = previewFixture();
    const finished = state.requestRoute(2, 'before');
    state.dismissRoute('2:before');
    state.requestRoute(2, 'before');
    requests[0].resolve();
    await Promise.resolve();
    assert.equal(state.loading, true);
    assert.equal(requests.length, 2);
    requests[1].resolve();
    await finished;
    assert.equal(state.loading, false);
});

test('failed route request exposes only a short status and reopening can recover', async () => {
    const { state, requests } = previewFixture();
    const failed = state.requestRoute(2, 'before');
    requests[0].reject(new Error('secret provider URL and payload'));
    await failed;
    assert.equal(state.errorText, 'Route nicht verfügbar');
    assert.equal(state.loading, false);
    state.dismissRoute('2:before');
    const retry = state.requestRoute(2, 'before');
    assert.equal(state.errorText, '');
    requests[1].resolve();
    await retry;
    assert.equal(state.loading, false);
    assert.equal(state.errorText, '');
});

test('destroy prevents late completion, queued calls and further preview requests', async () => {
    const { state, requests } = previewFixture();
    const finished = state.requestRoute(2, 'before');
    state.requestRoute(3, 'after');
    state.destroy();
    const destroyedState = { ...state };
    requests[0].reject(new Error('late failure'));
    await finished;
    state.requestRoute(4, 'arrival');
    state.dismissRoute('2:before');
    assert.equal(requests.length, 1);
    assert.deepEqual(state, destroyedState);
});

test('preview rejects invalid IDs and directions before any server call', () => {
    const { state, requests } = previewFixture();
    for (const id of [null, undefined, 0, -1, 2.5, 'invalid', Infinity]) state.requestRoute(id, 'before');
    state.requestRoute(2, 'elsewhere');
    assert.equal(requests.length, 0);
    assert.equal(state.activeKey, '');
});
