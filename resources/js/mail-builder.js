import {
    MAIL_BLOCK_ATTRIBUTE,
    SIGNATURE_PLACEHOLDER,
    createMailBlocks,
    mailCanvasStyles,
} from './mail-builder-blocks.js';
import {
    createLmzEditorChrome,
    createLmzAssistantAdapter,
    createPageBuilderLifecycleController,
    componentAnimationContext,
    handleScopedRtePaste,
    pageBuilderWorkspaceIsActive,
    refreshPausedAnimatedPreviewElement,
    restartAnimatedPreview,
    waitForPageBuilderActivation,
} from './lmz-editor-core.js';

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
const MAIL_PREVIEW_TRAIN_ATTRIBUTE = 'data-rt-mail-preview-train';
const MAIL_IMPORTED_INLINE_ATTRIBUTE = 'data-rt-mail-inline-source';
const MAIL_PREVIEW_TRANSPARENT_PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
const MAIL_PREVIEW_TRAIN_PLACEHOLDER = 'about:blank#rt-mail-train-preview';
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

function mailPreviewAssetSources(previewAssets = {}) {
    return [
        MAIL_PREVIEW_TRANSPARENT_PIXEL,
        MAIL_PREVIEW_TRAIN_PLACEHOLDER,
        previewAssets?.light?.logo,
        previewAssets?.light?.train,
        previewAssets?.dark?.logo,
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

function markSignatureTrainPreviews(signature) {
    let tokenCount = 0;
    const transformed = signature.replace(/<[a-z][^<>]*>/gi, (tag) => {
        const occurrences = tag.match(/\{\{TRAIN_SRC\}\}/g)?.length || 0;
        if (occurrences === 0) return tag;

        const styleContainsToken = Array.from(tag.matchAll(/\bstyle\s*=\s*(["'])([\s\S]*?)\1/gi))
            .some((match) => match[2].includes('{{TRAIN_SRC}}'));
        if (!styleContainsToken || new RegExp(`\\b${MAIL_PREVIEW_TRAIN_ATTRIBUTE}\\b`, 'i').test(tag)) {
            throw new Error('Das Zugmotiv der Signatur besitzt keine eindeutig reversible Style-Bindung.');
        }

        tokenCount += occurrences;
        // Kein data:-URI im style-Attribut: GrapesJS trennt dort am
        // Semikolon nach `image/png` und zerstoert die Deklaration bereits
        // beim reinen Laden. Die about:-Adresse ist syntaktisch stabil und
        // wird niemals gespeichert.
        const previewTag = tag.replaceAll('{{TRAIN_SRC}}', MAIL_PREVIEW_TRAIN_PLACEHOLDER);

        return previewTag.replace(/\/?>$/, (ending) => ` ${MAIL_PREVIEW_TRAIN_ATTRIBUTE}="TRAIN_SRC"${ending}`);
    });

    if (tokenCount !== 1 || transformed.includes('{{TRAIN_SRC}}')) {
        throw new Error('Die Signatur benoetigt genau ein vollstaendig gebundenes RailTime-Zugmotiv.');
    }

    return transformed;
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
    desktop: Object.freeze({ id: 'desktop', label: 'Desktop', width: 1024 }),
    tablet: Object.freeze({ id: 'tablet', label: 'Tablet', width: 820 }),
    mobile: Object.freeze({ id: 'mobile', label: 'Mobil', width: 375 }),
});

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
 * Enthaelt ausschliesslich Eigenschaften, die in den funktionierenden
 * RailTime-Vorlagen vorkommen und die ein Mailclient zuverlaessig umsetzt.
 * Nicht dabei und auch nicht nachtraeglich ergaenzen:
 *   - float/clear, flex-*, grid-*        zerlegen jedes Tabellenlayout
 *   - position/z-index                   in der Signatur noetig, dort aber
 *                                        serverseitig gesetzt; im Editor
 *                                        waere es nur eine Fehlerquelle
 *   - transform/transition/animation     Outlook kennt nichts davon
 *   - box-shadow/text-shadow             faellt in Outlook ersatzlos weg
 *
 * Zur Bauform: ein Sektor liest ENTWEDER buildProps ODER properties, nie
 * beides (grapesjs.js, Sector-Konstruktor). 'display' braucht eine eigene
 * Werteliste, weil die eingebaute Fassung flex anbietet — deshalb steht der
 * Fluss-Sektor auf properties mit { extend: 'display', options: [...] }.
 */
export const MAIL_STYLE_SECTORS = Object.freeze([
    Object.freeze({
        id: 'rt-mail-typography',
        name: 'Schrift',
        open: true,
        buildProps: [
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
        ],
    }),
    Object.freeze({
        id: 'rt-mail-spacing',
        name: 'Abstand & Größe',
        open: true,
        buildProps: [
            'padding',
            'padding-top',
            'padding-right',
            'padding-bottom',
            'padding-left',
            'margin',
            'margin-top',
            'margin-right',
            'margin-bottom',
            'margin-left',
            'width',
            'max-width',
            'height',
            'max-height',
            'box-sizing',
        ],
    }),
    Object.freeze({
        id: 'rt-mail-surface',
        name: 'Fläche',
        open: false,
        // 'background' als reine Farbe statt der eingebauten Ebenen-Fassung:
        // die Allowlist des Sanitizers kennt kein background-color, und
        // Hintergrundbilder faellt Outlook ohnehin weg. Der Bestand setzt
        // ebenfalls durchgaengig 'background:<Farbe>'.
        properties: [
            { property: 'background', name: 'Hintergrund', type: 'color' },
            { extend: 'opacity' },
        ],
    }),
    Object.freeze({
        id: 'rt-mail-border',
        name: 'Rahmen',
        open: false,
        buildProps: [
            'border',
            'border-top',
            'border-right',
            'border-bottom',
            'border-left',
            'border-radius',
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
            'vertical-align',
            'border-collapse',
            'direction',
            'overflow',
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

        const placeholderCount = page.component.split(SIGNATURE_PLACEHOLDER).length - 1;
        if (placeholderCount !== 1) {
            throw new Error('Die Mailvorlage benötigt genau einen gebundenen Signaturblock.');
        }

        const parsed = domParserFor(options.environment || globalThis)
            .parseFromString(page.component.replace(SIGNATURE_PLACEHOLDER, MAIL_TEMPLATE_SIGNATURE_PREVIEW), 'text/html');
        if (!parsed.head || !parsed.body || !parsed.documentElement) {
            throw new Error('Die Mailvorlage besitzt keine vollständige HTML-Struktur.');
        }

        // GrapesJS editiert ausschließlich den Body. Head und Dokumenthülle
        // werden beim Speichern aus der letzten kanonischen Serverfassung
        // rekonstruiert und können dadurch nicht still verloren gehen.
        markMailPreviewImageTokens(parsed.body);
        markImportedInlineStyles(parsed.body);
        page.component = parsed.body.innerHTML;
    }

    if (options.kind === 'signature') {
        if (!page || typeof page.component !== 'string') {
            throw new Error('Der Signatur-Editor benötigt ein kanonisches HTML-Fragment.');
        }

        let signature = page.component;
        MAIL_PREVIEW_IMAGE_TOKENS.forEach((token) => {
            const source = new RegExp(`src=(["'])\\{\\{${token}\\}\\}\\1`, 'gi');
            signature = signature.replace(
                source,
                `${MAIL_PREVIEW_IMAGE_ATTRIBUTE}="${token}" src="${MAIL_PREVIEW_TRANSPARENT_PIXEL}"`,
            );
        });
        signature = markSignatureTrainPreviews(signature);

        const parsed = parseMailFragment(
            domParserFor(options.environment || globalThis),
            `<table ${MAIL_SIGNATURE_CANVAS_ATTRIBUTE}="true" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;"><tbody>${signature}</tbody></table>`,
        );
        const wrapper = parsed.querySelector(`table[${MAIL_SIGNATURE_CANVAS_ATTRIBUTE}]`);
        const body = wrapper?.tBodies?.[0] || wrapper?.querySelector('tbody');
        const rows = Array.from(body?.children || []);
        const trainCarriers = wrapper?.querySelectorAll?.(`[${MAIL_PREVIEW_TRAIN_ATTRIBUTE}]`) || [];
        const trainCarrier = trainCarriers[0];
        if (!wrapper || !body || rows.length !== 2
            || rows.some((row) => row.tagName !== 'TR')
            || trainCarriers.length !== 1
            || trainCarrier?.tagName !== 'TD'
            || !trainCarrier.classList.contains('rt-sign-cell')
            || trainCarrier.parentElement !== rows[0]) {
            throw new Error('Die Signatur benoetigt zwei Tabellenzeilen und genau ein gebundenes Zugmotiv in ihrer Hauptflaeche.');
        }

        const trainRow = parsed.createElement('tr');
        trainRow.setAttribute('data-rt-mail-preview-only', 'train');
        const trainCell = parsed.createElement('td');
        trainCell.setAttribute('align', 'left');
        trainCell.setAttribute('style', 'padding:0;text-align:left;font-size:0;line-height:0;');
        const trainImage = parsed.createElement('img');
        trainImage.setAttribute('class', 'rt-sign-train');
        trainImage.setAttribute('data-rt-train', '');
        trainImage.setAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE, 'TRAIN_SRC');
        trainImage.setAttribute('src', MAIL_PREVIEW_TRANSPARENT_PIXEL);
        trainImage.setAttribute('width', '100%');
        trainImage.setAttribute('alt', '');
        trainImage.setAttribute('style', 'display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;');
        trainCell.appendChild(trainImage);
        trainRow.appendChild(trainCell);
        body.insertBefore(trainRow, rows[1]);

        markImportedInlineStyles(wrapper);
        page.component = wrapper.outerHTML;
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
        restoreMailPreviewImageTokens(canvasDocument.body);

        const canonicalDocument = parser.parseFromString(String(baselineHtml || ''), 'text/html');
        if (!canonicalDocument.documentElement || !canonicalDocument.head || !canonicalDocument.body) {
            throw new Error('Die kanonische Dokumenthülle der Mailvorlage fehlt.');
        }

        const previewRowHtml = previewRows[0].outerHTML;
        const canonicalBody = canvasDocument.body.innerHTML.replace(previewRowHtml, SIGNATURE_PLACEHOLDER);
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
    const trainPreviewRow = trainPreviewRows[0];
    if (trainPreviewRows.length !== 1
        || trainPreviewRow?.parentElement !== body
        || trainPreviewRow !== body?.children?.[1]
        || trainPreviewRow.querySelectorAll?.(`img.rt-sign-train[data-rt-train][${MAIL_PREVIEW_IMAGE_ATTRIBUTE}="TRAIN_SRC"]`).length !== 1) {
        throw new Error('Die sichere Zugvorschau des Signatur-Editors fehlt.');
    }
    trainPreviewRow.remove();
    const rows = Array.from(body?.children || []);
    const trainCarriers = wrapper?.querySelectorAll?.(`[${MAIL_PREVIEW_TRAIN_ATTRIBUTE}]`) || [];
    const trainCarrier = trainCarriers[0];
    if (wrappers.length !== 1 || !wrapper || !body
        || rows.length !== 2 || rows.some((row) => row.tagName !== 'TR')
        || trainCarriers.length !== 1
        || trainCarrier?.tagName !== 'TD'
        || !trainCarrier.classList.contains('rt-sign-cell')
        || trainCarrier.parentElement !== rows[0]) {
        throw new Error('Die sichere Tabellenstruktur des Signatur-Editors fehlt.');
    }

    wrapper.querySelectorAll(`[${MAIL_PREVIEW_IMAGE_ATTRIBUTE}]`).forEach((image) => {
        const token = image.getAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE);
        if (image.tagName !== 'IMG' || !MAIL_PREVIEW_IMAGE_TOKENS.includes(token)) {
            throw new Error('Der Signatur-Editor enthält einen unbekannten Bildplatzhalter.');
        }
        image.setAttribute('src', `{{${token}}}`);
        image.removeAttribute(MAIL_PREVIEW_IMAGE_ATTRIBUTE);
    });

    wrapper.querySelectorAll(`[${MAIL_PREVIEW_TRAIN_ATTRIBUTE}]`).forEach((cell) => {
        let style = String(cell.getAttribute('style') || '')
            .replaceAll(MAIL_PREVIEW_TRAIN_PLACEHOLDER, '{{TRAIN_SRC}}')
            .replaceAll(MAIL_PREVIEW_TRANSPARENT_PIXEL, '{{TRAIN_SRC}}');
        [previewAssets?.light?.train, previewAssets?.dark?.train]
            .filter((source) => typeof source === 'string' && source.length > 0)
            .forEach((source) => {
                style = style.replaceAll(source, '{{TRAIN_SRC}}');
            });
        if (!style.includes('{{TRAIN_SRC}}')) {
            throw new Error('Die Bindung des RailTime-Zugmotivs wurde im Editor beschädigt.');
        }
        cell.setAttribute('style', style);
        cell.removeAttribute(MAIL_PREVIEW_TRAIN_ATTRIBUTE);
    });

    const canonicalHtml = Array.from(body.children)
        .map((child) => child.outerHTML)
        .join('\n')
        .trim();
    if (!canonicalHtml.startsWith('<tr')) {
        throw new Error('Die Signatur besitzt nach dem Speichern kein gültiges Tabellenfragment.');
    }
    if (!canonicalHtml.includes('{{TRAIN_SRC}}')) {
        throw new Error('Das RailTime-Zugmotiv fehlt nach dem Speichern der Signatur.');
    }
    if (/data-rt-mail-(?:signature-canvas|preview(?:-[\w-]+)?)/i.test(canonicalHtml)
        || mailPreviewAssetSources(previewAssets).some((source) => canonicalHtml.includes(source))) {
        throw new Error('Reine Vorschauwerte dürfen nicht in der Signatur gespeichert werden.');
    }

    const canonicalProject = normalizeMailProject(structuredClone(project || {}));
    if (!canonicalProject.pages?.[0]) {
        throw new Error('Das Signaturprojekt besitzt keine bearbeitbare Seite.');
    }
    canonicalProject.pages[0].component = canonicalHtml;
    canonicalProject.styles = normalizedCanvas.styles;

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
    hostWidth = 0,
    hostHeight = 0,
    inset = 24,
    minLogicalHeight = 560,
} = {}) {
    const selected = resolveMailPreviewDevice(device);
    const normalizedInset = Math.max(0, Number.isFinite(Number(inset)) ? Number(inset) : 0);
    const normalizedHostWidth = Math.max(0, Number.isFinite(Number(hostWidth)) ? Number(hostWidth) : 0);
    const normalizedHostHeight = Math.max(0, Number.isFinite(Number(hostHeight)) ? Number(hostHeight) : 0);
    const minimumHeight = Math.max(320, Number.isFinite(Number(minLogicalHeight)) ? Number(minLogicalHeight) : 560);
    const availableWidth = Math.max(0, normalizedHostWidth - (normalizedInset * 2));
    const availableHeight = Math.max(0, normalizedHostHeight - (normalizedInset * 2));
    const scale = Math.max(0.01, Math.min(1, availableWidth > 0 ? availableWidth / selected.width : 1));
    const logicalHeight = Math.max(minimumHeight, availableHeight > 0 ? availableHeight / scale : minimumHeight);

    return Object.freeze({
        device: selected.id,
        label: selected.label,
        logicalWidth: selected.width,
        logicalHeight,
        hostWidth: normalizedHostWidth,
        hostHeight: normalizedHostHeight,
        availableWidth,
        availableHeight,
        scale,
        zoom: scale * 100,
        displayWidth: selected.width * scale,
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
    let observedHost = null;
    let scheduledFrame = null;
    let destroyed = false;
    let latestGeometry = null;
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
        latestGeometry = calculateMailPreviewGeometry({
            device: activeDevice,
            hostWidth: measuredWidth,
            hostHeight: measuredHeight,
            inset,
            minLogicalHeight,
        });

        const logicalWidth = `${latestGeometry.logicalWidth}px`;
        const logicalHeight = `${latestGeometry.logicalHeight}px`;
        editor.Canvas?.setZoom?.(latestGeometry.zoom);
        frame.dataset.previewDevice = latestGeometry.device;
        frame.dataset.previewScale = String(latestGeometry.scale);
        frame.dataset.logicalWidth = String(latestGeometry.logicalWidth);
        frame.dataset.logicalHeight = String(latestGeometry.logicalHeight);
        frame.style?.setProperty?.('--rt-mail-logical-width', logicalWidth);
        frame.style?.setProperty?.('--rt-mail-logical-height', logicalHeight);
        frame.style?.setProperty?.('--rt-mail-display-width', `${latestGeometry.displayWidth}px`);
        frame.style?.setProperty?.('--rt-mail-display-height', `${latestGeometry.displayHeight}px`);
        frame.style?.setProperty?.('--rt-mail-scale', String(latestGeometry.scale));
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
            editor.setDevice?.(`rt-mail-${activeDevice}`);
            this.refresh();
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

/**
 * Startet den Builder im E-Mail-Modus.
 *
 * @param {object}   options
 * @param {object}   options.runtime            window.LMZBuilder
 * @param {Element|string} options.root         Wurzelknoten; sein Inhalt wird geleert
 * @param {string}   options.projectId          nur Durchreichung an onLoad/onSave
 * @param {object}   options.vendor             { grapesJs, grapesCss }
 * @param {object}   options.storage            { onLoad, onSave } — Pflicht
 * @param {Array}    options.assets             Bildliste fuer den Asset-Manager
 * @param {object}   options.blockOptions       Durchreichung an createMailBlocks
 * @param {'light'|'dark'} options.theme        Vorschaufarben der Leinwand
 * @param {object}   options.previewAssets      lokale Logo-/Zug-/Iconquellen nur fuer das iframe
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
    let canvasCss = mailCanvasStyles(activeTheme, previewAssets);

    const instance = await runtime.create({
        root,
        projectId,
        gjsScript: vendor.grapesJs,
        gjsStyle: vendor.grapesCss,

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
        if (markAddedMailComponentStyles(editor, component)) return;
        // Bei per Block eingefuegtem HTML entsteht die cNN-Regel je nach
        // Komponententyp erst direkt nach component:add.
        globalThis.queueMicrotask?.(() => markAddedMailComponentStyles(editor, component));
    };
    editor.on?.('component:add', onComponentAdd);
    const rootElement = typeof root === 'string' ? document.querySelector(root) : root;
    const frame = rootElement?.closest?.('[data-mail-editor-frame]') || null;
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
    };

    applyMailCanvasStyles(editor, canvasCss);
    hydrateMailCanvasPlaceholders(editor);
    hydrateMailCanvasAssets(editor, activeTheme, previewAssets);
    editor.on?.('canvas:frame:load', onFrameLoad);

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
        mode: 'mail',
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
            baseUrl: window.location.origin + '/',
        },
    });
    let shellLifecycle = null;
    let assistantAdapter = null;
    let disposed = false;
    const api = {
        instance,
        editor,
        readOnly,

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

        getPreviewGeometry: () => preview?.getGeometry() || null,

        /** Alle Marken-GIFs auf der Leinwand ohne Modellmutation neu starten. */
        restartAllGifs() {
            return restartMailCanvasAnimations(editor);
        },

        /** Leinwandfarben wechseln; die Wahl wird nicht mitgespeichert. */
        setTheme(nextTheme = activeTheme) {
            activeTheme = nextTheme === 'dark' ? 'dark' : 'light';
            canvasCss = mailCanvasStyles(activeTheme, previewAssets);
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
            editorChrome.destroy();
            editor.off?.('component:add', onComponentAdd);
            editor.off?.('canvas:frame:load', onFrameLoad);
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
        mode: 'mail',
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
