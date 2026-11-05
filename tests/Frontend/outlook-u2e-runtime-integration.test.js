import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { reportUniversalFailure } from '../../resources/js/outlook-addin/u2e-telemetry.js';
import { automaticComposeDecision } from '../../resources/js/outlook-addin/automatic-compose-policy.js';
import * as bundleVersion from '../../resources/js/outlook-addin/u2e-bundle-version.js';

const runtime = await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8');
const pane = await readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
const functionSource = (source, name) => {
    const start = new RegExp(`^(?:export )?(?:async )?function ${name}\\(`, 'm').exec(source);
    assert.ok(start, `${name} exists`);
    const tail = source.slice(start.index).replace(/^export /, '');
    const next = /\r?\n(?:export )?(?:async )?function [A-Za-z_$]/.exec(tail);
    return next ? tail.slice(0, next.index) : tail;
};
const load = (source, name, context) => vm.runInNewContext(`(${functionSource(source, name)}\n)`, context);
const codeError = code => Object.assign(new Error(code), { code });
const id = '11111111-1111-4111-8111-111111111111';
const signature = { kind: 'signature', version: 'a'.repeat(24), html: 'signature-source' };
const combined = { kind: 'combined', version: 'b'.repeat(24), html: 'combined-source' };

function automaticFixture(mode = 'both', extra = {}) {
    const item = {};
    const entry = { id, key: id, isDefault: true, universalCompose: combined };
    const signatureEnabled = ['signature', 'both'].includes(mode);
    const templateEnabled = ['template', 'both'].includes(mode);
    const payload = {
        binding: { schema: 1 }, automaticInsertionEnabled: mode !== 'none',
        automaticInsertion: { schema: 2, composeType: 'newMail', signature: signatureEnabled, template: templateEnabled },
        automaticTemplateChoice: { schema: 1, composeType: 'newMail', selection: templateEnabled ? 'default' : 'none', templateId: templateEnabled ? id : null },
        automaticTemplateId: id, templates: [entry], universalSignature: signature,
    };
    const calls = { universal: [], validate: [], legacy: 0, binding: 0, deleted: 0 };
    const context = {
        ...bundleVersion,
        Office: { context: { mailbox: { item } } },
        codedError: codeError,
        reportRuntimePhase() {},
        diagnoseStep: async (phase, action) => action(),
        loadConfig: async () => ({}),
        acquireTokenSilently: async () => 'synthetic-token',
        withDeadline: action => action(), AUTH_TIMEOUT_MS: 15000,
        readAutomaticComposeType: async () => 'newMail',
        loadBootstrap: async () => payload,
        assertMailboxBinding: async () => { calls.binding++; },
        pendingNativeSignatures: { delete() { calls.deleted++; } },
        recordDiagnostic() {},
        automaticComposeDecision,
        automaticTemplate: value => value.templates.find(value => value.isDefault === true),
        isTemplateInsertionBlocked: () => false,
        isStandaloneSignatureInsertionBlocked: () => false,
        hasUncertainWrite: () => false,
        universalComposeBlocked: () => false,
        universalComposeReport: () => null,
        reportUniversalFailure,
        validateUniversalComposeDocument: document => { calls.validate.push(document); if (!document || typeof document !== 'object') throw codeError('U2E_DOCUMENT_INVALID'); return document; },
        insertUniversalComposeDocument: async (office, target, document, options) => {
            calls.universal.push({ office, target, document, options });
            await options.beforeWrite();
            return { status: 'applied' };
        },
        readTemplateState: async () => { calls.legacy++; throw codeError('LEGACY_ENTRY'); },
        ...extra,
    };
    return { item, entry, payload, calls, context, apply: load(runtime, 'applyPublishedContent', context) };
}

test('automatic combined U2e dispatch happens before all legacy body/layout writers', async () => {
    const f = automaticFixture();
    assert.equal(await f.apply(f.item), 'applied');
    assert.equal(f.calls.universal.length, 1);
    assert.equal(f.calls.universal[0].document, combined);
    assert.equal(f.calls.universal[0].target, f.item);
    assert.equal(f.calls.universal[0].options.binding, f.payload.binding);
    assert.equal(f.calls.legacy, 0);
    assert.ok(f.calls.binding >= 2);
});

test('signature-only automatic policy chooses the independent signature envelope', async () => {
    const f = automaticFixture('signature');
    assert.equal(await f.apply(f.item), 'applied');
    assert.equal(f.calls.universal.length, 1);
    assert.equal(f.calls.universal[0].document, signature);
    assert.equal(f.calls.legacy, 0);
});

test('a template-only policy never inserts the combined U2e signature', async () => {
    const f = automaticFixture('template');
    await assert.rejects(f.apply(f.item), { code: 'LEGACY_ENTRY' });
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.validate.length, 0);
    assert.equal(f.calls.legacy, 1);
});

test('disabled automatic policy does not read or validate any new body source', async () => {
    const f = automaticFixture('none');
    assert.equal(await f.apply(f.item), 'disabled');
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.validate.length, 0);
    assert.equal(f.calls.legacy, 0);
});

test('noneligible selected templates retain the existing writer path', async () => {
    const f = automaticFixture();
    delete f.entry.universalCompose;
    await assert.rejects(f.apply(f.item), { code: 'LEGACY_ENTRY' });
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.legacy, 1);
});

test('a malformed new envelope cannot fall back to an old writer', async () => {
    const f = automaticFixture();
    f.entry.universalCompose = null;
    await assert.rejects(f.apply(f.item), { code: 'U2E_DOCUMENT_INVALID' });
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.legacy, 0);
});

test('a proven incompatible bundle stops the actual automatic lifecycle before validation and every writer', async () => {
    const f = automaticFixture('both');
    const store = new Map([['railtime.u2e.compose.v1', 'original-ledger']]);
    f.context.Office.AsyncResultStatus = { Succeeded: 'succeeded' };
    f.item.sessionData = {
        getAsync(key, callback) { callback({ status: 'succeeded', value: store.get(key) || '' }); },
        setAsync(key, value, callback) { store.set(key, value); callback({ status: 'succeeded' }); },
    };
    f.payload.addinBuild = { schema: 1, revision: 'a'.repeat(64), sourceContract: 2,
        sourceTemplate: bundleVersion.U2E_SOURCE_TEMPLATE, bundles: { runtime: 'b'.repeat(64), taskpane: 'c'.repeat(64) } };
    await assert.rejects(f.apply(f.item), { code: 'U2E_BUNDLE_STALE' });
    assert.equal(f.calls.universal.length, 0); assert.equal(f.calls.validate.length, 0); assert.equal(f.calls.legacy, 0);
    assert.equal(JSON.parse(store.get(bundleVersion.U2E_BUNDLE_SESSION_KEY)).code, 'U2E_BUNDLE_STALE');
    assert.equal(store.get('railtime.u2e.compose.v1'), 'original-ledger');
});

test('a compatible fresh event clears only the bundle diagnostic while a real source failure retains its original code', async () => {
    const f = automaticFixture('both', { validateUniversalComposeDocument() { throw codeError('U2E_SOURCE_INVALID'); } });
    const store = new Map([[bundleVersion.U2E_BUNDLE_SESSION_KEY, JSON.stringify({ schema: 1, code: 'U2E_BUNDLE_STALE',
        at: '2026-11-04T08:54:09.000Z', sourceContract: 2, readerContract: 1 })], ['railtime.u2e.compose.v1', 'original-ledger']]);
    f.context.Office.AsyncResultStatus = { Succeeded: 'succeeded' };
    f.item.sessionData = {
        getAsync(key, callback) { callback({ status: 'succeeded', value: store.get(key) || '' }); },
        setAsync(key, value, callback) { store.set(key, value); callback({ status: 'succeeded' }); },
    };
    f.payload.addinBuild = { schema: 1, revision: 'a'.repeat(64), sourceContract: 1,
        sourceTemplate: bundleVersion.U2E_SOURCE_TEMPLATE, bundles: { runtime: 'b'.repeat(64), taskpane: 'c'.repeat(64) } };
    await assert.rejects(f.apply(f.item), { code: 'U2E_SOURCE_INVALID' });
    assert.equal(store.get(bundleVersion.U2E_BUNDLE_SESSION_KEY), '');
    assert.equal(store.get('railtime.u2e.compose.v1'), 'original-ledger');
    assert.equal(f.calls.universal.length, 0); assert.equal(f.calls.legacy, 0);
});

test('a kind mismatch cannot activate the combined or signature-only operation', async () => {
    for (const mode of ['both', 'signature']) {
        const f = automaticFixture(mode);
        if (mode === 'both') f.entry.universalCompose = signature;
        else f.payload.universalSignature = combined;
        await assert.rejects(f.apply(f.item), { code: 'U2E_DOCUMENT_INVALID' });
        assert.equal(f.calls.universal.length, 0);
        assert.equal(f.calls.legacy, 0);
    }
});

test('existing uncertain U2e operations block every later writer', async () => {
    const f = automaticFixture('both', { universalComposeBlocked: () => true });
    assert.equal(await f.apply(f.item), 'uncertain');
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.legacy, 0);
});

test('a U2e new-draft requirement propagates without trying a V17 fallback', async () => {
    const f = automaticFixture('both', { insertUniversalComposeDocument: async () => { throw codeError('U2E_NEW_DRAFT_REQUIRED'); } });
    await assert.rejects(f.apply(f.item), { code: 'U2E_NEW_DRAFT_REQUIRED' });
    assert.equal(f.calls.legacy, 0);
});

function paneFixture(extra = {}) {
    const item = {};
    const target = { item };
    const document = { universalCompose: combined, universalSignature: signature };
    const choice = { name: 'Synthetische Vorlage', document };
    const bootstrap = { binding: { schema: 1 }, universalSignature: signature };
    const calls = { universal: [], remembered: [], legacy: 0, states: [], refresh: 0 };
    const state = { busy: false, templatePresent: false, signatureCurrent: false, signatureVersion: '' };
    const context = {
        ...bundleVersion,
        taskpaneState: state,
        setStatus: (...args) => calls.states.push(args),
        withAuthenticatedBootstrap: async (button, action) => action(bootstrap, target, (_source, operation) => operation()),
        assertComposeTarget: value => value.item,
        assertWriteTarget: async value => value.item,
        selectedTemplate: () => choice,
        validateUniversalComposeDocument: value => { if (!value) throw codeError('U2E_DOCUMENT_INVALID'); return value; },
        insertUniversalComposeDocument: async (office, targetItem, source, options) => {
            calls.universal.push({ office, targetItem, source, options });
            await options.beforeWrite();
            return { status: 'applied' };
        },
        Office: {},
        diagnoseStep: async (phase, action) => action(),
        codedError: codeError,
        rememberConfirmedInsertion: (...args) => calls.remembered.push(args),
        refreshSignatureCurrentState: async () => { calls.refresh++; },
        signatureStateRevision: 0,
        observedComposeItem: null,
        renderSelectedTemplate() {},
        readTemplateState: async () => { calls.legacy++; throw codeError('LEGACY_ENTRY'); },
        readBodyHtml: async () => '<p><br></p>',
        readComposeType: async () => 'newMail',
        universalComposeStateFromBody: () => ({ readable: true, present: false, current: false, kind: null }),
        ...extra,
    };
    return { item, choice, document, bootstrap, calls, state, context,
        update: load(pane, 'updateSignature', context), insert: load(pane, 'insertTemplate', context) };
}

test('manual signature update uses its paired U2e signature before legacy state reads', async () => {
    const f = paneFixture();
    f.bootstrap.universalSignature = { ...signature, html: 'global-source' };
    await f.update({});
    assert.equal(f.calls.universal.length, 1);
    assert.equal(f.calls.universal[0].source, signature);
    assert.equal(f.calls.legacy, 0);
    assert.equal(f.calls.refresh, 1);
});

test('manual template insertion passes the original combined envelope once', async () => {
    const f = paneFixture();
    const result = await f.insert({});
    assert.equal(result.applied, true);
    assert.equal(f.calls.universal.length, 1);
    assert.equal(f.calls.universal[0].source, combined);
    assert.equal(f.calls.legacy, 0);
    assert.equal(f.calls.remembered.length, 2);
    assert.equal(f.state.templatePresent, true);
    assert.equal(f.state.signatureCurrent, true);
    assert.equal(f.state.signatureVersion, combined.version);
});

test('already present U2e content is not reported as a new body write', async () => {
    const f = paneFixture({ insertUniversalComposeDocument: async () => ({ status: 'already-present' }) });
    const result = await f.insert({});
    assert.equal(result.applied, false);
    assert.equal(result.reason, 'already-present');
    assert.equal(f.calls.remembered.length, 0);
    assert.equal(f.calls.legacy, 0);
    assert.equal(f.calls.states.at(-1)[1], 'Vorlage ist aktuell');
});

test('a saved wording preference does not inject a template into an empty draft', async () => {
    const f = paneFixture();
    const result = await f.insert({}, { replaceOnly: true });
    assert.equal(result.applied, false);
    assert.equal(result.reason, 'no-managed-template');
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.remembered.length, 0);
});

test('an existing edited draft cannot route manual U2e insertion through the old replacement path', async () => {
    const f = paneFixture({ insertUniversalComposeDocument: async () => { throw codeError('U2E_NEW_DRAFT_REQUIRED'); } });
    await assert.rejects(f.insert({}), { code: 'U2E_NEW_DRAFT_REQUIRED' });
    assert.equal(f.calls.legacy, 0);
    assert.equal(f.calls.remembered.length, 0);
});

test('invalid paired signature metadata cannot silently use another signature', async () => {
    const f = paneFixture();
    f.document.universalSignature = null;
    await assert.rejects(f.update({}), { code: 'U2E_DOCUMENT_INVALID' });
    assert.equal(f.calls.universal.length, 0);
    assert.equal(f.calls.legacy, 0);
});

test('new-draft errors have a specific user action instead of a generic retry message', () => {
    const message = load(pane, 'userMessage', { safeErrorCode: error => error.code })(codeError('U2E_NEW_DRAFT_REQUIRED'));
    assert.match(message, /neue Nachricht öffnen/);
    assert.doesNotMatch(message, /erneut versuchen/i);
});

test('definite U2e preflight failures do not claim an uncertain body write', () => {
    const message = load(pane, 'userMessage', { safeErrorCode: error => error.code });
    for (const code of ['U2E_SOURCE_INVALID', 'U2E_API_UNAVAILABLE', 'U2E_HEADER_UNAVAILABLE']) {
        assert.match(message(codeError(code)), /es wurde nichts eingesetzt/);
        assert.doesNotMatch(message(codeError(code)), /nicht sicher bestätigt/);
    }
    assert.match(message(codeError('U2E_HTML_REQUIRED')), /HTML-Format/);
});
