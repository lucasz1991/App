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
    registerRoutingMap({ data: (name, value) => { assert.equal(name, 'rtRoutingMap'); factory = value; } }, loader);
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
    const blade = await readFile(new URL('../../resources/views/livewire/operations/shift-travel-panel.blade.php', import.meta.url), 'utf8');
    assert.doesNotMatch(blade, /RoutingTileContext|cache_scope|tileCache/);
    assert.match(blade, /Server-Cache/);
    assert.match(blade, /Vor einer verbindlichen Zuteilung wird die Fahrt frisch geprüft/);
    assert.match(blade, /role="region"/);
    assert.match(blade, /role="status"/);
});
