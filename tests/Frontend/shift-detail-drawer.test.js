import test from 'node:test';
import assert from 'node:assert/strict';
import { shiftDetailDrawer } from '../../resources/js/shift-detail-drawer.js';

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

function harness(initialDetailOpen = false, withHooks = false) {
    const state = shiftDetailDrawer();
    const requests = [];
    const watchers = new Map();
    const requestWaiters = [];
    const hooks = new Map();
    const hookCleanups = [];
    state.$wire = {
        $id: 'shift-manager',
        detailOpen: initialDetailOpen,
        openDetails(id) {
            const request = { id, ...deferred() };
            requests.push(request);
            for (const waiter of requestWaiters) {
                if (requests.length >= waiter.count) waiter.resolve();
            }
            return request.promise;
        },
    };
    if (withHooks) {
        state.$wire.$hook = (name, callback) => {
            if (!hooks.has(name)) hooks.set(name, new Set());
            hooks.get(name).add(callback);
            return () => {
                hooks.get(name).delete(callback);
                hookCleanups.push(name);
            };
        };
    }
    state.$nextTick = callback => callback();
    state.$watch = (property, callback) => watchers.set(property, callback);
    state.init?.();
    return {
        state, requests, watchers, hooks, hookCleanups,
        waitForRequests(count) {
            if (requests.length >= count) return Promise.resolve();
            return new Promise(resolve => requestWaiters.push({ count, resolve }));
        },
        commitFailure(id, method = 'openDetails') {
            const failures = [];
            for (const hook of hooks.get('commit') ?? []) {
                hook({ commit: { calls: [{ method, params: [id] }] }, fail: callback => failures.push(callback) });
            }
            for (const failure of failures) failure();
            return failures.length;
        },
        requestFailure(status, components = [{ id: 'shift-manager', methods: ['openDetails'] }]) {
            const failures = [];
            const payload = JSON.stringify({ components: components.map(({ id, methods, shiftId = state.requestedShiftId }) => ({
                snapshot: JSON.stringify({ memo: { id } }),
                calls: methods.map(method => ({ method, params: [shiftId] })),
            })) });
            for (const hook of hooks.get('request') ?? []) {
                hook({ payload, fail: callback => failures.push(callback) });
            }
            let prevented = false;
            for (const failure of failures) failure({ status, preventDefault() { prevented = true; } });
            return prevented;
        },
    };
}

test('opens the local panel and loading state before the detail request completes', async () => {
    const { state, requests } = harness();
    const work = state.openShiftDetail(41);

    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, true);
    assert.equal(state.error, '');
    assert.equal(state.requestedShiftId, 41);
    assert.deepEqual(requests.map(request => request.id), [41]);

    requests[0].resolve();
    await work;
    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
});

test('failed request leaves the drawer open with a useful local error and stops the loader', async () => {
    const { state, requests } = harness();
    const work = state.openShiftDetail(42);
    requests[0].reject(new Error('Network unavailable'));
    await work;

    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, false);
    assert.ok(state.error.length > 0);
    assert.equal(state.requestedShiftId, 42);
});

test('closing while details load keeps the drawer closed when the late request returns', async () => {
    const { state, requests } = harness();
    const work = state.openShiftDetail(43);
    state.closeShiftDetail();
    assert.equal(state.detailVisible, false);

    state.$wire.detailOpen = true; // Livewire morph from the outstanding successful request.
    requests[0].resolve();
    await work;
    assert.equal(state.detailVisible, false);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
    assert.equal(state.$wire.detailOpen, false);
});

test('rapid selections serialize requests and keep the loader until the newest details arrive', async () => {
    const { state, requests, waitForRequests } = harness();
    const first = state.openShiftDetail(51);
    const second = state.openShiftDetail(52);
    assert.deepEqual(requests.map(request => request.id), [51]);
    assert.equal(state.requestedShiftId, 52);
    assert.equal(state.loading, true);

    requests[0].resolve();
    await waitForRequests(2);
    assert.deepEqual(requests.map(request => request.id), [51, 52]);
    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, true);
    assert.equal(state.requestedShiftId, 52);

    requests[1].resolve();
    await Promise.all([first, second]);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
    assert.equal(state.requestedShiftId, 52);
});

test('a superseded failure does not replace the newest selection with an error', async () => {
    const { state, requests, waitForRequests } = harness();
    const first = state.openShiftDetail(61);
    const second = state.openShiftDetail(62);
    requests[0].reject(new Error('Old request failed'));
    await waitForRequests(2);

    assert.equal(state.error, '');
    assert.equal(state.loading, true);
    assert.deepEqual(requests.map(request => request.id), [61, 62]);

    requests[1].resolve();
    await Promise.all([first, second]);
    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
});

test('close and reopen during an outstanding request displays only the new selection', async () => {
    const { state, requests, waitForRequests } = harness();
    const first = state.openShiftDetail(71);
    state.closeShiftDetail();
    const second = state.openShiftDetail(72);
    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, true);
    assert.equal(state.requestedShiftId, 72);
    assert.deepEqual(requests.map(request => request.id), [71]);

    requests[0].resolve();
    await waitForRequests(2);
    assert.equal(state.loading, true);
    assert.equal(state.requestedShiftId, 72);
    assert.deepEqual(requests.map(request => request.id), [71, 72]);

    requests[1].resolve();
    await Promise.all([first, second]);
    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, false);
    assert.equal(state.requestedShiftId, 72);
});

test('three rapid selections coalesce to the latest queued shift without parallel requests', async () => {
    const { state, requests, waitForRequests } = harness();
    const first = state.openShiftDetail(81);
    const second = state.openShiftDetail(82);
    const last = state.openShiftDetail(83);
    assert.deepEqual(requests.map(request => request.id), [81]);

    requests[0].resolve();
    await waitForRequests(2);
    assert.deepEqual(requests.map(request => request.id), [81, 83]);
    assert.equal(state.loading, true);
    requests[1].resolve();
    await Promise.all([first, second, last]);
    assert.equal(state.loading, false);
    assert.equal(state.requestedShiftId, 83);
});

test('an initial deep link is visible without duplicating its server-side detail request', () => {
    const { state, requests } = harness(true);
    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, false);
    assert.deepEqual(requests, []);
});

test('server-side edit or create action closes details through the watched state', () => {
    const { state, requests, watchers } = harness(true);
    state.$wire.detailOpen = false;
    watchers.get('$wire.detailOpen')(false);
    assert.equal(state.detailVisible, false);
    assert.equal(state.loading, false);
    assert.deepEqual(requests, []);
});

test('invalid ids never open the panel or reach the Livewire action', () => {
    const { state, requests } = harness();
    for (const id of [null, '', 'not-an-id', 0, -1, 1.5, Infinity, Number.MAX_SAFE_INTEGER + 1]) {
        state.openShiftDetail(id);
    }
    assert.equal(state.detailVisible, false);
    assert.equal(state.loading, false);
    assert.equal(state.requestedShiftId, null);
    assert.deepEqual(requests, []);
});

test('commit failure stops loading even when Livewire leaves the method promise pending, and removes both hooks', async () => {
    const { state, hooks, hookCleanups, commitFailure } = harness(false, true);
    const work = state.openShiftDetail(91);
    assert.equal(hooks.get('commit').size, 1);
    assert.equal(hooks.get('request').size, 1);
    assert.equal(commitFailure(91), 1);
    await work;

    assert.equal(state.detailVisible, true);
    assert.equal(state.loading, false);
    assert.equal(state.inFlight, false);
    assert.ok(state.error.length > 0);
    assert.equal(hooks.get('commit').size, 0);
    assert.equal(hooks.get('request').size, 0);
    assert.deepEqual(hookCleanups, ['commit', 'request']);
});

test('successful detail loading removes temporary request and commit hooks', async () => {
    const { state, requests, hooks, hookCleanups } = harness(false, true);
    const work = state.openShiftDetail(92);
    requests[0].resolve();
    await work;

    assert.equal(state.loading, false);
    assert.equal(hooks.get('commit').size, 0);
    assert.equal(hooks.get('request').size, 0);
    assert.deepEqual(hookCleanups, ['commit', 'request']);
});

test('unrelated methods or shift ids do not reject an outstanding detail request', async () => {
    const { state, requests, commitFailure } = harness(false, true);
    const work = state.openShiftDetail(93);
    assert.equal(commitFailure(999), 0);
    assert.equal(commitFailure(93, 'setView'), 0);
    assert.equal(state.loading, true);
    assert.equal(state.error, '');

    requests[0].resolve();
    await work;
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
});

test('dedicated detail failures use the local error UI while expired sessions and unrelated requests retain Livewire handling', async () => {
    const { state, requests, requestFailure } = harness(false, true);
    const work = state.openShiftDetail(94);
    assert.equal(requestFailure(500), true);
    assert.equal(requestFailure(0), true);
    assert.equal(requestFailure(419), false);
    assert.equal(requestFailure(500, [{ id: 'other-component', methods: ['openDetails'] }]), false);
    assert.equal(requestFailure(500, [{ id: 'shift-manager', methods: ['setView'] }]), false);
    assert.equal(requestFailure(500, [{ id: 'shift-manager', methods: [] }]), false);
    assert.equal(requestFailure(500, [{ id: 'shift-manager', methods: ['openDetails'], shiftId: 999 }]), false);
    assert.equal(requestFailure(500, [{ id: 'shift-manager', methods: ['openDetails', 'setView'] }]), false);
    assert.equal(requestFailure(500, [
        { id: 'shift-manager', methods: ['openDetails'] },
        { id: 'other-component', methods: ['search'] },
    ]), false);

    requests[0].resolve();
    await work;
});

test('a failed outstanding commit after closing cannot reopen the panel and still removes its hooks', async () => {
    const { state, hooks, hookCleanups, commitFailure } = harness(false, true);
    const work = state.openShiftDetail(95);
    state.closeShiftDetail();
    commitFailure(95);
    await work;

    assert.equal(state.detailVisible, false);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
    assert.equal(hooks.get('commit').size, 0);
    assert.equal(hooks.get('request').size, 0);
    assert.deepEqual(hookCleanups, ['commit', 'request']);
});

test('a failed commit advances the latest queued selection without showing its stale error or leaking hooks', async () => {
    const { state, requests, waitForRequests, hooks, hookCleanups, commitFailure } = harness(false, true);
    const first = state.openShiftDetail(96);
    const second = state.openShiftDetail(97);
    commitFailure(96);
    await waitForRequests(2);

    assert.deepEqual(requests.map(request => request.id), [96, 97]);
    assert.equal(state.requestedShiftId, 97);
    assert.equal(state.loading, true);
    assert.equal(state.error, '');
    assert.deepEqual(hookCleanups, ['commit', 'request']);
    assert.equal(hooks.get('commit').size, 1);
    assert.equal(hooks.get('request').size, 1);

    requests[1].resolve();
    await Promise.all([first, second]);
    assert.equal(state.loading, false);
    assert.equal(state.error, '');
    assert.equal(hooks.get('commit').size, 0);
    assert.equal(hooks.get('request').size, 0);
    assert.deepEqual(hookCleanups, ['commit', 'request', 'commit', 'request']);
});
