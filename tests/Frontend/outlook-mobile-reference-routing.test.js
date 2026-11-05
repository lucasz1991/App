import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import * as composeHelp from '../../resources/js/outlook-addin/u2e-compose-help.js';
import { universalComposeReport } from '../../resources/js/outlook-addin/u2e-compose.js';
import * as compose from '../../resources/js/outlook-addin/compose-template.js';
import * as mailboxGuard from '../../resources/js/outlook-addin/mailbox-guard.js';
import * as officeWrites from '../../resources/js/outlook-addin/office-write.js';
import * as diagnostics from '../../resources/js/outlook-addin/diagnostics.js';
import * as automaticPolicy from '../../resources/js/outlook-addin/automatic-compose-policy.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { mobileV17OfficeWire } from '../../resources/js/outlook-addin/v17-office-wire.js';

const ADDRESS = 'employee@company.example';
const MARKER = 'RT-SIGNATURE-MANAGED-V1';
const VERSION = '0123456789abcdef';
const ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const TEXT = '<p>Manual text &amp; punctuation.</p>';
const QUOTE = '<div id="divRplyFwdMsg"><blockquote>Opaque historical bytes &amp; links.</blockquote></div>';
// Protocol-only model, explicitly not a render or native-device fixture.
const modelSignature = `<div class="rt-outlook-signature rt-mobile-ledger rtm"><table role="presentation"><tbody><tr data-rt-artifact-version="v27"><td><table data-rt-desktop-flow="outlook-desktop-flow-v17" data-rt-personal-flow="v1" data-rt-mobile-reference="v1"><tbody><tr><td>Mara Muster</td></tr></tbody></table></td></tr><tr><td><!--${MARKER}--><!--RT-SIGNATURE-VERSION:${VERSION}--><span hidden style="font-size:0;line-height:0;">${MARKER}</span><span hidden style="font-size:0;line-height:0;">RT-SIGNATURE-VERSION:${VERSION}</span></td></tr></tbody></table></div>`;
const modelTemplate = { id: ID, key: ID, isDefault: true, signatureMode: 'native', html: '<p>Published template</p>', media: [],
    composeHtml: `<!--${compose.NATIVE_TEMPLATE_MARKER}--><p>Template only</p>`, composeMedia: [], signature: { html: modelSignature, media: [] },
    mobileComposeDocumentMode: 'combined-native-v1', mobileComposeVersion: VERSION, mobileComposeMedia: [],
    mobileComposeHtml: `<div data-rt-compose-document="combined-native-v1"><!--${compose.COMBINED_TEMPLATE_MARKER}--><!--RT-MOBILE-COMPOSE-VERSION:${VERSION}--><p>Guten Tag,</p>${modelSignature}</div>` };
const success = value => ({ status: 'succeeded', value });

async function runtimeHarness(document, { signatureOnly = false, composeType = 'newMail', initial = TEXT + QUOTE } = {}) {
    const state = { body: initial, signatures: [], prepends: 0, replacements: 0, attachments: 0, session: '', completed: 0 };
    const item = { from: { getAsync: callback => callback(success({ emailAddress: ADDRESS })) },
        getComposeTypeAsync: callback => callback(success({ composeType, coercionType: 'html' })),
        sessionData: { getAsync: (_key, callback) => callback(success(state.session)), setAsync: (_key, value, callback) => { state.session = value; callback(success()); } },
        body: { getTypeAsync: callback => callback(success('html')), getAsync: (_kind, options, callback) => {
            const complete = typeof options === 'function' ? options : callback;
            complete(success(typeof options === 'object' && options.bodyMode === 1 ? state.body.split('<div id="divRplyFwdMsg">')[0] : state.body));
        }, setSignatureAsync: (html, _options, callback) => { state.signatures.push(html); state.body = html + state.body; callback(success()); },
        prependAsync: () => { state.prepends++; throw new Error('Mobile must not prepend the ordinary body'); },
        setAsync: () => { state.replacements++; throw new Error('Mobile must not replace the ordinary body'); } },
        getAttachmentsAsync: callback => callback(success([])), addFileAttachmentFromBase64Async: (_bytes, _name, _options, callback) => { state.attachments++; callback(success('attachment')); },
        notificationMessages: { replaceAsync: (_key, _value, callback) => callback(success()) } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, MailboxEnums: { BodyMode: { HostConfig: 1 } },
        actions: { associate() {} }, onReady() {}, context: { platform: 'iOS', requirements: { isSetSupported() { return true; } },
            mailbox: { item, userProfile: { emailAddress: ADDRESS }, diagnostics: { hostName: 'OutlookIOS', hostVersion: '5.2635.0' } } } };
    const bootstrap = { automaticInsertionEnabled: true, automaticInsertion: { schema: 2, composeType, signature: true, template: !signatureOnly }, marker: MARKER,
        binding: { schema: 1, mailboxAddress: ADDRESS, senderAddress: ADDRESS, allowedSenderAddresses: [ADDRESS] }, automaticTemplateId: signatureOnly ? '' : document.id,
        automaticTemplateChoice: { schema: 1, composeType, selection: signatureOnly ? 'none' : 'default', templateId: signatureOnly ? null : document.id },
        templates: signatureOnly ? [] : [document], signature: document.signature };
    const config = { ready: true, marker: MARKER, auth: { clientId: 'test-client', authority: 'https://login.example.test/tenant', scopes: ['api://test/read'] }, endpoints: { bootstrap: 'https://company.example/outlook-addin/bootstrap' } };
    const context = vm.createContext({ ...composeHelp, universalComposeReport, ...compose, ...mailboxGuard, ...officeWrites, ...diagnostics, ...automaticPolicy, Office: office,
        URL, AbortController, setTimeout, clearTimeout, console: { info() {} }, RAILTIME_OUTLOOK_CONFIG_URL: 'https://company.example/outlook-addin/config.json',
        InteractionRequiredAuthError: class extends Error {}, createNestablePublicClientApplication: async () => ({ getAllAccounts: () => [{ username: ADDRESS }], acquireTokenSilent: async () => ({ accessToken: 'test-only-token' }) }),
        fetch: async url => ({ ok: true, json: async () => String(url).includes('bootstrap') ? bootstrap : config }) });
    const source = await readFile(new URL('../../resources/js/outlook-addin/runtime.js', import.meta.url), 'utf8');
    vm.runInContext(source.replace(/^import\s[\s\S]*?\sfrom\s['"][^'"]+['"];\r?$/gm, ''), context);
    return { state, item, office, run: () => context.onNewMessageComposeHandler({ completed() { state.completed++; } }) };
}

test('mobile reference protocol model preserves the mobile document selector and its exact one-slot limits', () => {
    const selected = compose.mobileCombinedComposeDocument(modelTemplate);
    assert.equal(selected.html, modelTemplate.mobileComposeHtml); assert.deepEqual(selected.media, []);
    assert.throws(() => managedTemplateContract(selected.html), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
    assert.throws(() => standaloneSignatureContract(modelSignature), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
    for (const change of [ { mobileComposeVersion: 'bad' }, { mobileComposeHtml: modelTemplate.mobileComposeHtml + 'x'.repeat(30000) },
        { mobileComposeDocumentMode: 'combined-v1' }, { mobileComposeHtml: modelTemplate.mobileComposeHtml.replace('RT-MOBILE-COMPOSE-VERSION:', 'Unknown-MOBILE-VERSION:') } ]) {
        assert.throws(() => compose.mobileCombinedComposeDocument({ ...modelTemplate, ...change }), error => error.code === 'MOBILE_COMBINED_TEMPLATE_INVALID');
    }
});

for (const composeType of ['newMail', 'reply', 'forward']) test('mobile reference protocol model ' + composeType + ' uses one complete native signature slot and preserves ordinary body bytes', async () => {
    const harness = await runtimeHarness(modelTemplate, { composeType });
    await harness.run();
    assert.deepEqual(harness.state.signatures, [modelTemplate.mobileComposeHtml]);
    assert.equal(harness.state.body, modelTemplate.mobileComposeHtml + TEXT + QUOTE);
    assert.equal(harness.state.session, '1'); assert.equal(harness.state.prepends + harness.state.replacements + harness.state.attachments, 0);
    await harness.run(); assert.equal(harness.state.signatures.length, 1);
});

test('mobile reference standalone protocol model uses the existing direct setter without invoking Desktop layout restoration', async () => {
    const harness = await runtimeHarness(modelTemplate, { signatureOnly: true });
    await harness.run();
    assert.deepEqual(harness.state.signatures, [modelSignature]);
    assert.equal(harness.state.body, modelSignature + TEXT + QUOTE);
    assert.equal(harness.state.prepends + harness.state.replacements + harness.state.attachments, 0);
    await harness.run(); assert.equal(harness.state.signatures.length, 1);
    await assert.rejects(compose.insertStandaloneSignature(harness.office, harness.item, modelSignature), error => error.code === 'SIGNATURE_LAYOUT_UNAVAILABLE');
});


test('the unchanged published Desktop V17 sources keep their independent native material contract', async () => {
    const fixture = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
    for (const [kind, html] of Object.entries(fixture.source)) {
        const contract = kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
        assert.equal(verifyFluidLayoutMaterial(html, contract, true).family, 17);
        const plan = prepareNativeLayoutRestore(html, contract, '51'.repeat(16), { context: { platform: 'OfficeOnline', mailbox: { diagnostics: { hostName: 'OutlookWebApp' } } } });
        assert.equal(plan.family, 17); assert.equal(plan.profile, 'M');
    }
    assert.throws(() => managedTemplateContract(modelTemplate.mobileComposeHtml), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
});


const actual = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-mobile-reference/mobile-reference-v1.json', import.meta.url), 'utf8'));
const selectedPrototype = { ...actual.template, isDefault: true };
test('actual PHP mobile reference wire keeps the existing mobile-only selector and strict Desktop rejection', () => {
    assert.equal(actual.synthetic, true);
    const selected = compose.mobileCombinedComposeDocument(selectedPrototype);
    assert.equal(selected.html, actual.template.mobileComposeHtml);
    assert.match(selected.html, /\bdata-rt-mobile-reference="v1"/);
    assert.match(selected.html, /\bdata-rt-desktop-flow="outlook-desktop-flow-v17"/);
    assert.ok(selected.html.length <= 30000); assert.deepEqual(selected.media, []);
    assert.doesNotMatch(selected.html, /cid:/i);
    assert.throws(() => managedTemplateContract(selected.html), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
    assert.throws(() => standaloneSignatureContract(actual.template.signature.html), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
    for (const [kind, html] of Object.entries(actual.desktop)) {
        assert.doesNotMatch(html, /\bdata-rt-mobile-reference=/);
        const contract = kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
        assert.equal(verifyFluidLayoutMaterial(html, contract, true).family, 17);
    }
});
for (const composeType of ['newMail', 'reply', 'forward']) test('actual PHP mobile reference ' + composeType + ' follows the production one-slot route without rewriting typed or quoted content', async () => {
    const harness = await runtimeHarness(selectedPrototype, { composeType });
    await harness.run();
    assert.equal(harness.state.signatures.length, 1);
    assert.equal(harness.state.signatures[0], mobileV17OfficeWire(actual.template.mobileComposeHtml));
    assert.doesNotMatch(harness.state.signatures[0], /<style\b/i);
    assert.match(harness.state.signatures[0], /width:104px!important/);
    assert.equal(harness.state.body, harness.state.signatures[0] + TEXT + QUOTE);
    assert.equal(harness.state.session, '1');
    assert.equal(harness.state.prepends + harness.state.replacements + harness.state.attachments, 0);
    await harness.run(); assert.equal(harness.state.signatures.length, 1);
});
test('actual PHP mobile signature-only reference keeps the direct native setter and its existing repeat guard', async () => {
    const harness = await runtimeHarness(selectedPrototype, { signatureOnly: true });
    await harness.run();
    assert.equal(harness.state.signatures.length, 1);
    assert.equal(harness.state.signatures[0], compose.signatureOfficeWire(actual.template.signature.html));
    assert.doesNotMatch(harness.state.signatures[0], /<style\b/i);
    assert.match(harness.state.signatures[0], /\bdata-rt-mobile-reference="v1"/);
    assert.equal(harness.state.body, harness.state.signatures[0] + TEXT + QUOTE);
    assert.equal(harness.state.prepends + harness.state.replacements + harness.state.attachments, 0);
    await harness.run(); assert.equal(harness.state.signatures.length, 1);
});
