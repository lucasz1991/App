import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const pane = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
const fault = code => Object.assign(new Error(code), { code });

function load(name, context) {
    const start = new RegExp(`^(?:export )?(?:async )?function ${name}\\(`, 'm').exec(pane);
    assert.ok(start, name);
    const tail = pane.slice(start.index).replace(/^export /, '');
    const end = /\r?\n(?:(?:export )?(?:async )?function [A-Za-z_$]|(?:let|const|class) [A-Za-z_$])/.exec(tail);
    return vm.runInContext(`(${end ? tail.slice(0, end.index) : tail}\n)`, context);
}

function fixture({ fetch, authenticate, accept } = {}) {
    const calls = { targets: [], accepted: [], failures: [], status: [], bodyRefreshes: 0, auth: 0, reset: 0 };
    const node = () => ({ dataset: {}, setAttribute() {} });
    const view = { root: node(), login: node(), signature: node(), template: node() };
    const state = { authenticated: true, bootstrapReady: true, busy: false, settingsMode: false };
    const initial = { id: 'initial' };
    const context = vm.createContext({
        Office: { context: { mailbox: { item: initial } } },
        currentConfig: {}, currentBootstrap: { target: initial }, currentTokenResult: null,
        taskpaneState: state, mailboxItemRevision: 0, bootstrapStateRevision: 0,
        signatureStateRevision: 0, observedComposeItem: initial,
        lastBootstrapRefreshAt: 0, silentBootstrapRefreshPromise: null,
        pendingItemBootstrapRevision: null, lastActionError: null,
        SILENT_BOOTSTRAP_REFRESH_INTERVAL_MS: 15000,
        universalStatusByItem: new WeakMap(),
        insertView: { reset() { calls.reset++; } },
        elements: () => view, clearTemplateChoices() {}, clearAccount() {},
        syncActionState() {}, updateDisabledStates() {}, syncAutomaticPreferences() {}, syncAccountOptions() {}, syncHeadProbeButton() {},
        failOpenSignatureCurrentState() {},
        requestSignatureCurrentStateRefresh() { calls.bodyRefreshes++; },
        setStatus(...values) { calls.status.push(values); },
        readyStatusDetail: () => 'synthetic', insertionBlocked: () => false,
        safeErrorCode: error => error.code, codedError: fault,
        authenticationWasLost: error => error.code === 'HTTP_401',
        composeItem: () => context.Office.context.mailbox.item,
        acquireAccessToken: async () => { calls.auth++; return authenticate ? authenticate() : 'synthetic'; },
        loadBootstrap: async (_config, _token, item) => {
            calls.targets.push(item);
            return fetch ? fetch(item) : { target: item };
        },
        acceptBootstrap: async payload => {
            context.bootstrapStateRevision++;
            if (accept) await accept(payload, context);
            calls.accepted.push(payload.target);
            context.currentBootstrap = payload;
            context.lastBootstrapRefreshAt = Date.now();
            state.bootstrapReady = true;
        },
        handleFailure(error) {
            calls.failures.push(error.code);
            context.invalidateBootstrap('error');
            context.setBusy(false);
        },
        console: { warn() {} },
    });
    for (const name of ['captureComposeTarget', 'assertComposeTarget', 'invalidateBootstrap',
        'handleMailboxItemChanged', 'setBusy', 'requestSilentBootstrapRefresh']) context[name] = load(name, context);
    const change = id => {
        const item = { id };
        context.Office.context.mailbox.item = item;
        context.handleMailboxItemChanged();
        return item;
    };
    const settle = async () => {
        for (let attempt = 0; attempt < 20 && context.silentBootstrapRefreshPromise; attempt++) await context.silentBootstrapRefreshPromise;
        assert.equal(context.silentBootstrapRefreshPromise, null);
    };
    return { context, state, calls, change, settle, initial };
}

test('an idle item change refreshes invalidated bootstrap for the captured current item', async () => {
    const f = fixture();
    const current = f.change('next');
    assert.equal(f.state.bootstrapReady, false);
    assert.equal(f.context.currentBootstrap, null);
    await f.settle();
    assert.deepEqual(f.calls.targets, [current]);
    assert.deepEqual(f.calls.accepted, [current]);
    assert.equal(f.state.bootstrapReady, true);
    assert.equal(f.context.pendingItemBootstrapRevision, null);
    assert.equal(f.calls.reset, 1);
});

test('a change during a busy action waits and resumes once when that action settles', async () => {
    const f = fixture();
    f.context.setBusy(true);
    const current = f.change('next');
    assert.deepEqual(f.calls.targets, []);
    assert.equal(f.context.pendingItemBootstrapRevision, 1);
    f.context.setBusy(false);
    await f.settle();
    assert.deepEqual(f.calls.targets, [current]);
    assert.deepEqual(f.calls.accepted, [current]);
    f.context.setBusy(false);
    assert.deepEqual(f.calls.targets, [current]);
});

test('changes during an in-flight request discard the old payload and load only the newest item next', async () => {
    let release;
    const f = fixture({ fetch: item => item.id === 'first' ? new Promise(resolve => { release = () => resolve({ target: item }); }) : { target: item } });
    const first = f.change('first');
    for (let index = 0; index < 10 && !release; index++) await Promise.resolve();
    assert.equal(typeof release, 'function');
    f.change('intermediate');
    const final = f.change('final');
    release();
    await f.settle();
    assert.deepEqual(f.calls.targets, [first, final]);
    assert.deepEqual(f.calls.accepted, [final]);
    assert.equal(f.context.currentBootstrap.target, final);
});

test('an item change during authentication never fetches a payload for the stale target', async () => {
    let release, count = 0;
    const f = fixture({ authenticate: () => ++count === 1 ? new Promise(resolve => { release = () => resolve('synthetic'); }) : 'synthetic' });
    f.change('stale');
    const final = f.change('final');
    release();
    await f.settle();
    assert.deepEqual(f.calls.targets, [final]);
    assert.deepEqual(f.calls.accepted, [final]);
    assert.deepEqual(f.calls.failures, []);
});

test('authentication loss consumes the request without a self-retry loop', async () => {
    const f = fixture({ fetch: () => { throw fault('HTTP_401'); } });
    f.change('next');
    await f.settle();
    assert.equal(f.calls.targets.length, 1);
    assert.equal(f.state.authenticated, false);
    assert.equal(f.state.bootstrapReady, false);
    assert.equal(f.context.pendingItemBootstrapRevision, null);
    assert.deepEqual(f.calls.accepted, []);
    assert.match(f.calls.status.at(-1)[1], /Verbindung erneuern/);
    f.context.requestSilentBootstrapRefresh();
    assert.equal(f.calls.targets.length, 1);
});

test('a server failure after item change reports a failure rather than leaving a permanent loading state', async () => {
    const f = fixture({ fetch: () => { throw fault('HTTP_503'); } });
    f.change('next');
    await f.settle();
    assert.deepEqual(f.calls.failures, ['HTTP_503']);
    assert.equal(f.calls.targets.length, 1);
    assert.equal(f.state.bootstrapReady, false);
    assert.equal(f.context.pendingItemBootstrapRevision, null);
    f.context.setBusy(false);
    assert.equal(f.calls.targets.length, 1);
});

test('a recovered item status is not overwritten by a generic refresh success', async () => {
    const f = fixture();
    const current = f.change('next');
    f.context.universalStatusByItem.set(current, { code: 'U2E_INSERT_UNCERTAIN' });
    await f.settle();
    assert.equal(f.calls.status.some(([, title]) => title === 'Mit RailTime verbunden'), false);
});

test('a bundle rejection inside acceptBootstrap is surfaced despite its own revision increment', async () => {
    const f = fixture({ accept: () => { throw fault('U2E_BUNDLE_STALE'); } });
    f.change('next');
    await f.settle();
    assert.deepEqual(f.calls.failures, ['U2E_BUNDLE_STALE']);
    assert.deepEqual(f.calls.accepted, []);
    assert.equal(f.calls.targets.length, 1);
    assert.equal(f.state.bootstrapReady, false);
    assert.equal(f.context.pendingItemBootstrapRevision, null);
});

test('a rejected old acceptance cannot replace the newer item status or trigger its failure handler', async () => {
    let reject;
    const f = fixture({ accept: payload => payload.target.id === 'old' ? new Promise((_resolve, fail) => { reject = fail; }) : undefined });
    f.change('old');
    for (let index = 0; index < 10 && !reject; index++) await Promise.resolve();
    assert.equal(typeof reject, 'function');
    const current = f.change('current');
    reject(fault('U2E_BUNDLE_STALE'));
    await f.settle();
    assert.deepEqual(f.calls.failures, []);
    assert.deepEqual(f.calls.accepted, [current]);
    assert.equal(f.context.currentBootstrap.target, current);
});
