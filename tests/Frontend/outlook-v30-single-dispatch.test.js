import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { managedTemplateContract, standaloneSignatureContract, fluidLayoutContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { diagnoseUncertainV15Readback, insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate, replaceManagedTemplate } from '../../resources/js/outlook-addin/compose-template.js';
import { nativeOfficeHtml } from './outlook-standalone-fixture.js';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v30-reference/v16-single-source.json', import.meta.url), 'utf8'));
const previous = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v30-reference/v16-source.json', import.meta.url), 'utf8'));
const nonce = '19'.repeat(16);
const host = profile => ({ context: { platform: profile === 'M' ? 'OfficeOnline' : 'PC', mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } } } });
const sourceContract = (html, kind = 'standalone') => kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
const proof = html => fluidLayoutContract(html, html, nonce, fixture.version);
const check = html => verifyFluidLayoutMaterial(html, proof(html));
const opening = (html, target, rewrite) => html.slice(0, target.node.start) + rewrite(html.slice(target.node.start, target.node.openEnd)) + html.slice(target.node.openEnd);
const style = (value, declaration) => value.replace(/style="([^"]*)"/, (_match, css) => 'style="' + css + ';' + declaration + '"');
const withoutStyles = html => html.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
const failed = html => assert.throws(() => check(html), error => ['TEMPLATE_REPLACEMENT_UNAVAILABLE', 'NATIVE_LAYOUT_RESTORE_INVALID'].includes(error.code));

// Reproduces only the independently observed Office transformations, using
// the public synthetic fixture, never real EML, identity or message contents.
function savedOffice(html, { selected = false, noMath = false, wrapper = 'line' } = {}) {
    const contract = fluidLayoutContract(html), material = verifyFluidLayoutMaterial(html, contract);
    if (selected) html = opening(html, material.trainImage, value => value.replace('<img ', '<img id="image_0" ').replace(/\sheight="115"/, ''));
    const metadata = '.x_rt-office-metadata{font-size:0;line-height:0;max-height:0;overflow:hidden}';
    html = html.replace(/<style\b[^>]*>([\s\S]*?)<\/style>/gi, (_all, css) => css.includes('.rt-office-metadata{')
        ? '<style>' + (wrapper === 'none' ? metadata : '<div style="' + (wrapper === 'rtl' ? 'direction: rtl; text-align: right; line-height: 0;' : 'line-height: 14px;') + '">' + metadata + '</div>') + '</style>' : '');
    html = html.replace(/<!--[\s\S]*?-->/g, '').replace(/\s(?:class|data-rt-[a-z-]+|aria-hidden)="[^"]*"/gi, '').replace(/\shidden(?=\s|>)/gi, '')
        .replace(/display\s*:\s*none(?:!important)?;?/gi, '').replace(/mso-hide\s*:\s*all;?/gi, '');
    return noMath ? html.replace('width:clamp(248px,calc(100% - 220px),280px)', 'width:280px').replace('width:min(547px,100%)', 'width:547px')
        : html.replace('width:clamp(248px,calc(100% - 220px),280px)', 'width: clamp(248px, calc(100% - 220px), 280px)').replace('width:min(547px,100%)', 'width: min(547px, 100%)');
}

function wordMetadataHost(html, elementToProof = false) {
    const contract = fluidLayoutContract(html), material = verifyFluidLayoutMaterial(html, contract), nodes = contract.elements;
    const leaf = nodes[contract.signatureMetadata.managed], legalHost = nodes[leaf.parent];
    const legalTable = nodes.find(node => node.parent === leaf.parent && node.tag === 'table');
    assert.ok(legalTable && material.singleDispatch);
    const officeClass = elementToProof ? ' class="elementToProof"' : '';
    const css = '<span style="font-size:9px;"><style><div' + officeClass + ' style="line-height:14px;">.x_rt-office-metadata{font-size:0;line-height:0;max-height:0;overflow:hidden}</div></style></span>';
    const marker = value => '<span style="font-size:0px;line-height:0;">' + value + '</span>';
    const meta = '<div' + officeClass + ' style="line-height:14px;font-family:&quot;Trebuchet MS&quot;,Arial,sans-serif;">' + css + marker('RT-SIGNATURE-MANAGED-V1') + css
        + marker('RT-SIGNATURE-VERSION:' + contract.fluidLayout.version + ' RT-FLUID-LAYOUT-V16' + contract.fluidLayout.profile + ':' + contract.fluidLayout.nonce + ':' + contract.fluidLayout.version) + '</div>';
    return html.slice(0, legalTable.end) + meta + html.slice(legalHost.close);
}

for (const [kind, html] of Object.entries(fixture.source)) for (const profile of ['M', 'B']) {
    for (const [model, rewrite] of [['raw', value => value], ['no STYLE', withoutStyles], ['Word', nativeOfficeHtml],
        ['saved Office', value => savedOffice(value)], ['saved Office selection and numeric fallback', value => savedOffice(value, { selected: true, noMath: true, wrapper: 'rtl' })]]) {
        test('single dispatch ' + kind + '/' + profile + ' preserves exact owned material through ' + model, () => {
            const source = verifyFluidLayoutMaterial(html, sourceContract(html, kind), true);
            assert.equal(source.singleDispatch, true); assert.equal(source.mobileBar, null);
            const plan = prepareNativeLayoutRestore(html, sourceContract(html, kind), nonce, host(profile));
            assert.equal(Object.isFrozen(plan), true); assert.equal(plan.singleDispatch, true);
            const material = check(rewrite(plan.html));
            assert.equal(material.family, 16); assert.equal(material.singleDispatch, true); assert.equal(material.mobileBar, null);
            assert.equal(material.columns.length, 4); assert.equal(material.trainImage.node.attrs.width, '529');
        });
    }
}

test('single-card source admission requires its marker and only the two exact constant sizing expressions', () => {
    const html = fixture.source.standalone;
    for (const [before, after] of [
        [' rt-v16-single-dispatch', ''], ['clamp(248px,calc(100% - 220px),280px)', 'clamp(247px,calc(100% - 220px),280px)'],
        ['clamp(248px,calc(100% - 220px),280px)', 'clamp(248px,calc(100% - 220px),281px)'],
        ['clamp(248px,calc(100% - 220px),280px)', 'var(--unknown-width)'], ['min(547px,100%)', 'min(548px,100%)'],
    ]) {
        assert.ok(html.includes(before)); const changed = html.replace(before, after);
        assert.throws(() => prepareNativeLayoutRestore(changed, sourceContract(changed), nonce, host('M')), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    }
});

test('a single-card footer cannot regain duplicate actions, hidden-card media or unmanaged siblings', () => {
    const plan = prepareNativeLayoutRestore(fixture.source.standalone, sourceContract(fixture.source.standalone), nonce, host('M'));
    const contract = proof(plan.html), material = check(plan.html), nodes = contract.elements;
    const metadata = nodes[contract.signatureMetadata.managed], hostIndex = metadata.parent;
    const table = nodes.find(node => node.parent === hostIndex && node.tag === 'table');
    const old = previous.source.standalone.match(/<div class="rt-v16-mobile-dispatch"[^>]*>[\s\S]*?<\/div>/)[0];
    for (const extra of [old, '<span>Unknown sibling</span>', 'Unknown bare text',
        '<style>.v30 .rt-v16-mobile-dispatch{display:block!important}</style>']) {
        failed(plan.html.slice(0, table.start) + extra + plan.html.slice(table.start));
    }
    const withHide = plan.html.slice(0, material.root.node.start) + '<style>.v30 .rt-v16-mobile-dispatch{display:block!important}</style>' + plan.html.slice(material.root.node.start);
    failed(withHide);
    failed(plan.html.slice(0, material.root.node.start) + '<style>@media(max-width:520px){.v30 .rt-v16-dispatch{display:none!important}}</style>' + plan.html.slice(material.root.node.start));
});

test('saved Office metadata exceptions stay exact and remain limited to the nonce-owned footer', () => {
    const plan = prepareNativeLayoutRestore(fixture.source.standalone, sourceContract(fixture.source.standalone), nonce, host('M'));
    for (const wrapper of ['none', 'line', 'rtl']) assert.equal(check(savedOffice(plan.html, { wrapper })).singleDispatch, true);
    const html = savedOffice(plan.html);
    for (const [before, after] of [
        ['.x_rt-office-metadata{font-size:0;', '.arbitrary{font-size:0;'], ['font-size:0;line-height:0;max-height:0;overflow:hidden}', 'font-size:14px;line-height:0;max-height:0;overflow:hidden}'],
        ['style="line-height: 14px;"', 'style="line-height: 14px;position:absolute;"'],
        ['.x_rt-office-metadata{', '.x_rt-office-metadata,.foreign{'], ['overflow:hidden}', 'overflow:hidden;background-image:url(https://outside.invalid/a.png)}'],
    ]) { assert.ok(html.includes(before)); failed(html.replace(before, after)); }
});

for (const [kind, html] of Object.entries(fixture.source)) for (const profile of ['M', 'B']) {
    test('single dispatch ' + kind + '/' + profile + ' accepts only the proved metadata-only saved Word container', () => {
        const plan = prepareNativeLayoutRestore(html, sourceContract(html, kind), nonce, host(profile));
        const saved = wordMetadataHost(savedOffice(plan.html, { selected: true, noMath: true }));
        assert.equal(check(saved).singleDispatch, true);
        const offset = saved.lastIndexOf('<div style="line-height:14px;font-family:');
        assert.ok(offset > 0); const prefix = saved.slice(0, offset), metadata = saved.slice(offset);
        for (const [before, after] of [
            ['line-height:14px;font-family:', 'line-height:15px;font-family:'], ['Trebuchet MS', 'Unknown font'],
            ['font-size:9px;', 'font-size:10px;'], ['font-size:9px;', 'font-size:9px;position:absolute;'],
            ['<span style="font-size:9px;">', '<span style="font-size:9px;">Unknown sibling'],
            ['<div style="line-height:14px;font-family:', '<div class="unowned" style="line-height:14px;font-family:'],
            ['.x_rt-office-metadata{', '.unmanaged{'],
        ]) { assert.ok(metadata.includes(before)); failed(prefix + metadata.replace(before, after)); }
    });

    test('single dispatch ' + kind + '/' + profile + ' accepts only the exact reply proofing class on known metadata DIVs', () => {
        const plan = prepareNativeLayoutRestore(html, sourceContract(html, kind), nonce, host(profile));
        const saved = wordMetadataHost(savedOffice(plan.html, { selected: true, noMath: true }), true);
        assert.equal(check(saved).singleDispatch, true);
        const offset = saved.lastIndexOf('<div class="elementToProof" style="line-height:14px;font-family:');
        assert.ok(offset > 0); const prefix = saved.slice(0, offset), metadata = saved.slice(offset);
        const classes = [...metadata.matchAll(/ class="elementToProof"/g)]; assert.equal(classes.length, 3);
        for (let mask = 0; mask < 8; mask++) {
            let current = metadata;
            for (const [index, match] of [...classes.entries()].reverse()) {
                if (!(mask & (1 << index))) current = current.slice(0, match.index) + current.slice(match.index + match[0].length);
            }
            assert.equal(check(prefix + current).singleDispatch, true);
        }
        for (const match of classes) for (const replacement of [
            ' class="unknown"', ' class="elementToProof extra"', ' class="x_elementToProof"',
            ' class="elementToProof-other"', ' class="ElementToProof"', ' class=""',
            ' class="elementToProof" id="unowned"', ' class="elementToProof" data-any="1"',
            ' class="elementToProof" onclick="doSomething()"', ' class="elementToProof" hidden',
        ]) failed(prefix + metadata.slice(0, match.index) + replacement + metadata.slice(match.index + match[0].length));
        failed(prefix + metadata.replace('<span style="font-size:9px;">', '<span class="elementToProof" style="font-size:9px;">'));
    });
}

function mailbox(profile, rewrite = value => value) {
    const state = { html: '', writes: 0, sessions: new Map(), completed: 0 };
    const success = value => ({ status: 'succeeded', value });
    const item = {
        getComposeTypeAsync: callback => callback(success({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, callback) => callback(success(state.sessions.get(key) || '')),
            setAsync: (key, value, callback) => { state.sessions.set(key, value); if (value === '1') state.completed++; callback(success()); } },
        body: { getTypeAsync: callback => callback(success('html')),
            getAsync: (_kind, callback) => callback(success(state.html)),
            setSignatureAsync: (html, _config, callback) => { state.writes++; state.html = rewrite(html); callback(success()); },
            prependAsync: (html, _config, callback) => { state.writes++; state.html = rewrite(html) + state.html; callback(success()); },
            setAsync: (html, _config, callback) => { state.writes++; state.html = rewrite(html); callback(success()); } },
    };
    return { state, item, office: { ...host(profile), AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' } } };
}

const removeOldFooter = html => {
    const contract = fluidLayoutContract(html), material = verifyFluidLayoutMaterial(html, contract);
    assert.equal(material.singleDispatch, undefined); assert.ok(material.mobileBar);
    return nativeOfficeHtml(html.slice(0, material.mobileBar.node.start) + html.slice(material.mobileBar.node.end));
};

for (const profile of ['M', 'B']) {
    test('source-bound ' + profile + ' cannot call a degraded old signature current without writing', async () => {
        const input = previous.source.standalone;
        const plan = prepareNativeLayoutRestore(input, standaloneSignatureContract(input), nonce, host(profile));
        const { state, item, office } = mailbox(profile); state.html = removeOldFooter(plan.html);
        const before = state.html;
        await assert.rejects(insertStandaloneSignature(office, item, input, () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(state.writes, 0); assert.equal(state.completed, 0); assert.equal(state.html, before);
    });
}

for (const profile of ['M', 'B']) for (const path of ['standalone', 'prepend', 'replace']) {
    test('source-bound ' + profile + '/' + path + ' rejects a deleted old mobile copy even with unchanged nonce and version', async () => {
        const old = previous.source[path === 'standalone' ? 'standalone' : 'combined'];
        const oldContract = path === 'standalone' ? standaloneSignatureContract(old) : managedTemplateContract(old);
        const plan = prepareNativeLayoutRestore(old, oldContract, nonce, host(profile));
        assert.equal(Object.isFrozen(plan), true); assert.equal(plan.singleDispatch, false);
        const { state, item, office } = mailbox(profile, removeOldFooter);
        if (path === 'replace') state.html = prepareNativeLayoutRestore(fixture.source.combined, managedTemplateContract(fixture.source.combined), '22'.repeat(16), host(profile)).html;
        const run = () => path === 'standalone' ? insertStandaloneSignature(office, item, old, () => {}, { media: [] })
            : path === 'prepend' ? prependTemplate(office, item, old, () => {}, { media: [] })
                : replaceManagedTemplate(office, item, old, () => {}, { media: [] });
        await assert.rejects(run(), error => /LAYOUT_UNCONFIRMED/.test(error.code));
        assert.equal(state.writes, 1); assert.equal(state.completed, 0);
        const result = fluidLayoutContract(state.html), material = verifyFluidLayoutMaterial(state.html, result);
        assert.equal(result.fluidLayout.version, previous.version); assert.equal(material.singleDispatch, true);
        const unchanged = state.html, report = await diagnoseUncertainV15Readback(office, item, { requested: true, uncertain: true });
        assert.equal(report.acknowledgementGranted, false); assert.equal(report.sourcePlanAvailable, true);
        assert.equal(report.sourcePlanMatched, false); assert.equal(report.material, false);
        await assert.rejects(run(), error => /INSERT_UNCERTAIN/.test(error.code));
        assert.equal(state.writes, 1); assert.equal(state.completed, 0); assert.equal(state.html, unchanged);
        if (path === 'standalone') assert.equal(isStandaloneSignatureInsertionBlocked(item), true);
    });

    test('source-bound ' + profile + '/' + path + ' confirms the new single card after only proved saved Word rewrites', async () => {
        const input = fixture.source[path === 'standalone' ? 'standalone' : 'combined'];
        const { state, item, office } = mailbox(profile, value => wordMetadataHost(savedOffice(value, { selected: true, noMath: true }), true));
        if (path === 'replace') state.html = prepareNativeLayoutRestore(fixture.source.combined, managedTemplateContract(fixture.source.combined), '22'.repeat(16), host(profile)).html;
        const result = path === 'standalone' ? await insertStandaloneSignature(office, item, input, () => {}, { media: [] })
            : path === 'prepend' ? await prependTemplate(office, item, input, () => {}, { media: [] })
                : await replaceManagedTemplate(office, item, input, () => {}, { media: [] });
        if (path === 'standalone') assert.equal(result, 'applied');
        assert.equal(state.writes, 1); assert.equal(state.completed, 1);
        assert.equal(verifyFluidLayoutMaterial(state.html, fluidLayoutContract(state.html)).singleDispatch, true);
    });
}

test('image_0 is accepted only on the one owned unchanged train, never source, arbitrary IDs, altered assets or geometry', () => {
    const original = fixture.source.standalone, source = verifyFluidLayoutMaterial(original, sourceContract(original), true);
    const sourceWithId = opening(original, source.trainImage, value => value.replace('<img ', '<img id="image_0" '));
    assert.throws(() => prepareNativeLayoutRestore(sourceWithId, sourceContract(sourceWithId), nonce, host('M')), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    const plan = prepareNativeLayoutRestore(original, sourceContract(original), nonce, host('M')), saved = savedOffice(plan.html, { selected: true });
    assert.equal(check(saved).trainImage.node.attrs.id, 'image_0');
    for (const id of ['image_1', 'x_image_0', 'rt-native-layout-forged-r']) failed(saved.replace('id="image_0"', 'id="' + id + '"'));
    const material = check(saved);
    for (const rewrite of [value => value.replace('width="529"', 'width="530"'),
        value => value.replace('height:auto', 'height:1150px'), value => value.replace('/mail-assets/zug-dampf-outlook-brand-v1-', '/mail-assets/unknown-train-'),
        value => value.replace('https://mail.example.test/', 'https://outside.invalid/')]) failed(opening(saved, material.trainImage, rewrite));
    const captionId = opening(saved, material.caption, value => value.replace('<img ', '<img id="image_0" ')); failed(captionId);
});

test('the old two-copy source still cannot acknowledge real loss of its mobile hiding rules', () => {
    const html = previous.source.standalone, plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce, host('M'));
    const lost = plan.html.replace(/display:none(?:!important)?;?/gi, '').replace(/mso-hide:all;?/gi, '');
    assert.throws(() => verifyFluidLayoutMaterial(lost, fluidLayoutContract(lost, lost, nonce, previous.version)), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
});
