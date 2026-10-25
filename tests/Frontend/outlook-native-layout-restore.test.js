import assert from 'node:assert/strict';
import test from 'node:test';
import { createNativeLayoutNonce, nativeLayoutRestoreStylePayload, prepareNativeLayoutRestore } from '../../resources/js/outlook-addin/native-layout-restore.js';

const nonce = '1234567890abcdef1234567890abcdef';
const element = (tag, attrs = {}, children = []) => ({ tag, attrs, children });
const table = (className, children = [], attrs = {}) => element('table', { class: className, ...attrs }, [element('tbody', {}, [element('tr', {}, [element('td', {}, children)])])]);

// This source builder records offsets as it emits bytes; no DOM serialization is used.
function fixture({ header = true, legacy = false, quotedCopy = false } = {}) {
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
    const signature = table('rt-managed-signature-v1 rt-combined-signature-frame', [
        ...(header ? [banner] : []), ...(legacy ? [element('p', {}, ['Legacy source'])] : [flow]),
    ]);
    const tree = element('div', {}, [
        '<!-- prefix with unicode 🚆 and arbitrary bytes -->',
        table('rt-managed-combined-v1', [table('rt-managed-template-v1', [element('p', { class: 'design-spacer' }, ['Editable content'])]), signature]),
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
    assert.match(plan.stylePayload, /\[id\$="rt-native-layout-[0-9a-f]{32}-t"\]>tbody>tr>td>div>img\{width:520px!important;max-width:none!important;height:auto!important;\}/);
    assert.match(plan.stylePayload, /position:sticky!important;top:100%!important/);
    assert.match(plan.stylePayload, /-h"\]>tbody>tr:first-child td/);
    assert.doesNotMatch(plan.stylePayload, /cid:|url\(|title=|rt-delivery-train-mso|:last-child|calc\(/);
    assert.equal((plan.stylePayload.match(/-h"\]>tbody>tr:first-child /g) || []).length, 6);
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
