import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import postcss from 'postcss';
import { generateCk19MobileStyle, ck19Manifest, buildCk19MobileStyles, buildCk19MobileStyle,
    ck19V16RuleTextFromSourceFile, ck19V17RuleTextFromSourceFile } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const generatorBytes = await read('scripts/build-ck19-mobile-style.mjs');
const currentPin = '1bfd6e33fbc15a5e278ce29cf039721cedd9b4f0a2a5506c4012df3d5483207d';
const currentCss = '44320e37dbd5376595ecbd11938fcc96b5be372088dea343fed9a187d18f746f';

for (const [tag, prior, size, digest, fromFile, derived, overrides, lineCount] of [
    ['v16', 'v14', 4948, 'c5532f4ac2805996973d7c001fbcadaf34ab988b752f4a23bd368635b6801b62', ck19V16RuleTextFromSourceFile, 87, 25, 3],
    ['v17', 'v15', 3801, '36bfdadfced47555ea12e092902652db5efa6f91a04b7cd704f80cf82a145d24', ck19V17RuleTextFromSourceFile, 80, 23, 1],
]) {
    const mappingBytes = await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json');
    const mapping = JSON.parse(mappingBytes);
    const original = JSON.parse(await read('tests/Fixtures/mail/ck19-mobile-style-' + tag + '/approved-source-file.json'));
    const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
    const actual = generate();

    test(tag + ' reproduces the exact approved file omitting only its one final LF', () => {
        assert.equal(actual.payload, fromFile(original.sourceFileHtml));
        assert.equal(original.sourceFileHtml, actual.payload + '\n');
        assert.equal(sha(original.sourceFileHtml), original.sourceFileSha256);
        assert.equal(actual.payload.length, size); assert.equal(Buffer.byteLength(actual.payload), size);
        assert.equal(sha(actual.payload), digest);
        assert.equal(actual.templateHash, currentPin); assert.equal(actual.cssHash, currentCss);
        assert.equal(original.sourceTemplateSha256, 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf'); assert.equal(original.sourceCssSha256, 'cf15bb89e9425c30f3db73b20b5263763e5c2b0d3d32d9a4e67625a5c2ed0006');
        assert.equal(actual.derivedDeclarations, derived); assert.equal(actual.transportOverrides, overrides);
        assert.equal(actual.historicalPaddingOverrides, 1); assert.equal(actual.historicalLineOverrides, lineCount);
        assert.equal(original.nativeClientProof, false);
        for (const changed of [original.sourceFileHtml + '\n', actual.payload, actual.payload + '\r\n', original.sourceFileHtml.replace('#e4002b', '#e4002c')]) {
            assert.throws(() => fromFile(changed), new RegExp('CK19_' + tag.toUpperCase() + '_SOURCE_FILE_INVALID'));
        }
    });

    test(tag + ' differs from its predecessor only by the three agreed padding values', async () => {
        let expected = await read('resources/mail-templates/generated/ck19-mobile-style-' + prior + '.html');
        for (const [before, after] of [['padding:11px 0 11px 20px!important', 'padding:8px 0 8px 20px!important'],
            ['padding:4px 20px 4px 0!important', 'padding:2px 20px 2px 0!important'],
            ['padding:5px 0 5px 12px!important', 'padding:4px 0 4px 12px!important']]) {
            assert.equal(expected.split(before).length - 1, 1); expected = expected.replace(before, after);
        }
        assert.equal(actual.payload, expected); assert.equal(original.derivedFrom, prior);
    });

    test(tag + ' provenance is deterministic and admission stays strict', () => {
        const manifest = ck19Manifest({ generated: actual, mappingBytes, generatorBytes });
        assert.deepEqual(manifest, ck19Manifest({ generated: generate(), mappingBytes, generatorBytes }));
        assert.equal(manifest.mappingSha256, sha(mappingBytes)); assert.equal(manifest.generatorSha256, sha(generatorBytes));
        assert.equal(manifest.payloadSha256, digest); assert.equal(manifest.nativeClientProof, false);
        assert.equal(manifest.exchangeRuleUpdatedByBuild, false);
        assert.match(manifest.scope, /unrelated-content compatibility is not asserted/);
        const changed = templateHtml.replace('width="247"', 'width="246"');
        assert.throws(() => generate({ templateHtml: changed, presentationPhp: presentationPhp.replace(currentPin, sha(changed)) }), /CK19_SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING/);
        assert.throws(() => generate({ templateHtml: templateHtml.replace('width:52%', 'width:51%') }), /CK19_SOURCE_CSS_CHANGED_REVIEW_MAPPING/);
        assert.throws(() => generate({ presentationPhp: presentationPhp.replace(currentPin, '0'.repeat(64)) }), /CK19_SOURCE_TEMPLATE_PIN_MISMATCH/);
        const altered = structuredClone(mapping); altered.rules[0].selector = 'table.foreign';
        assert.throws(() => generate({ mapping: altered }), new RegExp('CK19_APPROVED_' + tag.toUpperCase() + '_DRIFT'));
    });
}

test('active defaults select v18/v19 while all seven historical artifacts stay explicitly verifiable', async () => {
    const active = await buildCk19MobileStyles({ check: true });
    assert.deepEqual(active.map(x => x.revision), ['ck19-approved-v18-20261107', 'ck19-approved-v19-20261107']);
    assert.equal((await buildCk19MobileStyle({ check: true })).kind, 'ck19-approved-v18-generated-mobile-style');
    const all = await buildCk19MobileStyles({ check: true, releases: ['v11', 'v12', 'v13', 'v14', 'v15', 'v16', 'v17'] });
    assert.equal(all.length, 7);
});

test('every historical declaration is proved against its exact real v2.3 or v2.5 source CSS', async () => {
    const oldTemplate = await read('tests/Fixtures/mail/ck19-mobile-style-v16/template-v23-approved.html');
    assert.equal(sha(oldTemplate), '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204');
    const css = oldTemplate.match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1];
    assert.equal(sha(css), '502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1');
    const tree = postcss.parse(css);
    const previousV25 = templateHtml.replace('class="rt-shell" align="left"', 'class="rt-shell" align="center"').replace('margin:0 auto 0 0;float:none;text-align:left;', 'margin:0 auto;').replace('padding:32px 40px 48px', 'padding:32px 40px 30px').replace('.rt-text { padding:24px 20px 32px', '.rt-text { padding:24px 20px 24px');
    assert.equal(sha(previousV25), 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf');
    const priorCss = previousV25.match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1];
    assert.equal(sha(priorCss), 'cf15bb89e9425c30f3db73b20b5263763e5c2b0d3d32d9a4e67625a5c2ed0006');
    const priorV25Tree = postcss.parse(priorCss);
    for (const tag of ['v11', 'v12', 'v13', 'v14', 'v15', 'v16', 'v17']) {
        const mapping = JSON.parse(await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json'));
        for (const declaration of mapping.rules.flatMap(rule => rule.declarations).filter(x => x.historicalSource)) {
            const historical = declaration.historicalSource, found = [];
            const historicalTree = historical.templateSha256 === 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf' ? priorV25Tree : tree;
            historicalTree.walkRules(rule => {
                if (rule.parent.type === 'atrule' && rule.parent.name === 'media'
                    && rule.parent.params === historical.media && rule.selector === historical.selector) {
                    rule.walkDecls(historical.property, value => found.push(value.value));
                }
            });
            assert.deepEqual(found, [declaration.override]);
            assert.equal(historical.value, declaration.override);
        }
    }
});

for (const [tag, count] of [['v11', 4], ['v12', 4], ['v13', 4], ['v14', 3], ['v15', 3]]) {
    const mapping = JSON.parse(await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json'));
    const generate = altered => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping: altered });
    test(tag + ' allows only the finite real v2.3 padding provenance, never fabricated current-source claims', () => {
        assert.equal(generate(mapping).historicalPaddingOverrides, count);
        for (const kind of ['removed', 'fake-pin', 'fake-value', 'extra-field', 'duplicate']) {
            const altered = structuredClone(mapping);
            const declaration = altered.rules.flatMap(rule => rule.declarations).find(x => x.historicalSource && x.property === 'padding' && x.historicalSource.selector !== '.rt-text');
            assert.equal(declaration.historicalSource.templateSha256, '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204');
            assert.equal(declaration.historicalSource.cssSha256, '502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1');
            if (kind === 'removed') delete declaration.historicalSource;
            if (kind === 'fake-pin') declaration.historicalSource.templateSha256 = 'e71922554f82861cab15ddf4cccd30717deb48a42034990ce675535367848bcd';
            if (kind === 'fake-value') declaration.override = '8px 0 8px 20px';
            if (kind === 'extra-field') declaration.historicalSource.unreviewed = true;
            if (kind === 'duplicate') altered.rules[0].declarations.push(structuredClone(declaration));
            assert.throws(() => generate(altered), /CK19_HISTORICAL_OVERRIDE_PROVENANCE_INVALID/, kind);
        }
    });
}
