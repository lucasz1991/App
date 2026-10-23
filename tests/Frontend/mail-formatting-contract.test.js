import test from 'node:test';
import assert from 'node:assert/strict';
import { DOMParser, parseHTML } from 'linkedom';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';

import {
    MAIL_SAFE_EDITABLE_STYLE_PROPERTIES,
    protectMailSystemComponents,
    projectForMailDocument,
    serializeMailDocumentForSave,
    synchronizeMailContentImage,
    synchronizeMailPresentationAttributes,
} from '../../resources/js/mail-builder.js';

function component({ tagName = 'td', attributes = {}, style = {}, stylable = true, children = [] } = {}) {
    const state = { tagName, attributes: { ...attributes }, style: { ...style }, stylable };
    let parent = null;
    const model = {
        get: (property) => state[property],
        set(property, value) {
            if (typeof property === 'object') Object.assign(state, property);
            else state[property] = value;
        },
        getAttributes: () => state.attributes,
        addAttributes: (next) => Object.assign(state.attributes, next),
        removeAttributes: (name) => { delete state.attributes[name]; },
        getStyle: () => state.style,
        setStyle: (next) => { state.style = { ...next }; },
        components: () => ({ models: children }),
        parent: () => parent,
        setParent: (next) => { parent = next; },
    };
    children.forEach((child) => child.setParent(model));
    return model;
}

test('table presentation dimensions retain decimal precision, zero and explicit CSS priority', () => {
    const cases = [
        ['width', '33.3333%', '33.3333%'],
        ['width', '200.5px', '200.5'],
        ['width', '.0000001%', '0.0000001%'],
        ['width', '0002.5000PX', '2.5'],
        ['width', '0%', '0%'],
        ['width', '0px', '0'],
        ['width', 0, '0'],
        ['height', '12.75px', '12.75'],
        ['height', '.5px !important', '0.5'],
        ['height', '0.00px', '0'],
        ['height', '0', '0'],
    ];
    for (const [property, value, expected] of cases) {
        const cell = component({ attributes: { [property]: '300' }, style: { [property]: value } });
        assert.equal(synchronizeMailPresentationAttributes(cell, property), true, `${property}:${value}`);
        assert.equal(cell.getAttributes()[property], expected, `${property}:${value}`);
        assert.equal(cell.getStyle()[property], value, 'fallback synchronization must retain authored CSS');
    }
});

test('explicit unrepresentable dimensions remove stale fallbacks while style removal restores originals', () => {
    for (const [property, value] of [
        ['width', 'auto'], ['width', '12em'], ['width', 'calc(100% - 20px)'],
        ['width', '-5px'], ['width', '10000px'], ['width', '1e3px'],
        ['height', '50%'], ['height', 'auto'],
    ]) {
        const cell = component({ attributes: { [property]: '300' }, style: { [property]: value } });
        assert.equal(synchronizeMailPresentationAttributes(cell, property), true, `${property}:${value}`);
        assert(!Object.hasOwn(cell.getAttributes(), property), `${property}:${value}`);
        assert.equal(synchronizeMailPresentationAttributes(cell, property), false, 'repeating the same update is stable');
        cell.getStyle()[property] = '22.5px';
        synchronizeMailPresentationAttributes(cell, property);
        assert.equal(cell.getAttributes()[property], '22.5');
        cell.getStyle()[property] = value;
        synchronizeMailPresentationAttributes(cell, property);
        assert(!Object.hasOwn(cell.getAttributes(), property));
        cell.getStyle()[property] = '';
        synchronizeMailPresentationAttributes(cell, property);
        assert.equal(cell.getAttributes()[property], '300', 'Undo retains the original imported fallback');
    }
    const initiallyAbsent = component({ style: { width: 'auto' } });
    assert.equal(synchronizeMailPresentationAttributes(initiallyAbsent, 'width'), false);
    initiallyAbsent.getStyle().width = '23.5px';
    synchronizeMailPresentationAttributes(initiallyAbsent, 'width');
    initiallyAbsent.getStyle().width = '';
    synchronizeMailPresentationAttributes(initiallyAbsent, 'width');
    assert(!Object.hasOwn(initiallyAbsent.getAttributes(), 'width'));
});

test('dimension changes leave protected signature geometry unchanged', () => {
    for (const className of [
        'rt-sign-stage', 'rt-sign-content-frame', 'rt-sign-train-layer',
        'rt-sign-train-frame', 'rt-sign-train-slot', 'rt-sign-train',
    ]) {
        const node = component({ attributes: { class: className, width: '720', height: '61' }, style: { width: '33.3333%', height: 'auto' } });
        const before = { ...node.getAttributes() };
        assert.equal(synchronizeMailPresentationAttributes(node), false);
        assert.deepEqual(node.getAttributes(), before);
    }
});

test('regular image styles defer inspector geometry without widening existing restrictions', () => {
    const owned = ['width', 'max-width', 'height', 'max-height', 'margin', 'display'];
    for (const stylable of [true, false, [], ['padding', 'border', 'width']]) {
        const image = component({ tagName: 'img', attributes: { src: 'cid:content-image', width: '320', alt: 'Inhalt' }, stylable });
        protectMailSystemComponents({ getWrapper: () => image });
        const expected = stylable === false ? false : MAIL_SAFE_EDITABLE_STYLE_PROPERTIES.filter((property) =>
            !owned.includes(property) && (!Array.isArray(stylable) || stylable.includes(property)));
        assert.deepEqual(image.get('stylable'), expected);
        protectMailSystemComponents({ getWrapper: () => image });
        assert.deepEqual(image.get('stylable'), expected, 'repeated protection cannot widen a restricted image');
        assert.equal(image.getAttributes().src, 'cid:content-image');
    }
});

test('image inspector width and alignment stay proportional and survive a template save/reload', () => {
    const image = component({
        tagName: 'img',
        attributes: { src: 'cid:content-image', width: '600', height: '200', 'data-rt-image-align': 'right', alt: 'Inhalt' },
        style: { width: '100%', 'max-width': '600px', height: 'auto', border: '1px solid #112233' },
    });
    const cell = component({ children: [image] });
    protectMailSystemComponents({ getWrapper: () => cell });
    image.addAttributes({ width: '320' });
    synchronizeMailContentImage(image);
    assert.equal(image.getStyle()['max-width'], '320px');
    assert.equal(image.getStyle().width, '100%');
    assert.equal(image.getStyle().height, 'auto');
    assert.equal(image.getStyle().margin, '0 0 0 auto');
    assert.equal(cell.getAttributes().align, 'right');
    assert.equal(cell.getStyle()['text-align'], 'right');
    assert(!Object.hasOwn(image.getAttributes(), 'height'));

    const style = Object.entries(image.getStyle()).map(([property, value]) => `${property}:${value};`).join('');
    const attributes = Object.entries(image.getAttributes()).map(([name, value]) => `${name}="${value}"`).join(' ');
    const html = `<!doctype html><html><head></head><body><table role="presentation"><tbody><tr><td><img ${attributes} style="${style}"></td></tr>{{SIGNATURE_BLOCK}}</tbody></table></body></html>`;
    const project = projectForMailDocument({ html, builderData: { pages: [{ component: html }] } }, () => [], { kind: 'template', environment: { DOMParser } });
    const saved = serializeMailDocumentForSave({ project, html: project.pages[0].component, kind: 'template', baselineHtml: html, environment: { DOMParser } });
    const reloaded = projectForMailDocument({ html: saved.html, builderData: saved.project }, () => [], { kind: 'template', environment: { DOMParser } });
    const savedAgain = serializeMailDocumentForSave({ project: reloaded, html: reloaded.pages[0].component, kind: 'template', baselineHtml: saved.html, environment: { DOMParser } });
    assert.equal(savedAgain.html, saved.html);
    const storedImage = new DOMParser().parseFromString(savedAgain.html, 'text/html').querySelector('img');
    assert.equal(storedImage.getAttribute('src'), 'cid:content-image');
    assert.equal(storedImage.getAttribute('width'), '320');
    assert.match(storedImage.getAttribute('style'), /max-width:320px/);
    assert.match(storedImage.getAttribute('style'), /height:auto/);
    assert.match(storedImage.getAttribute('style'), /border:1px solid #112233/);
});

test('image geometry restrictions leave system preview and legacy train images intact', () => {
    for (const attributes of [
        { src: 'preview-logo.png', width: '240', 'data-rt-mail-preview-token': 'LOGO_SRC' },
        { src: 'preview-train.gif', width: '720', 'data-rt-mail-preview-token': 'TRAIN_SRC' },
        { src: '{{TRAIN_SRC}}', width: '720', class: 'rt-sign-train', 'data-rt-train': '' },
    ]) {
        const image = component({ tagName: 'img', attributes, style: { width: '125%', height: 'auto' }, stylable: true });
        const beforeStyle = { ...image.getStyle() };
        protectMailSystemComponents({ getWrapper: () => image });
        assert.equal(image.get('stylable'), true);
        assert.deepEqual(image.getAttributes(), attributes);
        assert.deepEqual(image.getStyle(), beforeStyle);
    }
});

test('shipped GrapesJS trait collections render after image or train protection without extra undo entries', () => {
    const { window, document } = parseHTML('<html><head></head><body></body></html>');
    // Linkedom exposes select.value as a getter; native TraitManager uses the
    // browser's writable property when it renders a select trait.
    const selectValue = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value');
    Object.defineProperty(window.HTMLSelectElement.prototype, 'value', {
        ...selectValue,
        set(value) { this.querySelectorAll('option').forEach((option) => { option.selected = option.value === String(value); }); },
    });
    const context = createContext({ console, window, document, navigator: { userAgent: 'node' }, setTimeout: () => 0, clearTimeout: () => {} });
    runInContext(readFileSync(new URL('../../public/vendor/lmz-builder/2.4.5/lmz-builder-core.js', import.meta.url), 'utf8'), context);
    runInContext(`
        var editor = grapesjs.init({ headless:true, storageManager:false, styleManager:{sectors:[]} });
        var nodes = editor.addComponents([
            {type:'image',attributes:{src:'/mail-assets/example.png',alt:'Inhalt',width:'600'}},
            {tagName:'div',attributes:{class:'rt-sign-stage','data-rt-artifact-version':'v26'}},
            {tagName:'div',attributes:{class:'rt-sign-train-layer','data-rt-layer-train':''}}
        ]);
    `, context);
    const editor = context.editor;
    const profile = { breakpoints: { desktop: {} }, desktopHeightMin: 154, heightMin: 0, heightMax: 800, sizes: [100, 125], offsetMin: -100, offsetMax: 100 };
    try {
        // Finish the existing geometry normalization before checking derived
        // trait metadata independently of real content changes.
        protectMailSystemComponents(editor, { imgOverlapProfile: profile });
        const collections = context.nodes.map((node) => node.get('traits'));
        const before = context.nodes.map((node) => JSON.stringify(node.getAttributes()));
        for (let iteration = 0; iteration < 2; iteration += 1) {
            editor.UndoManager.clear();
            protectMailSystemComponents(editor, { imgOverlapProfile: profile });
            assert.equal(editor.UndoManager.getStack().length, 0, 'trait normalization must not add content undo entries');
            context.nodes.forEach((node, index) => {
                assert.equal(Array.isArray(node.traits), false, 'TraitManager requires a collection of trait models');
                assert.equal(node.get('traits'), collections[index], 'unchanged metadata keeps its existing collection');
                node.traits.forEach((trait) => {
                    assert.equal(typeof trait.get, 'function');
                    assert.equal(typeof trait.parent?.initCategory, 'function');
                });
                editor.select(node);
                assert.doesNotThrow(() => editor.TraitManager.render());
                assert.equal(JSON.stringify(node.getAttributes()), before[index]);
            });
        }
        const image = context.nodes[0];
        editor.select(image);
        let attributeChanges = 0;
        image.on('change:attributes', () => { attributeChanges += 1; });
        image.addAttributes({ alt: 'Mailbild Beispiel', width: '320', 'data-rt-image-align': 'center' });
        assert.equal(attributeChanges, 1, 'image edits must propagate through the actual component change listeners');
        assert.equal(image.getAttributes().src, '/mail-assets/example.png');
        assert.doesNotThrow(() => editor.TraitManager.render());
    } finally {
        editor.destroy();
        Object.defineProperty(window.HTMLSelectElement.prototype, 'value', selectValue);
    }
});
