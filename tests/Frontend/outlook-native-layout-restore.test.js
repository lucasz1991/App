import assert from 'node:assert/strict';
import test from 'node:test';
import { createNativeLayoutNonce, nativeLayoutRestoreCarrierPresent, nativeLayoutRestoreCss, nativeLayoutRestoreRetained, nativeLayoutRestoreStylePayload, prepareNativeLayoutRestore, removeNativeLayoutRestoreCarrier } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';

const nonce = '1234567890abcdef1234567890abcdef';
const element = (tag, attrs = {}, children = []) => ({ tag, attrs, children });
const table = (className, children = [], attrs = {}) => element('table', { class: className, ...attrs }, [element('tbody', {}, [element('tr', {}, [element('td', {}, children)])])]);

// This source builder records offsets as it emits bytes; no DOM serialization is used.
function fixture({ header = true, legacy = false, quotedCopy = false, legal = false, legalColor = '#718096', wire = false, standalone = false } = {}) {
    const metadata = value => element('span', { hidden: '', style: 'font-size:0;line-height:0;' }, [value]);
    const person = table('rt-personal-left', [element('p', {}, ['Synthetic personal text'])]);
    const company = table('rt-personal-right', [element('img', { src: 'cid:logo.png', width: '180', height: '31' })]);
    const train = table('rt-df-train', [element('div', { class: 'rt-train-window' }, [element('img', {
        class: 'rt-delivery-train', src: 'cid:train.gif', width: '300', height: '38',
    })]), '<!--[if mso]><img class="rt-delivery-train-mso" src="cid:still.png" width="220" height="28"><![endif]-->']);
    const flow = table('rt-personal-columns', [person, company, train], {
        'data-rt-personal-flow': 'v1', 'data-rt-personal-columns': 'v1', 'data-rt-desktop-flow': 'outlook-desktop-flow-v4',
    });
    const banner = element('table', { class: 'banner' }, [element('tbody', {}, [
        element('tr', {}, [element('td', { class: 'design-hotline-row' }, [element('strong', {}, ['24/7']), ' reachable'])]),
        element('tr', {}, [element('td', { style: 'color:#718096' }, ['Footer must keep its color'])]),
    ])]);
    const signature = table(standalone ? 'rt-managed-signature-v1 rts1234567890' : 'rt-managed-signature-v1 rt-combined-signature-frame', [
        ...(header ? [banner] : []), ...(legacy ? [element('p', {}, ['Legacy source'])] : [flow]),
    ]);
    if (legal) signature.children[0].children.push(element('tr', {}, [element('td', { style: `color:${legalColor};font-size:9px;line-height:14px;` }, [
        element('font', { color: '#000000' }, ['Synthetic legal information']),
        element('span', { hidden: '', style: 'font-size:0;line-height:0;' }, ['RT-SIGNATURE-MANAGED-V1']),
        element('span', { hidden: '', style: 'font-size:0;line-height:0;' }, ['RT-SIGNATURE-VERSION:1234567890abcdef']),
    ])]));
    const template = wire ? [metadata('RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT'),
        table('rt-compose-editable rt-compose-editable-v1', [metadata('RT-COMPOSE-EDITABLE-START-V1'), element('p', {}, ['Editable content']), metadata('RT-COMPOSE-EDITABLE-END-V1')])]
        : [element('p', { class: 'design-spacer' }, ['Editable content'])];
    const tree = element('div', {}, [
        '<!-- prefix with unicode 🚆 and arbitrary bytes -->',
        standalone ? signature : table('rt-managed-combined-v1', [table('rt-managed-template-v1', template), signature]),
        ...(quotedCopy ? [element('blockquote', {}, [flow])] : []),
        '<!-- arbitrary suffix -->',
    ]);
    let html = '';
    const elements = [];
    function emit(spec, parent) {
        if (typeof spec === 'string') { html += spec; return; }
        const index = elements.length;
        const node = { tag: spec.tag, attrs: { ...spec.attrs }, parent, start: html.length };
        elements.push(node);
        html += '<' + spec.tag + Object.entries(spec.attrs).map(([key, value]) => ` ${key}="${value}"`).join('') + '>';
        node.openEnd = html.length;
        for (const child of spec.children) emit(child, index);
        node.close = html.length;
        if (spec.tag !== 'img') html += `</${spec.tag}>`;
        node.end = html.length;
    }
    emit(tree);
    const byClass = name => elements.findIndex(node => (node.attrs.class || '').split(' ').includes(name));
    const signatureIndex = byClass('rt-managed-signature-v1');
    return { html, elements, byClass, contract: { elements, signature: { index: signatureIndex, node: elements[signatureIndex] } } };
}

test('cryptographic nonce generation requests exactly128bits and never falls back to weak randomness', () => {
    let length = 0;
    assert.equal(createNativeLayoutNonce({ getRandomValues(bytes) { length = bytes.length; bytes.fill(171); return bytes; } }), 'ab'.repeat(16));
    assert.equal(length, 16);
    assert.throws(() => createNativeLayoutNonce(null), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
});

test('only five trusted TABLE openings change; user/quote/footer/MSO/image bytes remain exact', () => {
    const source = fixture({ quotedCopy: true });
    const plan = prepareNativeLayoutRestore(source.html, source.contract, nonce);
    assert.equal(Object.isFrozen(plan), true);
    assert.equal(Object.isFrozen(plan.ids), true);
    assert.equal(Object.keys(plan.ids).length, 5);
    assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpcth]"/g, ''), source.html);
    for (const id of Object.values(plan.ids)) assert.equal(plan.html.split(`id="${id}"`).length - 1, 1);
    assert.equal(plan.stylePayload, nativeLayoutRestoreStylePayload(plan.ids));
    assert.match(plan.html, /Footer must keep its color/);
    assert.equal((plan.html.match(/<img\b[^>]*>/g) || []).join(''), (source.html.match(/<img\b[^>]*>/g) || []).join(''));
});

test('restore CSS is static, operation scoped and only targets the moving IMG inside its window', () => {
    const source = fixture();
    const plan = prepareNativeLayoutRestore(source.html, source.contract, nonce);
    assert.match(plan.stylePayload, /#rt-native-layout-[0-9a-f]{32}-t>tbody>tr>td>div>img\{width:520px!important;max-width:none!important;height:auto!important;\}/);
    assert.match(plan.stylePayload, /position:sticky!important;top:100%!important/);
    assert.match(plan.stylePayload, /-h>tbody>tr:first-child td/);
    assert.doesNotMatch(plan.stylePayload, /cid:|url\(|title=|rt-delivery-train-mso|:last-child|calc\(/);
    assert.equal((plan.stylePayload.match(/-h>tbody>tr:first-child /g) || []).length, 6);
    assert.doesNotMatch(plan.stylePayload, /\[id/);
});

test('without a known hotline table the helper emits four IDs and no color override', () => {
    const source = fixture({ header: false });
    const plan = prepareNativeLayoutRestore(source.html, source.contract, nonce);
    assert.equal(Object.keys(plan.ids).length, 4);
    assert.doesNotMatch(plan.stylePayload, /color:|text-fill/);
});

test('legacy signatures receive no special native restoration', () => {
    const source = fixture({ legacy: true });
    assert.equal(prepareNativeLayoutRestore(source.html, source.contract), null);
});

test('malformed native structure, wrong flow version and reused target IDs fail before any write', () => {
    for (const corrupt of [
        source => { source.elements[source.byClass('rt-personal-columns')].attrs['data-rt-personal-flow'] = 'v2'; },
        source => { source.elements[source.byClass('rt-personal-right')].parent = source.byClass('rt-managed-signature-v1'); },
        source => { source.elements[source.byClass('rt-df-train')].attrs.id = 'existing'; },
        source => { source.elements[source.byClass('rt-train-window')].tag = 'span'; },
    ]) {
        const source = fixture(); corrupt(source);
        assert.throws(() => prepareNativeLayoutRestore(source.html, source.contract, nonce), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    }
});

test('stylesheet factory accepts only one known hexadecimal scope and its exact four/five suffixes', () => {
    const source = fixture();
    const plan = prepareNativeLayoutRestore(source.html, source.contract, nonce);
    for (const bad of [
        { ...plan.ids, train: 'foreign' }, { ...plan.ids, extra: 'anything' },
        { ...plan.ids, company: plan.ids.company.replace(nonce, 'f'.repeat(32)) },
    ]) assert.throws(() => nativeLayoutRestoreStylePayload(bad), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    for (const bad of ['', 'x'.repeat(32), nonce + '"><style>bad</style>']) {
        assert.throws(() => prepareNativeLayoutRestore(source.html, source.contract, bad), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    }
});

test('known two-row legal footer adds only the sixth TABLE ID and last-row gray leaf rules', () => {
    const source = fixture({ legal: true });
    const plan = prepareNativeLayoutRestore(source.html, source.contract, nonce);
    assert.equal(Object.keys(plan.ids).length, 6);
    assert.equal(plan.ids.signature, `rt-native-layout-${nonce}-s`);
    assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpcths]"/g, ''), source.html);
    assert.match(plan.stylePayload, /-s>tbody>tr:last-child font/);
    assert.match(plan.stylePayload, /color:#718096!important;/);
    assert.doesNotMatch(plan.stylePayload, /-webkit-text-fill-color/);
    assert.equal(plan.stylePayload, nativeLayoutRestoreStylePayload(plan.ids));
    assert.doesNotMatch(plan.stylePayload, /-s>tbody>tr:first-child/);
});

test('unknown footer color or missing legal metadata never receives a gray override', () => {
    const unknown = fixture({ legal: true, legalColor: '#123456' });
    const unknownPlan = prepareNativeLayoutRestore(unknown.html, unknown.contract, nonce);
    assert.equal(unknownPlan.ids.signature, undefined);
    const missing = fixture({ legal: true });
    missing.html = missing.html.replace('RT-SIGNATURE-MANAGED-V1', 'NO-SIGNATURE-METADATA-V1');
    const missingPlan = prepareNativeLayoutRestore(missing.html, missing.contract, nonce);
    assert.equal(missingPlan.ids.signature, undefined);
});

test('a retained empty carrier is positively verified and cleanup removes only its complete owned range', () => {
    const source = fixture({ wire: true, legal: true });
    const contract = managedTemplateContract(source.html);
    const plan = prepareNativeLayoutRestore(source.html, contract, nonce);
    const written = plan.stylePayload + plan.html;
    const parsed = managedTemplateContract(written);
    assert.equal(nativeLayoutRestoreRetained(written, parsed, plan.ids), true);
    assert.equal(removeNativeLayoutRestoreCarrier(written, parsed, plan.ids), plan.html);
    assert.ok(plan.stylePayload.includes(nativeLayoutRestoreCss(plan.ids)));
    assert.equal(plan.stylePayload.replace(/<style\b[^>]*>[\s\S]*?<\/style>/, '').replace(/<[^>]*>/g, ''), `RT-NATIVE-LAYOUT-RESTORE-V1:${nonce}`);
});

test('successful API without retained exact CSS, unknown sheets and quoted carriers never count as restored', () => {
    const source = fixture({ wire: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, managedTemplateContract(source.html), nonce);
    for (const body of [
        plan.html,
        plan.stylePayload.replace(/<style\b[^>]*>[\s\S]*?<\/style>/, '') + plan.html,
        plan.stylePayload.replace('float:left!important;', 'float:none!important;') + plan.html,
        '<blockquote>' + plan.stylePayload + '</blockquote>' + plan.html,
    ]) {
        const contract = managedTemplateContract(body);
        assert.throws(() => nativeLayoutRestoreRetained(body, contract, plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
        assert.equal(removeNativeLayoutRestoreCarrier(body, contract, plan.ids), body);
    }
});

test('retention accepts only the exact Office x_ selector prefix rewrite', () => {
    const source = fixture({ wire: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, managedTemplateContract(source.html), nonce);
    const body = (plan.stylePayload + plan.html).replace(/id="rt-native-layout-/g, 'id="x_rt-native-layout-')
        .replace(/#rt-native-layout-/g, '#x_rt-native-layout-');
    assert.equal(nativeLayoutRestoreRetained(body, managedTemplateContract(body), plan.ids), true);
    const noTrailingSemicolon = body.replace(/;}/g, '}');
    assert.equal(nativeLayoutRestoreRetained(noTrailingSemicolon, managedTemplateContract(noTrailingSemicolon), plan.ids), true);
    for (const bad of [body.replace(/#x_rt-native-layout-/g, '#x_x_rt-native-layout-'), body.replace(/#x_rt-native-layout-/g, '#foreign_rt-native-layout-'), body.replace(/#x_rt-native-layout-/g, '#rt-native-layout-')]) {
        assert.throws(() => nativeLayoutRestoreRetained(bad, managedTemplateContract(bad), plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    }
});

test('observed inner carrier before template permits at most two DIV ancestors and preserves preheader bytes', () => {
    const source = fixture({ wire: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, managedTemplateContract(source.html), nonce);
    const prepared = managedTemplateContract(plan.html);
    const template = prepared.template.node;
    const inner = plan.html.slice(0, template.start) + '<div><div>' + plan.stylePayload + 'Preserved preheader text</div>'
        + plan.html.slice(template.start, template.end) + '</div>' + plan.html.slice(template.end);
    assert.equal(nativeLayoutRestoreRetained(inner, managedTemplateContract(inner), plan.ids), true);
    assert.equal(removeNativeLayoutRestoreCarrier(inner, managedTemplateContract(inner), plan.ids), inner.replace(plan.stylePayload, ''));
    const tooDeep = inner.replace(plan.stylePayload, '<div>' + plan.stylePayload + '</div>');
    assert.throws(() => nativeLayoutRestoreRetained(tooDeep, managedTemplateContract(tooDeep), plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    const editable = prepared.region;
    const insideUserRegion = plan.html.slice(0, editable.start) + plan.stylePayload + plan.html.slice(editable.start);
    assert.throws(() => nativeLayoutRestoreRetained(insideUserRegion, managedTemplateContract(insideUserRegion), plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
});

test('standalone annotates only six TABLE openings and retains the same exact outside carrier', () => {
    const source = fixture({ standalone: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    assert.equal(Object.keys(plan.ids).length, 6);
    assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpcths]"/g, ''), source.html);
    const body = plan.stylePayload + plan.html;
    const contract = standaloneSignatureContract(body, body, plan.ids, '1234567890abcdef');
    assert.equal(nativeLayoutRestoreCarrierPresent(body, contract, plan.ids), true);
    assert.equal(nativeLayoutRestoreRetained(body, contract, plan.ids), true);
    assert.equal(removeNativeLayoutRestoreCarrier(body, contract, plan.ids), plan.html);
    assert.equal((body.match(/<img\b[^>]*>/g) || []).join(''), (source.html.match(/<img\b[^>]*>/g) || []).join(''));
    const office = body.replace(/id="rt-native-layout-/g, 'id="x_rt-native-layout-').replace(/#rt-native-layout-/g, '#x_rt-native-layout-').replace(/;}/g, '}');
    assert.equal(nativeLayoutRestoreRetained(office, standaloneSignatureContract(office, office, plan.ids), plan.ids), true);
});

test('standalone first-row carrier permits only zero to two DIV ancestors before header and native root', () => {
    const source = fixture({ standalone: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    const parsed = standaloneSignatureContract(plan.html, plan.html, plan.ids);
    const header = parsed.elements.find(node => (node.attrs.class || '').split(/\s+/).includes('banner'));
    for (const depth of [0, 1, 2]) {
        const carrier = '<div>'.repeat(depth) + plan.stylePayload + 'Preserved preheader bytes' + '</div>'.repeat(depth);
        const body = plan.html.slice(0, header.start) + carrier + plan.html.slice(header.start);
        const contract = standaloneSignatureContract(body, body, plan.ids);
        assert.equal(nativeLayoutRestoreRetained(body, contract, plan.ids), true);
        assert.equal(removeNativeLayoutRestoreCarrier(body, contract, plan.ids), body.replace(plan.stylePayload, ''));
    }
    const tooDeep = plan.html.slice(0, header.start) + '<div><div><div>' + plan.stylePayload + '</div></div></div>' + plan.html.slice(header.start);
    assert.throws(() => nativeLayoutRestoreRetained(tooDeep, standaloneSignatureContract(tooDeep, tooDeep, plan.ids), plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
});

test('standalone rejects carriers in personal/company/train/header/footer/quote or after the header', () => {
    const source = fixture({ standalone: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    const parsed = standaloneSignatureContract(plan.html, plan.html, plan.ids);
    const targets = ['rt-personal-left', 'rt-personal-right', 'rt-df-train', 'design-hotline-row'];
    const positions = targets.map(name => {
        const index = parsed.elements.findIndex(node => (node.attrs.class || '').split(/\s+/).includes(name));
        if (name === 'design-hotline-row') return parsed.elements[index].openEnd;
        return parsed.elements.find(node => node.tag === 'td' && node.start > parsed.elements[index].start && node.end < parsed.elements[index].end).openEnd;
    });
    const footer = parsed.elements[parsed.signatureMetadata.managed].parent;
    positions.push(parsed.elements[footer].openEnd);
    const header = parsed.elements.find(node => (node.attrs.class || '').split(/\s+/).includes('banner'));
    positions.push(header.end);
    const bodies = positions.map(position => plan.html.slice(0, position) + plan.stylePayload + plan.html.slice(position));
    bodies.push('<blockquote>' + plan.stylePayload + '</blockquote>' + plan.html);
    for (const body of bodies) {
        const contract = standaloneSignatureContract(body, body, plan.ids);
        assert.equal(nativeLayoutRestoreCarrierPresent(body, contract, plan.ids), true);
        assert.throws(() => nativeLayoutRestoreRetained(body, contract, plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
        assert.equal(removeNativeLayoutRestoreCarrier(body, contract, plan.ids), body);
    }
});

test('carrier presence is conservative for malformed/quoted/stripped-ID copies and does not count target IDs alone', () => {
    const source = fixture({ standalone: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    const contract = standaloneSignatureContract(plan.html, plan.html, plan.ids);
    assert.equal(nativeLayoutRestoreCarrierPresent(plan.html, contract, plan.ids), false);
    for (const payload of [plan.stylePayload, '<blockquote>' + plan.stylePayload + '</blockquote>',
        plan.stylePayload.replace(`id="rt-native-layout-${nonce}-carrier"`, ''),
        plan.stylePayload.replace(`id="rt-native-layout-${nonce}-carrier"`, '').replace(`RT-NATIVE-LAYOUT-RESTORE-V1:${nonce}`, ''),
        plan.stylePayload.replace(`id="rt-native-layout-${nonce}-carrier"`, `id="foreign_rt-native-layout-${nonce}-carrier"`)]) {
        const body = payload + plan.html;
        assert.equal(nativeLayoutRestoreCarrierPresent(body, standaloneSignatureContract(body, body, plan.ids), plan.ids), true);
    }
    const invalidSheet = plan.stylePayload.replace('float:left!important;', 'float:none!important;') + plan.html;
    const invalidContract = standaloneSignatureContract(invalidSheet, invalidSheet, plan.ids);
    assert.equal(nativeLayoutRestoreCarrierPresent(invalidSheet, invalidContract, plan.ids), true);
    assert.throws(() => nativeLayoutRestoreRetained(invalidSheet, invalidContract, plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
});
