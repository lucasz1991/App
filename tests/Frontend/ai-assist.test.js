import test, { afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { aiAssist, readAiAssistPrefs, filterAiAssistActions, AI_ASSIST_PREFS_KEY } from '../../resources/js/ai-assist.js';

const storage = (value) => ({ getItem: key => (key === AI_ASSIST_PREFS_KEY ? value : null) });
const originalWindow = globalThis.window;
const fixtures = [];
const flush = () => new Promise(resolve => setImmediate(resolve));

afterEach(() => {
    for (const component of fixtures.splice(0)) component.destroy();
    if (originalWindow === undefined) delete globalThis.window;
    else globalThis.window = originalWindow;
});

function fixture(options = {}) {
    const events = options.events || [];
    globalThis.window = { dispatchEvent: event => events.push(event), innerWidth: options.width || 1280, localStorage: { getItem: () => null }, sessionStorage: { getItem: () => '1', setItem() {} } };
    const component = aiAssist(options);
    Object.assign(component, {
        $wire: { load: async () => {}, ask: async () => {}, run: async () => {} },
        $nextTick: fn => fn(),
        $refs: { input: { style: {}, scrollHeight: 30, focus() {} }, launcher: { focus() {} } },
        $el: { dataset: { actions: JSON.stringify([{ key: 'period', title: 'Besetzung' }, { key: 'day', title: 'Tageslage' }]), hints: '[]' } },
    });
    component.init();
    fixtures.push(component);
    return component;
}

test('personal preferences default to visible context and hints and ignore broken storage', () => {
    assert.deepEqual(readAiAssistPrefs(storage(null)), { context: true, proactive: true });
    assert.deepEqual(readAiAssistPrefs(storage('{"context":false,"proactive":"yes"}')), { context: false, proactive: true });
    assert.deepEqual(readAiAssistPrefs(storage('{broken')), { context: true, proactive: true });
    assert.deepEqual(readAiAssistPrefs({ getItem() { throw new Error('blocked'); } }), { context: true, proactive: true });
});

test('slash list filters actions by title and tolerates bad input', () => {
    const actions = [{ key: 'period', title: 'Besetzung für den Zeitraum vorschlagen' }, { key: 'workload', title: 'Auslastung ausgleichen' }];
    assert.deepEqual(filterAiAssistActions(actions, '/').map(a => a.key), ['period', 'workload']);
    assert.deepEqual(filterAiAssistActions(actions, '/ausl').map(a => a.key), ['workload']);
    assert.deepEqual(filterAiAssistActions(null, '/x'), []);
});

test('keyboard: slash navigation wraps, Enter submits the selected action, Ctrl+J toggles', async () => {
    const component = fixture();
    const calls = [];
    Object.assign(component, { $wire: { load: async () => {}, run: key => { calls.push(['run', key]); return Promise.resolve(); }, ask: q => { calls.push(['ask', q]); return Promise.resolve(); } },
        $nextTick: fn => fn(), $refs: {}, $el: { dataset: { actions: JSON.stringify([{ key: 'period', title: 'Besetzung' }, { key: 'day', title: 'Tageslage' }]), hints: '[]' } } });
    globalThis.window = { dispatchEvent() {}, innerWidth: 1280, localStorage: { getItem: () => null }, sessionStorage: { getItem: () => '1', setItem() {} } };
    component.text = '/';
    const keydown = key => { let prevented = false; component.keydown({ key, shiftKey: false, preventDefault: () => { prevented = true; } }); return prevented; };
    assert.equal(keydown('ArrowUp'), true);
    assert.equal(component.slashIndex, 1);
    keydown('Enter');
    assert.deepEqual(calls[0], ['run', 'day']);
    assert.equal(component.text, '');
    await flush();
    component.text = 'Wer kann heute?';
    keydown('Enter');
    assert.deepEqual(calls[1], ['ask', 'Wer kann heute?']);
    await flush();
    const event = { ctrlKey: true, metaKey: false, altKey: false, key: 'J', prevented: false, preventDefault() { this.prevented = true; } };
    component.shortcut(event);
    assert.equal(component.open, true);
    assert.equal(event.prevented, true);
    component.shortcut({ ctrlKey: false, metaKey: false, altKey: false, key: 'j', preventDefault() { throw new Error('no'); } });
    assert.equal(component.open, true);
});

test('closing keeps the unsent draft and first opening loads once with retry after failure', async () => {
    const component = fixture();
    component.mode = 'fullscreen';
    let loads = 0;
    component.$wire.load = async () => { if (++loads === 1) throw new Error('offline'); };
    component.text = 'Ungesendeter Entwurf';
    component.toggle(true);
    assert.equal(component.mode, 'fullscreen', 'opening retains the selected desktop display mode');
    await flush();
    assert.match(component.callError, /erneut/);
    assert.equal(component.loaded, false);
    component.toggle(false);
    assert.equal(component.text, 'Ungesendeter Entwurf');
    component.toggle(true);
    await flush();
    assert.equal(component.loaded, true);
    assert.equal(component.callError, '');
    component.toggle(false);
    component.toggle(true);
    await flush();
    assert.equal(loads, 2);
    assert.equal(component.text, 'Ungesendeter Entwurf');
});

test('session restore loads once, restores mode/corner and retains them when reopening', async () => {
    const events=[],component=fixture({events});
    let loads=0;component.$wire.load=async()=>{loads++};
    component.restoreWindowSession({open:true,mode:'fullscreen',corner:'top-left'});
    await flush();
    assert.equal(component.open,true);assert.equal(component.mode,'fullscreen');assert.equal(component.corner,'top-left');
    assert.equal(component.loaded,true);assert.equal(loads,1);
    assert.equal(events.find(e=>e.type==='railtime-assistant-opened').detail.corner,'top-left');
    component.toggle(false);component.toggle(true);await flush();
    assert.equal(component.mode,'fullscreen');assert.equal(loads,1);
});

test('session restore respects mobile fullscreen and an explicit intake-opening request', () => {
    const component=fixture({width:390});
    component.$el.dataset.intakeOpen='true';
    component.restoreWindowSession({open:false,mode:'mini',corner:'top-right'});
    assert.equal(component.open,true,'an explicit intake request wins over the stored closed state');
    assert.equal(component.mode,'fullscreen');assert.equal(component.corner,'top-right');
});

test('opening dispatches the assistant corner and mobile state for surface coordination', () => {
    const events = [];
    const component = fixture({ events });
    component.corner = 'top-left';
    component.toggle(true, { load: false });
    const opened = events.find(event => event.type === 'rt-floating-surface-opened');
    assert.deepEqual(opened.detail, { surface: 'assist', corner: 'top-left', mobile: false });
    component.toggle(false);
});

test('busy admission prevents duplicate calls and rejected submit restores its exact draft', async () => {
    const component = fixture();
    let reject;
    let requests = 0;
    component.$wire.ask = () => { requests++; return new Promise((resolve, fail) => { reject = fail; }); };
    component.text = '  Anfrage noch prüfen  ';
    const first = component.submit();
    assert.equal(component.text, '');
    assert.equal(component.busy, true);
    component.text = 'Neuer Text';
    assert.equal(await component.submit(), false);
    assert.equal(await component.run('period'), false);
    assert.equal(component.text, 'Neuer Text', 'duplicate admission must not clear a new draft');
    component.text = '';
    reject(new Error('private transport detail'));
    assert.equal(await first, false);
    assert.equal(requests, 1);
    assert.equal(component.text, '  Anfrage noch prüfen  ');
    assert.equal(component.busy, false);
    assert.match(component.callError, /Eingabe bleibt erhalten/);
    assert.doesNotMatch(component.callError, /private transport/);
});

test('failed action never overwrites a newer draft and HTTP commit failure unlocks retry', async () => {
    const component = fixture();
    let hook;
    let cleaned = 0;
    component.$wire.$hook = (name, callback) => { assert.equal(name, 'commit'); hook = callback; return () => { cleaned++; }; };
    component.$wire.run = () => new Promise(() => {});
    component.text = '/period';
    const pending = component.run('period');
    component.text = 'Neuer eigener Text';
    hook({ commit: { calls: [{ method: 'load' }] }, fail() { assert.fail('unrelated commit must not reject action'); } });
    hook({ commit: { calls: [{ method: 'run' }] }, fail: callback => callback() });
    assert.equal(await pending, false);
    assert.equal(component.text, 'Neuer eigener Text');
    assert.equal(component.busy, false);
    assert.equal(cleaned, 1);
    component.$wire.run = async () => {};
    assert.equal(await component.run('period'), true);
    assert.equal(cleaned, 2);
});

test('new output preserves a reader position until jump to latest and follows a pinned reader', async () => {
    const component = fixture();
    const box = { scrollHeight: 500, clientHeight: 100, scrollTop: 100, isConnected: true };
    component.$refs.messages = box;
    component.$wire.ask = async () => { box.scrollHeight += 200; };
    assert.equal(await component.ask('Erste Frage'), true);
    assert.equal(box.scrollTop, 100);
    assert.equal(component.unseenOutput, true);
    component.jumpToLatest();
    assert.equal(box.scrollTop, 700);
    assert.equal(component.unseenOutput, false);
    box.scrollTop = 590;
    assert.equal(await component.ask('Zweite Frage'), true);
    assert.equal(box.scrollTop, 900);
    assert.equal(component.unseenOutput, false);
});

test('destroyed owner ignores late response, focus and all further calls', async () => {
    const component = fixture();
    const ticks = [];
    let resolve;
    let requests = 0;
    component.$nextTick = callback => ticks.push(callback);
    component.$wire.ask = () => { requests++; return new Promise(done => { resolve = done; }); };
    component.text = 'Entwurf';
    const pending = component.submit();
    component.toggle(true);
    component.destroy();
    resolve();
    assert.equal(await pending, false);
    ticks.forEach(callback => callback());
    assert.equal(component.callError, '');
    assert.equal(await component.ask('Später'), false);
    assert.equal(requests, 1);
});

test('embedded owner does not capture Ctrl+J and IME Enter never submits', () => {
    const component = fixture({ embedded: true });
    component.shortcut({ ctrlKey: true, key: 'j', preventDefault() { assert.fail('central assistant owns shortcut'); } });
    component.text = 'Komposition';
    component.keydown({ key: 'Enter', isComposing: true, preventDefault() { assert.fail('IME confirmation is not submit'); } });
    assert.equal(component.open, false);
    assert.equal(component.text, 'Komposition');
});


test('intake opening captures the requested identity and waits for native server acceptance', async () => {
    const component = fixture();
    const calls = [];
    component.$wire.openIntake = async (...args) => calls.push(args);
    assert.equal(await component.openIntake({ intakeId: 23, create: false, customerId: 9 }), true);
    assert.deepEqual(calls, [[23, false, 9]]);
    assert.equal(component.open, true);
    assert.equal(component.loaded, true);
    assert.equal(component.busy, false);
});

test('a hovered hint opens the assist and runs its action without a competing load request', async () => {
    const component = fixture();
    const calls = [];
    component.$wire.run = async key => { calls.push(['run', key]); };
    component.$wire.load = async () => { calls.push(['load']); };

    assert.equal(await component.openHint({ run: 'intake' }), true);
    assert.equal(component.open, true);
    assert.equal(component.loaded, true);
    assert.deepEqual(calls, [['run', 'intake']]);
});

test('intake opening rejects duplicate pending calls and leaves an actionable error on failure', async () => {
    const component = fixture();
    let release;
    let calls = 0;
    component.$wire.openIntake = () => { calls++; return new Promise((resolve, reject) => { release = reject; }); };
    const first = component.openIntake({ create: true });
    assert.equal(await component.openIntake({ intakeId: 88 }), false);
    release(new Error('Context not permitted'));
    assert.equal(await first, false);
    assert.equal(calls, 1);
    assert.equal(component.loaded, false);
    assert.equal(component.busy, false);
    assert.match(component.callError, /erneut versuchen/);
});

test('closing the assistant notifies capture controls to release the microphone', () => {
    const component = fixture();
    const events = [];
    globalThis.window.dispatchEvent = event => events.push(event.type);
    component.open = true;
    component.toggle(false);
    assert.deepEqual(events, ['ai-intake-panel-hidden']);
});
