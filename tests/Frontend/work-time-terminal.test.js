import assert from 'node:assert/strict';
import test from 'node:test';
import { workTimeTerminal } from '../../resources/js/work-time-terminal.js';

function terminal() {
    const state = workTimeTerminal({ identifyUrl: '/identify', captureUrl: '/capture' });
    // These tests exercise the terminal workflow without rendering an Alpine element.
    state.focus = () => {};
    state.userId = '24';
    state.pin = '123456';
    state.terminalId = '2d20afc5-b8f2-47fc-9153-f3e5e350984b';
    return state;
}

test('leaving the terminal while identification is pending cannot restore a personal session', async t => {
    let deliver;
    t.mock.method(globalThis, 'fetch', () => new Promise(resolve => { deliver = resolve; }));
    const state = terminal();
    const pending = state.identify();
    state.clear();
    deliver(Response.json({ token: 'late-token', active: null, choices: [], expires_in: 60 }));
    await pending;
    assert.equal(state.token, null);
    assert.equal(state.pin, '');
    assert.equal(state.userId, '');
    assert.equal(state.busy, false);
});

test('terminal sends the selected duty once and clears personal state after the receipt', async t => {
    const calls = [];
    let deliver;
    t.mock.method(globalThis, 'fetch', (url, init) => {
        calls.push({ url, init, body: JSON.parse(init.body) });
        if (url === '/identify') return Promise.resolve(Response.json({
            token: 'synthetic-token', expires_in: 60, choices: [{ id: 8, plan_revision: 3, title: 'Dienst 8' }],
            active: null, location_required: false,
        }));
        return new Promise(resolve => { deliver = resolve; });
    });
    const state = terminal();
    t.after(() => state.destroy());
    await state.identify();
    assert.equal(state.pin, '');
    state.choice = '8';
    const pending = state.capture();
    await state.capture();
    assert.equal(calls.length, 2);
    assert.equal(calls[1].init.credentials, 'omit');
    assert.equal(calls[1].body.event.work_context, 'shift');
    assert.equal(calls[1].body.event.assignment_id, 8);
    assert.equal(calls[1].body.event.plan_revision, 3);
    deliver(Response.json({ status: 'conflict', reason: 'Zur Prüfung erhalten.' }));
    await pending;
    assert.equal(state.message, 'Zur Prüfung erhalten.');
    assert.equal(state.tone, 'warning');
    assert.equal(state.token, null);
    assert.deepEqual(state.choices, []);
    assert.equal(state.userId, '');
});
