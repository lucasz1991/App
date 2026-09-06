import {
    MAIL_BLOCK_ATTRIBUTE,
    MAIL_DEFAULT_FONT_STACK,
    SIGNATURE_PLACEHOLDER,
    createMailBlocks,
    mailCanvasStyles,
} from './mail-builder-blocks.js';
import {
    createLmzEditorChrome,
    createLmzAssistantAdapter,
    createPageBuilderLifecycleController,
    componentAnimationContext,
    isMailSignatureBackgroundComponent,
    MAIL_SIGNATURE_BACKGROUND_SIZES,
    mailSignatureBackgroundStyle,
    handleScopedRtePaste,
    pageBuilderWorkspaceIsActive,
    refreshPausedAnimatedPreviewElement,
    restartAnimatedPreview,
    resolveLmzEditorMode,
    waitForPageBuilderActivation,
} from './lmz-editor-core.js';
import {
    createMailDegradationPreview,
    normalizeMailCompatibilityManifest,
    normalizeMailDegradationMode,
} from './mail-compatibility.js';

/**
 * E-Mail-Modus des LMZ Page Builders (Vendor 2.4.5).
 *
 * Bewusst OHNE eigenen Startvorgang: dieses Modul registriert nichts an
 * document und haengt an keinem Livewire-Ereignis. Es stellt nur
 * createMailBuilder() bereit; wer den Editor einhaengt, entscheidet die
 * aufrufende Stelle.
 *
 * Der Unterschied zum Marketing-Studio (resources/js/marketing-studio.js) ist
 * nicht kosmetisch: dort wird eine Bildflaeche mit fester Kantenlaenge
 * gestaltet, hier ein Dokument, das durch Outlook, Gmail und Apple Mail muss.
 * Deshalb faellt hier alles weg, was eine Mail nicht ueberlebt — Motion,
 * Flex- und Grid-Eigenschaften — und der Style-Manager wird auf die
 * Eigenschaften beschnitten, die im Bestand der RailTime-Vorlagen
 * tatsaechlich vorkommen. Die drei Geraetebreiten sind reine Vorschaufenster;
 * sie erzeugen keine zusaetzlichen Media Queries im gespeicherten Dokument.
 */

const MAIL_CANVAS_STYLE_ID = 'rt-mail-canvas-style';
const MAIL_CANVAS_BODY_CLASS = 'rt-mail-canvas';
const MAIL_SIGNATURE_CANVAS_ATTRIBUTE = 'data-rt-mail-signature-canvas';
const MAIL_PREVIEW_IMAGE_ATTRIBUTE = 'data-rt-mail-preview-token';
const MAIL_IMPORTED_INLINE_ATTRIBUTE = 'data-rt-mail-inline-source';
const MAIL_PRESENTATION_SYNC_STATE = new WeakMap();
const MAIL_TEMPLATE_MARK_START_NAME = 'RT_TEMPLATE_MARK_START';
const MAIL_TEMPLATE_MARK_END_NAME = 'RT_TEMPLATE_MARK_END';
const MAIL_TEMPLATE_MARK_START = `<!-- ${MAIL_TEMPLATE_MARK_START_NAME} -->`;
const MAIL_TEMPLATE_MARK_END = `<!-- ${MAIL_TEMPLATE_MARK_END_NAME} -->`;
const MAIL_TEMPLATE_APPLICATION_START_NAME = 'RT_APPLICATION_CONTENT_START';
const MAIL_TEMPLATE_APPLICATION_END_NAME = 'RT_APPLICATION_CONTENT_END';
const MAIL_TEMPLATE_APPLICATION_START = `<!-- ${MAIL_TEMPLATE_APPLICATION_START_NAME} -->`;
const MAIL_TEMPLATE_APPLICATION_END = `<!-- ${MAIL_TEMPLATE_APPLICATION_END_NAME} -->`;
const MAIL_TEMPLATE_APPLICATION_PLACEHOLDER = '{{APPLICATION_CONTENT}}';
const MAIL_PREVIEW_TRANSPARENT_PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
export const MAIL_SIGNATURE_SCHEMA = 29;
const MAIL_SIGNATURE_FIXED_HEIGHT = '200px';
const MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE = '200';
const MAIL_SIGNATURE_TRAIN_OVERLAP = '-200px';
const MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT = '61';
const MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE = 'data-rt-artifact-version';
const MAIL_SIGNATURE_FAIL_OPEN_ARTIFACTS = Object.freeze(['v15', 'v16', 'v17', 'v18', 'v19', 'v20']);
const MAIL_SIGNATURE_ASPECT_SAFE_ARTIFACTS = Object.freeze(['v17', 'v18', 'v20']);
const MAIL_SIGNATURE_FORWARD_SAFE_ARTIFACTS = Object.freeze(['v19']);
const MAIL_SIGNATURE_FLOW_SAFE_ARTIFACTS = Object.freeze(['v21', 'v25']);
const MAIL_SIGNATURE_MAIN_MARKER_NAME = 'RT_SIGNATURE_MAIN_END';
const MAIL_SIGNATURE_MAIN_MARKER = `<!-- ${MAIL_SIGNATURE_MAIN_MARKER_NAME} -->`;
const MAIL_SIGNATURE_CONTACT_MARKER_ATTRIBUTE = 'data-rt-mail-contact-marker';
const MAIL_SIGNATURE_CONTACT_MARKERS = Object.freeze({
    PHONE: Object.freeze({
        tableClass: 'rt-contact',
        forbiddenClass: 'rt-company-contact',
        valueToken: '{{DURCHWAHL}}',
    }),
    MOBILE: Object.freeze({
        tableClass: 'rt-contact',
        forbiddenClass: 'rt-company-contact',
        valueToken: '{{MOBIL}}',
    }),
    WEBSITE: Object.freeze({
        tableClass: 'rt-company-contact',
        forbiddenClass: null,
        valueToken: '{{FIRMEN_WEBSITE_LABEL}}',
    }),
    COMPANY_PHONE: Object.freeze({
        tableClass: 'rt-company-contact',
        forbiddenClass: null,
        valueToken: '{{FIRMEN_TELEFON}}',
    }),
    COMPANY_EMAIL: Object.freeze({
        tableClass: 'rt-company-contact',
        forbiddenClass: null,
        valueToken: '{{FIRMEN_EMAIL}}',
    }),
});

/**
 * Bestimmt den Medienvertrag aus dem zu importierenden/exportierenden HTML.
 * Die PHP-Konfiguration liefert die Dateilisten; JavaScript waehlt bei einer
 * Signatur den explizit markierten Vertrag. Nur markerlose Altstaende fallen
 * auf v7 zurueck. Ein unbekannter Marker bleibt dadurch fail-closed.
 *
 * @param {object} requirements
 * @param {string} kind
 * @param {string} html
 * @returns {string[]}
 */
export function resolvePortableMediaRequirementIds(requirements, kind, html) {
    const normalizedKind = String(kind || '').trim().toLowerCase();
    const contracts = requirements?.[normalizedKind];
    const artifactMarker = String(html || '').match(
        /\bdata-rt-artifact-version\s*=\s*(["'])(v[1-9][0-9]{0,3})\1/i,
    );
    const contract = normalizedKind === 'signature'
        ? (artifactMarker?.[2]?.toLowerCase() || 'v7')
        : 'default';
    const ids = contracts?.[contract];

    if (!Array.isArray(ids) || ids.length === 0) {
        throw new Error('Der serverseitige Medienvertrag ist nicht vollständig konfiguriert.');
    }

    const normalized = ids.map((id) => String(id || '').trim());
    if (normalized.some((id) => !/^[A-Za-z0-9._-]+\.(?:gif|png|jpe?g|webp)$/i.test(id))
        || new Set(normalized).size !== normalized.length) {
        throw new Error('Der serverseitige Medienvertrag enthält ungültige oder doppelte Kennungen.');
    }

    return normalized;
}

/**
 * Dieser Dokumenteditor ist absichtlich fest an das Mailprofil gebunden.
 * Das Profil darf hier nicht zur Laufzeit auf Marketing umgeschaltet werden,
 * weil bereits das Oeffnen sonst nicht portable Styles freischalten koennte.
 */
export const MAIL_EDITOR_MODE = resolveLmzEditorMode('mail');
const MAIL_SIGNATURE_RAW_CONTACT_MARKER = /RT_(?:PHONE|MOBILE|WEBSITE|COMPANY_PHONE|COMPANY_EMAIL)_(?:START|END)/i;
const MAIL_AUTO_STYLE_CLASS = /^c\d+$/i;
const MAIL_CSS_TOKEN = /\{\{([A-Z][A-Z0-9_]*)\}\}/g;
const MAIL_CSS_TOKEN_COLORS = Object.freeze([
    'aliceblue', 'antiquewhite', 'aquamarine', 'azure', 'beige', 'bisque',
    'blanchedalmond', 'blueviolet', 'burlywood', 'cadetblue', 'chartreuse',
    'chocolate', 'coral', 'cornflowerblue', 'cornsilk', 'crimson', 'darkcyan',
    'darkgoldenrod', 'darkkhaki', 'darkmagenta', 'darkolivegreen',
    'darkorange', 'darkorchid', 'darksalmon', 'darkseagreen', 'darkslateblue',
    'darkturquoise', 'darkviolet', 'deeppink', 'deepskyblue', 'dodgerblue',
    'firebrick', 'floralwhite', 'forestgreen', 'gainsboro', 'ghostwhite',
    'goldenrod', 'greenyellow', 'honeydew', 'hotpink', 'indianred', 'indigo',
    'khaki', 'lavender', 'lavenderblush', 'lawngreen', 'lemonchiffon',
    'lightblue', 'lightcoral', 'lightcyan', 'lightgoldenrodyellow',
]);
const MAIL_PREVIEW_IMAGE_TOKENS = Object.freeze([
    'LOGO_SRC',
    'ICON_RT_SRC',
    'TRAIN_SRC',
    'ICON_PHONE_SRC',
    'ICON_MOBILE_SRC',
    'ICON_EMAIL_SRC',
    'ICON_WEB_SRC',
    'ICON_LOCATION_SRC',
]);

export function mailTokenMediaDefinitions(previewAssets = {}, theme = 'light') {
    const palette = theme === 'dark' ? previewAssets.dark || {} : previewAssets.light || {};
    const icons = previewAssets.icons || {};
    return [
        { token: 'LOGO_SRC', label: 'RailTime Firmenlogo', src: palette.logo || '' },
        { token: 'ICON_RT_SRC', label: 'RailTime RT-Zeichen', src: palette.mark || '' },
        { token: 'TRAIN_SRC', label: 'RailTime Zuganimation', src: palette.train || '' },
        { token: 'ICON_PHONE_SRC', label: 'Telefon-Icon', src: icons.phone || '' },
        { token: 'ICON_MOBILE_SRC', label: 'Mobil-Icon', src: icons.mobile || '' },
        { token: 'ICON_EMAIL_SRC', label: 'E-Mail-Icon', src: icons.email || '' },
        { token: 'ICON_WEB_SRC', label: 'Web-Icon', src: icons.web || '' },
        { token: 'ICON_LOCATION_SRC', label: 'Standort-Icon', src: icons.location || '' },
    ];
}

const MAIL_TEMPLATE_SIGNATURE_PREVIEW = '<tr data-rt-mail-preview-only="signature"><td style="padding:0;"><div data-rt-mail-signature-preview="true" contenteditable="false" role="note" aria-label="Hier wird beim Versand der zentrale Signaturblock eingesetzt.">Signaturblock wird beim Versand hier eingesetzt</div></td></tr>';
const MAIL_TEMPLATE_APPLICATION_PREVIEW = '<tr data-rt-mail-preview-only="application"><td style="padding:0;"><div role="note" aria-label="Hier werden beim Versand die Anwendungsinhalte eingesetzt.">Anwendungsinhalte werden beim Versand hier eingesetzt</div></td></tr>';

function mailPreviewAssetSources(previewAssets = {}) {
    return [
        MAIL_PREVIEW_TRANSPARENT_PIXEL,
        previewAssets?.light?.logo,
        previewAssets?.light?.mark,
        previewAssets?.light?.train,
        previewAssets?.dark?.logo,
        previewAssets?.dark?.mark,
        previewAssets?.dark?.train,
        previewAssets?.icons?.phone,
        previewAssets?.icons?.mobile,
        previewAssets?.icons?.email,
        previewAssets?.icons?.web,
        previewAssets?.icons?.location,
    ].filter((source, index, sources) => typeof source === 'string'
        && source.length > 0
        && sources.indexOf(source) === index);
}

function markMailPreviewImageTokens(root) {
    let marked = 0;

    Array.from(root?.querySelectorAll?.('img') || []).forEach((image) => {
        if (image.hasAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE)) {
            throw new Error('Das Maildokument enthaelt einen reservierten Bildvorschau-Marker.');
        }

        const source = String(image.getAttribute('src') || '');
        const token = MAIL_PREVIEW_IMAGE_TOKENS.find((candidate) => source === `{{${candidate}}}`);
        if (!token) return;

        image.setAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE, token);
        image.setAttribute('src', MAIL_PREVIEW_TRANSPARENT_PIXEL);
        marked += 1;
    });

    root?.querySelectorAll?.('td.rt-sign-cell[data-rt-signature-background]').forEach((carrier) => {
        if (carrier.hasAttribute('data-rt-mail-preview-train')) throw new Error('Das Maildokument enthaelt einen reservierten Hintergrundvorschau-Marker.');
        carrier.setAttribute('data-rt-mail-preview-train', 'TRAIN_SRC');
        carrier.setAttribute('style', replaceInlineStyleDeclaration(carrier.getAttribute('style'), 'background-image', 'none'));
        marked += 1;
    });

    return marked;
}

function restoreMailPreviewImageTokens(root) {
    let restored = 0;

    Array.from(root?.querySelectorAll?.(`[${MAIL_PREVIEW_IMAGE_ATTRIBUTE}]`) || []).forEach((image) => {
        const token = image.getAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE);
        if (image.tagName !== 'IMG' || !MAIL_PREVIEW_IMAGE_TOKENS.includes(token)) {
            throw new Error('Der Mail-Editor enthaelt einen unbekannten Bildplatzhalter.');
        }

        image.setAttribute('src', `{{${token}}}`);
        image.removeAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE);
        restored += 1;
    });

    root?.querySelectorAll?.('[data-rt-mail-preview-train]').forEach((carrier) => {
        if (carrier.tagName !== 'TD' || !elementClassNames(carrier).includes('rt-sign-cell')
            || carrier.getAttribute('data-rt-mail-preview-train') !== 'TRAIN_SRC'
            || !['0', '1'].includes(carrier.getAttribute('data-rt-signature-background'))) {
            throw new Error('Der Signatur-Hintergrund besitzt keine gueltige Vorschau-Bindung.');
        }
        carrier.setAttribute('style', replaceInlineStyleDeclaration(carrier.getAttribute('style'), 'background-image',
            carrier.getAttribute('data-rt-signature-background') === '1' ? "url('{{TRAIN_SRC}}')" : 'none'));
        carrier.removeAttribute('data-rt-mail-preview-train');
        restored += 1;
    });

    return restored;
}

function domParserFor(environment) {
    const DOMParserClass = environment?.DOMParser || environment?.defaultView?.DOMParser;
    if (typeof DOMParserClass !== 'function') {
        throw new Error('Das Maildokument konnte nicht in den sicheren Editorvertrag ueberfuehrt werden.');
    }

    return new DOMParserClass();
}

function parseMailFragment(parser, html) {
    return parser.parseFromString(
        `<!doctype html><html><head></head><body>${String(html || '')}</body></html>`,
        'text/html',
    );
}

function templateMarkFragment(source, required = false) {
    const html = String(source || '');
    const exactStarts = html.split(MAIL_TEMPLATE_MARK_START).length - 1;
    const exactEnds = html.split(MAIL_TEMPLATE_MARK_END).length - 1;
    const namedStarts = html.split(MAIL_TEMPLATE_MARK_START_NAME).length - 1;
    const namedEnds = html.split(MAIL_TEMPLATE_MARK_END_NAME).length - 1;

    if (exactStarts === 0 && exactEnds === 0 && namedStarts === 0 && namedEnds === 0) {
        if (required) {
            throw new Error('Der kanonische RT-Headervertrag der Mailvorlage fehlt.');
        }

        return null;
    }

    const start = html.indexOf(MAIL_TEMPLATE_MARK_START);
    const end = html.indexOf(MAIL_TEMPLATE_MARK_END);
    if (exactStarts !== 1 || exactEnds !== 1 || namedStarts !== 1 || namedEnds !== 1
        || start < 0 || end <= start) {
        throw new Error('Der RT-Headervertrag der Mailvorlage ist unvollstaendig, dupliziert oder veraendert.');
    }

    return {
        start,
        end,
        html: html.slice(start, end + MAIL_TEMPLATE_MARK_END.length),
    };
}

function templateApplicationMarkers(source, required = false) {
    const html = String(source || '');
    const exactStarts = html.split(MAIL_TEMPLATE_APPLICATION_START).length - 1;
    const exactEnds = html.split(MAIL_TEMPLATE_APPLICATION_END).length - 1;
    const namedStarts = html.split(MAIL_TEMPLATE_APPLICATION_START_NAME).length - 1;
    const namedEnds = html.split(MAIL_TEMPLATE_APPLICATION_END_NAME).length - 1;

    if (exactStarts === 0 && exactEnds === 0 && namedStarts === 0 && namedEnds === 0) {
        if (required) throw new Error('Der kanonische Anwendungsslot der Mailvorlage fehlt.');
        return null;
    }

    const start = html.indexOf(MAIL_TEMPLATE_APPLICATION_START);
    const end = html.indexOf(MAIL_TEMPLATE_APPLICATION_END);
    if (exactStarts !== 1 || exactEnds !== 1 || namedStarts !== 1 || namedEnds !== 1
        || start < 0 || end <= start) {
        throw new Error('Die Anwendungsslot-Marker der Mailvorlage sind unvollstaendig, dupliziert oder veraendert.');
    }

    return { start, end };
}

function projectTemplateApplicationSlot(source) {
    const html = String(source || '');
    const markers = templateApplicationMarkers(html);
    const placeholderCount = html.split(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER).length - 1;
    if (!markers && placeholderCount === 0) return html;
    if (!markers || placeholderCount !== 1) {
        throw new Error('Die Mailvorlage benoetigt genau einen kanonisch markierten Anwendungsslot.');
    }

    const placeholder = html.indexOf(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER);
    if (placeholder <= markers.start || placeholder >= markers.end) {
        throw new Error('Der Anwendungsslot liegt nicht mehr zwischen seinen kanonischen Markern.');
    }

    return html.replace(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER, MAIL_TEMPLATE_APPLICATION_PREVIEW);
}

function templateMarkElementSignature(element) {
    if (!element || element.nodeType !== 1) return null;

    return {
        tag: String(element.tagName || '').toLowerCase(),
        attributes: Array.from(element.attributes || [])
            .map((attribute) => [String(attribute.name || '').toLowerCase(), String(attribute.value || '')])
            .sort(([left], [right]) => left.localeCompare(right)),
        children: Array.from(element.childNodes || [])
            .filter((node) => node.nodeType === 1
                || (node.nodeType === 3 && String(node.textContent || '').trim() !== ''))
            .map((node) => node.nodeType === 1
                ? templateMarkElementSignature(node)
                : { text: String(node.textContent || '') }),
    };
}

function templateMarkComments(element) {
    const comments = [];
    const visit = (node) => {
        Array.from(node?.childNodes || []).forEach((child) => {
            if (child.nodeType === 8) comments.push(String(child.data || child.nodeValue || ''));
            visit(child);
        });
    };
    visit(element);

    return comments;
}

function canonicalTemplateMarkBinding(parser, root, baselineHtml) {
    const canonical = templateMarkFragment(baselineHtml);
    if (!canonical) return null;

    const probe = parseMailFragment(
        parser,
        `<table data-rt-template-mark-probe="true"><tbody><tr>${canonical.html}</tr></tbody></table>`,
    );
    const probeTable = probe.querySelector('table[data-rt-template-mark-probe="true"]');
    const probeRow = probeTable?.tBodies?.[0]?.rows?.[0] || probeTable?.querySelector?.('tr');
    const canonicalCells = Array.from(probeRow?.children || []);
    const canonicalCell = canonicalCells[0];
    const canonicalImages = canonicalCell?.querySelectorAll?.('img') || [];
    if (canonicalCells.length !== 1
        || canonicalCell?.tagName !== 'TD'
        || canonicalImages.length !== 1
        || canonicalImages[0].getAttribute('src') !== '{{ICON_RT_SRC}}'
        || (canonical.html.split('{{ICON_RT_SRC}}').length - 1) !== 1
        || (canonical.html.split('{{ICON_RT_STILL_SRC}}').length - 1) !== 1) {
        throw new Error('Die kanonische RT-Headerquelle der Mailvorlage ist nicht eindeutig gebunden.');
    }

    const bindings = Array.from(root?.querySelectorAll?.(
        `img[${MAIL_PREVIEW_IMAGE_ATTRIBUTE}="ICON_RT_SRC"]`,
    ) || []);
    const image = bindings[0];
    const cell = image?.closest?.('td');
    const row = cell?.parentElement;
    const headerTable = row?.parentElement?.tagName === 'TBODY'
        ? row.parentElement.parentElement
        : row?.parentElement;
    const headerPad = headerTable?.closest?.('td.rt-pad');
    const shell = headerPad?.closest?.('table.rt-shell');
    const shellBody = shell?.tBodies?.[0] || shell?.querySelector?.('tbody') || shell;
    if (bindings.length !== 1
        || image?.tagName !== 'IMG'
        || cell?.tagName !== 'TD'
        || row?.tagName !== 'TR'
        || Array.from(row.children || []).length !== 2
        || row.children[1] !== cell
        || headerTable?.tagName !== 'TABLE'
        || !headerPad
        || !shell
        || shellBody?.children?.[0] !== headerPad.parentElement) {
        throw new Error('Das RT-Zeichen wurde aus seinem kanonischen Header verschoben oder vervielfacht.');
    }

    const currentSource = String(root?.innerHTML || '');
    const current = templateMarkFragment(currentSource);
    const cellSource = cell.outerHTML;
    if (current) {
        const cellOffset = current.html.indexOf(cellSource);
        if (cellOffset < 0) {
            throw new Error('Die RT-Headermarker umschliessen nicht mehr das gebundene RT-Zeichen.');
        }
    }

    const canonicalComments = templateMarkComments(canonicalCell);
    const currentComments = templateMarkComments(cell);
    if (currentComments.length !== 0
        && JSON.stringify(currentComments) !== JSON.stringify(canonicalComments)) {
        throw new Error('Das Outlook-Standbild des RT-Zeichens wurde im Editor veraendert oder verschoben.');
    }

    return { canonical, canonicalCell, cell };
}

function assertCanonicalTemplateMarkBinding(binding) {
    if (!binding) return;

    if (JSON.stringify(templateMarkElementSignature(binding.cell))
        !== JSON.stringify(templateMarkElementSignature(binding.canonicalCell))) {
        throw new Error('Das RT-Zeichen wurde im Editor veraendert oder aus seinem Header geloest.');
    }
}

function restoreCanonicalTemplateMark(root, binding) {
    if (!binding) return String(root?.innerHTML || '');

    const source = String(root?.innerHTML || '');
    const current = templateMarkFragment(source);
    const cellSource = binding.cell.outerHTML;
    let restored = '';

    if (current) {
        if (!current.html.includes(cellSource)) {
            throw new Error('Die RT-Headermarker umschliessen nicht mehr das gebundene RT-Zeichen.');
        }
        restored = source.slice(0, current.start)
            + binding.canonical.html
            + source.slice(current.end + MAIL_TEMPLATE_MARK_END.length);
    } else {
        const cellCount = source.split(cellSource).length - 1;
        if (cellCount !== 1) {
            throw new Error('Das gebundene RT-Zeichen konnte nicht eindeutig in den Header eingesetzt werden.');
        }
        restored = source.replace(cellSource, binding.canonical.html);
    }

    const fragment = templateMarkFragment(restored, true);
    if (fragment.html !== binding.canonical.html
        || (restored.split('{{ICON_RT_SRC}}').length - 1) !== 1
        || (restored.split('{{ICON_RT_STILL_SRC}}').length - 1) !== 1) {
        throw new Error('Der kanonische RT-Headervertrag konnte nicht verlustfrei wiederhergestellt werden.');
    }

    return restored;
}

function canonicalTemplateApplicationBinding(parser, root, baselineHtml) {
    const baselineMarkers = templateApplicationMarkers(baselineHtml);
    const baselinePlaceholderCount = String(baselineHtml || '')
        .split(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER).length - 1;
    if (!baselineMarkers && baselinePlaceholderCount === 0) return null;
    if (!baselineMarkers || baselinePlaceholderCount !== 1) {
        throw new Error('Die kanonische Anwendungsslot-Quelle der Mailvorlage ist nicht eindeutig.');
    }

    const rows = Array.from(root?.querySelectorAll?.('[data-rt-mail-preview-only="application"]') || []);
    const row = rows[0];
    const shell = row?.closest?.('table.rt-shell');
    const shellBody = shell?.tBodies?.[0] || shell?.querySelector?.('tbody') || shell;
    const expectedDocument = parseMailFragment(
        parser,
        `<table><tbody>${MAIL_TEMPLATE_APPLICATION_PREVIEW}</tbody></table>`,
    );
    const expectedRow = expectedDocument.querySelector('[data-rt-mail-preview-only="application"]');
    if (rows.length !== 1
        || row?.tagName !== 'TR'
        || !shell
        || shellBody?.children?.[1] !== row
        || JSON.stringify(templateMarkElementSignature(row))
            !== JSON.stringify(templateMarkElementSignature(expectedRow))) {
        throw new Error('Der Anwendungsslot wurde im Editor verschoben, vervielfacht oder veraendert.');
    }

    const source = String(root?.innerHTML || '');
    const markers = templateApplicationMarkers(source, true);
    const rowOffset = source.indexOf(row.outerHTML);
    if (rowOffset <= markers.start || rowOffset >= markers.end
        || (source.split(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER).length - 1) !== 0) {
        throw new Error('Die Anwendungsslot-Marker umschliessen nicht mehr ihre gebundene Tabellenzeile.');
    }

    return { row };
}

function restoreCanonicalTemplateApplication(root, binding, sourceHtml = null) {
    const source = sourceHtml === null ? String(root?.innerHTML || '') : String(sourceHtml);
    if (!binding) return source;

    const markers = templateApplicationMarkers(source, true);
    const rowSource = binding.row.outerHTML;
    const rowCount = source.split(rowSource).length - 1;
    const rowOffset = source.indexOf(rowSource);
    if (rowCount !== 1 || rowOffset <= markers.start || rowOffset >= markers.end) {
        throw new Error('Der gebundene Anwendungsslot konnte nicht eindeutig wiederhergestellt werden.');
    }

    const restored = source.replace(rowSource, MAIL_TEMPLATE_APPLICATION_PLACEHOLDER);
    const placeholder = restored.indexOf(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER);
    if ((restored.split(MAIL_TEMPLATE_APPLICATION_PLACEHOLDER).length - 1) !== 1
        || placeholder <= markers.start || placeholder >= markers.end) {
        throw new Error('Der kanonische Anwendungsslot konnte nicht verlustfrei wiederhergestellt werden.');
    }

    return restored;
}

/** Findet das Ende eines Starttags, ohne `>` in quotierten Attributen als
 * Tagende fehlzuinterpretieren. HTML kennt in Attributwerten kein
 * Backslash-Escaping; geschlossen wird nur durch dasselbe Quotezeichen. */
function findHtmlOpeningTag(source, tagName) {
    const start = new RegExp(`<${tagName}(?=[\\s/>])`, 'i').exec(source);
    if (!start) return null;

    let quote = null;
    for (let index = start.index + start[0].length; index < source.length; index += 1) {
        const character = source[index];
        if (quote !== null) {
            if (character === quote) quote = null;
            continue;
        }
        if (character === '"' || character === "'") {
            quote = character;
            continue;
        }
        if (character === '>') {
            return {
                index: start.index,
                html: source.slice(start.index, index + 1),
            };
        }
    }

    return null;
}

function isSignificantHtmlNode(node) {
    if (node?.nodeType === 1 || node?.nodeType === 8) return true;
    if (node?.nodeType !== 3) return false;

    // PHP trim(), wie im SignatureDocumentContract, entfernt nur diesen
    // ASCII-Whitespace. NBSP und andere sichtbare Unicode-Zeichen muessen den
    // Markerabstand weiterhin ungueltig machen statt still zu verschwinden.
    return String(node.textContent || '').replace(/[ \t\n\r\u0000\u000B]/g, '') !== '';
}

/**
 * Browser erzeugen fuer Tabellen ohne ausdrueckliches tbody selbst einen
 * Tabellenkoerper. Ein Kommentar direkt vor der ersten Zeile bleibt dabei am
 * table, waehrend Zeile und Endkommentar in das neue tbody wandern. Genau das
 * zerlegt die serverseitig benoetigten Kontaktmarker-Siblings.
 *
 * Vor dem ersten DOM-/GrapesJS-Import werden die bereits servervalidierten
 * Marker deshalb in ein reserviertes Attribut ihrer sichtbaren Zeile
 * ueberfuehrt. Kommentare koennen danach vom Builder verworfen werden, ohne
 * dass Text, Links, Icons oder Formatierungen verlorengehen. Beliebige
 * Markerzustaende werden nicht repariert: nur alle fuenf exakten, geordneten
 * Paare sind bindbar.
 */
function bindSignatureContactMarkerRows(signature, parser = null) {
    let source = String(signature || '');
    if (new RegExp(`\\b${MAIL_SIGNATURE_CONTACT_MARKER_ATTRIBUTE}\\b`, 'i').test(source)) {
        throw new Error('Die Signatur enthaelt einen reservierten Kontaktmarker des Editors.');
    }

    const pairs = [];
    Object.entries(MAIL_SIGNATURE_CONTACT_MARKERS).forEach(([marker, context]) => {
        const startName = `RT_${marker}_START`;
        const endName = `RT_${marker}_END`;
        const startComment = `<!-- ${startName} -->`;
        const endComment = `<!-- ${endName} -->`;
        const start = source.indexOf(startComment);
        const end = source.indexOf(endComment);
        const exactStarts = source.split(startComment).length - 1;
        const exactEnds = source.split(endComment).length - 1;
        const namedStarts = source.split(startName).length - 1;
        const namedEnds = source.split(endName).length - 1;

        if (exactStarts === 0 && exactEnds === 0 && namedStarts === 0 && namedEnds === 0) return;
        if (exactStarts !== 1 || exactEnds !== 1 || namedStarts !== 1 || namedEnds !== 1 || start >= end) {
            throw new Error('Die Kontaktmarker der Signatur sind unvollstaendig, dupliziert oder veraendert.');
        }

        const between = source.slice(start + startComment.length, end);
        const row = findHtmlOpeningTag(between, 'tr');
        if (!row) {
            throw new Error('Ein Kontaktmarker besitzt nicht mehr seine gebundene Wertzeile.');
        }
        if (parser) {
            const probe = parseMailFragment(
                parser,
                `<table data-rt-mail-contact-probe="true"><tbody>${between}</tbody></table>`,
            );
            const probeTables = probe.querySelectorAll('table[data-rt-mail-contact-probe="true"]');
            const probeBody = probeTables[0]?.tBodies?.[0] || probeTables[0]?.querySelector?.('tbody');
            const bodyChildren = Array.from(probe.body?.childNodes || []).filter(isSignificantHtmlNode);
            const significant = Array.from(probeBody?.childNodes || []).filter(isSignificantHtmlNode);
            const directRow = significant[0];
            if (probeTables.length !== 1
                || bodyChildren.length !== 1
                || bodyChildren[0] !== probeTables[0]
                || significant.length !== 1
                || directRow?.tagName !== 'TR'
                || !String(directRow.textContent || '').includes(context.valueToken)) {
                throw new Error('Jeder Kontaktmarker muss genau eine direkte Tabellenzeile binden.');
            }
        } else if (!between.includes(context.valueToken)) {
            throw new Error('Ein Kontaktmarker besitzt nicht mehr seine gebundene Wertzeile.');
        }

        pairs.push({
            marker,
            start,
            end,
            startComment,
            endComment,
            rowOffset: row.index,
            rowTag: row.html,
        });
    });

    if (pairs.length === 0) return { html: source, hasMarkers: false };
    if (pairs.length !== Object.keys(MAIL_SIGNATURE_CONTACT_MARKERS).length) {
        throw new Error('Die Signatur enthaelt nicht alle gebundenen Kontaktzeilen.');
    }

    const ordered = [...pairs].sort((left, right) => left.start - right.start);
    ordered.forEach((pair, index) => {
        if (index > 0 && pair.start <= ordered[index - 1].end) {
            throw new Error('Die Kontaktmarker der Signatur ueberlappen sich.');
        }
    });

    [...ordered].reverse().forEach((pair) => {
        const between = source.slice(pair.start + pair.startComment.length, pair.end);
        const taggedRow = pair.rowTag.replace(
            /\/?>$/,
            (ending) => ` ${MAIL_SIGNATURE_CONTACT_MARKER_ATTRIBUTE}="${pair.marker}"${ending}`,
        );
        const taggedBetween = between.slice(0, pair.rowOffset)
            + taggedRow
            + between.slice(pair.rowOffset + pair.rowTag.length);
        source = source.slice(0, pair.start)
            + taggedBetween
            + source.slice(pair.end + pair.endComment.length);
    });

    return { html: source, hasMarkers: true };
}

function assertSignatureContactMarkerBindings(root, required = false) {
    const source = String(root?.innerHTML || '');
    if (MAIL_SIGNATURE_RAW_CONTACT_MARKER.test(source)) {
        throw new Error('Kontaktmarker duerfen im Editor nicht neben ihrer gebundenen Zeile liegen.');
    }

    const rows = Array.from(root?.querySelectorAll?.(`[${MAIL_SIGNATURE_CONTACT_MARKER_ATTRIBUTE}]`) || []);
    if (!required && rows.length === 0) return new Map();
    if (rows.length !== Object.keys(MAIL_SIGNATURE_CONTACT_MARKERS).length) {
        throw new Error('Die gebundenen Kontaktzeilen der Signatur sind unvollstaendig oder dupliziert.');
    }

    const bindings = new Map();
    rows.forEach((row) => {
        const marker = String(row.getAttribute(MAIL_SIGNATURE_CONTACT_MARKER_ATTRIBUTE) || '');
        const context = MAIL_SIGNATURE_CONTACT_MARKERS[marker];
        const table = row.closest?.('table');
        if (!context || bindings.has(marker) || row.tagName !== 'TR' || !table
            || !table.classList.contains(context.tableClass)
            || (context.forbiddenClass && table.classList.contains(context.forbiddenClass))
            || !String(row.textContent || '').includes(context.valueToken)) {
            throw new Error('Eine Kontaktzeile wurde im Editor verschoben, vervielfacht oder veraendert.');
        }
        bindings.set(marker, row);
    });

    if (bindings.size !== Object.keys(MAIL_SIGNATURE_CONTACT_MARKERS).length) {
        throw new Error('Die gebundenen Kontaktzeilen der Signatur sind unvollstaendig.');
    }

    return bindings;
}

function restoreSignatureContactMarkers(root, required = false) {
    const bindings = assertSignatureContactMarkerBindings(root, required);
    bindings.forEach((row, marker) => {
        const parent = row.parentNode;
        if (!parent || !row.ownerDocument?.createComment) {
            throw new Error('Eine Kontaktzeile konnte nicht kanonisch rekonstruiert werden.');
        }

        row.removeAttribute(MAIL_SIGNATURE_CONTACT_MARKER_ATTRIBUTE);
        parent.insertBefore(row.ownerDocument.createComment(` RT_${marker}_START `), row);
        parent.insertBefore(row.ownerDocument.createComment(` RT_${marker}_END `), row.nextSibling);
    });

    if (required) {
        const source = String(root?.innerHTML || '');
        Object.keys(MAIL_SIGNATURE_CONTACT_MARKERS).forEach((marker) => {
            if ((source.split(`<!-- RT_${marker}_START -->`).length - 1) !== 1
                || (source.split(`<!-- RT_${marker}_END -->`).length - 1) !== 1) {
                throw new Error('Die Kontaktmarker konnten nicht exakt wiederhergestellt werden.');
            }
        });
    }

    return bindings.size;
}

/**
 * Kommentare sind keine `children`: die bisherige Signatur-Serialisierung
 * uebernahm deshalb beide kanonischen TRs, verlor aber den Zug-Layer-Anker
 * dazwischen. Kein beliebiger Markerzustand wird dabei still repariert. Ein
 * fehlender Kommentar ist der bekannte DOM-/GrapesJS-Verlust und wird spaeter
 * kanonisch eingesetzt; Duplikate, Near-Misses und verschobene echte Marker
 * bleiben ein harter Fehler.
 */
function assertRestorableSignatureMainMarker(body, rows) {
    const markerNodes = [];
    const visit = (node) => {
        Array.from(node?.childNodes || []).forEach((child) => {
            if (child.nodeType === 8
                && String(child.data || child.nodeValue || '').includes(MAIL_SIGNATURE_MAIN_MARKER_NAME)) {
                markerNodes.push(child);
            }
            visit(child);
        });
    };
    visit(body);

    const source = String(body?.innerHTML || '');
    const tokenCount = source.split(MAIL_SIGNATURE_MAIN_MARKER_NAME).length - 1;
    if (markerNodes.length === 0 && tokenCount === 0) return;

    const marker = markerNodes[0];
    const significantNodes = Array.from(body?.childNodes || []).filter((node) => (
        node.nodeType === 1
        || node.nodeType === 8
        || (node.nodeType === 3 && String(node.textContent || '').trim() !== '')
    ));
    const markerIndex = significantNodes.indexOf(marker);

    if (markerNodes.length !== 1
        || tokenCount !== 1
        || String(marker?.data || marker?.nodeValue || '').trim() !== MAIL_SIGNATURE_MAIN_MARKER_NAME
        || marker?.parentNode !== body
        || significantNodes[markerIndex - 1] !== rows[0]
        || significantNodes[markerIndex + 1] !== rows[1]) {
        throw new Error('Der Hauptsignatur-Marker wurde im Editor verschoben, vervielfacht oder verändert.');
    }
}

function serializeElementAttributes(element) {
    return Array.from(element?.attributes || [])
        .map((attribute) => ` ${attribute.name}="${String(attribute.value)
            .replaceAll('&', '&amp;')
            .replaceAll('"', '&quot;')}"`)
        .join('');
}

function markImportedInlineStyles(root) {
    const elements = [];
    if (root?.matches?.('[style]')) elements.push(root);
    elements.push(...Array.from(root?.querySelectorAll?.('[style]') || []));

    elements.forEach((element, index) => {
        if (element.hasAttribute(MAIL_IMPORTED_INLINE_ATTRIBUTE)) {
            throw new Error('Das Maildokument enthaelt einen reservierten Inline-Style-Marker.');
        }
        element.setAttribute(MAIL_IMPORTED_INLINE_ATTRIBUTE, `s${index + 1}`);
    });

    return elements.length;
}

/**
 * Entfernt ausschliesslich die Attribute, die der Mail-Builder fuer seine
 * Leinwand braucht. Die sichtbaren Elemente, Texte und Inline-Stile bleiben
 * unveraendert. Reservierte Preview-Bindungen werden vorher in den beiden
 * Serializerzweigen validiert und duerfen deshalb nie durch diese Bereinigung
 * zu einem scheinbar gueltigen Dokument werden.
 */
function stripMailEditorAttributes(root, excludedRoots = []) {
    const excluded = (Array.isArray(excludedRoots) ? excludedRoots : [excludedRoots]).filter(Boolean);
    const elements = [];
    if (root?.nodeType === 1) elements.push(root);
    elements.push(...Array.from(root?.querySelectorAll?.('*') || []));
    let removed = 0;

    elements.forEach((element) => {
        if (excluded.some((candidate) => candidate === element || candidate.contains?.(element))) return;

        Array.from(element.attributes || []).forEach((attribute) => {
            if (!String(attribute.name || '').toLowerCase().startsWith('data-rt-mail-')) return;
            element.removeAttribute(attribute.name);
            removed += 1;
        });
    });

    return removed;
}

function decodeMailCssTokens(value, sentinels) {
    if (Array.isArray(value)) return value.map((entry) => decodeMailCssTokens(entry, sentinels));
    if (!value || typeof value !== 'object') {
        if (typeof value !== 'string') return value;
        let decoded = value;
        sentinels.forEach((token, sentinel) => {
            decoded = decoded.replace(new RegExp(`\\b${sentinel}\\b`, 'gi'), `{{${token}}}`);
        });
        return decoded;
    }

    return Object.fromEntries(
        Object.entries(value).map(([key, entry]) => [key, decodeMailCssTokens(entry, sentinels)]),
    );
}

function canonicalizeParsedMailStyle(style = {}) {
    const canonical = { ...style };
    const sideValues = new Map();
    ['top', 'right', 'bottom', 'left'].forEach((side) => {
        const width = canonical[`border-${side}-width`];
        const lineStyle = canonical[`border-${side}-style`];
        const color = canonical[`border-${side}-color`];
        if ([width, lineStyle, color].every((value) => typeof value === 'string' && value.trim() !== '')) {
            sideValues.set(side, `${width} ${lineStyle} ${color}`);
            delete canonical[`border-${side}-width`];
            delete canonical[`border-${side}-style`];
            delete canonical[`border-${side}-color`];
        }
    });

    const allBordersEqual = sideValues.size === 4
        && new Set(sideValues.values()).size === 1;
    if (allBordersEqual) {
        canonical.border = sideValues.get('top');
    } else {
        sideValues.forEach((value, side) => {
            canonical[`border-${side}`] = value;
        });
    }

    const generatedBorderImageDefaults = {
        'border-image-source': 'none',
        'border-image-slice': '100%',
        'border-image-width': '1',
        'border-image-outset': '0',
        'border-image-repeat': 'stretch',
    };
    if (Object.entries(generatedBorderImageDefaults)
        .every(([property, value]) => canonical[property] === undefined || canonical[property] === value)) {
        Object.keys(generatedBorderImageDefaults).forEach((property) => delete canonical[property]);
    }

    const backgroundDefaults = [
        ['background-image', 'initial'],
        ['background-position-x', 'initial'],
        ['background-position-y', 'initial'],
        ['background-size', 'initial'],
        ['background-repeat', 'initial'],
        ['background-attachment', 'initial'],
        ['background-origin', 'initial'],
        ['background-clip', 'initial'],
    ];
    if (typeof canonical['background-color'] === 'string'
        && canonical['background-color'].trim() !== ''
        && backgroundDefaults.every(([property, value]) => canonical[property] === value)) {
        const color = canonical['background-color'];
        backgroundDefaults.forEach(([property]) => delete canonical[property]);
        delete canonical['background-color'];
        canonical.background = color;
    } else {
        const positionX = canonical['background-position-x'];
        const positionY = canonical['background-position-y'];
        if (typeof positionX === 'string' && typeof positionY === 'string') {
            if (positionX === 'initial' && positionY === 'initial') {
                delete canonical['background-position-x'];
                delete canonical['background-position-y'];
            } else {
                canonical['background-position'] = `${positionX} ${positionY}`;
                delete canonical['background-position-x'];
                delete canonical['background-position-y'];
            }
        }
        ['background-attachment', 'background-origin', 'background-clip'].forEach((property) => {
            if (canonical[property] === 'initial') delete canonical[property];
        });
    }

    return canonical;
}

/**
 * GrapesJS' CSS-Stringparser verwirft Werte wie `color:{{TOKEN}}` als
 * syntaktisch ungueltig. Fuer den Parser werden die Tokens deshalb kurzzeitig
 * zu eindeutigen, kontexttauglichen CSS-Farben; die strukturierten Regeln
 * bekommen unmittelbar danach wieder die kanonischen Mailtokens. `var(...)`
 * reicht hier nicht: der Browser verwirft es innerhalb von border/background-
 * Kurzformen waehrend GrapesJS diese zerlegt.
 */
export function parseMailCssProjectStyles(css, parseCss = () => []) {
    const source = String(css || '');
    if (source === '') return [];
    const tokens = Array.from(source.matchAll(MAIL_CSS_TOKEN), (match) => match[1])
        .filter((token, index, values) => values.indexOf(token) === index);
    const tokenSentinels = new Map();
    const sentinelTokens = new Map();
    let candidate = 0;
    tokens.forEach((token) => {
        while (candidate < MAIL_CSS_TOKEN_COLORS.length
            && new RegExp(`\\b${MAIL_CSS_TOKEN_COLORS[candidate]}\\b`, 'i').test(source)) {
            candidate += 1;
        }
        const sentinel = MAIL_CSS_TOKEN_COLORS[candidate];
        candidate += 1;
        if (!sentinel) {
            throw new Error('Die Mail-Stilregeln enthalten zu viele unterschiedliche Platzhalter.');
        }
        tokenSentinels.set(token, sentinel);
        sentinelTokens.set(sentinel, token);
    });

    const encoded = source.replace(MAIL_CSS_TOKEN, (_match, token) => tokenSentinels.get(token));
    const parsed = decodeMailCssTokens(parseCss(encoded) || [], sentinelTokens)
        .map((rule) => ({ ...rule, style: canonicalizeParsedMailStyle(rule?.style) }));
    const serialized = JSON.stringify(parsed);
    if (Array.from(sentinelTokens.keys()).some((sentinel) => new RegExp(`\\b${sentinel}\\b`, 'i').test(serialized))) {
        throw new Error('Ein temporaerer CSS-Tokenwert konnte nicht vollstaendig zurueckgefuehrt werden.');
    }
    if (tokens.some((token) => !serialized.includes(`{{${token}}}`))) {
        throw new Error('GrapesJS konnte mindestens einen gebundenen CSS-Wert nicht verlustfrei lesen.');
    }

    return parsed;
}

function splitCssDeclarations(style) {
    const declarations = [];
    let current = '';
    let quote = '';
    let depth = 0;
    let escaped = false;

    for (const character of String(style || '')) {
        if (escaped) {
            current += character;
            escaped = false;
            continue;
        }
        if (character === '\\' && quote) {
            current += character;
            escaped = true;
            continue;
        }
        if (quote) {
            current += character;
            if (character === quote) quote = '';
            continue;
        }
        if (character === '"' || character === "'") {
            quote = character;
            current += character;
            continue;
        }
        if (character === '(') depth += 1;
        if (character === ')' && depth > 0) depth -= 1;
        if (character === ';' && depth === 0) {
            if (current.trim()) declarations.push(current.trim());
            current = '';
            continue;
        }
        current += character;
    }

    if (current.trim()) declarations.push(current.trim());

    return declarations;
}

function declarationMap(style) {
    const declarations = new Map();
    splitCssDeclarations(style).forEach((declaration) => {
        const separator = declaration.indexOf(':');
        if (separator <= 0) return;
        const property = declaration.slice(0, separator).trim().toLowerCase();
        const value = declaration.slice(separator + 1).trim();
        if (/^(?:--)?[a-z][a-z0-9-]*$/i.test(property) && value !== '') {
            declarations.set(property, value);
        }
    });

    return declarations;
}

function mergeInlineDeclarations(ruleStyles, inlineStyle) {
    const declarations = new Map();
    ruleStyles.forEach((style) => {
        Object.entries(style || {}).forEach(([property, value]) => {
            const normalized = String(property).trim().toLowerCase();
            if (/^(?:--)?[a-z][a-z0-9-]*$/i.test(normalized) && value !== null && value !== undefined) {
                declarations.set(normalized, String(value).trim());
            }
        });
    });
    declarationMap(inlineStyle).forEach((value, property) => declarations.set(property, value));

    return Array.from(declarations.entries())
        .map(([property, value]) => `${property}:${value};`)
        .join('');
}

function autoInlineRule(rule, index) {
    if (!rule || typeof rule !== 'object'
        || rule.atRuleType || rule.mediaText || rule.state || rule.selectorsAdd) {
        return null;
    }

    const selectors = Array.isArray(rule.selectors) ? rule.selectors : [];
    if (selectors.length !== 1 || typeof selectors[0] !== 'string') return null;
    const className = selectors[0].replace(/^\./, '');
    if (!MAIL_AUTO_STYLE_CLASS.test(className) || !rule.style || typeof rule.style !== 'object') {
        return null;
    }

    return { className, index, style: rule.style };
}

function inlineGrapesImportedStyles({ parser, html, styles = [] }) {
    const parsed = parseMailFragment(parser, html);
    const rules = styles
        .map((rule, index) => autoInlineRule(rule, index))
        .filter(Boolean);
    const rulesByClass = new Map(rules
        .filter((rule) => Array.from(parsed.body?.querySelectorAll?.(`.${rule.className}`) || [])
            .every((element) => element.hasAttribute(MAIL_IMPORTED_INLINE_ATTRIBUTE)))
        .map((rule) => [rule.className, rule]));
    const consumedRules = new Set();

    parsed.body?.querySelectorAll?.('[class]')?.forEach((element) => {
        // Nur Styles, die projectForMailDocument() ausdruecklich als aus
        // einem Inline-Attribut stammend markiert hat. Eine fachlich bewusst
        // angelegte Nutzerklasse namens c123 darf nie aufgrund ihres Namens
        // umgeschrieben werden.
        if (!element.hasAttribute(MAIL_IMPORTED_INLINE_ATTRIBUTE)) return;
        const classNames = Array.from(element.classList || []);
        const matching = classNames
            .map((className) => rulesByClass.get(className))
            .filter(Boolean)
            .sort((left, right) => left.index - right.index);
        if (matching.length === 0) return;

        const mergedStyle = mergeInlineDeclarations(
            matching.map((rule) => rule.style),
            element.getAttribute('style') || '',
        );
        if (mergedStyle) element.setAttribute('style', mergedStyle);
        else element.removeAttribute('style');

        matching.forEach((rule) => {
            element.classList.remove(rule.className);
            consumedRules.add(rule.index);
        });
        if (element.classList.length === 0) element.removeAttribute('class');
    });

    parsed.body?.querySelectorAll?.(`[${MAIL_IMPORTED_INLINE_ATTRIBUTE}]`)?.forEach((element) => {
        element.removeAttribute(MAIL_IMPORTED_INLINE_ATTRIBUTE);
    });

    return {
        html: parsed.body?.innerHTML || '',
        styles: styles.filter((_rule, index) => !consumedRules.has(index)),
    };
}

function selectorForMailStyleRule(rule) {
    if (typeof rule?.selectorsAdd === 'string' && rule.selectorsAdd.trim() !== '') {
        return rule.selectorsAdd.trim();
    }

    const selectors = Array.isArray(rule?.selectors) ? rule.selectors : [];
    const selector = selectors.map((entry) => {
        const value = typeof entry === 'string' ? entry : entry?.name;
        if (typeof value !== 'string' || value.trim() === '') return '';
        const normalized = value.trim();
        return ['.', '#', '[', ':'].includes(normalized[0]) ? normalized : `.${normalized}`;
    }).join('');

    if (!selector) return '';
    return rule?.state ? `${selector}:${String(rule.state).trim()}` : selector;
}

/** Erzeugt nur die echten separaten GJS-Regeln. Komponentenstile stehen
 * bereits inline im HTML und duerfen nicht noch einmal als zufaellige #id-
 * Regel in der CSS-Spalte landen. */
export function serializeMailProjectStyles(styles = []) {
    return (Array.isArray(styles) ? styles : []).map((rule) => {
        const selector = selectorForMailStyleRule(rule);
        const declarations = Object.entries(rule?.style || {})
            .filter(([property, value]) => /^(?:--)?[a-z][a-z0-9-]*$/i.test(property)
                && value !== null && value !== undefined && String(value).trim() !== '')
            .map(([property, value]) => `${property}:${String(value).trim()};`)
            .join('');
        if (!selector || !declarations) return '';

        const cssRule = `${selector}{${declarations}}`;
        if (!rule?.atRuleType) return cssRule;
        const condition = String(rule.mediaText || '').trim();
        return condition ? `@${rule.atRuleType} ${condition}{${cssRule}}` : '';
    }).join('');
}

function markAddedMailComponentStyles(editor, component) {
    const classes = component?.getClasses?.() || [];
    const hasImportedRule = classes.some((className) => MAIL_AUTO_STYLE_CLASS.test(className)
        && editor?.Css?.getRule?.(`.${className}`));
    if (!hasImportedRule || component.getAttributes?.()[MAIL_IMPORTED_INLINE_ATTRIBUTE]) return false;

    component.addAttributes?.({ [MAIL_IMPORTED_INLINE_ATTRIBUTE]: 'added' }, { silent: true });
    return true;
}

/**
 * Logische Breiten der Mail-Vorschau. Die Werte beschreiben den Mailclient,
 * nicht das gerade verwendete Geraet: so bleibt die Desktopfassung auch auf
 * einem Telefon als vollstaendige, heruntergerechnete Arbeitsflaeche sichtbar.
 */
export const MAIL_PREVIEW_DEVICES = Object.freeze({
    wide: Object.freeze({ id: 'wide', label: 'Systemmail breit', width: 1920 }),
    desktop: Object.freeze({ id: 'desktop', label: 'Desktop', width: 1024 }),
    tablet: Object.freeze({ id: 'tablet', label: 'Tablet', width: 820 }),
    mobile: Object.freeze({ id: 'mobile', label: 'Mobil', width: 375 }),
});

export const MAIL_PREVIEW_MIN_WIDTH = 320;
export const MAIL_PREVIEW_MAX_WIDTH = 1920;

/** Freie Responsive-Breiten sind immer ganze, realistische CSS-Pixel. */
export function normalizeMailPreviewWidth(width, fallback = MAIL_PREVIEW_DEVICES.desktop.width) {
    const numeric = Number(width);
    const normalizedFallback = Number.isFinite(Number(fallback))
        ? Math.round(Number(fallback))
        : MAIL_PREVIEW_DEVICES.desktop.width;
    const rounded = Number.isFinite(numeric) ? Math.round(numeric) : normalizedFallback;

    return Math.min(MAIL_PREVIEW_MAX_WIDTH, Math.max(MAIL_PREVIEW_MIN_WIDTH, rounded));
}

/** Schalter, die im Nur-Lese-Modus verschwinden muessen. */
const WRITE_CONTROL_SELECTORS = [
    '[data-lmz-action="save"]',
    '[data-lmz-action="undo"]',
    '[data-lmz-action="redo"]',
    '[data-lmz-action="upload"]',
    '[data-lmz-upload-input]',
];

/** Bewegung hat in einer E-Mail nichts verloren — die Schalter auch nicht. */
const MOTION_CONTROL_SELECTORS = [
    '[data-lmz-action="motion-preview"]',
    '[data-lmz-action="motion-pause"]',
    '[data-lmz-action="motion-restart"]',
];

/**
 * Style-Manager des E-Mail-Modus.
 *
 * Enthaelt die SAFE-Eigenschaften der funktionierenden RailTime-Vorlagen
 * sowie eine ausdruecklich als progressiv markierte Rundung mit eckigem
 * Tabellen-/Farb-Fallback.
 * Nicht dabei und auch nicht nachtraeglich ergaenzen:
 *   - float/clear, flex-*, grid-*        zerlegen jedes Tabellenlayout
 *   - position/z-index                   in der Signatur noetig, dort aber
 *                                        serverseitig gesetzt; im Editor
 *                                        waere es nur eine Fehlerquelle
 *   - transform/transition/animation     Outlook kennt nichts davon
 *   - box-shadow/text-shadow             faellt in Outlook ersatzlos weg
 *
 * Zur Bauform: ein Sektor liest ENTWEDER buildProps ODER properties, nie
 * beides (LMZ-Builder-Core, Sector-Konstruktor). 'display' braucht eine eigene
 * Werteliste, weil die eingebaute Fassung flex anbietet — deshalb steht der
 * Fluss-Sektor auf properties mit { extend: 'display', options: [...] }.
 */
export const MAIL_SAFE_FONT_STACKS = Object.freeze([
    Object.freeze({ id: 'Arial,Helvetica,sans-serif', label: 'Arial · klassisch' }),
    Object.freeze({ id: 'Verdana,Arial,sans-serif', label: 'Verdana · gut lesbar' }),
    Object.freeze({ id: MAIL_DEFAULT_FONT_STACK, label: 'Trebuchet MS · RailTime' }),
    Object.freeze({ id: "Georgia,'Times New Roman',serif", label: 'Georgia · Serif' }),
    Object.freeze({ id: "'Times New Roman',Times,serif", label: 'Times New Roman · klassisch' }),
]);

const MAIL_SAFE_STYLE_GROUPS = Object.freeze({
    typography: Object.freeze([
        'font-family',
        'font-size',
        'font-weight',
        'line-height',
        'letter-spacing',
        'word-spacing',
        'color',
        'text-align',
        'text-decoration',
        'text-transform',
        'white-space',
    ]),
    spacing: Object.freeze([
        'padding',
        'margin',
        'width',
        'max-width',
        'height',
        'max-height',
        'box-sizing',
    ]),
    surface: Object.freeze(['background-color']),
    border: Object.freeze([
        'border',
        'border-top',
        'border-right',
        'border-bottom',
        'border-left',
        'border-radius',
    ]),
    flow: Object.freeze([
        'display',
        'vertical-align',
        'border-collapse',
        'direction',
    ]),
});

/**
 * Ein einziger, exportierter Vertrag fuer Style-Manager und Tests. Die
 * Editoroberflaeche bietet damit keine Browser-Properties an, die der
 * statische Mailpfad spaeter entfernen oder in Outlook anders auslegen muss.
 * Geschuetzte Systemkomponenten duerfen weiterhin ihre serverautoritativen
 * mso-/Positionierungsangaben besitzen; diese Liste gilt fuer Bearbeitungen.
 */
export const MAIL_SAFE_EDITABLE_STYLE_PROPERTIES = Object.freeze(
    Object.values(MAIL_SAFE_STYLE_GROUPS).flat(),
);

export const MAIL_STYLE_SECTORS = Object.freeze([
    Object.freeze({
        id: 'rt-mail-typography',
        name: 'Typografie · Schrift & Text',
        open: true,
        properties: [
            {
                extend: 'font-family',
                name: 'Schriftfamilie',
                options: MAIL_SAFE_FONT_STACKS,
            },
            ...MAIL_SAFE_STYLE_GROUPS.typography.slice(1),
        ],
    }),
    Object.freeze({
        id: 'rt-mail-spacing',
        name: 'Abstand & Größe',
        open: true,
        buildProps: MAIL_SAFE_STYLE_GROUPS.spacing,
    }),
    Object.freeze({
        id: 'rt-mail-surface',
        name: 'Fläche',
        open: false,
        // Ausschliesslich eine Farbe: frei editierbare Bilder, Gradients und
        // Ebenen im CSS-Hintergrund besitzen keinen verlaesslichen Outlook-
        // Pfad. synchronizeMailPresentationAttributes() schreibt parallel
        // das klassische bgcolor-Attribut fuer Tabellenzellen.
        properties: [
            { property: MAIL_SAFE_STYLE_GROUPS.surface[0], name: 'Hintergrundfarbe', type: 'color' },
        ],
    }),
    Object.freeze({
        id: 'rt-mail-border',
        name: 'Rahmen',
        open: false,
        properties: [
            ...MAIL_SAFE_STYLE_GROUPS.border.slice(0, -1),
            {
                extend: 'border-radius',
                name: 'Ecken (progressiv, eckiger Fallback)',
            },
        ],
    }),
    Object.freeze({
        id: 'rt-mail-flow',
        name: 'Anzeige',
        open: false,
        properties: [
            {
                extend: 'display',
                options: [
                    { id: 'block', label: 'Block' },
                    { id: 'inline-block', label: 'Inline-Block' },
                    { id: 'inline', label: 'Inline' },
                    { id: 'table', label: 'Tabelle' },
                    { id: 'table-row', label: 'Tabellenzeile' },
                    { id: 'table-cell', label: 'Tabellenzelle' },
                    { id: 'none', label: 'Ausgeblendet' },
                ],
            },
            ...MAIL_SAFE_STYLE_GROUPS.flow.slice(1),
        ],
    }),
]);

/**
 * GrapesJS-Optionen, die zuletzt ueber die fertige Konfiguration gelegt
 * werden und deshalb gewinnen.
 *
 * avoidInlineStyle: false ist der wichtigste Eintrag. Die Vorgabe von
 * GrapesJS ist true — dann landen alle im Editor gesetzten Stile als
 * '#id{...}'-Regeln in editor.getCss() und NICHT im style-Attribut. Fuer
 * eine E-Mail ist das genau falsch herum: viele Clients verwerfen den
 * style-Block, und die Nachricht kaeme unformatiert an.
 */
export const MAIL_GJS_OPTIONS = Object.freeze({
    // Ersetzt die vier Standardgeraete vollstaendig. Der Preview-Controller
    // fuegt danach drei reine Anzeigegeraete hinzu; die Umbrueche selbst
    // kommen ausschliesslich aus responsive-css.blade.php.
    deviceManager: { devices: [] },
    // Die Geraete dienen ausschliesslich der Vorschau. Stil-Aenderungen auf
    // Tablet/Mobil duerfen keine neuen GrapesJS-Media-Queries erzeugen.
    devicePreviewMode: true,
    canvas: { styles: [], scripts: [], allowExternalDrop: false },
    assetManager: { upload: false, showUrlInput: false, dropzone: false },
    styleManager: { sectors: MAIL_STYLE_SECTORS },
    avoidInlineStyle: false,
    keepUnusedStyles: false,
    richTextEditor: { actions: ['bold', 'italic', 'underline', 'link'], onPaste: handleScopedRtePaste },
});

export const MAIL_AUTOSAVE = Object.freeze({
    enabled: true,
    intervalMs: 10000,
    changesBeforeSave: 30,
});

/**
 * Leerzustand des Builders.
 *
 * storage.onLoad darf niemals null, undefined oder ein Array liefern: der
 * Builder normalisiert das zu seinem Standardprojekt und laedt die
 * Joomla-Demoseite "Neue Seite" samt Tailwind-Klassen in die Mail.
 */
export function normalizeMailProject(project) {
    if (!project || typeof project !== 'object' || Array.isArray(project)) {
        return {};
    }

    return project;
}

/**
 * Projektdaten eines Entwurfs fuer storage.onLoad.
 *
 * Das CSS liegt in einer eigenen Spalte, weil es getrennt geprueft wird —
 * fuer den Editor muss es zurueck in die Stilregeln des Projekts.
 *
 * @param {{builderData?: object, css?: string}} draft
 * @param {(css: string) => Array} parseCss
 */
function inlineStyleDeclaration(style, property) {
    const escapedProperty = String(property || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const matches = Array.from(String(style || '').matchAll(
        new RegExp(`(?:^|;)\\s*${escapedProperty}\\s*:\\s*([^;]*)`, 'gi'),
    ));

    return matches.length === 1 ? matches[0][1].trim() : null;
}

function normalizedInlineStyleValue(value) {
    return String(value || '').replace(/\s+/g, '').toLowerCase();
}

function replaceInlineStyleDeclaration(style, property, value) {
    const source = String(style || '').trim();
    const escapedProperty = String(property || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const matcher = new RegExp(`(^|;)\\s*${escapedProperty}\\s*:[^;]*`, 'gi');
    const matches = Array.from(source.matchAll(matcher));
    if (matches.length > 1) {
        throw new Error(`Der alte Zug-Carrier enthaelt ${property} mehrfach.`);
    }

    if (matches.length === 1) {
        return source.replace(matcher, (declaration, prefix) => `${prefix}${property}:${value}`);
    }

    return `${source.replace(/;+$/, '')};${property}:${value};`;
}

function removeInlineStyleDeclaration(style, property) {
    const source = String(style || '').trim();
    const escapedProperty = String(property || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const matcher = new RegExp(`(^|;)\\s*${escapedProperty}\\s*:[^;]*`, 'gi');
    const matches = Array.from(source.matchAll(matcher));
    if (matches.length > 1) {
        throw new Error(`Der alte Zug-Carrier enthaelt ${property} mehrfach.`);
    }

    return source.replace(matcher, '$1').replace(/;;+/g, ';').replace(/^;+|;+$/g, '');
}

/**
 * Der Editor verwendet fuer den Zug denselben Tokenpfad wie Logo und Icons.
 * Schema 19 (Flow-IMG ohne Ueberlappung), Schema 20 (CSS-Background),
 * Schema 21/22 (fruehere Flow-Reihenfolgen), Schema 23 (absoluter Layer)
 * und Schema 24 (proportionale Ueberlappung) werden nur bei exakt erkannter
 * Altstruktur in den markerabhaengigen aktuellen Signaturvertrag projiziert.
 * V14 und aelter behalten die feste 200-px-Buehne; V15/V16 erhalten die
 * fail-open Aussenbuehne. Prozentwerte werden bewusst nicht umgerechnet.
 * Mischformen oder frei manipulierte Layer bleiben harte Fehler.
 */
const MAIL_SIGNATURE_BASE_BACKGROUND = Object.freeze({
    'background-image': 'linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})',
    'background-repeat': 'no-repeat',
    'background-position': 'center center',
    'background-size': '100% 100%',
});
const MAIL_SIGNATURE_SCHEMA_20_BACKGROUND = Object.freeze({
    'background-image': "url('{{TRAIN_SRC}}'),linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})",
    'background-repeat': 'no-repeat,no-repeat',
    'background-position': 'left bottom,center center',
    'background-size': '100% auto,100% 100%',
});
const MAIL_SIGNATURE_TRAIN_SIZES = Object.freeze({
    100: Object.freeze({ width: '100%', centerMargin: '0', rightMargin: '0' }),
    '108.67': Object.freeze({ width: '108.67%', centerMargin: '-4.335%', rightMargin: '-8.67%' }),
    125: Object.freeze({ width: '125%', centerMargin: '-12.5%', rightMargin: '-25%' }),
    150: Object.freeze({ width: '150%', centerMargin: '-25%', rightMargin: '-50%' }),
    200: Object.freeze({ width: '200%', centerMargin: '-50%', rightMargin: '-100%' }),
});

function elementClassNames(element) {
    return String(element?.getAttribute?.('class') || '').split(/\s+/).filter(Boolean);
}

function assertExactElementAttributes(element, expected, label) {
    const names = typeof element?.getAttributeNames === 'function'
        ? element.getAttributeNames()
        : Array.from(element?.attributes || []).map((attribute) => attribute.name);
    const actual = names.map((name) => String(name).toLowerCase()).sort();
    const canonical = [...expected].map((name) => String(name).toLowerCase()).sort();
    if (actual.length !== canonical.length
        || actual.some((name, index) => name !== canonical[index])) {
        throw new Error(`${label} besitzt fremde oder fehlende Attribute.`);
    }
}

function assertInlineStyles(element, expected, label, { exact = false, optional = [] } = {}) {
    const style = String(element?.getAttribute?.('style') || '').trim();
    if (!style) throw new Error(`${label} besitzt kein eindeutiges style-Attribut.`);

    Object.entries(expected).forEach(([property, value]) => {
        if (normalizedInlineStyleValue(inlineStyleDeclaration(style, property))
            !== normalizedInlineStyleValue(value)) {
            throw new Error(`${label} besitzt keine kanonische ${property}-Angabe.`);
        }
    });

    if (!exact) return;
    const properties = style.split(';')
        .map((declaration) => declaration.trim())
        .filter(Boolean)
        .map((declaration) => declaration.split(':', 1)[0].trim().toLowerCase());
    const expectedProperties = [...Object.keys(expected), ...optional]
        .filter((property, index, all) => all.indexOf(property) === index)
        .sort();
    if (new Set(properties).size !== properties.length
        || properties.some((property) => !expectedProperties.includes(property))) {
        throw new Error(`${label} besitzt fremde oder doppelte Style-Angaben.`);
    }
}

function assertSignatureBaseStructure(wrapper, rows) {
    const carriers = Array.from(wrapper?.querySelectorAll?.('td.rt-sign-cell') || []);
    const stages = Array.from(wrapper?.querySelectorAll?.('div.rt-sign-stage') || []);
    const carrier = carriers[0];
    const stage = stages[0];
    const stageElements = Array.from(stage?.children || []);
    const contentTables = stageElements.filter((element) => element.tagName === 'TABLE');
    const contentTable = contentTables[0];

    if (!wrapper || rows.length !== 2 || rows.some((row) => row.tagName !== 'TR')
        || carriers.length !== 1 || stages.length !== 1
        || elementClassNames(carrier).length !== 1
        || carrier?.parentElement !== rows[0]
        || stage?.parentElement !== carrier
        || carrier?.lastElementChild !== stage
        || contentTables.length !== 1) {
        throw new Error('Die Signatur besitzt keine eindeutige IMG-Buehne in ihrer ersten Tabellenzeile.');
    }
    return { carrier, stage, stageElements, contentTable };
}

function usesFailOpenSignatureStage(rows) {
    return MAIL_SIGNATURE_FAIL_OPEN_ARTIFACTS.includes(
        String(rows?.[0]?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '')
            .trim()
            .toLowerCase(),
    );
}

function usesAspectSafeSignatureTrain(rows) {
    return MAIL_SIGNATURE_ASPECT_SAFE_ARTIFACTS.includes(
        String(rows?.[0]?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '')
            .trim()
            .toLowerCase(),
    );
}

function usesForwardSafeSignatureTrain(rows) {
    return MAIL_SIGNATURE_FORWARD_SAFE_ARTIFACTS.includes(
        String(rows?.[0]?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '')
            .trim()
            .toLowerCase(),
    );
}

function usesV20SignatureTrain(rows) {
    return String(rows?.[0]?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '')
        .trim()
        .toLowerCase() === 'v20';
}

function usesFlowSafeSignatureTrain(rows) {
    return MAIL_SIGNATURE_FLOW_SAFE_ARTIFACTS.includes(
        String(rows?.[0]?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '').trim().toLowerCase(),
    );
}

function usesSignatureBackground(rows) {
    return ['v22', 'v23'].includes(String(rows?.[0]?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '').trim().toLowerCase());
}

function assertSignatureBackgroundDocument(wrapper, rows) {
    const carriers = Array.from(wrapper?.querySelectorAll?.('td.rt-sign-cell') || []);
    const carrier = carriers[0];
    const content = carrier?.firstElementChild;
    const enabled = carrier?.getAttribute('data-rt-signature-background');
    if (!usesSignatureBackground(rows) || rows.length !== 2 || rows.some((row) => row.tagName !== 'TR')
        || carriers.length !== 1 || carrier?.parentElement !== rows[0]
        || carrier.children.length !== 1 || content?.tagName !== 'TABLE'
        || elementClassNames(content).join(' ') !== 'rt-sign-content-frame'
        || !['0', '1'].includes(enabled)
        || wrapper.querySelectorAll('.rt-sign-stage,.rt-sign-train-layer,.rt-sign-train,.rt-sign-train-frame,.rt-sign-train-slot,[data-rt-train],[data-rt-train-background],[data-rt-layer-train]').length !== 0) {
        throw new Error('V22 benoetigt einen optionalen Hintergrund und einen normalen Inhaltsrahmen ohne Zug-Layer.');
    }
    const attributes = Object.fromEntries(['desktop', 'tablet', 'mobile'].map((breakpoint) => [breakpoint, carrier.getAttribute(`data-rt-bg-${breakpoint}`)]));
    if (Object.values(attributes).some((value) => !MAIL_SIGNATURE_BACKGROUND_SIZES.includes(value))) {
        throw new Error('Die Signatur besitzt keine gueltigen Hintergrundgroessen fuer alle drei Umbrueche.');
    }
    const desktopFit = carrier.getAttribute('data-rt-bg-desktop-fit');
    if (desktopFit !== null && desktopFit !== 'contain') {
        throw new Error('Die Desktop-Einpassung des Signaturhintergrunds ist ungueltig.');
    }
    if ([...wrapper.querySelectorAll('[data-rt-bg-desktop-fit]')].some((element) => element !== carrier)) {
        throw new Error('Die Desktop-Einpassung ist nur am gebundenen Signaturhintergrund erlaubt.');
    }
    assertInlineStyles(carrier, {
        'background-image': enabled === '1' ? "url('{{TRAIN_SRC}}')" : 'none',
        'background-size': desktopFit === 'contain' ? 'contain' : `${attributes.desktop}% auto`,
        'background-position': desktopFit === 'contain' ? 'left bottom' : '65% bottom',
        'background-repeat': 'no-repeat',
        'background-color': '{{SIGNATURE_BG}}',
    }, 'Der Signatur-Hintergrund');
    if (carrier.hasAttribute('background')
        || (wrapper.outerHTML.split('{{TRAIN_SRC}}').length - 1) !== (enabled === '1' ? 1 : 0)) {
        throw new Error('Die optionale Hintergrundquelle der Signatur ist nicht eindeutig.');
    }
    assertExactElementAttributes(content, ['class', 'role', 'width', 'border', 'cellspacing', 'cellpadding', 'style'], 'Der V22-Inhaltsrahmen');
    if (content.getAttribute('role') !== 'presentation' || content.getAttribute('width') !== '100%'
        || ['border', 'cellspacing', 'cellpadding'].some((attribute) => content.getAttribute(attribute) !== '0')) {
        throw new Error('Der V22-Inhaltsrahmen besitzt ungueltige Tabellenattribute.');
    }
    assertInlineStyles(content, { width: '100%', 'border-collapse': 'collapse' }, 'Der V22-Inhaltsrahmen', { exact: true });
    wrapper.querySelectorAll('[style]').forEach((element) => {
        const style = String(element.getAttribute('style') || '');
        if (['position', 'z-index', 'transform', 'float'].some((property) => inlineStyleDeclaration(style, property) !== null)
            || ['margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right'].some((property) => /-\s*(?:\d|\.)/.test(inlineStyleDeclaration(style, property) || ''))
            || /^(?:inline-)?(?:flex|grid)$/i.test(inlineStyleDeclaration(style, 'display') || '')) {
            throw new Error('Der V22-Inhalt muss ohne positionierte oder negative Ueberlappung im normalen E-Mail-Fluss bleiben.');
        }
        if (element !== carrier && inlineStyleDeclaration(style, 'background-image') !== null
            || /(?:url|gradient|image-set)\s*\(/i.test(inlineStyleDeclaration(style, 'background') || '')) {
            throw new Error('V22 erlaubt das Zugbild nur im gebundenen Hintergrund.');
        }
        if (element.tagName !== 'IMG' && ['height', 'min-height', 'max-height'].some((property) => {
            const value = inlineStyleDeclaration(style, property);
            return value !== null && !['auto', 'none'].includes(value.toLowerCase());
        })) throw new Error('Der V22-Inhalt darf keine feste Buehnenhoehe besitzen.');
    });
    return { carrier, contentTable: content, background: true };
}

function elementUsesAspectSafeSignatureTrain(element) {
    return MAIL_SIGNATURE_ASPECT_SAFE_ARTIFACTS.includes(
        String(element?.closest?.(`[${MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE}]`)
            ?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '')
            .trim()
            .toLowerCase(),
    );
}

function elementUsesForwardSafeSignatureTrain(element) {
    return MAIL_SIGNATURE_FORWARD_SAFE_ARTIFACTS.includes(
        String(element?.closest?.(`[${MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE}]`)
            ?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE) || '')
            .trim()
            .toLowerCase(),
    );
}

function assertCanonicalSignatureStage(structure, failOpenStage = false) {
    const { stage, contentTable } = structure;
    assertExactElementAttributes(stage, ['class', 'style'], 'Die Signatur-Buehne');
    assertInlineStyles(
        stage,
        failOpenStage
            ? {
                position: 'relative',
                height: 'auto',
                'min-height': MAIL_SIGNATURE_FIXED_HEIGHT,
                overflow: 'visible',
            }
            : {
                position: 'relative',
                height: MAIL_SIGNATURE_FIXED_HEIGHT,
                'max-height': MAIL_SIGNATURE_FIXED_HEIGHT,
                overflow: 'hidden',
            },
        'Die Signatur-Buehne',
        { exact: true },
    );

    if (elementClassNames(contentTable).join(' ') !== 'rt-sign-content-frame'
        || contentTable.getAttribute('height') !== MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE) {
        throw new Error('Der Signatur-Inhaltsrahmen benoetigt die feste Pixelhoehe von 200px.');
    }
    assertInlineStyles(
        contentTable,
        {
            ...(failOpenStage ? { position: 'relative', 'z-index': '1' } : {}),
            width: '100%',
            height: MAIL_SIGNATURE_FIXED_HEIGHT,
            'border-collapse': 'collapse',
        },
        'Der Signatur-Inhaltsrahmen',
        { exact: true },
    );
}

function applyCanonicalSignatureStageGeometry(structure, failOpenStage = false) {
    structure.stage.setAttribute(
        'style',
        failOpenStage
            ? `position:relative;height:auto;min-height:${MAIL_SIGNATURE_FIXED_HEIGHT};overflow:visible;`
            : `position:relative;height:${MAIL_SIGNATURE_FIXED_HEIGHT};max-height:${MAIL_SIGNATURE_FIXED_HEIGHT};overflow:hidden;`,
    );
    structure.contentTable.setAttribute('class', 'rt-sign-content-frame');
    structure.contentTable.setAttribute('height', MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE);
    structure.contentTable.setAttribute(
        'style',
        `${failOpenStage ? 'position:relative;z-index:1;' : ''}width:100%;height:${MAIL_SIGNATURE_FIXED_HEIGHT};border-collapse:collapse;`,
    );
}

function assertSignatureBackground(carrier, expected, label) {
    if (carrier.hasAttribute('background')) {
        throw new Error('Der Zug-Carrier darf kein HTML-background-Attribut besitzen.');
    }
    assertInlineStyles(carrier, expected, label);
}

function assertOptionalSignatureBackground(carrier) {
    if (carrier.hasAttribute('background')) {
        throw new Error('Der Zug-Carrier darf kein HTML-background-Attribut besitzen.');
    }
    const style = String(carrier.getAttribute('style') || '');
    const properties = Object.keys(MAIL_SIGNATURE_BASE_BACKGROUND);
    const present = properties.filter((property) => inlineStyleDeclaration(style, property) !== null);
    if (present.length === 0) {
        if (inlineStyleDeclaration(style, 'background') !== null
            || style.includes('{{TRAIN_SRC}}')
            || style.includes('{{TRAIN_IDLE_SRC}}')) {
            throw new Error('Der optionale Signaturhintergrund darf keine Zugquelle oder Kurzform enthalten.');
        }

        return;
    }
    if (present.length !== properties.length) {
        throw new Error('Der optionale Signaturhintergrund besitzt unvollstaendige Background-Listen.');
    }
    assertSignatureBackground(carrier, MAIL_SIGNATURE_BASE_BACKGROUND, 'Der optionale Signaturhintergrund');
}

function signatureTrainGeometry(layer) {
    const alignment = String(layer?.getAttribute?.('data-rt-layer-align') || '').toLowerCase();
    const sizeName = String(layer?.getAttribute?.('data-rt-layer-size') || '');
    const mobileCrop = String(layer?.getAttribute?.('data-rt-layer-mobile') || '').toLowerCase();
    const size = MAIL_SIGNATURE_TRAIN_SIZES[sizeName];
    if (!['left', 'center', 'right'].includes(alignment)
        || !size
        || !['left', 'center', 'train', 'stop65', 'stop60', 'right'].includes(mobileCrop)) {
        throw new Error('Der Zug-Layer besitzt keine erlaubte mail-sichere Geometrie.');
    }
    const layerMargin = {
        left: '0 auto 0 0',
        center: '0 auto',
        right: '0 0 0 auto',
    }[alignment];
    const imageOffset = alignment === 'center'
        ? size.centerMargin
        : (alignment === 'right' ? size.rightMargin : '0');

    return {
        size,
        layerMargin,
        imageMargin: imageOffset === '0' ? '0' : `0 0 0 ${imageOffset}`,
    };
}

function assertLegacyMailSafeTrainOverlap(value) {
    const match = String(value || '').trim().toLowerCase()
        .match(/^-((?:\d+(?:\.\d+)?)|(?:\.\d+))(px|%)$/);
    if (!match) {
        throw new Error('Die Zug-Ueberlappung muss als negativer px- oder Prozentwert angegeben sein.');
    }
    const amount = Number.parseFloat(match[1]);
    const maximum = match[2] === '%' ? 100 : 1000;
    if (!Number.isFinite(amount) || amount <= 0 || amount > maximum) {
        throw new Error('Die Zug-Ueberlappung liegt ausserhalb des mail-sicheren Bereichs.');
    }

    return String(value).trim();
}

function assertCanonicalSignatureTrainOverlap(layer) {
    const value = inlineStyleDeclaration(String(layer?.getAttribute?.('style') || ''), 'margin-bottom');
    if (normalizedInlineStyleValue(value) !== normalizedInlineStyleValue(MAIL_SIGNATURE_TRAIN_OVERLAP)) {
        throw new Error('Der Zug-Layer benoetigt die feste Pixel-Ueberlappung von -200px.');
    }

    return MAIL_SIGNATURE_TRAIN_OVERLAP;
}

function assertLegacyFlowSignatureTrainLayer(layer, image) {
    if (!layer || !image
        || elementClassNames(layer).join(' ') !== 'rt-sign-train-layer'
        || elementClassNames(image).join(' ') !== 'rt-sign-train'
        || layer.getAttribute('data-rt-layer-train') !== ''
        || image.getAttribute('data-rt-train') !== ''
        || image.getAttribute('src') !== '{{TRAIN_SRC}}'
        || image.getAttribute('width') !== '720'
        || image.getAttribute('alt') !== ''
        || layer.children.length !== 1
        || layer.firstElementChild !== image) {
        throw new Error('Das Zugmotiv muss genau einmal im kanonischen IMG-Layer vorliegen.');
    }
    assertExactElementAttributes(layer, [
        'class',
        'data-rt-layer-train',
        'data-rt-layer-align',
        'data-rt-layer-size',
        'data-rt-layer-mobile',
        'style',
    ], 'Der Zug-Layer');
    assertExactElementAttributes(image, [
        'class',
        'data-rt-train',
        'src',
        'width',
        'alt',
        'style',
    ], 'Das Zugbild');

    const geometry = signatureTrainGeometry(layer);
    try {
        // Schema 24 besass absichtlich keine position-Angabe. Dieser exakte
        // Vertrag muss vor den kurzlebigen relative-/absolute-Varianten
        // erkannt werden, sonst meldet ein gueltiger Altstand irrefuehrend
        // eine fehlende kanonische Position und blockiert den Editorstart.
        assertInlineStyles(layer, {
            display: 'block',
            width: '100%',
            'max-width': '1815px',
            margin: geometry.layerMargin,
            overflow: 'hidden',
            'font-size': '0',
            'line-height': '0',
            'text-align': 'left',
        }, 'Der Zug-Layer', { exact: true, optional: ['margin-bottom'] });
    } catch (schema24Error) {
        try {
            assertInlineStyles(layer, {
                position: 'relative',
                left: '0',
                right: 'auto',
                top: 'auto',
                bottom: 'auto',
                width: '100%',
                'max-width': '1815px',
                margin: geometry.layerMargin,
                overflow: 'hidden',
                'z-index': '0',
                'font-size': '0',
                'line-height': '0',
                'text-align': 'left',
            }, 'Der Zug-Layer', { exact: true, optional: ['margin-bottom'] });
        } catch {
            throw schema24Error;
        }
    }
    assertInlineStyles(image, {
        position: 'static',
        left: 'auto',
        right: 'auto',
        bottom: 'auto',
        display: 'inline-block',
        width: geometry.size.width,
        'max-width': 'none',
        height: 'auto',
        margin: geometry.imageMargin,
        border: '0',
        outline: 'none',
        'text-decoration': 'none',
        'vertical-align': 'top',
        'mso-hide': 'all',
    }, 'Das Zugbild', { exact: true });
}

function assertLegacyAbsoluteSignatureTrainLayer(layer, image) {
    if (!layer || !image
        || elementClassNames(layer).join(' ') !== 'rt-sign-train-layer'
        || elementClassNames(image).join(' ') !== 'rt-sign-train'
        || layer.getAttribute('data-rt-layer-train') !== ''
        || image.getAttribute('data-rt-train') !== ''
        || image.getAttribute('src') !== '{{TRAIN_SRC}}'
        || image.getAttribute('width') !== '720'
        || image.getAttribute('alt') !== ''
        || layer.children.length !== 1
        || layer.firstElementChild !== image) {
        throw new Error('Das Zugmotiv muss genau einmal im kanonischen IMG-Layer vorliegen.');
    }
    assertExactElementAttributes(layer, [
        'class',
        'data-rt-layer-train',
        'data-rt-layer-align',
        'data-rt-layer-size',
        'data-rt-layer-mobile',
        'style',
    ], 'Der Zug-Layer');
    assertExactElementAttributes(image, [
        'class',
        'data-rt-train',
        'src',
        'width',
        'alt',
        'style',
    ], 'Das Zugbild');

    const geometry = signatureTrainGeometry(layer);
    assertInlineStyles(layer, {
        position: 'absolute',
        left: '0',
        right: 'auto',
        top: 'auto',
        bottom: '0',
        width: '100%',
        'max-width': '1815px',
        margin: '0',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
        'mso-hide': 'all',
    }, 'Der Zug-Layer', { exact: true });
    assertInlineStyles(image, {
        position: 'static',
        left: 'auto',
        right: 'auto',
        bottom: 'auto',
        display: 'inline-block',
        width: geometry.size.width,
        'max-width': 'none',
        height: 'auto',
        margin: geometry.imageMargin,
        border: '0',
        outline: 'none',
        'text-decoration': 'none',
        'vertical-align': 'top',
        'mso-hide': 'all',
    }, 'Das Zugbild', { exact: true });
}

function assertCanonicalSignatureTrainLayer(layer, image, failOpenStage = false) {
    const aspectSafeTrain = elementUsesAspectSafeSignatureTrain(image || layer);
    const forwardSafeTrain = elementUsesForwardSafeSignatureTrain(image || layer);
    const frame = layer?.firstElementChild;
    const frameBody = frame?.tBodies?.[0] || frame?.querySelector?.(':scope > tbody');
    const frameRows = Array.from(frameBody?.children || []);
    const slot = frameRows[0]?.firstElementChild;
    if (!layer || !image
        || elementClassNames(layer).join(' ') !== 'rt-sign-train-layer'
        || elementClassNames(image).join(' ') !== 'rt-sign-train'
        || layer.getAttribute('data-rt-layer-train') !== ''
        || image.getAttribute('data-rt-train') !== ''
        || image.getAttribute('src') !== '{{TRAIN_SRC}}'
        || image.getAttribute('width') !== '720'
        || (forwardSafeTrain || (failOpenStage && !aspectSafeTrain)
            ? image.getAttribute('height') !== MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT
            : image.hasAttribute('height'))
        || image.getAttribute('alt') !== ''
        || layer.children.length !== 1
        || frame?.tagName !== 'TABLE'
        || elementClassNames(frame).join(' ') !== 'rt-sign-train-frame'
        || frameRows.length !== 1
        || frameRows[0]?.tagName !== 'TR'
        || frameRows[0]?.children?.length !== 1
        || slot?.tagName !== 'TD'
        || elementClassNames(slot).join(' ') !== 'rt-sign-train-slot'
        || slot.children.length !== 1
        || slot.firstElementChild !== image) {
        throw new Error('Das Zugmotiv muss genau einmal im kanonischen IMG-Layer vorliegen.');
    }
    assertExactElementAttributes(layer, [
        'class',
        'data-rt-layer-train',
        'data-rt-layer-align',
        'data-rt-layer-size',
        'data-rt-layer-mobile',
        'style',
    ], 'Der Zug-Layer');
    assertExactElementAttributes(frame, [
        'class',
        'role',
        'width',
        'height',
        'border',
        'cellspacing',
        'cellpadding',
        'style',
    ], 'Der Zug-Pixelrahmen');
    assertExactElementAttributes(slot, [
        'class',
        'height',
        'valign',
        'style',
    ], 'Der Zug-Pixelslot');
    assertExactElementAttributes(image, [
        'class',
        'data-rt-train',
        'src',
        'width',
        ...(forwardSafeTrain || (failOpenStage && !aspectSafeTrain) ? ['height'] : []),
        'alt',
        'style',
    ], 'Das Zugbild');

    const geometry = signatureTrainGeometry(layer);
    assertInlineStyles(layer, forwardSafeTrain ? {
        position: 'absolute',
        'z-index': '0',
        left: '0',
        right: '0',
        top: 'auto',
        bottom: '0',
        display: 'block',
        width: '100%',
        height: '61px',
        'max-height': '61px',
        'max-width': '1815px',
        margin: '0',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    } : {
        ...(failOpenStage ? { position: 'relative', 'z-index': '0' } : {}),
        display: 'block',
        width: '100%',
        height: MAIL_SIGNATURE_FIXED_HEIGHT,
        'max-height': MAIL_SIGNATURE_FIXED_HEIGHT,
        'max-width': '1815px',
        margin: geometry.layerMargin,
        'margin-bottom': MAIL_SIGNATURE_TRAIN_OVERLAP,
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    }, 'Der Zug-Layer', { exact: true });
    if (!forwardSafeTrain) assertCanonicalSignatureTrainOverlap(layer);
    const trainFrameHeight = forwardSafeTrain ? '61' : MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE;
    const trainFrameCssHeight = forwardSafeTrain ? '61px' : MAIL_SIGNATURE_FIXED_HEIGHT;
    if (frame.getAttribute('role') !== 'presentation'
        || frame.getAttribute('width') !== '100%'
        || frame.getAttribute('height') !== trainFrameHeight
        || frame.getAttribute('border') !== '0'
        || frame.getAttribute('cellspacing') !== '0'
        || frame.getAttribute('cellpadding') !== '0') {
        throw new Error('Der Zug-Pixelrahmen besitzt fremde oder fehlende Attribute.');
    }
    assertInlineStyles(frame, {
        width: '100%',
        height: trainFrameCssHeight,
        'border-collapse': 'collapse',
    }, 'Der Zug-Pixelrahmen', { exact: true });
    if (slot.getAttribute('height') !== trainFrameHeight
        || slot.getAttribute('valign') !== 'bottom') {
        throw new Error('Der Zug-Pixelslot ist nicht pixelgenau unten ausgerichtet.');
    }
    assertInlineStyles(slot, {
        height: trainFrameCssHeight,
        padding: '0',
        'text-align': 'left',
        'vertical-align': 'bottom',
        'font-size': '0',
        'line-height': '0',
    }, 'Der Zug-Pixelslot', { exact: true });
    assertInlineStyles(image, forwardSafeTrain ? {
        position: 'static',
        left: 'auto',
        right: 'auto',
        bottom: 'auto',
        display: 'block',
        width: '720px',
        'max-width': '100%',
        height: 'auto',
        margin: '0',
        border: '0',
        outline: 'none',
        'text-decoration': 'none',
        'vertical-align': 'bottom',
        'mso-hide': 'all',
    } : {
        position: 'static',
        left: 'auto',
        right: 'auto',
        bottom: 'auto',
        display: 'inline-block',
        width: geometry.size.width,
        'max-width': 'none',
        height: 'auto',
        margin: geometry.imageMargin,
        border: '0',
        outline: 'none',
        'text-decoration': 'none',
        'vertical-align': 'bottom',
        'mso-hide': 'all',
    }, 'Das Zugbild', { exact: true });

    return { frame, slot };
}

function assertCanonicalSignatureTrainImage(
    wrapper,
    rows,
    failOpenStage = usesFailOpenSignatureStage(rows),
) {
    const structure = assertSignatureBaseStructure(wrapper, rows);
    const layers = Array.from(wrapper.querySelectorAll(
        'div[data-rt-layer-train], div.rt-sign-train-layer',
    ));
    const images = Array.from(wrapper.querySelectorAll(
        'img[data-rt-train], img.rt-sign-train',
    ));
    const layer = layers[0];
    const image = images[0];
    if (structure.stageElements.length !== 2
        || layers.length !== 1 || images.length !== 1
        || layer?.parentElement !== structure.stage
        || structure.stageElements[0] !== layer
        || structure.stage.firstElementChild !== layer
        || structure.stageElements[1] !== structure.contentTable
        || wrapper.querySelectorAll('[data-rt-train-background]').length !== 0
        || (wrapper.outerHTML.split('{{TRAIN_SRC}}').length - 1) !== 1) {
        throw new Error('Die Signatur benoetigt genau einen IMG-Zug direkt vor ihrem Inhaltsblock.');
    }
    assertOptionalSignatureBackground(structure.carrier);
    assertCanonicalSignatureTrainLayer(layer, image, failOpenStage);
    assertCanonicalSignatureStage(structure, failOpenStage);

    return {
        ...structure,
        layer,
        image,
        overlap: usesForwardSafeSignatureTrain(rows) ? null : MAIL_SIGNATURE_TRAIN_OVERLAP,
    };
}

function assertFlowSafeSignatureStyles(wrapper) {
    Array.from(wrapper?.querySelectorAll?.('[style]') || []).forEach((element) => {
        const style = String(element.getAttribute('style') || '');
        if (['position', 'z-index', 'transform', 'float', 'background-image']
            .some((property) => inlineStyleDeclaration(style, property) !== null)) {
            throw new Error('V21 darf keine positionierten oder bildbasierten Ebenen enthalten.');
        }
        const background = inlineStyleDeclaration(style, 'background');
        if (background !== null && /(?:url|gradient|image-set)\s*\(/i.test(background)) {
            throw new Error('V21 darf kein CSS-Hintergrundbild enthalten.');
        }
        if (['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left']
            .some((property) => /-\s*(?:\d|\.)/.test(inlineStyleDeclaration(style, property) || ''))) {
            throw new Error('V21 darf keine negative Zug-Ueberlappung enthalten.');
        }
        if (/^(?:inline-)?(?:flex|grid)$/i.test(inlineStyleDeclaration(style, 'display') || '')
            || ['height', 'min-height', 'max-height']
                .some((property) => /^\s*200px\s*$/i.test(inlineStyleDeclaration(style, property) || ''))) {
            throw new Error('V21 darf keine Flex-/Grid- oder feste 200-px-Buehne enthalten.');
        }
    });
}

function assertFlowSafeSignatureTrainImage(wrapper, rows) {
    if (!usesFlowSafeSignatureTrain(rows)) {
        throw new Error('Die Signatur besitzt keinen V21-Flowvertrag.');
    }

    const fluid = String(rows[0].getAttribute(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE)).trim().toLowerCase() === 'v25';
    const structure = assertSignatureBaseStructure(wrapper, rows);
    const layers = Array.from(wrapper.querySelectorAll(
        'div[data-rt-layer-train], div.rt-sign-train-layer',
    ));
    const images = Array.from(wrapper.querySelectorAll(
        'img[data-rt-train], img.rt-sign-train',
    ));
    const layer = layers[0];
    const image = images[0];
    const frame = layer?.firstElementChild;
    const frameChildren = Array.from(frame?.children || []);
    const frameBody = frameChildren.length === 1 && frameChildren[0]?.tagName === 'TBODY'
        ? frameChildren[0]
        : null;
    const frameRows = frameBody ? Array.from(frameBody.children || []) : frameChildren;
    const slot = frameRows[0]?.firstElementChild;

    if (structure.stageElements.length !== 2
        || layers.length !== 1 || images.length !== 1
        || structure.stageElements[0] !== structure.contentTable
        || structure.stage.firstElementChild !== structure.contentTable
        || structure.stageElements[1] !== layer
        || structure.stage.lastElementChild !== layer
        || layer?.parentElement !== structure.stage
        || layer?.children?.length !== 1
        || frame?.tagName !== 'TABLE'
        || frameRows.length !== 1
        || frameRows[0]?.tagName !== 'TR'
        || frameRows[0]?.children?.length !== 1
        || slot?.tagName !== 'TD'
        || slot?.children?.length !== 1
        || slot?.firstElementChild !== image
        || wrapper.querySelectorAll('[data-rt-train-background]').length !== 0
        || (wrapper.outerHTML.split('{{TRAIN_SRC}}').length - 1) !== 1) {
        throw new Error('V21 muss Inhalt und Zug genau einmal in normaler Flow-Reihenfolge enthalten.');
    }

    if (structure.carrier.hasAttribute('background')) {
        throw new Error('V21 darf kein HTML-background-Attribut besitzen.');
    }
    const carrierStyle = String(structure.carrier.getAttribute('style') || '');
    if (inlineStyleDeclaration(carrierStyle, 'background-image') !== null
        || /(?:url|gradient|image-set)\s*\(/i.test(inlineStyleDeclaration(carrierStyle, 'background') || '')
        || carrierStyle.includes('{{TRAIN_SRC}}')
        || carrierStyle.includes('{{TRAIN_IDLE_SRC}}')) {
        throw new Error('V21 darf Bilder ausschliesslich als normale IMG-Elemente einbinden.');
    }

    assertExactElementAttributes(structure.stage, ['class', 'style'], 'Der V21-Signaturfluss');
    if (elementClassNames(structure.stage).join(' ') !== 'rt-sign-stage') {
        throw new Error('Der V21-Signaturfluss ist nicht eindeutig markiert.');
    }
    assertInlineStyles(structure.stage, {
        display: 'block',
        width: '100%',
        overflow: 'visible',
    }, 'Der V21-Signaturfluss', { exact: true });

    if (elementClassNames(structure.contentTable).join(' ') !== 'rt-sign-content-frame') {
        throw new Error('Der V21-Inhaltsrahmen ist nicht eindeutig markiert.');
    }
    assertExactElementAttributes(structure.contentTable, [
        'class',
        'role',
        'width',
        'border',
        'cellspacing',
        'cellpadding',
        'style',
    ], 'Der V21-Inhaltsrahmen');
    if (structure.contentTable.getAttribute('role') !== 'presentation'
        || structure.contentTable.getAttribute('width') !== '100%'
        || structure.contentTable.getAttribute('border') !== '0'
        || structure.contentTable.getAttribute('cellspacing') !== '0'
        || structure.contentTable.getAttribute('cellpadding') !== '0') {
        throw new Error('Der V21-Inhaltsrahmen besitzt fremde Tabellenattribute.');
    }
    assertInlineStyles(structure.contentTable, {
        width: '100%',
        'border-collapse': 'collapse',
    }, 'Der V21-Inhaltsrahmen', { exact: true });

    if (elementClassNames(layer).join(' ') !== 'rt-sign-train-layer'
        || layer.getAttribute('data-rt-layer-train') !== ''
        || layer.getAttribute('data-rt-layer-align') !== 'left'
        || layer.getAttribute('data-rt-layer-size') !== '100'
        || layer.getAttribute('data-rt-layer-mobile') !== 'left') {
        throw new Error('Die V21-Zugzeile muss linksbuendig und proportionssicher bleiben.');
    }
    assertExactElementAttributes(layer, [
        'class',
        'data-rt-layer-train',
        'data-rt-layer-align',
        'data-rt-layer-size',
        'data-rt-layer-mobile',
        'style',
    ], 'Die V21-Zugzeile');
    assertInlineStyles(layer, {
        display: 'block',
        width: '100%',
        'max-width': fluid ? 'none' : '720px',
        margin: '0 auto 0 0',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    }, 'Die V21-Zugzeile', { exact: true });

    if (elementClassNames(frame).join(' ') !== 'rt-sign-train-frame') {
        throw new Error('Der V21-Zugrahmen ist nicht eindeutig markiert.');
    }
    assertExactElementAttributes(frame, [
        'class',
        'role',
        'width',
        ...(fluid ? [] : ['height']),
        'border',
        'cellspacing',
        'cellpadding',
        'style',
    ], 'Der V21-Zugrahmen');
    if (frame.getAttribute('role') !== 'presentation'
        || frame.getAttribute('width') !== '100%'
        || frame.getAttribute('height') !== (fluid ? null : MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT)
        || frame.getAttribute('border') !== '0'
        || frame.getAttribute('cellspacing') !== '0'
        || frame.getAttribute('cellpadding') !== '0') {
        throw new Error('Der V21-Zugrahmen besitzt fremde Tabellenattribute.');
    }
    assertInlineStyles(frame, {
        width: '100%',
        ...(fluid ? {} : { height: '61px' }),
        'border-collapse': 'collapse',
    }, 'Der V21-Zugrahmen', { exact: true });

    if (elementClassNames(slot).join(' ') !== 'rt-sign-train-slot'
        || slot.getAttribute('height') !== (fluid ? null : MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT)
        || slot.getAttribute('valign') !== 'bottom') {
        throw new Error('Der V21-Zugslot muss pixelgenau unten ausgerichtet sein.');
    }
    assertExactElementAttributes(slot, ['class', ...(fluid ? [] : ['height']), 'valign', 'style'], 'Der Flow-Zugslot');
    assertInlineStyles(slot, {
        ...(fluid ? {} : { height: '61px' }),
        padding: '0',
        'text-align': 'left',
        'vertical-align': 'bottom',
        'font-size': '0',
        'line-height': '0',
    }, 'Der V21-Zugslot', { exact: true });

    if (elementClassNames(image).join(' ') !== 'rt-sign-train'
        || image.getAttribute('data-rt-train') !== ''
        || image.getAttribute('src') !== '{{TRAIN_SRC}}'
        || image.getAttribute('width') !== '720'
        || image.getAttribute('height') !== MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT
        || image.getAttribute('alt') !== '') {
        throw new Error('Das V21-Zugbild besitzt fremde oder fehlende Attribute.');
    }
    assertExactElementAttributes(image, [
        'class',
        'data-rt-train',
        'src',
        'width',
        'height',
        'alt',
        'style',
    ], 'Das V21-Zugbild');
    assertInlineStyles(image, {
        display: 'block',
        width: '100%',
        'max-width': fluid ? 'none' : '720px',
        height: 'auto',
        margin: '0',
        border: '0',
        outline: 'none',
        'text-decoration': 'none',
        'vertical-align': 'bottom',
        'mso-hide': 'all',
    }, 'Das V21-Zugbild', { exact: true });
    assertFlowSafeSignatureStyles(wrapper);

    return {
        ...structure,
        layer,
        frame,
        slot,
        image,
        overlap: null,
    };
}

function createCanonicalSignatureTrainLayer(
    document_,
    failOpenStage = false,
    aspectSafeTrain = false,
    forwardSafeTrain = false,
) {
    const layer = document_.createElement('div');
    layer.setAttribute('class', 'rt-sign-train-layer');
    layer.setAttribute('data-rt-layer-train', '');
    layer.setAttribute('data-rt-layer-align', 'center');
    layer.setAttribute('data-rt-layer-size', '125');
    layer.setAttribute('data-rt-layer-mobile', 'train');
    layer.setAttribute('style', forwardSafeTrain
        ? 'position:absolute;z-index:0;left:0;right:0;top:auto;bottom:0;display:block;width:100%;height:61px;max-height:61px;max-width:1815px;margin:0;overflow:hidden;font-size:0;line-height:0;text-align:left;'
        : `${failOpenStage ? 'position:relative;z-index:0;' : ''}display:block;width:100%;height:${MAIL_SIGNATURE_FIXED_HEIGHT};max-height:${MAIL_SIGNATURE_FIXED_HEIGHT};max-width:1815px;margin:0 auto;margin-bottom:${MAIL_SIGNATURE_TRAIN_OVERLAP};overflow:hidden;font-size:0;line-height:0;text-align:left;`);

    const frame = document_.createElement('table');
    frame.setAttribute('class', 'rt-sign-train-frame');
    frame.setAttribute('role', 'presentation');
    frame.setAttribute('width', '100%');
    frame.setAttribute('height', forwardSafeTrain ? MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT : MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE);
    frame.setAttribute('border', '0');
    frame.setAttribute('cellspacing', '0');
    frame.setAttribute('cellpadding', '0');
    frame.setAttribute('style', `width:100%;height:${forwardSafeTrain ? '61px' : MAIL_SIGNATURE_FIXED_HEIGHT};border-collapse:collapse;`);
    const body = document_.createElement('tbody');
    const row = document_.createElement('tr');
    const slot = document_.createElement('td');
    slot.setAttribute('class', 'rt-sign-train-slot');
    slot.setAttribute('height', forwardSafeTrain ? MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT : MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE);
    slot.setAttribute('valign', 'bottom');
    slot.setAttribute('style', `height:${forwardSafeTrain ? '61px' : MAIL_SIGNATURE_FIXED_HEIGHT};padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;`);

    const image = document_.createElement('img');
    image.setAttribute('class', 'rt-sign-train');
    image.setAttribute('data-rt-train', '');
    image.setAttribute('src', '{{TRAIN_SRC}}');
    image.setAttribute('width', '720');
    if (forwardSafeTrain || (failOpenStage && !aspectSafeTrain)) image.setAttribute('height', MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT);
    image.setAttribute('alt', '');
    image.setAttribute('style', forwardSafeTrain
        ? 'position:static;left:auto;right:auto;bottom:auto;display:block;width:720px;max-width:100%;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;'
        : 'position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:125%;max-width:none;height:auto;margin:0 0 0 -12.5%;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;');
    slot.append(image);
    row.append(slot);
    body.append(row);
    frame.append(body);
    layer.append(frame);

    return layer;
}

function applyCanonicalSignatureTrainGeometry(layer, image, failOpenStage = false) {
    const geometry = signatureTrainGeometry(layer);
    const aspectSafeTrain = elementUsesAspectSafeSignatureTrain(layer);
    const forwardSafeTrain = elementUsesForwardSafeSignatureTrain(layer);
    const canonical = createCanonicalSignatureTrainLayer(
        layer.ownerDocument,
        failOpenStage,
        aspectSafeTrain,
        forwardSafeTrain,
    );
    const frame = canonical.firstElementChild;
    const slot = frame?.querySelector('td.rt-sign-train-slot');
    slot?.replaceChildren(image);
    layer.replaceChildren(frame);
    layer.setAttribute('style', forwardSafeTrain
        ? canonical.getAttribute('style')
        : `${failOpenStage ? 'position:relative;z-index:0;' : ''}display:block;width:100%;height:${MAIL_SIGNATURE_FIXED_HEIGHT};max-height:${MAIL_SIGNATURE_FIXED_HEIGHT};max-width:1815px;margin:${geometry.layerMargin};margin-bottom:${MAIL_SIGNATURE_TRAIN_OVERLAP};overflow:hidden;font-size:0;line-height:0;text-align:left;`);
    image.setAttribute('width', '720');
    if (forwardSafeTrain || (failOpenStage && !aspectSafeTrain)) image.setAttribute('height', MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT);
    else image.removeAttribute('height');
    image.setAttribute('style', forwardSafeTrain
        ? 'position:static;left:auto;right:auto;bottom:auto;display:block;width:720px;max-width:100%;height:auto;margin:0;border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;'
        : `position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:${geometry.size.width};max-width:none;height:auto;margin:${geometry.imageMargin};border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;`);
}

function projectLegacySignatureTrainLayer(wrapper, rows, failOpenStage = false) {
    const structure = assertSignatureBaseStructure(wrapper, rows);
    const layers = Array.from(wrapper.querySelectorAll(
        'div[data-rt-layer-train], div.rt-sign-train-layer',
    ));
    const images = Array.from(wrapper.querySelectorAll(
        'img[data-rt-train], img.rt-sign-train',
    ));
    const layer = layers[0];
    const image = images[0];
    const trainFirst = structure.stageElements[0] === layer
        && structure.stageElements[1] === structure.contentTable;
    const contentFirst = structure.stageElements[0] === structure.contentTable
        && structure.stageElements[1] === layer;
    if (structure.stageElements.length !== 2
        || layers.length !== 1 || images.length !== 1
        || (!trainFirst && !contentFirst)) {
        throw new Error('Die alte Signatur besitzt keine eindeutige Zug-/Inhaltsreihenfolge.');
    }
    assertOptionalSignatureBackground(structure.carrier);
    try {
        assertLegacyFlowSignatureTrainLayer(layer, image);
    } catch (flowError) {
        try {
            assertLegacyAbsoluteSignatureTrainLayer(layer, image);
        } catch {
            throw flowError;
        }
    }

    const legacyOverlap = inlineStyleDeclaration(String(layer.getAttribute('style') || ''), 'margin-bottom')
        || inlineStyleDeclaration(String(structure.contentTable.getAttribute('style') || ''), 'margin-bottom');
    if (legacyOverlap) assertLegacyMailSafeTrainOverlap(legacyOverlap);
    if (contentFirst) structure.stage.insertBefore(layer, structure.contentTable);
    applyCanonicalSignatureTrainGeometry(layer, image, failOpenStage);
    applyCanonicalSignatureStageGeometry(structure, failOpenStage);

    return assertCanonicalSignatureTrainImage(wrapper, rows, failOpenStage);
}

function projectPreviousSignatureTrainLayerToForwardSafe(wrapper, rows) {
    const artifactRow = rows?.[0];
    const artifactVersion = artifactRow?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE);
    if (!artifactRow || !usesForwardSafeSignatureTrain(rows)) {
        throw new Error('Die Signatur besitzt keinen weiterleitungssicheren Zugvertrag.');
    }

    let previous = null;
    try {
        // V19 folgt unmittelbar auf den proportionalen V18-Vertrag. Fuer die
        // einmalige Projektion wird deshalb nur die Vertragsauswahl umgeschaltet;
        // das DOM selbst bleibt bis zur erfolgreichen kanonischen Projektion
        // unveraendert und der V19-Marker wird in jedem Fall wiederhergestellt.
        artifactRow.setAttribute(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE, 'v18');
        for (const failOpenStage of [true, false]) {
            try {
                previous = assertCanonicalSignatureTrainImage(wrapper, rows, failOpenStage);
                break;
            } catch {
                // V18 existiert sowohl mit bereits migrierter fail-open als auch
                // mit aelterer fester Buehne; beide streng bekannten Formen sind
                // fuer diese eine Vorwaertsprojektion erlaubt.
            }
        }
    } finally {
        artifactRow.setAttribute(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE, artifactVersion || 'v19');
    }

    if (!previous) {
        throw new Error('Die V19-Signatur laesst sich nicht aus dem V18-Zugvertrag projizieren.');
    }

    applyCanonicalSignatureTrainGeometry(previous.layer, previous.image, true);
    applyCanonicalSignatureStageGeometry(previous, true);

    return assertCanonicalSignatureTrainImage(wrapper, rows, true);
}

/**
 * V20 nimmt bewusst die bewaehrte V18-Geometrie wieder auf. Ein bereits als
 * V20 markierter Import darf deshalb entweder diesen Vertrag direkt tragen
 * oder aus dem streng gueltigen V19-Zwischenstand stammen. Nur die exakt
 * bekannte V19-Form wird zur 200-px/-200-px-Ueberlagerung zurueckprojiziert;
 * frei manipulierte Layer bleiben weiterhin gesperrt.
 */
function projectPreviousSignatureTrainLayerToV18Geometry(wrapper, rows) {
    const artifactRow = rows?.[0];
    const artifactVersion = artifactRow?.getAttribute?.(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE);
    if (!artifactRow || !usesV20SignatureTrain(rows)) {
        throw new Error('Die Signatur besitzt keinen V20-Zugvertrag.');
    }

    let previous = null;
    try {
        artifactRow.setAttribute(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE, 'v19');
        previous = assertCanonicalSignatureTrainImage(wrapper, rows, true);
    } finally {
        artifactRow.setAttribute(MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE, artifactVersion || 'v20');
    }

    applyCanonicalSignatureTrainGeometry(previous.layer, previous.image, true);
    applyCanonicalSignatureStageGeometry(previous, true);

    return assertCanonicalSignatureTrainImage(wrapper, rows, true);
}

function projectSignatureTrainImage(wrapper, rows, project) {
    const declaredSchema = project?.railtime?.schema;
    const failOpenStage = usesFailOpenSignatureStage(rows);
    const aspectSafeTrain = usesAspectSafeSignatureTrain(rows);
    if (Number.isInteger(declaredSchema)
        && ![19, 20, 21, 22, 23, 24, 25, 26, 27, 28, MAIL_SIGNATURE_SCHEMA].includes(declaredSchema)) {
        throw new Error('Die Signatur besitzt einen nicht unterstuetzten Zugvertrag.');
    }

    if (usesSignatureBackground(rows)) {
        const canonical = assertSignatureBackgroundDocument(wrapper, rows);
        project.railtime = { ...(project.railtime || {}), document: 'signature', schema: MAIL_SIGNATURE_SCHEMA };
        return canonical;
    }

    if (usesFlowSafeSignatureTrain(rows)) {
        const canonical = assertFlowSafeSignatureTrainImage(wrapper, rows);
        project.railtime = {
            ...(project.railtime && typeof project.railtime === 'object' ? project.railtime : {}),
            document: 'signature',
            schema: MAIL_SIGNATURE_SCHEMA,
        };

        return canonical;
    }

    const backgroundCarriers = Array.from(
        wrapper?.querySelectorAll?.('td.rt-sign-cell[data-rt-train-background]') || [],
    );
    if (backgroundCarriers.length > 0) {
        if (backgroundCarriers.length !== 1
            || backgroundCarriers[0].getAttribute('data-rt-train-background') !== '1') {
            throw new Error('Die Signatur besitzt keinen eindeutigen Schema-20-Zughintergrund.');
        }
        const structure = assertSignatureBaseStructure(wrapper, rows);
        if (backgroundCarriers[0] !== structure.carrier
            || structure.stageElements.length !== 1
            || wrapper.querySelectorAll('img[data-rt-train], img.rt-sign-train, div[data-rt-layer-train], div.rt-sign-train-layer').length !== 0
            || (wrapper.outerHTML.split('{{TRAIN_SRC}}').length - 1) !== 1) {
            throw new Error('Die Signatur mischt den Schema-20-Zughintergrund mit einem Zugbild.');
        }
        assertSignatureBackground(structure.carrier, MAIL_SIGNATURE_SCHEMA_20_BACKGROUND, 'Der Schema-20-Zug-Carrier');

        let projectedStyle = String(structure.carrier.getAttribute('style') || '');
        Object.entries(MAIL_SIGNATURE_BASE_BACKGROUND).forEach(([property, value]) => {
            projectedStyle = replaceInlineStyleDeclaration(projectedStyle, property, value);
        });
        structure.carrier.setAttribute('style', projectedStyle);
        structure.carrier.removeAttribute('data-rt-train-background');
        structure.stage.insertBefore(
            createCanonicalSignatureTrainLayer(
                wrapper.ownerDocument,
                failOpenStage,
                aspectSafeTrain,
                usesForwardSafeSignatureTrain(rows),
            ),
            structure.contentTable,
        );
        applyCanonicalSignatureStageGeometry(structure, failOpenStage);
    } else {
        let canonicalError = null;
        try {
            assertCanonicalSignatureTrainImage(wrapper, rows, failOpenStage);
        } catch (error) {
            canonicalError = error;
            let migratedFixedFailOpenStage = false;
            if (failOpenStage && usesV20SignatureTrain(rows)) {
                try {
                    projectPreviousSignatureTrainLayerToV18Geometry(wrapper, rows);
                    migratedFixedFailOpenStage = true;
                } catch {
                    // Nur der streng kanonische V19-Zwischenstand darf ohne
                    // Benutzerentscheidung nach V20 zurueckprojiziert werden.
                }
            }
            if (!migratedFixedFailOpenStage && failOpenStage && usesForwardSafeSignatureTrain(rows)) {
                try {
                    projectPreviousSignatureTrainLayerToForwardSafe(wrapper, rows);
                    migratedFixedFailOpenStage = true;
                } catch {
                    // Unbekannte Altformen laufen weiterhin durch den bestehenden
                    // streng validierten Legacy-Pfad.
                }
            }
            if (failOpenStage) {
                if (!migratedFixedFailOpenStage) {
                    try {
                        const fixedStage = assertCanonicalSignatureTrainImage(wrapper, rows, false);
                        applyCanonicalSignatureTrainGeometry(fixedStage.layer, fixedStage.image, true);
                        applyCanonicalSignatureStageGeometry(fixedStage, true);
                        assertCanonicalSignatureTrainImage(wrapper, rows, true);
                        migratedFixedFailOpenStage = true;
                    } catch {
                        // Andere bekannte Altformen laufen durch denselben streng
                        // validierten Projektionspfad wie bisher.
                    }
                }
            }
            if (!migratedFixedFailOpenStage) {
                try {
                    projectLegacySignatureTrainLayer(wrapper, rows, failOpenStage);
                } catch (legacyError) {
                    // Bei als aktuell deklarierten Quellen beschreibt die erste
                    // Meldung den tatsaechlich verletzten aktuellen Vertrag. Eine
                    // nachgeschaltete Legacy-Pruefung darf sie nicht wieder durch
                    // die irrefuehrende position-Meldung verdecken.
                    if (declaredSchema === MAIL_SIGNATURE_SCHEMA && canonicalError instanceof Error) {
                        throw canonicalError;
                    }

                    throw legacyError;
                }
            }
        }
    }

    const canonical = assertCanonicalSignatureTrainImage(wrapper, rows);
    project.railtime = {
        ...(project.railtime && typeof project.railtime === 'object' ? project.railtime : {}),
        document: 'signature',
        schema: MAIL_SIGNATURE_SCHEMA,
    };

    return canonical;
}

export function projectForMailDocument(draft, parseCss = () => [], options = {}) {
    const project = normalizeMailProject(structuredClone(draft?.builderData || {}));

    if (draft?.css && (!Array.isArray(project.styles) || project.styles.length === 0)) {
        project.styles = parseMailCssProjectStyles(draft.css, parseCss);
    }

    const page = project.pages?.[0];

    if (options.kind === 'template') {
        if (!page || typeof page.component !== 'string') {
            throw new Error('Der Vorlagen-Editor benötigt ein vollständiges HTML-Dokument.');
        }

        // `html` ist die serverseitig gehaertete Quelle. Aeltere
        // builder_data-Staende koennen noch nur den Body oder eine bereits
        // von GrapesJS normalisierte Kopie enthalten; sie duerfen die
        // vollstaendige autoritative Dokumentfassung nicht ueberstimmen.
        if (typeof draft?.html === 'string' && draft.html.trim() !== '') {
            page.component = draft.html;
        }

        const placeholderCount = page.component.split(SIGNATURE_PLACEHOLDER).length - 1;
        if (placeholderCount !== 1) {
            throw new Error('Die Mailvorlage benötigt genau einen gebundenen Signaturblock.');
        }

        const projectedTemplate = projectTemplateApplicationSlot(page.component);
        const parsed = domParserFor(options.environment || globalThis)
            .parseFromString(
                projectedTemplate.replace(SIGNATURE_PLACEHOLDER, MAIL_TEMPLATE_SIGNATURE_PREVIEW),
                'text/html',
            );
        if (!parsed.head || !parsed.body || !parsed.documentElement) {
            throw new Error('Die Mailvorlage besitzt keine vollständige HTML-Struktur.');
        }

        // GrapesJS editiert ausschließlich den Body. Head und Dokumenthülle
        // werden beim Speichern aus der letzten kanonischen Serverfassung
        // rekonstruiert und können dadurch nicht still verloren gehen.
        markMailPreviewImageTokens(parsed.body);
        markImportedInlineStyles(parsed.body);
        page.component = parsed.body.innerHTML;
        delete page.frames;
    }

    if (options.kind === 'signature') {
        if (!page || typeof page.component !== 'string') {
            throw new Error('Der Signatur-Editor benötigt ein kanonisches HTML-Fragment.');
        }

        if (typeof draft?.html === 'string' && draft.html.trim() !== '') {
            page.component = draft.html;
        }

        const signatureParser = domParserFor(options.environment || globalThis);
        const contactProjection = bindSignatureContactMarkerRows(page.component, signatureParser);
        const signature = contactProjection.html;
        if (!/data-rt-artifact-version\s*=\s*(["'])v2[23]\1/i.test(signature)
            && (signature.split('{{TRAIN_SRC}}').length - 1) !== 1) {
            throw new Error('Die Signatur benötigt genau ein gebundenes Zugbild.');
        }
        const parsed = parseMailFragment(
            signatureParser,
            `<table ${MAIL_SIGNATURE_CANVAS_ATTRIBUTE}="true" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody>${signature}</tbody></table>`,
        );
        const wrapper = parsed.querySelector(`table[${MAIL_SIGNATURE_CANVAS_ATTRIBUTE}]`);
        const body = wrapper?.tBodies?.[0] || wrapper?.querySelector('tbody');
        const rows = Array.from(body?.children || []);
        const trainContract = projectSignatureTrainImage(wrapper, rows, project);
        markMailPreviewImageTokens(wrapper);
        if (!wrapper || !body || rows.length !== 2
            || rows.some((row) => row.tagName !== 'TR')
            || trainContract.carrier?.tagName !== 'TD'
            || trainContract.carrier.parentElement !== rows[0]
            || (!trainContract.background && (trainContract.image.getAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE) !== 'TRAIN_SRC'
                || trainContract.image.getAttribute('src') !== MAIL_PREVIEW_TRANSPARENT_PIXEL))) {
            throw new Error('Die Signatur benoetigt zwei Tabellenzeilen und genau einen gebundenen IMG-Zug.');
        }
        assertSignatureContactMarkerBindings(wrapper, contactProjection.hasMarkers);

        markImportedInlineStyles(wrapper);
        page.component = wrapper.outerHTML;
        // GrapesJS prioritizes exported frames over component on reload. They
        // contain the old canvas, never the authoritative server HTML above.
        delete page.frames;
    }

    return project;
}

/**
 * Entfernt die rein editorseitige Tabellenhülle wieder und stellt alle
 * offiziellen Bildtokens her. Der Server sieht dadurch weiterhin exakt das
 * <tr>-Fragment, das Downloads und Systemmails erwarten.
 */
export function serializeMailDocumentForSave({
    project,
    html,
    css = '',
    kind = 'template',
    environment = globalThis,
    baselineHtml = '',
    previewAssets = {},
} = {}) {
    const parser = domParserFor(environment);
    const normalizedCanvas = inlineGrapesImportedStyles({
        parser,
        html,
        styles: Array.isArray(project?.styles) ? project.styles : [],
    });
    const canonicalCss = serializeMailProjectStyles(normalizedCanvas.styles);

    if (kind === 'template') {
        const canvasDocument = parseMailFragment(parser, normalizedCanvas.html);
        const previewRows = canvasDocument.querySelectorAll('[data-rt-mail-preview-only="signature"]');
        if (previewRows.length !== 1 || !canvasDocument.body) {
            throw new Error('Die sichere Position des Signaturblocks in der Mailvorlage fehlt.');
        }
        const templateMarkBinding = canonicalTemplateMarkBinding(
            parser,
            canvasDocument.body,
            baselineHtml,
        );
        const templateApplicationBinding = canonicalTemplateApplicationBinding(
            parser,
            canvasDocument.body,
            baselineHtml,
        );
        restoreMailPreviewImageTokens(canvasDocument.body);
        assertCanonicalTemplateMarkBinding(templateMarkBinding);

        // Erst den kompletten unsauberen Canvaszustand pruefen. Ein zweiter
        // Preview-Marker oder eine echte Vorschauquelle darf nicht dadurch
        // legitimiert werden, dass unten die harmlosen Block-Metadaten
        // entfernt werden.
        const previewRowHtml = previewRows[0].outerHTML;
        const applicationRowHtml = templateApplicationBinding?.row?.outerHTML || '';
        let uncheckedBody = canvasDocument.body.innerHTML
            .replace(previewRowHtml, SIGNATURE_PLACEHOLDER);
        if (applicationRowHtml) {
            uncheckedBody = uncheckedBody.replace(
                applicationRowHtml,
                MAIL_TEMPLATE_APPLICATION_PLACEHOLDER,
            );
        }
        const uncheckedPlaceholderCount = uncheckedBody.split(SIGNATURE_PLACEHOLDER).length - 1;
        if (uncheckedPlaceholderCount !== 1
            || /data-rt-mail-(?:preview(?:-[\w-]+)?|signature-preview)/i.test(uncheckedBody)
            || mailPreviewAssetSources(previewAssets).some((source) => uncheckedBody.includes(source))) {
            throw new Error('Die Mailvorlage konnte nicht verlustfrei rekonstruiert werden.');
        }

        stripMailEditorAttributes(
            canvasDocument.body,
            [previewRows[0], templateApplicationBinding?.row].filter(Boolean),
        );

        const canonicalDocument = parser.parseFromString(String(baselineHtml || ''), 'text/html');
        if (!canonicalDocument.documentElement || !canonicalDocument.head || !canonicalDocument.body) {
            throw new Error('Die kanonische Dokumenthülle der Mailvorlage fehlt.');
        }

        const canonicalPreviewRowHtml = previewRows[0].outerHTML;
        const canonicalApplicationRowHtml = templateApplicationBinding?.row?.outerHTML || '';
        const canonicalBody = restoreCanonicalTemplateApplication(
            canvasDocument.body,
            templateApplicationBinding,
            restoreCanonicalTemplateMark(canvasDocument.body, templateMarkBinding),
        )
            .replace(canonicalPreviewRowHtml, SIGNATURE_PLACEHOLDER);
        if (canonicalApplicationRowHtml && canonicalBody.includes(canonicalApplicationRowHtml)) {
            throw new Error('Die Anwendungsslot-Vorschau wurde nicht vollstaendig entfernt.');
        }
        let canonicalHtml = '<!doctype html>\n'
            + `<html${serializeElementAttributes(canonicalDocument.documentElement)}>`
            + canonicalDocument.head.outerHTML
            + `<body${serializeElementAttributes(canonicalDocument.body)}>${canonicalBody}</body>`
            + '</html>';
        const placeholderCount = canonicalHtml.split(SIGNATURE_PLACEHOLDER).length - 1;
        if (placeholderCount !== 1
            || /data-rt-mail-(?:preview(?:-[\w-]+)?|signature-preview)/i.test(canonicalHtml)
            || mailPreviewAssetSources(previewAssets).some((source) => canonicalHtml.includes(source))) {
            throw new Error('Die Mailvorlage konnte nicht verlustfrei rekonstruiert werden.');
        }

        canonicalHtml = canonicalHtml.trim();
        const canonicalProject = normalizeMailProject(structuredClone(project || {}));
        if (!canonicalProject.pages?.[0]) {
            throw new Error('Das Vorlagenprojekt besitzt keine bearbeitbare Seite.');
        }
        canonicalProject.pages[0].component = canonicalHtml;
        delete canonicalProject.pages[0].frames;
        canonicalProject.styles = normalizedCanvas.styles;

        return { project: canonicalProject, html: canonicalHtml, css: canonicalCss };
    }

    if (kind !== 'signature') {
        return { project, html: normalizedCanvas.html, css: canonicalCss };
    }

    const parsed = parseMailFragment(parser, normalizedCanvas.html);
    const wrappers = parsed.querySelectorAll(`table[${MAIL_SIGNATURE_CANVAS_ATTRIBUTE}]`);
    const wrapper = wrappers[0];
    const body = wrapper?.tBodies?.[0] || wrapper?.querySelector('tbody');
    const trainPreviewRows = wrapper?.querySelectorAll?.('[data-rt-mail-preview-only="train"]') || [];
    if (trainPreviewRows.length !== 0) {
        throw new Error('Der Signatur-Editor darf keine alte Zugvorschauzeile enthalten.');
    }
    const rows = Array.from(body?.children || []);
    const trainPreviewImages = wrapper?.querySelectorAll?.(
        `img.rt-sign-train[data-rt-train][${MAIL_PREVIEW_IMAGE_ATTRIBUTE}="TRAIN_SRC"]`,
    ) || [];
    if (wrappers.length !== 1 || !wrapper || !body
        || rows.length !== 2 || rows.some((row) => row.tagName !== 'TR')
        || (usesSignatureBackground(rows)
            ? trainPreviewImages.length !== 0 || wrapper.querySelectorAll('[data-rt-mail-preview-train="TRAIN_SRC"]').length !== 1
            : trainPreviewImages.length !== 1)) {
        throw new Error('Die sichere Tabellenstruktur des Signatur-Editors fehlt.');
    }
    restoreMailPreviewImageTokens(wrapper);

    const baselineContactProjection = bindSignatureContactMarkerRows(baselineHtml, parser);
    restoreSignatureContactMarkers(wrapper, baselineContactProjection.hasMarkers);
    const trainContract = usesSignatureBackground(rows)
        ? assertSignatureBackgroundDocument(wrapper, rows)
        : usesFlowSafeSignatureTrain(rows)
        ? assertFlowSafeSignatureTrainImage(wrapper, rows)
        : assertCanonicalSignatureTrainImage(wrapper, rows);
    if ((!trainContract.background && trainContract.image.getAttribute('src') !== '{{TRAIN_SRC}}')
        || wrapper.querySelectorAll('[data-rt-train-background]').length !== 0) {
        throw new Error('Die IMG-Bindung des RailTime-Zugmotivs wurde im Editor beschädigt.');
    }

    assertRestorableSignatureMainMarker(body, rows);
    const uncheckedCanonicalHtml = [
        rows[0].outerHTML,
        MAIL_SIGNATURE_MAIN_MARKER,
        rows[1].outerHTML,
    ].join('\n').trim();
    if (!uncheckedCanonicalHtml.startsWith('<tr')) {
        throw new Error('Die Signatur besitzt nach dem Speichern kein gültiges Tabellenfragment.');
    }
    if (!trainContract.background && !uncheckedCanonicalHtml.includes('{{TRAIN_SRC}}')) {
        throw new Error('Das RailTime-Zugmotiv fehlt nach dem Speichern der Signatur.');
    }
    if (/data-rt-mail-(?:signature-canvas|signature-preview|preview(?:-[\w-]+)?)/i.test(uncheckedCanonicalHtml)
        || mailPreviewAssetSources(previewAssets).some((source) => uncheckedCanonicalHtml.includes(source))) {
        throw new Error('Reine Vorschauwerte dürfen nicht in der Signatur gespeichert werden.');
    }

    stripMailEditorAttributes(wrapper);
    const canonicalHtml = [
        rows[0].outerHTML,
        MAIL_SIGNATURE_MAIN_MARKER,
        rows[1].outerHTML,
    ].join('\n').trim();

    const canonicalProject = normalizeMailProject(structuredClone(project || {}));
    if (!canonicalProject.pages?.[0]) {
        throw new Error('Das Signaturprojekt besitzt keine bearbeitbare Seite.');
    }
    canonicalProject.pages[0].component = canonicalHtml;
    delete canonicalProject.pages[0].frames;
    canonicalProject.styles = normalizedCanvas.styles;
    canonicalProject.railtime = {
        ...(canonicalProject.railtime && typeof canonicalProject.railtime === 'object'
            ? canonicalProject.railtime
            : {}),
        document: 'signature',
        schema: MAIL_SIGNATURE_SCHEMA,
    };

    return { project: canonicalProject, html: canonicalHtml, css: canonicalCss };
}

/**
 * Laedt nach einem bereinigenden Server-Save genau dessen kanonischen Stand
 * wieder in die laufende Leinwand. Ohne diesen Schritt koennte GrapesJS bis
 * zum Reload weiterhin ein Element zeigen, das der Mail-Sanitizer bereits
 * entfernt hat. Ein fehlender Load-Vertrag ist absichtlich ein harter Fehler:
 * gespeichert und sichtbar duerfen nicht auseinanderlaufen.
 */
export async function rehydrateAuthoritativeMailProject({
    editor,
    draft,
    sanitizationChanged = false,
    parseCss = () => [],
    projectOptions = {},
} = {}) {
    if (!sanitizationChanged) return false;

    if (typeof editor?.loadProjectData !== 'function') {
        throw new Error('Der bereinigte Serverstand konnte nicht in den Mail-Editor geladen werden.');
    }

    await editor.loadProjectData(projectForMailDocument(draft, parseCss, projectOptions));

    return true;
}

/**
 * Schalter ENTFERNEN statt sperren.
 *
 * Die Shell des Builders setzt undo/redo bei jedem Editorereignis neu aus
 * dem UndoManager (syncShellState). Ein gesetztes disabled waere beim
 * naechsten Klick in die Leinwand wieder weg.
 */
export function removeBuilderControls(root, selectors) {
    const controls = root?.querySelectorAll?.(selectors.join(', ')) || [];

    controls.forEach((control) => control.remove());

    return controls.length;
}

/**
 * Leinwand-Stylesheet in das Editor-iframe haengen.
 *
 * Muss nach jedem canvas:frame:load erneut laufen — der Builder baut das
 * iframe bei einem Neuladen des Projekts komplett neu auf.
 */
export function applyMailCanvasStyles(editor, css) {
    const canvasDocument = editor?.Canvas?.getDocument?.();

    if (!canvasDocument?.head) {
        return false;
    }

    let style = canvasDocument.getElementById(MAIL_CANVAS_STYLE_ID);

    if (!style) {
        style = canvasDocument.createElement('style');
        style.id = MAIL_CANVAS_STYLE_ID;
        canvasDocument.head.appendChild(style);
    }

    if (style.textContent !== css) {
        style.textContent = css;
    }

    return true;
}

/**
 * Browser verschieben den reinen {{SIGNATURE_BLOCK}}-Text aus seinem
 * Tabellenkontext an den Anfang des Dokuments. Im Canvas wird er deshalb als
 * rein visuelle Tabellenzeile an der korrekten Stelle gezeigt. Die GrapesJS-
 * Modelldaten werden dabei nicht angefasst; editor.getHtml() liefert weiter
 * exakt den Platzhalter, den der Server spaeter durch die Signatur ersetzt.
 */
export function hydrateMailCanvasPlaceholders(editor) {
    const canvasDocument = editor?.Canvas?.getDocument?.();
    const body = canvasDocument?.body;
    const NodeFilterClass = canvasDocument?.defaultView?.NodeFilter;

    if (!body || !NodeFilterClass || typeof canvasDocument.createTreeWalker !== 'function') {
        return 0;
    }

    if (body.querySelector('[data-rt-mail-preview-only="signature"]')) {
        return 0;
    }

    const walker = canvasDocument.createTreeWalker(body, NodeFilterClass.SHOW_TEXT);
    const tokens = [];
    let current = walker.nextNode();
    while (current) {
        if (String(current.nodeValue || '').trim() === SIGNATURE_PLACEHOLDER) {
            tokens.push(current);
        }
        current = walker.nextNode();
    }

    tokens.forEach((tokenNode) => {
        const marker = canvasDocument.createElement('div');
        marker.setAttribute(MAIL_BLOCK_ATTRIBUTE, 'signature');
        marker.setAttribute('contenteditable', 'false');
        marker.setAttribute('role', 'note');
        marker.setAttribute('aria-label', 'Hier wird beim Versand der zentrale Signaturblock eingesetzt.');

        const shell = body.querySelector('table.rt-shell');
        if (shell) {
            tokenNode.remove();
            const row = canvasDocument.createElement('tr');
            row.setAttribute('data-rt-mail-preview-only', 'signature');
            const cell = canvasDocument.createElement('td');
            cell.setAttribute('style', 'padding:0;');
            cell.appendChild(marker);
            row.appendChild(cell);
            (shell.tBodies?.[0] || shell).appendChild(row);
        } else {
            tokenNode.replaceWith(marker);
        }
    });

    return tokens.length;
}

/**
 * GrapesJS ersetzt unbekannte {{...}}-Bildquellen durch sein Broken-Image-
 * Symbol. Die Projektkopie traegt deshalb neutrale 1px-Bilder und stabile
 * Vorschau-Marker; nur das iframe-DOM erhaelt hier die echten RailTime-
 * Assets. serializeMailDocumentForSave() stellt anschliessend die Tokens her.
 */
export function hydrateMailCanvasAssets(editor, theme = 'light', previewAssets = {}) {
    const canvasDocument = editor?.Canvas?.getDocument?.();
    if (!canvasDocument?.body) return 0;

    const themed = previewAssets?.[theme] || previewAssets?.light || {};
    const icons = previewAssets?.icons || {};
    const sources = {
        LOGO_SRC: themed.logo,
        ICON_RT_SRC: themed.mark,
        TRAIN_SRC: themed.train,
        ICON_PHONE_SRC: icons.phone,
        ICON_MOBILE_SRC: icons.mobile,
        ICON_EMAIL_SRC: icons.email,
        ICON_WEB_SRC: icons.web,
        ICON_LOCATION_SRC: icons.location,
    };
    let hydrated = 0;

    canvasDocument.querySelectorAll(`[${MAIL_PREVIEW_IMAGE_ATTRIBUTE}]`).forEach((image) => {
        const source = sources[image.getAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE)];
        if (!source) return;
        image.setAttribute('src', source);
        refreshPausedAnimatedPreviewElement(image);
        hydrated += 1;
    });
    canvasDocument.querySelectorAll('td.rt-sign-cell[data-rt-mail-preview-train="TRAIN_SRC"]').forEach((carrier) => {
        const enabled = carrier.getAttribute('data-rt-signature-background') === '1';
        const source = sources.TRAIN_SRC;
        // GrapesJS may update its CSS rule while an earlier DOM hydration still
        // owns inline styles. Refresh geometry too, without touching the model.
        const attributes = Object.fromEntries([...carrier.attributes].map((attribute) => [attribute.name, attribute.value]));
        Object.entries(mailSignatureBackgroundStyle(attributes, 'none')).forEach(([property, value]) => {
            carrier.style.setProperty(property, value);
        });
        carrier.style.backgroundImage = enabled && source ? `url(${JSON.stringify(source)})` : 'none';
        refreshPausedAnimatedPreviewElement(carrier);
        hydrated += 1;
    });

    return hydrated;
}

/**
 * Startet alle nur im Canvas eingesetzten Marken-GIFs neu. Die GrapesJS-
 * Modelle und damit der gespeicherte Mailentwurf bleiben vollständig
 * unverändert; ausgetauscht wird ausschließlich die gerenderte Bildidentität.
 */
export function restartMailCanvasAnimations(editor, { nonce = Date.now() } = {}) {
    const root = editor?.getWrapper?.();
    if (!root) return 0;

    const children = (component) => {
        const collection = component?.components?.();
        if (Array.isArray(collection)) return collection;
        if (Array.isArray(collection?.models)) return collection.models;
        if (typeof collection?.toArray === 'function') return collection.toArray();
        return [];
    };
    let restarted = 0;
    const visit = (component) => {
        if (componentAnimationContext(component).animated
            && restartAnimatedPreview(component, { nonce: `${nonce}-${restarted}` })) {
            restarted += 1;
        }
        children(component).forEach(visit);
    };

    visit(root);
    return restarted;
}

/**
 * Der Zug-Carrier und das kanonische RT-Zeichen sind serverkontrollierte
 * Geometrie. Der Style-Manager darf dort keine scheinbar speicherbaren
 * Aenderungen anbieten. Ihre Kinder bzw. die restliche Signatur bleiben
 * normal bearbeitbar.
 */
function mailComponentChildren(component) {
    const collection = component?.components?.();
    if (Array.isArray(collection)) return collection;
    if (Array.isArray(collection?.models)) return collection.models;
    if (typeof collection?.toArray === 'function') return collection.toArray();
    return [];
}

function componentClasses(component) {
    const attributes = component?.getAttributes?.() || component?.get?.('attributes') || {};
    return String(attributes.class || '').split(/\s+/).filter(Boolean);
}

function componentUsesFailOpenSignatureStage(component) {
    for (let current = component; current; current = current?.parent?.()) {
        const attributes = current?.getAttributes?.() || current?.get?.('attributes') || {};
        if (MAIL_SIGNATURE_FAIL_OPEN_ARTIFACTS.includes(
            String(attributes[MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE] || '').trim().toLowerCase(),
        )) {
            return true;
        }
    }

    return false;
}

function componentUsesAspectSafeSignatureTrain(component) {
    for (let current = component; current; current = current?.parent?.()) {
        const attributes = current?.getAttributes?.() || current?.get?.('attributes') || {};
        if (MAIL_SIGNATURE_ASPECT_SAFE_ARTIFACTS.includes(
            String(attributes[MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE] || '').trim().toLowerCase(),
        )) {
            return true;
        }
    }

    return false;
}

function componentUsesForwardSafeSignatureTrain(component) {
    for (let current = component; current; current = current?.parent?.()) {
        const attributes = current?.getAttributes?.() || current?.get?.('attributes') || {};
        if (MAIL_SIGNATURE_FORWARD_SAFE_ARTIFACTS.includes(
            String(attributes[MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE] || '').trim().toLowerCase(),
        )) {
            return true;
        }
    }

    return false;
}

function componentUsesFlowSafeSignatureTrain(component) {
    for (let current = component; current; current = current?.parent?.()) {
        const attributes = current?.getAttributes?.() || current?.get?.('attributes') || {};
        if (MAIL_SIGNATURE_FLOW_SAFE_ARTIFACTS.includes(
            String(attributes[MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE] || '').trim().toLowerCase(),
        )) {
            return true;
        }
    }

    return false;
}

function componentUsesSignatureBackground(component) {
    for (let current = component; current; current = current?.parent?.()) {
        if (['v22', 'v23'].includes(String((current.getAttributes?.() || current.get?.('attributes') || {})[MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE] || '').toLowerCase())) return true;
    }
    return false;
}

function componentUsesFluidSignatureTrain(component) {
    for (let current = component; current; current = current?.parent?.()) {
        const attributes = current?.getAttributes?.() || current?.get?.('attributes') || {};
        if (String(attributes[MAIL_SIGNATURE_ARTIFACT_ATTRIBUTE] || '').trim().toLowerCase() === 'v25') return true;
    }
    return false;
}

export function synchronizeMailSignatureBackground(component) {
    if (!componentUsesSignatureBackground(component) || !isMailSignatureBackgroundComponent(component)) return false;
    const attributes = component.getAttributes?.() || component.get?.('attributes') || {};
    const expected = mailSignatureBackgroundStyle(attributes, attributes['data-rt-mail-preview-train'] ? 'none' : "url('{{TRAIN_SRC}}')");
    const style = component.getStyle?.() || {};
    if (Object.entries(expected).every(([name, value]) => style[name] === value)) return false;
    component.addStyle?.(expected, { silent: true });
    return true;
}

function enforceComponentStyle(component, expected) {
    const current = component?.getStyle?.() || {};
    const expectedKeys = new Set(Object.keys(expected));
    let changed = Object.entries(expected).some(([name, value]) => current[name] !== value)
        || Object.keys(current).some((name) => !expectedKeys.has(name));
    if (!changed) return false;

    Object.keys(current).forEach((name) => {
        if (expectedKeys.has(name)) return;
        delete current[name];
        component?.removeStyle?.(name, { silent: true });
    });
    component?.setStyle?.({ ...expected }, { silent: true });
    return true;
}

function enforceComponentAttributes(component, expected) {
    const current = component?.getAttributes?.() || component?.get?.('attributes') || {};
    const changed = Object.entries(expected).some(([name, value]) => String(current[name] ?? '') !== String(value));
    if (!changed) return false;
    if (typeof component?.addAttributes === 'function') {
        component.addAttributes(expected, { silent: true });
    } else {
        Object.assign(current, expected);
    }
    return true;
}

function removeComponentAttributes(component, names) {
    const current = component?.getAttributes?.() || component?.get?.('attributes') || {};
    let changed = false;
    names.forEach((name) => {
        if (!Object.prototype.hasOwnProperty.call(current, name)) return;
        changed = true;
        if (typeof component?.removeAttributes === 'function') {
            component.removeAttributes(name, { silent: true });
        } else {
            delete current[name];
        }
    });

    return changed;
}

export function synchronizeMailSignatureFlowGeometry(component) {
    if (!componentUsesFlowSafeSignatureTrain(component) && !componentUsesSignatureBackground(component)) return false;

    const fluid = componentUsesFluidSignatureTrain(component);
    const attributes = component?.getAttributes?.() || component?.get?.('attributes') || {};
    const classes = componentClasses(component);
    if (classes.includes('rt-sign-stage')) {
        return enforceComponentStyle(component, {
            display: 'block',
            width: '100%',
            overflow: 'visible',
        });
    }
    if (classes.includes('rt-sign-content-frame')) {
        const removedHeight = removeComponentAttributes(component, ['height']);
        const attributesChanged = enforceComponentAttributes(component, {
            role: 'presentation',
            width: '100%',
            border: '0',
            cellspacing: '0',
            cellpadding: '0',
        });
        return enforceComponentStyle(component, {
            width: '100%',
            'border-collapse': 'collapse',
        }) || attributesChanged || removedHeight;
    }
    if (classes.includes('rt-sign-train-layer')
        && attributes['data-rt-layer-train'] !== undefined) {
        const attributesChanged = enforceComponentAttributes(component, {
            'data-rt-layer-align': 'left',
            'data-rt-layer-size': '100',
            'data-rt-layer-mobile': 'left',
        });
        return enforceComponentStyle(component, {
            display: 'block',
            width: '100%',
            'max-width': fluid ? 'none' : '720px',
            margin: '0 auto 0 0',
            overflow: 'hidden',
            'font-size': '0',
            'line-height': '0',
            'text-align': 'left',
        }) || attributesChanged;
    }
    if (classes.includes('rt-sign-train-frame')) {
        const removedHeight = fluid && removeComponentAttributes(component, ['height']);
        const attributesChanged = enforceComponentAttributes(component, {
            role: 'presentation',
            width: '100%',
            ...(fluid ? {} : { height: MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT }),
            border: '0',
            cellspacing: '0',
            cellpadding: '0',
        });
        return enforceComponentStyle(component, {
            width: '100%',
            ...(fluid ? {} : { height: '61px' }),
            'border-collapse': 'collapse',
        }) || attributesChanged || removedHeight;
    }
    if (classes.includes('rt-sign-train-slot')) {
        const removedHeight = fluid && removeComponentAttributes(component, ['height']);
        const attributesChanged = enforceComponentAttributes(component, {
            ...(fluid ? {} : { height: MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT }),
            valign: 'bottom',
        });
        return enforceComponentStyle(component, {
            ...(fluid ? {} : { height: '61px' }),
            padding: '0',
            'text-align': 'left',
            'vertical-align': 'bottom',
            'font-size': '0',
            'line-height': '0',
        }) || attributesChanged || removedHeight;
    }
    if (classes.includes('rt-sign-train') && attributes['data-rt-train'] !== undefined) {
        const attributesChanged = enforceComponentAttributes(component, {
            width: '720',
            height: MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT,
        });
        return enforceComponentStyle(component, {
            display: 'block',
            width: '100%',
            'max-width': fluid ? 'none' : '720px',
            height: 'auto',
            margin: '0',
            border: '0',
            outline: 'none',
            'text-decoration': 'none',
            'vertical-align': 'bottom',
            'mso-hide': 'all',
        }) || attributesChanged;
    }

    return false;
}

export function synchronizeMailSignatureFixedGeometry(
    component,
    failOpenStage = componentUsesFailOpenSignatureStage(component),
) {
    if (componentUsesFlowSafeSignatureTrain(component) || componentUsesSignatureBackground(component)) return false;

    const classes = componentClasses(component);
    const forwardSafeTrain = componentUsesForwardSafeSignatureTrain(component);
    if (classes.includes('rt-sign-stage')) {
        return enforceComponentStyle(
            component,
            failOpenStage
                ? {
                    position: 'relative',
                    height: 'auto',
                    'min-height': MAIL_SIGNATURE_FIXED_HEIGHT,
                    overflow: 'visible',
                }
                : {
                    position: 'relative',
                    height: MAIL_SIGNATURE_FIXED_HEIGHT,
                    'max-height': MAIL_SIGNATURE_FIXED_HEIGHT,
                    overflow: 'hidden',
                },
        );
    }
    if (classes.includes('rt-sign-train-frame')) {
        const attributesChanged = enforceComponentAttributes(component, {
            role: 'presentation',
            width: '100%',
            height: forwardSafeTrain ? MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT : MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE,
            border: '0',
            cellspacing: '0',
            cellpadding: '0',
        });
        return enforceComponentStyle(component, {
            width: '100%',
            height: forwardSafeTrain ? '61px' : MAIL_SIGNATURE_FIXED_HEIGHT,
            'border-collapse': 'collapse',
        }) || attributesChanged;
    }
    if (classes.includes('rt-sign-train-slot')) {
        const attributesChanged = enforceComponentAttributes(component, {
            height: forwardSafeTrain ? MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT : MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE,
            valign: 'bottom',
        });
        return enforceComponentStyle(component, {
            height: forwardSafeTrain ? '61px' : MAIL_SIGNATURE_FIXED_HEIGHT,
            padding: '0',
            'text-align': 'left',
            'vertical-align': 'bottom',
            'font-size': '0',
            'line-height': '0',
        }) || attributesChanged;
    }
    if (classes.includes('rt-sign-content-frame')) {
        const attributesChanged = enforceComponentAttributes(component, {
            height: MAIL_SIGNATURE_FIXED_HEIGHT_ATTRIBUTE,
        });
        return enforceComponentStyle(component, {
            ...(failOpenStage ? { position: 'relative', 'z-index': '1' } : {}),
            width: '100%',
            height: MAIL_SIGNATURE_FIXED_HEIGHT,
            'border-collapse': 'collapse',
        }) || attributesChanged;
    }

    return false;
}

export function synchronizeMailTrainLayerAlignment(component) {
    const attributes = component?.getAttributes?.() || component?.get?.('attributes') || {};
    const classes = String(attributes.class || '').split(/\s+/).filter(Boolean);
    if (!classes.includes('rt-sign-train-layer') || attributes['data-rt-layer-train'] === undefined) {
        return false;
    }
    if (componentUsesFlowSafeSignatureTrain(component)) return false;
    const failOpenStage = componentUsesFailOpenSignatureStage(component);
    const aspectSafeTrain = componentUsesAspectSafeSignatureTrain(component);
    const forwardSafeTrain = componentUsesForwardSafeSignatureTrain(component);

    const alignment = ['left', 'center', 'right'].includes(attributes['data-rt-layer-align'])
        ? attributes['data-rt-layer-align']
        : 'center';
    const sizeName = ['100', '108.67', '125', '150', '200'].includes(String(attributes['data-rt-layer-size']))
        ? String(attributes['data-rt-layer-size'])
        : '125';
    const mobileCrop = ['left', 'center', 'train', 'stop65', 'stop60', 'right'].includes(attributes['data-rt-layer-mobile'])
        ? attributes['data-rt-layer-mobile']
        : 'train';
    const size = {
        100: { width: '100%', centerMargin: '0', rightMargin: '0' },
        '108.67': { width: '108.67%', centerMargin: '-4.335%', rightMargin: '-8.67%' },
        125: { width: '125%', centerMargin: '-12.5%', rightMargin: '-25%' },
        150: { width: '150%', centerMargin: '-25%', rightMargin: '-50%' },
        200: { width: '200%', centerMargin: '-50%', rightMargin: '-100%' },
    }[sizeName];
    const layerMargin = {
        left: '0 auto 0 0',
        center: '0 auto',
        right: '0 0 0 auto',
    }[alignment];
    const imageOffset = alignment === 'center'
        ? size.centerMargin
        : (alignment === 'right' ? size.rightMargin : '0');
    const imageMargin = imageOffset === '0' ? '0' : `0 0 0 ${imageOffset}`;
    const canonicalAttributes = {
        'data-rt-layer-align': alignment,
        'data-rt-layer-size': sizeName,
        'data-rt-layer-mobile': mobileCrop,
    };
    const attributesChanged = Object.entries(canonicalAttributes)
        .some(([name, value]) => attributes[name] !== value);
    if (attributesChanged) {
        if (typeof component?.addAttributes === 'function') {
            component.addAttributes(canonicalAttributes, { silent: true });
        } else {
            Object.assign(attributes, canonicalAttributes);
        }
    }
    const layerChanged = enforceComponentStyle(component, forwardSafeTrain ? {
        position: 'absolute',
        'z-index': '0',
        left: '0',
        right: '0',
        top: 'auto',
        bottom: '0',
        display: 'block',
        width: '100%',
        height: '61px',
        'max-height': '61px',
        'max-width': '1815px',
        margin: '0',
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    } : {
        ...(failOpenStage ? { position: 'relative', 'z-index': '0' } : {}),
        display: 'block',
        width: '100%',
        height: MAIL_SIGNATURE_FIXED_HEIGHT,
        'max-height': MAIL_SIGNATURE_FIXED_HEIGHT,
        'max-width': '1815px',
        margin: layerMargin,
        'margin-bottom': MAIL_SIGNATURE_TRAIN_OVERLAP,
        overflow: 'hidden',
        'font-size': '0',
        'line-height': '0',
        'text-align': 'left',
    });

    const descendants = [];
    const visit = (parent) => mailComponentChildren(parent).forEach((child) => {
        descendants.push(child);
        visit(child);
    });
    visit(component);
    const frame = descendants.find((child) => componentClasses(child).includes('rt-sign-train-frame'));
    const slot = descendants.find((child) => componentClasses(child).includes('rt-sign-train-slot'));
    const image = descendants.find((child) => {
        const childAttributes = child?.getAttributes?.() || child?.get?.('attributes') || {};
        return componentClasses(child).includes('rt-sign-train')
            && childAttributes['data-rt-train'] !== undefined;
    });
    const frameChanged = frame ? synchronizeMailSignatureFixedGeometry(frame, failOpenStage) : false;
    const slotChanged = slot ? synchronizeMailSignatureFixedGeometry(slot, failOpenStage) : false;
    let imageChanged = false;
    if (image) {
        const imageAttributes = image.getAttributes?.() || image.get?.('attributes') || {};
        const requiresImageHeight = forwardSafeTrain || (failOpenStage && !aspectSafeTrain);
        const imageAttributesChanged = String(imageAttributes.width || '') !== '720'
            || (requiresImageHeight
                ? String(imageAttributes.height || '') !== MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT
                : Object.prototype.hasOwnProperty.call(imageAttributes, 'height'));
        if (imageAttributesChanged) {
            if (typeof image.addAttributes === 'function') {
                image.addAttributes({
                    width: '720',
                    ...(requiresImageHeight ? { height: MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT } : {}),
                }, { silent: true });
                if (!requiresImageHeight) image.removeAttributes?.('height', { silent: true });
            } else {
                imageAttributes.width = '720';
                if (requiresImageHeight) imageAttributes.height = MAIL_SIGNATURE_FAIL_OPEN_IMAGE_HEIGHT;
                else delete imageAttributes.height;
            }
        }
        const imageStyleChanged = enforceComponentStyle(image, forwardSafeTrain ? {
            position: 'static',
            left: 'auto',
            right: 'auto',
            bottom: 'auto',
            display: 'block',
            width: '720px',
            'max-width': '100%',
            height: 'auto',
            margin: '0',
            border: '0',
            outline: 'none',
            'text-decoration': 'none',
            'vertical-align': 'bottom',
            'mso-hide': 'all',
        } : {
            position: 'static',
            left: 'auto',
            right: 'auto',
            bottom: 'auto',
            display: 'inline-block',
            width: size.width,
            'max-width': 'none',
            height: 'auto',
            margin: imageMargin,
            border: '0',
            outline: 'none',
            'text-decoration': 'none',
            'vertical-align': 'bottom',
            'mso-hide': 'all',
        });
        imageChanged = imageAttributesChanged || imageStyleChanged;
    }

    return attributesChanged || layerChanged || frameChanged || slotChanged || imageChanged;
}

/**
 * Haelt reguläre Inhaltsbilder in einem einzigen, Outlook-tauglichen
 * Geometrievertrag. HTML-Breite und Inline-CSS duerfen sich nicht
 * widersprechen; eine feste Hoehe wird bei responsiven Bildern bewusst
 * entfernt, damit ein Medienwechsel kein verzerrtes Seitenverhaeltnis erbt.
 */
export function synchronizeMailContentImage(component) {
    const attributes = component?.getAttributes?.() || component?.get?.('attributes') || {};
    const tagName = String(component?.get?.('tagName') || '').toLowerCase();
    const classes = String(attributes.class || '').split(/\s+/).filter(Boolean);
    if (tagName !== 'img'
        || attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE]
        || attributes['data-rt-train'] !== undefined
        || classes.includes('rt-sign-train')) {
        return false;
    }

    const style = component?.getStyle?.() || {};
    const styleWidth = String(style['max-width'] || '').match(/^(\d{1,4})px$/i)?.[1];
    const requestedWidth = Number.parseInt(String(attributes.width || styleWidth || '600'), 10);
    const width = Math.min(1200, Math.max(40, Number.isFinite(requestedWidth) ? requestedWidth : 600));
    const alignment = ['left', 'center', 'right'].includes(attributes['data-rt-image-align'])
        ? attributes['data-rt-image-align']
        : (() => {
            const parentAttributes = component?.parent?.()?.getAttributes?.() || {};
            if (parentAttributes.align === 'center' || style.margin === '0 auto') return 'center';
            if (parentAttributes.align === 'right' || style.margin === '0 0 0 auto') return 'right';
            return 'left';
        })();
    const nextAttributes = {
        width: String(width),
        'data-rt-image-align': alignment,
    };
    const attributesChanged = String(attributes.width || '') !== nextAttributes.width
        || attributes['data-rt-image-align'] !== alignment
        || Object.prototype.hasOwnProperty.call(attributes, 'height');
    if (attributesChanged) {
        if (typeof component?.addAttributes === 'function') {
            component.addAttributes(nextAttributes, { silent: true });
            component.removeAttributes?.('height', { silent: true });
        } else {
            Object.assign(attributes, nextAttributes);
            delete attributes.height;
        }
    }

    const margin = {
        left: '0',
        center: '0 auto',
        right: '0 0 0 auto',
    }[alignment];
    const nextStyle = {
        ...style,
        display: 'block',
        width: '100%',
        'max-width': `${width}px`,
        height: 'auto',
        margin,
    };
    const styleChanged = style.display !== nextStyle.display
        || style.width !== nextStyle.width
        || style['max-width'] !== nextStyle['max-width']
        || style.height !== nextStyle.height
        || style.margin !== nextStyle.margin;
    if (styleChanged) component?.setStyle?.(nextStyle, { silent: true });

    const parent = component?.parent?.();
    const parentTag = String(parent?.get?.('tagName') || '').toLowerCase();
    let parentChanged = false;
    if (parentTag === 'td') {
        const parentAttributes = parent?.getAttributes?.() || parent?.get?.('attributes') || {};
        const parentStyle = parent?.getStyle?.() || {};
        parentChanged = parentAttributes.align !== alignment || parentStyle['text-align'] !== alignment;
        if (parentChanged) {
            if (typeof parent?.addAttributes === 'function') {
                parent.addAttributes({ align: alignment }, { silent: true });
            } else {
                parentAttributes.align = alignment;
            }
            parent?.setStyle?.({ ...parentStyle, 'text-align': alignment }, { silent: true });
        }
    }

    return attributesChanged || styleChanged || parentChanged;
}

function mailPresentationColor(value) {
    const color = String(value || '').trim();
    if (/^\{\{[A-Z][A-Z0-9_]*\}\}$/.test(color)
        || /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test(color)) {
        return color;
    }

    const rgb = color.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})(?:\s*,\s*(1(?:\.0+)?))?\s*\)$/i);
    if (!rgb) return '';
    const channels = rgb.slice(1, 4).map((channel) => Number(channel));
    if (channels.some((channel) => channel < 0 || channel > 255)) return '';

    return `#${channels.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

function mailPresentationDimension(value, { percent = true } = {}) {
    const dimension = String(value || '').trim();
    const match = dimension.match(percent
        ? /^(\d{1,4})(px|%)$/i
        : /^(\d{1,4})px$/i);
    if (!match || Number(match[1]) < 1) return '';

    return match[2] === '%' ? `${Number(match[1])}%` : String(Number(match[1]));
}

/**
 * Spiegelt eine im Style-Panel gesetzte SAFE-Deklaration in die klassischen
 * HTML-Praesentationsattribute. Moderne Clients lesen weiterhin Inline-CSS;
 * Outlook/Word erhaelt fuer Farbe, Groesse und Ausrichtung denselben Wert als
 * bgcolor/width/height/align/valign. Nur echte Style-Aenderungen rufen diese
 * Funktion auf, sodass die geschuetzte Signaturstruktur beim Laden bytegleich
 * bleibt. Ein Undo stellt ueber den WeakMap-Zustand den vorherigen Attributwert
 * wieder her, ohne Editor-Metadaten in das Mail-HTML zu schreiben.
 */
export function synchronizeMailPresentationAttributes(component, property = '') {
    if (!component || typeof component.getStyle !== 'function') return false;

    const tagName = String(component.get?.('tagName') || '').toLowerCase();
    const style = component.getStyle?.() || {};
    const attributes = component.getAttributes?.() || component.get?.('attributes') || {};
    const classes = componentClasses(component);
    const signatureSystemNode = [
        'rt-sign-stage',
        'rt-sign-content-frame',
        'rt-sign-train-layer',
        'rt-sign-train-frame',
        'rt-sign-train-slot',
        'rt-sign-train',
    ].some((className) => classes.includes(className));
    // Diese Knoten besitzen in allen Signaturvertraegen eine vollstaendige und
    // absichtlich exakte Attributliste. Insbesondere darf das generische
    // text-align-Fallback kein zusaetzliches align="left" auf Zugzeile oder
    // Zugslot schreiben. Inhaltsknoten innerhalb des geschuetzten Rahmens
    // bleiben weiterhin normal bearbeitbar und erhalten ihre Outlook-
    // Praesentationsattribute.
    if (signatureSystemNode) return false;

    const changedProperty = String(property || '').trim().toLowerCase();
    const state = MAIL_PRESENTATION_SYNC_STATE.get(component) || new Map();
    let changed = false;

    const updateAttribute = (attribute, value, { clear = false } = {}) => {
        const normalizedValue = String(value || '');
        const tracked = state.get(attribute);
        if (!normalizedValue) {
            if (!tracked) {
                if (!clear || !Object.prototype.hasOwnProperty.call(attributes, attribute)) return;
                component.removeAttributes?.(attribute, { silent: true });
                if (typeof component.removeAttributes !== 'function') delete attributes[attribute];
                changed = true;
                return;
            }
            if (tracked.original === undefined) {
                component.removeAttributes?.(attribute, { silent: true });
                if (typeof component.removeAttributes !== 'function') delete attributes[attribute];
            } else if (String(attributes[attribute] || '') !== String(tracked.original)) {
                component.addAttributes?.({ [attribute]: tracked.original }, { silent: true });
                if (typeof component.addAttributes !== 'function') attributes[attribute] = tracked.original;
            }
            state.delete(attribute);
            changed = true;
            return;
        }

        if (!tracked) state.set(attribute, { original: attributes[attribute] });
        if (String(attributes[attribute] || '') === normalizedValue) return;
        component.addAttributes?.({ [attribute]: normalizedValue }, { silent: true });
        if (typeof component.addAttributes !== 'function') attributes[attribute] = normalizedValue;
        changed = true;
    };
    const applies = (...properties) => !changedProperty || properties.includes(changedProperty);

    if (applies('background', 'background-color')
        && ['body', 'table', 'tr', 'td', 'th'].includes(tagName)) {
        const directBackground = String(style['background-color'] || '').trim();
        const shorthandBackground = String(style.background || '').trim();
        const directColor = mailPresentationColor(directBackground);
        const shorthandColor = mailPresentationColor(shorthandBackground);
        updateAttribute('bgcolor', directColor || shorthandColor, {
            clear: directBackground === '' && shorthandBackground === '',
        });
    }

    if (applies('width') && ['table', 'td', 'th'].includes(tagName)) {
        const width = String(style.width || '').trim();
        updateAttribute('width', mailPresentationDimension(width), { clear: width === '' });
    }
    if (applies('height') && ['table', 'td', 'th'].includes(tagName)) {
        const height = String(style.height || '').trim();
        updateAttribute('height', mailPresentationDimension(height, { percent: false }), { clear: height === '' });
    }

    if (applies('text-align')) {
        const alignment = String(style['text-align'] || '').trim().toLowerCase();
        const allowed = ['left', 'center', 'right', 'justify'];
        // Das table-Attribut align positioniert die Tabelle selbst, waehrend
        // CSS text-align ihren Inhalt ausrichtet. Diese zwei Semantiken duerfen
        // insbesondere in Outlook/Word nicht miteinander gekoppelt werden.
        if (['tr', 'td', 'th', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'].includes(tagName)) {
            updateAttribute('align', allowed.includes(alignment) ? alignment : '', { clear: alignment === '' });
        }
    }

    if (applies('vertical-align') && ['tr', 'td', 'th'].includes(tagName)) {
        const alignment = String(style['vertical-align'] || '').trim().toLowerCase();
        updateAttribute('valign', ['top', 'middle', 'bottom', 'baseline'].includes(alignment) ? alignment : '', {
            clear: alignment === '',
        });
    }

    if (state.size > 0) MAIL_PRESENTATION_SYNC_STATE.set(component, state);
    else MAIL_PRESENTATION_SYNC_STATE.delete(component);

    return changed;
}

export function protectMailSystemComponents(editor) {
    const root = editor?.getWrapper?.();
    if (!root) return 0;

    const children = (component) => {
        const collection = component?.components?.();
        if (Array.isArray(collection)) return collection;
        if (Array.isArray(collection?.models)) return collection.models;
        if (typeof collection?.toArray === 'function') return collection.toArray();
        return [];
    };
    const protectedComponents = new Set();
    const setName = (component, name) => {
        if (component && name) component.set?.({ 'custom-name': name }, { silent: true });
    };
    const classNames = (attributes) => String(attributes.class || '').split(/\s+/).filter(Boolean);
    const label = (component, attributes) => {
        const classes = classNames(attributes);
        const token = attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE];
        const preview = attributes['data-rt-mail-preview-only'];
        const block = attributes[MAIL_BLOCK_ATTRIBUTE];
        if (isMailSignatureBackgroundComponent(component)) return 'Signatur · Zughintergrund';
        if (token === 'TRAIN_SRC') return 'Zugbild';
        if (preview === 'application') return 'Anwendungsinhalt (geschützt)';
        if (preview === 'signature') return 'Signatur-Platzhalter (geschützt)';
        if (token === 'ICON_RT_SRC') return 'RT-Zeichen (geschützt)';
        if (token === 'LOGO_SRC') return 'Firmenlogo';
        if (token?.startsWith?.('ICON_')) return 'Kontakt-Icon (geschützt)';
        if (classes.includes('rt-shell')) return 'Nachrichtenschale';
        if (classes.includes('rt-sign-content')) return 'Signatur-Inhalt';
        if (classes.includes('rt-sign-content-frame')) {
            return componentUsesFlowSafeSignatureTrain(component)
                ? 'Signatur-Inhalt im E-Mail-Fluss'
                : 'Fester Signatur-Inhaltsrahmen';
        }
        if (classes.includes('rt-sign-stage')) {
            return componentUsesFlowSafeSignatureTrain(component)
                ? 'E-Mail-sicherer Signaturfluss'
                : 'Signatur-Bühne';
        }
        if (classes.includes('rt-sign-train-layer')) return 'Zug-Bildebene';
        if (classes.includes('rt-sign-train-frame')) {
            return componentUsesFlowSafeSignatureTrain(component)
                ? 'Zugrahmen im E-Mail-Fluss'
                : 'Fester Zugrahmen';
        }
        if (classes.includes('rt-sign-train-slot')) return 'Zugposition unten';
        if (classes.includes('rt-sign-identity')) return 'Personendaten';
        if (classes.includes('rt-sign-logo')) return 'Firmenlogo';
        if (classes.includes('rt-sign-company')) return 'Firmendaten';
        if (classes.includes('rt-company-contact')) return 'Firmenkontakte';
        if (classes.includes('rt-contact')) return 'Personenkontakte';
        if (block) return `Inhalt: ${String(block).replaceAll('-', ' ')}`;
        return '';
    };
    const protect = (component, { layerable = false, stylable = false } = {}) => {
        if (!component || protectedComponents.has(component)) return;
        component.set?.({
            stylable,
            editable: false,
            draggable: false,
            removable: false,
            copyable: false,
            droppable: false,
            layerable,
        }, { silent: true });
        protectedComponents.add(component);
    };
    const protectBranch = (component, root = true) => {
        protect(component, { layerable: root });
        children(component).forEach((child) => protectBranch(child, false));
    };
    const visit = (component) => {
        const attributes = component?.getAttributes?.() || component?.get?.('attributes') || {};
        setName(component, label(component, attributes));
        const tagName = String(component?.get?.('tagName') || '').toLowerCase();
        const classes = classNames(attributes);
        if (tagName !== 'img' && componentUsesSignatureBackground(component)) {
            // These layouts intentionally have no fixed stage or overlap.
            // Do not offer geometry the save contract would have to reject.
            component.set?.({ stylable: MAIL_SAFE_EDITABLE_STYLE_PROPERTIES.filter((property) =>
                !['height', 'max-height', 'margin', 'display'].includes(property)) }, { silent: true });
        }
        if (isMailSignatureBackgroundComponent(component)) {
            synchronizeMailSignatureBackground(component);
            protect(component, { layerable: true });
            component.set?.({ selectable: true, hoverable: true }, { silent: true });
        }
        if (['rt-sign-stage', 'rt-sign-train-frame', 'rt-sign-train-slot', 'rt-sign-content-frame']
            .some((name) => classes.includes(name))) {
            synchronizeMailSignatureFlowGeometry(component);
            synchronizeMailSignatureFixedGeometry(component);
            protect(component, { layerable: true });
        }
        if (tagName === 'img' && !attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE]) {
            synchronizeMailContentImage(component);
            setName(component, String(attributes.alt || '').trim() || 'Inhaltsbild');
            component.set?.({
                traits: [
                    { type: 'text', name: 'alt', label: 'Alternativtext' },
                    { type: 'text', name: 'title', label: 'Beschreibung' },
                    { type: 'number', name: 'width', label: 'Breite in px', min: 40, max: 1200, step: 1 },
                    {
                        type: 'select',
                        name: 'data-rt-image-align',
                        label: 'Ausrichtung',
                        options: [
                            { id: 'left', name: 'Links' },
                            { id: 'center', name: 'Mittig' },
                            { id: 'right', name: 'Rechts' },
                        ],
                    },
                ],
            }, { silent: true });
        }
        if (['application', 'signature'].includes(attributes['data-rt-mail-preview-only'])) {
            protectBranch(component);
        }
        if (attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE] === 'ICON_RT_SRC') {
            protect(component, { layerable: false });
            let ancestor = component?.parent?.();
            while (ancestor) {
                const ancestorTagName = String(ancestor?.get?.('tagName') || '').toLowerCase();
                const isCell = ancestorTagName === 'td';
                protect(ancestor, { layerable: isCell });
                if (isCell) setName(ancestor, 'RT-Zeichen (geschützt)');
                if (ancestorTagName === 'td') break;
                ancestor = ancestor?.parent?.();
            }
        }
        if (attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE] === 'TRAIN_SRC'
            || attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE] === 'LOGO_SRC') {
            if (attributes[MAIL_PREVIEW_IMAGE_ATTRIBUTE] === 'TRAIN_SRC') {
                synchronizeMailSignatureFlowGeometry(component);
            }
            component.set?.({
                stylable: true,
                selectable: true,
                hoverable: true,
                layerable: true,
                removable: false,
                copyable: false,
            }, { silent: true });
        }
        if (attributes['data-rt-layer-train'] !== undefined
            || classes.includes('rt-sign-train-layer')) {
            component.set?.({
                traits: componentUsesFlowSafeSignatureTrain(component) ? [] : [
                    {
                        type: 'select',
                        name: 'data-rt-layer-align',
                        label: 'Desktop-Ausschnitt',
                        options: [
                            { id: 'left', name: 'Links' },
                            { id: 'center', name: 'Mittig' },
                            { id: 'right', name: 'Rechts' },
                        ],
                    },
                    {
                        type: 'select',
                        name: 'data-rt-layer-size',
                        label: 'Zugbreite',
                        options: [
                            { id: '100', name: '100 %' },
                            { id: '108.67', name: '108,67 % · Halt bei 65 %' },
                            { id: '125', name: '125 % · Standard' },
                            { id: '150', name: '150 %' },
                            { id: '200', name: '200 %' },
                        ],
                    },
                    {
                        type: 'select',
                        name: 'data-rt-layer-mobile',
                        label: 'Mobil-Ausschnitt',
                        options: [
                            { id: 'left', name: 'Links' },
                            { id: 'center', name: 'Mittig' },
                            { id: 'train', name: 'Groß · mobil angeschnitten' },
                            { id: 'stop65', name: 'Groß · Halt bei 65 %' },
                            { id: 'stop60', name: 'Groß · früher Halt bei 60 %' },
                            { id: 'right', name: 'Rechts' },
                        ],
                    },
                ],
            }, { silent: true });
            synchronizeMailSignatureFlowGeometry(component);
            synchronizeMailTrainLayerAlignment(component);
            component.set?.({ stylable: false, selectable: true, layerable: true }, { silent: true });
        }
        children(component).forEach(visit);
    };

    visit(root);
    return protectedComponents.size;
}

export function resolveMailPreviewDevice(device = 'desktop') {
    return MAIL_PREVIEW_DEVICES[device] || MAIL_PREVIEW_DEVICES.desktop;
}

/**
 * Ermittelt eine feste logische Mailclient-Breite und die dazu passende
 * Bildschirmdarstellung. Nur die Darstellung wird skaliert; innerWidth des
 * Canvas-Iframes bleibt exakt bei 1024, 820 oder 375 Pixeln.
 */
export function calculateMailPreviewGeometry({
    device = 'desktop',
    logicalWidth = null,
    hostWidth = 0,
    hostHeight = 0,
    inset = 24,
    minLogicalHeight = 560,
} = {}) {
    const selected = resolveMailPreviewDevice(device);
    const custom = logicalWidth !== null && logicalWidth !== undefined;
    const selectedWidth = custom
        ? normalizeMailPreviewWidth(logicalWidth, selected.width)
        : selected.width;
    const normalizedInset = Math.max(0, Number.isFinite(Number(inset)) ? Number(inset) : 0);
    const normalizedHostWidth = Math.max(0, Number.isFinite(Number(hostWidth)) ? Number(hostWidth) : 0);
    const normalizedHostHeight = Math.max(0, Number.isFinite(Number(hostHeight)) ? Number(hostHeight) : 0);
    const minimumHeight = Math.max(320, Number.isFinite(Number(minLogicalHeight)) ? Number(minLogicalHeight) : 560);
    const availableWidth = Math.max(0, normalizedHostWidth - (normalizedInset * 2));
    const availableHeight = Math.max(0, normalizedHostHeight - (normalizedInset * 2));
    // Eine frei gezogene Kante arbeitet wie ein echter Browser-Viewport bei
    // 100 %. Nur Presets werden bei Platzmangel als Ganzes eingepasst.
    const scale = custom
        ? 1
        : Math.max(0.01, Math.min(1, availableWidth > 0 ? availableWidth / selectedWidth : 1));
    const logicalHeight = Math.max(minimumHeight, availableHeight > 0 ? availableHeight / scale : minimumHeight);

    return Object.freeze({
        device: custom ? 'custom' : selected.id,
        label: custom ? 'Individuell' : selected.label,
        logicalWidth: selectedWidth,
        logicalHeight,
        hostWidth: normalizedHostWidth,
        hostHeight: normalizedHostHeight,
        availableWidth,
        availableHeight,
        scale,
        zoom: scale * 100,
        displayWidth: selectedWidth * scale,
        displayHeight: logicalHeight * scale,
    });
}

/**
 * Verbindet die Mailclient-Breite mit dem LMZ/GrapesJS-Canvas. Der Host wird
 * per ResizeObserver nachgefuehrt, wodurch Rotation, Split View und das
 * Ein-/Ausklappen der Seitenleiste ohne Reload korrekt bleiben.
 */
export function createMailPreviewController({
    instance,
    frame,
    device = 'desktop',
    inset = 24,
    minLogicalHeight = 560,
    onChange = null,
    environment = {},
} = {}) {
    const editor = instance?.editor;
    if (!editor || !frame) {
        throw new TypeError('Die Mail-Vorschau benoetigt eine LMZ-Editorinstanz und einen Host.');
    }

    const browserWindow = typeof window !== 'undefined' ? window : null;
    const requestFrame = environment.requestAnimationFrame
        || browserWindow?.requestAnimationFrame?.bind(browserWindow)
        || null;
    const cancelFrame = environment.cancelAnimationFrame
        || browserWindow?.cancelAnimationFrame?.bind(browserWindow)
        || null;
    const ResizeObserverClass = Object.prototype.hasOwnProperty.call(environment, 'ResizeObserver')
        ? environment.ResizeObserver
        : (browserWindow?.ResizeObserver || globalThis.ResizeObserver);
    let activeDevice = resolveMailPreviewDevice(device).id;
    let activeWidth = null;
    let observedHost = null;
    let scheduledFrame = null;
    let destroyed = false;
    let latestGeometry = null;
    let appliedHost = null;
    let appliedCanvasFrame = null;
    let controller = null;

    Object.values(MAIL_PREVIEW_DEVICES).forEach((previewDevice) => {
        const id = `rt-mail-${previewDevice.id}`;
        const attributes = {
            id,
            name: previewDevice.label,
            width: `${previewDevice.width}px`,
        };
        const existing = editor.DeviceManager?.get?.(id);

        if (existing?.set) existing.set(attributes);
        else if (!existing) editor.DeviceManager?.add?.(id, attributes);
    });

    const resolveHost = () => frame.querySelector?.('.lmz-builder__main') || frame;
    const observer = typeof ResizeObserverClass === 'function'
        ? new ResizeObserverClass(() => controller?.refresh())
        : null;

    const observeCurrentHost = () => {
        const host = resolveHost();
        if (host === observedHost) return host;

        observer?.disconnect?.();
        observer?.observe?.(host);
        observedHost = host;

        return host;
    };

    const applyGeometry = () => {
        scheduledFrame = null;
        if (destroyed) return;

        const host = observeCurrentHost();
        const measuredWidth = Number(host?.clientWidth) > 0 ? host.clientWidth : frame.clientWidth;
        const measuredHeight = Number(host?.clientHeight) > 0 ? host.clientHeight : frame.clientHeight;
        const nextGeometry = calculateMailPreviewGeometry({
            device: activeDevice,
            logicalWidth: activeWidth,
            hostWidth: measuredWidth,
            hostHeight: measuredHeight,
            inset,
            minLogicalHeight,
        });
        const canvasFrame = editor.Canvas?.getFrameEl?.() || null;
        const geometryUnchanged = latestGeometry
            && latestGeometry.device === nextGeometry.device
            && latestGeometry.logicalWidth === nextGeometry.logicalWidth
            && latestGeometry.logicalHeight === nextGeometry.logicalHeight
            && latestGeometry.hostWidth === nextGeometry.hostWidth
            && latestGeometry.hostHeight === nextGeometry.hostHeight
            && latestGeometry.scale === nextGeometry.scale;

        // ResizeObserver meldet auch Layoutfolgen unserer eigenen Canvas-
        // Anpassung. Ohne diesen Guard wird bei identischer Geometrie erneut
        // editor.refresh() ausgeloest, was wiederum die beobachtete Flaeche
        // invalidiert und den Browser in einer dauerhaften Renderkette halten
        // kann. Ein neues Host-/iframe-Element muss trotzdem initialisiert
        // werden, etwa nach einem echten Canvas-Reload.
        if (geometryUnchanged && host === appliedHost && canvasFrame === appliedCanvasFrame) {
            return;
        }

        latestGeometry = nextGeometry;
        appliedHost = host;
        appliedCanvasFrame = canvasFrame;

        const logicalWidth = `${latestGeometry.logicalWidth}px`;
        const logicalHeight = `${latestGeometry.logicalHeight}px`;
        frame.dataset.previewDevice = latestGeometry.device;
        frame.dataset.previewScale = String(latestGeometry.scale);
        frame.dataset.logicalWidth = String(latestGeometry.logicalWidth);
        frame.dataset.logicalHeight = String(latestGeometry.logicalHeight);
        frame.style?.setProperty?.('--rt-mail-logical-width', logicalWidth);
        frame.style?.setProperty?.('--rt-mail-logical-height', logicalHeight);
        frame.style?.setProperty?.('--rt-mail-display-width', `${latestGeometry.displayWidth}px`);
        frame.style?.setProperty?.('--rt-mail-display-height', `${latestGeometry.displayHeight}px`);
        frame.style?.setProperty?.('--rt-mail-scale', String(latestGeometry.scale));

        // Nicht nur die sichtbare Canvas-Huelle verkleinern: das echte iframe
        // muss die logische Clientbreite besitzen, damit window.innerWidth und
        // CSS-Media-Queries auf 375/820/1024/1920 px reagieren. GrapesJS setzt
        // diese Werte beim Devicewechsel asynchron; die expliziten Attribute
        // halten Wrapper und Layout-Viewport auch nach Resize/Frame-Reload
        // deckungsgleich.
        if (canvasFrame) {
            canvasFrame.setAttribute?.('width', String(latestGeometry.logicalWidth));
            canvasFrame.setAttribute?.('height', String(Math.ceil(latestGeometry.logicalHeight)));
            canvasFrame.style?.setProperty?.('width', logicalWidth, 'important');
            canvasFrame.style?.setProperty?.('height', logicalHeight, 'important');
        }

        editor.Canvas?.setZoom?.(latestGeometry.zoom);
        editor.refresh?.({ tools: true });
        editor.trigger?.('rt:mail:preview-resize', latestGeometry);
        onChange?.(latestGeometry);
    };

    const onFrameLoad = () => controller?.refresh();
    controller = {
        refresh() {
            if (destroyed) return;

            observeCurrentHost();
            if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
            if (requestFrame) scheduledFrame = requestFrame(applyGeometry);
            else applyGeometry();
        },

        setDevice(nextDevice) {
            activeDevice = resolveMailPreviewDevice(nextDevice).id;
            activeWidth = null;
            editor.setDevice?.(`rt-mail-${activeDevice}`);
            this.refresh();
        },

        setWidth(nextWidth) {
            activeWidth = normalizeMailPreviewWidth(
                nextWidth,
                latestGeometry?.logicalWidth || MAIL_PREVIEW_DEVICES.desktop.width,
            );
            activeDevice = 'custom';
            const id = 'rt-mail-custom';
            const attributes = {
                id,
                name: `Individuell (${activeWidth} px)`,
                width: `${activeWidth}px`,
            };
            const existing = editor.DeviceManager?.get?.(id);
            if (existing?.set) existing.set(attributes);
            else if (!existing) editor.DeviceManager?.add?.(id, attributes);
            editor.setDevice?.(id);
            this.refresh();

            return activeWidth;
        },

        getGeometry() {
            return latestGeometry;
        },

        destroy() {
            if (destroyed) return;
            destroyed = true;
            if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
            scheduledFrame = null;
            observer?.disconnect?.();
            observedHost = null;
            appliedHost = null;
            appliedCanvasFrame = null;
            editor.off?.('canvas:frame:load', onFrameLoad);
        },
    };

    editor.on?.('canvas:frame:load', onFrameLoad);
    controller.setDevice(activeDevice);

    return controller;
}

/** Auf kleinen Displays darf kein automatisch geoeffnetes LMZ-Panel die
 * ohnehin schmale Arbeitsflaeche verdecken. Bewusst nur beim Erststart: ein
 * spaeter vom Benutzer geoeffnetes Panel bleibt offen. */
export function closeInitialMailPopovers(root, mediaQuery) {
    if (!root || mediaQuery?.matches !== true) return 0;

    let closed = 0;
    root.querySelectorAll?.('[data-lmz-popover].is-open')?.forEach((popover) => {
        if (popover.hidden === true) return;

        const activePanel = popover.querySelector?.('[data-lmz-popover-panel].is-active:not([hidden])');
        const closeButton = activePanel?.querySelector?.('[data-lmz-panel-close]')
            || popover.querySelector?.('[data-lmz-panel-close]');
        if (!closeButton || closeButton.disabled) return;

        closeButton.click();
        closed += 1;
    });

    return closed;
}

export function scheduleInitialMailPopoverClose({
    root,
    mediaQuery,
    editor = null,
    isCurrent = () => true,
    fallbackDelay = 400,
    environment = {},
} = {}) {
    if (!root || mediaQuery?.matches !== true) return () => {};

    const browserWindow = typeof window !== 'undefined' ? window : null;
    const queueTask = environment.queueMicrotask
        || browserWindow?.queueMicrotask?.bind(browserWindow)
        || globalThis.queueMicrotask?.bind(globalThis)
        || ((callback) => Promise.resolve().then(callback));
    const requestFrame = environment.requestAnimationFrame
        || browserWindow?.requestAnimationFrame?.bind(browserWindow)
        || null;
    const cancelFrame = environment.cancelAnimationFrame
        || browserWindow?.cancelAnimationFrame?.bind(browserWindow)
        || null;
    const setTimer = environment.setTimeout
        || browserWindow?.setTimeout?.bind(browserWindow)
        || globalThis.setTimeout?.bind(globalThis);
    const clearTimer = environment.clearTimeout
        || browserWindow?.clearTimeout?.bind(browserWindow)
        || globalThis.clearTimeout?.bind(globalThis);
    const watchedEvents = ['canvas:frame:load', 'component:selected'];
    const seenEvents = new Set();
    const eventHandlers = new Map();
    let disposed = false;
    let scheduledFrame = null;
    let fallbackTimer = null;

    const cleanup = () => {
        if (disposed) return;
        disposed = true;
        if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
        if (fallbackTimer !== null && clearTimer) clearTimer(fallbackTimer);
        scheduledFrame = null;
        fallbackTimer = null;
        eventHandlers.forEach((handler, eventName) => editor?.off?.(eventName, handler));
        eventHandlers.clear();
    };

    const scheduleClose = (finish = false) => {
        if (disposed || !isCurrent()) {
            cleanup();
            return;
        }

        if (scheduledFrame !== null && cancelFrame) cancelFrame(scheduledFrame);
        const close = () => {
            scheduledFrame = null;
            if (disposed || !isCurrent()) {
                cleanup();
                return;
            }
            closeInitialMailPopovers(root, mediaQuery);
            if (finish) cleanup();
        };

        if (requestFrame) scheduledFrame = requestFrame(close);
        else close();
    };

    watchedEvents.forEach((eventName) => {
        const handler = () => {
            if (disposed) return;
            seenEvents.add(eventName);
            scheduleClose(seenEvents.size === watchedEvents.length);
        };
        eventHandlers.set(eventName, handler);
        editor?.on?.(eventName, handler);
    });

    queueTask(() => scheduleClose(false));
    if (setTimer) {
        fallbackTimer = setTimer(() => {
            fallbackTimer = null;
            scheduleClose(true);
        }, Math.max(0, Number(fallbackDelay) || 0));
    }

    return cleanup;
}

/**
 * Teilnehmer fuer den gemeinsamen RailTime-Navigationskoordinator. Kleine
 * Aenderungen werden so auch vor Ablauf des 10-Sekunden-Autosaves gesichert;
 * bei einem Fehler bleibt die aktuelle Seite offen.
 */
export function createMailNavigationController({
    getBuilder = () => null,
    onSaving = null,
    onSaved = null,
    onFlushError = null,
} = {}) {
    return {
        hasPendingWork() {
            return Boolean(getBuilder()?.hasUnsavedChanges?.());
        },

        async flush() {
            const builder = getBuilder();
            if (!builder?.hasUnsavedChanges?.()) return true;

            onSaving?.();
            // LMZ 2.4.5 stabilisiert nur manuelle/Shortcut-Saves bis keine
            // waehrend des Requests neu eingetroffene Aenderung mehr offen
            // ist. Ein eigener "navigation"-Grund koennte dagegen true
            // liefern und trotzdem einen spaeteren Dirty-Stand verlieren.
            const saved = await builder.save?.('manual');
            if (!saved) {
                throw new Error('Offene Änderungen konnten vor dem Seitenwechsel nicht gespeichert werden.');
            }
            onSaved?.();

            return true;
        },

        onFlushError(error) {
            onFlushError?.(error);
        },
    };
}

/** Textauswahl oeffnet nur im Mail-Modus die vorhandenen Schriftsteuerungen. */
export function installMailTypographyFocus(editor, chrome, { readOnly = false } = {}) {
    if (readOnly) return () => {};
    let disposed = false;
    const onSelected = (selected) => {
        const type = String(selected?.get?.('type') || '').toLowerCase();
        const tag = String(selected?.get?.('tagName') || '').toLowerCase();
        const stylable = selected?.get?.('stylable');
        const isText = selected?.is?.('text') || type === 'text'
            || ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'blockquote'].includes(tag);
        if (!isText || stylable === false
            || (Array.isArray(stylable) && !stylable.includes('font-family'))) return;

        // Der gemeinsame Inspector bevorzugt Eigenschaften. Erst nach dessen
        // Auswahlabgleich auf Stile wechseln; weder Auswahl noch Text aendern.
        queueMicrotask(() => {
            if (disposed || editor.getSelected?.() !== selected) return;
            editor.StyleManager?.getSector?.('rt-mail-typography')?.set?.('open', true);
            chrome.openPanel?.('styles');
        });
    };
    editor.on?.('component:selected', onSelected);
    return () => {
        disposed = true;
        editor.off?.('component:selected', onSelected);
    };
}

/**
 * Startet den Builder im E-Mail-Modus.
 *
 * @param {object}   options
 * @param {object}   options.runtime            window.LMZBuilder
 * @param {Element|string} options.root         Wurzelknoten; sein Inhalt wird geleert
 * @param {string}   options.projectId          nur Durchreichung an onLoad/onSave
 * @param {object}   options.vendor             { coreJs, coreCss }
 * @param {object}   options.storage            { onLoad, onSave } — Pflicht
 * @param {Array}    options.assets             Bildliste fuer den Asset-Manager
 * @param {object}   options.blockOptions       Durchreichung an createMailBlocks
 * @param {'light'|'dark'} options.theme        Vorschaufarben der Leinwand
 * @param {object}   options.previewAssets      lokale Logo-/Zug-/Iconquellen nur fuer das iframe
 * @param {object}   options.previewResponsiveCss zentrale responsive Regeln nur fuer das iframe
 * @param {object}   options.previewThemeValues serverautoritative Farben nur fuer das iframe
 * @param {object}   options.compatibilityManifest sicherer UI-Ausschnitt des CSV-Regelkatalogs
 * @param {'desktop'|'tablet'|'mobile'} options.previewDevice Vorschau-Mailclient
 * @param {Function|null} options.onPreviewChange Meldung neuer Vorschaugeometrie
 * @param {boolean}  options.readOnly           kein Speichern, kein Hochladen
 * @param {object}   options.autosave           Abweichende Autosave-Einstellung
 * @param {string[]} options.canvasBodyClasses  Zusaetzliche Klassen am iframe-body
 */
export async function createMailBuilder({
    runtime,
    root,
    projectId = 'mail',
    vendor = {},
    storage = {},
    assets = [],
    blockOptions = {},
    theme = 'light',
    previewAssets = {},
    previewResponsiveCss = {},
    previewThemeValues = {},
    compatibilityManifest = {},
    previewDevice = 'desktop',
    onPreviewChange = null,
    readOnly = false,
    autosave = MAIL_AUTOSAVE,
    canvasBodyClasses = [],
    assistantContext = {},
} = {}) {
    if (typeof runtime?.create !== 'function') {
        throw new TypeError('Der E-Mail-Editor benoetigt die LMZBuilder-Laufzeit.');
    }

    if (!root) {
        throw new TypeError('Der E-Mail-Editor benoetigt einen Wurzelknoten.');
    }

    if (typeof storage.onLoad !== 'function' || typeof storage.onSave !== 'function') {
        throw new TypeError('Der E-Mail-Editor benoetigt storage.onLoad und storage.onSave.');
    }

    await waitForPageBuilderActivation(root);

    let activeTheme = theme === 'dark' ? 'dark' : 'light';
    const normalizedCompatibilityManifest = normalizeMailCompatibilityManifest(compatibilityManifest);
    const responsiveCssForTheme = (selectedTheme) => String(
        previewResponsiveCss?.[selectedTheme === 'dark' ? 'dark' : 'light'] || '',
    );
    let canvasCss = mailCanvasStyles(activeTheme, previewAssets, responsiveCssForTheme(activeTheme), previewThemeValues);

    const instance = await runtime.create({
        root,
        projectId,
        gjsScript: vendor.coreJs,
        gjsStyle: vendor.coreCss,

        // Ohne die ausdrueckliche Leerung laedt der Builder das Website-
        // Tailwind in die Leinwand und haengt es alle zwei Sekunden erneut
        // ein. In einer Mail gibt es kein Tailwind — der Editor wuerde etwas
        // anderes zeigen als der Empfaenger spaeter sieht.
        canvasStyles: [],
        canvasScripts: [],
        canvasBaseUrl: window.location.origin + '/',
        canvasBodyClasses: [MAIL_CANVAS_BODY_CLASS, ...canvasBodyClasses],
        canvasLanguage: 'de',

        motion: { enabled: false, preview: false },
        // Der Mail-Builder verwendet weder Joomla-Embeds noch globale
        // Website-Elemente. Zusammen mit deaktiviertem Motion kann die
        // gemeinsame Runtime dadurch die komplette Trait-Hydrierung auslassen.
        integrations: false,
        autosave,

        blocks: {
            // Sonst kommen die vier Joomla-Standardbloecke dazu — mit
            // Tailwind-Klassen und einem Bildpfad der Website.
            addDefault: false,
            custom: createMailBlocks(blockOptions),
        },

        storage: {
            onLoad: async (context) => normalizeMailProject(await storage.onLoad(context)),
            onSave: (context) => storage.onSave(context),
        },

        assets: {
            onLoad: async () => (Array.isArray(assets) ? assets : []),
            // onUpload bewusst weggelassen: Bilder kommen aus der
            // Dateiverwaltung, nicht aus dem Editor.
        },

        useStudioWebDefaults: false,
        // Lieber ein harter Fehler als stillschweigend die Joomla-Demoseite.
        allowFallbackProject: false,

        gjsOptions: MAIL_GJS_OPTIONS,
    });

    const editor = instance.editor;
    const onComponentAdd = (component) => {
        const marked = markAddedMailComponentStyles(editor, component);
        protectMailSystemComponents(editor);
        // Bei per Block eingefuegtem HTML entsteht die cNN-Regel je nach
        // Komponententyp erst direkt nach component:add.
        if (!marked) {
            globalThis.queueMicrotask?.(() => {
                markAddedMailComponentStyles(editor, component);
                protectMailSystemComponents(editor);
            });
        }
    };
    const onComponentUpdate = (component) => {
        const background = isMailSignatureBackgroundComponent(component);
        synchronizeMailSignatureBackground(component);
        synchronizeMailSignatureFlowGeometry(component);
        synchronizeMailSignatureFixedGeometry(component);
        synchronizeMailTrainLayerAlignment(component);
        synchronizeMailContentImage(component);
        if (background) globalThis.queueMicrotask?.(() => hydrateMailCanvasAssets(editor, activeTheme, previewAssets));
    };
    const onComponentStyleUpdate = (component, changes = {}) => {
        const styleChanges = changes?.style && typeof changes.style === 'object'
            ? changes.style
            : changes;
        const properties = styleChanges && typeof styleChanges === 'object'
            ? Object.keys(styleChanges)
            : [];
        (properties.length > 0 ? properties : ['']).forEach((property) => {
            synchronizeMailPresentationAttributes(component, property);
        });
        synchronizeMailContentImage(component);
    };
    editor.on?.('component:add', onComponentAdd);
    editor.on?.('component:update', onComponentUpdate);
    editor.on?.('component:styleUpdate', onComponentStyleUpdate);
    protectMailSystemComponents(editor);
    const rootElement = typeof root === 'string' ? document.querySelector(root) : root;
    const frame = rootElement?.closest?.('[data-mail-editor-frame]') || null;
    let degradationOverlay = null;
    let activeDegradationMode = 'normal';
    const removeDegradationOverlay = () => {
        degradationOverlay?.remove?.();
        degradationOverlay = null;
        frame?.removeAttribute?.('data-mail-degradation-active');
    };
    const currentCanvasDocumentHtml = () => {
        const canvasDocument = editor.Canvas?.getDocument?.();
        const documentElement = canvasDocument?.documentElement;

        if (!documentElement?.outerHTML) {
            throw new Error('Die aktuelle Mail-Leinwand ist für die Robustheitsvorschau noch nicht bereit.');
        }

        const doctype = canvasDocument.doctype?.name
            ? `<!doctype ${canvasDocument.doctype.name}>`
            : '<!doctype html>';

        return `${doctype}${documentElement.outerHTML}`;
    };
    const renderDegradationOverlay = (nextMode = activeDegradationMode) => {
        activeDegradationMode = normalizeMailDegradationMode(nextMode);
        if (activeDegradationMode === 'normal') {
            removeDegradationOverlay();

            return createMailDegradationPreview('<!doctype html><html><head></head><body></body></html>', 'normal', {
                environment: window,
            });
        }

        if (!frame) {
            throw new Error('Die Robustheitsvorschau benötigt den Mail-Vorschaurahmen.');
        }

        const previewResult = createMailDegradationPreview(
            currentCanvasDocumentHtml(),
            activeDegradationMode,
            { environment: window },
        );

        if (!degradationOverlay) {
            degradationOverlay = window.document.createElement('iframe');
            degradationOverlay.className = 'rt-mail-degradation-preview';
            degradationOverlay.setAttribute('sandbox', '');
            degradationOverlay.setAttribute('data-mail-degradation-preview', '');
            frame.appendChild(degradationOverlay);
        }

        degradationOverlay.title = `${previewResult.label}: ${previewResult.disclaimer}`;
        degradationOverlay.setAttribute('aria-label', degradationOverlay.title);
        degradationOverlay.srcdoc = previewResult.html;
        frame.setAttribute('data-mail-degradation-active', activeDegradationMode);

        return previewResult;
    };
    const preview = frame
        ? createMailPreviewController({
            instance,
            frame,
            device: previewDevice,
            onChange: onPreviewChange,
        })
        : null;
    const onFrameLoad = () => {
        applyMailCanvasStyles(editor, canvasCss);
        hydrateMailCanvasPlaceholders(editor);
        hydrateMailCanvasAssets(editor, activeTheme, previewAssets);
        preview?.refresh();
        if (activeDegradationMode !== 'normal') renderDegradationOverlay();
    };
    let projectPreviewFrame = null;
    let previewDisposed = false;
    const onProjectLoad = () => {
        // Loading canonical project data can reuse the existing iframe. Its
        // document then receives new neutral images without frame:load firing.
        globalThis.queueMicrotask?.(() => {
            if (previewDisposed) return;
            onFrameLoad();
            if (projectPreviewFrame !== null) globalThis.cancelAnimationFrame?.(projectPreviewFrame);
            projectPreviewFrame = globalThis.requestAnimationFrame?.(() => {
                projectPreviewFrame = null;
                if (!previewDisposed) onFrameLoad();
            }) ?? null;
        });
    };

    applyMailCanvasStyles(editor, canvasCss);
    hydrateMailCanvasPlaceholders(editor);
    hydrateMailCanvasAssets(editor, activeTheme, previewAssets);
    editor.on?.('canvas:frame:load', onFrameLoad);
    editor.on?.('project:load', onProjectLoad);

    removeBuilderControls(rootElement, MOTION_CONTROL_SELECTORS);

    // setActionLocked ist KEIN Nur-Lese-Modus: es unterbindet nur Autosave
    // und Strg+S. Die Leinwand bleibt bearbeitbar und ein Klick auf
    // "Speichern" ginge weiterhin durch — deshalb zusaetzlich die Schalter
    // entfernen und in save() selbst abfangen.
    instance.setActionLocked(readOnly);

    if (readOnly) {
        removeBuilderControls(rootElement, WRITE_CONTROL_SELECTORS);
    }

    const mobileMedia = typeof window !== 'undefined'
        ? window.matchMedia?.('(max-width: 899.98px)')
        : null;
    const cancelInitialPopoverClose = scheduleInitialMailPopoverClose({
        root: rootElement,
        mediaQuery: mobileMedia,
        editor,
        isCurrent: () => Boolean(instance?.editor),
    });

    const editorChrome = createLmzEditorChrome({
        instance,
        root: rootElement,
        mode: MAIL_EDITOR_MODE,
        layout: 'elementor',
        editingPolicy: normalizedCompatibilityManifest.editingPolicy,
        active: pageBuilderWorkspaceIsActive(rootElement),
        capabilities: {
            writable: !readOnly,
            media: true,
            mediaInsert: false,
            imageReplace: 'tokens-only',
            animation: false,
            gifControls: true,
            spacing: !readOnly,
        },
        media: {
            tokenMedia: () => mailTokenMediaDefinitions(previewAssets, activeTheme),
            assets: () => (Array.isArray(assets) ? assets : []),
            baseUrl: window.location.origin + '/',
        },
    });
    // Die gemeinsame Chrome-Haertung sperrt Systemkomponenten strukturell.
    protectMailSystemComponents(editor);
    const detachTypographyFocus = installMailTypographyFocus(editor, editorChrome, { readOnly });
    let shellLifecycle = null;
    let assistantAdapter = null;
    let disposed = false;
    const api = {
        instance,
        editor,
        mode: MAIL_EDITOR_MODE,
        readOnly,
        compatibilityManifest: normalizedCompatibilityManifest,

        /**
         * Speichern.
         *
         * Der Builder faengt Fehler aus onSave selbst ab und liefert dann
         * false — ohne Auswertung des Rueckgabewerts haelt man einen
         * abgelehnten Speichervorgang faelschlich fuer erfolgreich.
         *
         * @returns {Promise<boolean>}
         */
        async save(reason = 'manual') {
            if (readOnly) {
                return false;
            }

            return instance.save(reason);
        },

        hasUnsavedChanges: () => Boolean(instance.hasUnsavedChanges?.()),

        /** Vorschau-Mailclient wechseln, ohne das Dokument umzuschreiben. */
        setPreviewDevice(nextDevice) {
            preview?.setDevice(nextDevice);
            return preview?.getGeometry() || null;
        },

        /** Ganzzahlige, frei gezogene Clientbreite ohne Dokumentmutation. */
        setPreviewWidth(nextWidth) {
            preview?.setWidth(nextWidth);
            return preview?.getGeometry() || null;
        },

        getPreviewGeometry: () => preview?.getGeometry() || null,

        /**
         * Rein heuristische Robustheitsansicht. Das isolierte, skriptlose
         * iframe verändert weder GrapesJS-Modell noch gespeichertes HTML.
         */
        setDegradationMode(nextMode = 'normal') {
            return renderDegradationOverlay(nextMode);
        },

        /** Dieselbe skriptlose Degradation auf bereits kompiliertes HTML. */
        createDegradationPreview(html, nextMode = 'normal') {
            return createMailDegradationPreview(html, nextMode, { environment: window });
        },

        getDegradationMode: () => activeDegradationMode,

        /** Alle Marken-GIFs auf der Leinwand ohne Modellmutation neu starten. */
        restartAllGifs() {
            return restartMailCanvasAnimations(editor);
        },

        /**
         * Eine Aktion der im Modal-Kopf liegenden Single-Toolbar ausfuehren.
         * Die Vendor-Topbar bleibt nur als interner Zustandsanker im DOM;
         * deshalb werden Befehle hier direkt und nicht ueber versteckte
         * Klickziele ausgeloest.
         */
        runEditorAction(action) {
            const command = String(action || '').trim();

            if (command === 'assets') {
                editorChrome.openMedia({ initialTab: 'used' });
                return true;
            }

            if (command === 'undo' || command === 'redo') {
                if (readOnly) return false;
                editor.runCommand?.(`core:${command}`);
                return true;
            }

            if (command === 'preview') {
                const active = Boolean(editor.Commands?.isActive?.('core:preview'));
                if (active) editor.stopCommand?.('core:preview');
                else editor.runCommand?.('core:preview');
                return true;
            }

            return false;
        },

        /** Linke bzw. rechte Builder-Seitenleiste aus der Single-Toolbar. */
        openEditorPanel(panel) {
            const aliases = {
                'left:blocks': 'blocks',
                'left:layers': 'layers',
                'right:styles': 'styles',
                'right:traits': 'traits',
                'right:classes': 'classes',
            };

            return editorChrome.openPanel(aliases[String(panel || '')] || panel);
        },

        /** Nicht nutzbare Kontextschalter werden auch in der Kopfzeile verborgen. */
        isEditorPanelAvailable(panel) {
            const panelIds = {
                blocks: 'left:blocks',
                layers: 'left:layers',
                styles: 'right:styles',
                traits: 'right:traits',
                classes: 'right:classes',
                'left:blocks': 'left:blocks',
                'left:layers': 'left:layers',
                'right:styles': 'right:styles',
                'right:traits': 'right:traits',
                'right:classes': 'right:classes',
            };
            const panelId = panelIds[String(panel || '')];
            const toggle = panelId
                ? rootElement.querySelector(`[data-lmz-panel-toggle="${panelId}"]`)
                : null;

            return Boolean(
                toggle
                && !toggle.hidden
                && !toggle.inert
                && toggle.getAttribute('aria-disabled') !== 'true'
            );
        },

        /** Leinwandfarben wechseln; die Wahl wird nicht mitgespeichert. */
        setTheme(nextTheme = activeTheme) {
            activeTheme = nextTheme === 'dark' ? 'dark' : 'light';
            canvasCss = mailCanvasStyles(activeTheme, previewAssets, responsiveCssForTheme(activeTheme), previewThemeValues);
            const stylesApplied = applyMailCanvasStyles(editor, canvasCss);
            hydrateMailCanvasAssets(editor, activeTheme, previewAssets);
            return stylesApplied;
        },

        /** Rueckwaertskompatibler Name fuer bestehende Einbindungen. */
        refreshCanvasStyles(nextTheme = activeTheme) {
            return this.setTheme(nextTheme);
        },

        destroy() {
            if (disposed) return;
            disposed = true;
            cancelInitialPopoverClose();
            shellLifecycle?.destroy();
            shellLifecycle = null;
            assistantAdapter?.destroy();
            assistantAdapter = null;
            detachTypographyFocus();
            editorChrome.destroy();
            editor.off?.('component:add', onComponentAdd);
            editor.off?.('component:update', onComponentUpdate);
            editor.off?.('component:styleUpdate', onComponentStyleUpdate);
            editor.off?.('canvas:frame:load', onFrameLoad);
            editor.off?.('project:load', onProjectLoad);
            previewDisposed = true;
            if (projectPreviewFrame !== null) globalThis.cancelAnimationFrame?.(projectPreviewFrame);
            projectPreviewFrame = null;
            removeDegradationOverlay();
            preview?.destroy();
            instance.destroy?.();
        },
    };
    const inferredKind = (() => {
        try {
            const value = new URL(window.location.href).searchParams.get('dokument');
            return value === 'signature' ? 'signature' : 'template';
        } catch {
            return 'template';
        }
    })();
    const embeddedAssistantDocument = (() => {
        try {
            const script = rootElement?.closest?.('[data-mail-document-studio]')?.querySelector?.('[data-mail-document-config]');
            const config = JSON.parse(script?.textContent || '{}');
            return config.documents?.[config.currentDocument] || {};
        } catch {
            return {};
        }
    })();
    assistantAdapter = createLmzAssistantAdapter({
        root: rootElement,
        instance,
        chrome: editorChrome,
        mode: MAIL_EDITOR_MODE.id,
        routeName: 'admin.mail-documents.editor',
        resourceId: assistantContext.resourceId || embeddedAssistantDocument.id || String(projectId).replace(/^mail:/, ''),
        formatOrKind: typeof assistantContext.formatOrKind === 'function'
            ? assistantContext.formatOrKind
            : () => assistantContext.formatOrKind || inferredKind,
        persistedHash: typeof assistantContext.persistedHash === 'function'
            ? assistantContext.persistedHash
            : () => assistantContext.persistedHash || embeddedAssistantDocument.contentHash || '',
        persistedVersion: typeof assistantContext.persistedVersion === 'function'
            ? assistantContext.persistedVersion
            : () => assistantContext.persistedVersion || embeddedAssistantDocument.version || 0,
        readOnly,
        availableBlockIds: [
            'rt-mail-section', 'rt-mail-two-columns', 'rt-mail-heading',
            'rt-mail-paragraph', 'rt-mail-button', 'rt-mail-divider', 'rt-mail-spacer',
        ],
        save: () => api.save('manual'),
    });
    shellLifecycle = createPageBuilderLifecycleController({
        root: rootElement,
        getBuilder: () => api,
        onOpen: () => {
            editorChrome.open();
            preview?.refresh();
        },
        onClose: () => editorChrome.close(),
        onError: (error) => window.dispatchEvent(new CustomEvent('swal:toast', {
            detail: {
                type: 'error',
                title: 'Editor bleibt geöffnet',
                text: error?.message || 'Offene Änderungen konnten nicht gespeichert werden.',
            },
        })),
    });

    return api;
}
