import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { managedTemplateContract, standaloneSignatureContract, fluidLayoutContract } from '../../resources/js/outlook-addin/managed-template.js';
import { signatureOfficeWire } from '../../resources/js/outlook-addin/compose-template.js';
import { mobileV17OfficeWire } from '../../resources/js/outlook-addin/v17-office-wire.js';

const fixture = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const mobile = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-mobile-reference/mobile-reference-v1.json', import.meta.url), 'utf8'));
const sourceContract = (html, kind) => kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
const host = profile => ({ context: { platform: profile === 'M' ? 'OfficeOnline' : 'PC', mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } } } });
const logoTag = html => [...html.matchAll(/<img\b[^>]*>/gi)].map(value => value[0]).find(value => /\bclass="rt-v9-logo"/.test(value));
const boundReadback = (html, plan) => ({ ...fluidLayoutContract(html, html, plan.nonce, plan.version), sourceLogoUrl: plan.sourceLogoUrl });

for (const profile of ['M', 'B']) for (const [kind, source] of Object.entries(fixture.source)) {
    test('verified V17 ' + kind + '/' + profile + ' egress contains no generated STYLE and pins the Word logo itself', () => {
        const canonical = sourceContract(source, kind), original = verifyFluidLayoutMaterial(source, canonical, true);
        const plan = prepareNativeLayoutRestore(source, canonical, '71'.repeat(16), host(profile));
        assert.equal(plan.family, 17); assert.equal(plan.profile, profile); assert.equal(plan.sourceLogoUrl, original.sourceLogoUrl);
        assert.equal(plan.stylePayload, ''); assert.doesNotMatch(plan.html, /<style\b/i);
        const logo = logoTag(plan.html); assert.match(logo, /\bwidth="184"/); assert.match(logo, /\bheight="32"/);
        assert.match(logo, /width:184px!important/); assert.match(logo, /height:auto/); assert.match(logo, /max-width:100%/);
        assert.match(source, /<style\b/i); assert.match(logoTag(source), /width:100%!important/);
        assert.equal(verifyFluidLayoutMaterial(plan.html, boundReadback(plan.html, plan)).family, 17);
        // Real Office can remove numeric IMG attrs; its retained pixel width
        // is still proved against the independently verified source URL.
        const dropped = plan.html.replace(logo, logo.replace(/\s(?:width|height)="[^"]*"/g, ''));
        assert.equal(verifyFluidLayoutMaterial(dropped, boundReadback(dropped, plan)).family, 17);
        assert.throws(() => verifyFluidLayoutMaterial(dropped, { ...boundReadback(dropped, plan), sourceLogoUrl: 'https://unbound.example/logo.png' }));
        for (const value of ['185px', '1000px', '0px']) {
            const changed = plan.html.replace(logo, logo.replace('width:184px!important', 'width:' + value + '!important'));
            assert.throws(() => verifyFluidLayoutMaterial(changed, boundReadback(changed, plan)));
        }
        assert.throws(() => verifyFluidLayoutMaterial(plan.html, sourceContract(plan.html, kind), true));
    });
}

test('V17 egress preserves editable CSS/text and complete quoted bytes instead of scrubbing an existing message', () => {
    const original = fixture.source.combined, contract = managedTemplateContract(original);
    const editable = '<p>Existing editable text</p><style data-private="user">.own{color:purple}</style>';
    const quote = '<blockquote><style>.history{color:green}</style><p>Historical opaque bytes</p></blockquote>';
    const source = original.slice(0, contract.region.start) + editable + original.slice(contract.region.end) + quote;
    const input = managedTemplateContract(source), plan = prepareNativeLayoutRestore(source, input, '72'.repeat(16), host('B'));
    assert.ok(plan.html.includes(editable)); assert.ok(plan.html.endsWith(quote));
    assert.equal((plan.html.match(/<style\b/gi) || []).length, 2);
    const material = verifyFluidLayoutMaterial(plan.html, boundReadback(plan.html, plan)); assert.equal(material.family, 17);
});

test('foreign unbound scaffolding STYLE and incorrect canonical logo stop before Office egress', () => {
    const source = fixture.source.standalone;
    for (const insert of ['<style>.foreign{color:green}</style>', '<style data-rt-outlook-signature-css="1" nonce="foreign">.foreign{}</style>', '<style data-rt-outlook-signature-css="other">.foreign{}</style>']) {
        const altered = insert + source;
        assert.throws(() => prepareNativeLayoutRestore(altered, standaloneSignatureContract(altered), '73'.repeat(16), host('M')));
    }
    const wrong = source.replace(logoTag(source), logoTag(source).replace('width="184"', 'width="185"'));
    assert.throws(() => signatureOfficeWire(wrong));
});

for (const [kind, source] of Object.entries({ standalone: mobile.template.signature.html, combined: mobile.template.mobileComposeHtml })) {
    test('physical mobile ' + kind + ' egress is style-free, preserves its single native document, and pins 104 by 18 logo', () => {
        const wire = mobileV17OfficeWire(source); assert.doesNotMatch(wire, /<style\b/i);
        assert.match(wire, /data-rt-mobile-reference="v1"/); assert.match(wire, /width:104px!important/);
        assert.match(logoTag(wire), /\bwidth="104"/); assert.match(logoTag(wire), /\bheight="18"/);
        assert.equal((wire.match(/<table\b[^>]*data-rt-dispatch-card="v17"/g) || []).length, 1);
        assert.equal(mobileV17OfficeWire(wire), wire);
        assert.throws(() => mobileV17OfficeWire(source.replace('data-rt-mobile-reference="v1"', 'data-rt-mobile-reference="unknown"')));
        const wrong = source.replace(logoTag(source), logoTag(source).replace('width="104"', 'width="105"'));
        assert.throws(() => mobileV17OfficeWire(wrong));
        assert.throws(() => mobileV17OfficeWire('<style>.foreign{}</style>' + source));
        assert.throws(() => managedTemplateContract(wire));
    });
}

test('legacy documents without V17 flow are byte-identical at the direct native setter adapter', () => {
    const legacy = '<style>.legacy{color:blue}</style><p>Unchanged legacy HTML</p>';
    assert.equal(signatureOfficeWire(legacy), legacy); assert.equal(mobileV17OfficeWire(legacy), legacy);
});
