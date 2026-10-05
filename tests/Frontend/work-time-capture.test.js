import test from 'node:test';
import assert from 'node:assert/strict';
import { webcrypto } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { encryptCapture, decryptCapture, projectCapture, workTimeCapture } from '../../resources/js/work-time-capture.js';

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
