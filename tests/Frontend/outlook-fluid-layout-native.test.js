import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { fluidLayoutContract, managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate, replaceManagedTemplate, standaloneSignatureLayoutIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
import { nativeOfficeHtml } from './outlook-standalone-fixture.js';

const version = '0123456789abcdef', nonce = '11'.repeat(16);
const meta = value => '<span hidden aria-hidden="true" style="display:none!important;font-size:0;line-height:0;">' + value + '</span>';
const table = (attrs, content, cell = '') => '<table ' + attrs + '><tbody><tr><td ' + cell + '>' + content + '</td></tr></tbody></table>';
function fixture(combined = false, token = version) {
    const column = (role, width, right, content) => table('class="rt-v11-' + role + '" width="' + width + '" align="left" style="width:' + width + 'px;max-width:100%;"', content, 'style="padding:0 ' + right + 'px 0 0;"');
    const direct = table('width="100%"', '<img src="https://mail.example.test/icon.png" width="18" height="15"></td><td style="padding:0;"><a href="mailto:person@example.test">person@example.test</a>', 'width="27" style="width:27px;padding:0;"');
    const train = table('class="rt-v11-train" width="218" style="width:218px;max-width:100%;"', '<div><img src="https://mail.example.test/train.png" width="200" height="43" style="width:100%;max-width:100%;height:auto;"></div>', 'style="padding:0 18px 0 0;"');
    const root = '<table class="rt-personal-columns rt-v11-columns rt-df110123456789" width="100%" style="width:100%;max-width:734px;" data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v11"><tbody><tr><td style="padding:10px 0 0 18px;font-size:0;line-height:0;">'
        + column('identity', 176, 16, '<img src="https://mail.example.test/logo.png" width="160" height="27"><p>Synthetic person</p><p>Role</p>')
        + column('direct', 204, 0, direct) + column('company', 172, 12, '<p>Company address</p><p>Company contact</p>')
        + column('dispatch', 164, 4, '<p>Dispo 24/7 erreichbar</p><a href="tel:+490123456789">0123456789</a>')
        + '<br clear="all" style="font-size:0;line-height:0;"></td></tr><tr><td style="padding:0;">' + train + '</td></tr></tbody></table>';
    const legal = table('width="100%"', '<p>Synthetic legal content</p>', 'style="padding:0 18px;"');
    const signature = '<table class="rt-managed-signature-v1 rts0123456789" width="100%"><tbody><tr><td>' + root + '</td></tr><tr><td style="color:#718096;">' + legal
        + meta('RT-SIGNATURE-MANAGED-V1') + meta('RT-SIGNATURE-VERSION:' + token) + '</td></tr></tbody></table>';
    if (!combined) return signature;
    const template = meta('RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT') + table('class="rt-compose-editable rt-compose-editable-v1"', meta('RT-COMPOSE-EDITABLE-START-V1') + '<p>Preserved editable text</p>' + meta('RT-COMPOSE-EDITABLE-END-V1'));
    return table('class="rt-managed-combined-v1" width="100%"', table('class="rt-managed-template-v1" width="100%"', template) + signature);
}

// Explicitly a model, not a native Classic SDK capture: remove stylesheet and
// identity attributes, omit optional TBODYs, and retain the actual HTML floats.
function wordProfile(html) {
    return nativeOfficeHtml(html).replace(/<\/?tbody>/g, '').replace(/width:(\d+)px/g, (_match, width) => 'width:' + Number(width) * 0.75 + 'pt')
        .replace('<br clear="all" style="font-size:0;line-height:0;">', '<div style="font-size:0;line-height:0;"><br></div>');
}

function host(rewrite = wordProfile) {
    const state = { signature: '', body: '<p>Unknown user bytes</p><blockquote>Unknown history</blockquote>', signatures: 0, prepends: 0, replacements: 0, sessions: new Map() };
    const ok = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: cb => cb(ok({ composeType: 'newMail' })), sessionData: {
        getAsync: (key, cb) => cb(ok(state.sessions.get(key) || '')), setAsync: (key, value, cb) => { state.sessions.set(key, value); cb(ok()); },
    }, body: { getTypeAsync: cb => cb(ok('html')), getAsync: (_type, cb) => cb(ok(state.body)),
        setSignatureAsync: (html, _options, cb) => { state.signatures++; if (state.signature) state.body = state.body.replace(state.signature, ''); state.signature = rewrite(html); state.body = state.signature + state.body; cb(ok()); },
        prependAsync: (html, _options, cb) => { state.prepends++; state.body = rewrite(html) + state.body; cb(ok()); },
        setAsync: (html, _options, cb) => { state.replacements++; state.body = rewrite(html); cb(ok()); },
    } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform: 'PC', requirements: { isSetSupported: () => true }, mailbox: { item, diagnostics: { hostName: 'Outlook' } } } };
    return { state, item, office };
}

test('v11 binds real four-table material with a166-character nonce and no CSS IDs or carrier', () => {
    for (const combined of [false, true]) {
        const html = fixture(combined), source = combined ? managedTemplateContract(html) : standaloneSignatureContract(html);
        const plan = prepareNativeLayoutRestore(html, source, nonce);
        assert.equal(plan.sourceOnly, 'fluid-v11'); assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
        assert.equal(plan.html.length - html.length, 166);
        assert.doesNotMatch(plan.html, /rt-native-layout-/);
        const actual = wordProfile(plan.html), proof = fluidLayoutContract(actual, actual, nonce, version);
        assert.equal(verifyFluidLayoutMaterial(actual, proof).sourceOnly, true);
        assert.equal(proof.fluidLayout.nonce, nonce);
        assert.equal(actual.includes('id="'), false, 'No lost CSS IDs are virtually restored.');
    }
});

test('v11 accepts only exact hidden merged metadata, preserving quote ownership', () => {
    const html = fixture(), plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce);
    const merged = wordProfile(plan.html).replace(/(<span[^>]*>RT-SIGNATURE-MANAGED-V1)<\/span><span[^>]*>(RT-SIGNATURE-VERSION:[^<]+)<\/span><span[^>]*>(RT-FLUID-LAYOUT-V11:[^<]+)<\/span>/, '$1 $2 $3</span>');
    const quoted = merged + '<blockquote>' + merged + '</blockquote>';
    assert.equal(verifyFluidLayoutMaterial(quoted, fluidLayoutContract(quoted, quoted, nonce, version)).sourceOnly, true);
    assert.throws(() => fluidLayoutContract(merged + merged));
    assert.throws(() => fluidLayoutContract(merged + '<p>RT-FLUID-LAYOUT-V11:</p>'));
    assert.throws(() => fluidLayoutContract(merged, merged, '22'.repeat(16), version));
    assert.throws(() => fluidLayoutContract(merged, merged, nonce, 'ff'.repeat(8)));
});

test('v11 source and readback reject hard roots, wrong role widths, duplicate floats, changed gutters and unsafe trains', () => {
    const mutations = [
        html => html.replace('max-width:734px', 'max-width:735px'),
        html => html.replace('max-width:734px', 'min-width:900px;max-width:734px'),
        html => html.replace('width:204px;', 'position:absolute;width:204px;'),
        html => html.replace('width:204px;', 'margin-left:100px;width:204px;'),
        html => html.replace('width="204"', 'width="205"'),
        html => html.replace('width="100%" style="width:100%;max-width:734px;', 'width="720" style="width:720px;max-width:734px;'),
        html => html.replace('padding:10px 0 0 18px', 'padding:10px 0 0 40px'),
        html => html.replace('width="27"', 'width="28"'),
        html => html.replace('<td style="padding:0;"><a', '<td style="padding:0 9px;"><a'),
        html => html.replace('<a href="mailto:', '<a style="white-space:nowrap;" href="mailto:'),
        html => html.replace('width="218"', 'width="520"'),
        html => html.replace('width="200" height="43"', 'width="480" height="103"'),
        html => html.replace('<br clear="all" style="font-size:0;line-height:0;"></td></tr><tr><td style="padding:0;">', '</td></tr><tr><td style="padding:0;">'),
        html => html.replace('<br clear="all" style="font-size:0;line-height:0;">', '').replace('<table class="rt-v11-identity"', '<br clear="all" style="font-size:0;line-height:0;"><table class="rt-v11-identity"'),
    ];
    for (const mutate of mutations) {
        const changed = mutate(fixture());
        assert.throws(() => prepareNativeLayoutRestore(changed, standaloneSignatureContract(changed), nonce));
    }
    const html = fixture(), plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce);
    for (const mutate of mutations.slice(1)) {
        const changed = wordProfile(mutate(plan.html));
        assert.throws(() => verifyFluidLayoutMaterial(changed, fluidLayoutContract(changed, changed, nonce, version)));
    }
    const nested = fixture().replace('<table width="100%">', '<table width="100%" align="left">');
    assert.throws(() => prepareNativeLayoutRestore(nested, standaloneSignatureContract(nested), nonce));
});

test('v11 native-slot write verifies actual material without any carrier prepend or full-body rewrite', async () => {
    const { state, item, office } = host();
    await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
    assert.equal(state.signatures, 1); assert.equal(state.prepends, 0); assert.equal(state.replacements, 0);
    assert.equal(standaloneSignatureLayoutIsCurrent(state.body, state.body, version), true);
    assert.ok(state.body.endsWith('<p>Unknown user bytes</p><blockquote>Unknown history</blockquote>'));
    await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
    assert.equal(state.signatures, 1, 'An already proven matching version is not reinserted.');
});

test('v11 wrong SDK material hard-fails after the single write and blocks blind reinsertion', async () => {
    const { state, item, office } = host(html => wordProfile(html.replace('width="204"', 'width="205"')));
    await assert.rejects(insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(state.signatures, 1); assert.equal(state.prepends, 0); assert.equal(state.replacements, 0);
    assert.equal(isStandaloneSignatureInsertionBlocked(item), true);
    assert.equal(standaloneSignatureLayoutIsCurrent(state.body, state.body, version), false);
    await assert.rejects(insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] }));
    assert.equal(state.signatures, 1);
});

test('v11 actual OWA DOM serialization retains the bounded train width while omitting its height attribute', () => {
    // Sanitized from an actual OWA compose DOM capture, not Office SDK evidence.
    const html = readFileSync(new URL('./fixtures/outlook-v11-owa-rewritten.html', import.meta.url), 'utf8');
    const contract = fluidLayoutContract(html, html, nonce, version);
    assert.equal(contract.kind, 'text-v1');
    assert.equal(verifyFluidLayoutMaterial(html, contract).sourceOnly, true);
    const image = contract.elements.find(node => node.tag === 'img' && node.attrs.width === '200');
    assert.ok(image);
    assert.equal(image.attrs.height, undefined);
    assert.match(image.attrs.style, /height:\s*auto/);
    for (const replacement of ['width="201"', 'width="200" height="44"']) {
        const changed = html.slice(0, image.start) + html.slice(image.start, image.openEnd).replace('width="200"', replacement) + html.slice(image.openEnd);
        assert.throws(() => verifyFluidLayoutMaterial(changed, fluidLayoutContract(changed, changed, nonce, version)));
    }
});

test('v11 explicit combined replacement preserves editable text and outside history without a carrier', async () => {
    const { state, item, office } = host();
    const outside = state.body;
    await prependTemplate(office, item, fixture(true), () => {}, { media: [] });
    assert.equal(state.prepends, 1); assert.equal(state.replacements, 0);
    const current = fluidLayoutContract(state.body);
    assert.equal(verifyFluidLayoutMaterial(state.body, current).sourceOnly, true);
    const edited = '<p>Typed &amp; <b>kept exactly</b>.</p>';
    state.body = state.body.slice(0, current.region.start) + edited + state.body.slice(current.region.end);
    await replaceManagedTemplate(office, item, fixture(true, 'ff'.repeat(8)), () => {}, { media: [] });
    assert.equal(state.prepends, 1); assert.equal(state.replacements, 1);
    assert.ok(state.body.includes(edited)); assert.ok(state.body.endsWith(outside));
    const updated = fluidLayoutContract(state.body);
    assert.equal(updated.fluidLayout.version, 'ff'.repeat(8));
    assert.equal(verifyFluidLayoutMaterial(state.body, updated).sourceOnly, true);
});
