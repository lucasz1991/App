import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { diagnoseUncertainV15Readback, prependTemplate } from '../../resources/js/outlook-addin/compose-template.js';
import { diagnosticReport } from '../../resources/js/outlook-addin/diagnostics.js';
import { universalComposeBoundaryHelp } from '../../resources/js/outlook-addin/u2e-compose-help.js';
import { universalComposeReport } from '../../resources/js/outlook-addin/u2e-compose.js';
import { fluidLayoutContract, managedTemplateContract, standaloneSignatureContract, V17_READBACK_CONTRACT_STAGES } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore } from '../../resources/js/outlook-addin/native-layout-restore.js';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const nonce = '68'.repeat(16);
const options = { requested: true, uncertain: true };
const host = profile => ({ context: { platform: profile === 'B' ? 'PC' : 'OfficeOnline',
    mailbox: { diagnostics: { hostName: profile === 'B' ? 'Outlook' : 'OutlookWebApp' } } } });
const plan = (kind = 'combined', profile = 'B') => prepareNativeLayoutRestore(fixture.source[kind],
    kind === 'combined' ? managedTemplateContract(fixture.source[kind]) : standaloneSignatureContract(fixture.source[kind]), nonce, host(profile));

function mailbox(profile = 'B', rewrite = value => value) {
    const state = { body: '', reads: 0, writes: 0, sessionWrites: 0, sessions: new Map() };
    const succeeded = value => ({ status: 'succeeded', value });
    const item = {
        getComposeTypeAsync: callback => callback(succeeded({ composeType: 'newMail' })),
        sessionData: {
            getAsync: (key, callback) => callback(succeeded(state.sessions.get(key) || '')),
            setAsync: (key, value, callback) => { state.sessionWrites++; state.sessions.set(key, value); callback(succeeded()); },
        },
        body: {
            getTypeAsync: callback => callback(succeeded('html')),
            getAsync: (_kind, callback) => { state.reads++; callback(succeeded(state.body)); },
            prependAsync: (html, _config, callback) => { state.writes++; state.body = rewrite(html) + state.body; callback(succeeded()); },
            setAsync: () => { state.writes++; throw new Error('No additional body write allowed'); },
            setSignatureAsync: () => { state.writes++; throw new Error('No signature write allowed'); },
        },
    };
    return { state, item, office: { ...host(profile), AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' } } };
}

const snapshot = state => ({ body: state.body, writes: state.writes, sessionWrites: state.sessionWrites, sessions: [...state.sessions] });
const privateFree = report => assert.doesNotMatch(JSON.stringify(report), /https?:|mailto:|RT-|[a-f0-9]{16}|<\w|Muster|example\./);

for (const profile of ['M', 'B']) for (const kind of ['standalone', 'combined']) {
    test('V17 ' + kind + '/' + profile + ' finite checkpoints preserve the same contract and report parsed HTML', async () => {
        const html = plan(kind, profile).html, steps = [];
        const contract = fluidLayoutContract(html, html, nonce, fixture.version);
        const observed = fluidLayoutContract(html, html, nonce, fixture.version, stage => steps.push(stage));
        assert.deepEqual(observed, contract);
        assert.deepEqual(steps.slice(0, 3), ['input-bound', 'html-parse', 'html-parsed']);
        assert.equal(steps.at(-1), 'contract-complete');
        assert.equal(steps.every(stage => V17_READBACK_CONTRACT_STAGES.includes(stage)), true);
        const { state, item, office } = mailbox(profile); state.body = html;
        const before = snapshot(state), result = await diagnoseUncertainV15Readback(office, item, options);
        assert.equal(result.parse, true); assert.equal(result.htmlParseStatus, 'passed');
        assert.equal(result.contract, true); assert.equal(result.contractStage, 'contract-complete');
        assert.equal(result.nonceCheckStatus, 'passed'); assert.equal(result.writeNonceCompared, false);
        assert.equal(result.sourcePlanAvailable, false); assert.equal(result.sourcePlanMatched, null);
        assert.equal(result.acknowledgementGranted, false); assert.equal(state.reads, 1);
        assert.deepEqual(snapshot(state), before); privateFree(result);
    });
}

test('V17 malformed HTML is reported as an actual parser failure without claiming a contract or nonce comparison', async () => {
    const { state, item, office } = mailbox(); state.body = plan().html + '<broken';
    const before = snapshot(state), report = await diagnoseUncertainV15Readback(office, item, options);
    assert.equal(report.v17Candidate, true); assert.equal(report.parse, false); assert.equal(report.htmlParseStatus, 'failed');
    assert.equal(report.contractStage, 'html-parse'); assert.equal(report.contract, false);
    assert.equal(report.nonceCheckStatus, 'not-run'); assert.equal(report.writeNonceCompared, false);
    assert.equal(report.material, false); assert.equal(report.acknowledgementGranted, false);
    assert.deepEqual(snapshot(state), before); privateFree(report);
});

for (const [label, rewrite, stage] of [
    ['duplicate fluid markers', value => value + value, 'fluid-marker-shape'],
    ['quoted-only fluid marker', value => '<blockquote>' + value + '</blockquote>', 'fluid-marker-shape'],
    ['missing editable end', value => value.replaceAll('RT-COMPOSE-EDITABLE-END-V1', 'UNKNOWN-END'), 'editable-markers'],
    ['visible fluid metadata', value => {
        const node = fluidLayoutContract(value).fluidLayout.marker.node;
        return value.slice(0, node.start) + '<span style="font-size:16px;line-height:20px;">'
            + value.slice(node.openEnd, node.close) + '</span>' + value.slice(node.end);
    }, 'fluid-marker-hidden'],
]) {
    test('V17 ' + label + ' distinguishes successful parsing from the unchanged rejecting gate', async () => {
        const { state, item, office } = mailbox(); state.body = rewrite(plan().html);
        const before = snapshot(state), report = await diagnoseUncertainV15Readback(office, item, options);
        assert.equal(report.parse, true); assert.equal(report.htmlParseStatus, 'passed');
        assert.equal(report.contract, false); assert.equal(report.contractStage, stage);
        assert.equal(report.sourcePlanMatched, null); assert.equal(report.material, false);
        assert.equal(report.acknowledgementGranted, false); assert.deepEqual(snapshot(state), before); privateFree(report);
    });
}

test('V17 a failed post-write contract stays uncertain with no second write, session reset or fabricated source mismatch', async () => {
    const { state, item, office } = mailbox('B', value => value.replaceAll('RT-COMPOSE-EDITABLE-END-V1', 'UNKNOWN-END'));
    const insert = () => prependTemplate(office, item, fixture.source.combined, () => {}, { media: [] });
    await assert.rejects(insert(), error => error.code === 'TEMPLATE_LAYOUT_UNCONFIRMED');
    assert.equal(state.writes, 1);
    const before = snapshot(state), report = await diagnoseUncertainV15Readback(office, item, options);
    assert.equal(report.parse, true); assert.equal(report.contract, false); assert.equal(report.contractStage, 'editable-markers');
    assert.equal(report.sourcePlanAvailable, true); assert.equal(report.sourcePlanMatched, null);
    assert.equal(report.nonceCheckStatus, 'passed'); assert.equal(report.writeNonceCompared, true);
    assert.equal(report.acknowledgementGranted, false); assert.deepEqual(snapshot(state), before);
    await assert.rejects(insert(), error => error.code === 'TEMPLATE_INSERT_UNCERTAIN');
    assert.deepEqual(snapshot(state), before); privateFree(report);
});

test('V17 malformed readback with a pending source plan does not falsely claim its nonce was compared', async () => {
    const { state, item, office } = mailbox('B', value => value + '<broken');
    await assert.rejects(prependTemplate(office, item, fixture.source.combined, () => {}, { media: [] }), error => error.code === 'TEMPLATE_LAYOUT_UNCONFIRMED');
    const before = snapshot(state), report = await diagnoseUncertainV15Readback(office, item, options);
    assert.equal(report.sourcePlanAvailable, true); assert.equal(report.sourcePlanMatched, null);
    assert.equal(report.parse, false); assert.equal(report.contractStage, 'html-parse');
    assert.equal(report.writeNonceCompared, false); assert.equal(report.nonceCheckStatus, 'not-run');
    assert.equal(report.acknowledgementGranted, false); assert.deepEqual(snapshot(state), before); privateFree(report);
});

test('V17 a proven profile mismatch is distinguished from a contract that never reached source-plan comparison', async () => {
    const { state, item, office } = mailbox('B', value => value.replace('RT-FLUID-LAYOUT-V17B:', 'RT-FLUID-LAYOUT-V17M:'));
    await assert.rejects(prependTemplate(office, item, fixture.source.combined, () => {}, { media: [] }), error => error.code === 'TEMPLATE_LAYOUT_UNCONFIRMED');
    const before = snapshot(state), report = await diagnoseUncertainV15Readback(office, item, options);
    assert.equal(report.parse, true); assert.equal(report.contract, false); assert.equal(report.contractStage, 'contract-complete');
    assert.equal(report.sourcePlanAvailable, true); assert.equal(report.sourcePlanMatched, false);
    assert.equal(report.writeNonceCompared, true); assert.equal(report.nonceCheckStatus, 'passed');
    assert.equal(report.acknowledgementGranted, false); assert.deepEqual(snapshot(state), before); privateFree(report);
});

test('V17 diagnosis still requires an explicit request and fresh binding around exactly one body read', async () => {
    const { state, item, office } = mailbox(); state.body = plan().html;
    let bindings = 0;
    for (const args of [{ requested: false, uncertain: true }, { requested: true, uncertain: false }]) {
        await diagnoseUncertainV15Readback(office, item, { ...args, beforeRead: () => { bindings++; } });
    }
    assert.equal(state.reads, 0); assert.equal(bindings, 0);
    const before = snapshot(state);
    const changed = await diagnoseUncertainV15Readback(office, item, { ...options, beforeRead: () => {
        if (++bindings === 2) throw new Error('private@example.test');
    } });
    assert.equal(state.reads, 1); assert.equal(bindings, 2);
    assert.equal(changed.contract, false); assert.equal(changed.acknowledgementGranted, false);
    assert.deepEqual(snapshot(state), before); privateFree(changed);
});

function taskpaneFunction(name, next) {
    const source = readFileSync(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    const start = source.indexOf('function ' + name + '('), end = source.indexOf('\nfunction ' + next + '(', start);
    assert.ok(start >= 0 && end > start);
    return source.slice(start, end);
}

test('the actual taskpane distinguishes an accepted but unconfirmed layout from a proven write timeout', () => {
    const userMessage = new Function('safeErrorCode', 'isInteractionRequired', 'universalComposeBoundaryHelp', 'universalComposeReport',
        'return (' + taskpaneFunction('userMessage', 'readyStatusDetail') + ');')(
        error => error.code, () => false, universalComposeBoundaryHelp, universalComposeReport);
    const layout = userMessage({ code: 'TEMPLATE_LAYOUT_UNCONFIRMED', phase: 'template-replace-preflight', reason: 'unconfirmed' });
    assert.match(layout, /Einfügeaufruf.*angenommen/); assert.match(layout, /Darstellung/);
    assert.doesNotMatch(layout, /rechtzeitig|ersetzt|erneut versuchen/);
    const unknown = userMessage({ code: 'TEMPLATE_INSERT_UNCERTAIN' });
    assert.match(unknown, /nicht sicher bestätigt/); assert.doesNotMatch(unknown, /rechtzeitig/);
    assert.match(userMessage({ code: 'TEMPLATE_INSERT_UNCERTAIN', reason: 'timeout' }), /nicht rechtzeitig/);
});

test('the actual failure renderer keeps the exact original phase and reason without changing locks or claiming success', () => {
    const error = Object.freeze({ code: 'TEMPLATE_LAYOUT_UNCONFIRMED', phase: 'template-replace-preflight', reason: 'unconfirmed' });
    const records = [], statuses = [];
    const execute = new Function('error', 'recordDiagnostic', 'setStatus', `
        let lastActionError = null;
        const safeErrorCode = value => value.code;
        const authenticationWasLost = () => false;
        const setBusy = () => {}, syncActionState = () => {};
        const invalidateBootstrap = () => { throw new Error('Unexpected bootstrap mutation'); };
        const userMessage = () => 'Layout could not be confirmed';
        (${taskpaneFunction('handleFailure', 'requestSilentBootstrapRefresh')})(error, true);
        return lastActionError;
    `);
    const retained = execute(error, (...values) => records.push(values), (...values) => statuses.push(values));
    assert.equal(retained, error); assert.deepEqual(records, [['template-replace-preflight', 'failed', error]]);
    assert.equal(statuses[0][1], 'Darstellung nicht bestätigt'); assert.equal(statuses[0][0], 'error');
    assert.equal(diagnosticReport({}, {}).diagnosticRevision, 'v17-contract-checkpoints-v1');
});
