import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { managedTemplateContract, standaloneSignatureContract, fluidLayoutContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { applyV17OfficeWireEdits } from '../../resources/js/outlook-addin/v17-office-wire.js';
import { v17MsoColumnEdits, normalizeV17MsoColumns } from '../../resources/js/outlook-addin/v17-mso-columns.js';

const fixture = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const host = profile => ({ context: { platform: profile === 'M' ? 'OfficeOnline' : 'PC', mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } } } });
const sourceContract = (html, kind) => kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
const expand = html => html;
function readback(html, plan) {
    return { ...fluidLayoutContract(html, html, plan.nonce, plan.version), sourceLogoUrl: plan.sourceLogoUrl };
}
function prepared(source, kind, profile) {
    const plan = prepareNativeLayoutRestore(source, sourceContract(source, kind), '82'.repeat(16), host(profile));
    let html = plan.html;
    if (!html.includes('rt-v17-inline-columns')) {
        const contract = readback(html, plan), material = verifyFluidLayoutMaterial(html, contract);
        html = applyV17OfficeWireEdits(html, v17MsoColumnEdits(html, contract, material));
    }
    return { ...plan, html };
}
function normalize(html, plan) {
    const contract = readback(html, plan), normalized = normalizeV17MsoColumns(html, contract);
    return { contract, normalized, proxy: { ...contract, elements: normalized.elements,
        signature: { ...contract.signature, node: normalized.elements[contract.signature.index] } } };
}

for (const [kind, source] of Object.entries(fixture.source)) {
    test('V17 ' + kind + ' source hybrid edits retain all original bytes and exact four widths', () => {
        const contract = sourceContract(source, kind), material = verifyFluidLayoutMaterial(source, contract, true);
        const edits = v17MsoColumnEdits(source, contract, material), wire = applyV17OfficeWireEdits(source, edits);
        assert.equal(edits.length, 5);
        assert.doesNotMatch(wire, /<!--\[if mso\]>/);
        let offset = 0; const inverse = [...edits].sort((a,b) => a.start-b.start).map(edit => { const start=edit.start+offset;offset+=edit.value.length;return {start,end:start+edit.value.length,value:''}; });
        assert.equal(applyV17OfficeWireEdits(wire,inverse), source);
        assert.match(wire, /width="100%"/);
        for (const width of [220, 280, 210, 247]) assert.match(wire, new RegExp('<td width="' + width + '" valign="top"'));
        assert.throws(() => v17MsoColumnEdits(wire, sourceContract(wire, kind), verifyFluidLayoutMaterial(wire, sourceContract(wire, kind), true)));
    });
    for (const profile of ['M', 'B']) test('V17 ' + kind + '/' + profile + ' exact physical hybrid is virtual and fully re-proved', () => {
        const plan = prepared(source, kind, profile), expanded = expand(plan.html), { contract, normalized, proxy } = normalize(expanded, plan);
        assert.equal(normalized.msoColumns, true);
        assert.equal(normalized.elements.length, contract.elements.length);
        assert.notEqual(normalized.elements, contract.elements);
        for (const [index, node] of normalized.elements.entries()) {
            for (const key of ['start', 'end', 'openEnd', 'close', 'tag']) assert.equal(node[key], contract.elements[index][key]);
            assert.deepEqual(node.attrs, contract.elements[index].attrs);
        }
        assert.equal(verifyFluidLayoutMaterial(expanded, proxy).family, 17);
        assert.equal(normalizeV17MsoColumns(expanded, proxy).msoColumns, false);
        assert.equal(normalizeV17MsoColumns(plan.html, readback(plan.html, plan)).msoColumns, true);
        assert.ok(contract.elements.some(node => node.attrs.class === 'rt-v17-inline-columns' && Number.isInteger(node.parent)));
        assert.ok(normalized.elements.some(node => node.attrs.class === 'rt-v17-inline-columns' && node.parent === null));
    });
}

test('strictly bounded omitted ghost TBODY and px-to-pt Word styles keep the same proof', () => {
    const plan = prepared(fixture.source.standalone, 'standalone', 'B');
    let html = expand(plan.html);
    const begin = html.indexOf('<table class="rt-v17-inline-columns"'), end = html.indexOf('</td></tr></tbody></table><br', begin);
    assert.ok(begin >= 0 && end > begin);
    html = html.slice(0, begin) + html.slice(begin, end + '</td></tr></tbody></table>'.length)
        .replace(/<tbody>(<tr\b[^>]*>)/, '$1').replace(/<\/tr><\/tbody><\/table>$/, '</tr></table>') + html.slice(end + '</td></tr></tbody></table>'.length);
    const { normalized, proxy } = normalize(html, plan);
    assert.equal(normalized.msoColumns, true); assert.equal(verifyFluidLayoutMaterial(html, proxy).family, 17);
    const pts = expand(plan.html).replace('width:220px;max-width:100%;padding:0;', 'width:165pt;max-width:100%;padding:0pt;')
        .replace('width:280px;max-width:100%;padding:0;', 'width:210pt;max-width:100%;padding:0pt;')
        .replace('width:210px;max-width:100%;padding:0;', 'width:157.5pt;max-width:100%;padding:0pt;')
        .replace('width:247px;max-width:100%;padding:0;', 'width:185.25pt;max-width:100%;padding:0pt;');
    const converted = normalize(pts, plan); assert.equal(converted.normalized.msoColumns, true); assert.equal(verifyFluidLayoutMaterial(pts, converted.proxy).family, 17);
});

test('M requires retained modern inline hybrid properties while B permits only their finite Word loss', () => {
    const dropUnsupported = html => html
        .replace('width:100%;max-width:100%;border-collapse:collapse;table-layout:fixed;mso-table-lspace:0;mso-table-rspace:0;', 'width:100%;border-collapse:collapse;table-layout:fixed;')
        .replace('display:block!important;width:100%!important;font-size:0;line-height:0;', '')
        .replaceAll('display:inline-block!important;width:', 'width:')
        .replaceAll(';max-width:100%;padding:0;vertical-align:top;font-size:0;line-height:0;', ';padding:0;vertical-align:top;');
    const source = fixture.source.standalone;
    const bounded = prepared(source, 'standalone', 'B'), word = dropUnsupported(bounded.html);
    const result = normalize(word, bounded);
    assert.equal(result.normalized.inlineHybrid, true); assert.equal(verifyFluidLayoutMaterial(word, result.proxy).family, 17);
    const modern = prepared(source, 'standalone', 'M');
    assert.throws(() => normalize(dropUnsupported(modern.html), modern));
    assert.throws(() => normalize(word.replace('width:220px;padding:0;', 'width:220px;padding:1px;'), bounded));
    const wordWidth = word.replace('class="rt-v17-inline-columns" role="presentation" width="100%"', 'class="rt-v17-inline-columns" role="presentation" width="957"')
        .replace('width:100%;border-collapse:collapse;table-layout:fixed;', 'width:957px;border-collapse:collapse;table-layout:fixed;');
    const resized = normalize(wordWidth, bounded); assert.equal(resized.normalized.inlineHybrid, true); assert.equal(verifyFluidLayoutMaterial(wordWidth, resized.proxy).family, 17);
});

test('losing one modern cell display or max-width cannot be treated as responsive current material', () => {
    const plan = prepared(fixture.source.combined, 'combined', 'M');
    for (const [before, after] of [['display:inline-block!important;width:220px', 'width:220px'],
        ['width:280px;max-width:100%;padding:0;', 'width:280px;padding:0;'],
        ['display:block!important;width:100%!important', 'display:block!important;width:99%!important'],
        ['display:inline-block!important;width:247px', 'display:block!important;width:247px']]) {
        const html = plan.html.replace(before, after); assert.notEqual(html, plan.html); assert.throws(() => normalize(html, plan));
    }
});

test('unknown ghost attributes, rows, cells, text and nonzero geometry never flatten', () => {
    const plan = prepared(fixture.source.combined, 'combined', 'B'), source = expand(plan.html);
    const cases = [
        source.replace('class="rt-v17-inline-columns"', 'class="rt-v17-inline-columns foreign"'),
        source.replace('class="rt-v17-inline-columns"', 'class="rt-v17-inline-columns" data-foreign="1"'),
        source.replace('class="rt-v17-inline-columns" role="presentation" width="100%"', 'class="rt-v17-inline-columns" role="presentation" width="99%"'),
        source.replace('width:100%;max-width:100%;border-collapse:collapse;table-layout:fixed;mso-table-lspace', 'width:99%;max-width:100%;border-collapse:collapse;table-layout:fixed;mso-table-lspace'),
        source.replace('mso-table-rspace:0;', 'mso-table-rspace:1px;'),
        source.replace('width:220px;max-width:100%;padding:0;', 'width:220px;max-width:100%;padding:1px;'),
        source.replace('<td width="220" valign="top"', '<td width="221" valign="top"'),
        source.replace('<td width="220" valign="top"', '<td width="220" data-foreign="1" valign="top"'),
        source.replace('<td width="280" valign="top"', '<td width="280" valign="bottom"'),
        source.replace('width:220px;max-width:100%;padding:0;', 'width:220px;max-width:100%;padding:0;float:left;'),
        source.replace(/(<td width="220"[^>]*>)/, '$1Visible foreign text'),
        source.replace(/(<td width="220"[^>]*>)/, '$1<span>foreign</span>'),
        source.replace('class="rt-v17-inline-columns"', 'class="rt-v17-inline-columns" align="left"'),
    ];
    for (const html of cases) assert.throws(() => normalize(html, plan));
    assert.equal(normalize(source, plan).normalized.msoColumns, true);
});

test('editable bytes, quoted history and historical families are opaque', () => {
    const original = fixture.source.combined, contract = managedTemplateContract(original);
    const editable = '<p>Typed content stays byte-exact.</p>';
    const quote = '<blockquote><table width="957"><tbody><tr><td>Historical unrelated table</td></tr></tbody></table></blockquote>';
    const source = original.slice(0, contract.region.start) + editable + original.slice(contract.region.end) + quote;
    const plan = prepared(source, 'combined', 'M'), html = expand(plan.html);
    const { contract: bound, normalized, proxy } = normalize(html, plan);
    assert.ok(html.includes(editable)); assert.ok(html.endsWith(quote)); assert.equal(normalized.msoColumns, true);
    assert.equal(verifyFluidLayoutMaterial(html, proxy).family, 17);
    const legacy = { ...bound, fluidLayout: { ...bound.fluidLayout, family: 16 } };
    assert.equal(normalizeV17MsoColumns(html, legacy).elements, bound.elements);
    assert.equal(normalizeV17MsoColumns(html, legacy).msoColumns, false);
    assert.throws(() => v17MsoColumnEdits(original, managedTemplateContract(original), { family: 16 }));
});
