import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { parseHTML } from 'linkedom';
import postcss from 'postcss';
import { generateCk19MobileStyle, ck19Manifest, buildCk19MobileStyles } from '../../scripts/build-ck19-mobile-style.mjs';

const root = new URL('../../', import.meta.url);
const read = async name => (await fs.readFile(new URL(name, root), 'utf8')).replaceAll('\r\n', '\n');
const sha = value => createHash('sha256').update(value).digest('hex');
const templateHtml = await read('resources/mail-templates/u2e-universal-v1.html');
const presentationPhp = await read('app/Support/OutlookAddin/OutlookUniversalPresentation.php');
const generatorBytes = await read('scripts/build-ck19-mobile-style.mjs');
const parseRules = html => postcss.parse(html.match(/^<style type="text\/css">([\s\S]*)<\/style>$/)[1]).nodes[0].nodes;
const color = rule => rule.nodes.find(node => node.prop === 'color');
const ast = rules => rules.map(rule => ({ selector: rule.selector,
    declarations: rule.nodes.map(node => ({ property: node.prop, value: node.value, important: Boolean(node.important) })) }));
const cases = [];

for (const [tag, prior, size, digest, selector, whiteSelector] of [
    ['v18', 'v16', 4948, 'f3c94895db8ce4053d148779e7a0230636341f778a4ff70a2768e2237eb1502c',
        '[width="1040"] a,[width="1040"] a span', '[width="1040"] [width="247"] a,[width="1040"] [width="247"] a span'],
    ['v19', 'v17', 3801, '814833bd39e097eacfffe9285361756b6cede18bea41ef87068143ec6c8cefab',
        'table.rt-shell a,table.rt-shell a span', 'table.rt-shell table.rt-dispo a,table.rt-shell table.rt-dispo a span,table.rt-shell table.rt-dispo span'],
]) {
    const mappingBytes = await read('resources/mail-templates/ck19-mobile-style-' + tag + '.mapping.json');
    const mapping = JSON.parse(mappingBytes);
    const previous = await read('resources/mail-templates/generated/ck19-mobile-style-' + prior + '.html');
    const generate = extra => generateCk19MobileStyle({ templateHtml, presentationPhp, mapping, ...extra });
    const generated = generate(), rules = parseRules(generated.payload);
    cases.push({ tag, selector, whiteSelector, generated, rules });

    test(tag + ' changes exactly one approved declaration, retaining all selectors, media, CTA whites and layout values', () => {
        assert.equal(previous.split('color:#142231!important').length - 1, 1);
        assert.equal(generated.payload, previous.replace('color:#142231!important', 'color:inherit!important'));
        assert.equal(generated.payload.length, size); assert.equal(Buffer.byteLength(generated.payload), size);
        assert.equal(sha(generated.payload), digest); assert.ok(size < 5000);
        assert.equal(generated.templateHash, '1bfd6e33fbc15a5e278ce29cf039721cedd9b4f0a2a5506c4012df3d5483207d');
        assert.equal(generated.cssHash, '44320e37dbd5376595ecbd11938fcc96b5be372088dea343fed9a187d18f746f');
        const oldAst = ast(parseRules(previous)), newAst = ast(rules);
        const target = oldAst.filter(rule => rule.selector === selector);
        assert.equal(target.length, 1);
        const change = target[0].declarations.filter(declaration => declaration.property === 'color');
        assert.deepEqual(change, [{ property: 'color', value: '#142231', important: true }]);
        change[0].value = 'inherit';
        assert.deepEqual(newAst, oldAst);
        assert.deepEqual(rules.filter(rule => color(rule)?.value === '#fff').map(String),
            parseRules(previous).filter(rule => color(rule)?.value === '#fff').map(String));
        assert.doesNotMatch(generated.payload, /\[class\s*\$\s*=|:not\s*\(|<meta|[\r\n]/i);
    });

    test(tag + ' records exact predecessor provenance without pretending to normalize a supplied source file', () => {
        const manifest = ck19Manifest({ generated, mappingBytes, generatorBytes });
        assert.equal(manifest.mappingSha256, sha(mappingBytes));
        assert.equal(manifest.generatorSha256, sha(generatorBytes));
        assert.equal(manifest.payloadSha256, digest);
        assert.equal(manifest.sourceFileNormalization, null);
        assert.equal(manifest.derivation.predecessor, prior);
        assert.equal(manifest.derivation.predecessorSha256, sha(previous));
        assert.equal(manifest.nativeClientProof, false); assert.equal(manifest.exchangeRuleUpdatedByBuild, false);
        for (const mutation of ['absent', 'source-file', 'prior', 'color', 'extra']) {
            const altered = structuredClone(mapping);
            if (mutation === 'absent') delete altered.derivation;
            if (mutation === 'source-file') altered.sourceFileNormalization = {};
            if (mutation === 'prior') altered.derivation.predecessorSha256 = '0'.repeat(64);
            if (mutation === 'color') altered.derivation.value = '#fff';
            if (mutation === 'extra') altered.derivation.extra = true;
            assert.throws(() => generate({ mapping: altered }), /CK19_LINK_COLOR_DERIVATION_INVALID/, mutation);
        }
    });

    test(tag + ' cannot self-approve another scope, color, stale template or relaxed size limit', () => {
        for (const mutation of ['scope', 'color', 'pin', 'size']) {
            const altered = structuredClone(mapping);
            if (mutation === 'scope') altered.rules.find(rule => rule.selector === selector).selector = 'a,a span';
            if (mutation === 'color') altered.rules.find(rule => rule.selector === selector).declarations.find(node => node.property === 'color').override = '#ffffff';
            if (mutation === 'pin') altered.approvedTemplateSha256 = '0'.repeat(64);
            if (mutation === 'size') altered.policy.maximumCharactersExclusive = 5001;
            assert.throws(() => generate({ mapping: altered }), /CK19_(APPROVED_V(?:18|19)_DRIFT|SOURCE_TEMPLATE_CHANGED_REVIEW_MAPPING|MAPPING_INVALID)/, mutation);
        }
    });
}

// DOM checks establish selector scope and retained content, not native iOS
// computed colors. Host dark-mode inheritance requires the separate visual probe.
const shape = '<table class="rt-shell" width="1040"><tbody><tr><td><div id="contact">'
    + '<a id="address" href="https://maps.example.test/?q=Example#route"><span>Example Street 1<br>12345 Example</span></a>'
    + '<span id="suffix"> (Luhe)</span><a id="email" href="mailto:test@example.test"><span>test@example.test</span></a>'
    + '<a id="phone" href="tel:+4900012345"><span>+49 000 12345</span></a><a id="fragment" href="#section">Section</a>'
    + '</div><table class="rt-dispo" width="247"><tbody><tr><td class="rt-cta-cell">'
    + '<a id="cta" href="tel:+4900098765"><span><b>+49 000 98765</b></span></a>'
    + '</td></tr></tbody></table></td></tr></tbody></table><a id="outside" href="https://outside.example.test/">Outside</a>';
const documentFor = html => parseHTML('<html><head></head><body>' + html + '</body></html>').document;
const linkSet = document => [...document.querySelectorAll('a')].map(node => ({ href: node.getAttribute('href'), text: node.textContent, html: node.innerHTML }));

for (const current of cases) {
    test(current.tag + ' reaches auto-linked address fragments and nested spans while retaining the unlinked suffix and unrelated links', () => {
        const document = documentFor(shape), baseline = linkSet(document), body = document.body.innerHTML;
        const general = current.rules.find(rule => rule.selector === current.selector);
        const targets = new Set(document.querySelectorAll(general.selector));
        assert.equal(color(general).value, 'inherit'); assert.equal(color(general).important, true);
        for (const id of ['address', 'email', 'phone', 'fragment']) {
            const link = document.getElementById(id);
            assert.ok(targets.has(link));
            for (const span of link.querySelectorAll('span')) assert.ok(targets.has(span));
        }
        assert.equal(targets.has(document.getElementById('suffix')), false);
        assert.equal(targets.has(document.getElementById('outside')), false);
        document.head.innerHTML = current.generated.payload;
        assert.deepEqual(linkSet(document), baseline);
        assert.equal(document.body.innerHTML, body);
        assert.equal(document.getElementById('address').getAttribute('href'), 'https://maps.example.test/?q=Example#route');
        assert.equal(document.getElementById('fragment').getAttribute('href'), '#section');
        assert.equal(document.getElementById('suffix').textContent, ' (Luhe)');
    });

    test(current.tag + ' retains the more specific, later white CTA rule for links and their nested spans', () => {
        const document = documentFor(shape);
        const general = current.rules.find(rule => rule.selector === current.selector);
        const white = current.rules.find(rule => rule.selector === current.whiteSelector);
        assert.equal(color(white).value, '#fff'); assert.equal(color(white).important, true);
        assert.ok(current.rules.indexOf(white) > current.rules.indexOf(general));
        // Every white link selector adds a panel qualifier to its general
        // counterpart: a strict specificity increase, not source-order luck.
        const extra = current.tag === 'v18' ? ' [width="247"]' : ' table.rt-dispo';
        const whiteLinks = white.selector.split(',').filter(selector => / a(?: span)?$/.test(selector));
        assert.deepEqual(whiteLinks.map(selector => selector.replace(extra, '')), general.selector.split(','));
        const targets = new Set(document.querySelectorAll(white.selector));
        assert.ok(targets.has(document.getElementById('cta')));
        assert.ok(targets.has(document.querySelector('#cta span')));
        assert.equal(targets.has(document.getElementById('address')), false);
    });
}

for (const [tag, filename] of [
    ['v18', 'ck19-mobile-style-v10/received-word-1623.redacted.json'],
    ['v18', 'ck19-mobile-style-v10/received-rooster-1625.redacted.json'],
    ['v19', 'u2e-readback-v2/ios.ck24-210443.redacted.json'],
    ['v19', 'u2e-readback-v2/ios.synthetic.redacted.json'],
]) {
    test(tag + ' keeps link targets and nested-span coverage in ' + filename, async () => {
        const fixture = JSON.parse(await read('tests/Fixtures/mail/' + filename));
        const current = cases.find(value => value.tag === tag);
        const document = documentFor(fixture.html), links = [...document.querySelectorAll('a')], baseline = linkSet(document);
        const targets = new Set(document.querySelectorAll(current.selector));
        assert.ok(links.length >= 4);
        for (const link of links) {
            assert.ok(targets.has(link), 'Every fixture link remains in the same shell selector scope.');
            for (const span of link.querySelectorAll('span')) assert.ok(targets.has(span));
        }
        const white = new Set(document.querySelectorAll(current.whiteSelector));
        assert.ok(links.some(link => white.has(link)), 'Fixture includes a protected white CTA link.');
        document.head.innerHTML = current.generated.payload;
        assert.deepEqual(linkSet(document), baseline);
    });
}

test('active generated payloads and provenance are current without updating Exchange', async () => {
    const manifests = await buildCk19MobileStyles({ check: true });
    assert.deepEqual(manifests.map(value => value.revision), ['ck19-approved-v18-20261107', 'ck19-approved-v19-20261107']);
    assert.ok(manifests.every(value => value.exchangeRuleUpdatedByBuild === false && value.nativeClientProof === false));
});
