import test from 'node:test';
import assert from 'node:assert/strict';
import { DOMParser } from 'linkedom';

import {
    MAIL_PREVIEW_DEVICES,
    calculateMailPreviewGeometry,
    closeInitialMailPopovers,
    createMailNavigationController,
    createMailPreviewController,
    parseMailCssProjectStyles,
    projectForMailDocument,
    rehydrateAuthoritativeMailProject,
    resolveMailPreviewDevice,
    serializeMailDocumentForSave,
    serializeMailProjectStyles,
} from '../../resources/js/mail-builder.js';
import { mailCanvasStyles } from '../../resources/js/mail-builder-blocks.js';

test('mail canvas renders real local token assets in light and dark without mutating config', () => {
    const previewAssets = {
        light: {
            logo: 'data:image/png;base64,light-logo',
            train: 'data:image/png;base64,light-train',
        },
        dark: {
            logo: 'data:image/png;base64,dark-logo',
            train: 'data:image/png;base64,dark-train',
        },
        icons: {
            phone: 'data:image/png;base64,phone-icon',
            mobile: 'data:image/png;base64,mobile-icon',
            email: 'data:image/png;base64,email-icon',
            web: 'data:image/png;base64,web-icon',
            location: 'data:image/png;base64,location-icon',
        },
    };
    const snapshot = structuredClone(previewAssets);

    const light = mailCanvasStyles('light', previewAssets);
    const dark = mailCanvasStyles('dark', previewAssets);

    assert.match(light, /\[bgcolor="\{\{PAGE_BG\}\}"\]/);
    assert.match(light, /#e7eaed/);
    assert.match(light, /data:image\/png;base64,light-logo/);
    assert.match(light, /data:image\/png;base64,light-train/);
    assert.match(light, /data:image\/png;base64,phone-icon/);
    assert.match(light, /data:image\/png;base64,location-icon/);
    assert.match(dark, /#070a0e/);
    assert.match(dark, /data:image\/png;base64,dark-logo/);
    assert.match(dark, /data:image\/png;base64,dark-train/);
    assert.doesNotMatch(dark, /light-logo/);
    assert.deepEqual(previewAssets, snapshot);
});

test('signature project gets a valid editor-only table and neutral preview sources', () => {
    const source = {
        pages: [{
            component: '<tr><td class="rt-pad rt-sign-cell" style="background-image:url(\'{{TRAIN_SRC}}\')"><img src="{{LOGO_SRC}}" alt="{{FIRMENNAME}}"><img src="{{ICON_EMAIL_SRC}}"><img src="{{ICON_LOCATION_SRC}}"></td></tr><tr><td style="color:{{SIGNATURE_LEGAL_TEXT}}">Rechtliches</td></tr>',
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
    assert.match(component, /data-rt-mail-preview-train="TRAIN_SRC"/);
    assert.doesNotMatch(component, /src="\{\{LOGO_SRC\}\}"/);
    assert.doesNotMatch(component, /\{\{TRAIN_SRC\}\}/);
    assert.deepEqual(draft.builderData, source);
});

test('signature preview roundtrips the single strict train carrier and two top-level rows', () => {
    const original = '<tr><td class="rt-sign-cell" style="background-image:url(\'{{TRAIN_SRC}}\')"><img src="{{LOGO_SRC}}"></td></tr><tr><td style="color:{{SIGNATURE_LEGAL_TEXT}}"><img src="{{ICON_EMAIL_SRC}}"></td></tr>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: original }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });

    assert.equal((project.pages[0].component.match(/data-rt-mail-preview-train/g) || []).length, 1);
    const outgoing = serializeMailDocumentForSave({
        project,
        html: project.pages[0].component,
        kind: 'signature',
        environment: { DOMParser },
    });

    assert.equal((outgoing.html.match(/\{\{TRAIN_SRC\}\}/g) || []).length, 1);
    assert.equal((outgoing.html.match(/^<tr|\n<tr/g) || []).length, 2);
    assert.match(outgoing.html, /class="rt-sign-cell"/);
    assert.doesNotMatch(outgoing.html, /data-rt-mail-(?:signature-canvas|preview)/);
    assert.doesNotMatch(outgoing.html, /data:image\//);
    assert.equal(outgoing.project.pages[0].component, outgoing.html);
});

test('signature save fails closed when a preview marker is removed', () => {
    const original = '<tr><td class="rt-sign-cell" style="background-image:url(\'{{TRAIN_SRC}}\')"><img src="{{LOGO_SRC}}"></td></tr><tr><td>Rechtliches</td></tr>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: original }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } });

    const withoutTrainMarker = project.pages[0].component.replace(' data-rt-mail-preview-train="TRAIN_SRC"', '');
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
});

test('signature load fails closed for a second or displaced train binding', () => {
    const duplicateTrain = '<tr><td class="rt-sign-cell" style="background-image:url(\'{{TRAIN_SRC}}\')"><span style="background:url(\'{{TRAIN_SRC}}\')">X</span></td></tr><tr><td>Rechtliches</td></tr>';
    const displacedTrain = '<tr><td class="rt-sign-cell">Inhalt</td><td style="background:url(\'{{TRAIN_SRC}}\')">Zug</td></tr><tr><td>Rechtliches</td></tr>';

    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: duplicateTrain }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /genau ein/);
    assert.throws(() => projectForMailDocument({
        builderData: { pages: [{ component: displacedTrain }], styles: [] },
        css: '',
    }, () => [], { kind: 'signature', environment: { DOMParser } }), /zwei Tabellenzeilen/);
});

test('GrapesJS inline import rules are merged in cascade order without touching user CSS', () => {
    const transparent = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
    const html = '<table data-rt-mail-signature-canvas="true"><tbody>'
        + '<tr><td class="rt-sign-cell c777 c101 c102" data-rt-mail-inline-source="s1" data-rt-mail-preview-train="TRAIN_SRC" style="padding:9px;">Inhalt</td></tr>'
        + '<tr><td class="c777">Rechtliches</td></tr>'
        + '</tbody></table>';
    const project = {
        pages: [{ component: html }],
        styles: [
            { selectors: ['c101'], style: { padding: '2px', color: '{{SIGNATURE_TEXT_PRIMARY}}', 'background-image': "url('about:blank#rt-mail-train-preview')" } },
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

    assert.match(outgoing.html, /class="rt-sign-cell c777"/);
    assert.match(outgoing.html, /padding:9px;/);
    assert.match(outgoing.html, /color:#123456;/);
    assert.match(outgoing.html, /font-size:12px;/);
    assert.match(outgoing.html, /background-image:url\('\{\{TRAIN_SRC\}\}'\);/);
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

test('template preview preserves doctype and head while restoring exactly one signature slot', () => {
    const canonical = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>RailTime</title><style>.rt-shell{width:100%}</style></head><body bgcolor="#fff"><table class="rt-shell"><tbody><tr><td>Inhalt</td></tr>{{SIGNATURE_BLOCK}}</tbody></table></body></html>';
    const project = projectForMailDocument({
        builderData: { pages: [{ component: canonical }], styles: [] },
        css: '',
    }, () => [], { kind: 'template', environment: { DOMParser } });

    assert.doesNotMatch(project.pages[0].component, /<!doctype|<html|<head/i);
    assert.match(project.pages[0].component, /data-rt-mail-preview-only="signature"/);

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
    assert.doesNotMatch(outgoing.html, /data-rt-mail-(?:preview-only|signature-preview)/);
    assert.equal(outgoing.project.pages[0].component, outgoing.html);
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

test('mail preview devices model real desktop, tablet and mobile client widths', () => {
    assert.deepEqual(MAIL_PREVIEW_DEVICES.desktop, { id: 'desktop', label: 'Desktop', width: 1024 });
    assert.deepEqual(MAIL_PREVIEW_DEVICES.tablet, { id: 'tablet', label: 'Tablet', width: 820 });
    assert.deepEqual(MAIL_PREVIEW_DEVICES.mobile, { id: 'mobile', label: 'Mobil', width: 375 });
    assert.equal(resolveMailPreviewDevice('unknown'), MAIL_PREVIEW_DEVICES.desktop);
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
    const editor = {
        DeviceManager: {
            get: (id) => devices.get(id),
            add: (id, attributes) => devices.set(id, attributes),
        },
        Canvas: { setZoom: (zoom) => zooms.push(zoom) },
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

    assert.match(shell, /pageBuilderOpen:\s*false/);
    assert.match(shell, /data-page-builder-preview-first/);
    assert.match(shell, /data-page-builder-assist/);
    assert.doesNotMatch(shell, /data-page-builder-panel-host/);

    assert.match(mailView, /data-mail-studio-toolbar/);
    assert.match(mailView, /workspace-class="min-h-0 flex-1 overflow-hidden p-0"/);
    assert.match(mailView, /const studioRoot = workspace\.closest\('\[data-page-builder-fullscreen-root\]'\)/);
    assert.doesNotMatch(mailView, /class="rt-mail-preview-toolbar"/);

    assert.match(mailCss, /\.rt-mail-studio\s*\{[\s\S]*?overflow:\s*hidden;/);
    assert.doesNotMatch(mailCss, /min-height:\s*42rem/);
    assert.match(shellCss, /font-family:\s*'Plus Jakarta Sans Variable'/);
    assert.match(shellCss, /html\[data-rt-pagebuilder-assist-open='true'\]/);
    assert.match(shellCss, /\.lmz-builder\s+\.lmzbjs-layers/);
    assert.match(shellCss, /@media \(max-width: 639\.98px\)[\s\S]*?\.lmz-builder__popover/);
});
