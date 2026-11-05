import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { generateCk19MobileStyle, buildCk19MobileStyles } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const currentTemplate = 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf';
const previousTemplate = '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204';
const currentCss = 'cf15bb89e9425c30f3db73b20b5263763e5c2b0d3d32d9a4e67625a5c2ed0006';
const previousCss = '502931bbbfc2452dcfea30bb451782f75b9ec9e0059fe1f7477b7ae9044cfdc1';
const line = '<tr class="rt-cta-line"><td colspan="5" height="1" bgcolor="#ffffff" style="height:1px;line-height:1px;font-size:1px;mso-line-height-rule:exactly;padding:0;background-color:#ffffff;">&nbsp;</td></tr>';

test('the v2.5 source is pinned and v2.3 is retained as immutable historical test input', async () => {
    assert.equal(sha(templateHtml), currentTemplate); assert.equal(templateHtml.length, 15604);
    assert.doesNotMatch(templateHtml, /rt-cta-line|colspan="5"/);
    const prior = await read('tests/Fixtures/mail/ck19-mobile-style-v16/template-v23-approved.html');
    assert.equal(sha(prior), previousTemplate); assert.match(prior, /rt-cta-line/);
});

for (const [tag, expected] of [['v11', 3], ['v12', 1], ['v13', 1], ['v14', 3], ['v15', 1]]) {
    const mapping = JSON.parse(await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json'));
    const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
    test(tag + ' retains the exact prior payload with finite historical line provenance', async () => {
        const generated = generate();
        assert.equal(generated.templateHash, currentTemplate);
        assert.equal(generated.cssHash, currentCss);
        assert.equal(generated.payload, await read('resources/mail-templates/generated/ck19-mobile-style-' + tag + '.html'));
        const retained = mapping.rules.flatMap(rule => rule.declarations).filter(declaration => declaration.historicalSource && declaration.property === 'display');
        assert.equal(retained.length, expected);
        assert.equal(generated.historicalLineOverrides, expected);
        for (const declaration of retained) {
            assert.equal(declaration.from, undefined);
            assert.equal(declaration.property, 'display'); assert.equal(declaration.override, 'none');
            assert.equal(declaration.important, true);
            assert.deepEqual(declaration.historicalSource, {
                approval: 'C-K25-user-approved-retain-existing-v11-v15-payloads',
                templateSha256: previousTemplate, cssSha256: previousCss,
                media: 'only screen and (max-width:600px)', selector: '.rt-cta-line', property: 'display', value: 'none',
            });
        }
        for (const change of ['remove', 'value', 'template', 'extra', 'derive']) {
            const altered = structuredClone(mapping);
            const declaration = altered.rules.flatMap(rule => rule.declarations).find(declaration => declaration.historicalSource && declaration.property === 'display');
            if (change === 'remove') delete declaration.historicalSource;
            if (change === 'value') declaration.override = 'block';
            if (change === 'template') declaration.historicalSource.templateSha256 = currentTemplate;
            if (change === 'extra') declaration.historicalSource.unreviewed = true;
            if (change === 'derive') declaration.from = { media: 'only screen and (max-width:600px)', selector: '.rt-cta-line', property: 'display' };
            assert.throws(() => generate({ mapping: altered }), /CK19_HISTORICAL_OVERRIDE_PROVENANCE_INVALID/, change);
        }
    });
}

test('all five checked-in manifests bind current v2.5 while original source-file fixtures remain historical', async () => {
    const all = await buildCk19MobileStyles({ check: true, releases: ['v11', 'v12', 'v13', 'v14', 'v15'] });
    for (const manifest of all) {
        assert.equal(manifest.sourceTemplateSha256, currentTemplate);
        assert.equal(manifest.sourceCssSha256, currentCss);
        assert.equal(manifest.historicalLineSource.templateSha256, previousTemplate);
        assert.equal(manifest.historicalLineSource.cssSha256, previousCss);
        assert.equal(manifest.nativeClientProof, false);
    }
});
