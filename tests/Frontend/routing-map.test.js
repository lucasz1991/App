import test from 'node:test';
import assert from 'node:assert/strict';
import { registerRoutingMap } from '../../resources/js/routing-map.js';

test('route uses counted same-origin tiles and provider coordinate pairs; teardown removes map', async () => {
    let factory;
    const seen = {};
    const fakeMap = { fitBounds: () => {}, invalidateSize: () => {}, remove: () => { seen.removed = true; } };
    const fakeLeaflet = {
        map: () => fakeMap,
        tileLayer: (url) => { seen.url = url; return { on: (_, handler) => { seen.tileError = handler; return { addTo: () => {} }; } }; },
        polyline: (path) => { seen.path = path; return { addTo: () => ({ getBounds: () => [] }) }; },
        circleMarker: () => ({ addTo: () => {} }),
    };
    const previous = globalThis.ResizeObserver;
    globalThis.ResizeObserver = class { observe() {} disconnect() { seen.disconnected = true; } };
    try {
        registerRoutingMap({ data: (_, value) => { factory = value; } }, async () => fakeLeaflet);
        const state = factory([[52.5, 13.4], [52.6, 13.5]], '/operations/routing/tiles/__z__/__x__/__y__');
        state.$el = { isConnected: true };
        await state.init();
        assert.equal(seen.url, '/operations/routing/tiles/{z}/{x}/{y}');
        assert.deepEqual(seen.path, [[52.5, 13.4], [52.6, 13.5]]);
        seen.tileError();
        assert.match(state.errorText, /Kartenhintergrund derzeit nicht verfügbar/);
        assert.equal(state.map, fakeMap);
        state.destroy();
        assert.equal(seen.removed, true);
        assert.equal(seen.disconnected, true);
    } finally { globalThis.ResizeObserver = previous; }
});

test('Leaflet load failure produces an accessible status without creating a map', async () => {
    let factory;
    registerRoutingMap({ data: (_, value) => { factory = value; } }, async () => { throw new Error('network unavailable'); });
    const state = factory([[52.5, 13.4], [52.6, 13.5]], '/tiles/__z__/__x__/__y__');
    state.$el = { isConnected: true };
    await state.init();
    assert.equal(state.map, null);
    assert.match(state.errorText, /Karte konnte nicht geladen werden/);
});

test('route removed while Leaflet loads cannot create a detached map', async () => {
    let factory;
    let resolve;
    let calls = 0;
    registerRoutingMap({ data: (_, value) => { factory = value; } }, () => new Promise((done) => { resolve = done; }));
    const state = factory([[52.5, 13.4], [52.6, 13.5]], '/tiles/__z__/__x__/__y__');
    state.$el = { isConnected: true };
    const loading = state.init();
    state.destroy();
    resolve({ map: () => { calls += 1; } });
    await loading;
    assert.equal(calls, 0);
});
