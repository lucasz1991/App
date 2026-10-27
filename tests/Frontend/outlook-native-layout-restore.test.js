import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createNativeLayoutNonce, nativeLayoutRestoreCarrierPresent, nativeLayoutRestoreCss, nativeLayoutRestoreResponsiveCss, nativeLayoutRestoreRetained, nativeLayoutRestoreStylePayload, prepareNativeLayoutRestore, removeNativeLayoutRestoreCarrier } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';

const nonce = '1234567890abcdef1234567890abcdef';
const element = (tag, attrs = {}, children = []) => ({ tag, attrs, children });
const table = (className, children = [], attrs = {}) => element('table', { class: className, ...attrs }, [element('tbody', {}, [element('tr', {}, [element('td', {}, children)])])]);

// This source builder records offsets as it emits bytes; no DOM serialization is used.
function fixture({ header = true, legacy = false, quotedCopy = false, legal = false, legalColor = '#718096', wire = false, standalone = false, responsive = false, wrappers = false, templateGutter = false, ownedRaster = false } = {}) {
    const metadata = value => element('span', { hidden: '', style: 'font-size:0;line-height:0;' }, [value]);
    const person = table('rt-personal-left', [element('p', {}, ['Synthetic personal text'])]);
    const company = table('rt-personal-right', [element('img', { src: 'cid:logo.png', width: '180', height: '31' })]);
    const logo = table('rt-personal-logo-column', [table('rt-personal-logo-frame', [element('img', { src: 'cid:separate-logo.png', width: '200', height: '34' })])]);
    const train = table('rt-df-train', [element('div', { class: 'rt-train-window' }, [element('img', {
        class: 'rt-delivery-train', src: 'cid:train.gif', width: '300', height: '38',
    })]), '<!--[if mso]><img class="rt-delivery-train-mso" src="cid:still.png" width="220" height="28"><![endif]-->']);
    const flow = table('rt-personal-columns', [...(responsive ? [logo] : []), person, company, train], {
        'data-rt-personal-flow': 'v1', 'data-rt-personal-columns': 'v1', 'data-rt-desktop-flow': 'outlook-desktop-flow-v' + (responsive ? '5' : '4'),
    });
    const gutter = table('rt-brand-hotline-gutter', [element('img', { src: 'cid:fill.png', width: '100%', height: '34' })]);
    const raster = element('table', { class: 'owned-raster', style: 'table-layout:auto;' }, [element('tbody', {}, [element('tr', {}, [
        element('td', { width: '1' }, [gutter]), ...['137', '42', '42', '100%'].map(width => element('td', {}, [element('img', { src: 'cid:raster.png', width, height: '34' })])),
    ])])]);
    const banner = element('table', { class: 'banner' }, [element('tbody', {}, [
        element('tr', {}, [element('td', { class: 'design-hotline-row' + (ownedRaster ? ' rt-brand-hotline-cell' : ''), ...(ownedRaster ? { 'data-rt-brand-hotline': 'v1' } : {}) }, ownedRaster ? [raster] : [...(wrappers ? [gutter] : []), element('strong', {}, ['24/7']), ' reachable'])]),
        element('tr', {}, [element('td', { style: 'color:#718096' }, ['Footer must keep its color'])]),
    ])]);
    const signature = table(standalone ? 'rt-managed-signature-v1 rts1234567890' : 'rt-managed-signature-v1 rt-combined-signature-frame', [
        ...(header ? [banner] : []), ...(legacy ? [element('p', {}, ['Legacy source'])] : [flow]),
    ]);
    if (legal) signature.children[0].children.push(element('tr', {}, [element('td', { style: `color:${legalColor};font-size:9px;line-height:14px;` }, [
        ...(wrappers ? [table('rt-brand-legal-table', [element('font', { color: '#000000' }, ['Synthetic legal information'])])] : [element('font', { color: '#000000' }, ['Synthetic legal information'])]),
        element('span', { hidden: '', style: 'font-size:0;line-height:0;' }, ['RT-SIGNATURE-MANAGED-V1']),
        element('span', { hidden: '', style: 'font-size:0;line-height:0;' }, ['RT-SIGNATURE-VERSION:1234567890abcdef']),
    ])]));
    const template = wire ? [metadata('RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT'),
        table('rt-compose-editable rt-compose-editable-v1', [metadata('RT-COMPOSE-EDITABLE-START-V1'), element('p', {}, ['Editable content']), metadata('RT-COMPOSE-EDITABLE-END-V1')])]
        : [element('p', { class: 'design-spacer' }, ['Editable content'])];
    const tree = element('div', {}, [
        '<!-- prefix with unicode 🚆 and arbitrary bytes -->',
        standalone ? signature : table('rt-managed-combined-v1', [table('rt-managed-template-v1', templateGutter ? [table('rt-shell design-v27', template)] : template), signature]),
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

test('v5 adds only the seventh logo TABLE ID and retains exact responsive CSS after Office rewrites', () => {
    const source = fixture({ responsive: true, standalone: true, legal: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    assert.equal(Object.keys(plan.ids).length, 7);
    assert.equal(plan.ids.logo, `rt-native-layout-${nonce}-l`);
    assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpcthsl]"/g, ''), source.html);
    const body = (plan.stylePayload + plan.html).replace(/id="rt-native-layout-/g, 'id="x_rt-native-layout-')
        .replace(/#rt-native-layout-/g, '#x_rt-native-layout-').replace(/;}/g, '}');
    const inferred = standaloneSignatureContract(body);
    assert.equal(inferred.layoutIds.logo, plan.ids.logo);
    assert.equal(nativeLayoutRestoreRetained(body, inferred, plan.ids), true);
    assert.equal(removeNativeLayoutRestoreCarrier(body, inferred, plan.ids), body.slice(body.indexOf('</table>') + 8));
    const css = nativeLayoutRestoreCss(plan.ids);
    const responsive = nativeLayoutRestoreResponsiveCss(plan.ids);
    assert.equal(css.split('display:inline-block!important;').length - 1, 3);
    assert.ok(css.includes('#' + plan.ids.train + '{display:inline-table!important;vertical-align:bottom!important;'));
    assert.equal(css.split('width:100%!important;min-width:0!important;max-width:340px!important;direction:ltr!important;').length - 1, 4);
    assert.match(css, /max-width:680px!important;/);
    assert.match(css, /direction:rtl!important;/);
    assert.match(css, /-l\{margin-bottom:10px!important;\}/);
    assert.match(css, /border-top:4px solid transparent!important;/);
    assert.doesNotMatch(css, /min\(|max\(|calc\(|@media|[;{](?:float|clear|position|top):/);
    assert.doesNotMatch(responsive, /min\(|max\(|calc\(/);
    assert.match(responsive, /@media\(max-width:680px\)\{/);
    assert.match(responsive, /width:100%!important;min-width:0!important;max-width:100%!important;/);
    assert.match(css, /border-left:40px solid transparent!important;border-right:40px solid transparent!important/);
    assert.match(responsive, /border-right-width:18px!important/);
    const rules = [...(css + responsive).replace('@media(max-width:680px){', '').matchAll(/([^{}]+)\{([^{}]*)\}/g)];
    assert.ok(rules.length > 10);
    for (const [, selector] of rules) assert.match(selector, /^#rt-native-layout-[0-9a-f]{32}-[rpcthslif]$/);
    assert.doesNotMatch(css, /(?:^|[},])\s*(?:img|div|font|span|td|table)\b|[>+~]|width:520px|first-child|last-child/);
    assert.throws(() => nativeLayoutRestoreRetained(body.replace('max-width:680px', 'max-width:681px'), inferred, plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
});

test('v5 binds optional inset/legal TABLE wrappers with pure ID responsive rules and exact retained ownership', () => {
    const source = fixture({ responsive: true, standalone: true, legal: true, wrappers: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    assert.equal(Object.keys(plan.ids).length, 9);
    assert.equal(plan.ids.inset, `rt-native-layout-${nonce}-i`);
    assert.equal(plan.ids.footer, `rt-native-layout-${nonce}-f`);
    assert.equal(plan.html.replace(/ id="rt-native-layout-[0-9a-f]{32}-[rpcthslif]"/g, ''), source.html);
    const body = plan.stylePayload + plan.html;
    const parsed = standaloneSignatureContract(body);
    assert.deepEqual(parsed.layoutIds, plan.ids);
    assert.equal(nativeLayoutRestoreRetained(body, parsed, plan.ids), true);
    const css = nativeLayoutRestoreCss(plan.ids);
    const responsive = nativeLayoutRestoreResponsiveCss(plan.ids);
    assert.match(css, /-i\{width:40px!important;min-width:40px!important;max-width:40px!important;\}/);
    assert.match(responsive, /-i\{width:18px!important;min-width:18px!important;max-width:18px!important;\}/);
    assert.match(responsive, /-f\{border-left-width:18px!important;border-right-width:18px!important;\}/);
    assert.doesNotMatch(css, /[>+~]|width:520px|first-child|last-child/);
    assert.equal(removeNativeLayoutRestoreCarrier(body, parsed, plan.ids), plan.html);
});

test('only the owned v5 raster places h on its inner five-cell TABLE and overrides the fixed mirror with automatic layout', () => {
    const source = fixture({ responsive: true, standalone: true, legal: true, wrappers: true, ownedRaster: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    const parsed = standaloneSignatureContract(plan.html, plan.html, plan.ids);
    const header = parsed.elements.find(node => node.attrs.id === plan.ids.header);
    assert.equal(header.attrs.class, 'owned-raster');
    const hotline = parsed.elements[header.parent];
    assert.equal(hotline.attrs['data-rt-brand-hotline'], 'v1');
    assert.match(nativeLayoutRestoreCss(plan.ids), /-h\{color:#ffffff!important;table-layout:auto!important;width:100%!important;\}/);
    const body = plan.stylePayload + plan.html;
    assert.equal(nativeLayoutRestoreRetained(body, standaloneSignatureContract(body), plan.ids), true);
    for (const responsive of [false, true]) {
        const other = fixture({ responsive, standalone: true, legal: true, wrappers: true });
        const fallback = prepareNativeLayoutRestore(other.html, standaloneSignatureContract(other.html), nonce);
        const fallbackHeader = standaloneSignatureContract(fallback.html, fallback.html, fallback.ids).elements.find(node => node.attrs.id === fallback.ids.header);
        assert.equal(fallbackHeader.attrs.class, 'banner');
    }
});

test('v5 template gutter ID belongs only to the combined template and retains pure ID responsive borders', () => {
    const source = fixture({ responsive: true, wire: true, legal: true, wrappers: true, templateGutter: true });
    const plan = prepareNativeLayoutRestore(source.html, managedTemplateContract(source.html), nonce);
    assert.equal(Object.keys(plan.ids).length, 10);
    assert.equal(plan.ids.templateGutter, `rt-native-layout-${nonce}-q`);
    const body = plan.stylePayload + plan.html;
    const parsed = managedTemplateContract(body);
    assert.equal(nativeLayoutRestoreRetained(body, parsed, plan.ids), true);
    assert.equal(removeNativeLayoutRestoreCarrier(body, parsed, plan.ids), plan.html);
    const css = nativeLayoutRestoreCss(plan.ids);
    const responsive = nativeLayoutRestoreResponsiveCss(plan.ids);
    assert.match(responsive, /-q\{border-left-width:18px!important;border-right-width:18px!important;\}/);
    for (const [, selector] of (css + responsive).replace('@media(max-width:680px){', '').matchAll(/([^{}]+)\{[^{}]*\}/g)) {
        assert.match(selector, /^#rt-native-layout-[0-9a-f]{32}-[rpcthslifq]$/);
    }
    const foreign = { ...parsed, template: parsed.signature };
    assert.throws(() => nativeLayoutRestoreRetained(body, foreign, plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
});

test('v5 exact function-free core is required while the separate responsive sheet may be stripped or retain approved rule subsets', () => {
    const source = fixture({ responsive: true, standalone: true, legal: true, wrappers: true });
    const plan = prepareNativeLayoutRestore(source.html, standaloneSignatureContract(source.html), nonce);
    const optional = nativeLayoutRestoreResponsiveCss(plan.ids);
    const media = optional;
    const firstRule = optional.slice('@media(max-width:680px){'.length, -1).match(/^#[^{}]+\{[^{}]+\}/)[0];
    const optionalNode = /<style data-rt-native-layout-responsive="v1">[\s\S]*?<\/style>/;
    for (const value of [null, '', media, '@media(max-width:680px){' + firstRule + '}', '@media(max-width:680px){}']) {
        const payload = plan.stylePayload.replace(optionalNode, value === null ? '' : '<style>' + value + '</style>');
        const body = payload + plan.html;
        const parsed = standaloneSignatureContract(body);
        assert.equal(nativeLayoutRestoreRetained(body, parsed, plan.ids), true);
        assert.equal(removeNativeLayoutRestoreCarrier(body, parsed, plan.ids), plan.html);
    }
    for (const changed of [
        plan.stylePayload.replace('max-width:340px!important;', 'max-width:341px!important;'),
        plan.stylePayload.replace(optionalNode, '<style>' + firstRule + '</style>'),
        plan.stylePayload.replace(optionalNode, '<style>' + firstRule + firstRule + '</style>'),
        plan.stylePayload.replace(optionalNode, '<style>img{width:520px!important;}</style>'),
        plan.stylePayload.replace(optionalNode, '<style>@media(max-width:681px){}</style>'),
        plan.stylePayload.replace(optionalNode, '<style>' + firstRule + '</style><style></style>'),
    ]) {
        const body = changed + plan.html;
        const parsed = standaloneSignatureContract(body);
        assert.throws(() => nativeLayoutRestoreRetained(body, parsed, plan.ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
        assert.equal(removeNativeLayoutRestoreCarrier(body, parsed, plan.ids), body);
    }
});

test('actual sanitized R60 filtered optional declarations permit exact owned cleanup but never acknowledge the new hybrid core', () => {
    const body = readFileSync(new URL('../Fixtures/outlook/r60-office-filtered-responsive.html', import.meta.url), 'utf8');
    const contract = managedTemplateContract(body);
    const suffixes = { r: 'root', p: 'person', c: 'company', t: 'train', h: 'header', s: 'signature', l: 'logo', i: 'inset', f: 'footer', q: 'templateGutter' };
    const ids = {};
    for (const node of contract.elements) {
        const match = /^(?:x_)?(rt-native-layout-[0-9a-f]{32}-)([rpcthslifq])$/.exec(node.attrs.id || '');
        if (match) ids[suffixes[match[2]]] = match[1] + match[2];
    }
    assert.equal(Object.keys(ids).length, 10);
    assert.throws(() => nativeLayoutRestoreRetained(body, contract, ids), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    const carrier = contract.elements.find(node => (node.attrs.id || '').endsWith('-carrier'));
    assert.equal(removeNativeLayoutRestoreCarrier(body, contract, ids), body.slice(0, carrier.start) + body.slice(carrier.end));
    const fullCalc = body.replace(/(\{\s*)(min-width:50%!important;)/g, '$1width:calc(680000px - 100000%)!important;$2');
    assert.notEqual(removeNativeLayoutRestoreCarrier(fullCalc, managedTemplateContract(fullCalc), ids), fullCalc);
    for (const changed of [body.replace('width:460px!important', 'width:461px!important'), body.replace('min-width:50%!important', 'min-width:51%!important')]) {
        assert.equal(removeNativeLayoutRestoreCarrier(changed, managedTemplateContract(changed), ids), changed);
    }
});

test('v5 requires the exact logo/person/company/train sibling order and one bounded logo frame', () => {
    for (const corrupt of [
        source => { source.elements[source.byClass('rt-personal-logo-column')].parent = source.byClass('rt-personal-right'); },
        source => { source.elements[source.byClass('rt-personal-logo-frame')].attrs.class = 'foreign-logo-frame'; },
        source => { source.elements[source.byClass('rt-personal-columns')].attrs['data-rt-desktop-flow'] = 'outlook-desktop-flow-v6'; },
    ]) {
        const source = fixture({ responsive: true }); corrupt(source);
        assert.throws(() => prepareNativeLayoutRestore(source.html, source.contract, nonce), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
    }
    const source = fixture({ responsive: true });
    const logoIndex = source.byClass('rt-personal-logo-column');
    const personIndex = source.byClass('rt-personal-left');
    const oldParent = source.elements[logoIndex].parent;
    source.elements[logoIndex].parent = personIndex;
    source.elements[personIndex].parent = oldParent;
    assert.throws(() => prepareNativeLayoutRestore(source.html, source.contract, nonce), { code: 'NATIVE_LAYOUT_RESTORE_INVALID' });
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
