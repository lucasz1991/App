import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';
import * as preferencesView from '../../resources/js/outlook-addin/automatic-preferences-view.js';

const own = 'employee@company.test';
const preferences = (digit = '1', values = {}) => ({ schema: 2, revision: digit.repeat(64),
    newMessages: { signature: true, template: false }, replies: { signature: false, template: true }, ...values });
const payload = (digit = '1') => ({ automaticInsertionPreferences: preferences(digit),
    binding: { schema: 1, mailboxAddress: own, senderAddress: own, allowedSenderAddresses: [own] } });
const fail = code => Object.assign(new Error(code), { code });
const tick = async () => { for (let i = 0; i < 80; i++) await Promise.resolve(); };

function sessionFixture() {
    const state = { mailbox: own, requests: [], authenticate: [], response: payload() };
    const session = preferencesView.createAccountPreferencesSession({
        readMailbox: () => state.mailbox,
        authenticate: async allowPopup => { state.authenticate.push(allowPopup); return 'synthetic-not-a-token'; },
        request: async options => { state.requests.push(options); return state.response; },
    });
    return { state, session };
}

test('account session uses only its own mailbox, four schema2 choices and exact account-settings API context', async () => {
    const h = sessionFixture(); await h.session.load();
    assert.equal(h.session.current().account, own);
    assert.deepEqual(h.state.authenticate, [false]);
    const get = h.state.requests[0];
    assert.equal(get.method, 'GET'); assert.equal(get.credentials, 'omit');
    assert.equal(get.headers['X-RailTime-Outlook-Context'], 'account-settings');
    assert.equal(get.headers['X-RailTime-Outlook-Mailbox'], own);
    assert.equal(get.headers['X-RailTime-Outlook-Sender'], undefined);
    const change = { group: 'replies', field: 'template', enabled: false, revision: '1'.repeat(64), untrusted: 'ignored' };
    h.state.response = payload('2'); await h.session.save(change);
    assert.deepEqual(JSON.parse(h.state.requests[1].body), { group: 'replies', field: 'template', enabled: false, revision: '1'.repeat(64) });
    assert.equal(h.session.current().preferences.revision, '2'.repeat(64));
});

test('settings cannot save before a verified load, with stale revision, unknown fields or assumed defaults', async () => {
    const h = sessionFixture();
    const change = { group: 'newMessages', field: 'signature', enabled: true, revision: '1'.repeat(64) };
    await assert.rejects(h.session.save(change), { code: 'PREFERENCES_UNAVAILABLE' });
    await h.session.load();
    for (const bad of [{ ...change, group: 'mobile' }, { ...change, field: 'other' }, { ...change, enabled: 'true' }, { ...change, revision: '2'.repeat(64) }]) {
        await assert.rejects(h.session.save(bad), { code: 'PREFERENCES_UNAVAILABLE' });
    }
    assert.equal(h.state.requests.length, 1);
});

test('foreign binding, partial preferences and a received sender presented as owner fail closed', async () => {
    for (const response of [{ ...payload(), binding: { ...payload().binding, senderAddress: 'sender@outside.test' } },
        { ...payload(), binding: { ...payload().binding, mailboxAddress: 'other@company.test' } },
        { ...payload(), binding: { ...payload().binding, allowedSenderAddresses: [] } },
        { ...payload(), automaticInsertionPreferences: { ...preferences(), replies: {} } }]) {
        const h = sessionFixture(); h.state.response = response;
        await assert.rejects(h.session.load(), { code: 'ACCOUNT_PREFERENCES_BINDING_INVALID' });
        assert.equal(h.session.current(), null);
    }
});

test('mailbox change during authentication prevents the request; a late old-account receipt is discarded', async () => {
    let account = own; let finish;
    let requests = 0;
    const session = preferencesView.createAccountPreferencesSession({ readMailbox: () => account,
        authenticate: () => new Promise(resolve => { finish = resolve; }), request: async () => { requests++; return payload(); } });
    const pending = session.load(); account = 'other@company.test'; finish('token');
    await assert.rejects(pending, { code: 'MAILBOX_MISMATCH' }); assert.equal(requests, 0);
    account = own;
    const late = preferencesView.createAccountPreferencesSession({ readMailbox: () => account,
        authenticate: async () => 'token', request: () => new Promise(resolve => { finish = resolve; }) });
    const read = late.load(); await tick(); account = 'other@company.test'; finish(payload());
    await assert.rejects(read, { code: 'MAILBOX_MISMATCH' }); assert.equal(late.current(), null);
});

test('conflicting revision returns fresh persisted choices; unauthorized receipt clears ownership', async () => {
    let error;
    const session = preferencesView.createAccountPreferencesSession({ readMailbox: () => own,
        authenticate: async () => 'token', request: async () => { if (error) throw error; return payload(); } });
    await session.load();
    error = Object.assign(fail('HTTP_409'), { currentPreferences: preferences('3') });
    await assert.rejects(session.save({ group: 'replies', field: 'signature', enabled: true, revision: '1'.repeat(64) }), { code: 'HTTP_409' });
    assert.equal(session.current().preferences.revision, '3'.repeat(64));
    error = fail('HTTP_403'); await assert.rejects(session.load(), { code: 'HTTP_403' });
    assert.equal(session.current(), null);
});

test('a new mailbox cannot reuse a previously loaded account revision', async () => {
    const h = sessionFixture(); await h.session.load(); h.state.mailbox = 'other@company.test';
    await assert.rejects(h.session.save({ group: 'replies', field: 'signature', enabled: true, revision: '1'.repeat(64) }), { code: 'MAILBOX_MISMATCH' });
    assert.equal(h.session.current(), null); assert.equal(h.state.requests.length, 1);
});

test('one request owns the settings session and a reset quarantines its late receipt', async () => {
    let finish; let requests = 0;
    const session = preferencesView.createAccountPreferencesSession({ readMailbox: () => own, authenticate: async () => 'token',
        request: () => { requests++; return new Promise(resolve => { finish = resolve; }); } });
    const pending = session.load(); await tick();
    await assert.rejects(session.load(), { code: 'PREFERENCES_BUSY' });
    session.reset(); finish(payload());
    await assert.rejects(pending, { code: 'MAILBOX_MISMATCH' });
    assert.equal(session.current(), null); assert.equal(requests, 1);
});

const source = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
async function settingsHarness({ outside = false, interaction = false, queryMode = false } = {}) {
    const { document, window } = parseHTML(`<html><head><meta name="railtime-outlook-config-url" content="https://app.company.test/outlook-addin/config.json"></head><body>
        <main data-outlook-addin-taskpane data-outlook-context="${queryMode ? 'compose' : 'settings'}" aria-busy="true">
        <p data-outlook-settings-status></p><button data-outlook-settings-connect disabled>Connect</button>
        <a data-outlook-profile-settings href="https://app.company.test/user/profile?tab=outlook">Profile</a>
        <section data-outlook-automatic-region><p data-automatic-account></p><p data-automatic-feedback></p>
        ${['newMessages', 'replies'].flatMap(group => ['signature', 'template'].map(field => `<input disabled type="checkbox" data-automatic-setting data-automatic-group="${group}" data-automatic-field="${field}">`)).join('')}
        </section></main></body></html>`);
    const state = { requests: [], silent: 0, popup: 0, messageTouches: 0 };
    const received = new Proxy({}, { get() { state.messageTouches++; throw Error('Selected message must stay opaque'); } });
    const mailbox = { userProfile: { emailAddress: own }, get item() { state.messageTouches++; return received; } };
    const office = { HostType: { Outlook: 'Outlook' }, context: { mailbox, requirements: { isSetSupported: () => true } },
        onReady(callback) { callback({ host: 'Outlook' }); } };
    class InteractionRequiredAuthError extends Error {}
    const config = { ready: true, marker: 'RT-SIGNATURE-MANAGED-V1', auth: { clientId: 'synthetic-client',
        authority: 'https://login.microsoftonline.com/synthetic-tenant', scopes: ['api://test/identity'] },
        endpoints: { bootstrap: 'https://app.company.test/api/outlook-addin/bootstrap',
            accountPreferences: 'https://app.company.test/api/outlook-addin/account-preferences' },
        urls: { profileSettings: 'https://app.company.test/user/profile?tab=outlook' } };
    const context = vm.createContext({ ...preferencesView, document, window, Office: outside ? undefined : office,
        URL, URLSearchParams, AbortController, setTimeout, clearTimeout, console,
        location: { search: queryMode ? '?context=settings' : '' }, InteractionRequiredAuthError,
        createNestablePublicClientApplication: async () => ({ getAllAccounts: () => [],
            acquireTokenSilent: async () => { state.silent++; if (interaction && !state.popup) throw new InteractionRequiredAuthError(); return { accessToken: 'synthetic-token' }; },
            acquireTokenPopup: async () => { state.popup++; return { accessToken: 'synthetic-token' }; } }),
        fetch: async (url, options) => {
            state.requests.push({ url, options });
            if (String(url).includes('/bootstrap')) throw Error('No compose bootstrap in account settings');
            return { ok: true, json: async () => String(url).includes('config.json') ? config : payload(options?.method === 'PATCH' ? '2' : '1') };
        },
    });
    vm.runInContext(source.replace(/^import\s[\s\S]*?\sfrom\s['"][^'"]+['"];\r?$/gm, '').replace(/^export /gm, ''), context);
    await tick();
    return { state, context, document, window, mailbox };
}

test('actual settings startup accepts a read item, never touches its sender/body and saves through own-account API', async () => {
    const h = await settingsHarness();
    const inputs = [...h.document.querySelectorAll('input')];
    assert.ok(inputs.every(input => !input.disabled));
    assert.deepEqual(inputs.map(input => input.checked), [true, false, false, true]);
    assert.equal(h.state.messageTouches, 0);
    assert.deepEqual(h.state.requests.map(value => value.url), ['https://app.company.test/outlook-addin/config.json', 'https://app.company.test/api/outlook-addin/account-preferences']);
    inputs[1].checked = true; inputs[1].dispatchEvent(new h.window.Event('change')); await tick();
    const request = h.state.requests.at(-1);
    assert.equal(request.options.method, 'PATCH');
    assert.equal(request.options.headers['X-RailTime-Outlook-Sender'], undefined);
    assert.equal(h.state.messageTouches, 0);
    assert.throws(() => h.context.captureComposeTarget(), { code: 'ACCOUNT_SETTINGS_READ_ONLY' });
    await assert.rejects(h.context.loadBootstrap({}, 'token'), { code: 'ACCOUNT_SETTINGS_READ_ONLY' });
});

test('settings query is a hard read guard even with compose-marked DOM; outside Outlook remains link-only', async () => {
    const h = await settingsHarness({ queryMode: true });
    assert.equal(h.state.messageTouches, 0);
    assert.throws(() => h.context.bindActions(), { code: 'ACCOUNT_SETTINGS_READ_ONLY' });
    const outside = await settingsHarness({ outside: true });
    assert.equal(outside.state.requests.length, 0);
    assert.ok([...outside.document.querySelectorAll('input')].every(input => input.disabled));
    assert.match(outside.document.querySelector('[data-outlook-settings-status]').textContent, /RailTime/);
});

test('read-settings explicit login recovers silent-auth interaction without ever binding compose actions', async () => {
    const h = await settingsHarness({ interaction: true });
    assert.ok([...h.document.querySelectorAll('input')].every(input => input.disabled));
    assert.equal(h.state.popup, 0);
    h.document.querySelector('[data-outlook-settings-connect]').click(); await tick();
    assert.equal(h.state.popup, 1);
    assert.ok([...h.document.querySelectorAll('input')].every(input => !input.disabled));
    assert.equal(h.state.messageTouches, 0);
});

test('Blade has one inline four-switch region and settings-only markup excludes message actions', async () => {
    const blade = await readFile(new URL('../../resources/views/outlook-addin/taskpane.blade.php', import.meta.url), 'utf8');
    assert.equal((blade.match(/data-outlook-automatic-region/g) || []).length, 1);
    assert.equal((blade.match(/data-automatic-setting/g) || []).length, 4);
    assert.doesNotMatch(blade, /data-outlook-dialog(?:-open)?="automatic"/);
    const settings = blade.slice(blade.indexOf('@if ($accountSettingsMode)', blade.indexOf('<main')), blade.indexOf('@else', blade.indexOf('<main')));
    assert.match(settings, /data-outlook-settings-connect/);
    assert.doesNotMatch(settings, /data-outlook-action|data-outlook-template-region|data-outlook-diagnostics/);
    assert.match(blade, /Firmenkonto auf allen Geräten/);
});
