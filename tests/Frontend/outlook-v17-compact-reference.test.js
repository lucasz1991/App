import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { fluidLayoutContract, managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { isFluidLayoutSource, prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { diagnoseUncertainV15Readback, insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate, replaceManagedTemplate } from '../../resources/js/outlook-addin/compose-template.js';

// These protocol-hook cases deliberately use an unprojected synthetic V16
// body. Merely changing its family marker must never authorize V17 material.
const previous = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v30-reference/v16-single-source.json', import.meta.url), 'utf8'));
const nonce = '37'.repeat(16);
const host = profile => ({ context: { platform: profile === 'M' ? 'OfficeOnline' : 'PC', mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } } } });
const sourceContract = (html, kind) => kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);

for (const [kind, html] of Object.entries(previous.source)) for (const profile of ['M', 'B']) {
    test('V17 ' + kind + '/' + profile + ' parses its exact bound marker without admitting relabeled historical material', () => {
        const old = prepareNativeLayoutRestore(html, sourceContract(html, kind), nonce, host(profile));
        const changed = old.html.replace('RT-FLUID-LAYOUT-V16' + profile + ':', 'RT-FLUID-LAYOUT-V17' + profile + ':');
        const contract = fluidLayoutContract(changed, changed, nonce, previous.version);
        assert.equal(contract.fluidLayout.family, 17); assert.equal(contract.fluidLayout.profile, profile);
        assert.equal(contract.fluidLayout.nonce, nonce); assert.equal(contract.fluidLayout.version, previous.version);
        assert.throws(() => verifyFluidLayoutMaterial(changed, contract), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        for (const [expectedNonce, expectedVersion] of [['ab'.repeat(16), previous.version], [nonce, 'cd'.repeat(8)]]) {
            assert.throws(() => fluidLayoutContract(changed, changed, expectedNonce, expectedVersion), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
        }
        const foreign = '<span style="font-size:0;line-height:0;">RT-FLUID-LAYOUT-V16' + profile + ':' + nonce + ':' + previous.version + '</span>';
        assert.throws(() => fluidLayoutContract(foreign + changed), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
    });
}

test('V17 source routing remains fail closed for a relabeled V16 source without the new immutable contract', () => {
    const html = previous.source.standalone.replaceAll('outlook-desktop-flow-v16', 'outlook-desktop-flow-v17');
    const contract = standaloneSignatureContract(html);
    assert.equal(isFluidLayoutSource(contract), true);
    assert.throws(() => prepareNativeLayoutRestore(html, contract, nonce, host('M')), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
});

test('V17 explicit uncertain diagnosis stays read-only, unbound after reload and cannot acknowledge unproved material', async () => {
    const old = prepareNativeLayoutRestore(previous.source.standalone, standaloneSignatureContract(previous.source.standalone), nonce, host('M'));
    const html = old.html.replace('RT-FLUID-LAYOUT-V16M:', 'RT-FLUID-LAYOUT-V17M:');
    const counters = { reads: 0, writes: 0, binding: 0 };
    const success = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: callback => callback(success({ composeType: 'newMail' })),
        body: { getTypeAsync: callback => callback(success('html')),
            getAsync: (_kind, callback) => { counters.reads++; callback(success(html)); },
            setAsync: () => { counters.writes++; }, setSignatureAsync: () => { counters.writes++; }, prependAsync: () => { counters.writes++; } } };
    const office = { ...host('M'), AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' } };
    const result = await diagnoseUncertainV15Readback(office, item, { requested: true, uncertain: true, beforeRead: () => { counters.binding++; } });
    assert.equal(result.v17Candidate, true); assert.equal(result.family, 17); assert.equal(result.contract, true);
    assert.equal(result.material, false); assert.equal(result.phase, 'diagnostic-v17-material');
    assert.equal(result.sourcePlanAvailable, false); assert.equal(result.sourcePlanMatched, null);
    assert.equal(result.writeNonceCompared, false); assert.equal(result.acknowledgementGranted, false);
    assert.deepEqual(counters, { reads: 1, writes: 0, binding: 2 });
    assert.doesNotMatch(JSON.stringify(result), /example\.|https?:|mailto:|RT-|[a-f0-9]{16}|<\w|Muster/);
});


const current = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const withoutStyles = value => value.replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '');
const word = value => withoutStyles(value).replace(/\s(?:class|data-[\w-]+|aria-[\w-]+)="[^"]*"/gi, '');
const opening = (html, target, rewrite) => html.slice(0, target.node.start) + rewrite(html.slice(target.node.start, target.node.openEnd)) + html.slice(target.node.openEnd);
const appendStyle = (html, css) => html.replace(/style="([^"]*)"/, (_all, value) => 'style="' + value + ';' + css + '"');
const checked = html => verifyFluidLayoutMaterial(html, fluidLayoutContract(html));
const failure = html => assert.throws(() => checked(html), error => ['NATIVE_LAYOUT_RESTORE_INVALID', 'TEMPLATE_REPLACEMENT_UNAVAILABLE'].includes(error.code));
const prepare = (html, kind, profile) => prepareNativeLayoutRestore(html, sourceContract(html, kind), nonce, host(profile));

for (const [kind, html] of Object.entries(current.source)) for (const profile of ['M', 'B']) {
    for (const [model, rewrite] of [['raw', value => value], ['without STYLE', withoutStyles], ['Word classes removed', word], ['OWA prefixed classes', value => value.replace(/class="([^"]*)"/g, (_all, classes) => 'class="' + classes.split(/\s+/).filter(Boolean).map(name => 'x_' + name).join(' ') + '"')]]) {
        test('actual final PHP V17 ' + kind + '/' + profile + ' proves ' + model + ' with fluid inline geometry', () => {
            assert.equal(current.synthetic, true);
            const source = verifyFluidLayoutMaterial(html, sourceContract(html, kind), true);
            assert.equal(source.family, 17); assert.equal(source.fluidInline, true);
            assert.deepEqual(source.columns.map(value => value.node.attrs.width), ['220', '280', '210', '247']);
            const plan = prepare(html, kind, profile);
            assert.equal(Object.isFrozen(plan), true); assert.equal(plan.singleDispatch, true);
            assert.equal(plan.raisedTrain, source.raisedTrain); assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
            const received = rewrite(plan.html), contract = fluidLayoutContract(received, received, nonce, plan.version);
            const material = verifyFluidLayoutMaterial(received, contract);
            assert.equal(material.family, 17); assert.equal(material.raisedTrain, source.raisedTrain);
            assert.equal(material.fluidInline, true); assert.equal(material.singleDispatch, true);
            assert.equal(material.mobileBar, null); assert.equal(material.caption.node.attrs.width, '215');
            assert.equal(material.trainImage.node.attrs.width, '529');
        });
    }
}

test('V17 source requires its exact all-recipient contract and rejects altered sizing, paint, pins and action ownership', () => {
    const html = current.source.standalone;
    const mutations = [
        ['data-rt-compact-mobile="v17"', 'data-rt-compact-mobile="v16"'], [' rt-v17-mobile-inline', ''],
        ['max-width:1040px!important;', 'max-width:1041px!important;'],
        ['padding-bottom:3px!important;', 'padding-bottom:4px!important;'],
        ['padding:8px 40px 8px;', 'padding:9px 40px 8px;'], ['#dde1e6', '#dde1e7'], ['#47515e', '#47515f'],
        ['/m/4a55285090.png', '/m/a7626cc065.png'], ['/m/19125261c2.png', '/m/30b48b18c0.png'],
        ['/m/f9d88b899d.png', '/m/0000000000.png'], ['https://wa.me/491601881848', 'https://wa.me/491601881849'],
        ['margin-left:14px;', 'margin-left:15px;'], ['outlook-brand-v2-light.gif', 'outlook-brand-v1-light.gif'],
    ];
    for (const [before, after] of mutations) {
        assert.ok(html.includes(before), before); const changed = html.replace(before, after);
        assert.throws(() => prepare(changed, 'standalone', 'M'), error => ['NATIVE_LAYOUT_RESTORE_INVALID', 'TEMPLATE_REPLACEMENT_UNAVAILABLE'].includes(error.code), before);
    }
    const source = sourceContract(html, 'standalone'), material = verifyFluidLayoutMaterial(html, source, true);
    for (const [target, before, after] of [[material.columns[0], 'width:220px', 'width:221px'], [material.columns[1], 'width:280px', 'width:281px'], [material.columns[2], 'width:210px', 'width:211px'], [material.columns[3], 'width:247px', 'width:248px'], [material.train, 'max-width:547px', 'max-width:548px']]) {
        const changed = opening(html, target, value => value.replace(before, after)); assert.notEqual(changed, html);
        assert.throws(() => prepare(changed, 'standalone', 'M'));
    }
    const brokenTrain = opening(html, material.train, value => value.replace('table-layout:fixed!important', 'table-layout:auto!important'));
    assert.throws(() => prepare(brokenTrain, 'standalone', 'M'));
    const wa = source.elements.find(node => node.tag === 'a' && node.attrs.href === 'https://wa.me/491601881848');
    for (const extra of ['<a href="https://outside.invalid"><img src="https://outside.invalid/a.png"></a>', '<span>Unmanaged action text</span>', 'Unmanaged bare text']) {
        const changed = html.slice(0, wa.start) + extra + html.slice(wa.start);
        assert.throws(() => prepare(changed, 'standalone', 'M'));
    }
});

test('V17 numeric readback rejects Math-CSS, changed geometry and invisible substitutions', () => {
    for (const profile of ['M', 'B']) {
        const plan = prepare(current.source.standalone, 'standalone', profile), received = word(plan.html);
        for (const [before, after] of [
            ['width:280px!important', 'width:min(280px,52%)!important'], ['width:210px!important', 'width:min(210px,48%)!important'],
            ['width:247px!important', 'width:clamp(247px,calc((500px - 100%)*1000),100%)!important'],
            ['padding-left:20px!important', 'padding-left:clamp(20px,3.846154%,40px)!important'],
            ['margin-left:14px;', 'margin-left:0;'], ['white-space:nowrap!important;', 'white-space:normal!important;'],
        ]) { assert.ok(received.includes(before)); failure(received.replace(before, after)); }
        const contract = fluidLayoutContract(received), material = verifyFluidLayoutMaterial(received, contract);
        failure(opening(received, material.trainImage, value => appendStyle(value, 'max-width:none;')));
        failure(opening(received, material.columns[1], value => appendStyle(value, 'position:absolute;')));
        failure(opening(received, material.caption, value => value.replace('width="215"', 'width="216"')));
    }
});

test('V17 retains exact historical source eligibility while numeric delivery always has zero physical overlap', () => {
    for (const [name, html] of Object.entries(current.raised)) {
        const source = verifyFluidLayoutMaterial(html, sourceContract(html, 'standalone'), true);
        assert.equal(source.raisedTrain, name === 'short');
        assert.equal(html.includes(' rt-v17-raised'), name === 'short');
        const wrong = name === 'short'
            ? html.replace(' rt-v17-raised', '')
            : html.replace(' rt-v17-compact', ' rt-v17-raised rt-v17-compact');
        assert.notEqual(wrong, html);
        assert.throws(() => prepare(wrong, 'standalone', 'M'), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    }
});

function mailbox(profile, rewrite = value => value) {
    const state = { html: '', writes: 0, sessions: new Map(), completed: 0 };
    const success = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: callback => callback(success({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, callback) => callback(success(state.sessions.get(key) || '')),
            setAsync: (key, value, callback) => { state.sessions.set(key, value); if (value === '1') state.completed++; callback(success()); } },
        body: { getTypeAsync: callback => callback(success('html')), getAsync: (_kind, callback) => callback(success(state.html)),
            setSignatureAsync: (html, _config, callback) => { state.writes++; state.html = rewrite(html); callback(success()); },
            prependAsync: (html, _config, callback) => { state.writes++; state.html = rewrite(html) + state.html; callback(success()); },
            setAsync: (html, _config, callback) => { state.writes++; state.html = rewrite(html); callback(success()); } } };
    return { state, item, office: { ...host(profile), AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' } } };
}
for (const profile of ['M', 'B']) for (const path of ['standalone', 'prepend', 'replace']) {
    test('V17 ' + profile + '/' + path + ' zero physical train offset rejects a changed readback without retry', async () => {
        const rewrite = html => { const material = checked(html); return word(opening(html, material.train, value => value.replace('margin-top:0!important;', 'margin-top:-8px!important;'))); };
        const box = mailbox(profile, rewrite);
        if (path === 'replace') {
            box.state.html = prepare(current.source.combined.replaceAll(current.version, 'ee'.repeat(8)), 'combined', profile).html;
        }
        const invoke = () => path === 'standalone' ? insertStandaloneSignature(box.office, box.item, current.source.standalone, () => {}, { media: [] })
            : path === 'prepend' ? prependTemplate(box.office, box.item, current.source.combined, () => {}, { media: [] })
                : replaceManagedTemplate(box.office, box.item, current.source.combined, () => {}, { media: [] });
        await assert.rejects(invoke(), error => error.code === (path === 'standalone' ? 'SIGNATURE_LAYOUT_UNCONFIRMED' : 'TEMPLATE_LAYOUT_UNCONFIRMED'));
        assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 0);
        failure(box.state.html);
        await assert.rejects(invoke()); assert.equal(box.state.writes, 1);
        if (path === 'standalone') assert.equal(isStandaloneSignatureInsertionBlocked(box.item), true);
    });
    test('V17 ' + profile + '/' + path + ' confirms exact readback and preserves manual and quoted bytes', async () => {
        const outside = '<p>Manual bytes &amp; symbols.</p><blockquote>Opaque historical bytes.</blockquote>', box = mailbox(profile, word);
        box.state.html = outside;
        if (path === 'replace') box.state.html = prepare(current.source.combined.replaceAll(current.version, 'ee'.repeat(8)), 'combined', profile).html + outside;
        if (path === 'standalone') await insertStandaloneSignature(box.office, box.item, current.source.standalone, () => {}, { media: [] });
        else if (path === 'prepend') await prependTemplate(box.office, box.item, current.source.combined, () => {}, { media: [] });
        else await replaceManagedTemplate(box.office, box.item, current.source.combined, () => {}, { media: [] });
        assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 1);
        if (path !== 'standalone') assert.ok(box.state.html.endsWith(outside));
        assert.equal(checked(box.state.html).family, 17);
    });
}

for (const profile of ['M', 'B']) for (const path of ['standalone', 'prepend', 'replace']) test('V17 ' + profile + '/' + path + ' cannot acknowledge loss of the source-bound action table and default number image', async () => {
    const kind = path === 'standalone' ? 'standalone' : 'combined';
    const input = current.source[kind].replaceAll('+491705550202', '+491601881839');
    const original = sourceContract(input, kind), action = original.elements.find(node => node.tag === 'table' && (node.attrs.class || '').includes('rt-v17-dispatch-actions'));
    const oldAction = input.slice(action.start, action.end);
    const box = mailbox(profile, value => {
        const parsed = fluidLayoutContract(value), target = parsed.elements.find(node => node.tag === 'table' && (node.attrs.class || '').includes('rt-v17-dispatch-actions'));
        const changed = word(value.slice(0, target.start) + oldAction + value.slice(target.end));
        const material = checked(changed); assert.equal(material.officeActionsWire, false); assert.equal(material.defaultDispatchPhoneImage, false);
        return changed;
    });
    if (path === 'replace') box.state.html = prepare(input.replaceAll(current.version, 'ee'.repeat(8)), kind, profile).html;
    const invoke = () => path === 'standalone' ? insertStandaloneSignature(box.office, box.item, input, () => {}, { media: [] })
        : path === 'prepend' ? prependTemplate(box.office, box.item, input, () => {}, { media: [] }) : replaceManagedTemplate(box.office, box.item, input, () => {}, { media: [] });
    await assert.rejects(invoke(), error => ['SIGNATURE_LAYOUT_UNCONFIRMED', 'TEMPLATE_LAYOUT_UNCONFIRMED'].includes(error.code));
    assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 0);
    await assert.rejects(invoke()); assert.equal(box.state.writes, 1);
});

for (const profile of ['M', 'B']) test('V17 ' + profile + ' independently binds the default phone PNG even when the new action geometry survives', async () => {
    const input = current.source.standalone.replaceAll('+491705550202', '+491601881839');
    const box = mailbox(profile, value => {
        const changed = word(value.replace(/<img\b[^>]*ef89fd23c6\.png[^>]*>/, '+49 160 1881839'));
        const material = checked(changed); assert.equal(material.officeActionsWire, true); assert.equal(material.defaultDispatchPhoneImage, false);
        return changed;
    });
    const invoke = () => insertStandaloneSignature(box.office, box.item, input, () => {}, { media: [] });
    await assert.rejects(invoke(), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 0); await assert.rejects(invoke()); assert.equal(box.state.writes, 1);
});

for (const profile of ['M', 'B']) test('V17 ' + profile + ' independently binds the physical four-cell hybrid when actions survive', async () => {
    const input = current.source.standalone;
    const box = mailbox(profile, value => {
        const parsed = fluidLayoutContract(value), nodes = parsed.elements;
        const ghost = nodes.find(node => node.tag === 'table' && (node.attrs.class || '').includes('rt-v17-inline-columns'));
        const body = nodes.findIndex(node => node.parent === nodes.indexOf(ghost) && node.tag === 'tbody');
        const row = nodes.findIndex(node => node.parent === body && node.tag === 'tr');
        const cells = nodes.filter(node => node.parent === row && node.tag === 'td'); assert.equal(cells.length, 4);
        const changed = word(value.slice(0, ghost.start) + cells.map(node => value.slice(node.openEnd, node.close)).join('') + value.slice(ghost.end));
        const material = checked(changed); assert.equal(material.officeActionsWire, true); assert.equal(material.inlineHybrid, false);
        return changed;
    });
    const invoke = () => insertStandaloneSignature(box.office, box.item, input, () => {}, { media: [] });
    await assert.rejects(invoke(), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 0); await assert.rejects(invoke()); assert.equal(box.state.writes, 1);
});


function savedMetadata(html) {
    const contract = fluidLayoutContract(html), nodes = contract.elements, material = verifyFluidLayoutMaterial(html, contract);
    html = opening(html, material.trainImage, value => value.replace('<img ', '<img id="image_0" ').replace(/\sheight="115"/, ''));
    const fresh = fluidLayoutContract(html), entries = fresh.elements;
    const metadata = entries[fresh.signatureMetadata.managed], legalHost = entries[metadata.parent];
    const legal = entries.find(node => node.parent === metadata.parent && node.tag === 'table');
    const css = '<span style="font-size:9px;"><style><div class="elementToProof" style="line-height:14px;">.x_rt-office-metadata{font-size:0;line-height:0;max-height:0;overflow:hidden}</div></style></span>';
    const run = value => '<span style="font-size:0px;line-height:0;">' + value + '</span>';
    const wrapper = '<div class="elementToProof" style="line-height:14px;font-family:&quot;Trebuchet MS&quot;,Arial,sans-serif;">' + css + run('RT-SIGNATURE-MANAGED-V1') + css
        + run('RT-SIGNATURE-VERSION:' + fresh.fluidLayout.version + ' RT-FLUID-LAYOUT-V17' + fresh.fluidLayout.profile + ':' + fresh.fluidLayout.nonce + ':' + fresh.fluidLayout.version) + '</div>';
    return html.slice(0, legal.end) + wrapper + html.slice(legalHost.close);
}
for (const profile of ['M', 'B']) for (const [kind, html] of Object.entries(current.source)) {
    test('V17 ' + kind + '/' + profile + ' keeps the proven saved Office metadata and selected train boundary', () => {
        const plan = prepare(html, kind, profile), received = savedMetadata(plan.html);
        assert.equal(checked(received).family, 17); assert.equal(checked(received).raisedTrain, false);
        for (const replacement of ['class="foreign"', 'class="elementToProof extra"', 'class="elementToProof" id="foreign"']) failure(received.replace('class="elementToProof"', replacement));
        failure(received.replace('id="image_0"', 'id="image_1"'));
        failure(received.replace('RT-FLUID-LAYOUT-V17' + profile + ':', 'RT-FLUID-LAYOUT-V16' + profile + ':'));
    });
    test('V17 ' + kind + '/' + profile + ' excludes byte-preserved historical V16 quote and binds its own exact nonce', () => {
        const plan = prepare(html, kind, profile);
        const old = prepareNativeLayoutRestore(previous.source.standalone, standaloneSignatureContract(previous.source.standalone), '58'.repeat(16), host(profile));
        const quoted = '<blockquote>' + old.html + '</blockquote>', full = word(plan.html) + quoted;
        const contract = fluidLayoutContract(full, full, nonce, plan.version);
        assert.equal(verifyFluidLayoutMaterial(full, contract).family, 17); assert.ok(full.endsWith(quoted));
        assert.throws(() => fluidLayoutContract(full, full, '59'.repeat(16), plan.version));
        assert.throws(() => fluidLayoutContract(full, full, nonce, 'ff'.repeat(8)));
        assert.throws(() => fluidLayoutContract(plan.html + plan.html));
    });
}
for (const profile of ['M', 'B']) test('V17 short source ' + profile + ' retains zero physical overlap after Word class loss', () => {
    const html = current.raised.short, plan = prepare(html, 'standalone', profile);
    assert.equal(plan.raisedTrain, true); assert.equal(checked(word(plan.html)).raisedTrain, true);
});

for (const profile of ['M', 'B']) test('V17 same-version current fast path cannot complete a different train variant ' + profile, async () => {
    const box = mailbox(profile), plan = prepare(current.source.standalone, 'standalone', profile), material = checked(plan.html);
    box.state.html = word(opening(plan.html, material.train, value => value.replace('margin-top:0!important;', 'margin-top:-8px!important;')));
    failure(box.state.html);
    await assert.rejects(insertStandaloneSignature(box.office, box.item, current.source.standalone, () => {}, { media: [] }), error => ['SIGNATURE_LAYOUT_UNCONFIRMED', 'NATIVE_LAYOUT_RESTORE_INVALID'].includes(error.code));
    assert.equal(box.state.writes, 0); assert.equal(box.state.completed, 0);
});

test('numeric V17 source and STYLE-free delivery contain no Math CSS or unsafe transport-dependent width rules', () => {
    for (const html of [...Object.values(current.source), ...Object.values(current.raised)]) {
        const styles = [...html.matchAll(/ style="([^"]*)"/g)].map(match => match[1]).join(' ');
        assert.doesNotMatch(styles, /(?:min|max|clamp|calc)\s*\(/i);
        const material = verifyFluidLayoutMaterial(html, sourceContract(html, html.includes('RT-TEMPLATE-MANAGED-') ? 'combined' : 'standalone'), true);
        assert.equal(typeof material.raisedTrain, 'boolean');
        assert.equal(material.train.node.attrs.width, '100%');
        assert.match(material.train.node.attrs.style, /max-width:547px!important/);
    }
});

for (const profile of ['M', 'B']) for (const path of ['standalone', 'prepend', 'replace']) test('numeric V17 ' + profile + '/' + path + ' preserves historical source eligibility binding despite identical zero physical margins', async () => {
    const rewrite = html => {
        const contract = fluidLayoutContract(html), material = verifyFluidLayoutMaterial(html, contract);
        assert.equal(material.raisedTrain, true);
        const name = contract.elements.find(node => node.tag === 'p' && (node.attrs.class || '').split(/\s+/).includes('rt-sign-name'));
        assert.ok(name);
        const changed = html.slice(0, name.openEnd) + 'Mara Muster Extra Long Long Long' + html.slice(name.close);
        const readback = word(changed);
        assert.equal(checked(readback).raisedTrain, false);
        return readback;
    };
    const box = mailbox(profile, rewrite), source = current.shortSource[path === 'standalone' ? 'standalone' : 'combined'];
    if (path === 'replace') box.state.html = prepare(source.replaceAll(/RT-SIGNATURE-VERSION:([a-f0-9]{16})/g, 'RT-SIGNATURE-VERSION:' + 'aa'.repeat(8)), 'combined', profile).html;
    const invoke = () => path === 'standalone' ? insertStandaloneSignature(box.office, box.item, source, () => {}, { media: [] })
        : path === 'prepend' ? prependTemplate(box.office, box.item, source, () => {}, { media: [] })
            : replaceManagedTemplate(box.office, box.item, source, () => {}, { media: [] });
    await assert.rejects(invoke(), error => error.code === (path === 'standalone' ? 'SIGNATURE_LAYOUT_UNCONFIRMED' : 'TEMPLATE_LAYOUT_UNCONFIRMED'));
    assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 0);
    await assert.rejects(invoke()); assert.equal(box.state.writes, 1);
});

test('V17 rejects conflicting important padding even when a later ordinary shorthand looks canonical', () => {
    const html = current.source.standalone, contract = sourceContract(html, 'standalone'), material = verifyFluidLayoutMaterial(html, contract, true);
    const main = { node: contract.elements[material.columns[0].node.parent] };
    const malicious = opening(html, main, value => appendStyle(value, 'padding:900px!important;padding:12px 40px 0 40px;'));
    assert.throws(() => prepare(malicious, 'standalone', 'M'), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    for (const profile of ['M', 'B']) {
        const plan = prepare(html, 'standalone', profile), scoped = fluidLayoutContract(plan.html), proof = verifyFluidLayoutMaterial(plan.html, scoped);
        const target = { node: scoped.elements[proof.columns[0].node.parent] };
        failure(opening(plan.html, target, value => appendStyle(value, 'padding:900px!important;padding:12px 40px 0 40px;')));
    }
});


test('V17 proves all four effective edges across every owned pad before proxy normalization', () => {
    const padClasses = ['rt-v17-columns-cell', 'rt-v17-identity-cell', 'rt-v17-direct-cell', 'rt-v17-company-cell', 'rt-v17-dispatch-cell',
        'rt-v17-dispatch-block-cell', 'rt-v17-train-host', 'rt-v17-train-cell', 'rt-v17-legal-cell', 'rt-native-template-pad'];
    const targets = contract => {
        const nodes = contract.elements, found = new Set();
        for (const [index, node] of nodes.entries()) {
            if (node.tag === 'td' && padClasses.some(name => (node.attrs.class || '').split(/\s+/).includes(name))) found.add(index);
            if (node.tag === 'td' && (node.attrs.class || '').split(/\s+/).includes('rt-contact-icon')) {
                nodes.forEach((sibling, position) => { if (sibling.parent === node.parent && sibling.tag === 'td') found.add(position); });
            }
            if (node.tag === 'td' && nodes[node.parent]?.tag === 'tr') {
                const table = nodes[nodes[node.parent].parent]?.parent;
                const classes = (nodes[table]?.attrs.class || '').split(/\s+/);
                if (classes.includes('rt-personal-logo-frame') || classes.includes('rt-v17-dispatch-actions')) found.add(index);
            }
        }
        found.add(nodes[contract.signatureMetadata.managed].parent);
        return [...found].sort((a, b) => a - b).map(index => ({ index, node: nodes[index] }));
    };
    const prepend = (value, declaration) => value.replace(/style="([^"]*)"/, (_all, css) => 'style="' + declaration + css + '"');
    for (const [kind, html] of Object.entries(current.source)) {
        const contract = sourceContract(html, kind), pads = targets(contract);
        assert.ok(pads.length >= 24);
        for (const target of pads) {
            // A losing earlier ordinary shorthand is semantically harmless.
            const safe = opening(html, target, value => prepend(value, 'padding:900px;'));
            assert.equal(verifyFluidLayoutMaterial(safe, sourceContract(safe, kind), true).family, 17);
            for (const edge of ['top', 'right', 'bottom', 'left']) {
                const bad = opening(html, target, value => appendStyle(value, 'padding-' + edge + ':900px!important;padding-' + edge + ':0px;'));
                assert.throws(() => prepare(bad, kind, 'M'), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID', kind + ':' + target.index + ':' + edge);
            }
        }
        for (const profile of ['M', 'B']) {
            const plan = prepare(html, kind, profile), scoped = fluidLayoutContract(plan.html);
            for (const target of targets(scoped)) for (const edge of ['top', 'right', 'bottom', 'left']) {
                const bad = opening(plan.html, target, value => appendStyle(value, 'padding-' + edge + ':900px!important;padding-' + edge + ':0px;'));
                failure(word(bad));
            }
        }
        const main = pads.find(value => (value.node.attrs.class || '').includes('rt-v17-columns-cell'));
        for (const before of ['padding-top:900px!important;', 'padding:900px!important;']) {
            const bad = opening(html, main, value => prepend(value, before));
            assert.throws(() => prepare(bad, kind, 'M'), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        }
    }
});


const registeredLogo = verifyFluidLayoutMaterial(current.source.standalone, sourceContract(current.source.standalone, 'standalone'), true).sourceLogoUrl;
const registeredCheck = (html, url = registeredLogo) => verifyFluidLayoutMaterial(html, { ...fluidLayoutContract(html), sourceLogoUrl: url });
function actualOfficeModel(html, reply = false) {
    const contract = fluidLayoutContract(html), material = registeredCheck(html), nodes = contract.elements;
    const own = (node, parent) => node.start > parent.node.start && node.end < parent.node.end;
    const logo = nodes.map((node, index) => ({ node, index })).find(value => value.node.tag === 'img' && value.node.attrs.src === registeredLogo && own(value.node, material.columns[0]));
    const actions = nodes.map((node, index) => ({ node, index })).find(value => value.node.tag === 'table' && (value.node.attrs.class || '').includes('rt-v17-dispatch-actions'));
    const links = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'a' && own(value.node, material.columns[3]));
    const cellBody = nodes.findIndex(node => node.parent === actions.index && node.tag === 'tbody');
    const row = nodes.findIndex(node => node.parent === cellBody && node.tag === 'tr');
    const cells = nodes.map((node, index) => ({ node, index })).filter(value => value.node.parent === row && value.node.tag === 'td');
    const edits = [];
    const imgLinks = value => html.slice(value.node.start, value.node.end).replace(/vertical-align:middle;/g, '').replace(/border:0;/g, '');
    const host = (position, inner) => '<div style="direction:ltr;' + (position === 2 ? 'text-align:right;' : '') + 'line-height:19px;' + (position !== 1 ? 'white-space:nowrap;' : '')
        + 'font-family:' + (position === 2 ? 'Arial' : '&quot;Trebuchet MS&quot;,Arial') + ',sans-serif;font-size:13px;color:#ffffff;">' + inner + '</div>';
    const phoneSpan = nodes.find(node => node.parent === links[3].index && node.tag === 'span');
    const phone = html.slice(phoneSpan.openEnd, phoneSpan.close);
    const phoneLink = html.slice(links[3].node.start, links[3].node.openEnd) + '<b>' + phone + '</b></a>';
    for (const [position, cell] of cells.entries()) {
        const inner = position === 0 ? imgLinks(links[0]) + imgLinks(links[1]) : position === 1 ? imgLinks(links[2]) : '<span style="line-height:24px;">' + phoneLink + '</span>';
        const begin = html.slice(cell.node.start, cell.node.openEnd).replace(/\swidth="[^"]*"/, '');
        edits.push({ start: cell.node.start, end: cell.node.end, value: begin + host(position, inner) + '</td>' });
    }
    edits.push({ start: logo.node.start, end: logo.node.openEnd, value: html.slice(logo.node.start, logo.node.openEnd).replace(/\s(?:width|height)="(?:184|32)"/g, '') });
    for (const node of nodes) {
        if (node.tag === 'p' && (node.attrs.class || '').includes('rt-sign-name')) edits.push({ start: node.start, end: node.end,
            value: html.slice(node.start, node.openEnd).replace(/font-weight:bold;/g, '') + '<b>' + html.slice(node.openEnd, node.close) + '</b></p>' });
        if (node.tag === 'p' && (node.attrs.class || '').includes('rt-delivery-contact-value')) edits.push({ start: node.start, end: node.openEnd,
            value: html.slice(node.start, node.openEnd).replace(/word-break:normal(?:!important)?;/g, '') });
        if (node.tag === 'td' && (node.attrs.class || '').includes('rt-v17-legal-cell')) edits.push({ start: node.start, end: node.openEnd,
            value: html.slice(node.start, node.openEnd).replace('padding:8px 40px 8px;', 'padding:8px 20px;').replace(/padding-(?:left|right):20px!important;/g, '') });
        if (node.tag === 'br' && nodes[node.parent]?.tag === 'div' && (nodes[node.parent].attrs.style || '').includes('line-height:15px')) {
            edits.push({ start: node.start, end: node.end, value: '<span style="font-size:9px;"><br></span>' });
        }
    }
    let value = html;
    for (const edit of edits.sort((a, b) => b.start - a.start)) value = value.slice(0, edit.start) + edit.value + value.slice(edit.end);
    value = word(value);
    if (reply) {
        const currentContract = fluidLayoutContract(value), list = currentContract.elements;
        const wa = list.map((node, index) => ({ node, index })).find(item => item.node.tag === 'a' && item.node.attrs.href === 'https://wa.me/491601881848');
        value = opening(value, wa, text => text.replace('<a ', '<a class="x_rt-v17-dispatch-whatsapp OWAAutoLink" id="OWA12345678-1234-1234-1234-123456789abc" '));
        value = value.replace(/<div style="direction:ltr;(?=(?:text-align:right;)?line-height:19px;)/g, '<div class="elementToProof" style="direction:ltr;');
    }
    return value;
}
for (const profile of ['M', 'B']) for (const [kind, html] of Object.entries(current.source)) test('registered V17 ' + kind + '/' + profile + ' preserves the exact real Office receive/reply model and source-bound logo', () => {
    const plan = prepare(html, kind, profile);
    assert.equal(plan.sourceLogoUrl, registeredLogo);
    for (const reply of [false, true]) {
        const received = actualOfficeModel(plan.html, reply);
        assert.equal(registeredCheck(received).family, 17);
        assert.throws(() => checked(received), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        assert.throws(() => registeredCheck(received, 'https://mail.example.test/m/0000000000.png'), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        const scoped = fluidLayoutContract(received), image = scoped.elements.map((node, index) => ({ node, index })).find(value => value.node.tag === 'img' && value.node.attrs.src === registeredLogo);
        for (const attribute of ['width="185"', 'height="33"']) assert.throws(() => registeredCheck(opening(received, image, value => value.replace('<img ', '<img ' + attribute + ' '))), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        const badUrl = opening(received, image, value => value.replace(registeredLogo, 'https://outside.invalid/logo.png'));
        assert.throws(() => registeredCheck(badUrl), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        const wa = scoped.elements.find(node => node.tag === 'a' && node.attrs.href === 'https://wa.me/491601881848');
        const host = { node: scoped.elements[wa.parent] };
        assert.throws(() => registeredCheck(opening(received, host, value => value.replace('line-height:19px;', 'line-height:20px;'))), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        const legalBreak = scoped.elements.map((node, index) => ({ node, index })).find(value => value.node.tag === 'span' && value.node.attrs.style === 'font-size:9px;');
        assert.ok(legalBreak);
        assert.throws(() => registeredCheck(opening(received, legalBreak, value => value.replace('font-size:9px;', 'font-size:10px;'))), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        const phoneFormat = scoped.elements.map((node, index) => ({ node, index })).find(value => value.node.tag === 'div' && value.node.attrs.style?.includes('font-family:Arial,sans-serif;font-size:13px;color:#ffffff;'));
        assert.ok(phoneFormat);
        assert.throws(() => registeredCheck(opening(received, phoneFormat, value => value.replace('font-family:Arial,sans-serif;', 'font-family:Unknown,sans-serif;'))), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
        if (reply) for (const [before, after] of [
            ['x_rt-v17-dispatch-whatsapp OWAAutoLink', 'x_rt-v17-dispatch-whatsapp Foreign'],
            ['OWA12345678-1234-1234-1234-123456789abc', 'arbitrary-id'], ['class="elementToProof"', 'class="unknown"'],
        ]) assert.throws(() => registeredCheck(received.replace(before, after)), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    }
});
for (const profile of ['M', 'B']) for (const path of ['standalone', 'prepend', 'replace']) test('registered logo-loss readback ' + profile + '/' + path + ' is confirmed only against the verified source URL', async () => {
    const box = mailbox(profile, html => actualOfficeModel(html, true));
    if (path === 'replace') box.state.html = prepare(current.source.combined.replaceAll(current.version, 'ee'.repeat(8)), 'combined', profile).html;
    if (path === 'standalone') await insertStandaloneSignature(box.office, box.item, current.source.standalone, () => {}, { media: [] });
    else if (path === 'prepend') await prependTemplate(box.office, box.item, current.source.combined, () => {}, { media: [] });
    else await replaceManagedTemplate(box.office, box.item, current.source.combined, () => {}, { media: [] });
    assert.equal(box.state.writes, 1); assert.equal(box.state.completed, 1);
    assert.equal(registeredCheck(box.state.html).family, 17);
});
