import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { fluidLayoutContract, managedTemplateContract, standaloneSignatureContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { insertStandaloneSignature, isStandaloneSignatureInsertionBlocked, prependTemplate, replaceManagedTemplate, signatureHostProfileIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
import { nativeOfficeHtml } from './outlook-standalone-fixture.js';

const baseline = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v30-reference/v15-baseline.json', import.meta.url), 'utf8'));
const current = JSON.parse(readFileSync(new URL('../Fixtures/mail/outlook-v30-reference/v16-source.json', import.meta.url), 'utf8'));
const nonce = '42'.repeat(16);
const hash = value => createHash('sha256').update(value).digest('hex');
const host = profile => ({ context: {
    platform: profile === 'M' ? 'OfficeOnline' : 'PC',
    mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } },
} });
const contractFor = (html, field) => field === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
const withoutStyles = html => html.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
// Deliberately a parser/Word-rewrite model, not native Classic or browser proof.
const wordModel = html => nativeOfficeHtml(html).replace(/<\/?tbody>/gi, '')
    .replace(/(\d+(?:\.\d+)?)px/g, (_match, value) => Number(value) * 0.75 + 'pt')
    .replace(/<br\b[^>]*\bclear="all"[^>]*>/i, '<div style="font-size:0;line-height:0;"><br></div>');
const within = (nodes, child, parent) => {
    for (let index = child; Number.isInteger(index); index = nodes[index].parent) if (index === parent) return true;
    return false;
};
const descendants = (contract, owner, tag) => contract.elements.map((node, index) => ({ node, index }))
    .filter(value => value.node.tag === tag && within(contract.elements, value.index, owner.index));
const replaceOpening = (html, target, rewrite) => {
    const opening = html.slice(target.node.start, target.node.openEnd), changed = rewrite(opening);
    assert.notEqual(changed, opening, 'The mutation must change its intended target.');
    return html.slice(0, target.node.start) + changed + html.slice(target.node.openEnd);
};
const appendStyle = (opening, declaration) => opening.replace(/\bstyle="([^"]*)"/, (_match, style) => 'style="' + style + ';' + declaration + '"');
const removeMaxWidth = opening => opening.replace(/max-width\s*:\s*100%\s*(?:!important)?\s*;?/i, '');
const invalidSource = html => {
    assert.throws(() => prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce, host('M')),
        error => ['NATIVE_LAYOUT_RESTORE_INVALID', 'TEMPLATE_REPLACEMENT_UNAVAILABLE'].includes(error.code));
};

function mailbox(profile, rewrite = wordModel) {
    const state = { body: '', signature: '', signatureWrites: 0, prependWrites: 0, bodyWrites: 0, sessions: new Map() };
    const success = value => ({ status: 'succeeded', value });
    const item = { getComposeTypeAsync: callback => callback(success({ composeType: 'newMail' })),
        sessionData: { getAsync: (key, callback) => callback(success(state.sessions.get(key) || '')),
            setAsync: (key, value, callback) => { state.sessions.set(key, value); callback(success()); } },
        body: { getTypeAsync: callback => callback(success('html')),
            getAsync: (_kind, callback) => callback(success(state.body)),
            setSignatureAsync: (html, _options, callback) => {
                state.signatureWrites++;
                if (state.signature) state.body = state.body.replace(state.signature, '');
                state.signature = rewrite(html); state.body = state.signature + state.body; callback(success());
            },
            prependAsync: (html, _options, callback) => { state.prependWrites++; state.body = rewrite(html) + state.body; callback(success()); },
            setAsync: (html, _options, callback) => { state.bodyWrites++; state.body = rewrite(html); callback(success()); },
        } };
    const office = { ...host(profile), AsyncResultStatus: { Succeeded: 'succeeded' }, CoercionType: { Html: 'html' } };
    office.context.requirements = { isSetSupported: () => true }; office.context.mailbox.item = item;
    return { office, item, state };
}

test('the V30 extension preserves the exact previously published V15 M and B wire bytes', () => {
    assert.equal(baseline.synthetic, true);
    assert.equal(baseline.baselineCommit, '8f880caca71a73774d79c5c407ecfd6b043cde42');
    for (const [field, html] of Object.entries(baseline.source)) {
        for (const profile of ['B', 'M']) {
            const expected = baseline.expected[field + ':' + profile];
            const plan = prepareNativeLayoutRestore(html, contractFor(html, field), baseline.nonce, host(profile));
            assert.equal(hash(plan.html), expected.sha256, field + ':' + profile);
            assert.equal(plan.html.length - html.length, expected.annotationUnits);
            assert.equal(plan.profile, profile);
            assert.equal(plan.family, 15);
            assert.equal(plan.version, baseline.version);
            assert.equal(plan.ids, null);
            assert.equal(plan.stylePayload, '');
            const readback = nativeOfficeHtml(plan.html);
            assert.equal(verifyFluidLayoutMaterial(readback, fluidLayoutContract(readback, readback, baseline.nonce, baseline.version)).family, 15);
        }
    }
});

test('renaming a historical source cannot make its old geometry an admitted V30 source', () => {
    for (const [field, html] of Object.entries(baseline.source)) {
        const renamed = html.replaceAll('outlook-desktop-flow-v15', 'outlook-desktop-flow-v16')
            .replaceAll('rt-v15-', 'rt-v16-').replaceAll('rt-df15', 'rt-df16');
        assert.throws(() => prepareNativeLayoutRestore(renamed, contractFor(renamed, field), baseline.nonce, host('M')),
            error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    }
});

test('the actual synthetic PHP V30 export pins its four groups, legal gutter and mobile dispatch copy', () => {
    assert.equal(current.synthetic, true);
    assert.deepEqual(current.mediaCounts, { standalone: 0, combined: 0 });
    for (const [field, html] of Object.entries(current.source)) {
        const contract = contractFor(html, field), material = verifyFluidLayoutMaterial(html, contract, true);
        assert.equal(material.family, 16);
        assert.deepEqual(material.columns.map(value => value.node.attrs.width), ['220', '280', '230', '227']);
        assert.equal(material.train.node.attrs.width, '547');
        assert.equal(material.trainImage.node.attrs.width, '529');
        assert.equal(material.trainImage.node.attrs.height, '115');
        assert.match(material.mobileBar.node.attrs.style, /display:none/);
        assert.match(material.mobileBar.node.attrs.style, /mso-hide:all/);
        assert.equal(descendants(contract, material.mobileBar, 'a').length, 3);
        assert.equal(descendants(contract, material.mobileBar, 'img').length, 2);
    }
});

for (const [field, html] of Object.entries(current.source)) {
    for (const profile of ['B', 'M']) {
        for (const [model, rewrite] of [['raw', value => value], ['without STYLE', withoutStyles], ['Word model', wordModel]]) {
            test('actual V30 ' + field + '/' + profile + ' survives ' + model + ' with its own exact nonce and material', () => {
                const plan = prepareNativeLayoutRestore(html, contractFor(html, field), nonce, host(profile));
                assert.equal(plan.family, 16); assert.equal(plan.profile, profile);
                assert.equal(plan.sourceOnly, 'fluid-v16'); assert.equal(plan.ids, null); assert.equal(plan.stylePayload, '');
                assert.equal(plan.html.length - html.length, profile === 'B' ? 71 : 167);
                const readback = rewrite(plan.html), proof = fluidLayoutContract(readback, readback, nonce, current.version);
                assert.equal(proof.fluidLayout.profile, profile);
                assert.equal(verifyFluidLayoutMaterial(readback, proof).family, 16);
            });
        }
    }
}

test('an unknown host keeps the explicitly bounded V30 profile without changing historical host selection', () => {
    for (const [field, html] of Object.entries(current.source)) {
        const plan = prepareNativeLayoutRestore(html, contractFor(html, field), nonce);
        assert.equal(plan.profile, 'B'); assert.equal(plan.html.length - html.length, 71);
    }
});

test('V30 current ownership excludes a byte-preserved historical V15 quote and rejects duplicate current nonces', () => {
    for (const profile of ['B', 'M']) {
        const html = current.source.standalone;
        const plan = prepareNativeLayoutRestore(html, contractFor(html, 'standalone'), nonce, host(profile));
        const previous = prepareNativeLayoutRestore(baseline.source.standalone, contractFor(baseline.source.standalone, 'standalone'), baseline.nonce, host(profile));
        const quoted = '<blockquote>' + previous.html + '</blockquote>', full = wordModel(plan.html) + quoted;
        const proof = fluidLayoutContract(full, full, nonce, current.version);
        assert.equal(proof.fluidLayout.family, 16); assert.equal(verifyFluidLayoutMaterial(full, proof).family, 16);
        assert.ok(full.endsWith(quoted));
        assert.throws(() => fluidLayoutContract(plan.html + plan.html));
        assert.throws(() => fluidLayoutContract(plan.html, plan.html, '43'.repeat(16), current.version));
        assert.throws(() => fluidLayoutContract(plan.html, plan.html, nonce, 'ee'.repeat(8)));
    }
});

test('V30 rejects unsafe geometry, missing or visible mobile copies, and unowned extra footer content before writing', () => {
    const html = current.source.standalone, contract = standaloneSignatureContract(html);
    const material = verifyFluidLayoutMaterial(html, contract, true), bar = material.mobileBar;
    const main = { node: contract.elements[material.columns[0].node.parent] };
    const mutations = [
        () => replaceOpening(html, material.columns[0], opening => opening.replace('width="220"', 'width="221"')),
        () => replaceOpening(html, material.columns[1], opening => appendStyle(opening, 'min-width:900px;')),
        () => replaceOpening(html, main, opening => appendStyle(opening, 'padding-left:39px;')),
        () => replaceOpening(html, material.train, removeMaxWidth),
        () => replaceOpening(html, material.trainImage, removeMaxWidth),
        () => html.slice(0, bar.node.start) + html.slice(bar.node.end),
        () => replaceOpening(html, bar, opening => opening.replace('display:none', 'display:block')),
        () => replaceOpening(html, bar, opening => appendStyle(opening, 'background-color:#000000;')),
        () => replaceOpening(html, bar, opening => appendStyle(opening, 'direction:rtl;')),
        () => replaceOpening(html, bar, opening => opening.replace('<div', '<div onclick="alert(1)"')),
        () => html.slice(0, bar.node.close) + '<span>Foreign footer text</span>' + html.slice(bar.node.close),
        () => html.slice(0, bar.node.end) + '<div>Unmanaged sibling</div>' + html.slice(bar.node.end),
    ];
    for (const mutate of mutations) invalidSource(mutate());
    const links = descendants(contract, bar, 'a'), images = descendants(contract, bar, 'img');
    invalidSource(replaceOpening(html, links[0], opening => opening.replace(/href="[^"]*"/, 'href="mailto:outside@example.invalid"')));
    invalidSource(replaceOpening(html, images[0], opening => opening.replace(/src="[^"]*"/, 'src="https://mail.example.test/m/0000000000.png"')));
    invalidSource(replaceOpening(html, images[1], opening => opening.replace('width="18"', 'width="19"')));
});

test('V30 legal-host gaps cannot hide unmanaged visible siblings or text before, between, or after owned footer material', () => {
    const insertions = ['<span>Unmanaged visible footer</span>', 'Unmanaged visible footer'];
    const gaps = (contract, material) => {
        const bar = material.mobileBar, parent = bar.node.parent;
        const legal = contract.elements.filter(node => node.parent === parent && node.tag === 'table');
        assert.equal(legal.length, 1);
        return [bar.node.start, bar.node.end, legal[0].end];
    };
    const insertAt = (html, offset, value) => html.slice(0, offset) + value + html.slice(offset);
    for (const [field, html] of Object.entries(current.source)) {
        const source = contractFor(html, field), material = verifyFluidLayoutMaterial(html, source, true);
        for (const offset of gaps(source, material)) {
            for (const value of insertions) {
                const changed = insertAt(html, offset, value);
                assert.throws(() => prepareNativeLayoutRestore(changed, contractFor(changed, field), nonce, host('M')),
                    error => ['NATIVE_LAYOUT_RESTORE_INVALID', 'TEMPLATE_REPLACEMENT_UNAVAILABLE'].includes(error.code));
            }
        }
        for (const profile of ['B', 'M']) {
            const plan = prepareNativeLayoutRestore(html, source, nonce, host(profile));
            for (const rewrite of [value => value, wordModel]) {
                const received = rewrite(plan.html), contract = fluidLayoutContract(received, received, nonce, current.version);
                const proof = verifyFluidLayoutMaterial(received, contract);
                for (const offset of gaps(contract, proof)) {
                    for (const value of insertions) {
                        const changed = insertAt(received, offset, value);
                        assert.throws(() => verifyFluidLayoutMaterial(changed, fluidLayoutContract(changed, changed, nonce, current.version)),
                            error => ['NATIVE_LAYOUT_RESTORE_INVALID', 'TEMPLATE_REPLACEMENT_UNAVAILABLE'].includes(error.code));
                    }
                }
            }
        }
    }
});

test('a current V30 train losing its max-width can never be acknowledged as a safe large image', () => {
    for (const profile of ['B', 'M']) {
        const html = current.source.standalone, plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce, host(profile));
        const proof = fluidLayoutContract(plan.html, plan.html, nonce, current.version), material = verifyFluidLayoutMaterial(plan.html, proof);
        const changed = replaceOpening(plan.html, material.trainImage, removeMaxWidth);
        assert.throws(() => verifyFluidLayoutMaterial(changed, fluidLayoutContract(changed, changed, nonce, current.version)), error => error.code === 'NATIVE_LAYOUT_RESTORE_INVALID');
    }
});

for (const profile of ['B', 'M']) {
    test('V30 ' + profile + ' standalone insertion and combined replacement preserve manual text and opaque quoted history', async () => {
        const previous = prepareNativeLayoutRestore(baseline.source.standalone, standaloneSignatureContract(baseline.source.standalone), baseline.nonce, host(profile));
        // Existing quoted HTML has already passed through the host serializer.
        const outside = '<p>Unmanaged user bytes &amp; symbols.</p><blockquote>' + wordModel(previous.html) + '</blockquote>';
        const standalone = mailbox(profile); standalone.state.body = outside;
        await insertStandaloneSignature(standalone.office, standalone.item, current.source.standalone, () => {}, { media: [] });
        assert.equal(standalone.state.signatureWrites, 1); assert.equal(standalone.state.bodyWrites + standalone.state.prependWrites, 0);
        assert.ok(standalone.state.body.endsWith(outside));
        assert.equal(signatureHostProfileIsCurrent(standalone.state.body, standalone.state.body, current.version, standalone.office), true);
        const combined = mailbox(profile); combined.state.body = outside;
        await prependTemplate(combined.office, combined.item, current.source.combined, () => {}, { media: [] });
        const owned = fluidLayoutContract(combined.state.body), ownText = '<p>Preserved own text &amp; punctuation.</p>';
        combined.state.body = combined.state.body.slice(0, owned.region.start) + ownText + combined.state.body.slice(owned.region.end);
        const updated = current.source.combined.replaceAll(current.version, 'ee'.repeat(8));
        await replaceManagedTemplate(combined.office, combined.item, updated, () => {}, { media: [] });
        assert.equal(combined.state.prependWrites, 1); assert.equal(combined.state.bodyWrites, 1); assert.equal(combined.state.signatureWrites, 0);
        assert.ok(combined.state.body.endsWith(outside)); assert.ok(combined.state.body.includes(ownText));
        assert.equal(fluidLayoutContract(combined.state.body).fluidLayout.version, 'ee'.repeat(8));
    });
}

test('a rewritten V30 mobile action cannot grant success or trigger a second uncertain signature write', async () => {
    const rewrite = html => {
        const contract = fluidLayoutContract(html), material = verifyFluidLayoutMaterial(html, contract);
        const action = descendants(contract, material.mobileBar, 'a')[0];
        return wordModel(replaceOpening(html, action, opening => opening.replace(/href="[^"]*"/, 'href="mailto:outside@example.invalid"')));
    };
    const state = mailbox('M', rewrite), outside = '<p>Manual text</p><blockquote>Opaque quoted bytes.</blockquote>';
    state.state.body = outside;
    await assert.rejects(insertStandaloneSignature(state.office, state.item, current.source.standalone, () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
    assert.equal(state.state.signatureWrites, 1); assert.equal(isStandaloneSignatureInsertionBlocked(state.item), true);
    assert.ok(state.state.body.endsWith(outside));
    await assert.rejects(insertStandaloneSignature(state.office, state.item, current.source.standalone, () => {}, { media: [] }));
    assert.equal(state.state.signatureWrites, 1);
});

test('V30 merged and separate current metadata cannot become visible or contain foreign text', () => {
    for (const profile of ['B', 'M']) {
        const html = current.source.standalone, plan = prepareNativeLayoutRestore(html, standaloneSignatureContract(html), nonce, host(profile));
        const proof = fluidLayoutContract(plan.html), token = 'RT-FLUID-LAYOUT-V16' + profile + ':' + nonce + ':' + current.version;
        const carriers = proof.elements.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'span'
            && plan.html.slice(value.node.openEnd, value.node.close).includes(token));
        assert.equal(carriers.length, 1);
        const visible = replaceOpening(plan.html, carriers[0], opening => appendStyle(opening, 'font-size:1pt;'));
        assert.throws(() => fluidLayoutContract(visible, visible, nonce, current.version), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
        const foreign = plan.html.replace(token, 'Foreign visible text ' + token);
        assert.throws(() => fluidLayoutContract(foreign, foreign, nonce, current.version), error => error.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE');
    }
});

test('V30 write acknowledgement binds the host profile even when M and B have the same large-image geometry', async () => {
    for (const profile of ['B', 'M']) {
        const other = profile === 'B' ? 'M' : 'B';
        const rewrite = html => wordModel(html.replace('RT-FLUID-LAYOUT-V16' + profile + ':', 'RT-FLUID-LAYOUT-V16' + other + ':'));
        const standalone = mailbox(profile, rewrite); standalone.state.body = '<p>Manual bytes.</p>';
        await assert.rejects(insertStandaloneSignature(standalone.office, standalone.item, current.source.standalone, () => {}, { media: [] }), error => error.code === 'SIGNATURE_LAYOUT_UNCONFIRMED');
        assert.equal(standalone.state.signatureWrites, 1); assert.equal(isStandaloneSignatureInsertionBlocked(standalone.item), true);
        assert.equal(signatureHostProfileIsCurrent(standalone.state.body, standalone.state.body, current.version, standalone.office), false);
        const combined = mailbox(profile, rewrite); combined.state.body = '<p>Manual bytes.</p>';
        await assert.rejects(prependTemplate(combined.office, combined.item, current.source.combined, () => {}, { media: [] }), error => error.code === 'TEMPLATE_LAYOUT_UNCONFIRMED');
        assert.equal(combined.state.prependWrites, 1); assert.equal(combined.state.bodyWrites, 0);
    }
});
