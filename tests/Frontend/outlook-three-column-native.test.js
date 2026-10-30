import assert from 'node:assert/strict';
import test from 'node:test';
import { managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { nativeLayoutReadbackContract, nativeLayoutRestoreCellsAligned, nativeLayoutRestoreCss, nativeLayoutRestoreResponsiveCss, nativeLayoutRestoreRetained, prepareNativeLayoutRestore, removeNativeLayoutRestoreCarrier } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { insertStandaloneSignature, standaloneSignatureLayoutIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
import { nativeOfficeHtml, officeCarrier, standaloneHtml } from './outlook-standalone-fixture.js';

const nonce = '70'.repeat(16), version = '0123456789abcdef';
const metadata = text => `<span hidden aria-hidden="true" style="display:none!important;font-size:0;line-height:0;">${text}</span>`;
const table = (attributes, content, cell = '') => `<table role="presentation" border="0" cellspacing="0" cellpadding="0" ${attributes}><tbody><tr><td ${cell}>${content}</td></tr></tbody></table>`;
const image = (name, width, height, attributes = '') => `<img src="https://mail.example.test/m/${name}.png" width="${width}" height="${height}" ${attributes}>`;

function fixture(combined = false) {
    const logo = table('class="rt-personal-logo-frame"', image('0000000001', '160', '27'));
    const column = (role, name, content) => table(`class="${name} rt-v9-column" width="240" align="left" style="display:inline-table;float:left;width:240px;max-width:100%;"`, content,
        `class="rt-v9-${role}-cell" align="left" dir="ltr" style="padding:0 40px;text-align:left;direction:ltr;"`);
    const person = column('person', 'rt-personal-left', logo + '<p>Synthetic person</p><p>Role</p><p>person@example.test</p>');
    const company = column('company', 'rt-personal-right', '<p>Company address</p><p>0123456789</p><p>company@example.test</p>');
    const dispatch = column('dispatch', 'rt-dispatch-column', image('0000000002', '160', '30') + '<p>Dispo 24/7 erreichbar</p><a href="tel:+494171546803">04171546803</a>');
    const train = table('class="rt-df-train rt-train-enlarged-v1" width="240" align="left" style="width:240px;max-width:100%;"',
        '<div class="rt-train-window" style="overflow:hidden;">' + image('0000000003', '200', '43', 'class="rt-delivery-train" style="width:200px;max-width:100%;height:43px;"') + '</div>',
        'class="rt-v9-train-cell" align="left" dir="ltr" style="padding:0 40px 0 0;text-align:left;direction:ltr;"');
    const flow = '<table class="rt-personal-columns rt-v9-columns" width="100%" style="width:100%;max-width:720px;" data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v9"><tbody><tr><td align="left" dir="ltr" style="font-size:0;line-height:0;text-align:left;direction:ltr;">'
        + person + company + dispatch + '<br clear="all" style="font-size:0;line-height:0;height:0;"></td></tr><tr><td align="left" dir="ltr" style="text-align:left;direction:ltr;">' + train + '</td></tr></tbody></table>';
    const legal = table('class="rt-brand-legal-table"', '<p>Synthetic legal content</p>', 'class="rt-v9-legal-cell" style="padding:0 40px;"');
    const signature = '<div class="rt-outlook-signature rts0123456789 rt-dflow-host-v4 rt-df90123456789"><table class="rt-managed-signature-v1 rts0123456789 rt-dflow-host-v4 rt-df90123456789"><tbody><tr><td class="rt-v9-top-rule" style="border-top:3px solid #e90032;">'
        + flow + '</td></tr><tr><td style="color:#718096;">' + legal + metadata('RT-SIGNATURE-MANAGED-V1') + metadata('RT-SIGNATURE-VERSION:' + version) + '</td></tr></tbody></table></div>';
    if (!combined) return signature;
    const template = metadata('RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT') + table('class="rt-compose-editable rt-compose-editable-v1"', metadata('RT-COMPOSE-EDITABLE-START-V1') + '<p>Preserved editable text</p>' + metadata('RT-COMPOSE-EDITABLE-END-V1'));
    return table('class="rt-managed-combined-v1"', table('class="rt-managed-template-v1"', table('class="rt-shell design-v27"', template)) + signature);
}

// Synthetic reproduction of the Cua DOM observed in Outlook Web, not an SDK
// capture or proof of the received MIME. No actual names or addresses are used.
function observedOutlookReadback(html) {
    return nativeOfficeHtml(html)
        .replace(/(<td\b[^>]*?) id="x_rt-native-layout-[^"]+"/g, '$1')
        .replace(/\s(?:width|height)="[^"]*"/g, '')
        .replace('<br clear="all" style="font-size:0;line-height:0;height:0;">', '<div style="direction:ltr;line-height:0;font-family:Aptos,Calibri,sans-serif;font-size:0px;color:rgb(0,0,0);"><br></div>')
        .replace(/(<img\b[^>]*id="x_rt-native-layout-[^"]+-k"[^>]*>)/, '<div style="font-size:0;">$1</div>');
}

test('v9 binds three columns and their four padding cells without recycling old logo/header IDs', () => {
    for (const combined of [false, true]) {
        const html = fixture(combined), parse = combined ? managedTemplateContract : standaloneSignatureContract;
        const plan = prepareNativeLayoutRestore(html, parse(html), nonce);
        assert.equal(Object.keys(plan.ids).length, combined ? 15 : 14);
        assert.equal(plan.html.length - html.length, combined ? 855 : 798);
        assert.equal(plan.ids.dispatch, `rt-native-layout-${nonce}-d`);
        assert.equal(plan.ids.trainImage, `rt-native-layout-${nonce}-k`);
        for (const key of ['logo', 'header', 'inset']) assert.equal(plan.ids[key], undefined);
        assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpctsfqwkdaebgo]"/g, ''), html);
        const body = officeCarrier(plan.stylePayload) + nativeOfficeHtml(plan.html);
        const parsed = parse(body);
        assert.equal(nativeLayoutRestoreRetained(body, parsed, plan.ids), true);
        if (!combined) assert.deepEqual(parsed.layoutIds, plan.ids);
        assert.equal(removeNativeLayoutRestoreCarrier(body, parsed, plan.ids), nativeOfficeHtml(plan.html));
    }
});

test('v9 preserves numeric Word fallback and emits only pure owned IDs for mobile padding', () => {
    const html = fixture(), plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce);
    const css = nativeLayoutRestoreCss(plan.ids), optional = nativeLayoutRestoreResponsiveCss(plan.ids);
    assert.match(css, /-r\{[^}]*max-width:720px!important;[^}]*border-top:0!important;/);
    assert.match(css, /-o\{border-top:3px solid #e90032!important;\}/);
    for (const suffix of ['p', 'c', 'd']) assert.match(css, new RegExp('-' + suffix + '\\{[^}]*width:240px!important;[^}]*max-width:100%!important;'));
    for (const suffix of ['a', 'b', 'e']) assert.match(css, new RegExp('-' + suffix + '\\{padding-left:40px!important;padding-right:40px!important;'));
    assert.match(optional, /^@media\(max-width:600px\)\{/);
    assert.match(optional, /-g\{padding-right:40px!important;\}/);
    assert.match(css, /-t\{[^}]*width:100%!important;[^}]*max-width:364px!important;/);
    assert.match(optional, /-t\{[^}]*max-width:364px!important;/);
    assert.match(optional, /-f\{padding-left:40px!important;padding-right:40px!important;\}/);
    assert.doesNotMatch(optional, /18px/, 'V9 mobile rules must match surviving inline gutters after Outlook removes TD IDs.');
    const combined = fixture(true), combinedPlan = prepareNativeLayoutRestore(combined, managedTemplateContract(combined), nonce);
    assert.match(nativeLayoutRestoreResponsiveCss(combinedPlan.ids), /-q\{border-left-width:40px!important;border-right-width:40px!important;\}/);
    assert.doesNotMatch(css + optional, /(?:calc|min|max|clamp)\(|[>+~]/);
    assert.doesNotMatch(css + optional, /word-break|overflow-wrap|background(?:-color)?|display:none/, 'Native recovery must not repaint text backgrounds, split addresses or hide their line breaks.');
    for (const [, selector] of (css + optional).replace('@media(max-width:600px){', '').matchAll(/([^{}]+)\{[^{}]*\}/g)) assert.match(selector, /^#rt-native-layout-[0-9a-f]{32}-[rpctsfqwkdaebgo]$/);
    const noCss = html.replace(/\sstyle="[^"]*"/g, '');
    assert.equal((noCss.match(/width="240" align="left"/g) || []).length, 4);
    assert.match(noCss, /<br clear="all">/);
    assert.match(noCss, /width="160" height="27"/);
    assert.match(noCss, /width="160" height="30"/);
    assert.match(noCss, /class="rt-df-train rt-train-enlarged-v1" width="240" align="left"/);
    assert.match(noCss, /width="200" height="43" class="rt-delivery-train"/);
    assert.doesNotMatch(noCss, /<table\b[^>]*width="(?:364|720)"/);
});

test('v9 rejects mixed historical roots, changed columns, missing Word clear barrier and ambiguous padding cells', () => {
    for (const mutate of [
        html => html.replace('outlook-desktop-flow-v9', 'outlook-desktop-flow-v8'),
        html => html.replaceAll('rt-df90123456789', 'rt-df80123456789'),
        html => html.replace('width="240"', 'width="241"'),
        html => html.replace('rt-dispatch-column', 'unknown-column'),
        html => html.replace('<br clear="all" style="font-size:0;line-height:0;height:0;">', ''),
        html => html.replace('clear="all"', 'clear="left"'),
        html => html.replace('rt-v9-dispatch-cell', 'unknown-cell'),
        html => html.replace('class="rt-v9-top-rule"', 'class="unknown-rule"'),
        html => html.replace('width="200" height="43"', 'width="324" height="70"'),
    ]) {
        const html = mutate(fixture());
        assert.throws(() => prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce));
    }
});

test('v9 native insertion retains current acknowledgement across the observed remote image wrapper', async () => {
    let signature = '', carrier = '', fullWrites = 0;
    const sessions = new Map(), before = '<p>Unknown user bytes</p>', after = '<blockquote>Unknown quoted bytes</blockquote>';
    const body = () => before + carrier + signature + after, ok = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: cb => cb(ok({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, cb) => cb(ok(sessions.get(key) || '')), setAsync: (key, value, cb) => { sessions.set(key, value); cb(ok()); } },
        body: { getTypeAsync: cb => cb(ok('html')), getAsync: (_type, cb) => cb(ok(body())),
            setSignatureAsync: (html, _options, cb) => { signature = nativeOfficeHtml(html).replace(/(<img\b[^>]*id="x_rt-native-layout-[^"]+-k"[^>]*>)/, '<div style="font-size:0;">$1</div>'); cb(ok()); },
            prependAsync: (html, _options, cb) => { carrier = officeCarrier(html) + carrier; cb(ok()); }, setAsync: () => { fullWrites++; throw new Error('No full-body write'); } } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform: 'OfficeOnline', requirements: { isSetSupported: () => true }, mailbox: { item, diagnostics: { hostName: 'OutlookWebApp' } } } };
    await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
    assert.equal(standaloneSignatureLayoutIsCurrent(body(), body(), version), true);
    assert.equal(fullWrites, 0);
    assert.ok(body().startsWith(before));
    assert.ok(body().endsWith(after));
});

test('v9 accepts only the observed missing-TD-ID structure without changing HTML or source annotation budgets', () => {
    for (const combined of [false, true]) {
        const html = fixture(combined), parse = combined ? managedTemplateContract : standaloneSignatureContract;
        const plan = prepareNativeLayoutRestore(html, parse(html), nonce), readback = observedOutlookReadback(plan.html);
        const original = parse(readback), normalized = nativeLayoutReadbackContract(readback, original, plan.ids);
        assert.equal(normalized.nativeVirtualCellKeys.length, 6);
        assert.equal(nativeLayoutRestoreCellsAligned(normalized, plan.ids), true);
        assert.equal(original.elements.some(node => node.tag === 'td' && node.attrs.id), false, 'Normalization must not mutate the parser or write synthetic IDs into HTML.');
        assert.equal(plan.html.length - html.length, combined ? 855 : 798);
        const body = officeCarrier(plan.stylePayload) + readback;
        assert.equal(nativeLayoutRestoreRetained(body, parse(body), plan.ids), true);
        assert.equal(removeNativeLayoutRestoreCarrier(body, parse(body), plan.ids), readback);
        if (!combined) assert.deepEqual(parse(readback).layoutIds, plan.ids);
        for (const [mutation, mutate] of [
            value => value.replace('padding:0 40px;text-align:left', 'padding:0 41px;text-align:left'),
            value => value.replace('border-top:3px solid #e90032', 'border-top:4px solid #e90032'),
            value => value.replace('font-size:0px;color:', 'font-size:1px;color:'),
            value => value.replace('<br></div>', '<br>Unknown text</div>'),
            value => value.replace('<br></div>', '<br><br></div>'),
            value => value.replace('<br></div>', '<br id="foreign"></div>'),
            value => value.replace('style="border-top:', 'id="foreign" style="border-top:'),
            value => value + `<blockquote><table id="x_${plan.ids.personCell}"><tbody><tr><td>Unknown</td></tr></tbody></table></blockquote>`,
        ].entries()) {
            const changed = mutate(readback);
            assert.throws(() => {
                const proof = nativeLayoutReadbackContract(changed, parse(changed), plan.ids);
                nativeLayoutRestoreCellsAligned(proof, plan.ids);
            }, `Mutation ${mutation}: a conflicting ID, padding, line or nonempty clear wrapper cannot be normalized.`);
        }
        assert.throws(() => prepareNativeLayoutRestore(fixture(combined).replace('<br clear="all" style="font-size:0;line-height:0;height:0;">', '<div style="font-size:0;line-height:0;"><br></div>'), parse(fixture(combined).replace('<br clear="all" style="font-size:0;line-height:0;height:0;">', '<div style="font-size:0;line-height:0;"><br></div>')), nonce), 'Incoming source preflight must continue requiring the canonical clear BR.');
    }
});

test('v9 standalone insertion restores its pure-ID carrier after Outlook removes every TD ID', async () => {
    let signature = '', carrier = '', prepends = 0, writes = 0;
    const before = '<p>Opaque user text</p>', after = '<blockquote>Opaque history</blockquote>', sessions = new Map();
    const body = () => before + carrier + signature + after, ok = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: cb => cb(ok({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, cb) => cb(ok(sessions.get(key) || '')), setAsync: (key, value, cb) => { sessions.set(key, value); cb(ok()); } },
        body: { getTypeAsync: cb => cb(ok('html')), getAsync: (_type, cb) => cb(ok(body())),
            setSignatureAsync: (html, _options, cb) => { signature = observedOutlookReadback(html); cb(ok()); },
            prependAsync: (html, _options, cb) => { carrier = officeCarrier(html) + carrier; prepends++; cb(ok()); },
            setAsync: () => { writes++; throw new Error('No full-body write'); } } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform: 'OfficeOnline', requirements: { isSetSupported: () => true }, mailbox: { item, diagnostics: { hostName: 'OutlookWebApp' } } } };
    await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
    assert.equal(standaloneSignatureLayoutIsCurrent(body(), body(), version), true);
    assert.equal(prepends, 1, 'Readback must reach the pure-ID responsive carrier write.');
    await insertStandaloneSignature(office, item, fixture(), () => {}, { media: [] });
    assert.equal(standaloneSignatureLayoutIsCurrent(body(), body(), version), true);
    assert.equal(prepends, 1, 'An already current owned signature must not prepend duplicate carriers.');
    assert.equal(writes, 0);
    assert.ok(body().startsWith(before));
    assert.ok(body().endsWith(after));
    assert.doesNotMatch(signature, /<td\b[^>]*\bid=/);
    assert.doesNotMatch(carrier, /#[^{}]+[>+~][^{}]*\{/);
});

test('a saved v8 standalone signature migrates to distinct v9 IDs without altering the inert old carrier or other body bytes', async () => {
    const old = standaloneHtml(version, true, true);
    const plan = prepareNativeLayoutRestore(old, standaloneSignatureContract(old), nonce);
    const before = '<p>Saved user text</p>', after = '<blockquote>Preserved history</blockquote>';
    const oldCarrier = officeCarrier(plan.stylePayload);
    let signature = nativeOfficeHtml(plan.html), carrier = oldCarrier, fullWrites = 0;
    const html = () => before + carrier + signature + after;
    const sessions = new Map(), ok = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: cb => cb(ok({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, cb) => cb(ok(sessions.get(key) || '')), setAsync: (key, value, cb) => { sessions.set(key, value); cb(ok()); } },
        body: { getTypeAsync: cb => cb(ok('html')), getAsync: (_type, cb) => cb(ok(html())),
            setSignatureAsync: (value, _options, cb) => { signature = nativeOfficeHtml(value); cb(ok()); },
            prependAsync: (value, _options, cb) => { carrier = officeCarrier(value) + carrier; cb(ok()); },
            setAsync: () => { fullWrites++; throw new Error('No full-body write'); } } };
    const office = { AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' }, context: { platform: 'OfficeOnline', requirements: { isSetSupported: () => true }, mailbox: { item, diagnostics: { hostName: 'OutlookWebApp' } } } };
    const incoming = fixture().replaceAll('RT-SIGNATURE-VERSION:' + version, 'RT-SIGNATURE-VERSION:' + '70'.repeat(8));
    await insertStandaloneSignature(office, item, incoming, () => {}, { media: [] });
    assert.equal(standaloneSignatureLayoutIsCurrent(html(), html(), '70'.repeat(8)), true);
    assert.equal(fullWrites, 0);
    assert.ok(carrier.endsWith(oldCarrier));
    assert.ok(html().startsWith(before));
    assert.ok(html().endsWith(after));
    assert.doesNotMatch(signature, new RegExp(nonce));
});
