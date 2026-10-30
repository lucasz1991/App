import assert from 'node:assert/strict';
import test from 'node:test';
import { managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { nativeLayoutReadbackContract, nativeLayoutRestoreCellsAligned, nativeLayoutRestoreCss, nativeLayoutRestoreResponsiveCss, nativeLayoutRestoreRetained, prepareNativeLayoutRestore, removeNativeLayoutRestoreCarrier } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { insertStandaloneSignature, standaloneSignatureLayoutIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
import { nativeOfficeHtml, officeCarrier } from './outlook-standalone-fixture.js';

const nonce = '71'.repeat(16), version = '0123456789abcdef';
const metadata = text => `<span hidden aria-hidden="true" style="display:none!important;font-size:0;line-height:0;">${text}</span>`;
const table = (attributes, content, cell = '') => `<table role="presentation" border="0" cellspacing="0" cellpadding="0" ${attributes}><tbody><tr><td ${cell}>${content}</td></tr></tbody></table>`;
const image = (name, width, height, attributes = '') => `<img src="https://mail.example.test/m/${name}.png" width="${width}" height="${height}" ${attributes}>`;

function fixture(combined = false) {
    const logo = table('class="rt-personal-logo-frame"', image('0000000001', '160', '27'));
    const column = (role, name, content) => table(`class="${name} rt-v10-column" width="240" style="width:240px;max-width:100%;table-layout:fixed;border-collapse:collapse;"`, content,
        `class="rt-v9-${role}-cell" align="left" dir="ltr" style="padding:0 40px;text-align:left;direction:ltr;"`);
    const person = column('person', 'rt-personal-left', logo + '<p>Synthetic person</p><p>Role</p><p>person@example.test</p>');
    const company = column('company', 'rt-personal-right', '<p>Synthetic company address</p><p>company@example.test</p>');
    const dispatch = column('dispatch', 'rt-dispatch-column', image('0000000002', '160', '30') + '<p>Dispo 24/7 erreichbar</p><a href="tel:+490123456789">0123456789</a>');
    const train = table('class="rt-df-train rt-train-enlarged-v1" width="364" style="width:364px;max-width:100%;"',
        '<div class="rt-train-window" style="overflow:hidden;">' + image('0000000003', '324', '70', 'class="rt-delivery-train rt-v10-train-image" style="width:100%;max-width:100%;height:auto;"') + '</div>',
        'class="rt-v9-train-cell" align="left" dir="ltr" style="padding:0 40px 0 0;text-align:left;direction:ltr;"');
    const outer = content => '<td class="rt-v10-grid-cell" width="240" valign="top" style="display:inline-block;width:240px;max-width:100%;padding:0;border:0;vertical-align:top;">' + content + '</td>';
    const flow = '<table class="rt-personal-columns rt-v10-columns" width="720" style="width:720px;max-width:100%;" data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v10"><tbody>'
        + '<tr class="rt-v10-grid-row" style="display:block;padding:0;font-size:0;line-height:0;">' + [person, company, dispatch].map(outer).join('') + '</tr>'
        + '<tr class="rt-v10-train-row"><td colspan="3" align="left" dir="ltr" style="display:block;width:100%;padding:0;">' + train + '</td></tr></tbody></table>';
    const legal = table('class="rt-brand-legal-table"', '<p>Synthetic legal content</p>', 'class="rt-v9-legal-cell" style="padding:0 40px;"');
    const signature = '<div class="rt-outlook-signature rts0123456789 rt-dflow-host-v4 rt-df100123456789"><table class="rt-managed-signature-v1 rts0123456789 rt-dflow-host-v4 rt-df100123456789"><tbody><tr><td class="rt-v9-top-rule" style="border-top:3px solid #e90032;">'
        + flow + '</td></tr><tr><td style="color:#718096;">' + legal + metadata('RT-SIGNATURE-MANAGED-V1') + metadata('RT-SIGNATURE-VERSION:' + version) + '</td></tr></tbody></table></div>';
    if (!combined) return signature;
    const template = metadata('RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT') + table('class="rt-compose-editable rt-compose-editable-v1"', metadata('RT-COMPOSE-EDITABLE-START-V1') + '<p>Preserved editable text</p>' + metadata('RT-COMPOSE-EDITABLE-END-V1'));
    return table('class="rt-managed-combined-v1"', table('class="rt-managed-template-v1"', table('class="rt-shell design-v27"', template)) + signature);
}

// Model the observed Web TD-ID removal and image wrapper. This is not a Word
// SDK capture; native Classic rendering/send acceptance is a separate test.
function rewritten(html) {
    return nativeOfficeHtml(html).replace(/(<(?:td|tr)\b[^>]*?) id="x_rt-native-layout-[^"]+"/g, '$1')
        .replace(/\s(?:width|height)="[^"]*"/g, '')
        .replace(/(<img\b[^>]*id="x_rt-native-layout-[^"]+-k"[^>]*>)/, '<div style="font-size:0;">$1</div>');
}

test('v10 annotates the exact three-cell Word base with one distinct row ID and 855/912 character reserves', () => {
    for (const combined of [false, true]) {
        const html = fixture(combined), parse = combined ? managedTemplateContract : standaloneSignatureContract;
        const plan = prepareNativeLayoutRestore(html, parse(html), nonce);
        assert.equal(Object.keys(plan.ids).length, combined ? 16 : 15);
        assert.equal(plan.html.length - html.length, combined ? 912 : 855);
        assert.equal(plan.ids.gridRow, `rt-native-layout-${nonce}-u`);
        assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpctsfqwkdaebgou]"/g, ''), html);
        const body = officeCarrier(plan.stylePayload) + rewritten(plan.html), contract = parse(body);
        assert.equal(nativeLayoutRestoreRetained(body, contract, plan.ids), true);
        assert.equal(removeNativeLayoutRestoreCarrier(body, contract, plan.ids), rewritten(plan.html));
        if (!combined) assert.deepEqual(contract.layoutIds, plan.ids, 'Saved V10 is inferred only from three owned direct cells.');
        const proof = nativeLayoutReadbackContract(body, contract, plan.ids);
        assert.equal(proof.nativeVirtualCellKeys.length, 7);
        assert.equal(nativeLayoutRestoreCellsAligned(proof, plan.ids), true);
    }
});

test('v10 recovery has only pure owned ID rules and never turns inner tables into Word floating objects', () => {
    const html = fixture(), plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce);
    const css = nativeLayoutRestoreCss(plan.ids), responsive = nativeLayoutRestoreResponsiveCss(plan.ids);
    for (const suffix of ['p', 'c', 'd']) assert.match(css, new RegExp('-' + suffix + '\\{[^}]*display:table!important;float:none!important;[^}]*width:100%!important;'));
    assert.match(css, /-u\{display:block!important;padding:0!important;font-size:0!important;line-height:0!important;text-align:left!important;\}/);
    assert.doesNotMatch(css + responsive, /[>+~]|float:left|word-break|background(?:-color)?|display:none/);
    for (const [, selector] of (css + responsive).replace('@media(max-width:600px){', '').matchAll(/([^{}]+)\{[^{}]*\}/g)) assert.match(selector, /^#rt-native-layout-[0-9a-f]{32}-[rpctsfqwkdaebgou]$/);
    const noCss = html.replace(/\sstyle="[^"]*"/g, '');
    assert.match(noCss, /rt-v10-columns" width="720"/);
    assert.equal((noCss.match(/class="rt-v10-grid-cell" width="240" valign="top"/g) || []).length, 3);
    assert.match(noCss, /<td colspan="3"/);
    assert.match(noCss, /width="324" height="70"/);
    assert.doesNotMatch(noCss, /<table\b[^>]*align="left"|mso-table-anchor/);
});

test('v10 rejects V9 floating rewrites, missing cells, extra wrappers and incorrect source dimensions', () => {
    for (const mutate of [
        html => html.replace('outlook-desktop-flow-v10', 'outlook-desktop-flow-v9'),
        html => html.replaceAll('rt-df100123456789', 'rt-df90123456789'),
        html => html.replace('rt-v10-grid-row', 'unknown-row'),
        html => html.replace('display:block;padding:0;font-size:0', 'display:table-row;padding:0;font-size:0'),
        html => html.replace('class="rt-v10-grid-cell" width="240"', 'class="rt-v10-grid-cell" width="241"'),
        html => html.replace('colspan="3"', 'colspan="2"'),
        html => html.replace('class="rt-personal-left rt-v10-column"', 'align="left" class="rt-personal-left rt-v10-column"'),
        html => html.replace('style="width:240px;max-width:100%;table-layout:', 'style="float:left;width:240px;max-width:100%;table-layout:'),
        html => html.replace('width="324" height="70"', 'width="200" height="43"'),
    ]) {
        const changed = mutate(fixture());
        assert.throws(() => prepareNativeLayoutRestore(changed, standaloneSignatureContract(changed), nonce));
    }
    const html = fixture(), plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce), body = rewritten(plan.html);
    for (const mutate of [
        value => value.replace('display:inline-block;width:240px', 'display:inline-block;width:241px'),
        value => value.replace('colspan="3"', 'colspan="2"'),
        value => value.replace('display:block;padding:0;font-size:0', 'display:block;padding:0;font-size:1px'),
        value => value.replace('style="border-top:', 'id="foreign" style="border-top:'),
        value => value + `<blockquote><table id="x_${plan.ids.gridRow}"><tbody><tr><td>Unknown</td></tr></tbody></table></blockquote>`,
    ]) {
        const changed = mutate(body);
        assert.throws(() => nativeLayoutRestoreCellsAligned(nativeLayoutReadbackContract(changed, standaloneSignatureContract(changed), plan.ids), plan.ids));
    }
});

test('v10 insertion confirms the carrier after removed cell/row IDs without a Classic-specific acknowledgement bypass', async () => {
    for (const platform of ['OfficeOnline', 'PC']) {
        let signature = '', carrier = '', writes = 0, prepends = 0;
        const sessions = new Map(), before = '<p>Unknown user bytes</p>', after = '<blockquote>Unknown history</blockquote>';
        const body = () => before + carrier + signature + after, ok = value => ({ status: 'succeeded', value });
        const item = { getComposeTypeAsync: cb => cb(ok({ composeType: 'newMail' })),
            sessionData: { getAsync: (key, cb) => cb(ok(sessions.get(key) || '')), setAsync: (key, value, cb) => { sessions.set(key, value); cb(ok()); } },
            body: { getTypeAsync: cb => cb(ok('html')), getAsync: (_type, cb) => cb(ok(body())),
                setSignatureAsync: (html, _options, cb) => { signature = rewritten(html); cb(ok()); },
                prependAsync: (html, _options, cb) => { carrier = officeCarrier(html) + carrier; prepends++; cb(ok()); },
                setAsync: () => { writes++; throw new Error('No full-body write'); } } };
        const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform, requirements: { isSetSupported: () => true }, mailbox: { item, diagnostics: { hostName: platform === 'PC' ? 'Outlook' : 'OutlookWebApp' } } } };
        await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
        assert.equal(standaloneSignatureLayoutIsCurrent(body(), body(), version), true);
        await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
        assert.equal(prepends, 1);
        assert.equal(writes, 0);
        assert.ok(body().startsWith(before));
        assert.ok(body().endsWith(after));
    }
});
