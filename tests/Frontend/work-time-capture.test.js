import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { CaptureStore, encryptCapture, decryptCapture, projectCapture, workTimeCapture } from '../../resources/js/work-time-capture.js';

test('AES-GCM encrypts minimal event data and rejects changed ciphertext', async () => {
    const key = await webcrypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    const payload = { action: 'start', title: 'Private Tätigkeit', sequence: 1 };
    const row = await encryptCapture(key, payload, webcrypto);
    assert.equal(JSON.stringify(row).includes(payload.title), false);
    assert.deepEqual(await decryptCapture(key, row, webcrypto), payload);
    row.ciphertext[0] ^= 1;
    await assert.rejects(() => decryptCapture(key, row, webcrypto));
});

test('each encryption has a new nonce', async () => {
    const key = await webcrypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    const first = await encryptCapture(key, {}, webcrypto);
    const second = await encryptCapture(key, {}, webcrypto);
    assert.notDeepEqual(first.iv, second.iv);
});

test('offline projection follows one clock and revision sequence', () => {
    let state = projectCapture(null, { action: 'start', entry_capture_id: 'one', title: 'Dienst', occurred_at: '2027-01-01T08:00:00Z', timezone: 'Europe/Berlin' });
    assert.equal(state.revision, 1);
    state = projectCapture(state, { action: 'pause', entry_capture_id: 'one', revision: 1 });
    assert.equal(state.status, 'paused');
    assert.equal(state.revision, 2);
    assert.deepEqual(projectCapture(state, { action: 'stop', entry_capture_id: 'other', revision: 2 }), state);
    state = projectCapture(state, { action: 'stop', entry_capture_id: 'one', revision: 2, occurred_at: '2027-01-01T08:30:00Z' });
    assert.equal(state.status, 'completed');
    assert.equal(state.revision, 3);
});

test('offline state never persists a key and observers/handlers are removed', async () => {
    const source = await readFile(new URL('../../resources/js/work-time-capture.js', import.meta.url), 'utf8');
    assert.equal(/localStorage\.setItem\([^\n]*(key|encryption)/.test(source), false);
    assert.match(source, /importKey\('raw', raw, \{ name: 'AES-GCM' \}, false/);
    assert.match(source, /window\.removeEventListener\('online'/);
    assert.match(source, /window\.removeEventListener\('offline'/);
    assert.match(source, /window\.removeEventListener\('pagehide'/);
    assert.equal(/touchmove|ResizeObserver|MutationObserver/.test(source), false);
});

test('client clock controls use boolean disabled bindings and survive Livewire refresh', async () => {
    const view = await readFile(new URL('../../resources/views/operations/partials/work-clock.blade.php', import.meta.url), 'utf8');
    assert.match(view, /data-worktime-capture wire:ignore/);
    assert.equal(view.includes('x-bind:disabled="busy || !ready || conflicts"'), false);
    assert.match(view, /x-bind:disabled="busy \|\| !ready \|\| conflicts > 0"/);
    assert.match(view, /:show-offline="false"/);
    assert.equal(view.includes('active.status ==='), false);
    assert.match(view, /x-show\.important="active\?\.status === 'paused'"/);
});

test('two tabs retain device source instants without request-specific server clock offsets', async () => {
    const originalNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const originalNow = Date.now;
    let instant = Date.parse('2026-10-04T12:00:00Z');
    Object.defineProperty(globalThis, 'navigator', {configurable:true,value:{onLine:false,locks:{request:async (_name,callback)=>callback()}}});
    Date.now = () => instant;
    try {
        const key = await webcrypto.subtle.generateKey({name:'AES-GCM',length:256},false,['encrypt','decrypt']);
        const rows = [];
        const store = {state:async()=>null,rows:async()=>rows,sequence:async()=>rows.at(-1)?.sequence||0,append:async row=>rows.push(row)};
        const options = {actorId:1,timezone:'Europe/Berlin'};
        const first = Object.assign(workTimeCapture(options),{ready:true,key,store,scope:'shared',serverOffset:500});
        const second = Object.assign(workTimeCapture(options),{ready:true,key,store,scope:'shared',serverOffset:-900});
        await first.capture({action:'start',work_context:'internal',title:'Source clock'});
        instant += 100;
        await second.capture({action:'pause'});
        const events = await Promise.all(rows.map(row=>decryptCapture(key,row,webcrypto)));
        assert.equal(events[0].occurred_at,'2026-10-04T12:00:00.000Z');
        assert.equal(events[1].occurred_at,'2026-10-04T12:00:00.100Z');
        assert.equal(events[1].revision,1);
        assert.equal(events[1].sequence,2);
    } finally {
        Date.now=originalNow;
        if(originalNavigator) Object.defineProperty(globalThis,'navigator',originalNavigator);
        else delete globalThis.navigator;
    }
});

async function captureRuntime(t, { realSync = false } = {}) {
    const globals = ['navigator', 'window', 'document', 'localStorage', 'indexedDB', 'BroadcastChannel', 'fetch', 'CustomEvent'];
    const descriptors = new Map(globals.map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
    const originalOpen = CaptureStore.open;
    const windowLike = new EventTarget();
    let reloads = 0;
    windowLike.location = { reload: () => reloads++ };
    const documentLike = new EventTarget();
    documentLike.visibilityState = 'visible';
    const metas = { 'csrf-token': { content: 'fresh-csrf' }, 'rt-push-account-binding': { content: 'account-one' } };
    documentLike.querySelector = selector => metas[/meta\[name="([^"]+)"\]/.exec(selector)?.[1]] || null;
    const storage = new Map();
    const locks = new Map();
    const browserNavigator = { onLine: true, locks: { request: (name, callback) => {
        const next = (locks.get(name) || Promise.resolve()).catch(() => {}).then(callback);
        locks.set(name, next);
        return next;
    } } };
    class DetailEvent extends Event { constructor(name, options = {}) { super(name); this.detail = options.detail; } }
    const values = { navigator: browserNavigator, window: windowLike, document: documentLike,
        localStorage: { getItem: name => storage.get(name) || null, setItem: (name, value) => storage.set(name, value) },
        indexedDB: {}, BroadcastChannel: undefined, CustomEvent: DetailEvent };
    for (const [name, value] of Object.entries(values)) Object.defineProperty(globalThis, name, { configurable: true, writable: true, value });
    const rawKey = new Uint8Array(32).fill(7);
    const key = await webcrypto.subtle.importKey('raw', rawKey, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
    const rows = [];
    let savedState = null;
    let sequence = 0;
    const store = { rows: async () => rows, state: async () => savedState, sequence: async () => sequence,
        saveState: async (_scope, state, nextSequence) => { savedState = state; sequence = Math.max(sequence, nextSequence); },
        remove: async id => rows.splice(rows.findIndex(row => row.id === id), 1), close: () => {},
        append: async row => { rows.push(row); sequence = row.sequence; },
        update: async row => { rows[rows.findIndex(existing => existing.id === row.id)] = row; } };
    CaptureStore.open = async () => store;
    const calls = [];
    let actor = 1;
    let active = null;
    let serverSequence = 0;
    let responder = null;
    const response = data => ({ ok: true, status: 200, json: async () => data });
    globalThis.fetch = async (url, options) => {
        const payload = JSON.parse(options.body);
        calls.push({ url, payload, csrf: options.headers['X-CSRF-TOKEN'], ready: component.ready, generation: component._generation });
        if (responder) return responder(url, payload);
        if (url === '/capture/sync') {
            active = projectCapture(active, payload.events[0]);
            serverSequence = payload.events.at(-1).sequence;
            return response({ results: payload.events.map(event => ({ event_key: event.event_key, status: 'accepted', entry_id: 9, revision: active.revision })) });
        }
        return response({ actor_id: actor, device_id: 'device-one', key: Buffer.from(rawKey).toString('base64'),
            active, last_sequence: serverSequence, receipts: [] });
    };
    const component = workTimeCapture({ actorId: 1, timezone: 'Europe/Berlin', bootstrapUrl: '/capture/bootstrap', syncUrl: '/capture/sync' });
    let syncs = 0;
    if (!realSync) component.sync = async () => { syncs++; await component.restore(); };
    t.after(() => {
        component.destroy();
        CaptureStore.open = originalOpen;
        for (const name of globals) {
            const descriptor = descriptors.get(name);
            if (descriptor) Object.defineProperty(globalThis, name, descriptor); else delete globalThis[name];
        }
    });
    await component.init();
    return { component, key, store, rows, calls, storage, windowLike, documentLike, browserNavigator, metas,
        reloads: () => reloads, syncs: () => syncs, actor: value => { actor = value; }, respond: callback => { responder = callback; }, response,
        emit: (state, account_binding = 'account-one') => windowLike.dispatchEvent(new DetailEvent('railtime:app-connection-state', { detail: { state, account_binding } })) };
}

test('BFCache return reboots the same account securely without reloading or losing form state', async t => {
    const runtime = await captureRuntime(t);
    const { component, windowLike, metas } = runtime;
    component.title = 'Unsubmitted title';
    component.context = 'training';
    component.trainingId = '7';
    component.startDialogOpen = true;
    windowLike.RailtimeAppLifecycle = { recover: async () => { metas['csrf-token'].content = 'resumed-csrf'; runtime.emit('ready'); return true; } };
    windowLike.dispatchEvent(new Event('pagehide'));
    assert.equal(component.key, null);
    assert.equal(component.ready, false);
    const pageshow = new Event('pageshow');
    pageshow.persisted = true;
    windowLike.dispatchEvent(pageshow);
    await component._bootstrap;
    assert.equal(component.ready, true);
    assert.equal(runtime.calls.length, 2);
    assert.equal(runtime.calls.at(-1).csrf, 'resumed-csrf');
    assert.equal(runtime.syncs(), 2);
    assert.equal(runtime.reloads(), 0);
    assert.equal(component.title, 'Unsubmitted title');
    assert.equal(component.context, 'training');
    assert.equal(component.trainingId, '7');
    assert.equal(component.startDialogOpen, true);
    assert.deepEqual([...runtime.storage.values()], ['device-one']);
});

test('offline BFCache return keeps encrypted events and waits for authorized online recovery', async t => {
    const runtime = await captureRuntime(t);
    const event = { action: 'start', entry_capture_id: 'one', event_key: 'one', sequence: 1, title: 'Offline draft', occurred_at: '2026-10-12T08:00:00Z' };
    runtime.rows.push({ ...await encryptCapture(runtime.key, event), id: 'one', sequence: 1, scope: '1:device-one', status: 'pending' });
    runtime.windowLike.dispatchEvent(new Event('pagehide'));
    runtime.browserNavigator.onLine = false;
    await runtime.component.resume();
    assert.equal(runtime.component.key, null);
    assert.equal(runtime.component.ready, false);
    assert.equal(runtime.calls.length, 1);
    assert.equal(runtime.rows.length, 1);
    assert.equal(runtime.reloads(), 0);
    runtime.browserNavigator.onLine = true;
    await runtime.component.resume();
    assert.equal(runtime.component.ready, true);
    assert.equal(runtime.component.active.title, 'Offline draft');
});

test('recovery refuses another account or changed account binding before restoring or replaying outbox', async t => {
    const runtime = await captureRuntime(t);
    runtime.windowLike.dispatchEvent(new Event('pagehide'));
    runtime.emit('ready', 'other-account');
    assert.equal(runtime.calls.length, 1);
    assert.equal(runtime.component.key, null);
    runtime.actor(2);
    runtime.emit('ready');
    await runtime.component._bootstrap;
    assert.equal(runtime.calls.length, 2);
    assert.equal(runtime.syncs(), 1);
    assert.equal(runtime.component.ready, false);
    assert.equal(runtime.component.key, null);
    assert.match(runtime.component.error, /Sitzung geändert/);
    assert.equal(runtime.calls.some(call => call.url === '/capture/sync'), false);
});

test('pagehide and destroy invalidate an in-flight key bootstrap and late responses cannot restore readiness', async t => {
    const runtime = await captureRuntime(t);
    runtime.component.clearKey();
    let release;
    runtime.respond(() => new Promise(resolve => { release = resolve; }));
    const pending = runtime.component.bootstrap();
    await new Promise(resolve => setImmediate(resolve));
    runtime.windowLike.dispatchEvent(new Event('pagehide'));
    runtime.component.destroy();
    release(runtime.response({ actor_id: 1, device_id: 'device-one', key: Buffer.alloc(32, 7).toString('base64'), active: null, last_sequence: 0 }));
    await pending;
    assert.equal(runtime.component.ready, false);
    assert.equal(runtime.component.key, null);
    assert.equal(runtime.syncs(), 1);
    runtime.emit('ready');
    assert.equal(runtime.calls.length, 2);
});

test('authorized resume sends the existing encrypted owned outbox exactly once', async t => {
    const runtime = await captureRuntime(t, { realSync: true });
    const event = { action: 'start', entry_capture_id: 'entry-one', event_key: 'event-one', sequence: 1, revision: 0,
        title: 'Previously captured', occurred_at: '2026-10-12T08:00:00Z', timezone: 'Europe/Berlin' };
    await runtime.store.append({ ...await encryptCapture(runtime.key, event), id: event.event_key, sequence: 1, scope: '1:device-one', status: 'pending' });
    runtime.windowLike.dispatchEvent(new Event('pagehide'));
    const first = runtime.component.bootstrap();
    const second = runtime.component.bootstrap();
    assert.equal(first, second);
    await first;
    assert.equal(runtime.component.ready, true);
    assert.equal(runtime.component.active.title, 'Previously captured');
    assert.equal(runtime.rows.length, 0);
    const submissions = runtime.calls.filter(call => call.url === '/capture/sync');
    assert.equal(submissions.length, 1);
    assert.deepEqual(submissions[0].payload.events, [event]);
    runtime.emit('session-required');
    assert.equal(runtime.component.ready, false);
    assert.equal(runtime.component.key, null);
    await runtime.component.capture({ action: 'stop' });
    assert.equal(runtime.calls.filter(call => call.url === '/capture/sync').length, 1);
});

test('an interrupted old sync cannot use a newly bootstrapped key or publish stale state', async t => {
    const runtime = await captureRuntime(t, { realSync: true });
    const event = { action: 'start', entry_capture_id: 'entry-race', event_key: 'event-race', sequence: 1, revision: 0,
        title: 'Before suspension', occurred_at: '2026-10-12T08:00:00Z', timezone: 'Europe/Berlin' };
    await runtime.store.append({ ...await encryptCapture(runtime.key, event), id: event.event_key, sequence: 1, scope: '1:device-one', status: 'pending' });
    const readRows = runtime.store.rows;
    let reads = 0;
    let release;
    runtime.store.rows = async scope => {
        if (++reads === 2) await new Promise(resolve => { release = resolve; });
        return readRows(scope);
    };
    const oldSync = runtime.component.sync();
    for (let spin = 0; !release && spin < 30; spin++) await new Promise(resolve => setImmediate(resolve));
    assert.equal(typeof release, 'function');
    runtime.windowLike.dispatchEvent(new Event('pagehide'));
    const resumedGeneration = runtime.component._generation;
    const resumed = runtime.component.bootstrap();
    for (let spin = 0; !runtime.component.key && spin < 30; spin++) await new Promise(resolve => setImmediate(resolve));
    assert.ok(runtime.component.key);
    assert.equal(runtime.component.ready, false);
    release();
    await oldSync;
    await resumed;
    const submissions = runtime.calls.filter(call => call.url === '/capture/sync');
    assert.equal(submissions.length, 1);
    assert.equal(submissions[0].ready, true);
    assert.equal(submissions[0].generation, resumedGeneration);
    assert.equal(runtime.component.ready, true);
    assert.equal(runtime.component.syncing, false);
    assert.equal(runtime.component.active.title, 'Before suspension');
    assert.equal(runtime.rows.length, 0);
});
