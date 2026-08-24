import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { DOMParser } from 'linkedom';

import {
    MAIL_PREVIEW_DEVICES,
    calculateMailPreviewGeometry,
    closeInitialMailPopovers,
    createMailNavigationController,
    createMailPreviewController,
    hydrateMailCanvasAssets,
    MAIL_EDITOR_MODE,
    MAIL_GJS_OPTIONS,
    MAIL_STYLE_SECTORS,
    parseMailCssProjectStyles,
    protectMailSystemComponents,
    projectForMailDocument,
    rehydrateAuthoritativeMailProject,
    restartMailCanvasAnimations,
    resolveMailPreviewDevice,
    serializeMailDocumentForSave,
    serializeMailProjectStyles,
    synchronizeMailSignatureFixedGeometry,
    synchronizeMailTrainLayerAlignment,
} from '../../resources/js/mail-builder.js';
import { createMailBlocks, mailCanvasStyles } from '../../resources/js/mail-builder-blocks.js';

const canonicalTrain = '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto 0 0;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;"><table class="rt-sign-train-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;"><tbody><tr><td class="rt-sign-train-slot" height="200" valign="bottom" style="height:200px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></td></tr></tbody></table></div>';
const canonicalSignatureStage = (content = '') => '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">'
    + canonicalTrain
    + '<table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">'
    + `<tbody><tr><td>${content}</td></tr></tbody></table>`
    + '</div>';

test('LMZ traits and mail protection do not recurse through component updates', () => {
    const lmzBuilderSource = readFileSync(
        new URL('../../public/vendor/lmz-builder/2.4.5/lmz-builder.js', import.meta.url),
        'utf8',
    );
    const mailBuilderSource = readFileSync(
        new URL('../../resources/js/mail-builder.js', import.meta.url),
        'utf8',
    );
    const masterLayoutSource = readFileSync(
        new URL('../../resources/views/layouts/master.blade.php', import.meta.url),
        'utf8',
    );
    const motionTraitSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf('function addMotionTraits'),
        lmzBuilderSource.indexOf('function getEmbedCatalogItems'),
    );
    const motionSetupSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf('function setupMotionTraits'),
        lmzBuilderSource.indexOf('function setupSharedElementPreview'),
    );
    const mailUpdateSource = mailBuilderSource.slice(
        mailBuilderSource.indexOf('const onComponentUpdate ='),
        mailBuilderSource.indexOf("editor.on?.('component:add'"),
    );

    assert.match(motionTraitSource, /component\.get\?\.\('traits'\)/);
    assert.doesNotMatch(motionTraitSource, /component\.getTrait\?/);
    assert.doesNotMatch(motionSetupSource, /component:update/);
    assert.match(mailUpdateSource, /synchronizeMailTrainLayerAlignment\(component\)/);
    assert.match(mailUpdateSource, /synchronizeMailContentImage\(component\)/);
    assert.doesNotMatch(mailUpdateSource, /protectMailSystemComponents/);
    assert.equal(masterLayoutSource.split("'resources/js/app.js'").length - 1, 1);
    assert.equal(
        masterLayoutSource.indexOf("'resources/js/app.js'") < masterLayoutSource.indexOf('</head>'),
        true,
    );
    const bodyStart = masterLayoutSource.search(/<body\s/);
    assert.notEqual(bodyStart, -1);
    assert.doesNotMatch(masterLayoutSource.slice(bodyStart), /resources\/js\/app\.js/);
});

test('mail canvas renders lightweight same-origin token assets in light and dark without mutating config', () => {
    const previewAssets = {
        light: {
            logo: 'https://app.rail-time.test/mail-assets/light-logo.gif',
            mark: 'https://app.rail-time.test/mail-assets/light-mark.gif',
            train: 'https://app.rail-time.test/mail-assets/light-train.gif',
        },
        dark: {
            logo: 'https://app.rail-time.test/mail-assets/dark-logo.gif',
            mark: 'https://app.rail-time.test/mail-assets/dark-mark.gif',
            train: 'https://app.rail-time.test/mail-assets/dark-train.gif',
        },
        icons: {
            phone: 'https://app.rail-time.test/mail-assets/phone-icon.png',
            mobile: 'https://app.rail-time.test/mail-assets/mobile-icon.png',
            email: 'https://app.rail-time.test/mail-assets/email-icon.png',
            web: 'https://app.rail-time.test/mail-assets/web-icon.png',
            location: 'https://app.rail-time.test/mail-assets/location-icon.png',
        },
    };
    const snapshot = structuredClone(previewAssets);

    const responsiveCss = '@media only screen and (max-width: 860px) { tr.rt-stack > td { display:block !important; width:100% !important; } }';
    const light = mailCanvasStyles('light', previewAssets, responsiveCss);
    const dark = mailCanvasStyles('dark', previewAssets);

    assert.match(light, /\[bgcolor="\{\{PAGE_BG\}\}"\]/);
    assert.match(light, /#e7eaed/);
    assert.match(light, /mail-assets\/light-logo\.gif/);
    assert.doesNotMatch(light, /mail-assets\/light-train\.gif/);
    assert.match(light, /mail-assets\/phone-icon\.png/);
    assert.match(light, /mail-assets\/location-icon\.png/);
    assert.match(light, /max-width:\s*860px/);
    assert.match(light, /tr\.rt-stack > td/);
    assert.match(dark, /#070a0e/);
    assert.match(dark, /mail-assets\/dark-logo\.gif/);
    assert.doesNotMatch(dark, /mail-assets\/dark-train\.gif/);
    assert.doesNotMatch(dark, /light-logo/);
    assert.deepEqual(previewAssets, snapshot);
});

test('global mail replay restarts every animated token without touching component models', () => {
    const nextFrames = [];
    const previousFrame = globalThis.requestAnimationFrame;
    globalThis.requestAnimationFrame = (callback) => {
        nextFrames.push(callback);
        return nextFrames.length;
    };

    try {
        const component = (token, renderedSource, children = []) => {
            const model = {
                src: 'data:image/png;base64,neutral-model-pixel',
                'data-rt-mail-preview-token': token,
            };
            const element = {
                tagName: 'IMG',
                src: renderedSource,
                dataset: {},
                getAttribute: (name) => name === 'src' ? element.src : null,
                setAttribute: (name, value) => { if (name === 'src') element.src = value; },
                removeAttribute: (name) => { if (name === 'src') element.src = ''; },
                ownerDocument: { baseURI: 'https://app.rail-time.test/' },
            };

            return {
                components: () => ({ models: children }),
                get: (key) => model[key],
                getAttributes: () => model,
                getStyle: () => ({}),
                getEl: () => element,
                snapshot: () => structuredClone(model),
                rendered: () => element.src,
            };
        };
        const logo = component('LOGO_SRC', 'data:image/gif;base64,logo');
        const mark = component('ICON_RT_SRC', 'data:image/gif;base64,mark');
        const train = component('TRAIN_SRC', 'data:image/gif;base64,train');
        const plain = component('ICON_EMAIL_SRC', 'data:image/png;base64,email');
        const root = component('', '', [logo, mark, train, plain]);
        const snapshots = [logo.snapshot(), mark.snapshot(), train.snapshot(), plain.snapshot()];

        assert.equal(restartMailCanvasAnimations({ getWrapper: () => root }, { nonce: 41 }), 3);
        assert.deepEqual([logo.snapshot(), mark.snapshot(), train.snapshot(), plain.snapshot()], snapshots);
        assert.equal(logo.rendered(), '');
        assert.equal(mark.rendered(), '');
        assert.equal(train.rendered(), '');
        nextFrames.splice(0).forEach((callback) => callback());
        assert.equal(logo.rendered(), 'data:image/gif;base64,logo#_rt_preview_restart=41-0');
        assert.equal(mark.rendered(), 'data:image/gif;base64,mark#_rt_preview_restart=41-1');
        assert.equal(train.rendered(), 'data:image/gif;base64,train#_rt_preview_restart=41-2');
    } finally {
        globalThis.requestAnimationFrame = previousFrame;
    }
});

test('signature project gets a valid editor-only table and a reversible train image', () => {
    const source = {
        pages: [{
            component: `<tr><td class="rt-sign-cell">${canonicalSignatureStage('<img src="{{LOGO_SRC}}" alt="{{FIRMENNAME}}"><img src="{{ICON_EMAIL_SRC}}"><img src="{{ICON_LOCATION_SRC}}">')}</td></tr><tr><td style="color:{{SIGNATURE_LEGAL_TEXT}}">Rechtliches</td></tr>`,
        }],
        styles: [],
    };
    const draft = { builderData: structuredClone(source), css: '' };
    const project = projectForMailDocument(draft, () => [], {
        kind: 'signature',
        environment: { DOMParser },
    });
    const component = project.pages[0].component;

    assert.match(component, /^<table [^>]*data-rt-mail-signature-canvas="true"/);
    assert.match(component, /data-rt-mail-preview-token="LOGO_SRC"/);
    assert.match(component, /data-rt-mail-preview-token="ICON_EMAIL_SRC"/);
    assert.match(component, /data-rt-mail-preview-token="ICON_LOCATION_SRC"/);
    assert.match(component, /data-rt-mail-preview-token="TRAIN_SRC"/);
    assert.match(component, /class="rt-sign-train"[^>]*data-rt-train/);
    assert.doesNotMatch(component, /data-rt-mail-preview-only="train"/);
    assert.doesNotMatch(component, /src="\{\{LOGO_SRC\}\}"/);
    assert.doesNotMatch(component, /\{\{TRAIN_SRC\}\}/);
    assert.deepEqual(draft.builderData, source);
});

test('schema 22 train overlaps migrate deterministically to the schema 25 pixel frame', () => {
    const legacyTrain = '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="position:relative;left:0;right:auto;top:auto;bottom:auto;width:100%;max-width:1815px;margin:0 auto 0 0;margin-bottom:-150px;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:top;mso-hide:all;"></div>';
    const schema24Train = '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="display:block;width:100%;max-width:1815px;margin:0 auto 0 0;margin-bottom:-7.3611%;overflow:hidden;font-size:0;line-height:0;text-align:left;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:top;mso-hide:all;"></div>';
    const legacyStage = (train) => canonicalSignatureStage('Inhalt').replace(canonicalTrain, train);
    const schema24Stage = '<div class="rt-sign-stage" style="position:relative;overflow:hidden;">'
        + schema24Train
        + '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody><tr><td>Inhalt</td></tr></tbody></table>'
        + '</div>';
    const variants = [
        {
            label: 'without overlap',
            stage: legacyStage(legacyTrain.replace('margin-bottom:-150px;', '')),
            schema: 22,
        },
        {
            label: 'custom overlap',
            stage: legacyStage(legacyTrain.replace('margin-bottom:-150px;', 'margin-bottom:-72px;')),
            schema: 22,
        },
        {
            label: 'authentic schema 24 without position',
            stage: schema24Stage,
            schema: 24,
        },
        {
            label: 'stale schema 25 metadata with repairable schema 24 markup',
            stage: schema24Stage,
            schema: 25,
        },
    ];

    variants.forEach(({ label, stage, schema }) => {
        const original = `<tr><td class="rt-sign-cell">${stage}</td></tr>`
            + '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>';
        const project = projectForMailDocument({
            builderData: {
                pages: [{ component: original }],
                styles: [],
                railtime: { document: 'signature', schema },
            },
            css: '',
        }, () => [], { kind: 'signature', environment: { DOMParser } });
        const outgoing = serializeMailDocumentForSave({
            project,
            html: project.pages[0].component,
            kind: 'signature',
            environment: { DOMParser },
        });
        const document_ = new DOMParser().parseFromString(
            `<table><tbody>${outgoing.html}</tbody></table>`,
            'text/html',
        );
        const trainLayer = document_.querySelector('td.rt-sign-cell > div.rt-sign-stage > div.rt-sign-train-layer');

        assert.ok(trainLayer, label);
        assert.equal(trainLayer.style.position || '', '', label);
        assert.equal(trainLayer.style.bottom || '', '', label);
        assert.equal(trainLayer.style.margin, '0 auto 0 0', label);
        assert.equal(trainLayer.style.height, '200px', label);
        assert.equal(trainLayer.style.maxHeight, '200px', label);
        assert.equal(trainLayer.style.marginBottom, '-200px', label);
        assert.equal(trainLayer.querySelector('table.rt-sign-train-frame')?.getAttribute('height'), '200', label);
        assert.equal(trainLayer.querySelector('td.rt-sign-train-slot')?.getAttribute('valign'), 'bottom', label);
        assert.equal(outgoing.project.railtime.schema, 25, label);
    });
});

test('signature preview hydrates the train image and roundtrips two canonical rows', () => {
    const v8Stage = canonicalSignatureStage('<span data-rt-mail-block="paragraph" data-rt-mail-text="secondary">Inhalt</span><img src="{{LOGO_SRC}}">')
        .replace('data-rt-layer-mobile="train"', 'data-rt-layer-mobile="stop65"');
    const original = `<tr data-rt-artifact-version="v8"><td class="rt-sign-cell">${v8Stage}</td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td style="color:{{SIGNATURE_LEGAL_TEXT}}"><img src="{{ICON_EMAIL_SRC}}"></td></tr>`;
    const project = projectForMailDocument({
        builderData: { pages: [{ component: original }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });

    assert.equal((project.pages[0].component.match(/data-rt-mail-preview-token="TRAIN_SRC"/g) || []).length, 1);
    assert.equal((project.pages[0].component.match(/data-rt-mail-preview-only="train"/g) || []).length, 0);
    assert.equal((project.pages[0].component.match(/RT_SIGNATURE_MAIN_END/g) || []).length, 1);
    const outgoing = serializeMailDocumentForSave({
        project,
        html: project.pages[0].component,
        kind: 'signature',
        environment: { DOMParser },
    });

    assert.equal((outgoing.html.match(/\{\{TRAIN_SRC\}\}/g) || []).length, 1);
    assert.equal((outgoing.html.match(/^<tr|\n<tr/g) || []).length, 2);
    assert.equal((outgoing.html.match(/<!-- RT_SIGNATURE_MAIN_END -->/g) || []).length, 1);
    assert.match(outgoing.html, /<\/tr>\n<!-- RT_SIGNATURE_MAIN_END -->\n<tr>/);
    assert.match(outgoing.html, /class="rt-sign-cell"/);
    assert.match(outgoing.html, /<span>Inhalt<\/span>/);
    assert.doesNotMatch(outgoing.html, /data-rt-mail-/);
    assert.match(outgoing.html, /class="rt-sign-train"[^>]*data-rt-train[^>]*src="\{\{TRAIN_SRC\}\}"/);
    assert.doesNotMatch(outgoing.html, /data:image\//);
    const outgoingDocument = new DOMParser().parseFromString(
        `<table><tbody>${outgoing.html}</tbody></table>`,
        'text/html',
    );
    const outgoingStage = outgoingDocument.querySelector('td.rt-sign-cell > div.rt-sign-stage');
    const outgoingLayer = outgoingStage?.querySelector(':scope > div.rt-sign-train-layer[data-rt-layer-train]');
    const outgoingFrame = outgoingLayer?.querySelector(':scope > table.rt-sign-train-frame');
    const outgoingSlot = outgoingFrame?.querySelector('td.rt-sign-train-slot');
    const outgoingTrain = outgoingSlot?.querySelector(':scope > img.rt-sign-train[data-rt-train]');
    const outgoingContent = outgoingStage?.querySelector(':scope > table.rt-sign-content-frame');
    assert.ok(outgoingStage, 'the signature must retain its canonical relative stage');
    assert.equal(outgoingDocument.querySelector('tr[data-rt-artifact-version="v8"]') !== null, true);
    assert.equal(outgoingLayer.getAttribute('data-rt-layer-mobile'), 'stop65');
    assert.equal(outgoingStage.getAttribute('style'), 'position:relative;height:200px;max-height:200px;overflow:hidden;');
    assert.equal(outgoingContent.style.marginBottom || '', '');
    assert.equal(outgoingStage.firstElementChild, outgoingLayer);
    assert.equal(
        outgoingLayer.getAttribute('style'),
        'display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto 0 0;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;',
    );
    assert.equal(outgoingFrame.getAttribute('height'), '200');
    assert.equal(outgoingFrame.getAttribute('style'), 'width:100%;height:200px;border-collapse:collapse;');
    assert.equal(outgoingSlot.getAttribute('height'), '200');
    assert.equal(outgoingSlot.getAttribute('valign'), 'bottom');
    assert.equal(outgoingTrain.getAttribute('width'), '720');
    assert.equal(
        outgoingTrain.getAttribute('style'),
        'position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;',
    );
    assert.equal(outgoingStage.lastElementChild, outgoingContent);
    assert.equal(outgoingLayer.lastElementChild, outgoingFrame);
    assert.equal(outgoingSlot.lastElementChild, outgoingTrain);
    assert.match(outgoing.html, /<\/table><\/div><\/td><\/tr>\n<!-- RT_SIGNATURE_MAIN_END -->\n<tr>/);
    assert.equal(outgoing.project.pages[0].component, outgoing.html);

    // GrapesJS-/DOM-Serialisierer duerfen Kommentare verlieren. Genau dieser
    // bekannte Verlust wird kanonisch repariert, ohne den PHP-Vertrag zu
    // lockern oder einen zweiten Zug einzufuehren.
    const withoutMainMarker = project.pages[0].component.replace('<!-- RT_SIGNATURE_MAIN_END -->', '');
    const restored = serializeMailDocumentForSave({
        project,
        html: withoutMainMarker,
        kind: 'signature',
        environment: { DOMParser },
    });
    assert.equal((restored.html.match(/<!-- RT_SIGNATURE_MAIN_END -->/g) || []).length, 1);
});

test('signature contact rows survive the element-only GrapesJS roundtrip as exact marker siblings', () => {
    const content = ''
        + '<table class="rt-contact">'
        + '<!-- RT_PHONE_START --><tr><td>{{DURCHWAHL}}</td></tr><!-- RT_PHONE_END -->'
        + '<!-- RT_MOBILE_START --><tr><td>{{MOBIL}}</td></tr><!-- RT_MOBILE_END -->'
        + '</table>'
        + '<table class="rt-contact rt-company-contact">'
        + '<!-- RT_COMPANY_PHONE_START --><tr><td>{{FIRMEN_TELEFON}}</td></tr><!-- RT_COMPANY_PHONE_END -->'
        + '<!-- RT_COMPANY_EMAIL_START --><tr><td>{{FIRMEN_EMAIL}}</td></tr><!-- RT_COMPANY_EMAIL_END -->'
        + '<!-- RT_WEBSITE_START --><tr><td>{{FIRMEN_WEBSITE_LABEL}}</td></tr><!-- RT_WEBSITE_END -->'
        + '</table><img src="{{LOGO_SRC}}">';
    const original = `<tr><td class="rt-sign-cell">${canonicalSignatureStage(content)}</td></tr>`
        + '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: original }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });
    const editorHtml = project.pages[0].component;

    // GrapesJS serialisiert verlaesslich Elemente und Attribute, nicht aber
    // Kommentare zwischen Tabellenzeilen. Im Canvas existieren deshalb nur
    // die fuenf gebundenen sichtbaren Zeilen.
    assert.equal((editorHtml.match(/data-rt-mail-contact-marker=/g) || []).length, 5);
    assert.doesNotMatch(editorHtml, /RT_(?:PHONE|MOBILE|WEBSITE|COMPANY_PHONE|COMPANY_EMAIL)_(?:START|END)/);

    const outgoing = serializeMailDocumentForSave({
        project,
        html: editorHtml,
        kind: 'signature',
        baselineHtml: original,
        environment: { DOMParser },
    });
    const markerTokens = {
        PHONE: '{{DURCHWAHL}}',
        MOBILE: '{{MOBIL}}',
        COMPANY_PHONE: '{{FIRMEN_TELEFON}}',
        COMPANY_EMAIL: '{{FIRMEN_EMAIL}}',
        WEBSITE: '{{FIRMEN_WEBSITE_LABEL}}',
    };
    Object.entries(markerTokens).forEach(([marker, token]) => {
        assert.equal((outgoing.html.match(new RegExp(`<!-- RT_${marker}_START -->`, 'g')) || []).length, 1);
        assert.equal((outgoing.html.match(new RegExp(`<!-- RT_${marker}_END -->`, 'g')) || []).length, 1);
        assert.match(
            outgoing.html,
            new RegExp(`<!-- RT_${marker}_START -->\\s*<tr[^>]*>[\\s\\S]*?${token.replace(/[{}]/g, '\\$&')}[\\s\\S]*?<\\/tr>\\s*<!-- RT_${marker}_END -->`),
        );
    });
    assert.doesNotMatch(outgoing.html, /data-rt-mail-contact-marker/);

    const withoutBinding = editorHtml.replace(' data-rt-mail-contact-marker="PHONE"', '');
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: withoutBinding,
        kind: 'signature',
        baselineHtml: original,
        environment: { DOMParser },
    }), /Kontaktzeilen/);

    const duplicatedBinding = editorHtml.replace(
        'data-rt-mail-contact-marker="MOBILE"',
        'data-rt-mail-contact-marker="PHONE"',
    );
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: duplicatedBinding,
        kind: 'signature',
        baselineHtml: original,
        environment: { DOMParser },
    }), /verschoben, vervielfacht|unvollstaendig/);

    const movedBinding = editorHtml
        .replace('data-rt-mail-contact-marker="PHONE"', 'data-rt-mail-contact-marker="TEMP"')
        .replace('data-rt-mail-contact-marker="COMPANY_PHONE"', 'data-rt-mail-contact-marker="PHONE"')
        .replace('data-rt-mail-contact-marker="TEMP"', 'data-rt-mail-contact-marker="COMPANY_PHONE"');
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: movedBinding,
        kind: 'signature',
        baselineHtml: original,
        environment: { DOMParser },
    }), /verschoben, vervielfacht|veraendert/);

    const nearMiss = editorHtml.replace('</table>', '<!-- RT_PHONE_START_EXTRA --></table>');
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: nearMiss,
        kind: 'signature',
        baselineHtml: original,
        environment: { DOMParser },
    }), /Kontaktmarker/);

    const extraSibling = original.replace(
        '{{DURCHWAHL}}</td></tr><!-- RT_PHONE_END -->',
        '{{DURCHWAHL}}</td></tr><tr><td>Zusatzzeile</td></tr><!-- RT_PHONE_END -->',
    );
    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: extraSibling }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /genau eine direkte Tabellenzeile/);

    const fosterParentedSibling = original.replace(
        '<!-- RT_PHONE_START --><tr>',
        '<!-- RT_PHONE_START --><div>Fremdinhalt</div><tr>',
    );
    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: fosterParentedSibling }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /genau eine direkte Tabellenzeile/);

    const nonBreakingSibling = original.replace(
        '<!-- RT_PHONE_START --><tr>',
        '<!-- RT_PHONE_START -->&nbsp;<tr>',
    );
    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: nonBreakingSibling }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /genau eine direkte Tabellenzeile/);

    const tokenOnlyInAttribute = original.replace(
        '<!-- RT_PHONE_START --><tr><td>{{DURCHWAHL}}</td></tr>',
        '<!-- RT_PHONE_START --><tr title="{{DURCHWAHL}}"><td>Kein Werttoken</td></tr>',
    );
    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: tokenOnlyInAttribute }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /genau eine direkte Tabellenzeile/);

    const quotedTagEnd = original.replace(
        '<!-- RT_PHONE_START --><tr>',
        '<!-- RT_PHONE_START --><tr title="Durchwahl > Zentrale">',
    );
    const quotedProject = projectForMailDocument({
        builderData: { pages: [{ component: quotedTagEnd }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });
    const quotedOutgoing = serializeMailDocumentForSave({
        project: quotedProject,
        html: quotedProject.pages[0].component,
        kind: 'signature',
        baselineHtml: quotedTagEnd,
        environment: { DOMParser },
    });
    assert.match(quotedOutgoing.html, /<tr title="Durchwahl > Zentrale">/);
    assert.match(quotedOutgoing.html, /<!-- RT_PHONE_START -->\s*<tr/);
});

test('signature save fails closed when a preview marker is removed', () => {
    const original = `<tr><td class="rt-sign-cell">${canonicalSignatureStage('<img src="{{LOGO_SRC}}">')}</td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>`;
    const project = projectForMailDocument({
        builderData: { pages: [{ component: original }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });

    const withoutTrainMarker = project.pages[0].component.replace(' data-rt-mail-preview-token="TRAIN_SRC"', '');
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: withoutTrainMarker,
        kind: 'signature',
        environment: { DOMParser },
    }), /(?:Tabellenstruktur|Vorschauwerte|Zugmotiv)/);

    const withoutLogoMarker = project.pages[0].component.replace('data-rt-mail-preview-token="LOGO_SRC" ', '');
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: withoutLogoMarker,
        kind: 'signature',
        environment: { DOMParser },
    }), /Vorschauwerte/);

    const withSecondTrainImage = project.pages[0].component.replace(
        '<tr><td>Rechtliches</td></tr>',
        '<tr data-rt-mail-preview-only="train"><td><img data-rt-train src="x.gif"></td></tr><tr><td>Rechtliches</td></tr>',
    );
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: withSecondTrainImage,
        kind: 'signature',
        environment: { DOMParser },
    }), /alte Zugvorschauzeile/);

    const duplicatedMainMarker = project.pages[0].component.replace(
        '<tr><td>Rechtliches</td></tr>',
        '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>',
    );
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: duplicatedMainMarker,
        kind: 'signature',
        environment: { DOMParser },
    }), /Hauptsignatur-Marker/);

    const changedMainMarker = project.pages[0].component.replace(
        '<!-- RT_SIGNATURE_MAIN_END -->',
        '<!-- RT_SIGNATURE_MAIN_END_EXTRA -->',
    );
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: changedMainMarker,
        kind: 'signature',
        environment: { DOMParser },
    }), /Hauptsignatur-Marker/);

    const injectedTemplatePreview = project.pages[0].component.replace(
        '<tr><td>Rechtliches</td></tr>',
        '<tr><td data-rt-mail-signature-preview="true">Rechtliches</td></tr>',
    );
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: injectedTemplatePreview,
        kind: 'signature',
        environment: { DOMParser },
    }), /Vorschauwerte/);
});

test('signature load fails closed for a second or displaced train binding', () => {
    const duplicateStage = canonicalSignatureStage('Inhalt').replace(canonicalTrain, canonicalTrain + canonicalTrain);
    const duplicateTrain = `<tr><td class="rt-sign-cell">${duplicateStage}</td></tr><tr><td>Rechtliches</td></tr>`;
    const displacedTrain = '<tr><td class="rt-sign-cell"><div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">'
        + '<table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;"><tbody><tr><td>Inhalt</td></tr></tbody></table></div>'
        + `</td><td>${canonicalTrain}</td></tr><tr><td>Rechtliches</td></tr>`;

    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: duplicateTrain }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /genau ein/);
    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: displacedTrain }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /IMG-Zug|Zug-\/Inhaltsreihenfolge/);
});

test('GrapesJS inline import rules are merged in cascade order without touching user CSS', () => {
    const transparent = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
    const editorTrain = canonicalTrain.replace(
        'src="{{TRAIN_SRC}}"',
        `data-rt-mail-preview-token="TRAIN_SRC" src="${transparent}"`,
    );
    const editorStage = canonicalSignatureStage('<span class="c777 c101 c102" data-rt-mail-inline-source="s1" style="padding:9px;">Inhalt</span>')
        .replace(canonicalTrain, editorTrain);
    const html = '<table data-rt-mail-signature-canvas="true"><tbody>'
        + `<tr><td class="rt-sign-cell">${editorStage}</td></tr>`
        + '<tr><td class="c777">Rechtliches</td></tr>'
        + '</tbody></table>';
    const project = {
        pages: [{ component: html }],
        styles: [
            { selectors: ['c101'], style: { padding: '2px', color: '{{SIGNATURE_TEXT_PRIMARY}}' } },
            { selectors: ['c102'], style: { color: '#123456', 'font-size': '12px' } },
            { selectors: ['c777'], style: { color: '#abcdef' } },
            { selectors: ['rt-user'], style: { color: '{{SIGNATURE_TEXT_MUTED}}' } },
            { selectorsAdd: '.c999 .child', style: { color: '#111820' } },
            { selectors: ['rt-user'], style: { display: 'block' }, atRuleType: 'media', mediaText: 'only screen and (max-width: 620px)' },
        ],
    };

    const outgoing = serializeMailDocumentForSave({
        project,
        html,
        css: `data:${transparent}`,
        kind: 'signature',
        environment: { DOMParser },
    });

    assert.match(outgoing.html, /class="c777"/);
    assert.match(outgoing.html, /padding:9px;/);
    assert.match(outgoing.html, /color:#123456;/);
    assert.match(outgoing.html, /font-size:12px;/);
    assert.match(outgoing.html, /class="rt-sign-train"[^>]*data-rt-train[^>]*src="\{\{TRAIN_SRC\}\}"/);
    assert.match(outgoing.html, /class="c777"/);
    assert.doesNotMatch(outgoing.html, /c101|c102|data-rt-mail|about:blank|data:image/);
    assert.match(outgoing.css, /\.c777\{color:#abcdef;\}/);
    assert.match(outgoing.css, /\.rt-user\{color:\{\{SIGNATURE_TEXT_MUTED\}\};\}/);
    assert.match(outgoing.css, /\.c999 \.child\{color:#111820;\}/);
    assert.match(outgoing.css, /@media only screen and \(max-width: 620px\)\{\.rt-user\{display:block;\}\}/);
    assert.equal(outgoing.project.styles.length, 4);
});

test('CSS token sentinels survive GrapesJS parsing and never persist', () => {
    let parsedSource = '';
    const parsed = parseMailCssProjectStyles(
        '.rt-shell{color:{{TEXT_PRIMARY}};border:1px solid {{BORDER}}}',
        (css) => {
            parsedSource = css;
            return [{
                selectors: ['rt-shell'],
                style: {
                    color: 'aliceblue',
                    'border-top-color': 'antiquewhite',
                    'border-right-color': 'antiquewhite',
                },
            }];
        },
    );
    const serialized = serializeMailProjectStyles(parsed);

    assert.doesNotMatch(parsedSource, /\{\{/);
    assert.match(serialized, /color:\{\{TEXT_PRIMARY\}\}/);
    assert.match(serialized, /border-top-color:\{\{BORDER\}\}/);
    assert.match(serialized, /border-right-color:\{\{BORDER\}\}/);
    assert.doesNotMatch(serialized, /aliceblue|antiquewhite|rt-mail-bound/);
});

test('GrapesJS border and background expansions collapse to sanitizer-safe mail shorthands', () => {
    const parsed = parseMailCssProjectStyles(
        '.x{border-bottom:1px solid {{SIGNATURE_BORDER}};background:{{SIGNATURE_BG}}}',
        () => [{
            selectors: ['x'],
            style: {
                'border-bottom-width': '1px',
                'border-bottom-style': 'solid',
                'border-bottom-color': 'aliceblue',
                'background-image': 'initial',
                'background-position-x': 'initial',
                'background-position-y': 'initial',
                'background-size': 'initial',
                'background-repeat': 'initial',
                'background-attachment': 'initial',
                'background-origin': 'initial',
                'background-clip': 'initial',
                'background-color': 'antiquewhite',
            },
        }],
    );
    const serialized = serializeMailProjectStyles(parsed);

    assert.equal(
        serialized,
        '.x{border-bottom:1px solid {{SIGNATURE_BORDER}};background:{{SIGNATURE_BG}};}',
    );
    assert.doesNotMatch(serialized, /border-bottom-(?:width|style|color)|background-(?:position-[xy]|attachment|origin|clip)/);
});

test('template preview preserves its shell and restores image tokens plus exactly one signature slot', () => {
    const canonical = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>RailTime</title><style>.rt-shell{width:100%}</style></head><body bgcolor="#fff"><table class="rt-shell"><tbody><tr><td>Inhalt<img src="{{ICON_RT_SRC}}" alt=""></td></tr>{{SIGNATURE_BLOCK}}</tbody></table></body></html>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: canonical }], styles: [] },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });

    assert.doesNotMatch(project.pages[0].component, /<!doctype|<html|<head/i);
    assert.match(project.pages[0].component, /data-rt-mail-preview-only="signature"/);
    assert.match(project.pages[0].component, /data-rt-mail-preview-token="ICON_RT_SRC"/);
    assert.match(project.pages[0].component, /src="data:image\/png;base64,/);
    assert.doesNotMatch(project.pages[0].component, /src="\{\{ICON_RT_SRC\}\}"/);

    const outgoing = serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace('Inhalt', 'Bearbeitet'),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    });

    assert.match(outgoing.html, /^<!doctype html>/i);
    assert.match(outgoing.html, /<html lang="de">/i);
    assert.match(outgoing.html, /<head><meta charset="utf-8"><title>RailTime<\/title><style>/i);
    assert.match(outgoing.html, /Bearbeitet/);
    assert.equal((outgoing.html.match(/\{\{SIGNATURE_BLOCK\}\}/g) || []).length, 1);
    assert.equal((outgoing.html.match(/src="\{\{ICON_RT_SRC\}\}"/g) || []).length, 1);
    assert.doesNotMatch(outgoing.html, /data-rt-mail-(?:preview(?:-[\w-]+)?|signature-preview)/);
    assert.doesNotMatch(outgoing.html, /data:image\//);
    assert.equal(outgoing.project.pages[0].component, outgoing.html);
});

test('canonical template mark and Outlook still survive normalized or comment-free header roundtrips', () => {
    const canonical = readFileSync(
        new URL('../../resources/mail-templates/email-master.html', import.meta.url),
        'utf8',
    );
    const start = '<!-- RT_TEMPLATE_MARK_START -->';
    const end = '<!-- RT_TEMPLATE_MARK_END -->';
    const fragment = (html) => {
        const from = html.indexOf(start);
        const to = html.indexOf(end);
        return from < 0 || to <= from ? '' : html.slice(from, to + end.length);
    };
    const expectedMark = fragment(canonical);
    const project = projectForMailDocument({
        // `html` bleibt fuer alte, nur-Body enthaltende builder_data die
        // autoritative vollstaendige Serverfassung.
        html: canonical,
        builderData: {
            pages: [{ component: '<table><tbody><tr><td>Legacy-Body</td></tr></tbody></table>' }],
            styles: [],
        },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });

    assert.equal(fragment(project.pages[0].component) !== '', true);
    assert.doesNotMatch(project.pages[0].component, /Legacy-Body/);
    assert.doesNotMatch(project.pages[0].component, /\{\{APPLICATION_CONTENT\}\}/);
    assert.match(project.pages[0].component, /data-rt-mail-preview-only="application"/);

    const normalized = serializeMailDocumentForSave({
        project,
        html: project.pages[0].component,
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    });
    assert.equal(fragment(normalized.html), expectedMark);
    const applicationStart = normalized.html.indexOf('<!-- RT_APPLICATION_CONTENT_START -->');
    const applicationSlot = normalized.html.indexOf('{{APPLICATION_CONTENT}}');
    const applicationEnd = normalized.html.indexOf('<!-- RT_APPLICATION_CONTENT_END -->');
    assert.equal(applicationStart < applicationSlot && applicationSlot < applicationEnd, true);
    assert.doesNotMatch(normalized.html, /data-rt-mail-preview-only="application"/);

    const projectedMark = fragment(project.pages[0].component);
    const withoutHeaderComments = project.pages[0].component.replace(
        projectedMark,
        projectedMark.replace(/<!--[\s\S]*?-->/g, ''),
    );
    const restored = serializeMailDocumentForSave({
        project,
        html: withoutHeaderComments,
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    });
    assert.equal(fragment(restored.html), expectedMark);
    assert.equal((restored.html.match(/\{\{ICON_RT_SRC\}\}/g) || []).length, 1);
    assert.equal((restored.html.match(/\{\{ICON_RT_STILL_SRC\}\}/g) || []).length, 1);

    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace('ICON_RT_STILL_SRC', 'ICON_RT_STILL_SOURCE'),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /Outlook-Standbild/);
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace(start, ''),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /RT-Headervertrag/);
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace(
            'data-rt-mail-preview-only="application"',
            'data-rt-mail-preview-only="application-moved"',
        ),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /Anwendungsslot/);
});

test('built-in mail blocks keep content and formatting without persisting editor metadata', () => {
    const canonical = '<!doctype html><html lang="de"><head><style>.rt-shell{width:100%}</style></head><body><table class="rt-shell"><tbody><tr><td>Inhalt<img src="{{ICON_RT_SRC}}" alt=""></td></tr>{{SIGNATURE_BLOCK}}</tbody></table></body></html>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: canonical }], styles: [] },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });
    const paragraph = createMailBlocks()
        .find((block) => block.id === 'rt-mail-paragraph')
        .definition.content;
    const edited = project.pages[0].component.replace('Inhalt', `Inhalt${paragraph}`);
    const outgoing = serializeMailDocumentForSave({
        project,
        html: edited,
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    });

    assert.match(outgoing.html, /Hier steht der Text der Nachricht\./);
    assert.match(outgoing.html, /font-size:16px;line-height:27px/);
    assert.doesNotMatch(outgoing.html, /data-rt-mail-/);
    assert.doesNotMatch(outgoing.project.pages[0].component, /data-rt-mail-/);

    const tampered = edited.replace('data-rt-mail-block="paragraph"', 'data-rt-mail-preview-only="rogue"');
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: tampered,
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /verlustfrei/);
});

test('protected mail layers keep the regular train image visible and structural', () => {
    const component = (attributes = {}, tagName = 'div', children = [], style = {}) => {
        const state = { attributes, tagName, style };
        const item = {
            components: () => ({ models: children }),
            get: (key) => state[key],
            getAttributes: () => attributes,
            getStyle: () => style,
            addAttributes: (next) => Object.assign(attributes, next),
            setStyle: (next) => {
                Object.keys(style).forEach((name) => delete style[name]);
                Object.assign(style, next);
            },
            removeStyle: (name) => { delete style[name]; },
            parent: () => item.parentComponent || null,
            set: (values) => Object.assign(state, values),
            state,
        };
        children.forEach((child) => { child.parentComponent = item; });
        return item;
    };
    const mark = component({ 'data-rt-mail-preview-token': 'ICON_RT_SRC' }, 'img');
    const markCell = component({}, 'td', [mark]);
    const markRow = component({}, 'tr', [markCell]);
    const logo = component({ 'data-rt-mail-preview-token': 'LOGO_SRC' }, 'img');
    const train = component({ 'data-rt-mail-preview-token': 'TRAIN_SRC', 'data-rt-train': '' }, 'img');
    const trainSlot = component({ class: 'rt-sign-train-slot' }, 'td', [train]);
    const trainFrameRow = component({}, 'tr', [trainSlot]);
    const trainFrameBody = component({}, 'tbody', [trainFrameRow]);
    const trainFrame = component({ class: 'rt-sign-train-frame' }, 'table', [trainFrameBody]);
    const trainLayer = component({ class: 'rt-sign-train-layer', 'data-rt-layer-train': '', 'data-rt-layer-align': 'left' }, 'div', [trainFrame]);
    const carrierContent = component({ class: 'rt-sign-content-frame' }, 'table');
    const trainStage = component({ class: 'rt-sign-stage' }, 'div', [trainLayer, carrierContent]);
    const carrier = component({ class: 'rt-sign-cell' }, 'td', [trainStage]);
    const applicationNote = component({}, 'div');
    const applicationCell = component({}, 'td', [applicationNote]);
    const applicationRow = component({ 'data-rt-mail-preview-only': 'application' }, 'tr', [applicationCell]);
    const ordinary = component({}, 'p');
    const ordinaryImage = component({ src: 'https://app.rail-time.test/mail-assets/content.png', alt: 'Teambild' }, 'img');
    const root = component({}, 'body', [markRow, logo, carrier, applicationRow, ordinary, ordinaryImage]);

    assert.equal(protectMailSystemComponents({ getWrapper: () => root }), 9);
    [mark, markCell, applicationRow, applicationCell, applicationNote, trainStage, trainFrame, trainSlot, carrierContent].forEach((protectedComponent) => {
        assert.equal(protectedComponent.state.stylable, false);
        assert.equal(protectedComponent.state.editable, false);
        assert.equal(protectedComponent.state.draggable, false);
        assert.equal(protectedComponent.state.removable, false);
        assert.equal(protectedComponent.state.copyable, false);
    });
    [train, logo].forEach((editableBrandImage) => {
        assert.equal(editableBrandImage.state.stylable, true);
        assert.equal(editableBrandImage.state.selectable, true);
        assert.equal(editableBrandImage.state.hoverable, true);
        assert.equal(editableBrandImage.state.layerable, true);
        assert.equal(editableBrandImage.state.removable, false);
        assert.equal(editableBrandImage.state.copyable, false);
        assert.equal(editableBrandImage.state.editable, undefined);
        assert.equal(editableBrandImage.state.draggable, undefined);
        assert.equal(editableBrandImage.state.droppable, undefined);
    });
    assert.equal(train.state.layerable, true);
    assert.equal(train.state['custom-name'], 'Zugbild');
    assert.equal(logo.state['custom-name'], 'Firmenlogo');
    assert.equal(trainLayer.state['custom-name'], 'Zug-Bildebene');
    assert.equal(trainLayer.state.layerable, true);
    assert.equal(trainLayer.state.stylable, false);
    assert.equal(trainStage.state['custom-name'], 'Signatur-Bühne');
    assert.equal(trainStage.state.layerable, true);
    assert.equal(trainFrame.state['custom-name'], 'Fester Zugrahmen');
    assert.equal(trainSlot.state['custom-name'], 'Zugposition unten');
    assert.equal(carrierContent.state['custom-name'], 'Fester Signatur-Inhaltsrahmen');
    assert.deepEqual(
        trainLayer.state.traits.map((trait) => trait.label),
        ['Desktop-Ausschnitt', 'Zugbreite', 'Mobil-Ausschnitt'],
    );
    assert.deepEqual(
        trainLayer.state.traits[2].options.map((option) => option.id),
        ['left', 'center', 'train', 'stop65', 'right'],
    );
    assert.equal(markCell.state['custom-name'], 'RT-Zeichen (geschützt)');
    assert.equal(applicationRow.state['custom-name'], 'Anwendungsinhalt (geschützt)');
    [markRow, carrier, ordinary].forEach((editableComponent) => {
        assert.equal(editableComponent.state.stylable, undefined);
    });
    assert.equal(ordinaryImage.state['custom-name'], 'Teambild');
    assert.deepEqual(
        ordinaryImage.state.traits.map((trait) => trait.name),
        ['alt', 'title', 'width', 'data-rt-image-align'],
    );
    assert.equal(ordinaryImage.getAttributes().width, '600');
    assert.equal(ordinaryImage.getAttributes()['data-rt-image-align'], 'left');
});

test('train layer editor maps presets onto the fixed schema 25 pixel contract', () => {
    const state = { left: '0', right: 'auto', margin: '0', 'margin-bottom': '-150px', height: '100%' };
    const layerStyleOptions = [];
    const imageStyleOptions = [];
    const attributes = {
        class: 'rt-sign-train-layer',
        'data-rt-layer-train': '',
        'data-rt-layer-align': 'center',
        'data-rt-layer-size': '125',
        'data-rt-layer-mobile': 'center',
    };
    const imageAttributes = {
        class: 'rt-sign-train',
        'data-rt-train': '',
        width: '1815',
    };
    const imageState = { 'max-width': '1815px' };
    const image = {
        getAttributes: () => imageAttributes,
        getStyle: () => imageState,
        addAttributes: (next) => Object.assign(imageAttributes, next),
        setStyle: (next, options) => {
            imageStyleOptions.push(options);
            Object.assign(imageState, next);
        },
    };
    const fixedModel = (attributes, style, children = []) => ({
        getAttributes: () => attributes,
        getStyle: () => style,
        addAttributes: (next) => Object.assign(attributes, next),
        setStyle: (next) => {
            Object.keys(style).forEach((name) => delete style[name]);
            Object.assign(style, next);
        },
        removeStyle: (name) => { delete style[name]; },
        components: () => ({ models: children }),
    });
    const slotAttributes = { class: 'rt-sign-train-slot' };
    const slotState = {};
    const slot = fixedModel(slotAttributes, slotState, [image]);
    const row = fixedModel({}, {}, [slot]);
    const body = fixedModel({}, {}, [row]);
    const frameAttributes = { class: 'rt-sign-train-frame' };
    const frameState = {};
    const frame = fixedModel(frameAttributes, frameState, [body]);
    const component = {
        getAttributes: () => attributes,
        getStyle: () => state,
        setStyle: (next, options) => {
            layerStyleOptions.push(options);
            Object.assign(state, next);
        },
        removeStyle: (name) => { delete state[name]; },
        components: () => ({ models: [frame] }),
    };

    assert.equal(synchronizeMailTrainLayerAlignment(component), true);
    assert.deepEqual(state, {
        display: 'block',
        height: '200px',
        'max-height': '200px',
        margin: '0 auto',
        'margin-bottom': '-200px',
        width: '100%',
        'max-width': '1815px',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    });
    assert.equal(frameAttributes.height, '200');
    assert.equal(frameState.height, '200px');
    assert.equal(slotAttributes.height, '200');
    assert.equal(slotAttributes.valign, 'bottom');
    assert.equal(slotState['vertical-align'], 'bottom');
    assert.equal(imageAttributes.width, '720');
    assert.equal(imageState.position, 'static');
    assert.equal(imageState.left, 'auto');
    assert.equal(imageState.right, 'auto');
    assert.equal(imageState.bottom, 'auto');
    assert.equal(imageState.display, 'inline-block');
    assert.equal(imageState.width, '125%');
    assert.equal(imageState['max-width'], 'none');
    assert.equal(imageState.margin, '0 0 0 -12.5%');
    assert.equal(imageState['vertical-align'], 'bottom');
    attributes['data-rt-layer-align'] = 'right';
    for (const [sizeName, geometry] of Object.entries({
        100: ['100%', '0'],
        125: ['125%', '0 0 0 -25%'],
        150: ['150%', '0 0 0 -50%'],
        200: ['200%', '0 0 0 -100%'],
    })) {
        attributes['data-rt-layer-size'] = sizeName;
        assert.equal(synchronizeMailTrainLayerAlignment(component), true);
        assert.equal(state.position, undefined);
        assert.equal(state.left, undefined);
        assert.equal(state.right, undefined);
        assert.equal(state.margin, '0 0 0 auto');
        assert.equal(state['margin-bottom'], '-200px');
        assert.equal(state.width, '100%');
        assert.equal(state['max-width'], '1815px');
        assert.equal(state['text-align'], 'left');
        assert.equal(imageState.width, geometry[0]);
        assert.equal(imageState['max-width'], 'none');
        assert.equal(imageState.margin, geometry[1]);
    }
    attributes['data-rt-layer-size'] = '125';
    attributes['data-rt-layer-mobile'] = 'stop65';
    synchronizeMailTrainLayerAlignment(component);
    assert.equal(attributes['data-rt-layer-mobile'], 'stop65');
    state['margin-bottom'] = '-72px';
    attributes['data-rt-layer-align'] = 'calc(1px)';
    attributes['data-rt-layer-mobile'] = 'calc(1px)';
    assert.equal(synchronizeMailTrainLayerAlignment(component), true);
    assert.deepEqual(state, {
        display: 'block',
        height: '200px',
        'max-height': '200px',
        margin: '0 auto',
        'margin-bottom': '-200px',
        width: '100%',
        'max-width': '1815px',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    });
    assert.equal(attributes['data-rt-layer-align'], 'center');
    assert.equal(attributes['data-rt-layer-mobile'], 'train');
    assert.equal(imageAttributes.width, '720');
    assert.equal(layerStyleOptions.length > 0, true);
    assert.equal(imageStyleOptions.length > 0, true);
    [...layerStyleOptions, ...imageStyleOptions].forEach((options) => {
        assert.deepEqual(options, { silent: true });
    });
});

test('fixed signature geometry resets stage and content frame directly to pixels', () => {
    const create = (className, attributes, style) => ({
        getAttributes: () => attributes,
        getStyle: () => style,
        addAttributes: (next) => Object.assign(attributes, next),
        removeStyle: (name) => { delete style[name]; },
        setStyle: (next) => {
            Object.keys(style).forEach((name) => delete style[name]);
            Object.assign(style, next);
        },
    });
    const stageAttributes = { class: 'rt-sign-stage' };
    const stageStyle = { position: 'relative', height: '37%', 'max-height': '800px', overflow: 'visible' };
    const stage = create('rt-sign-stage', stageAttributes, stageStyle);
    const contentAttributes = { class: 'rt-sign-content-frame', height: '73%' };
    const contentStyle = { width: '100%', height: '73%', 'border-collapse': 'collapse', 'margin-bottom': '-73%' };
    const content = create('rt-sign-content-frame', contentAttributes, contentStyle);

    assert.equal(synchronizeMailSignatureFixedGeometry(stage), true);
    assert.deepEqual(stageStyle, {
        position: 'relative',
        height: '200px',
        'max-height': '200px',
        overflow: 'hidden',
    });
    assert.equal(synchronizeMailSignatureFixedGeometry(content), true);
    assert.equal(contentAttributes.height, '200');
    assert.deepEqual(contentStyle, {
        width: '100%',
        height: '200px',
        'border-collapse': 'collapse',
    });
});

test('mail editor no longer offers misleading train background controls', () => {
    assert.equal(MAIL_STYLE_SECTORS.some((item) => item.id === 'rt-mail-train-background'), false);
    assert.equal(MAIL_STYLE_SECTORS.some((item) => item.buildProps?.includes('position')), false);
    assert.equal(MAIL_STYLE_SECTORS.some((item) => item.buildProps?.includes('z-index')), false);
    const spacingSector = MAIL_STYLE_SECTORS.find((item) => item.id === 'rt-mail-spacing');
    assert.equal(spacingSector?.buildProps?.includes('padding'), true);
    assert.equal(spacingSector?.buildProps?.includes('margin'), true);
    assert.equal(spacingSector?.buildProps?.some((property) => /^padding-/.test(property)), false);
    assert.equal(spacingSector?.buildProps?.some((property) => /^margin-/.test(property)), false);
    assert.equal(MAIL_EDITOR_MODE.id, 'mail');
    assert.equal(MAIL_EDITOR_MODE.contentModel, 'email');
    assert.equal(MAIL_EDITOR_MODE.styleStrategy, 'inline');
});

test('canvas hydrates exactly one regular train image without mutating its token model', () => {
    const project = projectForMailDocument({
        builderData: { pages: [{ component: `<tr><td class="rt-sign-cell">${canonicalSignatureStage('Inhalt')}</td></tr><tr><td>Rechtliches</td></tr>` }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });
    const document_ = new DOMParser().parseFromString(project.pages[0].component, 'text/html');
    const train = document_.querySelector('img[data-rt-train][data-rt-mail-preview-token="TRAIN_SRC"]');
    const modelSource = train.getAttribute('src');

    assert.equal(hydrateMailCanvasAssets({ Canvas: { getDocument: () => document_ } }, 'light', {
        light: { train: 'https://app.rail-time.test/mail-assets/train.gif' },
    }), 1);
    assert.equal(train.getAttribute('src'), 'https://app.rail-time.test/mail-assets/train.gif');
    assert.equal(document_.querySelectorAll('img[data-rt-train].rt-sign-train').length, 1);
    assert.equal(modelSource.startsWith('data:image/png;base64,'), true);
    assert.equal(project.pages[0].component.includes('https://app.rail-time.test'), false);
});

test('template save never persists a detached or unknown image preview binding', () => {
    const canonical = '<!doctype html><html><head><title>RailTime</title></head><body><table><tbody><tr><td><img src="{{ICON_RT_SRC}}" alt=""></td></tr>{{SIGNATURE_BLOCK}}</tbody></table></body></html>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: canonical }], styles: [] },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });

    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace(' data-rt-mail-preview-token="ICON_RT_SRC"', ''),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /verlustfrei/);

    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace('ICON_RT_SRC', 'UNBEKANNTES_BILD'),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /unbekannten Bildplatzhalter/);
});

test('template save fails closed without its canonical shell or preview signature binding', () => {
    const canonical = '<!doctype html><html><head><title>RailTime</title></head><body><table><tbody>{{SIGNATURE_BLOCK}}</tbody></table></body></html>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: canonical }], styles: [] },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });

    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component.replace(/<tr data-rt-mail-preview-only="signature">[\s\S]*?<\/tr>/, ''),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    }), /Signaturblocks/);
    assert.throws(() => serializeMailDocumentForSave({
        project,
        html: project.pages[0].component,
        kind: 'template',
        baselineHtml: '',
        environment: { DOMParser },
    }), /Dokumenthülle/);
});

test('navigation controller saves a small pending mail edit before leaving', async () => {
    const reasons = [];
    const states = [];
    const builder = {
        dirty: true,
        hasUnsavedChanges() { return this.dirty; },
        async save(reason) {
            reasons.push(reason);
            this.dirty = false;
            return true;
        },
    };
    const controller = createMailNavigationController({
        getBuilder: () => builder,
        onSaving: () => states.push('saving'),
        onSaved: () => states.push('saved'),
    });

    assert.equal(controller.hasPendingWork(), true);
    assert.equal(await controller.flush(), true);
    assert.deepEqual(reasons, ['manual']);
    assert.deepEqual(states, ['saving', 'saved']);
    assert.equal(controller.hasPendingWork(), false);
});

test('navigation controller stays fail-closed when LMZ save fails', async () => {
    const controller = createMailNavigationController({
        getBuilder: () => ({
            hasUnsavedChanges: () => true,
            save: async () => false,
        }),
    });

    await assert.rejects(
        controller.flush(),
        /konnten vor dem Seitenwechsel nicht gespeichert werden/,
    );
});

test('sanitized server project replaces the running canvas and unchanged saves do not reload it', async () => {
    const loaded = [];
    const draft = {
        builderData: { pages: [{ component: '<p>Sicher</p>' }], styles: [] },
        css: '.safe{color:#111820}',
    };
    const editor = {
        async loadProjectData(project) {
            loaded.push(project);
        },
    };
    const parseCss = (css) => [{ selectors: ['.safe'], style: { color: css.includes('#111820') ? '#111820' : '' } }];

    assert.equal(await rehydrateAuthoritativeMailProject({ editor, draft, parseCss }), false);
    assert.equal(loaded.length, 0);

    assert.equal(await rehydrateAuthoritativeMailProject({
        editor,
        draft,
        sanitizationChanged: true,
        parseCss,
    }), true);
    assert.equal(loaded.length, 1);
    assert.equal(loaded[0].pages[0].component, '<p>Sicher</p>');
    assert.deepEqual(loaded[0].styles, [{ selectors: ['.safe'], style: { color: '#111820' } }]);

    await assert.rejects(
        rehydrateAuthoritativeMailProject({
            editor: {},
            draft,
            sanitizationChanged: true,
        }),
        /konnte nicht in den Mail-Editor geladen werden/,
    );
});

test('mobile mail editor closes only visible initial LMZ popovers', () => {
    let clicked = 0;
    const closeButton = { disabled: false, click: () => { clicked += 1; } };
    const visible = {
        hidden: false,
        querySelector(selector) {
            return selector.includes('is-active') ? { querySelector: () => closeButton } : closeButton;
        },
    };
    const hidden = { hidden: true };
    const root = { querySelectorAll: () => [visible, hidden] };

    assert.equal(closeInitialMailPopovers(root, { matches: false }), 0);
    assert.equal(closeInitialMailPopovers(root, { matches: true }), 1);
    assert.equal(clicked, 1);
});

test('mail preview devices model wide system mail, desktop, tablet and mobile client widths', () => {
    assert.deepEqual(MAIL_PREVIEW_DEVICES.wide, { id: 'wide', label: 'Systemmail breit', width: 1920 });
    assert.deepEqual(MAIL_PREVIEW_DEVICES.desktop, { id: 'desktop', label: 'Desktop', width: 1024 });
    assert.deepEqual(MAIL_PREVIEW_DEVICES.tablet, { id: 'tablet', label: 'Tablet', width: 820 });
    assert.deepEqual(MAIL_PREVIEW_DEVICES.mobile, { id: 'mobile', label: 'Mobil', width: 375 });
    assert.equal(resolveMailPreviewDevice('unknown'), MAIL_PREVIEW_DEVICES.desktop);
    assert.equal(MAIL_GJS_OPTIONS.devicePreviewMode, true);
});

test('desktop mail stays 1024 logical pixels wide when fitted onto a phone', () => {
    const geometry = calculateMailPreviewGeometry({
        device: 'desktop',
        hostWidth: 390,
        hostHeight: 700,
        inset: 24,
    });

    assert.equal(geometry.logicalWidth, 1024);
    assert.equal(geometry.displayWidth, 342);
    assert.ok(Math.abs(geometry.scale - (342 / 1024)) < 0.000001);
    assert.equal(geometry.displayHeight, 652);
    assert.equal(geometry.logicalHeight, 652 / geometry.scale);
});

test('wide system mail keeps a 1920 pixel layout while fitting into the editor', () => {
    const geometry = calculateMailPreviewGeometry({
        device: 'wide',
        hostWidth: 1000,
        hostHeight: 700,
        inset: 24,
    });

    assert.equal(geometry.logicalWidth, 1920);
    assert.equal(geometry.displayWidth, 952);
    assert.ok(Math.abs(geometry.scale - (952 / 1920)) < 0.000001);
});

test('mail preview never upscales and mobile keeps an exact 375 pixel iframe', () => {
    const geometry = calculateMailPreviewGeometry({
        device: 'mobile',
        hostWidth: 1400,
        hostHeight: 900,
    });

    assert.equal(geometry.logicalWidth, 375);
    assert.equal(geometry.scale, 1);
    assert.equal(geometry.displayWidth, 375);
    assert.equal(geometry.logicalHeight, 852);
});

test('preview controller writes logical frame variables and cleans listeners', () => {
    const devices = new Map();
    const handlers = new Map();
    const cssProperties = {};
    const selectedDevices = [];
    const zooms = [];
    const refreshes = [];
    const triggered = [];
    const iframeAttributes = {};
    const iframeStyles = {};
    const canvasFrame = {
        setAttribute(name, value) { iframeAttributes[name] = value; },
        style: { setProperty(name, value, priority = '') { iframeStyles[name] = { value, priority }; } },
    };
    const editor = {
        DeviceManager: {
            get: (id) => devices.get(id),
            add: (id, attributes) => devices.set(id, attributes),
        },
        Canvas: {
            setZoom: (zoom) => zooms.push(zoom),
            getFrameEl: () => canvasFrame,
        },
        refresh: (options) => refreshes.push(options),
        trigger: (event, detail) => triggered.push([event, detail]),
        setDevice: (device) => selectedDevices.push(device),
        on: (event, handler) => handlers.set(event, handler),
        off: (event, handler) => {
            if (handlers.get(event) === handler) handlers.delete(event);
        },
    };
    const frame = {
        clientWidth: 390,
        clientHeight: 700,
        dataset: {},
        style: {
            setProperty(name, value) {
                cssProperties[name] = value;
            },
        },
        querySelector: () => null,
    };
    const changes = [];
    const controller = createMailPreviewController({
        instance: { editor },
        frame,
        device: 'desktop',
        onChange: (geometry) => changes.push(geometry),
        environment: { ResizeObserver: null },
    });

    assert.equal(devices.get('rt-mail-desktop').width, '1024px');
    assert.equal(devices.get('rt-mail-wide').width, '1920px');
    assert.equal(devices.get('rt-mail-tablet').width, '820px');
    assert.equal(devices.get('rt-mail-mobile').width, '375px');
    assert.equal(selectedDevices.at(-1), 'rt-mail-desktop');
    assert.equal(cssProperties['--rt-mail-logical-width'], '1024px');
    assert.equal(frame.dataset.previewDevice, 'desktop');
    assert.equal(changes.at(-1).logicalWidth, 1024);

    controller.setDevice('mobile');
    assert.equal(selectedDevices.at(-1), 'rt-mail-mobile');
    assert.equal(cssProperties['--rt-mail-logical-width'], '375px');
    assert.equal(frame.dataset.previewDevice, 'mobile');
    assert.equal(zooms.at(-1), 91.2);
    assert.equal(iframeAttributes.width, '375');
    assert.equal(iframeStyles.width.value, '375px');
    assert.equal(iframeStyles.width.priority, 'important');
    assert.deepEqual(refreshes.at(-1), { tools: true });
    assert.equal(triggered.at(-1)[0], 'rt:mail:preview-resize');
    assert.equal(triggered.at(-1)[1].logicalWidth, 375);

    handlers.get('canvas:frame:load')?.();
    assert.equal(changes.at(-1).device, 'mobile');

    controller.destroy();
    assert.equal(handlers.size, 0);
});

test('mail editor waits for the teleported fullscreen workspace before booting LMZ', async () => {
    const { readFile } = await import('node:fs/promises');
    const source = await readFile(
        new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url),
        'utf8',
    );
    const coreSource = await readFile(
        new URL('../../resources/js/lmz-editor-core.js', import.meta.url),
        'utf8',
    );

    assert.match(source, /const editorStart = await new Promise/);
    assert.match(source, /new MutationObserver\(probe\)/);
    assert.match(source, /page-builder-shell:opened/);
    assert.match(source, /waitForFullscreenActivation/);
    assert.match(source, /await waitForFullscreenActivation\(\)/);
    assert.ok(source.indexOf('await waitForFullscreenActivation()') < source.indexOf('const runtime = await ensureRuntime()'));
    assert.match(source, /workspace && root && runtimeBridge/);
    assert.doesNotMatch(source, /if \(!workspace \|\| !root \|\| !runtimeBridge\) \{\s*return;/);
    assert.match(coreSource, /closest\?\.\('\[data-page-builder-fullscreen-root\]'\)/);
});

test('shared page builder opens from preview into a compact responsive Mail Studio', async () => {
    const { readFile } = await import('node:fs/promises');
    const [shell, mailView, mailCss, shellCss] = await Promise.all([
        readFile(new URL('../../resources/views/components/ui/page-builder/editor-shell.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/mail-builder.css', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/lmz-editor-shell.css', import.meta.url), 'utf8'),
    ]);

    assert.match(shell, /'autoOpen'\s*=>\s*false/);
    assert.match(shell, /pageBuilderOpen:\s*@js\(\(bool\) \$autoOpen\)/);
    assert.match(shell, /data-page-builder-preview-first/);
    assert.match(shell, /data-page-builder-assist/);
    assert.doesNotMatch(shell, /data-page-builder-panel-host/);

    assert.match(mailView, /data-mail-studio-toolbar/);
    assert.match(mailView, /data-mail-document-save/);
    assert.match(mailView, /data-mail-document-publish/);
    assert.match(mailView, /data-mail-code-open/);
    assert.match(mailView, /data-mail-code-export/);
    assert.match(mailView, /data-mail-code-import/);
    assert.match(mailView, /data-mail-editor-mode="mail"/);
    assert.match(mailView, /LMZ Page Builder wird im Mailmodus geladen/);
    assert.match(mailView, /data-mail-code-dialog/);
    assert.match(mailView, /const MAIL_SOURCE_VERSION = 2/);
    assert.match(mailView, /const MAX_SOURCE_BYTES = 1024 \* 1024/);
    assert.match(mailView, /const MAX_BUNDLE_BYTES = 16 \* 1024 \* 1024/);
    assert.match(mailView, /format: MAIL_SOURCE_FORMAT,[\s\S]*?version: MAIL_SOURCE_VERSION,[\s\S]*?kind: config\.currentDocument,[\s\S]*?html: source\.html,[\s\S]*?css: source\.css,[\s\S]*?media: await exportPortableMedia\(\)/);
    assert.match(mailView, /crypto\.subtle\.digest\('SHA-256'/);
    assert.match(mailView, /portable_media: portableMedia/);
    assert.match(mailView, /requiredIds\.some\(\(id\) => !seenIds\.has\(id\)\)/);
    assert.match(mailView, /\^mail-imports\\\/\(\[a-f0-9\]\{64\}\)\\\.\(gif\|png\|jpg\|webp\)\$/);
    assert.match(mailView, /Das Bundle enthält nicht den vollständigen Medienbestand dieses Dokuments/);
    assert.match(mailView, /validateSourceOnServer\(source, pendingPortableMedia\)[\s\S]*?runtimeBridge\.projectFor[\s\S]*?editor\.loadProjectData[\s\S]*?saveCurrentDraft\(\)/);
    assert.match(mailView, /document_\.endpoints\?\.validate[\s\S]*?Es wurde nichts übernommen/);
    assert.match(mailView, /URL\.revokeObjectURL\(objectUrl\)/);
    assert.match(mailView, /await saveCurrentDraft\(\)[\s\S]*?endpoints\.publish/);
    assert.match(mailView, /let lastEditorSaveError = null/);
    assert.match(mailView, /onSave:\s*async[\s\S]*?lastEditorSaveError = normalizeError[\s\S]*?throw lastEditorSaveError/);
    assert.match(mailView, /const saveError = lastEditorSaveError[\s\S]*?throw saveError \|\| new Error/);
    assert.match(mailView, /showRequestError\(error, 'Speichern nicht möglich'\)/);
    assert.match(mailView, /showRequestError\(error, 'Veröffentlichung nicht möglich'\)/);
    assert.doesNotMatch(mailView, /showFindings\(\{ messages: error\.messages[\s\S]*?severity: 'violation'/);
    assert.match(mailView, /workspace-class="min-h-0 flex-1 overflow-hidden p-0"/);
    assert.match(mailView, /const studioRoot = workspace\.closest\('\[data-page-builder-fullscreen-root\]'\)/);
    assert.doesNotMatch(mailView, /class="rt-mail-preview-toolbar"/);

    assert.match(mailCss, /\.rt-mail-studio\s*\{[\s\S]*?overflow:\s*hidden;/);
    assert.match(mailCss, /\.rt-mail-code-dialog\s*\{/);
    assert.match(mailCss, /\.rt-mail-code-dialog::backdrop/);
    assert.doesNotMatch(mailCss, /min-height:\s*42rem/);
    assert.match(shellCss, /font-family:\s*'Plus Jakarta Sans Variable'/);
    assert.match(shellCss, /html\[data-rt-pagebuilder-assist-open='true'\]/);
    assert.match(shellCss, /\.lmz-builder\s+\.lmzbjs-layers/);
    assert.match(shellCss, /@media \(max-width: 639\.98px\)[\s\S]*?\.lmz-builder__popover/);
});

test('mail toolbar keeps documents, preview and publishing in non-overlapping responsive zones', async () => {
    const { readFile } = await import('node:fs/promises');
    const [view, css] = await Promise.all([
        readFile(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/mail-builder.css', import.meta.url), 'utf8'),
    ]);

    assert.match(view, /data-mail-toolbar-layout="responsive"/);
    assert.match(view, /:auto-open="request\(\)->boolean\('open'\)"/);
    assert.match(view, /\['dokument' => \$kindValue, 'open' => 1\]/);
    for (const region of ['documents', 'preview', 'actions']) {
        assert.equal((view.match(new RegExp(`data-mail-toolbar-region="${region}"`, 'g')) || []).length, 1);
    }

    assert.match(css, /grid-template-columns:\s*minmax\(16rem, 0\.85fr\) minmax\(22rem, 1fr\) minmax\(17rem, 0\.85fr\)/);
    const compactDesktop = css.slice(
        css.indexOf('@media (min-width: 1100px) and (max-width: 1599.98px)'),
        css.indexOf('@media (max-width: 1099.98px)'),
    );
    assert.match(compactDesktop, /\.rt-mail-studio-toolbar__preview[\s\S]*?overflow:\s*hidden/);
    assert.match(compactDesktop, /\.rt-mail-studio-toolbar__actions[\s\S]*?overflow:\s*hidden/);
    assert.match(compactDesktop, /\.rt-mail-preview-toggle > button > span[\s\S]*?clip:\s*rect\(0, 0, 0, 0\)/);

    const tablet = css.slice(
        css.indexOf('@media (max-width: 1099.98px)'),
        css.indexOf('@media (max-width: 899.98px)'),
    );
    assert.match(tablet, /grid-template-columns:\s*minmax\(0, 1fr\) auto auto/);
    assert.match(tablet, /\.rt-mail-studio-toolbar__preview[\s\S]*?grid-column:\s*1 \/ -1[\s\S]*?grid-row:\s*2/);
    assert.match(tablet, /\.rt-mail-studio-toolbar__actions\s*\{[\s\S]*?display:\s*contents/);

    const mobile = css.slice(css.indexOf('@media (max-width: 639.98px)'));
    assert.match(mobile, /\.rt-mail-preview-toggle > button span/);
    assert.match(mobile, /\.rt-mail-studio-toolbar__action-label[\s\S]*?clip:\s*rect\(0, 0, 0, 0\)/);
    assert.match(mobile, /\.rt-mail-studio-toolbar__action-buttons > \.rt-ui-button[\s\S]*?width:\s*2\.75rem/);
    assert.match(mobile, /grid-template-columns:\s*minmax\(0, 1fr\) auto/);
    assert.match(mobile, /\.rt-mail-studio-toolbar__documents[\s\S]*?grid-column:\s*1 \/ -1[\s\S]*?grid-row:\s*1/);
    assert.match(mobile, /\.rt-mail-studio-toolbar__action-buttons[\s\S]*?grid-column:\s*2[\s\S]*?grid-row:\s*2/);
});

test('signature source uses a fixed schema 25 pixel frame with a Classic Outlook still', async () => {
    const { readFile } = await import('node:fs/promises');
    const [css, signatureSource, trainAsset, carrier, runtime] = await Promise.all([
        readFile(new URL('../../resources/views/emails/parts/responsive-css.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/emails/parts/signature.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/mail-templates/assets/zug-dampf-light.png', import.meta.url)),
        readFile(new URL('../../app/Support/Mail/SignatureTrainCarrier.php', import.meta.url), 'utf8'),
        readFile(new URL('../../app/Support/MailSignature.php', import.meta.url), 'utf8'),
    ]);
    assert.match(signatureSource, /<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">/);
    assert.match(signatureSource, /<table class="rt-sign-content-frame" role="presentation"[^>]*height="200"[^>]*style="width:100%;height:200px;border-collapse:collapse;">/);
    assert.doesNotMatch(signatureSource, /<td class="rt-sign-cell"[^>]*position:relative/);
    assert.match(signatureSource, /<img class="rt-sign-train" data-rt-train src="\{\{ \$trainSrc \}\}"/);
    assert.match(signatureSource, /<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="center" data-rt-layer-size="125" data-rt-layer-mobile="train" style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto;margin-bottom:-200px;[^"\r\n]*overflow:hidden;/);
    assert.match(signatureSource, /<img class="rt-sign-train"[^>]*width="720"[^>]*width:125%;[^"\r\n]*margin:0 0 0 -12\.5%;/);
    assert.match(signatureSource, /<table class="rt-sign-train-frame" role="presentation" width="100%" height="200"[^>]*style="width:100%;height:200px;border-collapse:collapse;">/);
    assert.match(signatureSource, /<td class="rt-sign-train-slot" height="200" valign="bottom" style="height:200px;[^"\r\n]*vertical-align:bottom;/);
    assert.doesNotMatch(signatureSource, /rt-sign-train-layer[^>]*position:absolute/);
    assert.ok(signatureSource.indexOf('class="rt-sign-train-layer"') < signatureSource.indexOf('class="rt-sign-content-frame"'));
    assert.match(signatureSource, /<img class="rt-sign-train"[^>]*width="720"[^>]*style="position:static;[^"\r\n]*bottom:auto;display:inline-block;[^"\r\n]*vertical-align:bottom;[^"\r\n]*mso-hide:all;/);
    assert.doesNotMatch(signatureSource, /url\(\{\$values\['TRAIN_SRC'\]\}\)/);
    assert.doesNotMatch(css, /\.rt-sign-cell\.rt-sign-train-background/);
    assert.match(css, /\.rt-sign-train-layer\s*\{[^}]*display:\s*block !important;[^}]*margin-top:\s*0 !important;/s);
    assert.doesNotMatch(css, /\.rt-sign-train-layer\s*\{[^}]*position:\s*absolute !important;/s);
    assert.doesNotMatch(css, /\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0 !important;/s);
    assert.match(css, /\.rt-sign-train,\s*\.rt-sign-train-mso\s*\{[^}]*position:\s*static !important;[^}]*display:\s*inline-block !important;[^}]*vertical-align:\s*bottom !important;/s);
    assert.match(css, /\.rt-train-idle-overlay\s*\{[^}]*position:\s*absolute !important;[^}]*bottom:\s*0 !important;[^}]*height:\s*0 !important;/s);
    assert.match(css, /@keyframes rt-train-idle-reveal/);
    assert.match(css, /animation-delay:\s*13s/);

    assert.equal(trainAsset.toString('ascii', 1, 4), 'PNG');
    const assetWidth = trainAsset.readUInt32BE(16);
    const assetHeight = trainAsset.readUInt32BE(20);
    assert.deepEqual([assetWidth, assetHeight], [2160, 159]);

    assert.equal((carrier.match(/<!--\[if mso\]><tr><td class="rt-sign-train-mso"/g) || []).length, 0);
    assert.equal((carrier.match(/<!--\[if mso\]><img class="rt-sign-train-mso"/g) || []).length, 1);
    assert.equal((runtime.match(/SignatureTrainCarrier::withMsoFallback\(/g) || []).length, 1);
    assert.equal((runtime.match(/SignatureTrainCarrier::withIdleOverlay\(/g) || []).length, 1);
    assert.equal((runtime.match(/SignatureTrainCarrier::projectAsRuntimeBackground\(/g) || []).length, 0);
    assert.match(
        carrier.slice(
            carrier.indexOf('public static function withMsoFallback'),
            carrier.indexOf('public static function withIdleOverlay'),
        ),
        /<img class="rt-sign-train-mso"[^>]*display:inline-block;[^>]*vertical-align:bottom;/,
    );
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);

    const mobile = css.slice(css.indexOf('@media only screen and (max-width: 860px)'));
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[\s\S]*?width: 100% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][^}]+width: 150% !important; max-width: none !important; margin-left: 0 !important/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][^}]+width: 150% !important; max-width: none !important; margin-left: -25% !important/);
    const phone = css.slice(css.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width: 175% !important;[\s\S]*?margin-left: -8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-stage\s*\{[^}]*height: 280px !important;[^}]*max-height: 280px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-train-layer\s*\{[^}]*height: 280px !important;[^}]*max-height: 280px !important;[^}]*margin-bottom: -280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][^}]+width: 175% !important;[^}]+margin-left: -40% !important/);
    assert.match(mobile, /data-rt-layer-mobile="right"\]\[data-rt-layer-size\][^}]+width: 200% !important; max-width: none !important; margin-left: -100% !important/);
});
