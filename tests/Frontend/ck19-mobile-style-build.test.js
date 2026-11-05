import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { parseHTML } from 'linkedom';
import postcss from 'postcss';
import { generateCk19MobileStyle, ck19Manifest, buildCk19MobileStyle,
    ck19V11RuleTextFromSourceFile, CK19_V11_SOURCE_FILE_SHA256,
    CK19_APPROVED_V10_SHA256, CK19_APPROVED_V10_CHARACTERS,
    CK19_APPROVED_V11_SHA256, CK19_APPROVED_V11_CHARACTERS } from '../../scripts/build-ck19-mobile-style.mjs';
import { validateUniversalRedactedFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const mappingBytes = await read('resources/mail-templates/ck19-mobile-style-v11.mapping.json');
const generatorBytes = await read('scripts/build-ck19-mobile-style.mjs');
const mapping = JSON.parse(mappingBytes);
const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
const sha = value => createHash('sha256').update(value).digest('hex');
const clone = () => structuredClone(mapping);
const generated = generate();

test('the actual template CSS and versioned mapping reproduce the exact approved v11 rule text', () => {
    assert.equal(generated.payload.length, CK19_APPROVED_V11_CHARACTERS);
    assert.equal(generated.payload.length, 4999);
    assert.equal(sha(generated.payload), CK19_APPROVED_V11_SHA256);
    assert.equal(generated.derivedDeclarations, 95);
    assert.equal(generated.transportOverrides, 20);
    assert.equal(mapping.rules.length, 42);
    assert.doesNotMatch(generated.payload, /\[class\s*\$\s*=|:not\s*\(|<meta/i);
    assert.equal(postcss.parse(generated.payload.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]).nodes[0].name, 'media');
});

test('manifest pins the actual template constant, CSS, generator, mapping and payload deterministically', () => {
    const first = ck19Manifest({ generated, mappingBytes, generatorBytes });
    const second = ck19Manifest({ generated: generate(), mappingBytes, generatorBytes });
    assert.deepEqual(first, second);
    assert.equal(first.sourceTemplateSha256, sha(templateHtml));
    assert.equal(first.sourceCssSha256, mapping.sourceCssSha256);
    assert.equal(first.mappingSha256, sha(mappingBytes));
    assert.equal(first.generatorSha256, sha(generatorBytes));
    assert.equal(first.payloadSha256, CK19_APPROVED_V11_SHA256);
    assert.deepEqual(first.sourceFileNormalization, mapping.sourceFileNormalization);
    assert.equal(first.nativeClientProof, false);
    assert.equal(first.exchangeRuleUpdatedByBuild, false);
});

test('checked-in generated files match deterministic generation, including the current template version', async () => {
    const result = await buildCk19MobileStyle({ check: true });
    assert.equal(result.sourceTemplateSha256, generated.templateHash);
});

test('a changed source CSS or a stale TEMPLATE_SHA256 stops generation', () => {
    assert.throws(() => generate({ templateHtml: templateHtml.replace('width:52%', 'width:51%') }), /CK19_SOURCE_CSS_CHANGED_REVIEW_MAPPING/);
    assert.throws(() => generate({ presentationPhp: presentationPhp.replace(generated.templateHash, '0'.repeat(64)) }), /CK19_SOURCE_TEMPLATE_PIN_MISMATCH/);
});

test('changing the HTML body and PHP pin together still needs explicit template mapping approval', () => {
    assert.equal(mapping.approvedTemplateSha256, 'f297c0bb222061eb3b78c85889e139274067d5744707d7807d5b6e8545e676b2');
    const changed = templateHtml.replace('width="247"', 'width="246"');
    assert.notEqual(changed, templateHtml);
    const changedPhp = presentationPhp.replace(generated.templateHash, sha(changed));
    assert.throws(() => generate({ templateHtml: changed, presentationPhp: changedPhp }), /CK19_SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING/);
});

test('missing or ambiguous mapped source declarations stop generation rather than becoming overrides', () => {
    const changed = clone();
    changed.rules[0].sourceSelectors[0].selector = '.unknown-source';
    changed.rules[0].declarations[0].from.selector = '.unknown-source';
    assert.throws(() => generate({ mapping: changed }), /CK19_SOURCE_REFERENCE_AMBIGUOUS/);
});

test('mapping edits cannot self-approve another v11 output or restore the hidden legacy action row', () => {
    const changed = clone();
    changed.rules[28].selector = '[width="247"]>tbody>tr:nth-child(3) table>tbody>tr:first-child';
    assert.throws(() => generate({ mapping: changed }), /CK19_APPROVED_V11_DRIFT/);
    changed.approvedPayloadSha256 = '0'.repeat(64);
    assert.throws(() => generate({ mapping: changed }), /CK19_MAPPING_INVALID/);
});

test('forbidden selectors and payloads at or over 5000 characters fail before any artifact is written', () => {
    for (const selector of ['[class$="rt-contact"]', 'tr:not(:last-child)']) {
        const changed = clone(); changed.rules[0].selector = selector;
        assert.throws(() => generate({ mapping: changed }), /CK19_RULE_INVALID/);
    }
    for (const length of [6, 7]) {
        const changed = clone();
        // Replacing five-byte "block" adds one/two bytes: exactly5000/5001.
        changed.rules[0].declarations[0] = { property: 'display', override: 'x'.repeat(length), important: true, reason: 'Length rejection fixture' };
        assert.throws(() => generate({ mapping: changed }), /CK19_PAYLOAD_LENGTH/);
    }
});

const suppliedV11 = JSON.parse(await read('tests/Fixtures/mail/ck19-mobile-style-v11/approved-source-file.json'));
test('the 5000-byte source file is retained and only its explicitly excluded final LF is omitted', () => {
    const raw = suppliedV11.sourceFileHtml;
    assert.equal(raw.length, 5000);
    assert.equal(sha(raw), CK19_V11_SOURCE_FILE_SHA256);
    assert.equal(raw.endsWith('\n'), true);
    assert.equal(raw.endsWith('\n\n'), false);
    assert.equal(ck19V11RuleTextFromSourceFile(raw), generated.payload);
    assert.equal(raw.slice(0, -1), generated.payload);
    for (const changed of [raw + '\n', raw.slice(0, -1), raw.replace(/\n$/, '\r\n'), raw.replace('#e4002b', '#e4002c')]) {
        assert.throws(() => ck19V11RuleTextFromSourceFile(changed), /CK19_V11_SOURCE_FILE_INVALID/);
    }
    const changed = clone(); changed.payloadSuffix = '</style>\n';
    assert.throws(() => generate({ mapping: changed }), /CK19_MAPPING_INVALID/);
});

test('v10 mapping and generated history remain unchanged and are still reproducible', async () => {
    for (const [name, digest] of Object.entries(suppliedV11.v10HistoryNormalizedSha256)) assert.equal(sha(await read(name)), digest);
    const historyMapping = JSON.parse(await read('resources/mail-templates/ck19-mobile-style-v10.mapping.json'));
    const history = generate({ mapping: historyMapping });
    assert.equal(history.payload, await read('resources/mail-templates/generated/ck19-mobile-style-v10.html'));
    assert.equal(history.payload.length, CK19_APPROVED_V10_CHARACTERS);
    assert.equal(sha(history.payload), CK19_APPROVED_V10_SHA256);
});

test('the only v10-to-v11 CSS changes are panel background shorthand and zero radius inside1039px', async () => {
    const old = await read('resources/mail-templates/generated/ck19-mobile-style-v10.html');
    const expected = old.replace('[width="247"]{display:block!important;text-align:left!important;width:100%!important;max-width:100%!important;overflow:hidden!important;background-color:#e4002b!important}',
        '[width="247"]{display:block!important;text-align:left!important;width:100%!important;max-width:100%!important;overflow:hidden!important;background:#e4002b!important;border-radius:0!important}').slice(0, -1);
    assert.notEqual(expected, old.slice(0, -1));
    assert.equal(expected, generated.payload);
    const ast = postcss.parse(generated.payload.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]);
    assert.equal(ast.nodes.length, 1);
    assert.equal(ast.nodes[0].params, 'only screen and (max-width:1039px)');
    assert.equal(suppliedV11.actualMedia, ast.nodes[0].params);
    const panels = []; ast.walkRules(rule => { if (rule.selector === '[width="247"]') panels.push(rule); });
    assert.equal(panels.length, 1);
    assert.deepEqual(panels[0].nodes.filter(node => ['background', 'background-color', 'border-radius'].includes(node.prop)).map(node => [node.prop, node.value, node.important]),
        [['background', '#e4002b', true], ['border-radius', '0', true]]);
});

// Linkedom deliberately does not insert implicit TBODY. Normalize only direct
// contiguous TR children, reproducing the browser structure for selector tests.
function browserTableStructure(html) {
    const { document } = parseHTML('<html><body>' + html + '</body></html>');
    for (const table of document.querySelectorAll('table')) {
        const direct = [...table.children].filter(node => node.tagName === 'TR');
        if (!direct.length) continue;
        const tbody = document.createElement('tbody');
        table.insertBefore(tbody, direct[0]);
        for (const row of direct) tbody.appendChild(row);
    }
    return document;
}
const outputRules = postcss.parse(generated.payload.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]);
function displayHidden(document) {
    const hidden = new Set();
    outputRules.walkRules(rule => {
        // This tests display topology, not native layout, clipping or cascade
        // of unrelated message styles. Native visual acceptance remains separate.
        if (rule.nodes.some(declaration => declaration.type === 'decl' && declaration.prop === 'display' && declaration.value === 'none')) {
            for (const node of document.querySelectorAll(rule.selector)) hidden.add(node);
        }
    });
    return node => { for (let at = node; at; at = at.parentElement) if (hidden.has(at)) return true; return false; };
}
function assertCtaVisibility(html, expected) {
    const document = browserTableStructure(html), hidden = displayHidden(document);
    const images = [...document.querySelectorAll('img')].filter(node => /(?:19125261c2|f9d88b899d|1111670ac8)\.png/.test(node.getAttribute('src')));
    assert.equal(images.length, expected);
    assert.equal(images.filter(hidden).length, 0);
    return { document, hidden };
}

for (const name of ['word.synthetic.html', 'rooster.synthetic.html']) {
    test('current synthetic host retains all CTA images under generated v11: ' + name, async () => {
        const html = await read('tests/Fixtures/mail/u2e-readback-v2/' + name);
        const before = sha(html);
        assertCtaVisibility(html, 3);
        assert.equal(sha(html), before);
    });
}

const nativeFolder = 'tests/Fixtures/mail/ck19-mobile-style-v10/';
const nativeManifest = JSON.parse(await read(nativeFolder + 'manifest.json'));
for (const name of Object.keys(nativeManifest.entries)) {
    test('strictly redacted native host structure retains CTA images: ' + name, async () => {
        const bytes = await read(nativeFolder + name);
        assert.equal(sha(bytes), nativeManifest.entries[name].sha256);
        const fixture = JSON.parse(bytes);
        assert.ok(validateUniversalRedactedFixture(fixture));
        assertCtaVisibility(fixture.html, 3);
        assert.doesNotMatch(bytes, /it-technik@|rail-time\.de|Zacharias|Borsteler|Winsen/i);
        assert.equal(nativeManifest.nativeV10Proof, false);
    });
}

test('the historical single CTA row remains visible in an old quote alongside current material', async () => {
    const current = await read('tests/Fixtures/mail/u2e-readback-v2/word.synthetic.html');
    const old = JSON.parse(await read('tests/Fixtures/mail/u2e-cta-border-0746/received-0746.redacted.json')).html;
    const input = current + '<blockquote>' + old + '</blockquote>';
    const before = sha(input);
    assertCtaVisibility(input, 6);
    assert.equal(sha(input), before);
});

test('known global foreign colspan=5 scope remains explicit rather than being declared safe', () => {
    const document = browserTableStructure('<table><tr><td colspan="5"><a href="https://foreign.invalid/">Foreign content</a></td></tr></table>');
    assert.equal(displayHidden(document)(document.querySelector('td')), true);
    assert.match(mapping.policy.scope, /unrelated-content compatibility/);
});
