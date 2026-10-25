import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { bindAutomaticPreferencesView, normalizeAutomaticPreferences } from '../../resources/js/outlook-addin/automatic-preferences-view.js';

const preferences = (digit = '1', values = {}) => ({ schema: 2, revision: digit.repeat(64),
    newMessages: { signature: false, template: false, ...values.newMessages },
    replies: { signature: false, template: false, ...values.replies } });
const tick = async () => { for (let index = 0; index < 15; index += 1) await Promise.resolve(); };
function fixture(save = async () => preferences('2')) {
    const { document, window } = parseHTML(`<section data-outlook-automatic-region>
        <p data-automatic-account hidden></p><p data-automatic-feedback></p>
        ${['newMessages', 'replies'].flatMap(group => ['signature', 'template'].map(field =>
            `<input type="checkbox" role="switch" data-automatic-setting data-automatic-group="${group}" data-automatic-field="${field}">`)).join('')}
        </section>`);
    const inputs = [...document.querySelectorAll('input')];
    const view = bindAutomaticPreferencesView(document, { save });
    const state = { preferences: preferences(), available: true, busy: false, account: 'employee@company.test' };
    const update = (changes = {}) => view.update({ ...state, ...changes });
    const change = (index, checked) => { inputs[index].checked = checked; inputs[index].dispatchEvent(new window.Event('change')); };
    return { document, inputs, update, change };
}

test('normalization requires all four explicit booleans and a bounded revision', () => {
    const original = preferences();
    const result = normalizeAutomaticPreferences(original);
    assert.equal(result.newMessages.signature, false);
    assert.ok(Object.isFrozen(result) && Object.isFrozen(result.replies));
    for (const invalid of [null, [], {}, { ...original, schema: 1 }, { ...original, revision: ['a'.repeat(64)] },
        { ...original, revision: 'not-revision' }, { ...original, replies: { signature: true } },
        { ...original, newMessages: { signature: 'true', template: false } }]) {
        assert.equal(normalizeAutomaticPreferences(invalid), null);
    }
});

test('unavailable state never enables defaults or sends a save', async () => {
    let saves = 0;
    const view = fixture(async () => { saves += 1; });
    assert.ok(view.inputs.every(input => input.disabled && !input.checked));
    view.update({ available: false });
    view.change(0, true);
    await tick();
    assert.equal(saves, 0);
    assert.equal(view.inputs[0].checked, false);
});

test('single-field save shows pending state and preserves the other three choices', async () => {
    let finish;
    const requests = [];
    const view = fixture(change => { requests.push(change); return new Promise(resolve => { finish = resolve; }); });
    view.update();
    view.change(0, true);
    assert.deepEqual(requests, [{ group: 'newMessages', field: 'signature', enabled: true, revision: '1'.repeat(64) }]);
    assert.ok(view.inputs.every(input => input.disabled));
    assert.equal(view.inputs[0].checked, true);
    assert.equal(view.document.querySelector('section').getAttribute('aria-busy'), 'true');
    view.change(1, true);
    assert.equal(requests.length, 1);
    finish({ automaticInsertionPreferences: preferences('2', { newMessages: { signature: true } }) });
    await tick();
    assert.deepEqual(view.inputs.map(input => input.checked), [true, false, false, false]);
    assert.ok(view.inputs.every(input => !input.disabled));
    assert.match(view.document.querySelector('[data-automatic-feedback]').textContent, /Gespeichert/);
});

test('failed save restores the persisted choice and communicates the failure', async () => {
    const view = fixture(async () => { throw new Error('Synthetic network failure'); });
    view.update(); view.change(3, true); await tick();
    assert.deepEqual(view.inputs.map(input => input.checked), [false, false, false, false]);
    assert.equal(view.document.querySelector('[data-automatic-feedback]').dataset.tone, 'error');
    assert.match(view.document.querySelector('[data-automatic-feedback]').textContent, /Speichern nicht bestätigt/);
});

test('revision conflicts show the current server choices without overwriting them', async () => {
    const current = preferences('3', { replies: { signature: true } });
    const view = fixture(async () => { throw Object.assign(new Error('Conflict'), { currentPreferences: current }); });
    view.update(); view.change(1, true); await tick();
    assert.deepEqual(view.inputs.map(input => input.checked), [false, false, true, false]);
    assert.match(view.document.querySelector('[data-automatic-feedback]').textContent, /inzwischen geändert/);
});

test('a late old-mailbox receipt never changes the new mailbox presentation', async () => {
    let finish;
    const view = fixture(() => new Promise(resolve => { finish = resolve; }));
    view.update(); view.change(0, true);
    view.update({ account: 'other@company.test', preferences: preferences('4', { replies: { template: true } }) });
    finish(preferences('2', { newMessages: { signature: true } })); await tick();
    assert.deepEqual(view.inputs.map(input => input.checked), [false, false, false, true]);
    assert.equal(view.document.querySelector('[data-automatic-account]').textContent, 'other@company.test');
});

test('manual action busy state disables settings without changing persisted choices', () => {
    const view = fixture();
    view.update({ busy: true, preferences: preferences('2', { newMessages: { template: true } }) });
    assert.ok(view.inputs.every(input => input.disabled));
    assert.deepEqual(view.inputs.map(input => input.checked), [false, true, false, false]);
});

const taskpaneSource = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
const saveSource = taskpaneSource.slice(taskpaneSource.indexOf('async function saveAutomaticPreference('), taskpaneSource.indexOf('\nfunction displayAccount('));
test('actual taskpane save uses fresh token and mailbox binding with no Office body write', async () => {
    const requests = [];
    const signature = { html: '<p>Existing signature</p>' };
    const binding = { schema: 1, mailboxAddress: 'employee@company.test', senderAddress: 'employee@company.test' };
    const context = vm.createContext({
        taskpaneState: { busy: false, authenticated: true, bootstrapReady: true },
        currentConfig: { endpoints: { preferences: 'https://app.company.test/api/outlook-addin/preferences' } },
        currentBootstrap: { binding, signature, automaticInsertionPreferences: preferences() },
        API_TIMEOUT_MS: 12000,
        Office: {},
        captureComposeTarget: () => ({ item: Object.freeze({}) }),
        setBusy() {}, syncActionState() {},
        acquireAccessToken: async () => 'synthetic-not-a-token',
        assertWriteTarget: async (_target, actual) => assert.deepEqual(actual, binding),
        readComposeSender: async () => 'employee@company.test',
        mailboxAddress: () => 'employee@company.test',
        fetchJson: async (url, options) => { requests.push({ url, options }); return { binding, automaticInsertionPreferences: preferences('2') }; },
        normalizeAutomaticPreferences,
        codedError: code => Object.assign(new Error(code), { code }),
        authenticationWasLost: () => false,
        safeErrorCode: error => error.code || '',
        invalidateBootstrap() { throw new Error('Unexpected invalidation'); },
    });
    vm.runInContext(saveSource, context);
    const change = { group: 'replies', field: 'template', enabled: false, revision: '1'.repeat(64) };
    await context.saveAutomaticPreference(change);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.credentials, 'omit');
    assert.equal(requests[0].options.headers['X-RailTime-Outlook-Context'], 'taskpane');
    assert.deepEqual(JSON.parse(requests[0].options.body), change);
    assert.equal(context.currentBootstrap.signature, signature);
    assert.equal(context.currentBootstrap.automaticInsertionPreferences.revision, '2'.repeat(64));
});
