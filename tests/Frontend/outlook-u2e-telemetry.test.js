import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { universalComposeHost, universalFailurePayload, reportUniversalFailure } from '../../resources/js/outlook-addin/u2e-telemetry.js';
import { automaticComposeDecision } from '../../resources/js/outlook-addin/automatic-compose-policy.js';
import { u2eProbeDocument } from '../../resources/js/outlook-addin/u2e-editor-probe.js';
import { universalRedactedReadbackFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { universalTaskpaneStatus } from '../../resources/js/outlook-addin/u2e-taskpane-status.js';

const codeError = code => Object.assign(new Error('PRIVATE-MAIL-BODY'), { code, token: 'PRIVATE-TOKEN' });

test('operation telemetry keeps original and replacement codes with finite execution facts', () => {
    const report = { code: 'U2E_INSERT_UNCERTAIN', originalCode: 'U2E_NEW_DRAFT_REQUIRED', phase: 'readback',
        step: 'snapshot.region', bodyLength: 57355, bodyLengthOrigin: 'actual', composeType: 'reply' };
    const payload = universalFailurePayload(codeError(report.code), report);
    for (const key of ['code', 'originalCode', 'phase', 'step', 'bodyLength', 'bodyLengthOrigin', 'composeType']) assert.equal(payload[key], report[key]);
    const bad = universalFailurePayload(codeError(report.code), { ...report, originalCode: 'PRIVATE-ERROR',
        step: 'PRIVATE-STEP', bodyLengthOrigin: 'PRIVATE-LENGTH', composeType: 'PRIVATE-TYPE', automaticReason: 'PRIVATE-REASON' });
    assert.equal(bad.originalCode, 'UNAVAILABLE');
    assert.doesNotMatch(JSON.stringify(bad), /PRIVATE/);
    const stale = universalFailurePayload(codeError('U2E_SOURCE_INVALID'), report);
    for (const key of ['originalCode', 'step', 'bodyLengthOrigin', 'composeType']) assert.equal(stale[key], undefined);
});

test('automatic skipped telemetry is pilot-only and needs a finite reason', async t => {
    const f = environment(t);
    const args = { ...f.args, error: codeError('U2E_AUTOMATIC_SKIPPED'),
        report: { code: 'U2E_AUTOMATIC_SKIPPED', phase: 'preflight', automaticReason: 'compose_type_unknown', composeType: 'unknown' } };
    assert.equal(await reportUniversalFailure(args), false); assert.equal(f.calls.length, 0);
    const binding = { mailboxAddress: 'it-technik@rail-time.de', senderAddress: 'it-technik@rail-time.de' };
    f.office.context.mailbox.userProfile.emailAddress = binding.mailboxAddress;
    assert.equal(await reportUniversalFailure({ ...args, binding }), true);
    assert.equal(JSON.parse(f.calls[0][1].body).automaticReason, 'compose_type_unknown');
    assert.equal(await reportUniversalFailure({ ...args, binding, report: { ...args.report, automaticReason: 'private@example.test' } }), false);
    assert.equal(f.calls.length, 1);
});
const flush = async until => { for (let i = 0; i < 100 && !until(); i++) await Promise.resolve(); assert.equal(until(), true); };
const runtime = await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8');
const pane = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
function load(source, name, context) {
    const start = new RegExp(`^(?:export )?(?:async )?function ${name}\\(`, 'm').exec(source);
    assert.ok(start, `${name} exists`);
    const tail = source.slice(start.index).replace(/^export /, '');
    const end = /\r?\n(?:(?:export )?(?:async )?function [A-Za-z_$]|(?:let|const|class) [A-Za-z_$])/.exec(tail);
    return vm.runInNewContext(`(${end ? tail.slice(0, end.index) : tail}\n)`, context);
}

function environment(t, fetchImplementation = async () => ({ ok: true })) {
    const previous = { Office: globalThis.Office, fetch: globalThis.fetch };
    t.after(() => { globalThis.Office = previous.Office; globalThis.fetch = previous.fetch; });
    const calls = [];
    const item = { body: { getAsync() { throw new Error('Telemetry must not read mail'); } } };
    const office = { context: { platform: 'iOS', diagnostics: { hostName: 'Outlook' }, mailbox: {
        item, userProfile: { emailAddress: 'own@company.test' },
    } } };
    globalThis.Office = office;
    globalThis.fetch = (...args) => { calls.push(args); return fetchImplementation(...args); };
    const args = { config: { endpoints: { bootstrap: 'https://mail.example.test/api/outlook-addin/bootstrap',
        diagnostics: 'https://mail.example.test/api/outlook-addin/diagnostics' } },
    accessToken: 'synthetic-auth-token', item,
    binding: { mailboxAddress: 'own@company.test', senderAddress: 'alias@company.test' },
    context: 'event', error: codeError('SIGNATURE_LAYOUT_UNCONFIRMED'),
    report: { code: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', sourceLength: 13817, bodyLength: 35 }, isCurrent: () => true };
    return { office, item, args, calls };
}

test('failure payload contains only finite metadata and never error, body or identity values', t => {
    environment(t);
    const payload = universalFailurePayload(codeError('SIGNATURE_LAYOUT_UNCONFIRMED'), {
        code: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', sourceLength: 13817, bodyLength: 35,
        html: '<p>PRIVATE-MAIL-BODY</p>', mailbox: 'private@example.test', token: 'PRIVATE-TOKEN',
    });
    assert.deepEqual(payload, { schema: 1, host: 'OutlookIOS', code: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', sourceLength: 13817, bodyLength: 35 });
    assert.ok(Object.isFrozen(payload));
    assert.doesNotMatch(JSON.stringify(payload), /PRIVATE|private@example|<p>/);
    assert.equal(universalFailurePayload(null), null);
    assert.equal(universalFailurePayload(codeError('private@example.test')).code, 'U2E_OPERATION_FAILED');
    assert.equal(universalFailurePayload(codeError('UNKNOWN_REMOTE_MESSAGE')).code, 'U2E_OPERATION_FAILED');
});

test('host mapping and lengths remain finite even for malformed reports', t => {
    const f = environment(t);
    for (const [platform, host, expected] of [['iOS', 'Outlook', 'OutlookIOS'], ['Android', 'Outlook', 'OutlookAndroid'],
        ['OfficeOnline', 'OutlookWebApp', 'OutlookWebApp'], ['PC', 'Outlook', 'Outlook'], ['private@example.test', 'unknown', 'unknown']]) {
        f.office.context.platform = platform; f.office.context.diagnostics.hostName = host;
        assert.equal(universalComposeHost(), expected);
    }
    for (const bad of [-1, 1.5, '35', true, Number.MAX_SAFE_INTEGER, Infinity, NaN]) {
        const payload = universalFailurePayload(codeError('U2E_INSERT_UNCERTAIN'), { phase: 'private@example.test', sourceLength: bad, bodyLength: bad }, 200);
        assert.equal(payload.phase, 'preflight'); assert.equal(payload.sourceLength, 200); assert.equal(payload.bodyLength, null);
    }
});

test('host metadata supports the mailbox diagnostics surface and the context fallback', t => {
    const f = environment(t);
    f.office.context.platform = 'PC';
    f.office.context.mailbox.diagnostics = { hostName: 'OutlookWebApp' };
    f.office.context.diagnostics.hostName = 'Outlook';
    assert.equal(universalComposeHost(), 'OutlookWebApp');
    delete f.office.context.mailbox.diagnostics;
    assert.equal(universalComposeHost(), 'Outlook');
    f.office.context.platform = 'Android';
    f.office.context.mailbox.diagnostics = { hostName: 'OutlookWebApp' };
    assert.equal(universalComposeHost(), 'OutlookAndroid');
});

test('a previous operation report cannot supply the current failure phase or body length', t => {
    environment(t);
    const stale = universalFailurePayload(codeError('U2E_SOURCE_INVALID'), {
        code: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', sourceLength: 999, bodyLength: 888,
    }, 123);
    assert.equal(stale.phase, 'preflight'); assert.equal(stale.bodyLength, null); assert.equal(stale.sourceLength, 123);
    const current = universalFailurePayload(codeError('SIGNATURE_LAYOUT_UNCONFIRMED'), {
        code: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', sourceLength: 999, bodyLength: 888,
    }, 123);
    assert.equal(current.phase, 'readback'); assert.equal(current.bodyLength, 888); assert.equal(current.sourceLength, 123);
});

test('checkpoint and difference are finite and stale or raw evidence never enters telemetry', t => {
    environment(t);
    const report = { code: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', checkpoint: 'evidence.text',
        difference: { key: 'evidence.text.sha256', index: null, expected: 'sha256:' + 'a'.repeat(64) + ':13', actual: 'sha256:' + 'b'.repeat(64) + ':14' } };
    const payload = universalFailurePayload(codeError(report.code), report);
    assert.equal(payload.checkpoint, report.checkpoint);
    assert.deepEqual(payload.difference, report.difference);
    for (const bad of [{ checkpoint: 'PRIVATE-BODY' }, { difference: { ...report.difference, actual: 'private@example.test' } },
        { difference: { ...report.difference, index: 4096 } }, { difference: { ...report.difference, actual: 1.5 } }]) {
        const safe = universalFailurePayload(codeError(report.code), { ...report, ...bad });
        assert.doesNotMatch(JSON.stringify(safe), /PRIVATE-BODY|private@example/);
        if (Object.hasOwn(bad, 'difference')) assert.equal(safe.difference, undefined);
    }
    const stale = universalFailurePayload(codeError('U2E_SOURCE_INVALID'), report);
    assert.equal(stale.checkpoint, undefined); assert.equal(stale.difference, undefined);
});

test('only the pilot sends a validated signature pair and no raw fixture is forwarded', async t => {
    const f = environment(t);
    const source = u2eProbeDocument().html;
    const fixture = universalRedactedReadbackFixture(source, '<p>PRIVATE-CURRENT</p>' + source);
    assert.ok(fixture);
    const report = { ...f.args.report, checkpoint: 'images.src', fixture };
    assert.equal(await reportUniversalFailure({ ...f.args, report }), true);
    assert.equal(JSON.parse(f.calls[0][1].body).fixture, undefined);
    f.office.context.mailbox.userProfile.emailAddress = 'it-technik@rail-time.de';
    const binding = { mailboxAddress: 'it-technik@rail-time.de', senderAddress: 'it-technik@rail-time.de' };
    const pilotItem = { ...f.args.item };
    f.office.context.mailbox.item = pilotItem;
    assert.equal(await reportUniversalFailure({ ...f.args, item: pilotItem, report, binding }), true);
    assert.deepEqual(JSON.parse(f.calls[1][1].body).fixture, fixture);
    assert.equal(f.calls[1][1].keepalive, true);
    assert.doesNotMatch(f.calls[1][1].body, /PRIVATE-CURRENT|mailto:|https:\/\/|private@example/);
    const unsafe = { ...fixture, html: '<p>PRIVATE-BODY</p>' };
    const anotherItem = { ...f.args.item };
    f.office.context.mailbox.item = anotherItem;
    assert.equal(await reportUniversalFailure({ ...f.args, item: anotherItem, report: { ...report, fixture: unsafe }, binding }), true);
    assert.equal(JSON.parse(f.calls[2][1].body).fixture, undefined);
    assert.equal(JSON.parse(f.calls[2][1].body).checkpoint, 'images.src');
});

test('a bounded large host-style fixture disables keepalive and retains its comparison pair', async t => {
    const f = environment(t);
    f.office.context.mailbox.userProfile.emailAddress = 'it-technik@rail-time.de';
    const source = u2eProbeDocument().html;
    const expanded = source.replace(/style="/g, 'style="' + 'margin:0;'.repeat(100));
    const fixture = universalRedactedReadbackFixture(source, expanded);
    assert.ok(fixture);
    const report = { ...f.args.report, checkpoint: 'evidence.topology', fixture };
    const binding = { mailboxAddress: 'it-technik@rail-time.de', senderAddress: 'it-technik@rail-time.de' };
    assert.equal(await reportUniversalFailure({ ...f.args, report, binding }), true);
    assert.ok(f.calls[0][1].body.length > 60000);
    assert.ok(f.calls[0][1].body.length <= 252000);
    assert.equal(f.calls[0][1].keepalive, false);
    assert.deepEqual(JSON.parse(f.calls[0][1].body).fixture, fixture);
});

test('authenticated reporting posts one bounded body to the bootstrap origin with the existing binding', async t => {
    const f = environment(t);
    assert.equal(await reportUniversalFailure(f.args), true);
    assert.equal(f.calls.length, 1);
    const [url, options] = f.calls[0];
    assert.equal(url, f.args.config.endpoints.diagnostics);
    assert.equal(options.method, 'POST'); assert.equal(options.credentials, 'omit'); assert.equal(options.cache, 'no-store');
    assert.equal(options.headers.Authorization, 'Bearer synthetic-auth-token');
    assert.equal(options.headers['X-RailTime-Outlook-Mailbox'], f.args.binding.mailboxAddress);
    assert.equal(options.headers['X-RailTime-Outlook-Sender'], f.args.binding.senderAddress);
    assert.equal(options.headers['X-RailTime-Compose-Contract'], 'native-signature-v1');
    assert.equal(options.headers['X-RailTime-Outlook-Context'], 'event');
    assert.deepEqual(Object.keys(JSON.parse(options.body)).sort(), ['schema', 'host', 'code', 'phase', 'sourceLength', 'bodyLength'].sort());
    assert.doesNotMatch(options.body, /synthetic-auth-token|company\.test|PRIVATE|<p>/);
});

test('missing authorization, invalid origin, stale item or inactive lifecycle never starts a request', async t => {
    const f = environment(t);
    const changes = [
        { accessToken: '' }, { accessToken: null }, { accessToken: 'x'.repeat(32769) }, { context: 'account-settings' },
        { error: null }, { item: {} }, { isCurrent: () => false }, { isCurrent: () => { throw new Error('stale'); } },
        { config: {} }, { config: { endpoints: { ...f.args.config.endpoints, diagnostics: 'http://mail.example.test/diagnostics' } } },
        { config: { endpoints: { ...f.args.config.endpoints, diagnostics: 'https://foreign.example.test/diagnostics' } } },
    ];
    for (const change of changes) assert.equal(await reportUniversalFailure({ ...f.args, ...change }), false);
    f.office.context.mailbox.userProfile.emailAddress = 'other@company.test';
    assert.equal(await reportUniversalFailure(f.args), false);
    assert.equal(f.calls.length, 0);
});

test('a compose switch between admission and the scheduled fetch prevents stale telemetry', async t => {
    const f = environment(t);
    const result = reportUniversalFailure(f.args);
    f.office.context.mailbox.item = {};
    assert.equal(await result, false);
    assert.equal(f.calls.length, 0);
});

test('an unconfirmed network outcome never replaces the compose error or retries the same diagnostic', async t => {
    let response = () => Promise.reject(new Error('PRIVATE-NETWORK-DETAIL'));
    const f = environment(t, (...args) => response(...args));
    assert.equal(await reportUniversalFailure(f.args), false);
    response = async () => ({ ok: false, status: 403 });
    assert.equal(await reportUniversalFailure(f.args), false);
    response = () => { throw new Error('PRIVATE-NETWORK-DETAIL'); };
    assert.equal(await reportUniversalFailure(f.args), false);
    assert.equal(f.args.error.code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(f.calls.length, 1);
});

test('new drafts still isolate HTTP refusal and a synchronous network error from compose failure', async t => {
    let response = async () => ({ ok: false, status: 403 });
    const f = environment(t, (...args) => response(...args));
    assert.equal(await reportUniversalFailure(f.args), false);
    const next = { ...f.args.item };
    f.office.context.mailbox.item = next;
    response = () => { throw new Error('PRIVATE-NETWORK-DETAIL'); };
    assert.equal(await reportUniversalFailure({ ...f.args, item: next }), false);
    assert.equal(f.args.error.code, 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(f.calls.length, 2);
});

test('a pending draft sends only a hashed diagnostic identity and repeated clicks send once', async t => {
    const f = environment(t), claim = 'pending:' + 'a'.repeat(24) + ':' + 'b'.repeat(32);
    const values = new Map([['railtime.u2e.compose.v1', claim]]);
    f.office.AsyncResultStatus = { Succeeded: 'ok' };
    f.args.item.sessionData = {
        getAsync: (key, cb) => cb({ status: 'ok', value: values.get(key) }),
        setAsync: (key, value, cb) => { values.set(key, value); cb({ status: 'ok' }); },
    };
    assert.equal(await reportUniversalFailure(f.args), true);
    assert.match(JSON.parse(f.calls[0][1].body).diagnosticId, /^[0-9a-f]{64}$/);
    assert.doesNotMatch(f.calls[0][1].body, /pending:|bbbbbbbb|own@company|synthetic-auth/);
    for (let attempt = 0; attempt < 4; attempt++) assert.equal(await reportUniversalFailure(f.args), false);
    assert.equal(f.calls.length, 1);
    assert.equal(values.get('railtime.u2e.compose.v1'), claim);
});

test('a hanging request stops after two seconds and a late response never starts a retry', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let finish;
    const f = environment(t, () => new Promise(resolve => { finish = resolve; }));
    const pending = reportUniversalFailure(f.args);
    await flush(() => f.calls.length === 1);
    t.mock.timers.tick(2001);
    assert.equal(await pending, false);
    assert.equal(f.calls[0][1].signal.aborted, true);
    finish({ ok: true }); await Promise.resolve();
    assert.equal(f.calls.length, 1);
});

test('the event lifecycle completes once while actual failure telemetry remains pending', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const f = environment(t, () => new Promise(() => {}));
    const error = f.args.error;
    const id = '11111111-1111-4111-8111-111111111111';
    const source = { kind: 'combined', html: 'source-only' };
    const payload = { binding: f.args.binding, automaticInsertionEnabled: true,
        automaticInsertion: { schema: 2, composeType: 'newMail', signature: true, template: true },
        automaticTemplateChoice: { schema: 1, composeType: 'newMail', selection: 'default', templateId: id },
        automaticTemplateId: id, templates: [{ id, isDefault: true, universalCompose: source }] };
    const calls = { completed: 0, writes: 0, failure: null };
    let telemetry;
    const context = {
        Office: f.office, Date, console: { info() {} }, LOG_PREFIX: 'synthetic',
        composeOperations: new WeakMap(), pendingNativeSignatures: new WeakMap(),
        codedError: codeError, safeErrorCode: value => value.code, reportRuntimePhase() {}, recordDiagnostic() {},
        diagnoseStep: async (phase, action) => action(), loadConfig: async () => f.args.config,
        acquireTokenSilently: async () => f.args.accessToken, withDeadline: action => action(),
        AUTH_TIMEOUT_MS: 15000, MOBILE_EVENT_TIMEOUT_MS: 55000,
        readAutomaticComposeType: async () => 'newMail', loadBootstrap: async () => payload,
        assertMailboxBinding: async () => {}, automaticComposeDecision,
        automaticTemplate: value => value.templates[0], isTemplateInsertionBlocked: () => false,
        isStandaloneSignatureInsertionBlocked: () => false, hasUncertainWrite: () => false, universalComposeBlocked: () => false,
        validateUniversalComposeDocument: value => value,
        insertUniversalComposeDocument: async () => { calls.writes++; throw error; },
        universalComposeReport: () => f.args.report,
        reportUniversalFailure: args => { telemetry = reportUniversalFailure(args); return telemetry; },
        composeItemWhenReady: async () => f.item, isMobileComposeHost: () => true,
        notifyMobileFailure: async (item, cause) => { calls.failure = cause; },
    };
    context.completeOnce = load(runtime, 'completeOnce', context);
    context.applyPublishedContent = load(runtime, 'applyPublishedContent', context);
    const handle = load(runtime, 'handleComposeEvent', context);
    await handle({ completed() { calls.completed++; } });
    assert.equal(calls.completed, 1); assert.equal(calls.writes, 1); assert.equal(calls.failure, error);
    assert.equal(f.calls.length, 1);
    t.mock.timers.tick(2001);
    assert.equal(await telemetry, false);
    assert.equal(calls.completed, 1); assert.equal(calls.writes, 1);
});

test('manual lifecycle reuses its existing token and releases failure handling without awaiting telemetry', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const f = environment(t, () => new Promise(() => {}));
    const target = { item: f.item, revision: 0 };
    const state = { settingsMode: false, busy: false };
    const calls = { auth: 0, failure: null, attempts: 0 };
    let telemetry;
    const context = {
        Office: f.office, taskpaneState: state, bootstrapStateRevision: 0, signatureStateRevision: 0, mailboxItemRevision: 0,
        universalStatusByItem: new WeakMap(), universalTaskpaneStatus, safeErrorCode: error => error?.code,
        currentConfig: f.args.config, lastActionError: null, codedError: codeError,
        setBusy: busy => { state.busy = busy; }, captureComposeTarget: () => target,
        isTemplateInsertionBlocked: () => false, isStandaloneSignatureInsertionBlocked: () => false,
        hasUncertainWrite: () => false, u2eTransportProbeReport: () => null, universalComposeBlocked: () => false,
        diagnoseStep: async (phase, action) => action(),
        acquireAccessToken: async () => { calls.auth++; return f.args.accessToken; }, displayAccount() {},
        loadBootstrap: async () => ({ binding: f.args.binding }), assertWriteTarget: async () => {}, acceptBootstrap: async () => {},
        universalComposeReport: () => f.args.report, assertComposeTarget: value => value.item,
        reportUniversalFailure: args => { telemetry = reportUniversalFailure(args); return telemetry; },
        handleFailure: cause => { calls.failure = cause; state.busy = false; }, syncActionState() {},
    };
    const action = load(pane, 'withAuthenticatedBootstrap', context);
    await action({}, async (bootstrap, selected, universalOperation) => universalOperation({ html: 'synthetic source' }, async () => {
        calls.attempts++; throw f.args.error;
    }));
    assert.equal(calls.auth, 1); assert.equal(calls.failure, f.args.error); assert.equal(state.busy, false);
    await flush(() => f.calls.length === 1);
    assert.equal(calls.attempts, 1); assert.equal(f.calls.length, 1);
    assert.equal(f.calls[0][1].headers['X-RailTime-Outlook-Context'], 'taskpane');
    t.mock.timers.tick(2001); assert.equal(await telemetry, false);
    assert.equal(calls.attempts, 1); assert.equal(calls.auth, 1);
});
