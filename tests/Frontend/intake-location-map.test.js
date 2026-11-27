import test from 'node:test';
import assert from 'node:assert/strict';
import { intakeLocationMap, registerIntakeLocationMap } from '../../resources/js/intake-location-map.js';

function fixture({ point = { latitude: 53.5, longitude: 10, zoom: 14 }, deferred = false, observer = true, reject = false } = {}) {
    const calls = [];
    const invalidations = [];
    const tileEvents = {};
    let observerCallback;
    let disconnects = 0;
    let destroyed = 0;
    let settle;
    const map = {
        map: { eachLayer(callback) { callback({ getTileUrl() {}, on(type, handler) { tileEvents[type] = handler; } }); } },
        invalidate(options) { invalidations.push(options); },
        destroy() { destroyed += 1; },
    };
    const windowLike = observer ? {
        IntersectionObserver: class {
            constructor(callback) { observerCallback = callback; }
            observe() {}
            disconnect() { disconnects += 1; }
        },
    } : {};
    const state = intakeLocationMap(point, { windowLike, createMap(options) {
        calls.push(options);
        if (reject) return Promise.reject(new Error('unavailable'));
        return deferred ? new Promise((resolve) => { settle = () => resolve(map); }) : Promise.resolve(map);
    } });
    state.$el = { isConnected: true };
    state.$refs = { canvas: { isConnected: true } };
    return { state, calls, invalidations, tileEvents, show: () => observerCallback?.([{ isIntersecting: true }]),
        hide: () => observerCallback?.([{ isIntersecting: false }]), settle: () => settle(),
        get destroyed() { return destroyed; }, get disconnects() { return disconnects; } };
}

const flush = async () => { await Promise.resolve(); await Promise.resolve(); };

test('lazy preview loads only when visible, once, and reflows after a tab returns', async () => {
    const f = fixture();
    f.state.init();
    assert.equal(f.calls.length, 0);
    f.hide();
    assert.equal(f.calls.length, 0);
    f.show();
    await flush();
    assert.equal(f.calls.length, 1);
    assert.equal(f.state.status, 'ready');
    assert.equal(f.calls[0].interactive, true);
    assert.equal(f.calls[0].zoom, 14);
    f.hide(); f.show();
    assert.equal(f.calls.length, 1);
    assert.equal(f.invalidations.length, 2);
    f.state.destroy();
    assert.equal(f.destroyed, 1);
    assert.equal(f.disconnects, 1);
});

test('repeated visibility cannot duplicate a pending map and late completion cleans up after morph', async () => {
    const f = fixture({ deferred: true });
    f.state.init();
    f.show(); f.show();
    assert.equal(f.calls.length, 1);
    f.state.destroy();
    f.settle();
    await flush();
    assert.equal(f.destroyed, 1);
    assert.equal(f.invalidations.length, 0);
    f.show();
    assert.equal(f.calls.length, 1);
});

test('detached element does not start a map and detachment during loading destroys it', async () => {
    const first = fixture();
    first.state.$refs.canvas.isConnected = false;
    first.state.init(); first.show();
    assert.equal(first.calls.length, 0);
    const second = fixture({ deferred: true });
    second.state.init(); second.show();
    second.state.$refs.canvas.isConnected = false;
    second.settle();
    await flush();
    assert.equal(second.destroyed, 1);
    assert.equal(second.invalidations.length, 0);
});

test('invalid or missing coordinates never create a map', async () => {
    for (const point of [null, {}, { latitude: null, longitude: 10 }, { latitude: 91, longitude: 10 }, { latitude: 53, longitude: 181 }, { latitude: 'NaN', longitude: 10 }]) {
        const f = fixture({ point, observer: false });
        f.state.init();
        await f.state.load();
        assert.equal(f.calls.length, 0);
        assert.equal(f.state.status, 'error');
    }
});

test('safe fallback without IntersectionObserver enables zoom controls and bounds zoom', async () => {
    const f = fixture({ observer: false, point: { latitude: 53.5, longitude: 10, zoom: 99 } });
    f.state.init();
    await flush();
    assert.equal(f.state.status, 'ready');
    assert.equal(f.calls[0].zoom, 11);
    assert.equal(f.calls[0].interactive, true);
});

test('loading or tile failure produces honest state and late events cannot revive destroyed map', async () => {
    const failed = fixture({ reject: true });
    failed.state.init(); failed.show();
    await flush();
    assert.equal(failed.state.status, 'error');
    const f = fixture();
    f.state.init(); f.show();
    await flush();
    f.tileEvents.tileerror();
    assert.equal(f.state.status, 'error');
    f.state.destroy();
    f.state.status = 'closed';
    f.tileEvents.tileerror();
    assert.equal(f.state.status, 'closed');
});

test('Alpine registration is scoped to the intake preview', () => {
    const registrations = [];
    registerIntakeLocationMap({ data(name, factory) { registrations.push([name, factory]); } });
    assert.equal(registrations.length, 1);
    assert.equal(registrations[0][0], 'rtIntakeLocationMap');
    assert.equal(typeof registrations[0][1]({}).destroy, 'function');
});
