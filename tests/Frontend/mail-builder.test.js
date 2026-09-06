import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createContext, runInContext } from 'node:vm';
import { DOMParser } from 'linkedom';

import {
    MAIL_PREVIEW_DEVICES,
    MAIL_PREVIEW_MAX_WIDTH,
    MAIL_PREVIEW_MIN_WIDTH,
    MAIL_SIGNATURE_SCHEMA,
    calculateMailPreviewGeometry,
    closeInitialMailPopovers,
    createMailNavigationController,
    createMailPreviewController,
    hydrateMailCanvasAssets,
    installMailTypographyFocus,
    MAIL_EDITOR_MODE,
    MAIL_GJS_OPTIONS,
    MAIL_SAFE_EDITABLE_STYLE_PROPERTIES,
    MAIL_SAFE_FONT_STACKS,
    MAIL_STYLE_SECTORS,
    parseMailCssProjectStyles,
    normalizeMailPreviewWidth,
    protectMailSystemComponents,
    projectForMailDocument,
    rehydrateAuthoritativeMailProject,
    restartMailCanvasAnimations,
    resolveMailPreviewDevice,
    resolvePortableMediaRequirementIds,
    serializeMailDocumentForSave,
    serializeMailProjectStyles,
    synchronizeMailPresentationAttributes,
    synchronizeMailSignatureBackground,
    synchronizeMailSignatureFlowGeometry,
    synchronizeMailSignatureFixedGeometry,
    synchronizeMailTrainLayerAlignment,
} from '../../resources/js/mail-builder.js';
import { createMailBlocks, mailCanvasStyles, MAIL_DEFAULT_FONT_STACK, MAIL_PERSON_FIELDS } from '../../resources/js/mail-builder-blocks.js';

const canonicalTrain = '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:0 auto 0 0;margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;"><table class="rt-sign-train-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;"><tbody><tr><td class="rt-sign-train-slot" height="200" valign="bottom" style="height:200px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="720" alt="" style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:100%;max-width:none;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></td></tr></tbody></table></div>';
const canonicalSignatureStage = (content = '') => '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">'
    + canonicalTrain
    + '<table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">'
    + `<tbody><tr><td>${content}</td></tr></tbody></table>`
    + '</div>';
const flowSafeTrain = '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="left" style="display:block;width:100%;max-width:720px;margin:0 auto 0 0;overflow:hidden;font-size:0;line-height:0;text-align:left;"><table class="rt-sign-train-frame" role="presentation" width="100%" height="61" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:61px;border-collapse:collapse;"><tr><td class="rt-sign-train-slot" height="61" valign="bottom" style="height:61px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;"><img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="720" height="61" alt="" style="display:block;width:100%;max-width:720px;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;"></td></tr></table></div>';
const flowSafeSignatureStage = (content = '') => '<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;">'
    + '<table class="rt-sign-content-frame" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">'
    + `<tbody><tr><td>${content}</td></tr></tbody></table>`
    + flowSafeTrain
    + '</div>';
const fluidSignatureStage = (content = '') => flowSafeSignatureStage(content)
    .replaceAll('max-width:720px', 'max-width:none')
    .replaceAll(' height="61"', '')
    .replaceAll('height:61px;', '')
    .replace('width="720" alt=', 'width="720" height="61" alt=');

const backgroundSignature = (enabled = true) => `<tr data-rt-artifact-version="v22"><td class="rt-sign-cell" width="100%" bgcolor="{{SIGNATURE_BG}}" data-rt-signature-background="${enabled ? '1' : '0'}" data-rt-bg-desktop="110" data-rt-bg-tablet="150" data-rt-bg-mobile="175" style="width:100%;padding:0;background-color:{{SIGNATURE_BG}};background-image:${enabled ? "url('{{TRAIN_SRC}}')" : 'none'};background-size:110% auto;background-position:65% bottom;background-repeat:no-repeat;border-top:5px solid #e4002b;">`
    + '<table class="rt-sign-content-frame" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tr><td><img class="rt-logo" src="{{LOGO_SRC}}" width="200" alt="Logo"><p>Kontaktdaten</p></td></tr></table>'
    + '</td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtstext</td></tr>';

test('V22 roundtrips optional responsive backgrounds without IMG layers or preview URLs', () => {
    for (const enabled of [true, false]) {
        const html = backgroundSignature(enabled);
        const project = projectForMailDocument({ html, builderData: { pages: [{ component: html }] } }, () => [], { kind: 'signature', environment: { DOMParser } });
        assert.match(project.pages[0].component, /data-rt-mail-preview-train="TRAIN_SRC"/);
        assert.doesNotMatch(project.pages[0].component, /\{\{TRAIN_SRC\}\}/);
        const outgoing = serializeMailDocumentForSave({ project, html: project.pages[0].component, kind: 'signature', baselineHtml: html, environment: { DOMParser } });
        assert.equal(outgoing.project.railtime.schema, 29);
        assert.match(outgoing.html, /data-rt-bg-desktop="110"/);
        assert.match(outgoing.html, /data-rt-bg-tablet="150"/);
        assert.match(outgoing.html, /data-rt-bg-mobile="175"/);
        assert.equal((outgoing.html.match(/\{\{TRAIN_SRC\}\}/g) || []).length, enabled ? 1 : 0);
        assert.doesNotMatch(outgoing.html, /data-rt-mail-preview|rt-sign-stage|rt-sign-train-layer|margin-bottom:\s*-/);
        const again = projectForMailDocument({ html: outgoing.html, builderData: outgoing.project }, () => [], { kind: 'signature', environment: { DOMParser } });
        assert.equal(serializeMailDocumentForSave({ project: again, html: again.pages[0].component, kind: 'signature', baselineHtml: outgoing.html, environment: { DOMParser } }).html, outgoing.html);
    }
});

test('V22 rejects unbound sources unsupported breakpoint sizes and renewed overlap', () => {
    const html = backgroundSignature();
    for (const invalid of [
        html.replace('data-rt-bg-mobile="175"', 'data-rt-bg-mobile="999"'),
        html.replace("url('{{TRAIN_SRC}}')", "url('https://evil.example/train.gif')"),
        html.replace('width:100%;padding:0;', 'width:100%;padding:0;margin-bottom:-200px;'),
        html.replace('width:100%;border-collapse:collapse;', 'width:100%;height:200px;border-collapse:collapse;'),
        html.replace('data-rt-signature-background="1"', 'data-rt-signature-background="0"'),
    ]) {
        assert.throws(() => projectForMailDocument({ html: invalid, builderData: { pages: [{ component: invalid }] } }, () => [], { kind: 'signature', environment: { DOMParser } }));
    }
});

test('V23 optional background editing and reload preserve the V18 content table and version', () => {
    const layout = '<table class="rt-sign-layout" role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td class="rt-sign-logo" colspan="2">Logo</td></tr><tr class="rt-stack rt-sign-top-row"><td class="rt-sign-identity">Person</td><td class="rt-sign-company">Firma</td></tr></table>';
    const html = backgroundSignature().replace('v22', 'v23').replace('<p>Kontaktdaten</p>', layout);
    const project = projectForMailDocument({ html, builderData: { pages: [{ component: html }] } }, () => [], { kind: 'signature', environment: { DOMParser } });
    const edited = project.pages[0].component.replace('data-rt-bg-mobile="175"', 'data-rt-bg-mobile="200"');
    const saved = serializeMailDocumentForSave({ project, html: edited, kind: 'signature', baselineHtml: html, environment: { DOMParser } });
    assert.match(saved.html, /data-rt-artifact-version="v23"/);
    assert.match(saved.html, /data-rt-bg-mobile="200"/);
    assert.match(saved.html, /class="rt-sign-layout"/);
    assert.match(saved.html, /class="rt-sign-logo" colspan="2"/);
    assert.match(saved.html, /class="rt-sign-identity">Person/);
    const again = projectForMailDocument({ html: saved.html, builderData: saved.project }, () => [], { kind: 'signature', environment: { DOMParser } });
    assert.equal(serializeMailDocumentForSave({ project: again, html: again.pages[0].component, kind: 'signature', baselineHtml: saved.html, environment: { DOMParser } }).html, saved.html);
    const disabled = saved.html.replace('data-rt-signature-background="1"', 'data-rt-signature-background="0"').replace("url('{{TRAIN_SRC}}')", 'none');
    assert.doesNotThrow(() => projectForMailDocument({ html: disabled, builderData: { pages: [{ component: disabled }] } }, () => [], { kind: 'signature', environment: { DOMParser } }));
});

test('V22 and V23 content style controls exclude invalid fixed heights and overlaps only in their own descendants', () => {
    for (const version of ['v20', 'v22', 'v23']) {
        const row = { getAttributes: () => ({ 'data-rt-artifact-version': version }), parent: () => null, components: () => ({ models: [] }) };
        const state = { tagName: 'td', attributes: { class: 'rt-sign-identity' } };
        const cell = { get: (name) => state[name], getAttributes: () => state.attributes, parent: () => row, set: (next) => Object.assign(state, next), components: () => ({ models: [] }) };
        protectMailSystemComponents({ getWrapper: () => cell });
        if (version === 'v20') assert.equal(state.stylable, undefined);
        else {
            ['height', 'max-height', 'display', 'margin'].forEach((property) => assert(!state.stylable.includes(property)));
            ['padding', 'font-family', 'font-size', 'color', 'width'].forEach((property) => assert(state.stylable.includes(property)));
        }
    }
});

test('V22 canvas hydrates a decorative background without changing the stored token model', () => {
    const html = backgroundSignature();
    const project = projectForMailDocument({ html, builderData: { pages: [{ component: html }] } }, () => [], { kind: 'signature', environment: { DOMParser } });
    const before = JSON.stringify(project);
    const doc = new DOMParser().parseFromString(`<html><body>${project.pages[0].component}</body></html>`, 'text/html');
    hydrateMailCanvasAssets({ Canvas: { getDocument: () => doc } }, 'light', { light: { train: '/mail/train.gif', logo: '/mail/logo.gif' } });
    assert.match(doc.querySelector('td.rt-sign-cell').style.backgroundImage, /\/mail\/train\.gif/);
    assert.equal(JSON.stringify(project), before);
    const disabled = doc.querySelector('td.rt-sign-cell');
    disabled.setAttribute('data-rt-bg-desktop', '125');
    disabled.style.backgroundSize = '110% auto';
    hydrateMailCanvasAssets({ Canvas: { getDocument: () => doc } }, 'light', { light: { train: '/mail/train.gif' } });
    assert.equal(disabled.style.backgroundSize, '125% auto');
    assert.equal(JSON.stringify(project), before);
    disabled.setAttribute('data-rt-signature-background', '0');
    hydrateMailCanvasAssets({ Canvas: { getDocument: () => doc } }, 'light', { light: { train: '/mail/train.gif' } });
    assert.equal(disabled.style.backgroundImage, 'none');
});

test('authoritative V22 HTML replaces stale exported GrapesJS frames on save and reload', () => {
    const html = backgroundSignature();
    const builderData = { pages: [{ component: html, frames: [{ component: '<div>Stale canvas</div>' }] }] };
    const project = projectForMailDocument({ html, builderData }, () => [], { kind: 'signature', environment: { DOMParser } });
    assert.equal(project.pages[0].frames, undefined);
    const saved = serializeMailDocumentForSave({ project: { ...project, pages: [{ ...project.pages[0], frames: [{ component: '<div>Stale canvas</div>' }] }] }, html: project.pages[0].component, kind: 'signature', baselineHtml: html, environment: { DOMParser } });
    assert.equal(saved.project.pages[0].frames, undefined);
    assert.match(saved.html, /data-rt-artifact-version="v22"/);
});

test('V22 geometry synchronization keeps breakpoint settings and cannot reintroduce a fixed stage', () => {
    const row = { getAttributes: () => ({ 'data-rt-artifact-version': 'v22' }), parent: () => null };
    const attributes = { class: 'rt-sign-cell', 'data-rt-signature-background': '1', 'data-rt-bg-desktop': '150', 'data-rt-bg-tablet': '175', 'data-rt-bg-mobile': '200', 'data-rt-mail-preview-train': 'TRAIN_SRC' };
    const style = { padding: '0', 'background-size': '110% auto' };
    const carrier = { get: (name) => name === 'tagName' ? 'td' : null, getAttributes: () => attributes, getStyle: () => style, parent: () => row, addStyle: (value) => Object.assign(style, value) };
    assert.equal(synchronizeMailSignatureBackground(carrier), true);
    assert.equal(style['background-size'], '150% auto');
    assert.equal(style['background-image'], 'none');
    assert.equal(attributes['data-rt-bg-tablet'], '175');
    assert.equal(attributes['data-rt-bg-mobile'], '200');
    assert.equal(synchronizeMailSignatureBackground(carrier), false);
    const frameAttributes = { class: 'rt-sign-content-frame', height: '200' };
    let frameStyle = { height: '200px' };
    const frame = { getAttributes: () => frameAttributes, getStyle: () => frameStyle, parent: () => carrier,
        addAttributes: (value) => Object.assign(frameAttributes, value), removeAttributes: (key) => delete frameAttributes[key],
        setStyle: (value) => { frameStyle = value; }, removeStyle: (key) => delete frameStyle[key] };
    assert.equal(synchronizeMailSignatureFixedGeometry(frame), false);
    assert.equal(synchronizeMailSignatureFlowGeometry(frame), true);
    assert.equal(frameAttributes.height, undefined);
    assert.deepEqual(frameStyle, { width: '100%', 'border-collapse': 'collapse' });
});

test('portable media requirements follow the imported signature instead of the open draft', () => {
    const requirements = {
        signature: {
            v7: ['common.png', 'zug-dampf-light.gif', 'zug-dampf-idle-light.gif'],
            v8: ['common.png', 'zug-dampf-v8-light.gif'],
            v9: ['common.png', 'zug-dampf-v8-light.gif'],
            v10: ['common.png', 'zug-dampf-v8-light.gif'],
            v11: ['common.png', 'zug-dampf-v8-light.gif'],
            v12: ['common.png', 'zug-dampf-v12-light.gif'],
            v13: ['common.png', 'zug-dampf-v13-light.gif'],
            v14: ['common.png', 'zug-dampf-v13-light.gif'],
            v15: [
                'common.png',
                'zug-dampf-v15-light.gif',
                'zug-dampf-v15-light.png',
                'wortmarke-signature-v15-light.gif',
                'wortmarke-signature-v15-light.png',
            ],
            v16: [
                'common.png',
                'zug-dampf-v15-light.gif',
                'zug-dampf-v15-light.png',
                'wortmarke-signature-v15-light.gif',
                'wortmarke-signature-v15-light.png',
            ],
            v17: [
                'common.png',
                'zug-dampf-v15-light.gif',
                'zug-dampf-v15-light.png',
                'wortmarke-signature-v15-light.gif',
                'wortmarke-signature-v15-light.png',
            ],
            v18: [
                'common.png',
                'zug-dampf-v15-light.gif',
                'zug-dampf-v15-light.png',
                'wortmarke-signature-v15-light.gif',
                'wortmarke-signature-v15-light.png',
            ],
            v19: [
                'common.png',
                'zug-dampf-v19-light.gif',
                'zug-dampf-v19-light.png',
                'wortmarke-signature-v19-light.gif',
                'wortmarke-signature-v19-light.png',
                'icon-rt-v19-light.gif',
                'icon-rt-v19-light.png',
            ],
            v20: [
                'common.png',
                'zug-dampf-v19-light.gif',
                'zug-dampf-v19-light.png',
                'wortmarke-signature-v19-light.gif',
                'wortmarke-signature-v19-light.png',
                'icon-rt-v19-light.gif',
                'icon-rt-v19-light.png',
            ],
            v21: [
                'common.png',
                'zug-dampf-v19-light.gif',
                'zug-dampf-v19-light.png',
                'wortmarke-signature-v19-light.gif',
                'wortmarke-signature-v19-light.png',
                'icon-rt-v19-light.gif',
                'icon-rt-v19-light.png',
            ],
        },
        template: {
            default: ['icon-rt-light.gif'],
        },
    };

    assert.deepEqual(
        resolvePortableMediaRequirementIds(requirements, 'signature', '<tr><td>v7</td></tr>'),
        requirements.signature.v7,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v8"><td>v8</td></tr>',
        ),
        requirements.signature.v8,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v9"><td>v9</td></tr>',
        ),
        requirements.signature.v9,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v10"><td>v10</td></tr>',
        ),
        requirements.signature.v10,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v11"><td>v11</td></tr>',
        ),
        requirements.signature.v11,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v12"><td>v12</td></tr>',
        ),
        requirements.signature.v12,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v13"><td>v13</td></tr>',
        ),
        requirements.signature.v13,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v14"><td>v14</td></tr>',
        ),
        requirements.signature.v14,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v15"><td>v15</td></tr>',
        ),
        requirements.signature.v15,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v16"><td>v16</td></tr>',
        ),
        requirements.signature.v16,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v17"><td>v17</td></tr>',
        ),
        requirements.signature.v17,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v18"><td>v18</td></tr>',
        ),
        requirements.signature.v18,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v19"><td>v19</td></tr>',
        ),
        requirements.signature.v19,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v20"><td>v20</td></tr>',
        ),
        requirements.signature.v20,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v21"><td>v21</td></tr>',
        ),
        requirements.signature.v21,
    );
    assert.deepEqual(
        resolvePortableMediaRequirementIds(requirements, 'template', '<table></table>'),
        requirements.template.default,
    );
    assert.throws(
        () => resolvePortableMediaRequirementIds(
            requirements,
            'signature',
            '<tr data-rt-artifact-version="v22"><td>unbekannt</td></tr>',
        ),
        /Medienvertrag ist nicht vollständig konfiguriert/,
    );
    assert.throws(
        () => resolvePortableMediaRequirementIds({}, 'signature', '<tr></tr>'),
        /Medienvertrag ist nicht vollständig konfiguriert/,
    );
});

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
    const sharedPreviewSetupSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf('function setupSharedElementPreview'),
        lmzBuilderSource.indexOf('function setupMotionEditor'),
    );
    const motionEditorSetupSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf('function setupMotionEditor'),
        lmzBuilderSource.indexOf('function waitForNextPaint'),
    );
    const mailRuntimeOptionsSource = mailBuilderSource.slice(
        mailBuilderSource.indexOf('const instance = await runtime.create'),
        mailBuilderSource.indexOf('// "Speichern" ginge weiterhin durch'),
    );
    const mailUpdateSource = mailBuilderSource.slice(
        mailBuilderSource.indexOf('const onComponentUpdate ='),
        mailBuilderSource.indexOf("editor.on?.('component:add'"),
    );
    const mailStyleUpdateSource = mailBuilderSource.slice(
        mailBuilderSource.indexOf('const onComponentStyleUpdate ='),
        mailBuilderSource.indexOf('protectMailSystemComponents(editor);', mailBuilderSource.indexOf('const onComponentStyleUpdate =')),
    );
    const mailDestroySource = mailBuilderSource.slice(
        mailBuilderSource.indexOf('destroy() {'),
        mailBuilderSource.indexOf('const inferredKind ='),
    );
    const runtimeUpdateSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf("editor.on('update'"),
        lmzBuilderSource.indexOf("editor.on('component:selected'", lmzBuilderSource.indexOf("editor.on('update'")),
    );
    const runtimeLoadSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf('async load() {'),
        lmzBuilderSource.indexOf('async function initFromStudioNode'),
    );
    const grapesRuntimeSource = lmzBuilderSource.slice(
        lmzBuilderSource.indexOf('async function ensureGrapesJs'),
        lmzBuilderSource.indexOf('function resolveEndpoint'),
    );

    assert.match(motionTraitSource, /component\.get\?\.\('traits'\)/);
    assert.doesNotMatch(motionTraitSource, /component\.getTrait\?/);
    assert.doesNotMatch(motionSetupSource, /component:update/);
    assert.match(motionSetupSource, /motionOptions/);
    assert.match(motionSetupSource, /!motionEnabled && !integrationsEnabled/);
    assert.match(sharedPreviewSetupSource, /integrations === false/);
    assert.match(motionEditorSetupSource, /if \(!enabled\)[\s\S]*?destroy\(\) \{\}/);
    assert.match(mailRuntimeOptionsSource, /motion:\s*\{ enabled: false, preview: false \}/);
    assert.match(mailRuntimeOptionsSource, /integrations:\s*false/);
    assert.match(runtimeUpdateSource, /state\.loading \|\| state\.destroyed/);
    assert.match(runtimeLoadSource, /await api\.load\(\)/);
    assert.match(runtimeLoadSource, /await loadProject\(\);[\s\S]*?state\.loading = false;[\s\S]*?await loadAssetLibrary/);
    assert.match(runtimeLoadSource, /api\.destroy\(\)/);
    assert.match(grapesRuntimeSource, /__lmzBuilderGrapesJsAssetUrl/);
    assert.match(grapesRuntimeSource, /String\(current\?\.version \|\| ''\) === GRAPESJS_VERSION/);
    assert.match(grapesRuntimeSource, /trackedSource === expectedSource/);
    assert.match(lmzBuilderSource, /searchParams\.get\('runtime'\) \|\| currentScriptUrl\.searchParams\.get\('v'\)/);
    assert.match(mailUpdateSource, /synchronizeMailTrainLayerAlignment\(component\)/);
    assert.match(mailUpdateSource, /synchronizeMailContentImage\(component\)/);
    assert.doesNotMatch(mailUpdateSource, /protectMailSystemComponents/);
    assert.match(mailStyleUpdateSource, /changes\?\.style/);
    assert.match(mailStyleUpdateSource, /Object\.keys\(styleChanges\)/);
    assert.match(mailStyleUpdateSource, /synchronizeMailPresentationAttributes\(component, property\)/);
    assert.match(mailStyleUpdateSource, /editor\.on\?\.\('component:styleUpdate', onComponentStyleUpdate\)/);
    assert.match(mailDestroySource, /editor\.off\?\.\('component:styleUpdate', onComponentStyleUpdate\)/);
    assert.equal(masterLayoutSource.split("'resources/js/app.js'").length - 1, 1);
    assert.equal(
        masterLayoutSource.indexOf("'resources/js/app.js'") < masterLayoutSource.indexOf('</head>'),
        true,
    );
    const bodyStart = masterLayoutSource.search(/<body\s/);
    assert.notEqual(bodyStart, -1);
    assert.doesNotMatch(masterLayoutSource.slice(bodyStart), /resources\/js\/app\.js/);
});

test('mail canvas uses authoritative theme colors without leaking CSS or changing config', () => {
    const light = Object.freeze({
        PAGE_BG: '#010203', SURFACE_BG: '#020304', CARD_BG: '#030405', SOFT_BG: '#040506',
        TEXT_PRIMARY: '#050607', TEXT_SECONDARY: '#060708', TEXT_MUTED: '#070809', BORDER: '#08090a',
        SIGNATURE_BG: '#090a0b', SIGNATURE_LEGAL_BG: '#0a0b0c', SIGNATURE_TEXT_PRIMARY: '#0b0c0d',
        SIGNATURE_CONTACT_TEXT: '#0c0d0e', SIGNATURE_META_TEXT: '#0d0e0f', SIGNATURE_TEXT_MUTED: '#0e0f10',
        SIGNATURE_LEGAL_TEXT: '#0f1011', SIGNATURE_ACCENT: '#101112', SIGNATURE_BORDER: '#111213',
        SIGNATURE_RULE: '#121314', THEME_LABEL: 'not-a-color', UNKNOWN_TOKEN: '#c0ffee',
    });
    const dark = Object.freeze({ ...light, SIGNATURE_BG: '#131415', SIGNATURE_RULE: '#141516' });
    const previewThemeValues = Object.freeze({ light, dark });
    const snapshot = JSON.stringify(previewThemeValues);
    for (const theme of ['light', 'dark']) {
        const css = mailCanvasStyles(theme, {}, '', previewThemeValues);
        for (const [token, value] of Object.entries(previewThemeValues[theme])) {
            if (token === 'THEME_LABEL' || token === 'UNKNOWN_TOKEN') continue;
            const rule = css.split('\n').find((line) => line.includes(`{{${token}}}`));
            assert.ok(rule?.includes(`: ${value} !important;`), `${theme}/${token}`);
        }
        assert.doesNotMatch(css, /c0ffee|not-a-color/);
    }
    assert.equal(JSON.stringify(previewThemeValues), snapshot);
    assert.equal(mailCanvasStyles('unknown', {}, '', previewThemeValues), mailCanvasStyles('light', {}, '', previewThemeValues));

    for (const value of ['red; } body { display:none', 'url(https://evil.example/color)', '#000000\n}', 42, {}, null]) {
        const css = mailCanvasStyles('light', {}, '', { light: { SIGNATURE_BG: value } });
        const rule = css.split('\n').find((line) => line.includes('{{SIGNATURE_BG}}'));
        assert.ok(rule.includes(': #ffffff !important;'));
        assert.doesNotMatch(css, /evil\.example|body \{ display:none/);
    }
    assert.equal(mailCanvasStyles('light', {}, '', null), mailCanvasStyles('light'));
    assert.match(mailCanvasStyles('dark'), /\{\{SIGNATURE_RULE\}\}[^\n]*: #252c35 !important;/);

    // Verify both the initial iframe and later theme switches use the server input.
    const builderSource = readFileSync(new URL('../../resources/js/mail-builder.js', import.meta.url), 'utf8');
    const viewSource = readFileSync(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8');
    assert.match(viewSource, /previewThemeValues:\s*config\.previewThemeValues \|\| \{\}/);
    assert.equal((builderSource.match(/mailCanvasStyles\(activeTheme, previewAssets, responsiveCssForTheme\(activeTheme\), previewThemeValues\)/g) || []).length, 2);
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

    const responsiveCss = '/* rt-responsive-sentinel */\n@media only screen and (max-width: 860px) { tr.rt-stack > td { display:block !important; width:100% !important; } }';
    const light = mailCanvasStyles('light', previewAssets, responsiveCss);
    const dark = mailCanvasStyles('dark', previewAssets);
    const editorTrainSelector = 'body.rt-mail-canvas table[data-rt-mail-signature-canvas] tr[data-rt-artifact-version="v19"] .rt-sign-stage > .rt-sign-train-layer';

    assert.match(light, /\[bgcolor="\{\{PAGE_BG\}\}"\]/);
    assert.match(light, /#eef1f4/);
    assert.match(light, /mail-assets\/light-logo\.gif/);
    assert.doesNotMatch(light, /mail-assets\/light-train\.gif/);
    assert.match(light, /mail-assets\/phone-icon\.png/);
    assert.match(light, /mail-assets\/location-icon\.png/);
    assert.match(light, /max-width:\s*860px/);
    assert.match(light, /tr\.rt-stack > td/);
    assert.match(light, /body\.rt-mail-canvas\s*\{[^}]*padding:\s*0;/s);
    assert.doesNotMatch(light, /padding:\s*28px 0/);
    assert.match(light, /body\.rt-mail-canvas table\s*\{[^}]*border-spacing:\s*0;[^}]*mso-table-lspace:\s*0pt;[^}]*mso-table-rspace:\s*0pt;/s);
    assert.match(light, /body\.rt-mail-canvas img\s*\{[^}]*display:\s*block;[^}]*-ms-interpolation-mode:\s*bicubic;/s);
    assert.equal(light.indexOf(responsiveCss) < light.indexOf(editorTrainSelector), true);
    assert.match(light, /body\.rt-mail-canvas table\[data-rt-mail-signature-canvas\] tr\[data-rt-artifact-version="v19"\] \.rt-sign-stage > \.rt-sign-train-layer\s*\{[^}]*position:\s*absolute !important;[^}]*left:\s*0 !important;[^}]*right:\s*0 !important;[^}]*bottom:\s*0 !important;[^}]*height:\s*100% !important;[^}]*max-height:\s*none !important;[^}]*margin:\s*0 !important;/s);
    assert.match(light, /tr\[data-rt-artifact-version="v19"\][^{}]+\.rt-sign-train-layer > \.rt-sign-train-frame,\s*body\.rt-mail-canvas[^{}]+\.rt-sign-train-slot\s*\{[^}]*height:\s*100% !important;/s);
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

test('schema 22 to 25 train overlaps migrate deterministically to the current pixel contract', () => {
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
        assert.equal(outgoing.project.railtime.schema, MAIL_SIGNATURE_SCHEMA, label);
    });
});

test('schema 28 keeps V18 and V20 geometry stable while V19 retains its isolated forwarding fallback', () => {
    const serializeVersion = (version) => {
        let original = `<tr data-rt-artifact-version="${version}"><td class="rt-sign-cell">${canonicalSignatureStage('Inhalt')}</td></tr>`
            + '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>';
        if (version === 'v16' || ['v18', 'v19', 'v20'].includes(version)) {
            original = original.replace('data-rt-layer-mobile="train"', 'data-rt-layer-mobile="stop60"');
        }
        const project = projectForMailDocument({
            builderData: {
                pages: [{ component: original }],
                styles: [],
                railtime: { document: 'signature', schema: 25 },
            },
            css: '',
        }, () => [], { kind: 'signature', environment: { DOMParser } });

        return serializeMailDocumentForSave({
            project,
            html: project.pages[0].component,
            kind: 'signature',
            environment: { DOMParser },
        });
    };
    const signatureDocument = (html) => new DOMParser().parseFromString(
        `<table><tbody>${html}</tbody></table>`,
        'text/html',
    );

    const v14 = serializeVersion('v14');
    const v14Document = signatureDocument(v14.html);
    const v14Stage = v14Document.querySelector('.rt-sign-stage');
    const v14Train = v14Document.querySelector('img.rt-sign-train[data-rt-train]');
    assert.equal(v14Stage?.getAttribute('style'), 'position:relative;height:200px;max-height:200px;overflow:hidden;');
    assert.equal(v14Train?.getAttribute('width'), '720');
    assert.equal(v14Train?.hasAttribute('height'), false);
    assert.equal(v14.project.railtime.schema, MAIL_SIGNATURE_SCHEMA);

    const v15 = serializeVersion('v15');
    const v15Document = signatureDocument(v15.html);
    const v15Stage = v15Document.querySelector('.rt-sign-stage');
    const v15Layer = v15Document.querySelector('.rt-sign-train-layer[data-rt-layer-train]');
    const v15Train = v15Document.querySelector('img.rt-sign-train[data-rt-train]');
    const v15Content = v15Document.querySelector('table.rt-sign-content-frame');
    assert.equal(v15Stage?.getAttribute('style'), 'position:relative;height:auto;min-height:200px;overflow:visible;');
    assert.match(v15Layer?.getAttribute('style') || '', /^position:relative;z-index:0;display:block;[^\r\n]*margin-bottom:-200px;/);
    assert.equal(v15Train?.getAttribute('width'), '720');
    assert.equal(v15Train?.getAttribute('height'), '61');
    assert.match(v15Content?.getAttribute('style') || '', /^position:relative;z-index:1;width:100%;height:200px;/);
    assert.equal(v15.project.railtime.schema, MAIL_SIGNATURE_SCHEMA);

    const v16 = serializeVersion('v16');
    const v16Document = signatureDocument(v16.html);
    const v16Stage = v16Document.querySelector('.rt-sign-stage');
    const v16Layer = v16Document.querySelector('.rt-sign-train-layer[data-rt-layer-train]');
    const v16Train = v16Document.querySelector('img.rt-sign-train[data-rt-train]');
    const v16Content = v16Document.querySelector('table.rt-sign-content-frame');
    assert.equal(v16Stage?.getAttribute('style'), 'position:relative;height:auto;min-height:200px;overflow:visible;');
    assert.match(v16Layer?.getAttribute('style') || '', /^position:relative;z-index:0;display:block;[^\r\n]*margin-bottom:-200px;/);
    assert.equal(v16Layer?.getAttribute('data-rt-layer-mobile'), 'stop60');
    assert.equal(v16Train?.getAttribute('width'), '720');
    assert.equal(v16Train?.getAttribute('height'), '61');
    assert.match(v16Content?.getAttribute('style') || '', /^position:relative;z-index:1;width:100%;height:200px;/);
    assert.equal(v16.project.railtime.schema, MAIL_SIGNATURE_SCHEMA);

    const v19 = serializeVersion('v19');
    const v19Document = signatureDocument(v19.html);
    const v19Stage = v19Document.querySelector('.rt-sign-stage');
    const v19Layer = v19Document.querySelector('.rt-sign-train-layer[data-rt-layer-train]');
    const v19Frame = v19Document.querySelector('table.rt-sign-train-frame');
    const v19Slot = v19Document.querySelector('td.rt-sign-train-slot');
    const v19Train = v19Document.querySelector('img.rt-sign-train[data-rt-train]');
    const v19Content = v19Document.querySelector('table.rt-sign-content-frame');
    assert.equal(v19Stage?.getAttribute('style'), 'position:relative;height:auto;min-height:200px;overflow:visible;');
    assert.match(v19Layer?.getAttribute('style') || '', /^position:absolute;z-index:0;left:0;right:0;top:auto;bottom:0;display:block;[^\r\n]*height:61px;[^\r\n]*margin:0;/);
    assert.doesNotMatch(v19Layer?.getAttribute('style') || '', /margin-bottom/);
    assert.equal(v19Layer?.getAttribute('data-rt-layer-mobile'), 'stop60');
    assert.equal(v19Frame?.getAttribute('height'), '61');
    assert.equal(v19Slot?.getAttribute('height'), '61');
    assert.equal(v19Train?.getAttribute('width'), '720');
    assert.equal(v19Train?.getAttribute('height'), '61');
    assert.match(v19Train?.getAttribute('style') || '', /display:block;width:720px;max-width:100%;height:auto;margin:0;/);
    assert.match(v19Content?.getAttribute('style') || '', /^position:relative;z-index:1;width:100%;height:200px;/);
    assert.equal(v19.project.railtime.schema, MAIL_SIGNATURE_SCHEMA);

    const v18 = serializeVersion('v18');
    const v20 = serializeVersion('v20');
    const v20Document = signatureDocument(v20.html);
    const v20Stage = v20Document.querySelector('.rt-sign-stage');
    const v20Layer = v20Document.querySelector('.rt-sign-train-layer[data-rt-layer-train]');
    const v20Frame = v20Document.querySelector('table.rt-sign-train-frame');
    const v20Slot = v20Document.querySelector('td.rt-sign-train-slot');
    const v20Train = v20Document.querySelector('img.rt-sign-train[data-rt-train]');
    const v20Content = v20Document.querySelector('table.rt-sign-content-frame');
    assert.equal(v20Stage?.getAttribute('style'), 'position:relative;height:auto;min-height:200px;overflow:visible;');
    assert.match(v20Layer?.getAttribute('style') || '', /^position:relative;z-index:0;display:block;[^\r\n]*height:200px;[^\r\n]*margin-bottom:-200px;/);
    assert.equal(v20Layer?.getAttribute('data-rt-layer-mobile'), 'stop60');
    assert.equal(v20Frame?.getAttribute('height'), '200');
    assert.equal(v20Slot?.getAttribute('height'), '200');
    assert.equal(v20Train?.getAttribute('width'), '720');
    assert.equal(v20Train?.hasAttribute('height'), false);
    assert.match(v20Train?.getAttribute('style') || '', /display:inline-block;width:100%;max-width:none;height:auto;margin:0;/);
    assert.match(v20Content?.getAttribute('style') || '', /^position:relative;z-index:1;width:100%;height:200px;/);
    assert.equal(v20.project.railtime.schema, MAIL_SIGNATURE_SCHEMA);
    assert.equal(
        v20.html.replace('data-rt-artifact-version="v20"', 'data-rt-artifact-version="v18"'),
        v18.html,
        'V20 muss bis auf den Artefaktmarker bytegleich die V18-Geometrie serialisieren',
    );

    const migratedV19 = projectForMailDocument({
        builderData: {
            pages: [{
                component: v19.html.replace(
                    'data-rt-artifact-version="v19"',
                    'data-rt-artifact-version="v20"',
                ),
            }],
            styles: [],
            railtime: { document: 'signature', schema: MAIL_SIGNATURE_SCHEMA },
        },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });
    const migratedV19Document = signatureDocument(migratedV19.pages[0].component);
    assert.match(
        migratedV19Document.querySelector('.rt-sign-train-layer')?.getAttribute('style') || '',
        /^position:relative;z-index:0;display:block;[^\r\n]*height:200px;[^\r\n]*margin-bottom:-200px;/,
    );
    assert.equal(migratedV19Document.querySelector('.rt-sign-train')?.hasAttribute('height'), false);
});

test('schema 28 keeps V21 in strict content-first flow without overlay geometry', () => {
    const original = `<tr data-rt-artifact-version="v21"><td class="rt-sign-cell">${flowSafeSignatureStage('<img src="{{LOGO_SRC}}" alt="{{FIRMENNAME}}"><p>Inhalt</p>')}</td></tr>`
        + '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>';
    const project = projectForMailDocument({
        builderData: {
            pages: [{ component: original }],
            styles: [],
            railtime: { document: 'signature', schema: 28 },
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
    const stage = document_.querySelector('tr[data-rt-artifact-version="v21"] .rt-sign-stage');
    const content = stage?.querySelector(':scope > table.rt-sign-content-frame');
    const layer = stage?.querySelector(':scope > div.rt-sign-train-layer[data-rt-layer-train]');
    const frame = layer?.querySelector(':scope > table.rt-sign-train-frame');
    const slot = frame?.querySelector('td.rt-sign-train-slot');
    const train = slot?.querySelector(':scope > img.rt-sign-train[data-rt-train]');

    assert.equal(MAIL_SIGNATURE_SCHEMA, 29);
    assert.equal(outgoing.project.railtime.schema, MAIL_SIGNATURE_SCHEMA);
    assert.equal(stage?.children.length, 2);
    assert.equal(stage?.firstElementChild, content);
    assert.equal(stage?.lastElementChild, layer);
    assert.equal(stage?.getAttribute('style'), 'display:block;width:100%;overflow:visible;');
    assert.equal(content?.hasAttribute('height'), false);
    assert.equal(content?.getAttribute('style'), 'width:100%;border-collapse:collapse;');
    assert.equal(layer?.getAttribute('data-rt-layer-align'), 'left');
    assert.equal(layer?.getAttribute('data-rt-layer-size'), '100');
    assert.equal(layer?.getAttribute('data-rt-layer-mobile'), 'left');
    assert.equal(
        layer?.getAttribute('style'),
        'display:block;width:100%;max-width:720px;margin:0 auto 0 0;overflow:hidden;font-size:0;line-height:0;text-align:left;',
    );
    assert.equal(frame?.getAttribute('width'), '100%');
    assert.equal(frame?.getAttribute('height'), '61');
    assert.equal(frame?.getAttribute('style'), 'width:100%;height:61px;border-collapse:collapse;');
    assert.equal(slot?.getAttribute('height'), '61');
    assert.equal(slot?.getAttribute('valign'), 'bottom');
    assert.equal(train?.getAttribute('width'), '720');
    assert.equal(train?.getAttribute('height'), '61');
    assert.equal(
        train?.getAttribute('style'),
        'display:block;width:100%;max-width:720px;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;',
    );
    assert.equal((outgoing.html.match(/\{\{TRAIN_SRC\}\}/g) || []).length, 1);
    assert.doesNotMatch(outgoing.html, /(?:position|z-index|background-image)\s*:/i);
    assert.doesNotMatch(outgoing.html, /margin(?:-(?:top|right|bottom|left))?\s*:\s*-/i);
});

test('V25 preserves proportional full-width train flow on save and rejects the old fixed-height frame', () => {
    const html = `<tr data-rt-artifact-version="v25"><td class="rt-sign-cell">${fluidSignatureStage('<p>V25 Inhalt</p>')}</td></tr><!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtstext</td></tr>`;
    const options = { kind: 'signature', environment: { DOMParser } };
    const project = projectForMailDocument({ html, builderData: { pages: [{ component: html }], railtime: { schema: 29, document: 'signature' } } }, () => [], options);
    const saved = serializeMailDocumentForSave({ ...options, project, html: project.pages[0].component, baselineHtml: html });
    const doc = new DOMParser().parseFromString(`<html><body><table>${saved.html}</table></body></html>`, 'text/html');
    const stage = doc.querySelector('.rt-sign-stage');
    assert(stage.firstElementChild.classList.contains('rt-sign-content-frame'));
    assert(stage.lastElementChild.classList.contains('rt-sign-train-layer'));
    for (const selector of ['.rt-sign-train-frame', '.rt-sign-train-slot']) {
        const element = doc.querySelector(selector);
        assert(!element.hasAttribute('height'));
        assert.doesNotMatch(element.getAttribute('style'), /(?:^|;)(?:min-|max-)?height:/);
    }
    const image = doc.querySelector('img.rt-sign-train');
    assert.equal(image.getAttribute('width'), '720');
    assert.equal(image.getAttribute('height'), '61');
    assert.equal(image.style.width, '100%');
    assert.equal(image.style.maxWidth, 'none');
    assert.equal(image.style.height, 'auto');
    assert.equal(doc.querySelector('.rt-sign-train-layer').style.maxWidth, 'none');
    assert.doesNotMatch(saved.html, /background-image|position:|margin-bottom:-|height:200px/);
    const reload = projectForMailDocument({ html: saved.html, builderData: saved.project }, () => [], options);
    assert.equal(serializeMailDocumentForSave({ ...options, project: reload, html: reload.pages[0].component, baselineHtml: saved.html }).html, saved.html);
    for (const invalid of [
        html.replace('max-width:none', 'max-width:720px'),
        html.replace('class="rt-sign-train-frame"', 'class="rt-sign-train-frame" height="61"'),
        html.replace('class="rt-sign-train-slot"', 'class="rt-sign-train-slot" height="61"'),
        html.replace('width:100%;border-collapse:collapse;', 'width:100%;height:61px;border-collapse:collapse;'),
        html.replace('padding:0;text-align:left;', 'height:61px;padding:0;text-align:left;'),
        html.replace('margin:0 auto 0 0;', 'margin:0 auto 0 0;margin-bottom:-61px;'),
        html.replace('height="61" alt=', 'alt='),
    ]) {
        assert.throws(() => projectForMailDocument({ html: invalid, builderData: { pages: [{ component: invalid }] } }, () => [], options));
    }
});

test('V21 rejects overlay remnants and never migrates an invalid flow into a legacy stage', () => {
    const originalStage = flowSafeSignatureStage('Inhalt');
    const variants = [
        originalStage.replace(
            /(<table class="rt-sign-content-frame"[\s\S]*?<\/table>)(<div class="rt-sign-train-layer"[\s\S]*?<\/div>)/,
            '$2$1',
        ),
        originalStage.replace(
            'display:block;width:100%;overflow:visible;',
            'position:relative;display:block;width:100%;overflow:visible;',
        ),
        originalStage.replace('height="61" alt=""', 'alt=""'),
        originalStage.replace(
            'margin:0 auto 0 0;',
            'margin:0 auto 0 0;margin-bottom:-61px;',
        ),
        originalStage.replace(
            'margin:0 auto 0 0;',
            'margin:0 0 -1px 0;',
        ),
        originalStage.replace(
            'overflow:visible;',
            'overflow:visible;background-image:url(\'{{TRAIN_SRC}}\');',
        ),
        originalStage.replace(
            'class="rt-sign-stage"',
            'class="rt-sign-stage fremde-klasse"',
        ),
        originalStage.replace(
            'class="rt-sign-content-frame"',
            'class="rt-sign-content-frame fremde-klasse"',
        ),
        originalStage.replace(
            '</table></div></div>',
            '</table><span>Fremder Layer-Inhalt</span></div></div>',
        ),
        originalStage.replace(
            '<img class="rt-sign-train"',
            '<span>Fremder Slot-Inhalt</span><img class="rt-sign-train"',
        ),
        originalStage.replace(
            'display:block;width:100%;overflow:visible;',
            'display:block;width:100%;overflow:visible;transform:translateX(0);',
        ),
        originalStage.replace(
            'width:100%;border-collapse:collapse;',
            'width:100%;border-collapse:collapse;display:flex;',
        ),
        originalStage.replace(
            'width:100%;border-collapse:collapse;',
            'width:100%;border-collapse:collapse;height:200px;',
        ),
    ];

    variants.forEach((stage) => {
        const html = `<tr data-rt-artifact-version="v21"><td class="rt-sign-cell">${stage}</td></tr>`
            + '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>';
        assert.throws(() => projectForMailDocument({
            builderData: {
                pages: [{ component: html }],
                styles: [],
                railtime: { document: 'signature', schema: 28 },
            },
            css: '',
        }, () => [], { kind: 'signature', environment: { DOMParser } }));
    });
});

test('V21 permits plain inline background colors without reopening image backgrounds', () => {
    const original = `<tr data-rt-artifact-version="v21"><td class="rt-sign-cell" style="background:#fff;">${flowSafeSignatureStage('Inhalt')}</td></tr>`
        + '<!-- RT_SIGNATURE_MAIN_END --><tr><td>Rechtliches</td></tr>';

    assert.doesNotThrow(() => projectForMailDocument({
        builderData: {
            pages: [{ component: original }],
            styles: [],
            railtime: { document: 'signature', schema: 28 },
        },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }));
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
    assert.doesNotMatch(outgoing.html, /body\.rt-mail-canvas|position:\s*absolute[^;]*;[^"']*bottom:\s*0[^;]*;[^"']*height:\s*100%/);
    assert.doesNotMatch(outgoing.css, /data-rt-mail-signature-canvas|rt-sign-train-layer/);
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
        ['left', 'center', 'train', 'stop65', 'stop60', 'right'],
    );
    assert.deepEqual(
        trainLayer.state.traits[1].options.map((option) => option.id),
        ['100', '108.67', '125', '150', '200'],
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
        '108.67': ['108.67%', '0 0 0 -8.67%'],
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
    attributes['data-rt-layer-mobile'] = 'stop60';
    synchronizeMailTrainLayerAlignment(component);
    assert.equal(attributes['data-rt-layer-mobile'], 'stop60');
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

for (const version of ['v21', 'v25']) test(`${version.toUpperCase()} editor synchronization restores its flow geometry and bypasses legacy synchronizers`, () => {
    const component = (attributes = {}, tagName = 'div', children = [], style = {}) => {
        const state = { attributes, tagName, style };
        const item = {
            components: () => ({ models: children }),
            get: (key) => state[key],
            getAttributes: () => attributes,
            getStyle: () => style,
            addAttributes: (next) => Object.assign(attributes, next),
            removeAttributes: (name) => { delete attributes[name]; },
            setStyle: (next) => {
                Object.keys(style).forEach((name) => delete style[name]);
                Object.assign(style, next);
            },
            removeStyle: (name) => { delete style[name]; },
            parent: () => item.parentComponent || null,
        };
        children.forEach((child) => { child.parentComponent = item; });
        return item;
    };
    const train = component(
        { class: 'rt-sign-train', 'data-rt-train': '', width: '1200' },
        'img',
        [],
        { position: 'absolute', width: '200%', 'margin-bottom': '-61px' },
    );
    const slot = component({ class: 'rt-sign-train-slot', height: '200' }, 'td', [train], { height: '200px' });
    const frameRow = component({}, 'tr', [slot]);
    const frameBody = component({}, 'tbody', [frameRow]);
    const frame = component({ class: 'rt-sign-train-frame', width: '100%', height: '200' }, 'table', [frameBody], { height: '200px' });
    const layer = component({
        class: 'rt-sign-train-layer',
        'data-rt-layer-train': '',
        'data-rt-layer-align': 'center',
        'data-rt-layer-size': '200',
        'data-rt-layer-mobile': 'stop60',
    }, 'div', [frame], { position: 'absolute', 'margin-bottom': '-200px' });
    const content = component({ class: 'rt-sign-content-frame', height: '200' }, 'table', [], { height: '200px', 'z-index': '1' });
    const stage = component({ class: 'rt-sign-stage' }, 'div', [content, layer], { position: 'relative', height: '200px' });
    const cell = component({ class: 'rt-sign-cell' }, 'td', [stage]);
    const artifact = component({ 'data-rt-artifact-version': version }, 'tr', [cell]);

    [stage, content, layer, frame, slot, train].forEach((node) => {
        assert.equal(synchronizeMailSignatureFlowGeometry(node), true);
    });
    assert.deepEqual(stage.getStyle(), { display: 'block', width: '100%', overflow: 'visible' });
    assert.equal(content.getAttributes().height, undefined);
    assert.deepEqual(content.getStyle(), { width: '100%', 'border-collapse': 'collapse' });
    assert.deepEqual(layer.getAttributes(), {
        class: 'rt-sign-train-layer',
        'data-rt-layer-train': '',
        'data-rt-layer-align': 'left',
        'data-rt-layer-size': '100',
        'data-rt-layer-mobile': 'left',
    });
    assert.deepEqual(layer.getStyle(), {
        display: 'block',
        width: '100%',
        'max-width': version === 'v25' ? 'none' : '720px',
        margin: '0 auto 0 0',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    });
    assert.equal(frame.getAttributes().height, version === 'v25' ? undefined : '61');
    assert.equal(slot.getAttributes().height, version === 'v25' ? undefined : '61');
    assert.equal(frame.getStyle().height, version === 'v25' ? undefined : '61px');
    assert.equal(slot.getStyle().height, version === 'v25' ? undefined : '61px');
    assert.equal(slot.getAttributes().valign, 'bottom');
    assert.equal(train.getAttributes().width, '720');
    assert.equal(train.getAttributes().height, '61');
    assert.deepEqual(train.getStyle(), {
        display: 'block',
        width: '100%',
        'max-width': version === 'v25' ? 'none' : '720px',
        height: 'auto',
        margin: '0',
        border: '0',
        outline: 'none',
        'text-decoration': 'none',
        'vertical-align': 'bottom',
        'mso-hide': 'all',
    });

    [stage, content, layer, frame, slot, train].forEach((systemNode) => {
        assert.equal(synchronizeMailPresentationAttributes(systemNode, 'text-align'), false);
        assert.equal(systemNode.getAttributes().align, undefined);
    });
    assert.equal(slot.getAttributes().valign, 'bottom');

    const snapshot = JSON.stringify({
        stage: stage.getStyle(),
        layer: layer.getStyle(),
        train: train.getStyle(),
    });
    assert.equal(synchronizeMailSignatureFixedGeometry(stage), false);
    assert.equal(synchronizeMailTrainLayerAlignment(layer), false);
    assert.equal(JSON.stringify({
        stage: stage.getStyle(),
        layer: layer.getStyle(),
        train: train.getStyle(),
    }), snapshot);
    assert.ok(artifact);
});

test('mail editor no longer offers misleading train background controls', () => {
    const exposedProperties = MAIL_STYLE_SECTORS.flatMap((sector) => [
        ...(sector.buildProps || []),
        ...(sector.properties || []).map((property) => (
            typeof property === 'string' ? property : (property.property || property.extend)
        )),
    ]);
    assert.equal(MAIL_STYLE_SECTORS.some((item) => item.id === 'rt-mail-train-background'), false);
    assert.equal(MAIL_STYLE_SECTORS.some((item) => item.buildProps?.includes('position')), false);
    assert.equal(MAIL_STYLE_SECTORS.some((item) => item.buildProps?.includes('z-index')), false);
    const spacingSector = MAIL_STYLE_SECTORS.find((item) => item.id === 'rt-mail-spacing');
    assert.equal(spacingSector?.buildProps?.includes('padding'), true);
    assert.equal(spacingSector?.buildProps?.includes('margin'), true);
    assert.equal(spacingSector?.buildProps?.some((property) => /^padding-/.test(property)), false);
    assert.equal(spacingSector?.buildProps?.some((property) => /^margin-/.test(property)), false);
    assert.equal(exposedProperties.includes('opacity'), false);
    assert.equal(exposedProperties.includes('overflow'), false);
    assert.equal(exposedProperties.includes('flex'), false);
    assert.equal(exposedProperties.includes('grid'), false);
    assert.deepEqual([...new Set(exposedProperties)].sort(), [...MAIL_SAFE_EDITABLE_STYLE_PROPERTIES].sort());
    assert.equal(exposedProperties.includes('background'), false);
    assert.equal(exposedProperties.includes('background-color'), true);
    assert.deepEqual(
        MAIL_SAFE_FONT_STACKS.map((font) => font.id),
        [
            'Arial,Helvetica,sans-serif',
            'Verdana,Arial,sans-serif',
            "'Trebuchet MS',Arial,sans-serif",
            "Georgia,'Times New Roman',serif",
            "'Times New Roman',Times,serif",
        ],
    );
    const progressiveRadius = MAIL_STYLE_SECTORS
        .flatMap((sector) => sector.properties || [])
        .find((property) => typeof property === 'object' && property.extend === 'border-radius');
    assert.match(progressiveRadius?.name || '', /progressiv.*Fallback/i);
    assert.equal(MAIL_EDITOR_MODE.id, 'mail');
    assert.equal(MAIL_EDITOR_MODE.contentModel, 'email');
    assert.equal(MAIL_EDITOR_MODE.styleStrategy, 'inline');
});

test('the bundled GrapesJS font selector writes all five full stacks inline in desktop and mobile preview', () => {
    // Use the shipped engine, not a substitute PropertySelect mock. Only the
    // font sector is needed: numeric controls require rendered browser views.
    const typography = MAIL_STYLE_SECTORS.find((sector) => sector.id === 'rt-mail-typography');
    assert.match(typography.name, /Typografie/);
    assert.equal(typography.open, true);
    const context = createContext({
        console,
        setTimeout: () => 0,
        clearTimeout: () => {},
        optionsJSON: JSON.stringify({
            ...MAIL_GJS_OPTIONS,
            headless: true,
            storageManager: false,
            styleManager: { sectors: [{ ...typography, properties: typography.properties.slice(0, 1) }] },
            deviceManager: { devices: [{ id: 'desktop', name: 'Desktop', width: '' }, { id: 'mobile', name: 'Mobil', width: '390px', widthMedia: '600px' }] },
        }),
    });
    runInContext(readFileSync(new URL('../../public/vendor/lmz-builder/2.4.5/lmz-builder-core.js', import.meta.url), 'utf8'), context);
    runInContext(`
        const editor = grapesjs.init(JSON.parse(optionsJSON));
        try {
            editor.addComponents({ type: 'text', tagName: 'p', attributes: { 'data-rt-mail-inline-source': '' }, content: 'Lesbarer Mailtext', style: { color: '#112233', 'font-family': "'Trebuchet MS',Arial,sans-serif" } });
            const text = editor.getWrapper().components().at(0);
            const property = editor.StyleManager.getProperty('rt-mail-typography', 'font-family');
            const results = [];
            for (const device of ['Desktop', 'Mobil']) {
                editor.setDevice(device);
                editor.StyleManager.select(text);
                for (const option of property.getOptions()) {
                    property.upValue(option.id);
                    results.push({ device, stack: option.id, style: editor.StyleManager.getSelected().getStyle(), html: editor.getHtml(), css: editor.getCss(), styles: editor.getProjectData().styles });
                }
            }
            output = JSON.stringify({ type: property.getType(), options: property.getOptions(), results });
        } finally { editor.destroy(); }
    `, context);
    const { type, options, results } = JSON.parse(context.output);
    assert.equal(type, 'select');
    assert.deepEqual(options, MAIL_SAFE_FONT_STACKS);
    assert.equal(results.length, 10);
    for (const result of results) {
        assert.equal(result.style['font-family'], result.stack);
        assert.equal(result.style.color, '#112233');
        assert.equal(result.styles.length, 1, 'only the imported inline style may be represented by its temporary GrapesJS rule');
        assert(!result.styles.some((rule) => rule.atRuleType || rule.mediaText), 'preview widths must not create competing font CSS rules');
        const p = new DOMParser().parseFromString(result.html, 'text/html').querySelector('p');
        assert.doesNotMatch(result.css, /@font-face|@import|@media/);

        for (const kind of ['template', 'signature']) {
            const baseline = kind === 'template'
                ? `<!doctype html><html><head><meta charset="utf-8"></head><body><table><tr><td>${p.outerHTML}</td></tr>{{SIGNATURE_BLOCK}}</table></body></html>`
                : backgroundSignature().replace('<p>Kontaktdaten</p>', p.outerHTML);
            const project = projectForMailDocument({ html: baseline, builderData: { pages: [{ component: baseline }] } }, () => [], { kind, environment: { DOMParser } });
            project.styles = result.styles;
            const saved = serializeMailDocumentForSave({ project, html: project.pages[0].component, kind, baselineHtml: baseline, environment: { DOMParser } });
            const reload = projectForMailDocument({ html: saved.html, builderData: saved.project }, () => [], { kind, environment: { DOMParser } });
            const reloadedP = new DOMParser().parseFromString(`<html><body>${reload.pages[0].component}</body></html>`, 'text/html').querySelector('p');
            assert.equal(reloadedP.style.fontFamily, result.stack, `${kind}/${result.device}: full fallback stack survives save and reopen`);
            assert.equal(reloadedP.textContent, 'Lesbarer Mailtext');
        }
    }
});

test('mail text selection exposes typography without changing selection or opening styles for structural image or read-only selection', async () => {
    const handlers = new Map();
    let selected;
    const opened = [];
    const sectorChanges = [];
    const editor = {
        on: (event, handler) => handlers.set(event, handler),
        off: (event, handler) => { if (handlers.get(event) === handler) handlers.delete(event); },
        getSelected: () => selected,
        StyleManager: { getSector: (id) => {
            assert.equal(id, 'rt-mail-typography');
            return { set: (...args) => sectorChanges.push(args) };
        } },
    };
    const chrome = { openPanel: (panel) => opened.push(panel) };
    const select = async (state) => {
        selected = { get: (key) => state[key] };
        const original = selected;
        handlers.get('component:selected')?.(selected);
        await Promise.resolve();
        assert.equal(selected, original);
    };
    const detach = installMailTypographyFocus(editor, chrome);
    await select({ type: 'text', tagName: 'p', stylable: true });
    await select({ type: 'text', tagName: 'span', stylable: ['font-family', 'color'] });
    assert.deepEqual(opened, ['styles', 'styles']);
    assert.deepEqual(sectorChanges, [['open', true], ['open', true]]);
    await select({ type: 'image', tagName: 'img', stylable: true });
    await select({ type: 'tablecell', tagName: 'td', stylable: true });
    await select({ type: 'text', tagName: 'p', stylable: false });
    await select({ type: 'text', tagName: 'p', stylable: ['color'] });
    assert.equal(opened.length, 2);
    selected = { get: (key) => ({ type: 'text', tagName: 'p' })[key] };
    handlers.get('component:selected')(selected);
    selected = null;
    await Promise.resolve();
    assert.equal(opened.length, 2, 'stale selection cannot reopen the inspector');
    selected = { get: (key) => ({ type: 'text', tagName: 'p' })[key] };
    handlers.get('component:selected')(selected);
    detach();
    await Promise.resolve();
    assert.equal(opened.length, 2, 'unmounted editor cannot reopen the inspector');
    assert.equal(handlers.size, 0);
    installMailTypographyFocus(editor, chrome, { readOnly: true })();
    assert.equal(handlers.size, 0);
});

test('new mail blocks consistently use the V25 house stack but old imported fonts are not rewritten', () => {
    assert.equal(MAIL_DEFAULT_FONT_STACK, "'Trebuchet MS',Arial,sans-serif");
    for (const field of MAIL_PERSON_FIELDS) assert(field.style.includes(`font-family:${MAIL_DEFAULT_FONT_STACK};`));
    let fontNodes = 0;
    for (const block of createMailBlocks()) {
        const document_ = new DOMParser().parseFromString(`<html><body>${block.definition.content}</body></html>`, 'text/html');
        for (const node of document_.querySelectorAll('[style]')) {
            if (!node.style.fontFamily) continue;
            fontNodes += 1;
            assert.equal(node.style.fontFamily, MAIL_DEFAULT_FONT_STACK, block.id);
        }
    }
    assert(fontNodes >= 10);
    const baseline = '<!doctype html><html><head></head><body><table><tr><td><p style="font-family:Tahoma,Verdana,sans-serif;">Bestand</p></td></tr>{{SIGNATURE_BLOCK}}</table></body></html>';
    const project = projectForMailDocument({ html: baseline, builderData: { pages: [{ component: baseline }] } }, () => [], { kind: 'template', environment: { DOMParser } });
    const saved = serializeMailDocumentForSave({ project, html: project.pages[0].component, kind: 'template', baselineHtml: baseline, environment: { DOMParser } });
    assert.match(saved.html, /font-family:Tahoma,Verdana,sans-serif;/);
    assert.doesNotMatch(saved.html, /Trebuchet/);
});

test('mail style changes keep Outlook presentation attributes in sync and undo restores the original fallback', () => {
    const createComponent = (tagName, style, attributes) => ({
        get: (key) => key === 'tagName' ? tagName : undefined,
        getStyle: () => style,
        getAttributes: () => attributes,
        addAttributes(next) { Object.assign(attributes, next); },
        removeAttributes(name) { delete attributes[name]; },
    });
    const style = {
        'background-color': 'rgb(228, 0, 43)',
        width: '60%',
        height: '44px',
        'text-align': 'center',
        'vertical-align': 'middle',
    };
    const attributes = {
        bgcolor: '{{CARD_BG}}',
        width: '50%',
        height: '36',
        align: 'left',
        valign: 'top',
    };
    const cell = createComponent('td', style, attributes);

    ['background-color', 'width', 'height', 'text-align', 'vertical-align'].forEach((property) => {
        assert.equal(synchronizeMailPresentationAttributes(cell, property), true);
    });
    assert.deepEqual(attributes, {
        bgcolor: '#e4002b',
        width: '60%',
        height: '44',
        align: 'center',
        valign: 'middle',
    });

    style['background-color'] = '';
    style.width = '';
    style.height = '';
    style['text-align'] = '';
    style['vertical-align'] = '';
    ['background-color', 'width', 'height', 'text-align', 'vertical-align'].forEach((property) => {
        assert.equal(synchronizeMailPresentationAttributes(cell, property), true);
    });
    assert.deepEqual(attributes, {
        bgcolor: '{{CARD_BG}}',
        width: '50%',
        height: '36',
        align: 'left',
        valign: 'top',
    });

    style.background = 'linear-gradient(red, blue)';
    assert.equal(synchronizeMailPresentationAttributes(cell, 'background'), false);
    assert.equal(attributes.bgcolor, '{{CARD_BG}}');

    const tableStyle = { 'text-align': 'center' };
    const tableAttributes = { align: 'left' };
    const table = createComponent('table', tableStyle, tableAttributes);
    assert.equal(synchronizeMailPresentationAttributes(table, 'text-align'), false);
    assert.equal(tableAttributes.align, 'left');

    // Legacy-Signaturen haben dieselben reservierten Strukturknoten wie V21.
    // Ein Style-Update beim Laden darf kein align ergaenzen oder vorhandene
    // HTML-Hoehen entfernen, auch ohne einen V21-Marker am Vorfahren.
    for (const [className, tagName] of [
        ['rt-sign-stage', 'div'],
        ['rt-sign-content-frame', 'table'],
        ['rt-sign-train-layer', 'div'],
        ['rt-sign-train-frame', 'table'],
        ['rt-sign-train-slot', 'td'],
        ['rt-sign-train', 'img'],
    ]) {
        const systemAttributes = { class: className, height: '200', valign: 'bottom' };
        const systemNode = createComponent(tagName, { 'text-align': 'left' }, systemAttributes);
        assert.equal(synchronizeMailPresentationAttributes(systemNode), false);
        assert.deepEqual(systemAttributes, { class: className, height: '200', valign: 'bottom' });
    }

    const reloadedStyle = {
        'background-color': '',
        width: '',
        height: '',
        'text-align': '',
        'vertical-align': '',
    };
    const reloadedAttributes = {
        bgcolor: '#e4002b',
        width: '60%',
        height: '44',
        align: 'center',
        valign: 'middle',
    };
    const reloadedCell = createComponent('td', reloadedStyle, reloadedAttributes);
    ['background-color', 'width', 'height', 'text-align', 'vertical-align'].forEach((property) => {
        assert.equal(synchronizeMailPresentationAttributes(reloadedCell, property), true);
    });
    assert.deepEqual(reloadedAttributes, {});
});

test('mail presentation attributes survive save and style removal without stale edited fallbacks', () => {
    const canonical = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>RailTime</title></head><body><table><tbody><tr><td class="mail-card" bgcolor="#ffffff" width="50%" height="36" align="left" valign="top" style="background-color:#ffffff;width:50%;height:36px;text-align:left;vertical-align:top;">Inhalt</td></tr>{{SIGNATURE_BLOCK}}</tbody></table></body></html>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: canonical }], styles: [] },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });
    const projectedHtml = project.pages[0].component;
    const document_ = new DOMParser().parseFromString(projectedHtml, 'text/html');
    const cellElement = document_.querySelector('td.mail-card');
    const style = {
        'background-color': '#e4002b',
        width: '60%',
        height: '44px',
        'text-align': 'center',
        'vertical-align': 'middle',
    };
    const attributes = Object.fromEntries([...cellElement.attributes].map(({ name, value }) => [name, value]));
    const cell = {
        get: (key) => key === 'tagName' ? 'td' : undefined,
        getStyle: () => style,
        getAttributes: () => attributes,
        addAttributes(next) { Object.assign(attributes, next); },
        removeAttributes(name) { delete attributes[name]; },
    };
    const properties = ['background-color', 'width', 'height', 'text-align', 'vertical-align'];
    const projectHtml = () => {
        const inlineStyle = Object.entries(style)
            .filter(([, value]) => String(value || '').trim() !== '')
            .map(([property, value]) => `${property}:${value}`)
            .join(';');
        if (inlineStyle) attributes.style = `${inlineStyle};`;
        else delete attributes.style;
        const openingTag = `<td ${Object.entries(attributes)
            .map(([name, value]) => `${name}="${String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;')}"`)
            .join(' ')}>`;
        return projectedHtml.replace(/<td\b[^>]*\bclass="mail-card"[^>]*>/i, openingTag);
    };
    const save = () => serializeMailDocumentForSave({
        project,
        html: projectHtml(),
        kind: 'template',
        baselineHtml: canonical,
        environment: { DOMParser },
    });

    properties.forEach((property) => synchronizeMailPresentationAttributes(cell, property));
    const editedDocument = new DOMParser().parseFromString(save().html, 'text/html');
    const editedCell = editedDocument.querySelector('td.mail-card');
    assert.equal(editedCell.getAttribute('bgcolor'), '#e4002b');
    assert.equal(editedCell.getAttribute('width'), '60%');
    assert.equal(editedCell.getAttribute('height'), '44');
    assert.equal(editedCell.getAttribute('align'), 'center');
    assert.equal(editedCell.getAttribute('valign'), 'middle');
    assert.match(editedCell.getAttribute('style') || '', /background-color:\s*#e4002b/i);

    properties.forEach((property) => { style[property] = ''; });
    properties.forEach((property) => synchronizeMailPresentationAttributes(cell, property));
    const removedDocument = new DOMParser().parseFromString(save().html, 'text/html');
    const removedCell = removedDocument.querySelector('td.mail-card');
    assert.equal(removedCell.getAttribute('bgcolor'), '#ffffff');
    assert.equal(removedCell.getAttribute('width'), '50%');
    assert.equal(removedCell.getAttribute('height'), '36');
    assert.equal(removedCell.getAttribute('align'), 'left');
    assert.equal(removedCell.getAttribute('valign'), 'top');
    assert.doesNotMatch(removedCell.getAttribute('style') || '', /#e4002b|60%|44px|center|middle/i);
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

test('custom mail preview widths use whole CSS pixels and clamp to the supported range', () => {
    assert.equal(MAIL_PREVIEW_MIN_WIDTH, 320);
    assert.equal(MAIL_PREVIEW_MAX_WIDTH, 1920);
    assert.equal(normalizeMailPreviewWidth(641.6), 642);
    assert.equal(normalizeMailPreviewWidth(100), 320);
    assert.equal(normalizeMailPreviewWidth(5000), 1920);
    assert.equal(normalizeMailPreviewWidth('487'), 487);
    assert.equal(normalizeMailPreviewWidth('ungueltig', 777.8), 778);

    const geometry = calculateMailPreviewGeometry({
        device: 'desktop',
        logicalWidth: 641.6,
        hostWidth: 390,
        hostHeight: 700,
    });
    assert.equal(geometry.device, 'custom');
    assert.equal(geometry.label, 'Individuell');
    assert.equal(geometry.logicalWidth, 642);
    assert.equal(geometry.scale, 1);
    assert.equal(geometry.displayWidth, 642);
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

    controller.setWidth(641.6);
    assert.equal(devices.get('rt-mail-custom').width, '642px');
    assert.equal(selectedDevices.at(-1), 'rt-mail-custom');
    assert.equal(cssProperties['--rt-mail-logical-width'], '642px');
    assert.equal(frame.dataset.previewDevice, 'custom');
    assert.equal(iframeAttributes.width, '642');
    assert.equal(iframeStyles.width.value, '642px');
    assert.equal(zooms.at(-1), 100);

    controller.setDevice('tablet');
    assert.equal(selectedDevices.at(-1), 'rt-mail-tablet');
    assert.equal(frame.dataset.previewDevice, 'tablet');
    assert.equal(cssProperties['--rt-mail-logical-width'], '820px');

    handlers.get('canvas:frame:load')?.();
    assert.equal(changes.at(-1).device, 'tablet');

    controller.destroy();
    assert.equal(handlers.size, 0);
});

test('preview controller ignores identical ResizeObserver feedback after geometry was applied', () => {
    let resizeCallback = null;
    let scheduledCallback = null;
    let refreshCount = 0;
    let zoomCount = 0;
    let changeCount = 0;
    const iframe = {
        setAttribute() {},
        style: { setProperty() {} },
    };
    const editor = {
        DeviceManager: {
            get: () => null,
            add: () => {},
        },
        Canvas: {
            getFrameEl: () => iframe,
            setZoom: () => { zoomCount += 1; },
        },
        setDevice() {},
        refresh() { refreshCount += 1; },
        trigger() {},
        on() {},
        off() {},
    };
    const host = { clientWidth: 1200, clientHeight: 800 };
    const frame = {
        clientWidth: 1200,
        clientHeight: 800,
        dataset: {},
        style: { setProperty() {} },
        querySelector: () => host,
    };
    class ResizeObserverStub {
        constructor(callback) { resizeCallback = callback; }
        observe() {}
        disconnect() {}
    }

    const controller = createMailPreviewController({
        instance: { editor },
        frame,
        onChange: () => { changeCount += 1; },
        environment: {
            ResizeObserver: ResizeObserverStub,
            requestAnimationFrame(callback) {
                scheduledCallback = callback;
                return 1;
            },
            cancelAnimationFrame() {},
        },
    });

    scheduledCallback();
    assert.equal(refreshCount, 1);
    assert.equal(zoomCount, 1);
    assert.equal(changeCount, 1);

    resizeCallback();
    scheduledCallback();
    assert.equal(refreshCount, 1);
    assert.equal(zoomCount, 1);
    assert.equal(changeCount, 1);

    host.clientWidth = 900;
    resizeCallback();
    scheduledCallback();
    assert.equal(refreshCount, 2);
    assert.equal(zoomCount, 2);
    assert.equal(changeCount, 2);

    controller.destroy();
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

test('compiled preview drops stale bytes on failure, late responses and editor teardown', async () => {
    const source = readFileSync(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8');
    const start = source.indexOf('const loadCompiledDeliveryPreview =');
    const end = source.indexOf('const selectViewMode =', start);
    const pending = [];
    const renderings = [];
    const setup = new Function('request', 'renderings', `
        const document_ = { endpoints: { deliveryPreview: '/preview' }, contentHash: 'saved' };
        const deliveryFrame = { srcdoc: 'old' }, deliveryState = { textContent: '' };
        let deliveryPreviewRequest = null, deliveryPreviewGeneration = 0;
        let compiledDeliveryHtml = 'old', selectedViewMode = 'delivery', destroyed = false;
        const currentCandidateForServer = () => ({ builderData: {}, html: 'candidate', css: '' });
        const showFindings = () => {};
        const renderCompiledDeliveryHtml = () => renderings.push(compiledDeliveryHtml);
        ${source.slice(start, end)}
        return { load: loadCompiledDeliveryPreview,
            state: () => ({ html: compiledDeliveryHtml, frame: deliveryFrame.srcdoc }),
            destroy: () => { destroyed = true; } };
    `);
    const preview = setup(() => new Promise((resolve, reject) => pending.push({ resolve, reject })), renderings);
    const failed = preview.load();
    assert.deepEqual(preview.state(), { html: '', frame: '' });
    pending.shift().reject(new Error('422'));
    await assert.rejects(failed, /422/);
    assert.equal(preview.state().html, '', 'robustness toggles cannot resurrect an older successful mail');
    const older = preview.load(), newer = preview.load();
    const previous = pending.shift(), current = pending.shift();
    current.resolve({ preview: { rendering: 'compiled-system-mail', html: 'current' } });
    await newer;
    previous.resolve({ preview: { rendering: 'compiled-system-mail', html: 'obsolete' } });
    await older;
    assert.deepEqual(renderings, ['current']);
    const last = preview.load();
    preview.destroy();
    pending.shift().resolve({ preview: { rendering: 'compiled-system-mail', html: 'after-close' } });
    await last;
    assert.equal(preview.state().html, '');
    assert.deepEqual(renderings, ['current']);
});

test('server save preserves edits made during a request and detaches its update observer', async () => {
    const source = readFileSync(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8');
    const signature = 'onSave: async ({ project, html, css, editor }) => {';
    const start = source.indexOf(signature) + signature.length;
    const end = source.indexOf('                                    },', start);
    assert.ok(start > signature.length && end > start);
    const invoke = new Function('context', `
        const { runtimeBridge, request, editor, draft } = context;
        const document_ = draft, config = { currentDocument: 'signature' }, window = {};
        let lastEditorSaveError = null, activeBaselineHtml = 'old', destroyed = false;
        const applyDocumentState = () => {}, showFindings = () => {}, setMessage = (message) => context.messages?.push(message);
        const normalizeError = (error) => error;
        return (async ({ project, html, css, editor }) => { ${source.slice(start, end)} })({ project: {}, html: 'sent', css: '', editor });
    `);
    for (const concurrent of [false, true]) {
        const listeners = new Set();
        const messages = [];
        let rehydrations = 0;
        const editor = { on: (event, callback) => listeners.add(callback), off: (event, callback) => listeners.delete(callback) };
        const draft = { endpoints: { update: '/save' }, contentHash: 'old' };
        const runtimeBridge = {
            serializeForSave: () => ({ project: {}, html: 'sent', css: '' }),
            rehydrateAuthoritative: async () => { rehydrations++; listeners.forEach((listener) => listener()); },
        };
        await invoke({ editor, draft, runtimeBridge, messages, request: async () => {
            if (concurrent) listeners.forEach((listener) => listener());
            return { document: { html: 'cleaned', css: '', builder_data: {} }, report: { findings: [{ severity: 'violation' }] } };
        } });
        assert.equal(rehydrations, concurrent ? 0 : 1);
        assert.equal(draft.html, 'cleaned', 'server baseline is updated independently of newer canvas edits');
        assert.equal(listeners.size, 0);
        assert.equal(messages.at(-1).includes('Neuere Änderungen'), concurrent, 'server hydration is not mistaken for a newer user edit');
        await assert.rejects(invoke({ editor, draft, runtimeBridge, request: async () => { throw new Error('network'); } }), /network/);
        assert.equal(listeners.size, 0, 'failed saves release the observer too');
    }
});

test('shared page builder opens from preview into a compact responsive Mail Studio', async () => {
    const { readFile } = await import('node:fs/promises');
    const [shell, mailView, mailIndex, adminSidebar, mailCss, shellCss] = await Promise.all([
        readFile(new URL('../../resources/views/components/ui/page-builder/editor-shell.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/email-templates/index.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/layouts/admin-sidebar.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/mail-builder.css', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/lmz-editor-shell.css', import.meta.url), 'utf8'),
    ]);

    assert.match(shell, /'autoOpen'\s*=>\s*false/);
    assert.match(shell, /pageBuilderOpen:\s*@js\(\(bool\) \$autoOpen\)/);
    assert.match(shell, /data-page-builder-preview-first/);
    assert.match(shell, /data-page-builder-assist/);
    assert.match(shell, /'singleToolbar'\s*=>\s*false/);
    assert.match(shell, /data-page-builder-single-toolbar/);
    assert.match(shell, /data-page-builder-single-toolbar-content/);
    assert.doesNotMatch(shell, /data-page-builder-panel-host/);

    assert.match(mailView, /data-mail-studio-toolbar/);
    assert.match(mailView, /:single-toolbar="\$currentDocument !== null && \$editorRequested"/);
    assert.match(mailView, /:open-url="\$currentDocument !== null && ! \$editorRequested \? \$editorOpenUrl : null"/);
    assert.match(mailView, /:render-workspace="\$currentDocument === null \|\| \$editorRequested"/);
    assert.match(mailView, /data-mail-document-save/);
    assert.match(mailView, /data-mail-document-publish/);
    assert.match(mailView, /data-mail-code-open/);
    assert.match(mailView, /data-mail-code-export/);
    assert.match(mailView, /data-mail-code-import/);
    assert.match(mailView, /data-mail-editor-mode="mail"/);
    assert.match(mailView, /LMZ Page Builder wird im Mailmodus geladen/);
    assert.match(mailView, /data-mail-code-dialog/);
    assert.match(mailView, /data-mail-degradation-mode/);
    assert.match(mailView, /data-mail-view-mode="delivery"/);
    assert.match(mailView, /data-mail-view-mode="forward"/);
    assert.match(mailView, />Weiterleitung</);
    assert.match(mailView, /selectedViewMode === 'forward'/);
    assert.match(mailView, /Versandvorschau · aktueller Entwurf nach CSS-Inliner/);
    assert.match(mailView, /data-mail-compiler-parity-note/);
    assert.match(mailView, /Keine Ansicht emuliert Outlook oder iPhone Mail/);
    assert.match(mailView, /clientspezifische Medien- und Wrapper-Anpassungen/);
    assert.match(mailView, />Versandvorschau</);
    assert.match(mailView, /let selectedViewMode = 'delivery'/);
    assert.match(mailView, /data-mail-view-shortcut/);
    assert.match(mailCss, /\[data-mail-compiler-parity-note\]\s*\{[^}]*white-space:\s*normal;/s);
    assert.match(mailView, /Kompilierte Weiterleitungsbasis im Browser/);
    assert.match(mailView, /Weiterleitungsansicht: visuelle Prüfung erforderlich/);
    assert.match(mailView, /kein geprüfter Nachweis für einen bestimmten Mailclient/);
    assert.match(mailView, /const enteringForward = selectedViewMode !== 'forward' && nextViewMode === 'forward'/);
    assert.match(mailView, /if \(enteringForward\) selectDevice\('mobile'\)/);
    assert.match(mailView, /restoreForwardPreviewViewport/);
    assert.match(mailView, /data-mail-preview-width/);
    assert.match(mailView, /data-mail-preview-resizer/);
    assert.match(mailView, /role="separator"/);
    assert.match(mailView, /aria-valuemin="320"/);
    assert.match(mailView, /aria-valuemax="1920"/);
    assert.match(mailView, /setPreviewWidth/);
    assert.match(mailView, /setPointerCapture/);
    assert.match(mailView, /'pointercancel'/);
    assert.match(mailView, /event\.shiftKey \? 10 : 1/);
    assert.match(mailView, /document_\.endpoints\.deliveryPreview/);
    assert.match(mailView, /Bilder aus/);
    assert.match(mailView, /Head-CSS aus/);
    assert.match(mailView, /Gesamtes CSS aus/);
    assert.match(mailView, /keine Mailclient-Emulation/);
    assert.match(mailView, /instance\?\.setDegradationMode\?\.\(selectedDegradationMode\)/);
    assert.match(mailView, /compatibilityBlocksPublication/);
    assert.match(mailView, /showFindings\(payload\.report, payload\.compatibility\)/);
    assert.match(mailView, /const MAIL_SOURCE_VERSION = 2/);
    assert.match(mailView, /const MAX_SOURCE_BYTES = 1024 \* 1024/);
    assert.match(mailView, /const MAX_BUNDLE_BYTES = 16 \* 1024 \* 1024/);
    assert.match(mailView, /format: MAIL_SOURCE_FORMAT,[\s\S]*?version: MAIL_SOURCE_VERSION,[\s\S]*?kind: config\.currentDocument,[\s\S]*?html: source\.html,[\s\S]*?css: source\.css,[\s\S]*?media: await exportPortableMedia\(source\)/);
    assert.match(mailView, /crypto\.subtle\.digest\('SHA-256'/);
    assert.match(mailView, /portable_media: portableMedia/);
    assert.match(mailView, /requiredIds\.some\(\(id\) => !seenIds\.has\(id\)\)/);
    assert.match(mailView, /resolvePortableMediaRequirementIds[\s\S]*?config\.portableMediaRequirements[\s\S]*?source\?\.html/);
    assert.match(mailView, /const requiredIds = requiredPortableMediaIds\(source\)/);
    assert.doesNotMatch(mailView, /const requiredIds = catalog\.filter\(\(asset\) => asset\.required\)/);
    assert.match(mailView, /explicitTitle \|\| \([\s\S]*?compatibility !== undefined && compatibilityBlocksPublication/);
    assert.match(mailView, /\^mail-imports\\\/\(\[a-f0-9\]\{64\}\)\\\.\(gif\|png\|jpg\|webp\)\$/);
    assert.match(mailView, /Das Bundle enthält nicht den vollständigen Medienbestand dieses Dokuments/);
    assert.match(mailView, /const validated = await validateSourceOnServer\(source, pendingPortableMedia, expectedHash\)[\s\S]*?document_\.endpoints\.update[\s\S]*?builder_data: validated\.draft\.builderData[\s\S]*?html: validated\.draft\.html[\s\S]*?css: validated\.draft\.css/);
    assert.doesNotMatch(mailView, /const validatedProject = runtimeBridge\.projectFor/);
    assert.doesNotMatch(mailView, /editorWasReplaced/);
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
    assert.match(mailView, /const studioRoot = workspace\.closest\('\[data-rt-fullscreen-modal\]'\)[\s\S]*?workspace\.closest\('\[data-page-builder-fullscreen-root\]'\)/);
    assert.match(mailView, /data-mail-builder-action[\s\S]*?instance\.runEditorAction/);
    assert.match(mailView, /data-mail-builder-panel[\s\S]*?instance\.openEditorPanel/);
    assert.doesNotMatch(mailView, /class="rt-mail-preview-toolbar"/);
    assert.match(mailIndex, /data-email-template-editor-link/);
    assert.match(mailIndex, /data-email-template-import-link/);
    assert.match(mailIndex, /:navigate-edit="false"/);
    assert.doesNotMatch(mailIndex, /wire:navigate\s+data-email-template-(?:editor|import)-link/);
    assert.match(adminSidebar, /:href="route\('admin\.mail-documents\.editor'\)"[\s\S]*?:navigate="false"/);
    assert.doesNotMatch(adminSidebar, /admin\.mail-documents\.import-page|Entwürfe importieren/);

    assert.match(mailCss, /\.rt-mail-studio\s*\{[\s\S]*?overflow:\s*hidden;/);
    assert.match(mailCss, /\.rt-mail-code-dialog\s*\{/);
    assert.match(mailCss, /\.rt-mail-code-dialog::backdrop/);
    assert.match(mailCss, /\.rt-mail-degradation-preview\s*\{/);
    assert.match(mailCss, /\.rt-mail-delivery-preview\s*\{/);
    assert.match(mailCss, /data-mail-view-mode='forward'\] \.rt-mail-builder-root/);
    assert.match(mailCss, /\.rt-mail-preview-resizer\s*\{/);
    assert.match(mailCss, /touch-action:\s*none/);
    assert.match(mailCss, /data-preview-device='custom'/);
    assert.match(mailCss, /data-mail-degradation-active/);
    assert.doesNotMatch(mailCss, /min-height:\s*42rem/);
    assert.match(shellCss, /font-family:\s*'Plus Jakarta Sans Variable'/);
    assert.match(shellCss, /html\[data-rt-pagebuilder-assist-open='true'\]/);
    assert.match(shellCss, /\.lmz-builder\s+\.lmzbjs-layers/);
    assert.match(shellCss, /data-rt-lmz-mode='mail'[\s\S]*?\.lmz-builder__topbar\s*\{[\s\S]*?display:\s*none !important/);
    assert.match(shellCss, /@media \(max-width: 639\.98px\)[\s\S]*?\.lmz-builder__popover/);
});

test('mail view controls work after teleport and ignore other panels and destroyed editors', async () => {
    const source = readFileSync(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8');
    const queriesStart = source.indexOf('const viewPanelId =');
    const queriesEnd = source.indexOf('const saveButton =', queriesStart);
    const eventsStart = source.indexOf('// Delegation erreicht auch einen erst spaeter teleportierten');
    const eventsEnd = source.indexOf('const finishResizeGesture =', eventsStart);
    assert.ok(queriesStart > 0 && queriesEnd > queriesStart && eventsEnd > eventsStart);
    const document = new DOMParser().parseFromString('<html><body><div id="studio"><div data-mail-toolbar-menu="view"><button id="trigger">Ansicht</button></div></div></body></html>', 'text/html');
    const studioRoot = document.getElementById('studio');
    const frames = [];
    const calls = [];
    const controlListeners = new AbortController();
    const listeners = [];
    const nativeListen = document.addEventListener.bind(document);
    document.addEventListener = (type, listener, options) => {
        assert.equal(options.signal, controlListeners.signal, 'every delegated listener belongs to this editor');
        listeners.push(type);
        nativeListen(type, listener);
        // linkedom has no browser AbortSignal listener cleanup implementation.
        options.signal.addEventListener('abort', () => document.removeEventListener(type, listener), { once: true });
    };
    const compile = new Function('context', `
        const { window, studioRoot, controlListeners, calls } = context;
        const config = { currentDocument: 'signature' };
        let destroyed = false;
        let selectedViewMode = 'edit';
        let selectedTheme = 'light';
        let selectedDegradationMode = 'normal';
        const instance = { restartAllGifs: () => { calls.push(['replay']); return 2; }, getPreviewGeometry: () => ({ logicalWidth: 375 }) };
        const selectViewMode = async (value) => { selectedViewMode = value; calls.push(['mode', value]); };
        const selectTheme = (value) => { selectedTheme = value; calls.push(['theme', value]); };
        const selectDevice = (value) => calls.push(['device', value]);
        const selectPreviewWidth = (value) => calls.push(['width', value]);
        const selectDegradationMode = (value) => { selectedDegradationMode = value; calls.push(['degradation', value]); };
        const setMessage = (value) => calls.push(['message', value]);
        const updatePreviewStatus = (value) => calls.push(['status', value.logicalWidth]);
        const showRequestError = (error) => error;
        const toast = () => {};
        ${source.slice(queriesStart, queriesEnd)}
        ${source.slice(eventsStart, eventsEnd)}
        return { queryViewControl, queryViewControls, destroy: () => { destroyed = true; controlListeners.abort(); } };
    `);
    const bindings = compile({
        window: { document, requestAnimationFrame: (callback) => frames.push(callback) },
        studioRoot, controlListeners, calls,
    });
    assert.deepEqual(listeners.sort(), ['change', 'click', 'dropdown-open', 'input']);
    assert.equal(bindings.queryViewControls('[data-mail-view-mode]').length, 0, 'boot can run before Alpine creates the panel');

    const panel = document.createElement('div');
    panel.id = 'rt-dropdown-mail-document-view-signature-content';
    panel.innerHTML = '<button data-mail-view-mode="delivery"><span>Compiler-Parität</span></button>'
        + '<button data-mail-view-mode="forward">Weiterleitung</button>'
        + '<div data-mail-theme-controls><button data-mail-theme-button="dark">Dunkel</button></div>'
        + '<button data-mail-preview-device="mobile">Mobil</button>'
        + '<input data-mail-preview-width value="390">'
        + '<select data-mail-degradation-mode><option value="normal">Normal</option><option value="images-off">Bilder aus</option></select>'
        + '<button data-mail-preview-replay>Neustart</button>';
    document.body.append(panel);
    assert.equal(studioRoot.contains(panel), false);
    assert.equal(bindings.queryViewControls('[data-mail-view-mode]').length, 2);
    const fire = (node, type) => node.dispatchEvent(new document.defaultView.Event(type, { bubbles: true }));
    const get = (selector) => panel.querySelector(selector);
    get('[data-mail-view-mode] span').click();
    await Promise.resolve();
    assert.deepEqual(calls.pop(), ['mode', 'delivery'], 'clicks from nested labels reach the mode action');
    get('[data-mail-theme-button]').click();
    assert.deepEqual(calls.pop(), ['theme', 'dark']);
    get('[data-mail-preview-device]').click();
    assert.deepEqual(calls.pop(), ['device', 'mobile']);
    fire(get('input'), 'input');
    assert.deepEqual(calls.pop(), ['width', '390']);
    get('input').value = '';
    fire(get('input'), 'input');
    assert.equal(calls.length, 0);
    get('select').selectedIndex = 1;
    // linkedom exposes a read-only select value; select the option explicitly.
    get('select').querySelector('[value="images-off"]').selected = true;
    fire(get('select'), 'change');
    assert.deepEqual(calls.pop(), ['degradation', 'images-off']);
    get('[data-mail-preview-replay]').click();
    assert.deepEqual(calls.splice(0), [['replay'], ['message', '2 Animationen neu gestartet.']]);

    const foreign = panel.cloneNode(true);
    foreign.id = 'rt-dropdown-mail-document-view-template-content';
    document.body.append(foreign);
    foreign.querySelector('[data-mail-view-mode]').click();
    fire(foreign.querySelector('input'), 'input');
    fire(foreign.querySelector('select'), 'change');
    assert.equal(calls.length, 0, 'another document panel cannot control this editor');
    get('[data-mail-preview-device]').disabled = true;
    get('[data-mail-preview-device]').click();
    get('[data-mail-view-mode]').setAttribute('aria-disabled', 'true');
    get('[data-mail-view-mode]').click();
    assert.equal(calls.length, 0, 'disabled controls cannot dispatch actions');

    get('[data-mail-view-mode="forward"]').click();
    await Promise.resolve();
    calls.length = 0;
    // The opened panel must show the selected state, including when created late.
    Object.defineProperty(get('select'), 'value', { writable: true, value: 'normal' });
    fire(document.getElementById('trigger'), 'dropdown-open');
    frames.shift()();
    assert.equal(get('[data-mail-view-mode="forward"]').getAttribute('aria-pressed'), 'true');
    assert.equal(get('[data-mail-theme-button]').disabled, true);
    assert.equal(get('[data-mail-theme-controls]').getAttribute('aria-disabled'), 'true');
    assert.equal(get('select').disabled, true);
    assert.equal(get('select').value, 'images-off');
    assert.deepEqual(calls.splice(0), [['status', 375]]);

    fire(document.getElementById('trigger'), 'dropdown-open');
    bindings.destroy();
    frames.shift()();
    get('[data-mail-preview-replay]').click();
    fire(get('input'), 'input');
    fire(get('select'), 'change');
    assert.equal(calls.length, 0, 'abort removes listeners and pending frame ignores the destroyed editor');
});

test('code import drains pending autosave and keeps the previous canvas locked through reload', async () => {
    const mailView = readFileSync(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8');
    const start = mailView.indexOf('const applyCodeAsDraft = async () => {');
    const end = mailView.indexOf("bindToolControl('[data-mail-code-open]'", start);
    assert.ok(start > 0 && end > start);
    const compile = new Function('context', `
        const { instance, document_, codeHtml, codeCss, assertPortableSource,
            validateSourceOnServer, request, applyDocumentState, showFindings,
            setMessage, toast, codeDialog, window,
            editorBootState = 'ready' } = context;
        let destroyed = Boolean(context.destroyed);
        let pendingPortableMedia = context.media;
        let activeBaselineHtml = '';
        ${mailView.slice(start, end)}
        applyCodeAsDraft.destroy = () => { destroyed = true; };
        return applyCodeAsDraft;
    `);

    for (const failure of [null, 'validate', 'update', 'teardown']) {
        const events = [];
        let releaseAutosave;
        const pendingAutosave = new Promise((resolve) => { releaseAutosave = resolve; });
        let releaseUpdate;
        const pendingUpdate = new Promise((resolve) => { releaseUpdate = resolve; });
        let signalUpdate;
        const updateStarted = new Promise((resolve) => { signalUpdate = resolve; });
        const document_ = { contentHash: 'before-autosave', endpoints: { update: '/draft' } };
        const draft = { builderData: { pages: [] }, html: '<table>import</table>', css: '' };
        const media = [{ id: 'imported-image' }];
        let locked = false;
        let reload;
        const apply = compile({
            document_, media,
            instance: {
                readOnly: false,
                instance: { setActionLocked(value) { locked = value; events.push(['lock', value]); } },
                async save(reason) {
                    assert.equal(locked, true);
                    assert.equal(reason, 'autosave-import-drain');
                    events.push(['drain']);
                    await pendingAutosave;
                    document_.contentHash = 'after-autosave';
                    return true;
                },
            },
            codeHtml: { value: draft.html }, codeCss: { value: draft.css },
            assertPortableSource: (source) => source,
            async validateSourceOnServer(source, files, expectedHash) {
                events.push(['validate', expectedHash]);
                assert.deepEqual(source, { html: draft.html, css: draft.css });
                assert.equal(files, media);
                if (failure === 'validate') throw new Error('validation failed');
                return { draft };
            },
            async request(url, method, payload) {
                events.push(['update', payload.expected_hash]);
                assert.equal(locked, true);
                assert.equal(url, '/draft');
                assert.equal(method, 'PUT');
                assert.deepEqual(payload, {
                    builder_data: draft.builderData, html: draft.html, css: draft.css,
                    expected_hash: 'after-autosave',
                });
                if (failure === 'update') throw new Error('update failed');
                if (failure === 'teardown') {
                    signalUpdate();
                    await pendingUpdate;
                }
                return { document: { content_hash: 'imported', html: draft.html } };
            },
            applyDocumentState(payload) { document_.contentHash = payload.content_hash; document_.html = payload.html; },
            showFindings() {}, setMessage() {}, toast() {},
            codeDialog: { close: (reason) => events.push(['close', reason]) },
            window: {
                setTimeout(callback) { reload = callback; },
                location: { reload: () => events.push(['reload']) },
            },
        });
        const applying = apply();
        assert.deepEqual(events, [['lock', true], ['drain']]);
        releaseAutosave();
        if (failure === 'teardown') {
            await updateStarted;
            apply.destroy();
            releaseUpdate();
            await applying;
            assert.equal(document_.contentHash, 'after-autosave', 'a late response must not update a destroyed page');
            assert.equal(reload, undefined, 'a completed import must not reload the destination page after teardown');
            assert.deepEqual(events, [
                ['lock', true], ['drain'], ['validate', 'after-autosave'], ['update', 'after-autosave'],
            ]);
        } else if (failure) {
            await assert.rejects(applying, /failed/);
            assert.equal(locked, false, 'a rejected import releases the editor');
            assert.equal(reload, undefined);
        } else {
            await applying;
            assert.equal(locked, true, 'old canvas must not autosave using the imported hash');
            assert.equal(document_.contentHash, 'imported');
            assert.deepEqual(events, [
                ['lock', true], ['drain'], ['validate', 'after-autosave'],
                ['update', 'after-autosave'], ['close', 'saved'],
            ]);
            reload();
            assert.deepEqual(events.at(-1), ['reload']);
        }
    }
    for (const state of [
        { editorBootState: 'failed', instance: null, allowed: true },
        { editorBootState: 'loading', instance: null, allowed: false },
        { editorBootState: 'loading', instance: { instance: { setActionLocked() { assert.fail('loading editor must not be locked'); } } }, allowed: false },
        { editorBootState: 'ready', instance: null, allowed: false },
        { editorBootState: 'failed', instance: null, destroyed: true, allowed: false },
    ]) {
        const events = [];
        const draft = { builderData: { pages: [] }, html: '<table>repaired</table>', css: '' };
        const apply = compile({
            ...state,
            document_: { contentHash: 'stored', endpoints: { update: '/draft' } },
            media: [], codeHtml: { value: draft.html }, codeCss: { value: '' },
            assertPortableSource: (source) => source,
            async validateSourceOnServer(source, media, expectedHash) {
                events.push('validate');
                assert.equal(expectedHash, 'stored');
                assert.deepEqual(source, { html: draft.html, css: '' });
                return { draft };
            },
            async request(url, method, payload) {
                events.push('update');
                assert.equal(payload.expected_hash, 'stored');
                assert.equal(payload.html, draft.html);
                return { document: { content_hash: 'repaired', html: draft.html } };
            },
            applyDocumentState() {}, showFindings() {}, setMessage() {}, toast() {},
            codeDialog: { close: () => events.push('close') },
            window: { setTimeout: () => events.push('reload-scheduled') },
        });
        if (state.allowed) {
            await apply();
            assert.deepEqual(events, ['validate', 'update', 'close', 'reload-scheduled']);
        } else {
            await assert.rejects(apply, /Importspeicher/);
            assert.deepEqual(events, [], 'pending/closed editors must not start an import');
        }
    }
    assert.match(mailView, /await selectViewMode\(selectedViewMode\);\s*if \(!destroyed\) editorBootState = 'ready'/);
    assert.match(mailView, /boot\(\)\.catch\([\s\S]*?instance = null;\s*editorBootState = 'failed'/);
    assert.match(mailView, /if \(!importApplied\)\s*\{[\s\S]*?setActionsBusy\(false\)/);
});

test('mail editor exposes one responsive topbar with grouped controls and visible publishing actions', async () => {
    const { readFile } = await import('node:fs/promises');
    const [view, css] = await Promise.all([
        readFile(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/mail-builder.css', import.meta.url), 'utf8'),
    ]);

    assert.match(view, /data-mail-toolbar-layout="responsive"/);
    assert.match(view, /data-mail-toolbar-single/);
    assert.match(view, /role="toolbar" aria-label="Mail- und Signatur-Editor"/);
    assert.match(view, /:auto-open="\$editorRequested"/);
    assert.match(view, /\['dokument' => \$kindValue, 'open' => 1\]/);
    assert.match(view, /data-mail-document-hard-switch/);
    assert.doesNotMatch(view, /wire:navigate/);
    assert.match(view, /data-mail-document-root[\s\S]*?wire:ignore[\s\S]*?x-ignore/);
    assert.match(view, /stopObservingMutations/);
    assert.match(view, /finally\s*\{[\s\S]*?startObservingMutations/);
    for (const region of ['documents', 'preview', 'actions']) {
        assert.equal((view.match(new RegExp(`data-mail-toolbar-region="${region}"`, 'g')) || []).length, 1);
    }

    for (const group of ['document', 'content', 'edit', 'view', 'designs-versions', 'tools']) {
        assert.equal((view.match(new RegExp(`^\\s*data-mail-toolbar-menu="${group}"`, 'gm')) || []).length, 1);
    }
    assert.ok((view.match(/<x-ui\.dropdown\.anchor-dropdown/g) || []).length >= 5);
    assert.match(view, /data-mail-design-manager-trigger/);
    assert.doesNotMatch(view, /data-mail-toolbar-menu="versions"/);
    assert.doesNotMatch(view, /data-mail-document-version(?:[\s=>-]|$)/);
    for (const action of ['assets', 'upload', 'undo', 'redo', 'preview']) {
        assert.match(view, new RegExp(`data-mail-builder-action="${action}"`));
    }
    for (const panel of ['left:blocks', 'left:layers', 'right:styles', 'right:traits', 'right:classes']) {
        assert.match(view, new RegExp(`data-mail-builder-panel="${panel}"`));
    }
    assert.match(view, /data-mail-document-status/);
    assert.match(view, /data-mail-document-save/);
    assert.match(view, /data-mail-document-publish/);

    const singleToolbar = css.slice(css.indexOf('Einzeilige Studio-Kopfzeile'));
    assert.match(singleToolbar, /\.rt-mail-studio-toolbar\[data-mail-toolbar-single\]\s*\{[\s\S]*?display:\s*flex/);
    assert.match(singleToolbar, /overflow-x:\s*auto/);
    assert.match(singleToolbar, /scrollbar-width:\s*none/);
    assert.match(singleToolbar, /min-height:\s*2\.75rem/);
    assert.match(singleToolbar, /@media \(max-width: 1199\.98px\)[\s\S]*?width:\s*2\.75rem/);
});

test('mail design manager uses the shared state modal and saves before slot or version transitions', async () => {
    const { readFile } = await import('node:fs/promises');
    const [view, editorShell, stateModal] = await Promise.all([
        readFile(new URL('../../resources/views/livewire/admin/mail-document-editor.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/components/ui/page-builder/editor-shell.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/components/ui/state-modal.blade.php', import.meta.url), 'utf8'),
    ]);

    assert.match(view, /x-data="\{[\s\S]*?managerOpen: false,[\s\S]*?managerBusy: false,[\s\S]*?closeManager\(\)/);
    assert.match(view, /x-on:mail-design-manager-open\.window="openManager\(\)"/);
    assert.match(view, /x-on:mail-design-manager-busy\.window="managerBusy = \$event\.detail === true"/);
    assert.match(view, /<x-ui\.state-modal[\s\S]*?state="managerOpen"[\s\S]*?data-mail-design-manager[\s\S]*?data-page-builder-subdialog/);
    assert.match(view, /close-action="closeManager\(\)"/);
    assert.match(view, /description="[^"]*Genau ein veröffentlichtes Design wird von Systemmails verwendet\."/);

    for (const control of [
        'data-mail-slot-create-form',
        'data-mail-design-slot-list',
        'data-mail-design-slot',
        'data-mail-slot-open',
        'data-mail-slot-activate',
        'data-mail-slot-rename-form',
        'data-mail-slot-delete',
        'data-mail-slot-version-list',
        'data-mail-version-restore',
        'data-mail-version-delete',
    ]) {
        assert.match(view, new RegExp(control));
    }

    assert.doesNotMatch(view, /dropdown-id="mail-document-versions-/);
    assert.doesNotMatch(view, /data-mail-toolbar-menu="versions"/);
    assert.doesNotMatch(view, /data-mail-document-version(?:[\s=>-]|$)/);
    assert.doesNotMatch(view, /window\.confirm\s*\(/);
    assert.match(editorShell, /pageBuilderSubdialogOpen\(\)/);
    assert.match(editorShell, /escape-action="if \(! pageBuilderAssistantOpen\(\) && ! pageBuilderSubdialogOpen\(\)\) requestClose\(\)"/);
    assert.match(editorShell, /if \(event\.defaultPrevented \|\| this\.pageBuilderSubdialogOpen\(\)\) return;/);
    assert.match(stateModal, /keydown\.escape\.window="if \(\{\{ \$state \}\}\) \{ \$event\.stopImmediatePropagation\(\);/);

    const managerScriptStart = view.indexOf('const saveBeforeDesignAction = async () => {');
    const managerScriptEnd = view.indexOf('bindDesignManager();', managerScriptStart);
    assert.ok(managerScriptStart >= 0, 'save-before-design helper must exist');
    assert.ok(managerScriptEnd > managerScriptStart, 'design-manager binding must follow the save helper');

    const managerScript = view.slice(managerScriptStart, managerScriptEnd + 'bindDesignManager();'.length);
    const saveHelper = managerScript.slice(0, managerScript.indexOf('const dispatchConfirmation'));
    assert.match(saveHelper, /if \(codeDialog\?\.open\)/);
    assert.match(saveHelper, /if \(pendingPortableMedia\.length > 0\)/);
    assert.match(saveHelper, /focused\.blur\(\);[\s\S]*?requestAnimationFrame[\s\S]*?await saveCurrentDraft\(\);/);
    assert.match(managerScript, /new CustomEvent\('rt-confirm',[\s\S]*?cancelLabel: 'Abbrechen',[\s\S]*?action/);
    assert.match(managerScript, /new CustomEvent\('mail-design-manager-busy', \{ detail: true \}\)/);
    assert.match(managerScript, /new CustomEvent\('mail-design-manager-busy', \{ detail: false \}\)/);
    assert.ok((managerScript.match(/fail\(error,[^\n]+\);\s*throw error;/g) || []).length >= 4);
    assert.equal((managerScript.match(/dispatchConfirmation\(\{/g) || []).length, 4);
    assert.equal((managerScript.match(/await saveBeforeDesignAction\(\);/g) || []).length, 6);
    assert.doesNotMatch(managerScript, /window\.confirm\s*\(/);

    const slotOpenStart = managerScript.indexOf("if (control.matches('[data-mail-slot-open]'))");
    const slotActivateStart = managerScript.indexOf("if (control.matches('[data-mail-slot-activate]'))", slotOpenStart);
    assert.ok(slotOpenStart >= 0 && slotActivateStart > slotOpenStart, 'slot-open handler must precede slot activation');
    const slotOpenHandler = managerScript.slice(slotOpenStart, slotActivateStart);
    const saveIndex = slotOpenHandler.indexOf('await saveBeforeDesignAction();');
    const navigateIndex = slotOpenHandler.indexOf('window.location.assign(control.dataset.url);');
    assert.ok(saveIndex >= 0 && navigateIndex > saveIndex, 'slot changes must save the current draft before navigation');
});

test('signature source keeps older artifacts stable and defines the schema 28 V17-V21 Outlook contracts', async () => {
    const { readFile } = await import('node:fs/promises');
    const [css, signatureSource, trainAsset, v15TrainAsset, v17TrainAsset, v19TrainAsset, carrier, runtime, mailBuilderSource] = await Promise.all([
        readFile(new URL('../../resources/views/emails/parts/responsive-css.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/emails/parts/signature.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/mail-templates/assets/zug-dampf-light.png', import.meta.url)),
        readFile(new URL('../../resources/mail-templates/assets/zug-dampf-v15-light.png', import.meta.url)),
        readFile(new URL('../../resources/mail-templates/assets/zug-dampf-v17-light.png', import.meta.url)),
        readFile(new URL('../../resources/mail-templates/assets/zug-dampf-v19-light.png', import.meta.url)),
        readFile(new URL('../../app/Support/Mail/SignatureTrainCarrier.php', import.meta.url), 'utf8'),
        readFile(new URL('../../app/Support/MailSignature.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/mail-builder.js', import.meta.url), 'utf8'),
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
    const baseRuntimeCss = css.slice(css.indexOf('.rt-sign-stage {'), css.indexOf('/* V11 bis V13'));
    assert.doesNotMatch(baseRuntimeCss, /\.rt-sign-train-layer\s*\{[^}]*position:\s*absolute !important;/s);
    assert.doesNotMatch(css, /^\s*\.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0\s*!important;/ms);
    assert.match(css, /tr\[data-rt-artifact-version="v21"\] \.rt-sign-train-layer\s*\{[^}]*margin-bottom:\s*0\s*!important;/s);
    assert.match(css, /\.rt-sign-train,\s*\.rt-sign-train-mso\s*\{[^}]*position:\s*static !important;[^}]*display:\s*inline-block !important;[^}]*vertical-align:\s*bottom !important;/s);
    assert.match(css, /\.rt-train-idle-overlay\s*\{[^}]*position:\s*absolute !important;[^}]*bottom:\s*0 !important;[^}]*height:\s*0 !important;/s);
    assert.match(css, /@keyframes rt-train-idle-reveal/);
    assert.match(css, /animation-delay:\s*13s/);

    assert.equal(trainAsset.toString('ascii', 1, 4), 'PNG');
    const assetWidth = trainAsset.readUInt32BE(16);
    const assetHeight = trainAsset.readUInt32BE(20);
    assert.deepEqual([assetWidth, assetHeight], [2160, 159]);
    assert.equal(v15TrainAsset.toString('ascii', 1, 4), 'PNG');
    assert.deepEqual(
        [v15TrainAsset.readUInt32BE(16), v15TrainAsset.readUInt32BE(20)],
        [2016, 171],
    );
    assert.equal(v17TrainAsset.toString('ascii', 1, 4), 'PNG');
    assert.deepEqual(
        [v17TrainAsset.readUInt32BE(16), v17TrainAsset.readUInt32BE(20)],
        [2016, 171],
    );
    assert.equal(v19TrainAsset.toString('ascii', 1, 4), 'PNG');
    assert.deepEqual(
        [v19TrainAsset.readUInt32BE(16), v19TrainAsset.readUInt32BE(20)],
        [2016, 171],
    );

    assert.equal((carrier.match(/<!--\[if mso\]><tr><td class="rt-sign-train-mso"/g) || []).length, 0);
    assert.equal((carrier.match(/<!--\[if mso\]><img class="rt-sign-train-mso"/g) || []).length, 2);
    assert.equal((runtime.match(/SignatureTrainCarrier::withMsoFallback\(/g) || []).length, 1);
    assert.equal((runtime.match(/SignatureTrainCarrier::withIdleOverlay\(/g) || []).length, 1);
    assert.equal((runtime.match(/SignatureTrainCarrier::projectAsRuntimeBackground\(/g) || []).length, 0);
    assert.match(carrier, /\$imageHeight = \(\$forwardSafeTrain \|\| \(\$failOpenStage && ! \$aspectSafeTrain\)\) \? ' height="61"' : '';/);
    assert.match(carrier, /usesAspectSafeTrain\(string \$html\)[\s\S]*?SignatureArtifactVersion::usesAspectSafeTrain/);
    assert.match(carrier, /canonicalStageStartMarkup\(bool \$failOpenStage\)[\s\S]*?height:auto;min-height:200px;overflow:visible;/);
    assert.match(mailBuilderSource, /export const MAIL_SIGNATURE_SCHEMA = 29;/);
    assert.match(mailBuilderSource, /const MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT = '61';/);
    assert.match(mailBuilderSource, /MAIL_SIGNATURE_FAIL_OPEN_ARTIFACTS = Object\.freeze\(\['v15', 'v16', 'v17', 'v18', 'v19', 'v20'\]\)/);
    assert.match(mailBuilderSource, /MAIL_SIGNATURE_ASPECT_SAFE_ARTIFACTS = Object\.freeze\(\['v17', 'v18', 'v20'\]\)/);
    assert.match(mailBuilderSource, /MAIL_SIGNATURE_FORWARD_SAFE_ARTIFACTS = Object\.freeze\(\['v19'\]\)/);
    assert.match(mailBuilderSource, /failOpenStage[\s\S]*?height:\s*'auto'[\s\S]*?'min-height': MAIL_SIGNATURE_FIXED_HEIGHT[\s\S]*?overflow:\s*'visible'/);
    const v21CssStart = css.indexOf('/* V21:');
    assert.ok(v21CssStart > 0);
    const v21MobileRules = Array.from(
        css.slice(0, v21CssStart).matchAll(/tr\[data-rt-artifact-version="v21"\]([^{}]*)\{([^}]*)\}/g),
        (match) => [match[1].trim(), match[2].replace(/\s+/g, '')],
    );
    // Nur der positive Logoabstand ist geteilt, keine Legacy-Ueberlappung.
    assert.deepEqual(v21MobileRules, [['.rt-sign-content', 'padding-top:14px!important;']]);
    const v21Css = css.slice(v21CssStart);
    assert.match(v21Css, /tr\[data-rt-artifact-version="v21"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v21"\] \.rt-sign-content-frame,\s*tr\[data-rt-artifact-version="v21"\] \.rt-sign-train-layer\s*\{[^}]*position:\s*static !important;[^}]*height:\s*auto !important;[^}]*max-height:\s*none !important;/s);
    assert.match(v21Css, /tr\[data-rt-artifact-version="v21"\] \.rt-sign-stage\s*\{\s*overflow:\s*visible !important;/s);
    assert.match(v21Css, /tr\[data-rt-artifact-version="v21"\] \.rt-sign-train-layer\s*\{[^}]*max-width:\s*720px !important;[^}]*margin:\s*0 auto 0 0 !important;/s);
    assert.match(v21Css, /tr\[data-rt-artifact-version="v21"\] \.rt-sign-train-layer\[data-rt-layer-train\] \.rt-sign-train,[\s\S]*?width:\s*100% !important;[\s\S]*?max-width:\s*720px !important;[\s\S]*?height:\s*auto !important;/);
    assert.match(
        carrier.slice(
            carrier.indexOf('public static function withMsoFallback'),
            carrier.indexOf('public static function withIdleOverlay'),
        ),
        /\$fallbackStyle = \(\$aspectSafeTrain \|\| \$forwardSafeTrain\)[\s\S]*?display:inline-block;[\s\S]*?vertical-align:bottom;/,
    );
    assert.doesNotMatch(carrier, /<v:(?:rect|fill)\b/);

    const mobile = css.slice(css.indexOf('@media only screen and (max-width: 860px)'));
    assert.match(mobile, /\.rt-sign-train-layer\s*\{[\s\S]*?width: 100% !important;/);
    assert.match(mobile, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][^}]+width: 150% !important; max-width: none !important; margin-left: 0 !important/);
    assert.match(mobile, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][^}]+width: 150% !important; max-width: none !important; margin-left: -25% !important/);
    assert.match(mobile, /data-rt-layer-mobile="stop60"\]\[data-rt-layer-size\][^}]+width: 160% !important;[^}]+margin-left: -36% !important/);
    const phone = css.slice(css.indexOf('@media only screen and (max-width: 480px)'));
    assert.match(phone, /data-rt-layer-mobile="train"\]\[data-rt-layer-size\][\s\S]*?width: 175% !important;[\s\S]*?margin-left: -8% !important;/);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-stage\s*\{[^}]*height: 280px !important;[^}]*max-height: 280px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v8"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v9"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*height: 280px !important;[^}]*max-height: 280px !important;[^}]*margin-bottom: -280px !important;/s);
    assert.match(phone, /data-rt-layer-mobile="stop65"\]\[data-rt-layer-size\][^}]+width: 175% !important;[^}]+margin-left: -40% !important/);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*height: 270px !important;[^}]*max-height: 270px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\s*\{[^}]*margin-bottom: -270px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\s*\{[^}]*height: 190px !important;[^}]*max-height: 190px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\s*\{[^}]*height: 175px !important;[^}]*max-height: 175px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\s*\{[^}]*margin-bottom: -175px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*max-height: none !important;[^}]*min-height: 175px !important;[^}]*overflow: visible !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\s*\{[^}]*position: relative !important;[^}]*z-index: 0 !important;[^}]*height: 175px !important;[^}]*margin-bottom: -175px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-content-frame\s*\{[^}]*position: relative !important;[^}]*z-index: 1 !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 200px !important;[^}]*overflow: visible !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-train-layer\s*\{[^}]*height: 200px !important;[^}]*margin-bottom: -200px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v16"\] \.rt-sign-train-mso\s*\{[^}]*display: block !important;[^}]*margin-bottom: 0 !important;[^}]*vertical-align: bottom !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 200px !important;[^}]*overflow: visible !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v19"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-train\s*\{[^}]*display: block !important;[^}]*height: auto !important;[^}]*margin-bottom: 0 !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v19"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-train-mso\s*\{[^}]*width: 720px !important;[^}]*height: 61px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v19"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 200px !important;[^}]*overflow: visible !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v19"\] \.rt-sign-train-layer\s*\{[^}]*position: absolute !important;[^}]*bottom: 0 !important;[^}]*height: auto !important;[^}]*margin: 0 !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v19"\] \.rt-sign-train-frame,\s*tr\[data-rt-artifact-version="v19"\] \.rt-sign-train-slot\s*\{\s*height: 100% !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v19"\] \.rt-sign-train\s*\{[^}]*width: 108\.67% !important;[^}]*max-width: none !important;[^}]*margin-left: 0 !important;/s);
    assert.doesNotMatch(css, /tr\[data-rt-artifact-version="v20"\] \.rt-sign-train-layer\s*\{[^}]*position: absolute !important;/s);
    assert.match(css, /tr\[data-rt-signature-density="compact"\] \.rt-sign-stage,\s*tr\[data-rt-signature-density="compact"\] \.rt-sign-train-layer\s*\{[^}]*height: 145px !important;[^}]*max-height: 145px !important;/s);
    assert.match(css, /tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v14"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-train-mso\s*\{[^}]*width: 94% !important;[^}]*margin-left: 0 !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\s*\{[^}]*height: 296px !important;[^}]*max-height: 296px !important;/s);
    assert.match(mobile, /tr\[data-rt-signature-density="compact"\] \.rt-sign-stage,\s*tr\[data-rt-signature-density="compact"\] \.rt-sign-train-layer\s*\{[^}]*height: 215px !important;[^}]*max-height: 215px !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 296px !important;[^}]*overflow: visible !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 215px !important;[^}]*overflow: visible !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-stage\s*\{[^}]*min-height: 304px !important;[^}]*overflow: visible !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-train-layer\s*\{[^}]*height: 304px !important;[^}]*margin-bottom: -304px !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-stage\s*\{[^}]*min-height: 304px !important;[^}]*overflow: visible !important;/s);
    assert.match(mobile, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-train-layer\s*\{[^}]*height: 304px !important;[^}]*margin-bottom: -304px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\s*\{[^}]*height: 264px !important;[^}]*max-height: 264px !important;/s);
    assert.match(phone, /tr\[data-rt-signature-density="compact"\] \.rt-sign-stage,\s*tr\[data-rt-signature-density="compact"\] \.rt-sign-train-layer\s*\{[^}]*height: 190px !important;[^}]*max-height: 190px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 264px !important;[^}]*overflow: visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v15"\]\[data-rt-signature-density="compact"\] \.rt-sign-stage\s*\{[^}]*height: auto !important;[^}]*min-height: 190px !important;[^}]*overflow: visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-stage\s*\{[^}]*min-height: 272px !important;[^}]*overflow: visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-train-layer\s*\{[^}]*height: 272px !important;[^}]*margin-bottom: -272px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-stage,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-stage\s*\{[^}]*min-height: 272px !important;[^}]*overflow: visible !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-train-layer,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-train-layer\s*\{[^}]*height: 272px !important;[^}]*margin-bottom: -272px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v16"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop60"\] \.rt-sign-train\s*\{[^}]*width: 160% !important;[^}]*margin-left: -36% !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v17"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop60"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v18"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop60"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v19"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop60"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v20"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop60"\] \.rt-sign-train\s*\{[^}]*width: 164% !important;[^}]*height: auto !important;[^}]*margin-left: -40% !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v11"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v12"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v14"\] \.rt-sign-content,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-content\s*\{[^}]*padding-top: 14px !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v10"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v11"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 108\.67% !important;[^}]*margin-left: 0 !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v12"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v13"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 135% !important;[^}]*margin-left: -15\.75% !important;/s);
    assert.match(phone, /tr\[data-rt-artifact-version="v14"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train,\s*tr\[data-rt-artifact-version="v15"\] \.rt-sign-train-layer\[data-rt-layer-mobile="stop65"\] \.rt-sign-train\s*\{[^}]*width: 175% !important;[^}]*margin-left: -40% !important;/s);
    assert.match(mobile, /data-rt-layer-mobile="right"\]\[data-rt-layer-size\][^}]+width: 200% !important; max-width: none !important; margin-left: -100% !important/);
});
