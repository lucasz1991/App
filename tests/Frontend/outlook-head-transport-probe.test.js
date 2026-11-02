import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';
import { HEAD_PROBE_MAILBOX, HEAD_PROBE_SUBJECT, HEAD_PROBE_SESSION_KEY, headProbeContextAvailable, headProbeDocument } from '../../resources/js/outlook-addin/head-transport-probe.js';
import { insertHeadTransportProbe, headTransportProbeReport, isStandaloneSignatureInsertionBlocked } from '../../resources/js/outlook-addin/compose-template.js';
import { managedTemplateContract, fluidLayoutContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { wasSignatureWriteConfirmed, hasUncertainWrite } from '../../resources/js/outlook-addin/office-write.js';
import { recordDiagnostic, diagnosticReport } from '../../resources/js/outlook-addin/diagnostics.js';
const fixture = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const SOURCE = fixture.source.combined;
const binding = { schema: 1, mailboxAddress: HEAD_PROBE_MAILBOX, senderAddress: HEAD_PROBE_MAILBOX, allowedSenderAddresses: [HEAD_PROBE_MAILBOX] };
const success = value => ({ status: 'succeeded', value });

function harness(options = {}) {
    const state = { writes: [], bodyWrites: 0, session: options.session || new Map(), subject: HEAD_PROBE_SUBJECT,
        to: [{ emailAddress: HEAD_PROBE_MAILBOX }], cc: [], bcc: [], attachments: [], reads: 0, body: '', before: 0, sessionReads: [], sessionWrites: [] };
    const item = { from: { getAsync: callback => callback(success({ emailAddress: HEAD_PROBE_MAILBOX })) },
        subject: { getAsync: callback => callback(success(state.subject)) },
        to: { getAsync: callback => callback(success(state.to)) }, cc: { getAsync: callback => callback(success(state.cc)) }, bcc: { getAsync: callback => callback(success(state.bcc)) },
        getAttachmentsAsync: callback => callback(success(state.attachments)),
        getComposeTypeAsync: callback => callback(success({ composeType: options.composeType || 'newMail' })),
        sessionData: { getAsync: (key, callback) => { state.sessionReads.push(key); callback(success(state.session.get(key))); },
            setAsync: (key, value, callback) => { state.sessionWrites.push(key); state.session.set(key, value); options.afterSession?.(state, key, value); callback(success()); } },
        body: { getTypeAsync: callback => callback(success(options.format || 'html')), getAsync: (_type, callback) => { state.reads++; callback(success(state.body)); },
            setSignatureAsync: (html, _options, callback) => {
                state.writes.push(html);
                if (options.throwWrite) throw new Error('private exception must not leak');
                if (options.writeFailure) { callback({ status: 'failed', error: { code: '5001', message: 'private text' } }); return; }
                const written = options.rewrite ? options.rewrite(html, state) : html;
                state.body += written; callback(success());
            }, setAsync: () => { state.bodyWrites++; throw Error('Forbidden full-body write'); }, prependAsync: () => { state.bodyWrites++; throw Error('Forbidden body prepend'); } } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' },
        context: { platform: options.profile === 'B' ? 'PC' : 'OfficeOnline', mailbox: { item, userProfile: { emailAddress: HEAD_PROBE_MAILBOX },
            diagnostics: { hostName: options.profile === 'B' ? 'Outlook' : 'OutlookWebApp' } } } };
    const plan = prepareNativeLayoutRestore(SOURCE, managedTemplateContract(SOURCE), '78'.repeat(16), office);
    state.body = plan.html;
    return { state, item, office, plan, invoke: () => insertHeadTransportProbe(office, item, { sourceDocument: { html: SOURCE, media: [] }, binding,
        beforeWrite: async () => { state.before++; options.beforeWrite?.(state, office, item); } }) };
}

test('HEAD probe is a small fixed fictional document with one HEAD stylesheet, explicit media syntax and inline fallbacks', () => {
    const probe = headProbeDocument('12'.repeat(16));
    assert.ok(probe.html.length < 1600); assert.match(probe.html, /<head>[\s\S]*<style[\s\S]*@media only screen and \(max-width:520px\)[\s\S]*<\/style><\/head>/);
    assert.doesNotMatch(probe.html.slice(probe.html.indexOf('<body>')), /<style\b|<img\b|<a\b|https?:|mailto:/);
    assert.match(probe.html, />Alpha</); assert.match(probe.html, />Beta</); assert.match(probe.html, /style="display:inline-block/);
    assert.throws(() => headProbeDocument('<script>'));
});

for (const profile of ['M', 'B']) test('own empty technical draft ' + profile + ' gets exactly one native-slot probe without changing normal session claims', async () => {
    const box = harness({ profile }); box.state.session.set('railtime.template.inserted.v1', '1'); box.state.session.set('railtime.signature.layout.v1', '1');
    const original = box.state.body, result = await box.invoke();
    assert.equal(box.state.writes.length, 1); assert.equal(box.state.bodyWrites, 0); assert.ok(box.state.body.startsWith(original));
    assert.equal(result.state, 'complete'); assert.equal(result.rectangles, true); assert.equal(result.probeHeadStyles, 1);
    assert.equal(result.probeBodyStyles, 0); assert.equal(result.exactCss, true); assert.equal(result.mediaRetained, true);
    assert.equal(box.state.session.get(HEAD_PROBE_SESSION_KEY), '1');
    assert.equal(box.state.session.get('railtime.template.inserted.v1'), '1'); assert.equal(box.state.session.get('railtime.signature.layout.v1'), '1');
    assert.equal(wasSignatureWriteConfirmed(box.item), false); assert.equal(isStandaloneSignatureInsertionBlocked(box.item), true);
    assert.doesNotMatch(JSON.stringify(result), /Mara|example|it-technik|pending:|[0-9a-f]{32}/);
    await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 1);
});

for (const [name, change] of [
    ['wrong subject', state => { state.subject = '[TEST] another message'; }],
    ['second recipient', state => { state.to.push({ emailAddress: 'other@example.invalid' }); }],
    ['wrong sole recipient', state => { state.to = [{ emailAddress: 'other@example.invalid' }]; }],
    ['CC', state => { state.cc.push({ emailAddress: HEAD_PROBE_MAILBOX }); }],
    ['BCC', state => { state.bcc.push({ emailAddress: HEAD_PROBE_MAILBOX }); }],
    ['attachment', state => { state.attachments.push({ name: 'private.pdf' }); }],
    ['quote', state => { state.body += '<blockquote>Private historical text</blockquote>'; }],
    ['outside user text', state => { state.body += '<p>Private typed text</p>'; }],
    ['editable user text', state => { const c = managedTemplateContract(state.body); state.body = state.body.slice(0, c.region.start) + '<p>Private typed text</p>' + state.body.slice(c.region.end); }],
    ['normal pending claim', state => { state.session.set('railtime.template.inserted.v1', 'pending:unresolved'); }],
]) test('probe preflight refuses ' + name + ' before any probe claim or Office body write', async () => {
    const box = harness(); change(box.state); const original = box.state.body;
    await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 0); assert.equal(box.state.body, original);
    assert.equal(box.state.session.has(HEAD_PROBE_SESSION_KEY), false);
});

for (const options of [{ composeType: 'reply' }, { composeType: 'forward' }, { format: 'text' }]) test('probe rejects non-new HTML context ' + JSON.stringify(options), async () => {
    const box = harness(options); await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 0);
});

test('probe checks actual From and signed-in/bound identity rather than the visible profile label', async () => {
    const box = harness(); box.item.from.getAsync = callback => callback(success({ emailAddress: 'delegate@example.invalid' }));
    await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 0);
    box.office.context.mailbox.userProfile.emailAddress = 'other@example.invalid'; assert.equal(headProbeContextAvailable(box.office, binding), false);
});

test('a draft edit after the dedicated claim stops the write and retains its one-attempt pending lock', async () => {
    const box = harness({ afterSession(state, key) { if (key === HEAD_PROBE_SESSION_KEY) state.body += '<p>Concurrent edit</p>'; } });
    await assert.rejects(box.invoke(), error => error.code === 'HEAD_PROBE_UNCONFIRMED');
    assert.equal(box.state.writes.length, 0); assert.match(box.state.session.get(HEAD_PROBE_SESSION_KEY), /^pending:head-probe:/);
    await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 0);
});

for (const options of [{ throwWrite: true }, { writeFailure: true }, { rewrite: () => '' }]) test('uncertain/failed probe write never retries or clears claims: ' + JSON.stringify(options), async () => {
    const box = harness(options); await assert.rejects(box.invoke(), error => error.code === 'HEAD_PROBE_UNCONFIRMED');
    assert.equal(box.state.writes.length, 1); assert.equal(box.state.bodyWrites, 0); assert.match(box.state.session.get(HEAD_PROBE_SESSION_KEY), /^pending:/);
    assert.equal(headTransportProbeReport(box.item).state, 'uncertain'); await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 1);
    if (options.throwWrite) assert.equal(hasUncertainWrite(box.item), true);
    const reopened = harness({ session: box.state.session }); await assert.rejects(reopened.invoke()); assert.equal(reopened.state.writes.length, 0);
});

test('a clearly retained probe with discarded HEAD CSS is a completed negative experiment, not a production signature ACK', async () => {
    const box = harness({ rewrite: html => html.replace(/<style\b[^>]*>[\s\S]*?<\/style>/, '') });
    const report = await box.invoke(); assert.equal(report.state, 'complete'); assert.equal(report.rectangles, true);
    assert.equal(report.exactCss, false); assert.equal(report.mediaRetained, false); assert.equal(report.probeHeadStyles, 0);
    assert.equal(wasSignatureWriteConfirmed(box.item), false);
});

for (const [name, rewrite] of [
    ['duplicate probe', html => html + html],
    ['changed rectangles', html => html.replace('>Alpha<', '>Gamma<')],
    ['BODY stylesheet', html => { const css = /<style\b[^>]*>[\s\S]*?<\/style>/.exec(html)[0]; return html.replace(css, '').replace('<body>', '<body>' + css); }],
    ['duplicate HEAD stylesheet', html => { const css = /<style\b[^>]*>[\s\S]*?<\/style>/.exec(html)[0]; return html.replace('</head>', css + '</head>'); }],
    ['stylesheet inside retained managed root', (html, state) => {
        const css = /<style\b[^>]*>[\s\S]*?<\/style>/.exec(html)[0], contract = fluidLayoutContract(state.body);
        state.body = state.body.slice(0, contract.region.start) + css + state.body.slice(contract.region.start);
        return html.replace(css, '');
    }],
]) test('ambiguous or altered probe readback stays locked: ' + name, async () => {
    const box = harness({ rewrite }); await assert.rejects(box.invoke(), error => error.code === 'HEAD_PROBE_UNCONFIRMED');
    const report = headTransportProbeReport(box.item); assert.equal(report.state, 'uncertain');
    if (name === 'duplicate probe') assert.equal(report.probeCount, 2);
    if (name === 'BODY stylesheet') { assert.equal(report.probeHeadStyles, 0); assert.equal(report.probeBodyStyles, 1); }
    if (name === 'duplicate HEAD stylesheet') assert.equal(report.probeHeadStyles, 2);
    if (name === 'stylesheet inside retained managed root') assert.equal(report.probeBodyStyles, 1);
    assert.equal(box.state.writes.length, 1); assert.match(box.state.session.get(HEAD_PROBE_SESSION_KEY), /^pending:/);
    await assert.rejects(box.invoke()); assert.equal(box.state.writes.length, 1);
});

test('Status UI reuses its diagnostic row and exposes the probe only in the bound IT compose context', async () => {
    const [js, blade] = await Promise.all([readFile(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8'), readFile(new URL('../../resources/views/outlook-addin/taskpane.blade.php', import.meta.url), 'utf8')]);
    assert.match(blade, /data-outlook-head-probe hidden disabled/); assert.match(js, /headProbeButton\?\.addEventListener\('click', \(\) => runHeadTransportProbe/);
    const { document } = parseHTML('<details data-outlook-diagnostics hidden><button data-outlook-head-probe hidden disabled>Probe</button></details>');
    const box = harness(), state = { settingsMode: false, authenticated: true, bootstrapReady: true, busy: false };
    const context = vm.createContext({ document, taskpaneState: state, currentBootstrap: { binding }, Office: box.office,
        headProbeContextAvailable, headTransportProbeReport, insertionBlocked: () => false });
    const start = js.indexOf('function headProbeAvailable()'), end = js.indexOf('function syncAutomaticPreferences()', start);
    vm.runInContext(js.slice(start, end) + '\nglobalThis.syncProbe=syncHeadProbeButton;', context); context.syncProbe();
    const button = document.querySelector('button'); assert.equal(button.hidden, false); assert.equal(button.disabled, false);
    state.busy = true; context.syncProbe(); assert.equal(button.disabled, true);
    state.busy = false; box.office.context.mailbox.userProfile.emailAddress = 'other@example.invalid'; context.syncProbe(); assert.equal(button.hidden, true);
});

for (const [key, value, reason, reads] of [
    ['railtime.template.inserted.v1', 'pending:private-template-token', 'template-session-pending', 1],
    ['railtime.template.inserted.v1', 'private-unrecognized-claim', 'template-session-other', 1],
    ['railtime.signature.layout.v1', 'uncertain:private-signature-token', 'signature-session-pending', 2],
    ['railtime.signature.layout.v1', 'private-unrecognized-claim', 'signature-session-other', 2],
]) test('guard reports only finite ' + reason + ' without another session read or mutation', async () => {
    const box = harness(); box.state.session.set(key, value); const original = box.state.body;
    let failure;
    await assert.rejects(box.invoke(), error => { failure = error; return error.code === 'HEAD_PROBE_ALREADY_ATTEMPTED' && error.phase === 'head-probe-preflight' && error.reason === reason; });
    assert.equal(box.state.writes.length, 0); assert.equal(box.state.bodyWrites, 0); assert.equal(box.state.body, original);
    assert.equal(box.state.sessionReads.length, reads); assert.equal(box.state.sessionWrites.length, 0); assert.equal(box.state.session.get(key), value);
    assert.equal(headTransportProbeReport(box.item), null); assert.equal(box.state.session.has(HEAD_PROBE_SESSION_KEY), false);
    recordDiagnostic('user-action', 'failed', failure);
    const event = diagnosticReport(box.office).events.at(-1);
    assert.equal(event.reason, reason); assert.doesNotMatch(JSON.stringify(event), /private-|@|pending:|uncertain:/);
});

for (const [templateLock, signatureLock, officeUncertain, reason] of [
    [true, false, false, 'preflight-template-lock'],
    [false, true, false, 'preflight-signature-lock'],
    [false, false, true, 'preflight-office-uncertain'],
]) test('entry guard identifies ' + reason + ' before any SDK/session dependency', async () => {
    const source = await readFile(new URL('../../resources/js/outlook-addin/compose-template.js', import.meta.url), 'utf8');
    const start = source.indexOf('export async function insertHeadTransportProbe(');
    const context = vm.createContext({ insertionOperations: { has: () => templateLock }, signatureOperations: { has: () => signatureLock },
        hasUncertainWrite: () => officeUncertain, failure: (code, details) => Object.assign(new Error(code), { code, ...details }) });
    vm.runInContext(source.slice(start).replace(/^export /, '') + '\nglobalThis.probe=insertHeadTransportProbe;', context);
    await assert.rejects(context.probe({}, {}), error => error.code === 'HEAD_PROBE_ALREADY_ATTEMPTED' && error.reason === reason && error.phase === 'head-probe-preflight');
});

test('an existing private probe claim is categorized and retained without reading its token into diagnostics or writing again', async () => {
    const box = harness(); box.state.session.set(HEAD_PROBE_SESSION_KEY, 'pending:private-prior-probe-token');
    await assert.rejects(box.invoke(), error => error.code === 'HEAD_PROBE_UNCONFIRMED' && error.reason === 'prior-probe-claim');
    const report = headTransportProbeReport(box.item);
    assert.equal(report.reason, 'prior-probe-claim'); assert.equal(report.state, 'uncertain');
    assert.equal(box.state.sessionReads.length, 3); assert.equal(box.state.sessionWrites.length, 0); assert.equal(box.state.writes.length, 0);
    assert.equal(box.state.session.get(HEAD_PROBE_SESSION_KEY), 'pending:private-prior-probe-token');
    assert.doesNotMatch(JSON.stringify(report), /private-|@|pending:|[0-9a-f]{32}/);
    await assert.rejects(box.invoke(), error => error.code === 'HEAD_PROBE_ALREADY_ATTEMPTED' && error.reason === 'preflight-signature-lock');
    assert.equal(box.state.sessionReads.length, 3); assert.equal(box.state.sessionWrites.length, 0); assert.equal(box.state.writes.length, 0);
});
