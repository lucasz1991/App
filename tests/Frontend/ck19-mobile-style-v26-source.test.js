import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { generateCk19MobileStyle } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const currentPin = '1bfd6e33fbc15a5e278ce29cf039721cedd9b4f0a2a5506c4012df3d5483207d';
const currentCss = '44320e37dbd5376595ecbd11938fcc96b5be372088dea343fed9a187d18f746f';
const v25Pin = 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf';
const v25Css = 'cf15bb89e9425c30f3db73b20b5263763e5c2b0d3d32d9a4e67625a5c2ed0006';

test('v2.6 changes exactly the desktop and source-mobile text-cell spacing from v2.5', () => {
    assert.equal(sha(templateHtml), currentPin);
    assert.equal(templateHtml.length, 15633);
    for (const value of ['padding:32px 40px 48px;', '.rt-text { padding:24px 20px 32px !important; }']) {
        assert.equal(templateHtml.split(value).length - 1, 1);
    }
    const prior = templateHtml.replace('class="rt-shell" align="left"', 'class="rt-shell" align="center"').replace('margin:0 auto 0 0;float:none;text-align:left;', 'margin:0 auto;').replace('padding:32px 40px 48px;', 'padding:32px 40px 30px;')
        .replace('.rt-text { padding:24px 20px 32px !important; }', '.rt-text { padding:24px 20px 24px !important; }');
    assert.equal(sha(prior), v25Pin);
    assert.equal(sha(prior.match(/<style type="text\/css">([\s\S]*?)<\/style>/)[1]), v25Css);
});

for (const [tag, count] of [['v11', 4], ['v12', 4], ['v13', 4], ['v14', 3], ['v15', 3], ['v16', 1], ['v17', 1]]) {
    const mapping = JSON.parse(await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json'));
    const generate = value => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping: value });
    test(tag + ' retains its 24px transport spacing with explicit real v2.5 provenance while the source uses 32px', async () => {
        const actual = generate(mapping);
        assert.equal(actual.templateHash, currentPin); assert.equal(actual.cssHash, currentCss);
        assert.equal(actual.historicalPaddingOverrides, count);
        assert.equal(actual.payload, await read('resources/mail-templates/generated/ck19-mobile-style-' + tag + '.html'));
        const declarations = mapping.rules.flatMap(rule => rule.declarations).filter(value => value.historicalSource?.selector === '.rt-text');
        assert.equal(declarations.length, 1);
        const declaration = declarations[0];
        assert.equal(declaration.from, undefined);
        assert.equal(declaration.override, '24px 20px 24px');
        assert.deepEqual(declaration.historicalSource, {
            approval: 'C-K27-user-approved-retain-existing-v11-v17-payloads',
            templateSha256: v25Pin, cssSha256: v25Css, media: 'only screen and (max-width:600px)',
            selector: '.rt-text', property: 'padding', value: '24px 20px 24px',
        });
        for (const kind of ['absent', 'source32', 'current-pin', 'fake-css', 'extra', 'duplicate']) {
            const modified = structuredClone(mapping);
            const cell = modified.rules.flatMap(rule => rule.declarations).find(value => value.historicalSource?.selector === '.rt-text');
            if (kind === 'absent') delete cell.historicalSource;
            if (kind === 'source32') cell.override = '24px 20px 32px';
            if (kind === 'current-pin') cell.historicalSource.templateSha256 = currentPin;
            if (kind === 'fake-css') cell.historicalSource.cssSha256 = currentCss;
            if (kind === 'extra') cell.historicalSource.unreviewed = true;
            if (kind === 'duplicate') modified.rules[0].declarations.push(structuredClone(cell));
            assert.throws(() => generate(modified), /CK19_HISTORICAL_OVERRIDE_PROVENANCE_INVALID/, kind);
        }
    });
}
