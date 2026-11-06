import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { bindInsertView, compactComposeMessage, isUnwrittenPreflight } from '../../resources/js/outlook-addin/taskpane-insert-view.js';

const flush = () => new Promise(resolve => setImmediate(resolve));
function fixture({ choose = async () => ({ applied: true }), available = () => true } = {}) {
    const { document, window } = parseHTML('<main><button data-outlook-pane-tab="insert">Einfügen</button><button data-outlook-pane-tab="automatic">Automatik</button><section data-outlook-pane-panel="insert"><button data-outlook-insert-template>Einfügen</button></section><section data-outlook-pane-panel="automatic">Einstellungen</section><dialog data-outlook-template-picker><button data-outlook-template-picker-close>Schließen</button><div data-outlook-template-options></div><p data-outlook-template-picker-status></p></dialog></main>');
    const root = document.querySelector('main'), dialog = root.querySelector('dialog'), opener = root.querySelector('[data-outlook-insert-template]');
    let focus = 0, captures = 0;
    const errors = [], calls = [], target = { item: 'draft-one', revision: 3 };
    opener.focus = () => { focus++; };
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new window.Event('close')); };
    const view = bindInsertView(root, { templates: () => [{ id: 'standard', name: 'Standard <b>untrusted</b>' }],
        available, capture: () => { captures++; return target; },
        choose: async (id, context) => { calls.push({ id, context }); return choose(id, context); },
        failure: error => errors.push(error) });
    return { document, window, root, dialog, opener, view, errors, calls, target,
        get captures() { return captures; }, get focuses() { return focus; },
        option: () => root.querySelector('[data-template-id]'), status: () => root.querySelector('[data-outlook-template-picker-status]') };
}

test('tabs support selection and keyboard navigation without choosing or mutating a message', () => {
    const f = fixture(), tabs = [...f.root.querySelectorAll('[data-outlook-pane-tab]')];
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true');
    tabs[1].click();
    assert.equal(f.root.querySelector('[data-outlook-pane-panel="insert"]').hidden, true);
    const event = new f.window.Event('keydown', { cancelable: true }); event.key = 'ArrowLeft';
    tabs[1].dispatchEvent(event);
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true');
    assert.equal(tabs[1].tabIndex, -1);
    assert.equal(f.captures, 0); assert.equal(f.calls.length, 0);
});

test('opening and closing the picker do not insert; selection is escaped and bound to the captured item', async () => {
    const f = fixture(); f.opener.click();
    assert.equal(f.dialog.open, true); assert.equal(f.calls.length, 0);
    assert.equal(f.option().querySelector('b'), null);
    assert.match(f.option().textContent, /<b>untrusted<\/b>/);
    f.root.querySelector('[data-outlook-template-picker-close]').click();
    assert.equal(f.calls.length, 0); assert.equal(f.focuses, 1);
    f.opener.click(); f.option().click(); await flush();
    assert.deepEqual(f.calls, [{ id: 'standard', context: f.target }]);
    assert.equal(f.dialog.open, false); assert.equal(f.focuses, 2);
});

test('after success a second explicit choice starts a fresh operation', async () => {
    const f = fixture();
    for (let i = 0; i < 2; i++) { f.opener.click(); f.option().click(); await flush(); }
    assert.equal(f.calls.length, 2); assert.equal(f.captures, 2);
    assert.equal(f.dialog.open, false);
});

test('parallel clicks and Escape cannot interrupt an active choice', async () => {
    let release;
    const f = fixture({ choose: () => new Promise(resolve => { release = resolve; }) });
    f.opener.click(); const oldOption = f.option(); oldOption.click(); oldOption.click();
    const event = new f.window.Event('cancel', { cancelable: true }); f.dialog.dispatchEvent(event);
    f.root.querySelector('[data-outlook-template-picker-close]').click();
    assert.equal(event.defaultPrevented, true); assert.equal(f.dialog.open, true);
    assert.equal(f.calls.length, 1); assert.equal(f.option().disabled, true);
    release({ applied: true }); await flush();
    assert.equal(f.dialog.open, false);
});

test('failure detail remains accessible inside the modal without a hidden-behind-sheet instruction', async () => {
    const f = fixture({ choose: async () => ({ applied: false, message: 'Zitatgrenze nicht eindeutig. Nichts eingefügt.' }) });
    f.opener.click(); f.option().click(); await flush();
    assert.equal(f.dialog.open, true); assert.match(f.status().textContent, /Zitatgrenze/);
    assert.equal(f.status().hidden, false); assert.equal(f.calls.length, 1);
});

test('unavailable insertion and reset cannot use a stale captured context', async () => {
    let enabled = false;
    const f = fixture({ available: () => enabled });
    f.opener.click(); assert.equal(f.captures, 0);
    enabled = true; f.opener.click(); const stale = f.option();
    f.view.reset(); stale.click(); await flush();
    assert.equal(f.calls.length, 0); assert.equal(f.dialog.open, false);
});

test('preflight copy never claims an uncertain write and header mutations are not called untouched', () => {
    const report = { phase: 'preflight', attempted: false, headerWritten: false, bodyWriteStarted: false, bodyWriteSucceeded: false, step: 'snapshot.region' };
    assert.equal(isUnwrittenPreflight(report), true);
    assert.match(compactComposeMessage('U2E_NEW_DRAFT_REQUIRED', report), /nicht ersetzt/);
    assert.doesNotMatch(compactComposeMessage('U2E_NEW_DRAFT_REQUIRED', report), /nicht bestätigt/);
    assert.equal(isUnwrittenPreflight({ ...report, headerWritten: true }), false);
    assert.match(compactComposeMessage('U2E_INSERT_UNCERTAIN', { ...report, state: 'uncertain' }), /nicht bestätigt/);
    assert.doesNotMatch(compactComposeMessage('U2E_INSERT_UNCERTAIN', { ...report, state: 'uncertain' }), /nicht ersetzt/);
});
