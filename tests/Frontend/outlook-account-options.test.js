import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { createAccountOptionsSession, normalizeTemplateChoicePreferences, normalizeWordingPreferences,
    bindWordingPreferencesView, bindTemplateChoiceView } from '../../resources/js/outlook-addin/account-options-view.js';
import { automaticComposeDecision } from '../../resources/js/outlook-addin/automatic-compose-policy.js';

const own = 'employee@company.test';
const A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const binding = { schema: 1, mailboxAddress: own, senderAddress: own, allowedSenderAddresses: [own] };
const wording = (digit = '1') => ({ schema: 1, greeting: 'Guten Tag,', farewell: 'Mit freundlichen Grüßen,', revision: digit.repeat(64) });
const choices = (digit = '1') => ({ schema: 1, revision: digit.repeat(64), newMessages: { selection: A, available: true },
    replies: { selection: B, available: true }, templates: [{ id: A, name: 'Standard', isDefault: true }, { id: B, name: 'Mit Anrede' }] });
const tick = async () => { for (let i = 0; i < 50; i++) await Promise.resolve(); };
const fail = code => Object.assign(new Error(code), { code });

function fixture(kind = 'wording') {
    const key = kind === 'wording' ? 'wordingPreferences' : 'templateChoicesPreferences';
    const state = { account: own, response: { [key]: kind === 'wording' ? wording() : choices(), binding }, requests: [], error: null };
    const session = createAccountOptionsSession({ kind, readMailbox: () => state.account, authenticate: async () => 'synthetic',
        request: async options => { state.requests.push(options); if (state.error) throw state.error; return state.response; } });
    return { state, session, key };
}

test('wording is plain bounded text, schema/revision mandatory and HTML never rendered', () => {
    for (const text of ['', ' ', '<b>Name</b>', 'first\nsecond', '{{OUTLOOK_ANREDE}}', 'x'.repeat(121), '\u0000']) {
        assert.equal(normalizeWordingPreferences({ ...wording(), greeting: text }), null);
    }
    assert.equal(normalizeWordingPreferences({ ...wording(), greeting: '"&copy;" <' }), null);
    assert.equal(normalizeWordingPreferences({ ...wording(), greeting: '"&copy;"' }).greeting, '"&copy;"');
    assert.equal(normalizeWordingPreferences({ ...wording(), revision: '' }), null);
});

test('both resources bind freshly to own mailbox and send only exact account-settings patches', async () => {
    for (const kind of ['wording', 'choices']) {
        const h = fixture(kind);
        await assert.rejects(h.session.save({ revision: '1'.repeat(64) }), { code: 'PREFERENCES_UNAVAILABLE' });
        await h.session.load();
        await h.session.save(kind === 'wording' ? { ...wording(), unknown: 'discard' }
            : { group: 'replies', selection: 'none', revision: '1'.repeat(64), unknown: 'discard' });
        const patch = h.state.requests[1];
        assert.equal(patch.headers['X-RailTime-Outlook-Context'], 'account-settings');
        assert.equal(patch.headers['X-RailTime-Outlook-Mailbox'], own);
        assert.equal(patch.headers['X-RailTime-Outlook-Sender'], undefined);
        assert.equal(patch.credentials, 'omit');
        assert.deepEqual(Object.keys(JSON.parse(patch.body)).sort(), kind === 'wording' ? ['farewell', 'greeting', 'revision'] : ['group', 'revision', 'selection']);
    }
});

test('unknown choice, stale revision and foreign own-account receipts cannot be saved', async () => {
    const h = fixture('choices'); await h.session.load();
    await assert.rejects(h.session.save({ group: 'replies', selection: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', revision: '1'.repeat(64) }), { code: 'PREFERENCES_INVALID' });
    await assert.rejects(h.session.save({ group: 'replies', selection: 'none', revision: '2'.repeat(64) }), { code: 'PREFERENCES_UNAVAILABLE' });
    h.state.response.binding = { ...binding, senderAddress: 'received@outside.test' };
    await assert.rejects(h.session.load(), { code: 'ACCOUNT_PREFERENCES_BINDING_INVALID' });
    assert.equal(h.session.current(), null);
});

test('resource CAS receipt requires its own binding; account changes quarantine late responses', async () => {
    const h = fixture(); await h.session.load();
    h.state.error = Object.assign(fail('HTTP_409'), { currentAccountOptions: { wordingPreferences: wording('2'), binding } });
    await assert.rejects(h.session.save(wording()), { code: 'HTTP_409' });
    assert.equal(h.session.current().preferences.revision, '2'.repeat(64));
    h.state.error.currentAccountOptions.binding = { ...binding, mailboxAddress: 'other@company.test' };
    await assert.rejects(h.session.save(wording('2')), { code: 'HTTP_409' });
    assert.equal(h.session.current(), null);
    let account = own; let finish;
    const session = createAccountOptionsSession({ kind: 'wording', readMailbox: () => account, authenticate: async () => 'token',
        request: () => new Promise(resolve => { finish = resolve; }) });
    const pending = session.load(); await tick(); account = 'other@company.test'; finish({ wordingPreferences: wording(), binding });
    await assert.rejects(pending, { code: 'MAILBOX_MISMATCH' });
    assert.equal(session.current(), null);
});

function wordingViewFixture(save = async () => ({ wordingPreferences: wording('2') })) {
    const { document, window } = parseHTML(`<section data-outlook-wording-region>
        <input data-wording-field="greeting"><input data-wording-field="farewell">
        <button data-wording-toggle="showGreeting" aria-pressed="false">Greeting</button><button data-wording-toggle="showFarewell" aria-pressed="false">Farewell</button><button data-wording-save>Save</button><button data-wording-reload>Reload</button><p data-wording-feedback></p></section>`);
    const view = bindWordingPreferencesView(document, { save, reload: async () => ({ wordingPreferences: wording('3') }) });
    view.update({ account: own, preferences: wording(), available: true, busy: false });
    return { document, window, view, input: document.querySelector('input'), save: document.querySelector('[data-wording-save]') };
}

test('wording needs explicit save, disables invalid draft and uses text inputs only', async () => {
    const requests = [];
    const h = wordingViewFixture(async change => { requests.push(change); return { wordingPreferences: { ...wording('2'), greeting: change.greeting } }; });
    h.input.value = 'Hallo & willkommen,'; h.input.dispatchEvent(new h.window.Event('input'));
    assert.equal(requests.length, 0);
    h.save.click(); await tick();
    assert.equal(requests.length, 1); assert.equal(requests[0].greeting, 'Hallo & willkommen,');
    assert.match(h.document.querySelector('[data-wording-feedback]').textContent, /gespeichert/);
    h.input.value = '<script>'; h.input.dispatchEvent(new h.window.Event('input')); assert.ok(h.save.disabled);
});

test('wording conflict preserves draft and requires explicit reload; old mailbox save cannot alter new view', async () => {
    const h = wordingViewFixture(async () => { throw fail('HTTP_409'); });
    h.input.value = 'Mein eigener Text'; h.input.dispatchEvent(new h.window.Event('input')); h.save.click(); await tick();
    assert.equal(h.input.value, 'Mein eigener Text'); assert.ok(h.save.disabled);
    h.document.querySelector('[data-wording-reload]').click(); await tick();
    assert.equal(h.input.value, 'Guten Tag,');
    let finish;
    const late = wordingViewFixture(() => new Promise(resolve => { finish = resolve; }));
    late.input.value = 'Old'; late.input.dispatchEvent(new late.window.Event('input')); late.save.click();
    late.view.update({ account: 'other@company.test', preferences: { ...wording(), greeting: 'Other' }, available: true });
    finish({ wordingPreferences: wording('2') }); await tick(); assert.equal(late.input.value, 'Other');
});

test('background refresh cannot promote an unsaved form revision past another client change', async () => {
    const requests = [];
    const h = wordingViewFixture(async change => { requests.push(change); return { wordingPreferences: wording('3') }; });
    h.input.value = 'Unsaved local text'; h.input.dispatchEvent(new h.window.Event('input'));
    h.view.update({ account: own, preferences: { ...wording('2'), greeting: 'Changed elsewhere' }, available: true, busy: false });
    assert.equal(h.input.value, 'Unsaved local text');
    assert.ok(h.save.disabled);
    h.save.click(); await tick();
    assert.equal(requests.length, 0);
    assert.equal(h.document.querySelector('[data-wording-reload]').hidden, false);
    h.document.querySelector('[data-wording-reload]').click(); await tick();
    assert.equal(h.input.value, 'Guten Tag,');
});

test('template choices are two independent dropdowns, withdrawn selection stays unavailable and manual picker is untouched', async () => {
    const { document, window } = parseHTML(`<select id="manual"><option selected value="manual">Manual</option></select>
        <div data-outlook-choices-region><select data-template-choice-group="newMessages"></select>
        <select data-template-choice-group="replies"></select><p data-template-choice-feedback></p></div>`);
    const changes = [];
    const view = bindTemplateChoiceView(document, { save: async change => { changes.push(change); return { templateChoicesPreferences: { ...choices('2'), replies: { selection: change.selection, available: true } } }; } });
    assert.equal(document.querySelector('[data-template-choice-group]').options.length, 1);
    assert.match(document.querySelector('[data-template-choice-group]').textContent, /nicht geladen/);
    view.update({ account: own, preferences: choices(), available: true });
    const reply = document.querySelector('[data-template-choice-group="replies"]');
    [...reply.options].forEach(option => { option.selected = false; });
    reply.querySelector('[value="none"]').selected = true;
    reply.dispatchEvent(new window.Event('change')); await tick();
    assert.deepEqual(changes[0], { group: 'replies', selection: 'none', revision: '1'.repeat(64) });
    assert.equal(document.querySelector('#manual').value, 'manual');
    view.update({ account: own, available: true, preferences: { ...choices(), replies: { selection: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', available: false } } });
    assert.match(reply.textContent, /Nicht mehr verfügbar/);
    assert.equal(reply.querySelector('[value="cccccccc-cccc-4ccc-8ccc-cccccccccccc"]').disabled, true);
    view.update({ account: own, available: true, preferences: { ...choices(), replies: { selection: 'default', available: false } } });
    assert.equal(reply.querySelectorAll('[value="default"]').length, 1);
    assert.equal(reply.querySelector('[value="default"]').disabled, true);
    view.update({ account: own, available: true, preferences: { ...choices(), templates: choices().templates.map(entry => ({ ...entry, isDefault: false })) } });
    assert.equal(reply.querySelector('[value="default"]').disabled, true, 'no default stays unavailable even when a specific published document is selected');
    assert.equal(reply.querySelector(`[value="${B}"]`).disabled, false);
    assert.equal(normalizeTemplateChoicePreferences({ ...choices(), templates: [choices().templates[0], choices().templates[0]] }), null);
});

test('Policy3 chooses only the server-confirmed context document, none leaves signature independent and legacy decoder stays compatible', () => {
    const payload = (composeType, id, template = true) => ({ automaticInsertionEnabled: true,
        automaticInsertion: { schema: 2, composeType, signature: true, template },
        automaticTemplateChoice: { schema: 1, composeType, selection: template ? id : 'none', templateId: template ? id : null },
        automaticTemplateId: id, templates: [{ id, isDefault: true }] });
    for (const [type, id] of [['newMail', A], ['reply', B], ['forward', A]]) {
        assert.equal(automaticComposeDecision(payload(type, id), type, { requireTemplateChoice: true }).mode, 'both');
    }
    assert.equal(automaticComposeDecision(payload('reply', B, false), 'reply', { requireTemplateChoice: true }).mode, 'signature');
    for (const mutate of [p => { p.automaticTemplateChoice = null; }, p => { p.automaticTemplateChoice.composeType = 'reply'; },
        p => { p.automaticTemplateChoice.templateId = B; }, p => { p.templates.push({ ...p.templates[0] }); }, p => { p.templates[0].isDefault = false; }]) {
        const p = payload('newMail', A); mutate(p);
        assert.equal(automaticComposeDecision(p, 'newMail', { requireTemplateChoice: true }).mode, 'none');
    }
    const legacy = payload('newMail', A); delete legacy.automaticTemplateChoice;
    assert.equal(automaticComposeDecision(legacy, 'newMail').mode, 'both');
});

test('the next actual manual action fetches fresh compiled wording and never substitutes the pane initial payload', async () => {
    const source = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    const start = source.indexOf('async function withAuthenticatedBootstrap(');
    const end = source.indexOf('\nasync function updateSignature(', start);
    assert.ok(start > 0 && end > start);
    const h = fixture(); await h.session.load();
    const oldPayload = { templateHtml: '<p>Old wording</p>' };
    let freshPayload = oldPayload; let loads = 0; let accepted = null; let received = null;
    const state = { settingsMode: false, busy: false };
    const target = { item: new Proxy({}, { get() { throw Error('No message body access for loading settings'); } }) };
    const context = vm.createContext({ taskpaneState: state, bootstrapStateRevision: 0, signatureStateRevision: 0,
        currentConfig: {}, currentBootstrap: oldPayload, lastActionError: null,
        captureComposeTarget: () => target, isTemplateInsertionBlocked: () => false, isStandaloneSignatureInsertionBlocked: () => false,
        hasUncertainWrite: () => false, diagnoseStep: (_step, fn) => fn(), acquireAccessToken: async () => 'fresh-token', displayAccount() {},
        loadBootstrap: async () => { loads++; return freshPayload; }, assertWriteTarget: async () => {},
        acceptBootstrap: async (payload, options) => { accepted = payload; assert.equal(options.inspectBody, false); },
        setBusy: busy => { state.busy = busy; }, syncActionState() {}, handleFailure: error => { throw error; } });
    vm.runInContext(source.slice(start, end), context);
    h.state.response.wordingPreferences = { ...wording('2'), greeting: 'Neue Anrede,', farewell: 'Neuer Gruß,' };
    await h.session.save({ ...wording(), greeting: 'Neue Anrede,', farewell: 'Neuer Gruß,' });
    freshPayload = { templateHtml: '<p>Neue Anrede,</p><p>Neuer Gruß,<br>Canonical Employee</p>', version: 'new-wording' };
    await context.withAuthenticatedBootstrap({}, async payload => { received = payload; });
    assert.equal(loads, 1);
    assert.equal(accepted, freshPayload);
    assert.equal(received, freshPayload);
    assert.equal(context.currentBootstrap, oldPayload, 'test proves the action uses the fresh argument, not cached state');
});


test('two visibility toggles save independently, preserve a dirty text draft and never optimistically acknowledge', async () => {
    const requests = []; let finish;
    const h = wordingViewFixture(change => { requests.push(change); return new Promise(resolve => { finish = resolve; }); });
    const greeting = h.document.querySelector('[data-wording-toggle="showGreeting"]');
    const farewell = h.document.querySelector('[data-wording-toggle="showFarewell"]');
    h.input.value = 'Unsaved personal text,'; h.input.dispatchEvent(new h.window.Event('input'));
    greeting.click(); await tick();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].greeting, 'Guten Tag,', 'visibility never commits a deferred text draft');
    assert.equal(requests[0].showGreeting, true); assert.equal(requests[0].showFarewell, false);
    assert.equal(greeting.getAttribute('aria-pressed'), 'false');
    assert.equal(farewell.disabled, true);
    finish({ wordingPreferences: { ...wording('2'), showGreeting: true, showFarewell: false } }); await tick();
    assert.equal(greeting.getAttribute('aria-pressed'), 'true');
    assert.equal(farewell.getAttribute('aria-pressed'), 'false');
    assert.equal(h.input.value, 'Unsaved personal text,'); assert.equal(h.save.disabled, false);
    farewell.click(); await tick();
    assert.equal(requests[1].revision, '2'.repeat(64));
    assert.equal(requests[1].showGreeting, true); assert.equal(requests[1].showFarewell, true);
});

test('visibility session strictly validates booleans, retains old text-only PATCH and binds account receipts', async () => {
    const h = fixture(); await h.session.load();
    for (const value of [1, 'true', null, []]) {
        await assert.rejects(h.session.save({ ...wording(), showGreeting: value }), { code: 'PREFERENCES_INVALID' });
    }
    h.state.response.wordingPreferences = { ...wording('2'), showGreeting: true, showFarewell: false };
    await h.session.save({ ...wording(), showGreeting: true, showFarewell: false });
    assert.equal(JSON.parse(h.state.requests.at(-1).body).showGreeting, true);
    assert.equal(normalizeWordingPreferences({ ...wording(), showFarewell: 'false' }), null);
});

test('immediate wording replacement requires a known managed template and never falls through to prepend', async () => {
    const source = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    const start = source.indexOf('async function insertTemplate(');
    const end = source.indexOf('\nasync function showDiagnosticReport', start);
    let writes = 0; let reloads = 0;
    const state = { busy: false };
    const context = vm.createContext({ taskpaneState: state, Office: {}, currentConfig: { marker: 'marker' },
        selectedTemplate: () => ({ name: 'Standard', document: {} }), setStatus() {},
        withAuthenticatedBootstrap: async (_button, action) => { reloads++; await action({ signature: {} }, {}); },
        assertComposeTarget: () => ({ body: {} }), combinedComposeDocument: () => ({ html: 'fresh', media: [] }),
        nativeComposeTemplate() {}, validatedDocument: value => value, diagnoseStep: (_name, action) => action(),
        readTemplateState: async () => ({ readable: true, present: false }), codedError: code => fail(code),
        prependTemplate: async () => { writes++; }, replaceManagedTemplate: async () => { writes++; } });
    vm.runInContext(source.slice(start, end), context);
    const result = await context.insertTemplate(null, { replaceOnly: true });
    assert.equal(result.applied, false); assert.equal(result.reason, 'no-managed-template');
    assert.equal(reloads, 1); assert.equal(writes, 0);
    assert.match(source, /afterSaved:[\s\S]*insertTemplate\(null, \{ replaceOnly: true, expectedTarget: context.target, expectedAccount: context.account \}\)/);
});


test('wording refresh carries the click-time account and compose target, rather than a later active item', async () => {
    const { document } = parseHTML(`<section data-outlook-wording-region><input data-wording-field="greeting"><input data-wording-field="farewell">
        <button data-wording-toggle="showGreeting">Toggle</button><button data-wording-save>Save</button><button data-wording-reload>Reload</button><p data-wording-feedback></p></section>`);
    const target = { item: 'original-compose' }; let active = target; let finish; let applied;
    const view = bindWordingPreferencesView(document, { beforeSave: () => active,
        save: () => new Promise(resolve => { finish = resolve; }), reload: async () => {},
        afterSaved: async context => { applied = context; return 'Saved'; } });
    view.update({ account: own, preferences: wording(), available: true, busy: false });
    document.querySelector('[data-wording-toggle]').click(); await tick();
    active = { item: 'different-compose' };
    finish({ wordingPreferences: { ...wording('2'), showGreeting: true } }); await tick();
    assert.equal(applied.account, own); assert.equal(applied.target, target); assert.notEqual(applied.target, active);
});


test('422 explains the mobile wording limit for both saves and toggles without changing confirmed flags or drafts', async () => {
    const attempts = [];
    const h = wordingViewFixture(async change => { attempts.push(change); throw Object.assign(fail('HTTP_422'), { message: '<img src=x onerror=alert(1)>' }); });
    h.input.value = 'Unsaved personal draft,'; h.input.dispatchEvent(new h.window.Event('input'));
    const toggle = h.document.querySelector('[data-wording-toggle="showGreeting"]');
    toggle.click(); await tick();
    const feedback = h.document.querySelector('[data-wording-feedback]');
    assert.match(feedback.textContent, /mobile Signatur/); assert.match(feedback.textContent, /Texte kürzen/);
    assert.equal(feedback.getAttribute('role'), 'alert'); assert.equal(feedback.querySelector('img'), null);
    assert.equal(toggle.getAttribute('aria-pressed'), 'false'); assert.equal(toggle.disabled, false);
    assert.equal(h.input.value, 'Unsaved personal draft,'); assert.equal(h.save.disabled, false);
    h.save.click(); await tick();
    assert.match(feedback.textContent, /mobile Signatur/); assert.equal(h.input.value, 'Unsaved personal draft,');
    assert.equal(attempts.length, 2); assert.equal(attempts[1].revision, '1'.repeat(64));
    assert.equal(attempts[1].showGreeting, false); assert.equal(attempts[1].showFarewell, false);
});
