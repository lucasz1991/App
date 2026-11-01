import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { diagnoseUncertainV15Readback, insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate } from '../../resources/js/outlook-addin/compose-template.js';
import { managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { diagnosticReport } from '../../resources/js/outlook-addin/diagnostics.js';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v30-reference/v16-source.json', import.meta.url), 'utf8'));
const nonce = '71'.repeat(16);
const options = { requested: true, uncertain: true };
const host = { context: { platform: 'OfficeOnline', mailbox: { diagnostics: { hostName: 'OutlookWebApp' } } } };
const planFor = field => prepareNativeLayoutRestore(fixture.source[field], field === 'standalone'
    ? standaloneSignatureContract(fixture.source[field]) : managedTemplateContract(fixture.source[field]), nonce, host);
// Synthetic shape copied from observed visible OWA transformations, not a
// claim that the SDK returns the visible DOM or that this is native acceptance.
const owaVisibleShape = html => html.replace(/<style\b[^>]*>([\s\S]*?)<\/style>/gi, (_match, css) => css.includes('.rt-office-metadata{')
    ? '<style> .x_rt-office-metadata {font-size:0;line-height:0;max-height:0;overflow:hidden} </style>' : '<style></style>')
    .replace(/class="([^"]*)"/g, (_match, classes) => 'class="' + classes.split(/\s+/).map(value => 'x_' + value).join(' ') + '"')
    .replace(/\s(?:hidden|aria-hidden="true")(?=\s|>)/g, '').replace(/display\s*:\s*none(?:!important)?;?/gi, '').replace(/mso-hide\s*:\s*all;?/gi, '');

function mailbox(rewrite = value => value) {
    const state = { body: '', reads: 0, signatures: 0, prepends: 0, replacements: 0, sessionWrites: 0, sessions: new Map() };
    const success = value => ({ status: 'succeeded', value });
    const item = {
        getComposeTypeAsync: callback => callback(success({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, callback) => callback(success(state.sessions.get(key) || '')),
            setAsync: (key, value, callback) => { state.sessionWrites++; state.sessions.set(key, value); callback(success()); } },
        body: { getTypeAsync: callback => callback(success('html')),
            getAsync: (_kind, callback) => { state.reads++; callback(success(state.body)); },
            setSignatureAsync: (html, _config, callback) => { state.signatures++; state.body = rewrite(html); callback(success()); },
            prependAsync: (html, _config, callback) => { state.prepends++; state.body = rewrite(html) + state.body; callback(success()); },
            setAsync: (html, _config, callback) => { state.replacements++; state.body = rewrite(html); callback(success()); } },
    };
    return { state, item, office: { ...host, AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' } } };
}
const writes = state => [state.signatures, state.prepends, state.replacements, state.sessionWrites, [...state.sessions]];
const privateFree = result => assert.doesNotMatch(JSON.stringify(result), /example\.|https?:|mailto:|RT-|[a-f0-9]{16}|<\w|Muster|Synthetic identity/);

test('V16 diagnostics remain explicit and preserve the mailbox checks before and after their one SDK body read', async () => {
    const { state, item, office } = mailbox(); state.body = planFor('standalone').html;
    let bindings = 0;
    for (const option of [{ requested: false, uncertain: true }, { requested: true, uncertain: false }]) {
        await diagnoseUncertainV15Readback(office, item, { ...option, beforeRead: () => { bindings++; } });
    }
    assert.equal(state.reads, 0); assert.equal(bindings, 0);
    const report = await diagnoseUncertainV15Readback(office, item, { ...options, beforeRead: () => { bindings++; } });
    assert.equal(state.reads, 1); assert.equal(bindings, 2);
    assert.equal(report.v16Candidate, true); assert.equal(report.phase, 'diagnostic-v16-complete');
    assert.equal(report.family, 16); assert.equal(report.profile, 'M'); assert.equal(report.material, true);
    assert.equal(report.sourcePlanAvailable, false); assert.equal(report.sourcePlanMatched, null);
    assert.equal(report.writeNonceCompared, false); assert.equal(report.acknowledgementGranted, false);
    assert.deepEqual(writes(state), [0, 0, 0, 0, []]); privateFree(report);
});

test('V16 diagnostics report surviving rewritten marker CSS and lost hidden material without accepting or rewriting it', async () => {
    const { state, item, office } = mailbox(); state.body = owaVisibleShape(planFor('combined').html);
    const before = state.body, report = await diagnoseUncertainV15Readback(office, item, options);
    assert.equal(report.phase, 'diagnostic-v16-material'); assert.equal(report.contract, true); assert.equal(report.material, false);
    assert.equal(report.v16Shape.footerOwaZeroLineStyles, 2); assert.equal(report.v16Shape.currentMediaQueries, 0);
    assert.equal(report.v16Shape.mobileDispatchCandidates, 1);
    assert.equal(report.v16Shape.mobileDispatchDisplayNone, false); assert.equal(report.v16Shape.mobileDispatchMsoHideAll, false);
    assert.equal(report.v16Shape.mobileDispatchMaxHeightZero, false); assert.equal(report.v16Shape.mobileDispatchOverflowHidden, false);
    assert.deepEqual(report.v16Shape.columnWidths, [220, 280, 230, 227]);
    assert.equal(state.body, before); assert.deepEqual(writes(state), [0, 0, 0, 0, []]);
    assert.equal(report.acknowledgementGranted, false); privateFree(report);
});

for (const kind of ['standalone', 'combined']) {
    test('V16 ' + kind + ' uncertainty retains its private source plan without diagnosis clearing, acknowledging or retrying', async () => {
        const { state, item, office } = mailbox(owaVisibleShape);
        const insert = () => kind === 'standalone' ? insertStandaloneSignature(office, item, fixture.source.standalone, () => {}, { media: [] })
            : prependTemplate(office, item, fixture.source.combined, () => {}, { media: [] });
        await assert.rejects(insert(), error => /LAYOUT_UNCONFIRMED/.test(error.code));
        const before = state.body, counters = writes(state);
        const report = await diagnoseUncertainV15Readback(office, item, options);
        assert.equal(report.sourcePlanAvailable, true); assert.equal(report.sourcePlanMatched, true);
        assert.equal(report.writeNonceCompared, true); assert.equal(report.material, false); assert.equal(report.acknowledgementGranted, false);
        assert.equal(state.body, before); assert.deepEqual(writes(state), counters);
        if (kind === 'standalone') assert.equal(isStandaloneSignatureInsertionBlocked(item), true);
        await assert.rejects(insert(), error => /INSERT_UNCERTAIN/.test(error.code));
        assert.deepEqual(writes(state), counters); privateFree(report);
        for (const rewrite of [value => value.replace(/(RT-FLUID-LAYOUT-V16M:)[a-f0-9]{32}/, '$1' + 'ab'.repeat(16)),
            value => value.replace('RT-FLUID-LAYOUT-V16M:', 'RT-FLUID-LAYOUT-V16B:')]) {
            state.body = rewrite(before);
            const mismatch = await diagnoseUncertainV15Readback(office, item, options);
            assert.equal(mismatch.phase, 'diagnostic-v16-contract'); assert.equal(mismatch.contract, false);
            assert.equal(mismatch.sourcePlanMatched, false); assert.equal(mismatch.writeNonceCompared, true);
            assert.equal(mismatch.acknowledgementGranted, false); assert.deepEqual(writes(state), counters); privateFree(mismatch);
        }
    });
}

test('V16 diagnosis stops on changed binding, failed SDK reads, malformed metadata or quoted-only candidates without exposing inputs', async () => {
    const { state, item, office } = mailbox(); const html = planFor('standalone').html;
    state.body = html; let binding = 0;
    const changed = await diagnoseUncertainV15Readback(office, item, { ...options, beforeRead: () => { if (++binding === 2) throw new Error('private@example.test'); } });
    assert.equal(changed.contract, false); privateFree(changed);
    for (const body of [html + html, '<blockquote>' + html + '</blockquote>', html.replaceAll('RT-SIGNATURE-VERSION:', 'UNKNOWN-PRIVATE:')]) {
        state.body = body;
        const rejected = await diagnoseUncertainV15Readback(office, item, options);
        assert.equal(rejected.contract, false); assert.equal(rejected.acknowledgementGranted, false); privateFree(rejected);
    }
    item.body.getAsync = (_kind, callback) => callback({ status: 'failed', error: { code: 'private@example.test', message: html } });
    const failed = await diagnoseUncertainV15Readback(office, item, options);
    assert.equal(failed.phase, 'body-read'); assert.equal(failed.sdkRead, false); privateFree(failed);
    assert.deepEqual(writes(state), [0, 0, 0, 0, []]);
});

test('the existing explicit retry-check and copy UI paths continue to invoke the same read-only uncertain diagnosis', () => {
    const source = readFileSync(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    assert.match(source, /diagnosticButton\?\.addEventListener\('click', \(\) => runDiagnostics\(diagnosticButton\)\)/);
    assert.match(source, /if \(readV15 && boundMailbox && uncertain && !taskpaneState\.busy\)/);
    assert.match(source, /report\.v15Readback = await diagnoseUncertainV15Readback\(Office, diagnosticTarget\.item/);
    assert.equal((source.match(/await showDiagnosticReport\(\{ readV15: true \}\)/g) || []).length, 2);
});

test('the actual taskpane report renderer replaces a stale visible report with fresh finite V16 SDK features', async () => {
    const source = readFileSync(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    const start = source.indexOf('async function showDiagnosticReport('), end = source.indexOf('\nasync function runDiagnostics(', start);
    assert.ok(start >= 0 && end > start);
    const { state, item, office } = mailbox(); state.body = owaVisibleShape(planFor('combined').html);
    const output = { value: '{"generatedAt":"2000-01-01T00:00:00.000Z"}', hidden: true };
    const bootstrap = { binding: { mailboxAddress: 'private@example.test', senderAddress: 'private@example.test' } };
    const dependencies = {
        document: { querySelector: selector => selector === '[data-outlook-diagnostics-output]' ? output : null },
        taskpaneState: { authenticated: true, bootstrapReady: true, configReady: true, busy: false }, currentBootstrap: bootstrap,
        captureComposeTarget: () => ({ item }), diagnoseStep: (_phase, operation) => operation(), assertWriteTarget: async () => {},
        diagnosticReport, globalThis: { Office: office }, Office: office, automaticTemplate: () => null,
        insertionBlocked: () => true, insertionDisplayEvidence: () => ({}), safeErrorCode: error => error?.code,
        lastActionError: { code: 'TEMPLATE_LAYOUT_UNCONFIRMED' }, diagnoseUncertainV15Readback,
    };
    const render = new Function('dependencies', 'const {' + Object.keys(dependencies).join(',') + '} = dependencies; return (' + source.slice(start, end) + ');')(dependencies);
    await render({ readV15: true });
    const report = JSON.parse(output.value);
    assert.notEqual(report.generatedAt, '2000-01-01T00:00:00.000Z'); assert.equal(output.hidden, false);
    assert.equal(report.checks.writeBlocked, true); assert.equal(report.v15Readback.sdkRead, true);
    assert.equal(report.v15Readback.v16Candidate, true); assert.equal(report.v15Readback.v16Shape.footerOwaZeroLineStyles, 2);
    assert.equal(report.v15Readback.v16Shape.mobileDispatchDisplayNone, false);
    assert.equal(report.v15Readback.sourcePlanAvailable, false); assert.equal(report.v15Readback.acknowledgementGranted, false);
    assert.deepEqual(writes(state), [0, 0, 0, 0, []]); privateFree(report);
});
