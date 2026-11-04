import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { automaticComposeDecision } from '../../resources/js/outlook-addin/automatic-compose-policy.js';
import { universalFailurePayload } from '../../resources/js/outlook-addin/u2e-telemetry.js';

const runtime = await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8');
const start = /^async function applyPublishedContent\(/m.exec(runtime);
assert.ok(start);
const tail = runtime.slice(start.index);
const end = /\r?\n(?:export )?(?:async )?function [A-Za-z_$]/.exec(tail);
const applySource = end ? tail.slice(0, end.index) : tail;
const codedError = code => Object.assign(new Error(code), { code });
const pilot = 'it-technik@rail-time.de';
const id = '11111111-1111-4111-8111-111111111111';
const flush = async () => { for (let index = 0; index < 5; index++) await Promise.resolve(); };

function fixture({ mode = 'both', composeType = 'reply', mailbox = pilot, active = true, extra = {} } = {}) {
    const item = {};
    const source = { kind: 'combined', html: 'synthetic-combined-source' };
    const signature = { kind: 'signature', html: 'synthetic-signature-source' };
    const enabledSignature = ['both', 'signature'].includes(mode);
    const enabledTemplate = ['both', 'template'].includes(mode);
    const payload = {
        binding: { schema: 1, mailboxAddress: mailbox, senderAddress: mailbox, allowedSenderAddresses: [mailbox] },
        automaticInsertionEnabled: enabledSignature || enabledTemplate,
        automaticInsertion: { schema: 2, composeType, signature: enabledSignature, template: enabledTemplate },
        automaticTemplateChoice: { schema: 1, composeType, selection: enabledTemplate ? 'default' : 'none', templateId: enabledTemplate ? id : null },
        automaticTemplateId: id, templates: [{ id, key: id, isDefault: true, universalCompose: source }],
        universalSignature: signature,
    };
    const calls = { reports: [], insertions: [], bodyReads: 0, bootstrap: 0, binding: 0 };
    const config = { endpoints: { diagnostics: 'https://example.invalid/diagnostics', bootstrap: 'https://example.invalid/bootstrap' } };
    const context = {
        Office: { context: { mailbox: { item, userProfile: { emailAddress: mailbox } } } },
        codedError, reportRuntimePhase() {}, recordDiagnostic() {},
        diagnoseStep: async (_phase, action) => action(),
        loadConfig: async () => config,
        acquireTokenSilently: async () => 'synthetic-access-token',
        withDeadline: action => action(), AUTH_TIMEOUT_MS: 15000,
        readAutomaticComposeType: async () => composeType,
        loadBootstrap: async () => { calls.bootstrap++; return payload; },
        assertMailboxBinding: async () => { calls.binding++; },
        pendingNativeSignatures: { delete() {} },
        automaticComposeDecision,
        automaticTemplate: value => value.templates.find(entry => entry.isDefault === true),
        isTemplateInsertionBlocked: () => false,
        isStandaloneSignatureInsertionBlocked: () => false,
        hasUncertainWrite: () => false,
        universalComposeBlocked: () => false,
        universalComposeReport: () => null,
        reportUniversalFailure: options => { calls.reports.push(options); return Promise.resolve(true); },
        validateUniversalComposeDocument: document => document,
        insertUniversalComposeDocument: async (_office, target, document, options) => {
            calls.insertions.push({ target, document });
            await options.beforeWrite();
            return { status: 'applied' };
        },
        readTemplateState: async () => { calls.bodyReads++; throw codedError('LEGACY_ENTRY'); },
        ...extra,
    };
    const apply = vm.runInNewContext(`(${applySource}\n)`, context);
    return { item, payload, calls, context, source, signature, config,
        apply: () => apply(item, () => active) };
}

const scenarios = [
    { reason: 'compose_type_unknown', options: { mode: 'none', composeType: 'unknown' }, result: 'disabled' },
    { reason: 'automatic_disabled', options: { mode: 'none' }, result: 'disabled' },
    { reason: 'automatic_policy_unconfirmed', mutate: f => { f.payload.automaticInsertion.composeType = 'newMail'; }, result: 'skipped' },
    { reason: 'prior_write_uncertain', options: { extra: { universalComposeBlocked: () => true } }, result: 'uncertain' },
    { reason: 'universal_source_unavailable', mutate: f => { delete f.payload.templates[0].universalCompose; }, error: 'LEGACY_ENTRY' },
    { reason: 'template_only_policy', options: { mode: 'template' }, error: 'LEGACY_ENTRY' },
];

for (const scenario of scenarios) {
    test(`authenticated pilot logs automatic gate ${scenario.reason} without changing its outcome`, async () => {
        const f = fixture(scenario.options);
        scenario.mutate?.(f);
        if (scenario.error) await assert.rejects(f.apply(), { code: scenario.error });
        else assert.equal(await f.apply(), scenario.result);
        await flush();
        assert.equal(f.calls.reports.length, 1);
        const report = f.calls.reports[0];
        assert.equal(report.error.code, 'U2E_AUTOMATIC_SKIPPED');
        assert.equal(report.report.code, report.error.code);
        assert.equal(report.report.phase, 'preflight');
        assert.equal(report.report.automaticReason, scenario.reason);
        assert.equal(report.report.composeType, scenario.options?.composeType || 'reply');
        assert.equal(report.report.sourceLength, null);
        assert.equal(report.report.bodyLength, null);
        assert.equal(report.context, 'event');
        assert.equal(report.binding, f.payload.binding);
        assert.equal(report.accessToken, 'synthetic-access-token');
        assert.equal(report.item, f.item);
        assert.equal(report.config, f.config);
        assert.equal(report.isCurrent(), true);
        const wire = universalFailurePayload(report.error, report.report);
        assert.equal(wire.code, 'U2E_AUTOMATIC_SKIPPED');
        assert.equal(wire.automaticReason, scenario.reason);
        assert.equal(wire.composeType, scenario.options?.composeType || 'reply');
        assert.equal(f.calls.insertions.length, 0);
        assert.equal(f.calls.bodyReads, scenario.error ? 1 : 0);
    });
}

test('every automatic gate is silent for a resolved nonpilot and preserves its legacy behavior', async () => {
    for (const scenario of scenarios) {
        const f = fixture({ ...scenario.options, mailbox: 'employee@example.invalid' });
        scenario.mutate?.(f);
        if (scenario.error) await assert.rejects(f.apply(), { code: scenario.error });
        else assert.equal(await f.apply(), scenario.result);
        assert.equal(f.calls.reports.length, 0, scenario.reason);
    }
});

test('all existing uncertain-write gates retain the same pilot skip reason and prevent new writes', async () => {
    for (const key of ['isTemplateInsertionBlocked', 'isStandaloneSignatureInsertionBlocked', 'hasUncertainWrite', 'universalComposeBlocked']) {
        const f = fixture({ extra: { [key]: () => true } });
        assert.equal(await f.apply(), 'uncertain');
        assert.equal(f.calls.reports[0].report.automaticReason, 'prior_write_uncertain');
        assert.equal(f.calls.insertions.length, 0);
        assert.equal(f.calls.bodyReads, 0);
    }
});

test('a missing signature-only U2e projection records the same reason before the unchanged fallback', async () => {
    const f = fixture({ mode: 'signature' });
    delete f.payload.universalSignature;
    await assert.rejects(f.apply(), { code: 'LEGACY_ENTRY' });
    assert.equal(f.calls.reports.length, 1);
    assert.equal(f.calls.reports[0].report.automaticReason, 'universal_source_unavailable');
    assert.equal(f.calls.bodyReads, 1);
});

test('successful U2e reply/forward/new-message insertion keeps the original source and does not emit a skip', async () => {
    for (const composeType of ['reply', 'forward', 'newMail']) {
        for (const mode of ['both', 'signature']) {
            const f = fixture({ composeType, mode });
            assert.equal(await f.apply(), 'applied');
            assert.equal(f.calls.insertions.length, 1);
            assert.equal(f.calls.insertions[0].document, mode === 'both' ? f.source : f.signature);
            assert.equal(f.calls.bodyReads, 0);
            assert.equal(f.calls.reports.length, 0);
        }
    }
});

test('already-present U2e still returns without a write, fallback or skip report', async () => {
    const f = fixture({ extra: { insertUniversalComposeDocument: async () => ({ status: 'already-present' }) } });
    assert.equal(await f.apply(), 'already-present');
    assert.equal(f.calls.reports.length, 0);
    assert.equal(f.calls.bodyReads, 0);
});

test('failed authentication, failed binding and expired lifecycle cannot report an authenticated skip', async () => {
    for (const options of [
        { extra: { acquireTokenSilently: async () => { throw codedError('AUTH_INTERACTION_REQUIRED'); } } },
        { extra: { assertMailboxBinding: async () => { throw codedError('MAILBOX_MISMATCH'); } } },
        { active: false },
    ]) {
        const f = fixture({ ...options, mode: 'none' });
        await assert.rejects(f.apply());
        assert.equal(f.calls.reports.length, 0);
        assert.equal(f.calls.insertions.length, 0);
        assert.equal(f.calls.bodyReads, 0);
    }
});

test('a pilot skip waits for the bounded reporter before completing the Classic event once', async () => {
    let settle;
    let completed = 0;
    const f = fixture({ mode: 'none', extra: { reportUniversalFailure: () => new Promise(resolve => { settle = resolve; }) } });
    const handlerStart = /^async function handleComposeEvent\(/m.exec(runtime);
    const handlerTail = runtime.slice(handlerStart.index);
    const handlerEnd = /\r?\n(?:export )?(?:async )?function [A-Za-z_$]/.exec(handlerTail);
    // The next function is preceded by module state; cut at that declaration.
    const handlerSource = handlerTail.slice(0, handlerTail.indexOf('\nlet associatedActions;'));
    assert.ok(handlerEnd);
    const handler = vm.runInNewContext(`(${handlerSource}\n)`, {
        ...f.context, completeOnce: event => () => event.completed(),
        composeItemWhenReady: async () => f.item, composeOperations: new WeakMap(),
        applyPublishedContent: () => f.apply(), isMobileComposeHost: () => false,
        notifyMobileFailure: async () => {}, safeErrorCode: error => error.code,
    });
    const pending = handler({ completed() { completed++; } });
    for (let index = 0; index < 50 && !settle; index++) await Promise.resolve();
    assert.equal(typeof settle, 'function');
    assert.equal(completed, 0);
    settle(false);
    await pending;
    assert.equal(completed, 1);
    assert.equal(f.calls.bodyReads, 0);
    assert.equal(f.calls.insertions.length, 0);
});

test('a U2e failure retains its report type and original error without falling back', async () => {
    const error = codedError('U2E_INSERT_UNCERTAIN');
    const f = fixture({ extra: {
        insertUniversalComposeDocument: async () => { throw error; },
        universalComposeReport: () => ({ code: error.code, phase: 'readback', composeType: 'reply' }),
    } });
    await assert.rejects(f.apply(), value => value === error);
    assert.equal(f.calls.reports.length, 1);
    assert.equal(f.calls.reports[0].error, error);
    assert.equal(f.calls.reports[0].report.composeType, 'reply');
    assert.equal(f.calls.reports[0].report.phase, 'readback');
    assert.equal(f.calls.reports[0].sourceLength, f.source.html.length);
    assert.equal(f.calls.bodyReads, 0);
});

test('a pilot U2e error gives its bounded report time to settle and retains the original failure', async () => {
    let settle;
    let rejected = false;
    const error = codedError('U2E_INSERT_UNCERTAIN');
    const f = fixture({ extra: {
        insertUniversalComposeDocument: async () => { throw error; },
        reportUniversalFailure: () => new Promise(resolve => { settle = resolve; }),
    } });
    const pending = f.apply().catch(value => { rejected = true; throw value; });
    for (let index = 0; index < 50 && !settle; index++) await Promise.resolve();
    assert.equal(typeof settle, 'function');
    assert.equal(rejected, false);
    settle(false);
    await assert.rejects(pending, value => value === error);
});

test('a nonpilot U2e error keeps its existing immediate failure path', async () => {
    const error = codedError('U2E_INSERT_UNCERTAIN');
    const f = fixture({ mailbox: 'employee@example.invalid', extra: {
        insertUniversalComposeDocument: async () => { throw error; },
        reportUniversalFailure: () => new Promise(() => {}),
    } });
    await assert.rejects(f.apply(), value => value === error);
});
