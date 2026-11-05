import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { parseHTML } from 'linkedom';
import postcss from 'postcss';
import { generateCk19MobileStyle, ck19Manifest, buildCk19MobileStyles,
    ck19V12RuleTextFromSourceFile, CK19_APPROVED_V12_SHA256, CK19_APPROVED_V12_CHARACTERS,
    CK19_V12_SOURCE_FILE_SHA256, CK19_APPROVED_V11_SHA256 } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const mappingBytes = await read('resources/mail-templates/ck19-mobile-style-v12.mapping.json');
const generatorBytes = await read('scripts/build-ck19-mobile-style.mjs');
const mapping = JSON.parse(mappingBytes);
const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
const generated = generate();
const outputRules = postcss.parse(generated.payload.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]);

test('C-K23 derives the exact approved v12 payload from the v2.5 source and explicit class mapping', () => {
    assert.equal(generated.payload.length, CK19_APPROVED_V12_CHARACTERS);
    assert.equal(generated.payload.length, 4262);
    assert.equal(Buffer.byteLength(generated.payload), 4262);
    assert.equal(sha(generated.payload), CK19_APPROVED_V12_SHA256);
    assert.equal(generated.templateHash, 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf');
    assert.equal(generated.rules, 36);
    assert.equal(generated.derivedDeclarations, 80);
    assert.equal(generated.transportOverrides, 21);
    assert.doesNotMatch(generated.payload, /\[class\s*\$\s*=|:not\s*\(|<meta/i);
    assert.equal(outputRules.nodes.length, 1);
    assert.equal(outputRules.nodes[0].params, 'only screen and (max-width:1039px)');
});

test('C-K23 omits only the approved source-file terminal LF without generic trimming', () => {
    const raw = generated.payload + '\n';
    assert.equal(raw.length, 4263);
    assert.equal(sha(raw), CK19_V12_SOURCE_FILE_SHA256);
    assert.equal(ck19V12RuleTextFromSourceFile(raw), generated.payload);
    for (const changed of [raw + '\n', raw.slice(0, -1), raw.replace(/\n$/, '\r\n'), raw.replace('#e4002b', '#e4002c')]) {
        assert.throws(() => ck19V12RuleTextFromSourceFile(changed), /CK19_V12_SOURCE_FILE_INVALID/);
    }
    const changed = structuredClone(mapping);
    changed.sourceFileNormalization.operation = 'trim';
    assert.throws(() => generate({ mapping: changed }), /CK19_V12_NORMALIZATION_CONTRACT_INVALID/);
});

test('C-K23 manifest deterministically binds template, CSS, mapping, generator and exact payload', async () => {
    const manifest = ck19Manifest({ generated, mappingBytes, generatorBytes });
    assert.deepEqual(manifest, ck19Manifest({ generated: generate(), mappingBytes, generatorBytes }));
    assert.equal(manifest.sourceTemplateSha256, sha(templateHtml));
    assert.equal(manifest.sourceCssSha256, mapping.sourceCssSha256);
    assert.equal(manifest.mappingSha256, sha(mappingBytes));
    assert.equal(manifest.generatorSha256, sha(generatorBytes));
    assert.equal(manifest.payloadSha256, CK19_APPROVED_V12_SHA256);
    assert.equal(manifest.rules, 36);
    assert.equal(manifest.nativeClientProof, false);
    assert.equal(manifest.exchangeRuleUpdatedByBuild, false);
    const both = await buildCk19MobileStyles({ check: true, releases: ['v11', 'v12'] });
    assert.deepEqual(both.map(value => value.payloadSha256), [CK19_APPROVED_V11_SHA256, CK19_APPROVED_V12_SHA256]);
    assert.equal(sha(await read('resources/mail-templates/generated/ck19-mobile-style-v11.html')), CK19_APPROVED_V11_SHA256);
});

test('C-K23 rejects changed CSS, body plus PHP pin, stale PHP pin and ambiguous declaration provenance', () => {
    assert.throws(() => generate({ templateHtml: templateHtml.replace('width:52%', 'width:51%') }), /CK19_SOURCE_CSS_CHANGED_REVIEW_MAPPING/);
    const changedBody = templateHtml.replace('width="247"', 'width="246"');
    assert.notEqual(changedBody, templateHtml);
    assert.throws(() => generate({ templateHtml: changedBody, presentationPhp: presentationPhp.replace(generated.templateHash, sha(changedBody)) }), /CK19_SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING/);
    assert.throws(() => generate({ presentationPhp: presentationPhp.replace(generated.templateHash, '0'.repeat(64)) }), /CK19_SOURCE_TEMPLATE_PIN_MISMATCH/);
    const changed = structuredClone(mapping);
    changed.rules[0].sourceSelectors[0].selector = '.unknown-source';
    changed.rules[0].declarations[0].from.selector = '.unknown-source';
    assert.throws(() => generate({ mapping: changed }), /CK19_SOURCE_REFERENCE_AMBIGUOUS/);
});

test('C-K23 mapping cannot self-approve changed output, broaden selectors or cross the 5000-character limit', () => {
    const changed = structuredClone(mapping);
    changed.rules[0].selector = 'table.foreign';
    assert.throws(() => generate({ mapping: changed }), /CK19_APPROVED_V12_DRIFT/);
    changed.approvedPayloadSha256 = '0'.repeat(64);
    assert.throws(() => generate({ mapping: changed }), /CK19_MAPPING_INVALID/);
    for (const selector of ['[class$="rt-contact"]', 'tr:not(:last-child)']) {
        const invalid = structuredClone(mapping); invalid.rules[0].selector = selector;
        assert.throws(() => generate({ mapping: invalid }), /CK19_RULE_INVALID/);
    }
    for (const length of [743, 744]) {
        const invalid = structuredClone(mapping);
        invalid.rules[0].declarations[0] = { property: 'display', override: 'x'.repeat(length), important: true, reason: 'Exact 5000/5001-character negative fixture' };
        assert.throws(() => generate({ mapping: invalid }), /CK19_PAYLOAD_LENGTH/);
    }
});

// Selector/topology checks only. They do not simulate native CSS layout,
// dark mode, overflow or the Exchange transport's treatment of a style tag.
function browserTableStructure(html) {
    const { document } = parseHTML('<html><body>' + html + '</body></html>');
    for (const table of document.querySelectorAll('table')) {
        const rows = [...table.children].filter(node => node.tagName === 'TR');
        if (!rows.length) continue;
        const tbody = document.createElement('tbody'); table.insertBefore(tbody, rows[0]);
        for (const row of rows) tbody.appendChild(row);
    }
    return document;
}
function hiddenByOutput(document) {
    const hidden = new Set();
    outputRules.walkRules(rule => {
        if (rule.nodes.some(declaration => declaration.prop === 'display' && declaration.value === 'none')) {
            for (const node of document.querySelectorAll(rule.selector)) hidden.add(node);
        }
    });
    return node => { for (let at = node; at; at = at.parentElement) if (hidden.has(at)) return true; return false; };
}

test('historical C-K23 selectors work without width attributes or span classes in the preserved v2.3 serialization', async () => {
    const source = JSON.parse(await read('tests/Fixtures/mail/u2e-readback-v2/legacy-v23/source.json')).html;
    const document = browserTableStructure(source);
    for (const node of document.querySelectorAll('table,td')) { node.removeAttribute('width'); node.removeAttribute('bgcolor'); }
    for (const node of document.querySelectorAll('span')) node.removeAttribute('class');
    assert.equal(document.querySelectorAll('table.rt-shell').length, 1);
    assert.equal(document.querySelectorAll('td.rt-col-person,td.rt-col-direct,td.rt-col-company,td.rt-col-dispo').length, 4);
    assert.equal(document.querySelectorAll('table.rt-dispo').length, 1);
    assert.ok(document.querySelectorAll('td.rt-col-direct td td span').length > 0);
    const hidden = hiddenByOutput(document);
    const images = [...document.querySelectorAll('img')].filter(node => /(?:19125261c2|f9d88b899d|1111670ac8)\.png/.test(node.getAttribute('src')));
    assert.equal(images.length, 3);
    assert.equal(images.filter(hidden).length, 0);
    assert.equal(document.querySelectorAll('table.rt-shell td[colspan="5"]').length, 1);
    assert.ok([...document.querySelectorAll('table.rt-shell td[colspan="5"]')].every(hidden));
});

for (const name of ['word.synthetic.html', 'rooster.synthetic.html']) {
    test('C-K23 synthetic class retention is explicit rather than promising blanket desktop exclusion: ' + name, async () => {
        const html = await read('tests/Fixtures/mail/u2e-readback-v2/' + name), before = sha(html);
        const document = browserTableStructure(html);
        let matches = 0;
        outputRules.walkRules(rule => { matches += document.querySelectorAll(rule.selector).length; });
        if (name === 'word.synthetic.html') assert.equal(matches, 0);
        else assert.ok(matches > 0, 'A host retaining original RailTime classes is selected; the two transport header conditions must keep the rule paths separate.');
        assert.equal(sha(html), before);
    });
}

for (const name of ['received-word-1623.redacted.json', 'received-rooster-1625.redacted.json']) {
    test('C-K23 does not select these recorded desktop class families: ' + name, async () => {
        const fixture = JSON.parse(await read('tests/Fixtures/mail/ck19-mobile-style-v10/' + name));
        const document = browserTableStructure(fixture.html);
        let matches = 0; outputRules.walkRules(rule => { matches += document.querySelectorAll(rule.selector).length; });
        assert.equal(matches, 0);
        // This is selector evidence from historical redacted input, not native
        // acceptance of the new v12 Exchange rule or a guarantee for all hosts.
    });
}

test('C-K23 CTA-line guard does not hide a historical single action row', () => {
    const document = browserTableStructure('<table class="rt-shell"><tr><td><table class="rt-dispo"><tr><td></td></tr><tr><td></td></tr><tr><td><table><tr><td><img src="cta-1.png"></td><td><img src="cta-2.png"></td><td></td><td><img src="cta-3.png"></td><td></td></tr></table></td></tr><tr><td></td></tr></table></td></tr></table>');
    const hidden = hiddenByOutput(document);
    assert.equal([...document.querySelectorAll('img')].filter(hidden).length, 0);
});

test('C-K23 unrelated tables without RailTime classes are not selected, while same-class scope remains explicit', () => {
    const plain = browserTableStructure('<table><tr><td colspan="5"><span>Foreign content</span></td></tr></table>');
    let matches = 0; outputRules.walkRules(rule => { matches += plain.querySelectorAll(rule.selector).length; });
    assert.equal(matches, 0);
    const sameClass = browserTableStructure('<table><tr><td class="rt-col-direct">Same-class content</td></tr></table>');
    assert.equal(sameClass.querySelectorAll(mapping.rules[4].selector).length, 1);
    assert.equal(sameClass.querySelectorAll('table.rt-shell').length, 0);
    assert.match(mapping.policy.scope, /not rooted under table\.rt-shell/);
    assert.match(mapping.policy.scope, /unrelated-content compatibility is not asserted/);
});
