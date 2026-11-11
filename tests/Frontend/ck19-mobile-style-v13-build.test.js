import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { parseHTML } from 'linkedom';
import postcss from 'postcss';
import { generateCk19MobileStyle, ck19Manifest, buildCk19MobileStyles,
    ck19V13RuleTextFromSourceFile, CK19_APPROVED_V13_SHA256, CK19_APPROVED_V13_CHARACTERS,
    CK19_V13_SOURCE_FILE_SHA256, CK19_APPROVED_V11_SHA256, CK19_APPROVED_V12_SHA256 } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const mappingBytes = await read('resources/mail-templates/ck19-mobile-style-v13.mapping.json');
const generatorBytes = await read('scripts/build-ck19-mobile-style.mjs');
const mapping = JSON.parse(mappingBytes);
const supplied = JSON.parse(await read('tests/Fixtures/mail/ck19-mobile-style-v13/approved-source-file.json'));
const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
const generated = generate();
const rules = postcss.parse(generated.payload.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]).nodes[0].nodes;

test('v13 exactly reproduces its tracked original source with only the excluded terminal LF omitted', () => {
    assert.equal(supplied.sourceFileHtml.length, 3802);
    assert.equal(sha(supplied.sourceFileHtml), CK19_V13_SOURCE_FILE_SHA256);
    assert.equal(ck19V13RuleTextFromSourceFile(supplied.sourceFileHtml), generated.payload);
    assert.equal(supplied.sourceFileHtml, generated.payload + '\n');
    assert.equal(generated.payload.length, CK19_APPROVED_V13_CHARACTERS);
    assert.equal(generated.payload.length, 3801);
    assert.equal(Buffer.byteLength(generated.payload), 3801);
    assert.equal(sha(generated.payload), CK19_APPROVED_V13_SHA256);
    assert.equal(generated.templateHash, '0201650e3985cf95b7ef84d112fede5d8338017f6d725a764906da1a99e1c5dc');
    assert.equal(supplied.sourceTemplateSha256, '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204', 'Original approved source metadata remains historical.');
    assert.equal(generated.cssHash, '44320e37dbd5376595ecbd11938fcc96b5be372088dea343fed9a187d18f746f');
    assert.equal(supplied.sourceCssSha256, '502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1');
    assert.equal(supplied.nativeV13Proof, false);
    assert.equal(generated.rules, 34);
    assert.equal(generated.derivedDeclarations, 77);
    assert.equal(generated.transportOverrides, 26);
});

test('v13 source normalization is exact rather than trim or line-ending repair', () => {
    const raw = supplied.sourceFileHtml;
    for (const changed of [raw + '\n', raw.slice(0, -1), raw.replace(/\n$/, '\r\n'), raw.replace('#e4002b', '#e4002c')]) {
        assert.throws(() => ck19V13RuleTextFromSourceFile(changed), /CK19_V13_SOURCE_FILE_INVALID/);
    }
    const changed = structuredClone(mapping); changed.sourceFileNormalization.operation = 'trim';
    assert.throws(() => generate({ mapping: changed }), /CK19_V13_NORMALIZATION_CONTRACT_INVALID/);
});

test('v13 provenance is deterministic and the expanded hook verifies all three independent artifacts', async () => {
    const manifest = ck19Manifest({ generated, mappingBytes, generatorBytes });
    assert.deepEqual(manifest, ck19Manifest({ generated: generate(), mappingBytes, generatorBytes }));
    assert.equal(manifest.sourceTemplateSha256, sha(templateHtml));
    assert.equal(manifest.mappingSha256, sha(mappingBytes));
    assert.equal(manifest.generatorSha256, sha(generatorBytes));
    assert.equal(manifest.payloadSha256, CK19_APPROVED_V13_SHA256);
    assert.equal(manifest.rules, 34);
    assert.equal(manifest.nativeClientProof, false);
    assert.equal(manifest.exchangeRuleUpdatedByBuild, false);
    const all = await buildCk19MobileStyles({ check: true, releases: ['v11', 'v12', 'v13'] });
    assert.deepEqual(all.map(value => value.payloadSha256), [CK19_APPROVED_V11_SHA256, CK19_APPROVED_V12_SHA256, CK19_APPROVED_V13_SHA256]);
    for (const releases of [[], ['v13', 'v13'], ['v99']]) {
        await assert.rejects(buildCk19MobileStyles({ check: true, releases }), /CK19_BUILD_RELEASE_INVALID/);
    }
});

test('v13 leaves both previous rule payloads byte-exact', async () => {
    for (const [tag, length, digest] of [['v11', 4999, CK19_APPROVED_V11_SHA256], ['v12', 4262, CK19_APPROVED_V12_SHA256]]) {
        const payload = await fs.readFile(new URL('resources/mail-templates/generated/ck19-mobile-style-' + tag + '.html', root));
        assert.equal(payload.length, length); assert.equal(sha(payload), digest);
    }
});

test('v13 rejects changed CSS, changed body plus PHP pin and stale PHP pin', () => {
    assert.throws(() => generate({ templateHtml: templateHtml.replace('width:52%', 'width:51%') }), /CK19_SOURCE_CSS_CHANGED_REVIEW_MAPPING/);
    const changed = templateHtml.replace('width="247"', 'width="246"');
    assert.notEqual(changed, templateHtml);
    assert.throws(() => generate({ templateHtml: changed, presentationPhp: presentationPhp.replace(generated.templateHash, sha(changed)) }), /CK19_SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING/);
    assert.throws(() => generate({ presentationPhp: presentationPhp.replace(generated.templateHash, '0'.repeat(64)) }), /CK19_SOURCE_TEMPLATE_PIN_MISMATCH/);
});

test('v13 keeps declaration provenance strict and rejects self-approved output changes', () => {
    const missing = structuredClone(mapping);
    missing.rules[0].sourceSelectors[0].selector = '.missing'; missing.rules[0].declarations[0].from.selector = '.missing';
    assert.throws(() => generate({ mapping: missing }), /CK19_SOURCE_REFERENCE_AMBIGUOUS/);
    const changed = structuredClone(mapping); changed.rules[0].selector = 'table.foreign';
    assert.throws(() => generate({ mapping: changed }), /CK19_APPROVED_V13_DRIFT/);
    changed.approvedPayloadSha256 = '0'.repeat(64);
    assert.throws(() => generate({ mapping: changed }), /CK19_MAPPING_INVALID/);
});

test('v13 forbids unsupported selector forms and refuses 5000/5001 characters', () => {
    assert.doesNotMatch(generated.payload, /\[class\s*\$\s*=|:not\s*\(|<meta/i);
    for (const selector of ['[class$="rt-contact"]', 'tr:not(:last-child)']) {
        const changed = structuredClone(mapping); changed.rules[0].selector = selector;
        assert.throws(() => generate({ mapping: changed }), /CK19_RULE_INVALID/);
    }
    for (const length of [1204, 1205]) {
        const changed = structuredClone(mapping);
        changed.rules[0].declarations[0] = { property: 'display', override: 'x'.repeat(length), important: true, reason: 'Exact 5000/5001-character negative fixture' };
        assert.throws(() => generate({ mapping: changed }), /CK19_PAYLOAD_LENGTH/);
    }
});

test('v13 panel head and action selectors no longer use the rejected nested positional chains', () => {
    const panel = rules.filter(rule => /rt-dispo(?:-head|-actions)?|rt-cta-cell/.test(rule.selector));
    assert.ok(panel.length > 10);
    assert.ok(panel.every(rule => !/:nth-child|:last-child|:first-child/.test(rule.selector)));
    assert.ok(rules.some(rule => rule.selector === 'div.rt-dispo-head b,div.rt-dispo-head i'));
    assert.ok(rules.some(rule => rule.selector === 'table.rt-dispo-actions td'));
});

// These assertions prove selector targets and display topology only. CSS
// layout, inheritance under host inline styles and native iOS remain unproved.
const mobileShape = '<table class="rt-shell"><tbody><tr><td><table class="rt-dispo"><tbody>'
    + '<tr><td class="rt-dispo-cap"></td></tr><tr><td class="rt-dispo-head"><table class="rt-dispo-head"><tbody><tr><td>'
    + '<div class="rt-dispo-head"><span><b>Dispo</b></span><span>erreichbar</span><br></div></td><td><div class="rt-dispo-head"><i>24/7</i></div>'
    + '</td></tr></tbody></table></td></tr><tr><td class="rt-dispo-actions"><table class="rt-dispo-actions"><tbody>'
    + '<tr class="rt-cta-line"><td colspan="5"></td></tr><tr><td class="rt-cta-cell"><img src="cta-1.png"></td><td class="rt-cta-cell"><img src="cta-2.png"></td>'
    + '<td class="rt-cta-cell rt-cta-gap"></td><td class="rt-cta-cell"><img src="cta-3.png"></td><td class="rt-cta-cell"><span><b>+49 000 0000</b></span></td>'
    + '</tr></tbody></table></td></tr><tr><td class="rt-dispo-cap"></td></tr></tbody></table></td></tr></tbody></table>';
function shapeDocument(html) { return parseHTML('<html><body>' + html + '</body></html>').document; }
function propertyTargets(document, property, value) {
    return new Set(rules.filter(rule => rule.nodes.some(declaration => declaration.prop === property && declaration.value === value))
        .flatMap(rule => [...document.querySelectorAll(rule.selector)]));
}

test('v13 targets the serialized mobile b/i head while leaving its wrapping spans in the tree', () => {
    const document = shapeDocument(mobileShape), zero = propertyTargets(document, 'font-size', '0'), visible = propertyTargets(document, 'font-size', '14px');
    const bold = document.querySelector('div.rt-dispo-head b'), italic = document.querySelector('div.rt-dispo-head i');
    assert.ok(visible.has(bold)); assert.ok(visible.has(italic));
    assert.ok([...document.querySelectorAll('div.rt-dispo-head span')].every(node => zero.has(node)));
    const hidden = propertyTargets(document, 'display', 'none');
    assert.ok([...document.querySelectorAll('div.rt-dispo-head span')].every(node => !hidden.has(node)));
    assert.ok(propertyTargets(document, 'padding-left', '5px').has(italic));
});

test('v13 hides only panel caps, separator and gap without hiding CTA icons or number cells', () => {
    const document = shapeDocument(mobileShape), hidden = propertyTargets(document, 'display', 'none');
    assert.equal(hidden.size, 4);
    assert.ok([...document.querySelectorAll('.rt-dispo-cap,.rt-cta-line,.rt-cta-gap')].every(node => hidden.has(node)));
    for (const image of document.querySelectorAll('img')) for (let at = image; at; at = at.parentElement) assert.equal(hidden.has(at), false);
    const number = document.querySelector('td.rt-cta-cell b');
    assert.ok(propertyTargets(document, 'font-size', '13px').has(number));
    assert.ok(propertyTargets(document, 'line-height', '24px').has(number));
});

test('v13 retains an explicit same-class scope boundary outside the outer shell', () => {
    const plain = shapeDocument('<table><tbody><tr><td colspan="5">Foreign content</td></tr></tbody></table>');
    assert.ok(rules.every(rule => plain.querySelectorAll(rule.selector).length === 0));
    const sameClass = shapeDocument('<div class="rt-dispo-head"><b>Other content with a matching class</b></div>');
    assert.equal(sameClass.querySelectorAll('table.rt-shell').length, 0);
    assert.ok(propertyTargets(sameClass, 'font-size', '14px').has(sameClass.querySelector('b')));
    assert.match(mapping.policy.scope, /outside table\.rt-shell/);
    assert.match(mapping.policy.scope, /Unrelated-content compatibility is not asserted/);
});
