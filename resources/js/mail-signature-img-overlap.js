/** V26 editor projection. Geometry and style templates come from PHP's profile. */

const VERSION = 'v26';
const BREAKPOINT_NAMES = ['desktop', 'tablet', 'mobile'];
const PROPERTIES = ['height', 'size', 'offset'];
const CLASS_NAMES = {
    carrier: 'rt-sign-cell', stage: 'rt-sign-stage', layer: 'rt-sign-train-layer',
    frame: 'rt-sign-train-frame', slot: 'rt-sign-train-slot', image: 'rt-sign-train',
    contentTable: 'rt-sign-content-frame',
};

function assertProfile(profile) {
    if (!profile || profile.version !== VERSION
        || JSON.stringify(Object.keys(profile.breakpoints || {})) !== JSON.stringify(BREAKPOINT_NAMES)
        || !Array.isArray(profile.sizes) || profile.sizes.length === 0
        || !['heightMin', 'heightMax', 'offsetMin', 'offsetMax', 'desktopHeightMin', 'maxImageWidth', 'sourceWidth', 'sourceHeight']
            .every((key) => Number.isInteger(profile[key]))
        || profile.sourceWidth < 1 || profile.sourceHeight < 1
        || !BREAKPOINT_NAMES.every((name) => Number.isInteger(profile.compactHeights?.[name]))
        || typeof profile.layoutCss !== 'string'
        || !['stage', 'layer', 'frame', 'slot', 'image'].every((key) => typeof profile.styleTemplates?.[key] === 'string')) {
        throw new Error('Das serverseitige V26-Profil fehlt oder ist unvollstaendig.');
    }
    return profile;
}

export function imgOverlapSettingAttributes(profile) {
    assertProfile(profile);
    return BREAKPOINT_NAMES.flatMap((breakpoint) => PROPERTIES.map((property) => `data-rt-v26-${property}-${breakpoint}`));
}

export function readImgOverlapSettings(stage, profile) {
    assertProfile(profile);
    if (!stage?.getAttribute) throw new Error('Die V26-Buehne fehlt.');
    const attributes = imgOverlapSettingAttributes(profile);
    for (const attr of Array.from(stage.attributes || [])) {
        if (attr.name.startsWith('data-rt-v26-') && !attributes.includes(attr.name)) {
            throw new Error('Die V26-Geometrie besitzt ein unbekanntes Attribut.');
        }
    }
    const settings = {};
    for (const breakpoint of BREAKPOINT_NAMES) {
        const current = {};
        for (const property of PROPERTIES) {
            const value = stage.getAttribute(`data-rt-v26-${property}-${breakpoint}`);
            if (!/^(?:0|[1-9][0-9]{0,2})$/.test(String(value ?? ''))) {
                throw new Error('Die V26-Geometrie benoetigt eindeutige ganze Zahlen.');
            }
            current[property] = Number(value);
        }
        if (current.height < profile.heightMin || current.height > profile.heightMax
            || !profile.sizes.includes(current.size)
            || current.offset < profile.offsetMin || current.offset > profile.offsetMax
            || (breakpoint === 'desktop' && current.height < profile.desktopHeightMin)) {
            throw new Error('Die V26-Geometrie liegt ausserhalb der erlaubten Grenzen.');
        }
        const imageWidth = profile.breakpoints[breakpoint] === null
            ? profile.maxImageWidth
            : Math.min(profile.maxImageWidth, profile.breakpoints[breakpoint] * current.size / 100);
        if (current.height < Math.ceil(imageWidth * profile.sourceHeight / profile.sourceWidth)) {
            throw new Error('Die V26-Geometrie wuerde den Fahrrauch abschneiden.');
        }
        settings[breakpoint] = current;
    }
    return settings;
}

function stylesFor(settings, profile) {
    const replacements = {
        height: String(settings.height), size: String(settings.size),
        left: settings.offset === 0 ? '0' : `-${settings.offset}%`,
        maxImageWidth: String(profile.maxImageWidth),
    };
    return Object.fromEntries(Object.entries(profile.styleTemplates).map(([key, template]) => [
        key, template.replace(/\{(height|size|left|maxImageWidth)\}/g, (_, property) => replacements[property]),
    ]));
}

/** Explicit editor-setting change helper; never called implicitly on import. */
export function imgOverlapGeometry(settings, profile) {
    assertProfile(profile);
    if (JSON.stringify(Object.keys(settings || {})) !== JSON.stringify(BREAKPOINT_NAMES)
        || BREAKPOINT_NAMES.some((breakpoint) => JSON.stringify(Object.keys(settings[breakpoint] || {})) !== JSON.stringify(PROPERTIES))) {
        throw new Error('Die V26-Geometrie besitzt unbekannte oder fehlende Breakpoints.');
    }
    const attributes = {};
    for (const breakpoint of BREAKPOINT_NAMES) {
        for (const property of PROPERTIES) {
            const value = settings[breakpoint][property];
            if (!Number.isInteger(value)) throw new Error('Die V26-Geometrie benoetigt eindeutige ganze Zahlen.');
            attributes[`data-rt-v26-${property}-${breakpoint}`] = String(value);
        }
    }
    const valid = readImgOverlapSettings({ attributes: [], getAttribute: (name) => attributes[name] ?? null }, profile);
    const styles = stylesFor(valid.desktop, profile);
    return {
        attributes,
        height: String(valid.desktop.height),
        styles: { ...styles, image: `${styles.image}mso-hide:all;`, contentTable: styles.frame },
    };
}

function tableRows(table) {
    const children = Array.from(table?.children || []);
    return children.length === 1 && children[0]?.tagName === 'TBODY'
        ? Array.from(children[0].children || []) : children;
}

function unique(wrapper, className) {
    const nodes = Array.from(wrapper?.querySelectorAll?.(`.${className}`) || []);
    if (nodes.length !== 1) throw new Error(`Die V26-Struktur besitzt keinen eindeutigen ${className}-Knoten.`);
    return nodes[0];
}

function assertStyle(element, expected) {
    const normalize = (value) => {
        const source = String(value || '');
        if (/\/\*|\*\/|\\/.test(source)) throw new Error('Die V26-Geometrie besitzt mehrdeutige CSS-Deklarationen.');
        const declarations = {};
        for (const part of source.split(';').filter((part) => part.trim())) {
            const separator = part.indexOf(':');
            if (separator === -1) throw new Error('Die V26-Geometrie besitzt unvollstaendige CSS-Deklarationen.');
            const key = part.slice(0, separator).trim().toLowerCase();
            if (Object.hasOwn(declarations, key)) throw new Error('Die V26-Geometrie besitzt doppelte CSS-Deklarationen.');
            declarations[key] = part.slice(separator + 1).trim().replace(/\s*!important\s*$/i, '').replace(/\s+/g, '').toLowerCase();
        }
        return JSON.stringify(Object.fromEntries(Object.entries(declarations).sort(([a], [b]) => a.localeCompare(b))));
    };
    if (normalize(element.getAttribute('style')) !== normalize(expected)) {
        throw new Error(`Die V26-Geometrie besitzt vom Profil abweichende ${element.getAttribute('class')}-Stile.`);
    }
}

/** Validate source DOM only. Does not rewrite legacy or current documents. */
export function assertImgOverlapSignature(wrapper, rows, profile) {
    assertProfile(profile);
    if (!wrapper || rows?.length !== 2 || rows.some((row) => row.tagName !== 'TR')
        || rows[0].getAttribute('data-rt-artifact-version') !== VERSION) {
        throw new Error('Der IMG-Ueberlappungsvertrag ist ausschliesslich fuer V26 mit zwei Zeilen bestimmt.');
    }
    if (rows[0].hasAttribute('data-rt-signature-density') || rows[0].classList.contains('rt-sign-density-compact')) {
        throw new Error('Die V26-Laufzeitdichte darf nicht in einer Importquelle gespeichert werden.');
    }
    const nodes = Object.fromEntries(Object.entries(CLASS_NAMES).map(([key, className]) => [key, unique(wrapper, className)]));
    const { carrier, stage, layer, frame, slot, image, contentTable } = nodes;
    const stageElements = Array.from(stage.children || []);
    const frameRows = tableRows(frame);
    if (carrier.tagName !== 'TD' || carrier.parentElement !== rows[0]
        || stage.tagName !== 'DIV' || stage.parentElement !== carrier || carrier.lastElementChild !== stage
        || stageElements.length !== 2 || stageElements[0] !== layer || stageElements[1] !== contentTable
        || layer.tagName !== 'DIV' || layer.children.length !== 1 || layer.firstElementChild !== frame
        || frame.tagName !== 'TABLE' || frameRows.length !== 1 || frameRows[0].tagName !== 'TR'
        || frameRows[0].children.length !== 1 || frameRows[0].firstElementChild !== slot
        || slot.tagName !== 'TD' || slot.children.length !== 1 || slot.firstElementChild !== image
        || image.tagName !== 'IMG' || contentTable.tagName !== 'TABLE') {
        throw new Error('In V26 muss genau ein echtes Zugbild vor dem Inhaltsrahmen stehen.');
    }
    if ((String(wrapper.outerHTML || wrapper.innerHTML).match(/\{\{TRAIN_SRC\}\}/g) || []).length !== 1
        || String(wrapper.outerHTML || wrapper.innerHTML).includes('{{TRAIN_IDLE_SRC}}')
        || wrapper.querySelector('[data-rt-train-background], [data-rt-train-idle]')
        || image.getAttribute('src') !== '{{TRAIN_SRC}}' || image.getAttribute('width') !== '720'
        || image.hasAttribute('height')) {
        throw new Error('V26 benoetigt genau ein proportionales echtes TRAIN_SRC-Zugbild ohne Idle-Overlay.');
    }
    for (const element of [carrier, ...Array.from(carrier.querySelectorAll('*'))]) {
        const style = element.getAttribute('style') || '';
        if (element.hasAttribute('background')
            || /(?:^|;)\s*background(?:-image)?\s*:[^;]*(?:url|image-set|gradient)\s*\(/i.test(style)
            || (element.tagName !== 'IMG' && style.includes('{{TRAIN_SRC}}'))) {
            throw new Error('V26 bindet Bilder ausschliesslich als echte IMG-Elemente ein.');
        }
    }
    const settings = readImgOverlapSettings(stage, profile);
    const styles = stylesFor(settings.desktop, profile);
    assertStyle(stage, styles.stage);
    assertStyle(layer, styles.layer);
    assertStyle(frame, styles.frame);
    assertStyle(slot, styles.slot);
    assertStyle(contentTable, styles.frame);
    assertStyle(image, `${styles.image}mso-hide:all;`);
    for (const element of [frame, slot, contentTable]) {
        if (element.getAttribute('height') !== String(settings.desktop.height)) {
            throw new Error('Die V26-Pixelhoehen muessen dem Desktopprofil entsprechen.');
        }
    }
    return { ...nodes, stageElements, settings, overlap: `-${settings.desktop.height}px`, imgOverlap: true };
}

/** Render only server-defined V26 rules. No default profile or legacy rewrites. */
export function imgOverlapCss(htmlOrDocument, profile, { DOMParser: Parser = globalThis.DOMParser,
    prefix = 'tr[data-rt-artifact-version="v26"]' } = {}) {
    assertProfile(profile);
    if (prefix !== 'tr[data-rt-artifact-version="v26"]' && !/^\.[a-zA-Z_][a-zA-Z0-9_-]{0,100}$/.test(prefix)) {
        throw new Error('Der V26-CSS-Bereich ist nicht sicher gebunden.');
    }
    const document = typeof htmlOrDocument === 'string'
        ? new Parser().parseFromString(`<html><body><table><tbody>${htmlOrDocument}</tbody></table></body></html>`, 'text/html')
        : htmlOrDocument;
    if (!document?.querySelectorAll) throw new Error('Das V26-Dokument konnte nicht gelesen werden.');
    const versionRows = document.querySelectorAll('tr[data-rt-artifact-version="v26"]');
    if (versionRows.length === 0) return '';
    if (versionRows.length !== 1) throw new Error('Das V26-Dokument ist nicht eindeutig.');
    const stage = unique(versionRows[0], CLASS_NAMES.stage);
    const settings = readImgOverlapSettings(stage, profile);
    let css = profile.layoutCss.replaceAll('{scope}', prefix);
    const important = (value) => value.replaceAll(';', '!important;');
    const compactPrefix = prefix === 'tr[data-rt-artifact-version="v26"]'
        ? `${prefix}.rt-sign-density-compact` : `${prefix} .rt-sign-density-compact`;
    for (const compact of [false, true]) {
        const selector = compact ? compactPrefix : prefix;
        for (const [breakpoint, maxWidth] of Object.entries(profile.breakpoints)) {
            const current = { ...settings[breakpoint] };
            if (compact) current.height = Math.min(current.height, profile.compactHeights[breakpoint]);
            const styles = stylesFor(current, profile);
            const rule = compact
                ? `${selector} .rt-sign-train-layer{height:${current.height}px!important;max-height:${current.height}px!important;margin-bottom:-${current.height}px!important;}`
                    + `${selector} .rt-sign-train-frame,${selector} .rt-sign-content-frame,${selector} .rt-sign-train-slot{height:${current.height}px!important;}`
                : `${selector} .rt-sign-train-layer{${important(styles.layer)}}`
                + `${selector} .rt-sign-train-frame,${selector} .rt-sign-content-frame{${important(styles.frame)}}`
                + `${selector} .rt-sign-train-slot{${important(styles.slot)}}`
                + `${selector} .rt-sign-train,${selector} .rt-sign-train-mso{${important(styles.image)}}`;
            css += maxWidth === null ? rule : `@media only screen and (max-width:${maxWidth}px){${rule}}`;
        }
    }
    return css;
}
