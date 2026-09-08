import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DOMParser } from 'linkedom';
import { projectForMailDocument, serializeMailDocumentForSave, resolveMailCanvasResponsiveCss, synchronizeMailSignatureFixedGeometry } from '../../resources/js/mail-builder.js';
import { assertTableOverlapSignature, isTableOverlapSignatureVersion } from '../../resources/js/mail-signature-table-overlap.js';

const original = readFileSync(new URL('../Fixtures/mail/signature-v27.html', import.meta.url), 'utf8').trim();
const options = { kind: 'signature', environment: { DOMParser } };

function mirrored(version) {
    const doc = new DOMParser().parseFromString(`<html><body><table id="fixture"><tbody>${original}</tbody></table></body></html>`, 'text/html');
    const wrapper = doc.querySelector('#fixture');
    wrapper.querySelector('tr').setAttribute('data-rt-artifact-version', version);
    for (const [selector, direction] of [['.rt-sign-content-frame', 'rtl'], ['.rt-v27-image-cell', 'rtl'], ['.rt-sign-content', 'ltr']]) {
        const element = wrapper.querySelector(selector);
        element.setAttribute('dir', direction);
        element.setAttribute('style', `${element.getAttribute('style')};direction:${direction};`);
    }
    for (const selector of ['.rt-v27-image-cell', '.rt-v27-anchor']) wrapper.querySelector(selector).setAttribute('align', 'right');
    return { html: wrapper.querySelector('tbody').innerHTML, doc, wrapper };
}

for (const version of ['v28', 'v29']) {
    test(`${version} editor import-save-import retains directional IMG carrier and canonical tokens`, () => {
        const { html } = mirrored(version);
        const project = projectForMailDocument({ html, builderData: { pages: [{ component: html }] } }, () => [], options);
        const first = serializeMailDocumentForSave({ project, html: project.pages[0].component, baselineHtml: html, ...options });
        assert.match(first.html, new RegExp(`data-rt-artifact-version="${version}"`));
        assert.deepEqual(first.html.match(/\{\{[A-Z0-9_]+\}\}/g), original.match(/\{\{[A-Z0-9_]+\}\}/g));
        assert.equal(first.html.split('{{TRAIN_SRC}}').length - 1, 1);
        assert.doesNotMatch(first.html, /(?:[;"\s])transform:|display:(?:grid|flex)|position:absolute|margin-bottom:-|rt-sign-train-layer|height:200px/);
        const again = projectForMailDocument({ html: first.html, builderData: first.project }, () => [], options);
        assert.equal(serializeMailDocumentForSave({ project: again, html: again.pages[0].component, baselineHtml: first.html, ...options }).html, first.html);
    });

    test(`${version} opts out of legacy height synchronization and requires its own server CSS`, () => {
        let changed = false;
        const root = { getAttributes: () => ({ 'data-rt-artifact-version': version }), parent: () => null };
        const stage = { getAttributes: () => ({ class: 'rt-sign-stage' }), parent: () => root, addStyle: () => { changed = true; } };
        assert.equal(synchronizeMailSignatureFixedGeometry(stage), false);
        assert.equal(changed, false);
        const { doc } = mirrored(version);
        assert.equal(resolveMailCanvasResponsiveCss(doc, { previewResponsiveCssByArtifact: { [version]: { light: `${version}-CSS` } } }), `${version}-CSS`);
        assert.throws(() => resolveMailCanvasResponsiveCss(doc, { previewResponsiveCssByArtifact: { v27: { light: 'old-CSS' } } }), new RegExp(version.toUpperCase()));
    });

    test(`${version} rejects reversed contact direction and CSS mirror substitutes`, () => {
        const { wrapper } = mirrored(version);
        const rows = Array.from(wrapper.querySelector('tbody').children);
        wrapper.querySelector('.rt-sign-content').setAttribute('dir', 'rtl');
        assert.throws(() => assertTableOverlapSignature(wrapper, rows), /links-nach-rechts/);
        wrapper.querySelector('.rt-sign-content').setAttribute('dir', 'ltr');
        wrapper.querySelector('.rt-sign-train').setAttribute('style', 'display:inline-block;transform:scaleX(-1)');
        assert.throws(() => assertTableOverlapSignature(wrapper, rows), /CSS-Spiegelung/);
    });
}

test('table IMG family is explicitly opt-in', () => {
    assert.equal(isTableOverlapSignatureVersion('v27'), true);
    assert.equal(isTableOverlapSignatureVersion('v26'), false);
    assert.equal(isTableOverlapSignatureVersion('v30'), false);
    assert.equal(isTableOverlapSignatureVersion(null), false);
});
