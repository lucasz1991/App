import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { managedTemplateContract, standaloneSignatureContract, fluidLayoutContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { mobileV17OfficeWire } from '../../resources/js/outlook-addin/v17-office-wire.js';
import { signatureHostProfileIsCurrent, standaloneSignatureLayoutIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
const fixture = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const mobile = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-mobile-reference/mobile-reference-v1.json', import.meta.url), 'utf8'));
const configured = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-office-actions/configured-default.json', import.meta.url), 'utf8'));
const host = profile => ({ context: { platform: profile === 'M' ? 'OfficeOnline' : 'PC', mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } } } });
const canonical = (html, kind) => kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
const contract = (html, plan) => ({ ...fluidLayoutContract(html), sourceLogoUrl: plan.sourceLogoUrl });

for (const profile of ['M', 'B']) for (const [kind, input] of Object.entries(configured.source)) test('actual configured PHP V17 ' + kind + '/' + profile + ' proves exact action geometry and default white number asset independently from canonical CSS', () => {
    const plan = prepareNativeLayoutRestore(input, canonical(input, kind), '83'.repeat(16), host(profile));
    assert.equal(plan.officeActionsWire, true); assert.equal(plan.defaultDispatchPhoneImage, true);
    const material = verifyFluidLayoutMaterial(plan.html, contract(plan.html, plan));
    assert.equal(material.officeActionsWire, true); assert.equal(material.defaultDispatchPhoneImage, true);
    assert.match(plan.html, /width:215px!important;max-width:100%;table-layout:auto!important/);
    for (const width of [62, 32, 121]) assert.match(plan.html, new RegExp('width:' + width + 'px!important'));
    assert.equal((plan.html.match(/\/m\/ef89fd23c6\.png/g) || []).length, 1);
    const image = /<img\b[^>]*ef89fd23c6\.png[^>]*>/.exec(plan.html)[0];
    assert.match(image, /width="108" height="20" alt="\+49 160 1881839"/); assert.doesNotMatch(image, /max-width/);
    for (const [before, after] of [['width:215px!important', 'width:216px!important'], ['width:62px!important', 'width:1px!important'],
        ['table-layout:auto!important', 'table-layout:fixed!important'], ['ef89fd23c6.png', 'ef89fd23c7.png'], ['height="20"', 'height="21"'],
        ['alt="+49 160 1881839"', 'alt="+49 160 1881830"'], ['white-space:nowrap!important', 'white-space:normal!important']]) {
        const changed = plan.html.replace(before, after); assert.notEqual(changed, plan.html);
        assert.throws(() => verifyFluidLayoutMaterial(changed, contract(changed, plan)), before);
    }
    const foreignOrigin = plan.html.replace(image, image.replace('https://mail.example.test/', 'https://foreign.invalid/'));
    assert.throws(() => verifyFluidLayoutMaterial(foreignOrigin, contract(foreignOrigin, plan)));
    const offset = plan.html.lastIndexOf('href="tel:+491601881839"');
    const changedHref = plan.html.slice(0, offset) + plan.html.slice(offset).replace('href="tel:+491601881839"', 'href="tel:+491601881830"');
    assert.throws(() => verifyFluidLayoutMaterial(changedHref, contract(changedHref, plan)));
});

test('nondefault dispatch numbers remain real text with a bounded nowrap geometry', () => {
    const source = fixture.source.standalone, plan = prepareNativeLayoutRestore(source, canonical(source, 'standalone'), '84'.repeat(16), host('B'));
    assert.equal(plan.defaultDispatchPhoneImage, false); assert.doesNotMatch(plan.html, /ef89fd23c6/); assert.match(plan.html, /\+491705550202<\/span>/);
    assert.equal(verifyFluidLayoutMaterial(plan.html, contract(plan.html, plan)).defaultDispatchPhoneImage, false);
});

test('reloaded currentness never labels old action geometry or a lost default phone image as current', () => {
    const source = configured.source.standalone;
    const plan = prepareNativeLayoutRestore(source, canonical(source, 'standalone'), '85'.repeat(16), host('M'));
    const sourceAction = /<table\b[^>]*rt-v17-dispatch-actions[^>]*>[\s\S]*?<\/table>/.exec(source)[0];
    const action = /<table\b[^>]*rt-v17-dispatch-actions[^>]*>[\s\S]*?<\/table>/.exec(plan.html)[0];
    assert.equal(signatureHostProfileIsCurrent(plan.html, plan.html, plan.version, host('M')), true);
    assert.equal(standaloneSignatureLayoutIsCurrent(plan.html, plan.html, plan.version, host('M')), true);
    for (const changed of [plan.html.replace(action, sourceAction), plan.html.replace(/<img\b[^>]*ef89fd23c6\.png[^>]*>/, '+49 160 1881839')]) {
        assert.equal(signatureHostProfileIsCurrent(changed, changed, plan.version, host('M')), false);
        assert.equal(standaloneSignatureLayoutIsCurrent(changed, changed, plan.version, host('M')), false);
    }
});

for (const [kind, source] of Object.entries(configured.mobile)) test('actual configured physical mobile ' + kind + ' keeps its flat auto table and uses only the bound default white phone PNG', () => {
    const wire = mobileV17OfficeWire(source);
    assert.match(wire, /ef89fd23c6\.png/); assert.match(wire, /width="94" height="17"/); assert.match(wire, /width:94px;height:auto/);
    assert.doesNotMatch(wire, /width:215px!important/); assert.match(wire, /table-layout:auto!important/);
    assert.doesNotMatch(mobileV17OfficeWire(kind === 'standalone' ? mobile.template.signature.html : mobile.template.mobileComposeHtml), /ef89fd23c6/);
});
