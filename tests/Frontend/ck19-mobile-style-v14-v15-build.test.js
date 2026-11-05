import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import postcss from 'postcss';
import { parseHTML } from 'linkedom';
import { generateCk19MobileStyle, ck19Manifest, buildCk19MobileStyles,
    ck19V14RuleTextFromSourceFile, ck19V15RuleTextFromSourceFile,
    CK19_APPROVED_V11_SHA256, CK19_APPROVED_V12_SHA256, CK19_APPROVED_V13_SHA256,
    CK19_APPROVED_V14_SHA256, CK19_APPROVED_V15_SHA256,
    CK19_V14_SOURCE_FILE_SHA256, CK19_V15_SOURCE_FILE_SHA256 } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const generatorBytes = await read('scripts/build-ck19-mobile-style.mjs');
const cases = await Promise.all([
    ['v14', 4950, CK19_APPROVED_V14_SHA256, CK19_V14_SOURCE_FILE_SHA256, ck19V14RuleTextFromSourceFile, 85, 27],
    ['v15', 3803, CK19_APPROVED_V15_SHA256, CK19_V15_SOURCE_FILE_SHA256, ck19V15RuleTextFromSourceFile, 78, 25],
].map(async ([tag, size, digest, sourceDigest, fromSource, derived, overrides]) => {
    const mappingBytes = await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json');
    const mapping = JSON.parse(mappingBytes);
    const supplied = JSON.parse(await read('tests/Fixtures/mail/ck19-mobile-style-' + tag + '/approved-source-file.json'));
    const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
    return { tag, size, digest, sourceDigest, fromSource, derived, overrides, mappingBytes, mapping, supplied, generate, generated: generate() };
}));

for (const current of cases) {
    test(current.tag + ' exactly reproduces its original file excluding only one final LF', () => {
        const { supplied, generated, fromSource, size, digest, sourceDigest } = current;
        assert.equal(supplied.sourceFileHtml.length, size + 1);
        assert.equal(sha(supplied.sourceFileHtml), sourceDigest);
        assert.equal(fromSource(supplied.sourceFileHtml), generated.payload);
        assert.equal(supplied.sourceFileHtml, generated.payload + '\n');
        assert.equal(generated.payload.length, size); assert.equal(Buffer.byteLength(generated.payload), size);
        assert.equal(sha(generated.payload), digest);
        assert.equal(supplied.sourceTemplateSha256, '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204', 'Original approval metadata stays historical.');
        assert.equal(generated.templateHash, 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf');
        assert.equal(generated.cssHash, 'cf15bb89e9425c30f3db73b20b5263763e5c2b0d3d32d9a4e67625a5c2ed0006');
        assert.equal(supplied.sourceCssSha256, '502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1');
        assert.equal(generated.derivedDeclarations, current.derived);
        assert.equal(generated.transportOverrides, current.overrides);
        assert.equal(supplied.nativeClientProof, false);
        for (const changed of [supplied.sourceFileHtml + '\n', generated.payload, generated.payload + '\r\n', supplied.sourceFileHtml.replace('#e4002b', '#e4002c')]) {
            assert.throws(() => fromSource(changed), new RegExp('CK19_' + current.tag.toUpperCase() + '_SOURCE_FILE_INVALID'));
        }
    });

    test(current.tag + ' manifest deterministically binds source, generator, mapping and output', () => {
        const first = ck19Manifest({ generated: current.generated, mappingBytes: current.mappingBytes, generatorBytes });
        assert.deepEqual(first, ck19Manifest({ generated: current.generate(), mappingBytes: current.mappingBytes, generatorBytes }));
        assert.equal(first.mappingSha256, sha(current.mappingBytes)); assert.equal(first.generatorSha256, sha(generatorBytes));
        assert.equal(first.sourceTemplateSha256, sha(templateHtml)); assert.equal(first.payloadSha256, current.digest);
        assert.equal(first.nativeClientProof, false); assert.equal(first.exchangeRuleUpdatedByBuild, false);
        assert.match(first.scope, /unrelated-content compatibility is not asserted/);
        const invalid = structuredClone(current.mapping); invalid.sourceFileNormalization.operation = 'trim';
        assert.throws(() => current.generate({ mapping: invalid }), new RegExp('CK19_' + current.tag.toUpperCase() + '_NORMALIZATION_CONTRACT_INVALID'));
    });
}

function replaceExactly(html, needle, replacement, count) {
    assert.equal(html.split(needle).length - 1, count, needle);
    return html.replaceAll(needle, replacement);
}

test('v14 differs from v11 only by the approved head padding, Word paragraph rule and redundant padding removal', async () => {
    let expected = await read('resources/mail-templates/generated/ck19-mobile-style-v11.html');
    expected = replaceExactly(expected, 'padding-left:24px!important;padding-right:24px!important;', '', 2);
    expected = replaceExactly(expected, '[width="247"]>tbody>tr:nth-child(2) td td{padding:0!important;',
        '[width="247"]>tbody>tr:nth-child(2) td td,[width="247"]>tbody>tr:nth-child(2) td td p{margin:0!important;padding:0!important;', 1);
    expected = replaceExactly(expected, 'padding:9px 0 9px 20px!important', 'padding:11px 0 11px 20px!important', 1);
    assert.equal(cases[0].generated.payload, expected);
    assert.equal(cases[0].supplied.derivedFrom, 'v11');
});

test('v15 differs from v13 only by the approved head padding', async () => {
    const previous = await read('resources/mail-templates/generated/ck19-mobile-style-v13.html');
    assert.equal(cases[1].generated.payload, replaceExactly(previous, 'padding:9px 0 9px 20px!important', 'padding:11px 0 11px 20px!important', 1));
    assert.equal(cases[1].supplied.derivedFrom, 'v13');
});

test('all historical releases remain explicitly checkable while the default selects two active releases', async () => {
    const all = await buildCk19MobileStyles({ check: true, releases: ['v11', 'v12', 'v13', 'v14', 'v15'] });
    assert.deepEqual(all.map(x => x.payloadSha256), [CK19_APPROVED_V11_SHA256, CK19_APPROVED_V12_SHA256, CK19_APPROVED_V13_SHA256, CK19_APPROVED_V14_SHA256, CK19_APPROVED_V15_SHA256]);
    assert.equal((await buildCk19MobileStyles({ check: true })).length, 2);
});

test('v11, v12 and v13 payloads retain their exact literal bytes', async () => {
    for (const [tag, size, digest] of [['v11', 4999, CK19_APPROVED_V11_SHA256], ['v12', 4262, CK19_APPROVED_V12_SHA256], ['v13', 3801, CK19_APPROVED_V13_SHA256]]) {
        const payload = await fs.readFile(new URL('resources/mail-templates/generated/ck19-mobile-style-' + tag + '.html', root));
        assert.equal(payload.length, size); assert.equal(sha(payload), digest);
    }
});

test('both new releases retain strict template, PHP and CSS source admission', () => {
    for (const current of cases) {
        assert.throws(() => current.generate({ templateHtml: templateHtml.replace('width:52%', 'width:51%') }), /CK19_SOURCE_CSS_CHANGED_REVIEW_MAPPING/);
        const changed = templateHtml.replace('width="247"', 'width="246"');
        assert.throws(() => current.generate({ templateHtml: changed, presentationPhp: presentationPhp.replace(current.generated.templateHash, sha(changed)) }), /CK19_SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING/);
        assert.throws(() => current.generate({ presentationPhp: presentationPhp.replace(current.generated.templateHash, '0'.repeat(64)) }), /CK19_SOURCE_TEMPLATE_PIN_MISMATCH/);
    }
});

test('mapping edits cannot self-approve other output or unsupported selectors', () => {
    for (const current of cases) {
        const changed = structuredClone(current.mapping); changed.rules[0].selector = 'table.foreign';
        assert.throws(() => current.generate({ mapping: changed }), new RegExp('CK19_APPROVED_' + current.tag.toUpperCase() + '_DRIFT'));
        changed.approvedPayloadSha256 = '0'.repeat(64);
        assert.throws(() => current.generate({ mapping: changed }), /CK19_MAPPING_INVALID/);
        for (const selector of ['[class$="rt-contact"]', 'tr:not(:last-child)']) {
            const invalid = structuredClone(current.mapping); invalid.rules[0].selector = selector;
            assert.throws(() => current.generate({ mapping: invalid }), /CK19_RULE_INVALID/);
        }
    }
});

test('both new releases fail before producing payloads of 5000 or 5001 characters', () => {
    for (const current of cases) for (const size of [5000, 5001]) {
        const invalid = structuredClone(current.mapping);
        invalid.rules[0].declarations[0] = { property: 'display', override: 'x'.repeat(size - current.size + 5), important: true, reason: 'Exact maximum-length negative fixture' };
        assert.throws(() => current.generate({ mapping: invalid }), /CK19_PAYLOAD_LENGTH/);
    }
});

const parseRules = html => postcss.parse(html.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]).nodes[0].nodes;
test('v14 explicitly selects recorded Word head paragraphs for margin/padding/line-height reset', async () => {
    const fixture = JSON.parse(await read('tests/Fixtures/mail/ck19-mobile-style-v10/received-word-1623.redacted.json'));
    const { document } = parseHTML('<html><body>' + fixture.html + '</body></html>');
    for (const table of document.querySelectorAll('table')) {
        const rows = [...table.children].filter(node => node.tagName === 'TR');
        if (rows.length) { const tbody = document.createElement('tbody'); table.insertBefore(tbody, rows[0]); for (const row of rows) tbody.appendChild(row); }
    }
    const paragraphRule = parseRules(cases[0].generated.payload).find(rule => rule.selector.includes('td td p'));
    const paragraphs = document.querySelectorAll('[width="247"]>tbody>tr:nth-child(2) td td p');
    assert.ok(paragraphs.length > 0);
    const targets = new Set(document.querySelectorAll(paragraphRule.selector));
    assert.ok([...paragraphs].every(node => targets.has(node)));
    for (const [property, value] of [['margin', '0'], ['padding', '0'], ['line-height', '20px']]) {
        assert.ok(paragraphRule.nodes.some(node => node.prop === property && node.value === value && node.important));
    }
});

test('new releases keep their inherited media and all display:none guards unchanged', async () => {
    for (const [current, prior] of [[cases[0], 'v11'], [cases[1], 'v13']]) {
        const old = await read('resources/mail-templates/generated/ck19-mobile-style-' + prior + '.html');
        const hidden = html => parseRules(html).filter(rule => rule.nodes.some(node => node.prop === 'display' && node.value === 'none')).map(rule => rule.toString());
        assert.deepEqual(hidden(current.generated.payload), hidden(old));
        assert.equal(postcss.parse(current.generated.payload.match(/<style[^>]*>([\s\S]*)<\/style>/)[1]).nodes[0].params, 'only screen and (max-width:1039px)');
    }
});
