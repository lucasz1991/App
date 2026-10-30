import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { fluidLayoutContract, managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { outlookSignatureDeliveryProfile, prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate, replaceManagedTemplate, signatureHostProfileIsCurrent, standaloneSignatureLayoutIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
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

function flatFixture(combined = false, token = version) {
    return fixture(combined, token).replaceAll('rt-v11-', 'rt-v12-').replaceAll('rt-df11', 'rt-df12')
        .replace('outlook-desktop-flow-v11', 'outlook-desktop-flow-v12')
        .replace('rt-personal-columns rt-v12-columns', 'rt-sign-content-frame rt-personal-columns rt-v12-columns')
        .replace('max-width:734px;', '');
}

function referenceFixture(combined = false, token = version) {
    const row = (href, label) => '<tr><td width="27" style="width:27px;padding:0;"><img src="https://mail.example.test/icon.png" width="18" height="18"></td><td style="padding:0;">'
        + (href ? '<a href="' + href + '">' + label + '</a>' : label) + '</td></tr>';
    const contact = rows => '<table width="100%"><tbody>' + rows + '</tbody></table>';
    const column = (role, width, right, content) => table('class="rt-v13-' + role + '" width="' + width + '" align="left" style="width:' + width + 'px;max-width:100%;"', content, 'style="padding:0 ' + right + 'px 0 0;"');
    const block = table('class="rt-v13-dispatch-block" width="100%" style="width:100%;max-width:227px;"',
        '<img src="https://mail.example.test/caption.png" width="199" height="28" style="width:199px;max-width:100%!important;height:auto;">'
        + '<table width="100%"><tbody><tr><td><a href="mailto:office@example.test"><img src="https://mail.example.test/mail.png" width="25" height="25" style="width:25px;height:25px;"></a></td>'
        + '<td><a href="tel:+490123456789"><img src="https://mail.example.test/phone.png" width="25" height="25" style="width:25px;height:25px;"></a></td>'
        + '<td><a href="tel:+490123456789"><span style="font-size:13px;">+490123456789</span></a></td></tr></tbody></table>',
        'style="padding:0;"');
    const train = table('width="547" style="width:547px;max-width:100%!important;"', '<img src="https://mail.example.test/train.png" width="529" height="115" style="width:529px;max-width:100%!important;height:auto;">', 'style="padding:0 18px 0 0;"');
    const root = '<table class="rt-sign-content-frame rt-personal-columns rt-v13-columns rt-df130123456789" width="100%" style="width:100%;" data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v13"><tbody><tr><td style="padding:12px 0 0 18px;font-size:0;line-height:0;">'
        + column('identity', 200, 16, '<img src="https://mail.example.test/logo.png" width="184" height="32" style="width:184px;max-width:100%;height:auto;"><p>Synthetic person</p><p>Role</p>')
        + column('direct', 260, 16, contact(row('mailto:person@example.test', 'person@example.test') + row('tel:+490123456789', '0123456789')))
        + column('company', 230, 16, contact(row('https://example.test', 'example.test') + row(null, 'Synthetic address<br>Synthetic city')))
        + column('dispatch', 247, 20, block)
        + '<br clear="all" style="font-size:0;line-height:0;"></td></tr><tr><td style="padding:0;">' + train + '</td></tr></tbody></table>';
    const legal = table('width="100%"', '<p>Synthetic legal content</p>', 'style="padding:0 18px;"');
    const signature = '<table class="rt-managed-signature-v1 rts0123456789" width="100%"><tbody><tr><td>' + root + '</td></tr><tr><td>' + legal
        + meta('RT-SIGNATURE-MANAGED-V1') + meta('RT-SIGNATURE-VERSION:' + token) + '</td></tr></tbody></table>';
    if (!combined) return signature;
    const template = meta('RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT') + table('class="rt-compose-editable rt-compose-editable-v1"', meta('RT-COMPOSE-EDITABLE-START-V1') + '<p>Preserved editable text</p>' + meta('RT-COMPOSE-EDITABLE-END-V1'));
    return table('class="rt-managed-combined-v1" width="100%"', table('class="rt-managed-template-v1" width="100%"', template) + signature);
}

function dispatchCardFixture(combined = false, token = version) {
    const fill = 'https://mail.example.test/m/3ee5033b5b.png';
    const paint = 'background-color:#e90032;background-image:url(' + fill + ');background-repeat:repeat;border-radius:8px;';
    const card = table('class="rt-v14-dispatch-block" data-rt-dispatch-card="v14" width="100%" bgcolor="#e90032" background="' + fill + '" style="width:100%;max-width:227px;' + paint + '"',
        '<img src="https://mail.example.test/m/464054a84f.png" width="177" height="34" style="width:min(199px,100%)!important;max-width:100%!important;height:auto!important;">'
        + '<table width="100%" style="width:100%;border-top:1px solid #ff6b8b;"><tbody><tr><td><a href="mailto:office@example.test" style="color:#ffffff;"><img src="https://mail.example.test/m/36ecdeda31.png" width="25" height="25" style="width:25px;height:25px;"></a></td>'
        + '<td><a href="tel:+490123456789" style="color:#ffffff;"><img src="https://mail.example.test/m/a3564d9bc8.png" width="25" height="25" style="width:25px;height:25px;"></a></td>'
        + '<td><a href="tel:+490123456789" style="color:#ffffff;"><span style="font-size:12px;color:#ffffff;">+490123456789</span></a></td></tr></tbody></table>',
        'data-rt-dispatch-card="v14" bgcolor="#e90032" background="' + fill + '" style="padding:8px 14px 5px;' + paint + '"');
    const base = referenceFixture(combined, token);
    const opening = base.indexOf('<table class="rt-v13-dispatch-block"');
    const closing = base.indexOf('</table></td></tr></tbody></table>', opening) + '</table></td></tr></tbody></table>'.length;
    assert.ok(opening > 0 && closing > opening);
    return (base.slice(0, opening) + card + base.slice(closing)).replaceAll('rt-v13-', 'rt-v14-')
        .replaceAll('rt-df13', 'rt-df14').replaceAll('outlook-desktop-flow-v13', 'outlook-desktop-flow-v14')
        .replace('width="547" style="width:547px;max-width:100%!important;"', 'width="242" style="width:min(547px,100%)!important;max-width:100%!important;"')
        .replace('width="529" height="115" style="width:529px;max-width:100%!important;height:auto;"',
            'width="224" height="49" style="width:min(529px,100%)!important;max-width:100%!important;height:auto!important;"');
}

function hostProfileFixture(combined = false, token = version) {
    return dispatchCardFixture(combined, token).replaceAll('rt-v14-', 'rt-v15-').replaceAll('rt-df14', 'rt-df15')
        .replaceAll('outlook-desktop-flow-v14', 'outlook-desktop-flow-v15').replaceAll('data-rt-dispatch-card="v14"', 'data-rt-dispatch-card="v15"')
        .replace('width:min(547px,100%)!important;', 'width:242px;')
        .replace('width:min(529px,100%)!important;', 'width:224px;')
        .replace('width:min(199px,100%)!important;', 'width:177px;')
        .replace('https://mail.example.test/train.png', 'https://mail.example.test/m/4700745390.gif');
}

function modernHost(rewrite = wordProfile) {
    const result = host(rewrite);
    result.office.context.platform = 'OfficeOnline';
    result.office.context.mailbox.diagnostics.hostName = 'OutlookWebApp';
    return result;
}

function swapReferenceContactRows(html, index) {
    const contacts = [...html.matchAll(/<table width="100%"><tbody>(<tr><td width="27"[\s\S]*?)<\/tbody><\/table>/g)];
    const contact = contacts[index], rows = [...contact[1].matchAll(/<tr>[\s\S]*?<\/tr>/g)].map(match => match[0]);
    assert.equal(rows.length, 2);
    return html.slice(0, contact.index) + contact[0].replace(contact[1], rows[1] + rows[0]) + html.slice(contact.index + contact[0].length);
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

test('v12 binds the flat surviving fluid content frame and preserves the v11 source protocol', () => {
    for (const combined of [false, true]) {
        const html = flatFixture(combined), contract = combined ? managedTemplateContract(html) : standaloneSignatureContract(html);
        const plan = prepareNativeLayoutRestore(html, contract, nonce);
        assert.equal(plan.sourceOnly, 'fluid-v12'); assert.equal(plan.family, 12);
        assert.equal(plan.html.length - html.length, 166); assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
        const retained = wordProfile(plan.html), owned = fluidLayoutContract(retained, retained, nonce, version);
        assert.equal(owned.fluidLayout.family, 12);
        assert.equal(verifyFluidLayoutMaterial(retained, owned).family, 12);
        for (const changed of [html.replace('rt-sign-content-frame ', ''), html.replace('width:100%;', 'width:720px;'),
            html.replace('width:100%;', 'width:100%;max-width:734px;'), html.replace('rt-df12', 'rt-df11')]) {
            assert.throws(() => prepareNativeLayoutRestore(changed, combined ? managedTemplateContract(changed) : standaloneSignatureContract(changed), nonce));
        }
    }
    const legacy = fixture(), previous = prepareNativeLayoutRestore(legacy, standaloneSignatureContract(legacy), nonce);
    assert.equal(previous.sourceOnly, 'fluid-v11');
    assert.ok(previous.html.includes('RT-FLUID-LAYOUT-V11:' + nonce + ':' + version));
    assert.equal(previous.html.length - legacy.length, 166);
});

test('v12 verifies native-slot writes and explicit combined replacement without restoring styles', async () => {
    const standalone = host();
    await insertStandaloneSignature(standalone.office, standalone.item, flatFixture(), () => {}, { media: [] });
    assert.equal(standalone.state.signatures, 1); assert.equal(standalone.state.prepends, 0); assert.equal(standalone.state.replacements, 0);
    assert.equal(fluidLayoutContract(standalone.state.body).fluidLayout.family, 12);
    const combined = host(), outside = combined.state.body;
    await prependTemplate(combined.office, combined.item, flatFixture(true), () => {}, { media: [] });
    await replaceManagedTemplate(combined.office, combined.item, flatFixture(true, 'ff'.repeat(8)), () => {}, { media: [] });
    assert.equal(combined.state.prepends, 1); assert.equal(combined.state.replacements, 1);
    assert.ok(combined.state.body.endsWith(outside));
    assert.equal(verifyFluidLayoutMaterial(combined.state.body, fluidLayoutContract(combined.state.body)).family, 12);
    const wrongFamily = host(html => wordProfile(html.replace('RT-FLUID-LAYOUT-V12:', 'RT-FLUID-LAYOUT-V11:')));
    await assert.rejects(insertStandaloneSignature(wrongFamily.office, wrongFamily.item, flatFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(wrongFamily.state.signatures, 1);
    assert.equal(isStandaloneSignatureInsertionBlocked(wrongFamily.item), true);
});

test('v13 independently pins reference groups, contact order, source train bounds and family ownership', () => {
    for (const combined of [false, true]) {
        const html = referenceFixture(combined), source = combined ? managedTemplateContract(html) : standaloneSignatureContract(html);
        const plan = prepareNativeLayoutRestore(html, source, nonce);
        assert.equal(plan.sourceOnly, 'fluid-v13'); assert.equal(plan.family, 13);
        assert.equal(plan.html.length - html.length, 166); assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
        const filtered = wordProfile(plan.html);
        assert.equal(verifyFluidLayoutMaterial(filtered, fluidLayoutContract(filtered, filtered, nonce, version)).family, 13);
        for (const changed of [html.replace('width="260"', 'width="261"'), html.replace('padding:12px 0 0 18px', 'padding:10px 0 0 18px'),
            html.replace('width="184"', 'width="160"'), html.replace('https://example.test', 'mailto:person@example.test'),
            html.replace('width="547"', 'width="218"'), html.replace('width="529"', 'width="200"'),
            html.replace('height="115"', 'height="116"'), html.replace('max-width:100%!important', 'max-width:100%'),
            html.replace('style="width:529px;max-width:100%!important;height:auto;"', 'style="width:529px;height:auto;"'),
            html.replace('style="width:100%;max-width:227px;"', 'style="width:227px;max-width:100%;"'),
            html.replace('max-width:227px;', 'min-width:900px;max-width:227px;'),
            html.replace('max-width:227px;', 'max-width:228px;'), html.replace('width="199"', 'width="198"'), html.replace('height="28"', 'height="29"'),
            html.replace('width="199"', 'width="invalid"'), html.replace('width:199px;', 'width:199px;min-width:900px;'), html.replace('width="25"', 'width="24"'),
            html.replace('font-size:13px;', 'font-size:12px;'), html.replace('class="rt-v13-dispatch-block"', 'class="rt-v13-dispatch-card" bgcolor="#e90032"'),
            html.replace('>+490123456789</span>', '>+490123456780</span>'),
            swapReferenceContactRows(html, 0), swapReferenceContactRows(html, 1)]) {
            assert.throws(() => prepareNativeLayoutRestore(changed, combined ? managedTemplateContract(changed) : standaloneSignatureContract(changed), nonce));
        }
    }
});

test('v13 writes once and rejects an incorrect family callback without touching manual text or quoted history', async () => {
    const state = host();
    await insertStandaloneSignature(state.office, state.item, referenceFixture(), () => {}, { media: [] });
    assert.equal(state.state.signatures, 1); assert.equal(state.state.prepends, 0); assert.equal(state.state.replacements, 0);
    assert.ok(state.state.body.endsWith('<p>Unknown user bytes</p><blockquote>Unknown history</blockquote>'));
    const combined = host(), outside = combined.state.body;
    await prependTemplate(combined.office, combined.item, referenceFixture(true), () => {}, { media: [] });
    await replaceManagedTemplate(combined.office, combined.item, referenceFixture(true, 'ff'.repeat(8)), () => {}, { media: [] });
    assert.equal(combined.state.prepends, 1); assert.equal(combined.state.replacements, 1); assert.ok(combined.state.body.endsWith(outside));
    const altered = host(html => wordProfile(html.replace('RT-FLUID-LAYOUT-V13:', 'RT-FLUID-LAYOUT-V12:')));
    await assert.rejects(insertStandaloneSignature(altered.office, altered.item, referenceFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(altered.state.signatures, 1); assert.equal(isStandaloneSignatureInsertionBlocked(altered.item), true);
    const formattedPhone = host(html => wordProfile(html.replace('>+490123456789</span>', '>+49 0123 456 789</span>')));
    await insertStandaloneSignature(formattedPhone.office, formattedPhone.item, referenceFixture(), () => {}, { media: [] });
    assert.equal(formattedPhone.state.signatures, 1);
    const wrongPhone = host(html => wordProfile(html.replace('>+490123456789</span>', '>+490123456780</span>')));
    await assert.rejects(insertStandaloneSignature(wrongPhone.office, wrongPhone.item, referenceFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(wrongPhone.state.signatures, 1); assert.equal(isStandaloneSignatureInsertionBlocked(wrongPhone.item), true);
    const unexpectedFill = host(html => wordProfile(html.replace('max-width:227px;', 'background-color:#e90032;max-width:227px;')));
    await assert.rejects(insertStandaloneSignature(unexpectedFill.office, unexpectedFill.item, referenceFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(unexpectedFill.state.signatures, 1);
    for (const mutate of [html => html.replace('max-width:227px;', 'max-width:228px;'),
        html => html.replace('max-width:227px;', 'min-width:900px;max-width:227px;'),
        html => html.replace('width:100%;max-width:227px;', 'width:227px;max-width:100%;'),
        html => html.replace('width:199px;max-width:100%!important;', 'width:199px;')]) {
        const changedBounds = host(html => wordProfile(mutate(html)));
        await assert.rejects(insertStandaloneSignature(changedBounds.office, changedBounds.item, referenceFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(changedBounds.state.signatures, 1);
        assert.equal(isStandaloneSignatureInsertionBlocked(changedBounds.item), true);
    }
});

test('v14 separately binds the red dispatch card and retains the 166-character source-only ownership contract', () => {
    for (const combined of [false, true]) {
        const html = dispatchCardFixture(combined), source = combined ? managedTemplateContract(html) : standaloneSignatureContract(html);
        const plan = prepareNativeLayoutRestore(html, source, nonce);
        assert.equal(plan.sourceOnly, 'fluid-v14'); assert.equal(plan.family, 14);
        assert.equal(plan.html.length - html.length, 166); assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
        const filtered = wordProfile(plan.html);
        assert.equal(verifyFluidLayoutMaterial(filtered, fluidLayoutContract(filtered, filtered, nonce, version)).family, 14);
        for (const changed of [html.replace('padding:8px 14px 5px;', 'padding:8px 13px 5px;'),
            html.replace('height="34"', 'height="38"'), html.replace('width="177"', 'width="199"'),
            html.replaceAll('3ee5033b5b.png', 'unknown.png'), html.replace('3ee5033b5b.png);', '3ee5033b5b.png),url(https://mail.example.test/unknown.png);'),
            html.replace('36ecdeda31.png', 'dcf209bd17.png'), html.replace('background-color:#e90032;', 'background-color:#000000;'),
            html.replace('bgcolor="#e90032"', 'bgcolor="#ffffff"'), html.replace('border-radius:8px;', 'border-radius:20px;'),
            html.replace('border-top:1px solid #ff6b8b;', 'border-top:2px solid #ff6b8b;'),
            html.replace('font-size:12px;', 'font-size:13px;'), html.replaceAll('color:#ffffff;', 'color:#e90032;'),
            html.replace('>+490123456789</span>', '>+490123456780</span>'),
            html.replace('min(529px,100%)', 'min(530px,100%)'), html.replace('min(199px,100%)', 'min(200px,100%)'),
            html.replace('width="242"', 'width="547"'), html.replace('min(547px,100%)', 'min(548px,100%)'),
            html.replace('width="224"', 'width="529"'), html.replace('height="49"', 'height="115"'),
            html.replace('min(529px,100%)!important;', 'min(529px,100%);')]) {
            assert.throws(() => prepareNativeLayoutRestore(changed, combined ? managedTemplateContract(changed) : standaloneSignatureContract(changed), nonce));
        }
    }
});

test('v14 source-only insertion preserves manual and quoted bytes and fails closed on changed card or lost train bounds', async () => {
    const standalone = host(), outside = standalone.state.body;
    await insertStandaloneSignature(standalone.office, standalone.item, dispatchCardFixture(), () => {}, { media: [] });
    assert.equal(standalone.state.signatures, 1); assert.equal(standalone.state.replacements, 0); assert.equal(standalone.state.prepends, 0);
    assert.ok(standalone.state.body.endsWith(outside));
    const combined = host();
    await prependTemplate(combined.office, combined.item, dispatchCardFixture(true), () => {}, { media: [] });
    await replaceManagedTemplate(combined.office, combined.item, dispatchCardFixture(true, 'ff'.repeat(8)), () => {}, { media: [] });
    assert.equal(combined.state.prepends, 1); assert.equal(combined.state.replacements, 1); assert.ok(combined.state.body.endsWith(outside));
    for (const mutate of [html => html.replace('RT-FLUID-LAYOUT-V14:', 'RT-FLUID-LAYOUT-V13:'),
        html => html.replace('padding:8px 14px 5px;', 'padding:8px 0 5px;'),
        html => html.replaceAll('3ee5033b5b.png', 'unknown.png'),
        html => html.replace('36ecdeda31.png', 'dcf209bd17.png'),
        html => html.replace('>+490123456789</span>', '>+490123456780</span>'),
        html => html.replace('min(529px,100%)', 'min(900px,100%)'),
        html => html.replace('min(199px,100%)', 'min(900px,100%)')]) {
        const altered = host(html => wordProfile(mutate(html)));
        await assert.rejects(insertStandaloneSignature(altered.office, altered.item, dispatchCardFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(altered.state.signatures, 1); assert.equal(isStandaloneSignatureInsertionBlocked(altered.item), true);
        assert.ok(altered.state.body.endsWith(outside));
    }
});

test('v14 accepts only its numeric image bases after the observed Word inch rewrite, never an unbounded modern image', async () => {
    // This is a model of the units/style loss measured in the actual V13 MIME,
    // applied to the new V14 safe bases; it is not native V14 acceptance.
    const boundedWordImages = html => wordProfile(html
        .replace('width:min(547px,100%)!important;max-width:100%!important;', 'width:2.5208in;')
        .replace('width:min(529px,100%)!important;max-width:100%!important;height:auto!important;', 'width:2.3333in;height:.5104in;')
        .replace('width:min(199px,100%)!important;max-width:100%!important;height:auto!important;', 'width:1.8437in;height:.3541in;')
        .replace('width="100%" bgcolor="#e90032"', 'bgcolor="#e90032"')
        .replace('width:100%;max-width:227px;', 'max-width:227px;')
        .replace('width:184px;max-width:100%;height:auto;', 'width:1.9166in;height:.3333in;')
        .replaceAll('width:25px;height:25px;', 'width:.2604in;height:.2604in;'));
    const sample = dispatchCardFixture(), plan = prepareNativeLayoutRestore(sample, standaloneSignatureContract(sample), nonce);
    const filtered = boundedWordImages(plan.html);
    assert.equal(verifyFluidLayoutMaterial(filtered, fluidLayoutContract(filtered, filtered, nonce, version)).family, 14);
    const state = host(boundedWordImages);
    await insertStandaloneSignature(state.office, state.item, dispatchCardFixture(), () => {}, { media: [] });
    assert.equal(state.state.signatures, 1); assert.equal(state.state.prepends, 0); assert.equal(state.state.replacements, 0);
    for (const mutate of [html => html.replace('width="224" height="49"', 'width="529" height="115"').replace('width:2.3333in;height:.5104in;', 'width:5.5104in;height:1.1979in;'),
        html => html.replace('width="177" height="34"', 'width="199" height="38"').replace('width:1.8437in;height:.3541in;', 'width:2.0729in;height:.3958in;'),
        html => html.replace('width:2.3333in;', 'width:3in;'), html => html.replace('max-width:170.25pt;', 'max-width:171pt;'),
        html => html.replace('width:.2604in;', 'width:.5in;'), html => html.replace('width:1.9166in;', 'width:3in;'),
        html => html.replace('width="242"', 'width="547"').replace('width:2.5208in;', 'width:5.6979in;')]) {
        assert.notEqual(mutate(filtered), filtered, 'The negative must alter the modeled readback.');
        const changed = host(html => mutate(boundedWordImages(html)));
        await assert.rejects(insertStandaloneSignature(changed.office, changed.item, dispatchCardFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(changed.state.signatures, 1); assert.equal(isStandaloneSignatureInsertionBlocked(changed.item), true);
    }
});

test('v15 chooses the modern profile only for the evidenced OfficeOnline OutlookWebApp pair', () => {
    for (const [platform, hostName, expected] of [
        ['OfficeOnline', 'OutlookWebApp', 'M'], ['PC', 'Outlook', 'B'], ['Mac', 'Outlook', 'B'],
        ['OfficeOnline', 'Outlook', 'B'], ['OfficeOnline', 'newOutlookWindows', 'B'], ['PC', 'OutlookWebApp', 'B'],
        ['iOS', 'OutlookIOS', 'B'], ['Android', 'OutlookAndroid', 'B'], ['', 'OutlookWebApp', 'B'],
        ['OfficeOnline', '', 'B'], ['OfficeOnline', 'OutlookWebApp ', 'B'], ['OfficeOnlineUnknown', 'OutlookWebApp', 'B'],
    ]) assert.equal(outlookSignatureDeliveryProfile({ context: { platform, mailbox: { diagnostics: { hostName } } } }), expected);
    assert.equal(outlookSignatureDeliveryProfile(undefined), 'B');
});

test('v15 prepares bounded canonical sources and enlarges only three verified image targets on the modern host', () => {
    for (const combined of [false, true]) {
        const html = hostProfileFixture(combined), source = combined ? managedTemplateContract(html) : standaloneSignatureContract(html);
        for (const [office, expected] of [[host().office, 'B'], [modernHost().office, 'M'], [undefined, 'B']]) {
            const plan = prepareNativeLayoutRestore(html, source, nonce, office);
            assert.equal(plan.profile, expected); assert.equal(plan.sourceOnly, 'fluid-v15'); assert.equal(plan.family, 15);
            assert.equal(plan.html.length - html.length, expected === 'M' ? 168 : 167);
            assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
            assert.match(plan.html, new RegExp('RT-FLUID-LAYOUT-V15' + expected + ':' + nonce + ':' + version));
            assert.match(plan.html, /src="https:\/\/mail\.example\.test\/m\/4700745390\.gif"/);
            assert.match(plan.html, new RegExp('width="' + (expected === 'M' ? 529 : 224) + '" height="' + (expected === 'M' ? 115 : 49) + '"'));
            const received = wordProfile(plan.html), proof = fluidLayoutContract(received, received, nonce, version);
            assert.equal(proof.fluidLayout.profile, expected); assert.equal(verifyFluidLayoutMaterial(received, proof).family, 15);
        }
        for (const changed of [html.replace('width:242px;', 'width:547px;'), html.replace('width="224"', 'width="529"'),
            html.replace('width:177px;', 'width:199px;'), html.replace('width:224px;', 'width:min(529px,100%);'),
            html.replace('4700745390.gif', '4700745390.png'), html.replace('4700745390.gif', 'unknown.gif')]) {
            assert.throws(() => prepareNativeLayoutRestore(changed, combined ? managedTemplateContract(changed) : standaloneSignatureContract(changed), nonce, modernHost().office));
        }
    }
});

test('v15 currentness includes the actual host profile and changing profiles replaces only the owned native signature', async () => {
    const state = host(), html = hostProfileFixture(), outside = state.state.body;
    await insertStandaloneSignature(state.office, state.item, html, () => {}, { media: [] });
    assert.equal(fluidLayoutContract(state.state.body).fluidLayout.profile, 'B');
    assert.equal(standaloneSignatureLayoutIsCurrent(state.state.body, state.state.body, version, state.office), true);
    state.office.context.platform = 'OfficeOnline'; state.office.context.mailbox.diagnostics.hostName = 'OutlookWebApp';
    assert.equal(standaloneSignatureLayoutIsCurrent(state.state.body, state.state.body, version, state.office), false);
    await insertStandaloneSignature(state.office, state.item, html, () => {}, { media: [] });
    assert.equal(state.state.signatures, 2); assert.equal(state.state.prepends, 0); assert.equal(state.state.replacements, 0);
    assert.equal(fluidLayoutContract(state.state.body).fluidLayout.profile, 'M');
    assert.equal(signatureHostProfileIsCurrent(state.state.body, state.state.body, version, state.office), true);
    assert.ok(state.state.body.endsWith(outside));
    const combined = modernHost();
    await prependTemplate(combined.office, combined.item, hostProfileFixture(true), () => {}, { media: [] });
    assert.equal(fluidLayoutContract(combined.state.body).fluidLayout.profile, 'M');
    assert.equal(signatureHostProfileIsCurrent(combined.state.body, combined.state.body, version, combined.office), true);
    await replaceManagedTemplate(combined.office, combined.item, hostProfileFixture(true, 'ff'.repeat(8)), () => {}, { media: [] });
    assert.equal(combined.state.prepends, 1); assert.equal(combined.state.replacements, 1); assert.ok(combined.state.body.endsWith(outside));
});

test('v15 profile markers, versions and material cannot be interchanged or silently downgraded after writing', async () => {
    for (const mutate of [html => html.replace('RT-FLUID-LAYOUT-V15M:', 'RT-FLUID-LAYOUT-V15B:'),
        html => html.replace('RT-FLUID-LAYOUT-V15M:', 'RT-FLUID-LAYOUT-V15X:'),
        html => html.replace('RT-FLUID-LAYOUT-V15M:', 'RT-FLUID-LAYOUT-V14:'),
        html => html.replace('width:529px;max-width:100%!important;', 'width:529px;'),
        html => html.replace('width:547px;max-width:100%!important;', 'width:547px;'),
        html => html.replace('width:199px;max-width:100%!important;', 'width:199px;')]) {
        const state = modernHost(html => wordProfile(mutate(html)));
        await assert.rejects(insertStandaloneSignature(state.office, state.item, hostProfileFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(state.state.signatures, 1); assert.equal(isStandaloneSignatureInsertionBlocked(state.item), true);
    }
    const html = hostProfileFixture(), plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce, modernHost().office);
    assert.throws(() => fluidLayoutContract(plan.html + plan.html));
    assert.throws(() => fluidLayoutContract(plan.html, plan.html, '22'.repeat(16), version));
    assert.throws(() => fluidLayoutContract(plan.html, plan.html, nonce, 'ff'.repeat(8)));
});

test('v15 bounded material tolerates only equivalent finite Word image units without certifying an unsafe modern profile', async () => {
    const wordLoss = html => wordProfile(html.replace('width:242px;max-width:100%!important;', 'width:2.5208in;')
        .replace('width:224px;max-width:100%!important;height:auto!important;', 'width:2.3333in;height:.5104in;')
        .replace('width:177px;max-width:100%!important;height:auto!important;', 'width:1.8437in;height:.3541in;'));
    const bounded = host(wordLoss);
    await insertStandaloneSignature(bounded.office, bounded.item, hostProfileFixture(), () => {}, { media: [] });
    assert.equal(bounded.state.signatures, 1); assert.equal(fluidLayoutContract(bounded.state.body).fluidLayout.profile, 'B');
    const quoted = bounded.state.body + '<blockquote>' + bounded.state.body + '</blockquote>';
    assert.equal(verifyFluidLayoutMaterial(quoted, fluidLayoutContract(quoted, quoted)).family, 15);
    const changed = host(html => wordProfile(html.replace('width:177px;max-width:100%!important;', 'width:100%;max-width:100%!important;')));
    await assert.rejects(insertStandaloneSignature(changed.office, changed.item, hostProfileFixture(), () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(changed.state.signatures, 1);
});

test('the production taskpane currentness check distinguishes V15 profiles for standalone and combined bodies', () => {
    const source = readFileSync(new URL('../../resources/js/outlook-addin/taskpane.js', import.meta.url), 'utf8');
    const declaration = /function signatureIsCurrent\([\s\S]*?\n\}/.exec(source)?.[0];
    assert.ok(declaration);
    for (const combined of [false, true]) {
        const html = hostProfileFixture(combined), incoming = combined ? managedTemplateContract(html) : standaloneSignatureContract(html);
        const payload = { signatureVersion: version, signature: { html } };
        for (const office of [host().office, modernHost().office]) {
            const context = vm.createContext({ Office: office, snapshotSignatureVersion: value => value.signatureVersion,
                hasCurrentSnapshot: () => true, currentComposeBodyHtml: value => value, isMobileComposeHost: () => false,
                requiresStandaloneSignatureLayout: () => true, standaloneSignatureLayoutIsCurrent, signatureHostProfileIsCurrent,
                templateStateFromBody: () => ({ present: combined }), signatureVersionsFromBody: () => [] });
            vm.runInContext(declaration + ';globalThis.check=signatureIsCurrent;', context);
            for (const target of [host().office, modernHost().office]) {
                const plan = prepareNativeLayoutRestore(html, incoming, nonce, target), body = wordProfile(plan.html);
                assert.equal(context.check(payload, body, 'newMail'), plan.profile === outlookSignatureDeliveryProfile(office));
                assert.equal(context.check({ ...payload, signatureVersion: 'ff'.repeat(8) }, body, 'newMail'), false);
            }
        }
    }
});
