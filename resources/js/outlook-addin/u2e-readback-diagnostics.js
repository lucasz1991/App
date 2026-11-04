import { parseManagedHtml } from './managed-template.js';
import { u2eCenteringContext } from './u2e-appearance.js';
import { U2E_BORDER_IMAGE_PROPERTIES, U2E_AMBIGUOUS_TOP_BORDER_PROPERTIES } from './u2e-borders.js';

// This module describes failures and redacts already-read HTML. It never grants
// ownership, changes a compose body, or admits a different material layout.
export const U2E_READBACK_CHECKPOINTS = Object.freeze([
    'html.parse', 'scope.boundary', 'markers.count', 'markers.tag', 'markers.binding',
    'shell.rows', 'shell.width', 'shell.align', 'shell.hidden', 'grid.author', 'grid.columns',
    'grid.rowspan', 'grid.train', 'grid.legal', 'markers.position', 'shell.count',
    'material.hidden', 'material.float', 'material.content', 'dispatch.count',
    'images.src', 'images.width', 'images.height', 'images.count', 'images.order', 'images.origin',
    'links.content', 'links.scheme', 'links.count', 'borders.structure', 'borders.invalid', 'borders.image', 'borders.cta', 'line.visibility', 'colors.line', 'colors.background-image', 'colors.conflict',
    'colors.legal', 'colors.dispatch', 'colors.corners', 'state.present', 'state.kind',
    'state.version', 'state.unreadable', 'source.invalid', 'evidence.topology', 'evidence.images',
    'evidence.links', 'evidence.colors', 'evidence.borderColors', 'evidence.text', 'compose.type', 'quote.bytes',
    'author.signature', 'author.combined', 'readback.unknown',
]);
export const U2E_READBACK_DIFFERENCE_KEYS = Object.freeze([
    ...U2E_READBACK_CHECKPOINTS,
    'evidence.images.count', 'evidence.images.src', 'evidence.images.width', 'evidence.images.height',
    'evidence.links.count', 'evidence.links.href', 'evidence.colors.count', 'evidence.colors.value',
    'evidence.borderColors.count', 'evidence.borderColors.value',
    'evidence.text.length', 'evidence.text.sha256',
]);
export const U2E_REDACTED_TAGS = Object.freeze(['table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th',
    'div', 'p', 'span', 'font', 'b', 'strong', 'i', 'em', 'u', 's', 'a', 'img', 'br', 'wbr', 'o:p']);
export const U2E_REDACTED_ATTRIBUTES = Object.freeze(['id', 'class', 'style', 'width', 'height', 'border',
    'cellpadding', 'cellspacing', 'rowspan', 'colspan', 'align', 'valign', 'bgcolor', 'color',
    'src', 'href', 'alt', 'title', 'dir', 'role', 'face', 'data-rt-redacted']);
export const U2E_REDACTED_STYLE_PROPERTIES = Object.freeze([
    'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
    'border-width', 'border-color', 'border-style', 'border-collapse', 'border-spacing',
    'border-top-width', 'border-top-color', 'border-top-style', 'mso-border-top-alt',
    ...U2E_BORDER_IMAGE_PROPERTIES, ...U2E_AMBIGUOUS_TOP_BORDER_PROPERTIES,
    'background', 'background-color', 'background-image', 'color', 'text-align', 'text-indent', 'line-height',
    'display', 'visibility', 'opacity', 'float', 'clear', 'vertical-align', 'table-layout',
    'font-size', 'font-family', 'font-weight', 'font-style', 'text-decoration',
    'white-space', 'word-break', 'overflow-wrap',
    'mso-line-height-rule', 'mso-table-lspace', 'mso-table-rspace', 'mso-padding-alt',
    'mso-margin-top-alt', 'mso-margin-bottom-alt', 'mso-hide', 'mso-element',
]);
export const U2E_REDACTED_CLASSES = Object.freeze(['rt-col', 'rt-col-company', 'rt-col-direct', 'rt-col-dispo', 'rt-col-person',
    'rt-contact', 'rt-cta-br', 'rt-cta-cell', 'rt-cta-dispo', 'rt-cta-erreichbar', 'rt-cta-gap', 'rt-cta-icon', 'rt-cta-line',
    'rt-cta-number', 'rt-cta-time', 'rt-dispo', 'rt-dispo-actions', 'rt-dispo-corner', 'rt-dispo-head',
    'rt-dispo-row', 'rt-legal', 'rt-logo', 'rt-logo-link', 'rt-pad', 'rt-pad-dispo', 'rt-shell',
    'rt-sig-row', 'rt-text', 'rt-train', 'rt-train-cell', 'MsoNormal', 'MsoTableGrid', 'ElementToProof', 'ContentPasted0']);
export const U2E_REDACTED_CSS_WORDS = Object.freeze([
    'auto', 'none', 'normal', 'initial', 'inherit', 'unset', 'transparent', 'currentcolor',
    'white', 'black', 'red', 'blue', 'green', 'gray', 'grey', 'collapse', 'separate', 'solid', 'dashed', 'dotted', 'double',
    'left', 'right', 'center', 'justify', 'start', 'end', 'top', 'bottom', 'middle', 'baseline', 'text-top', 'text-bottom',
    'block', 'inline', 'inline-block', 'table', 'table-cell', 'hidden', 'visible', 'both', 'fixed',
    'bold', 'bolder', 'lighter', 'italic', 'oblique', 'underline', 'line-through', 'nowrap', 'pre', 'pre-wrap', 'pre-line',
    'break-word', 'break-all', 'keep-all', 'anywhere', 'exactly', 'at-least', 'all', 'para-border-div',
    'arial', 'calibri', 'aptos', 'sans-serif', 'serif', 'tahoma', 'verdana', 'times', 'new', 'roman',
    'segoe', 'ui', 'helvetica', 'courier', 'cambria', 'rt-redacted',
]);

const checkpointSet = new Set(U2E_READBACK_CHECKPOINTS), differenceSet = new Set(U2E_READBACK_DIFFERENCE_KEYS);
const markerPattern = /^(?:x_)?rt-sig-u2-([0-9a-f]{24})(?:-cell)?$/;
const knownAssets = new Set(['b983af0049.png', '9dfa25b253.png', 'd92bd192b6.png', '1593d63cca.png', 'a38bc7d27c.png',
    '8a9ee1e161.png', '4b1a2aaba8.png', 'b4b1fbea07.png', 'f1b083c20b.png', '19125261c2.png', 'f9d88b899d.png', '1111670ac8.png', '14d7943c88.png']);
const cssWords = new Set(U2E_REDACTED_CSS_WORDS), classes = new Set(U2E_REDACTED_CLASSES);
const cssProperties = new Set(U2E_REDACTED_STYLE_PROPERTIES), allowedTags = new Set(U2E_REDACTED_TAGS);
const rotate = (word, bits) => word >>> bits | word << 32 - bits;
const shaConstants = [0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2];

// Synchronous SHA-256 keeps the pure state reader synchronous. This is only a
// non-reversible diagnostic summary, never an authorization or ownership token.
export function u2eDiagnosticHash(input) {
    const bytes = [];
    for (const character of String(input)) {
        let code = character.codePointAt(0);
        if (code >= 0xd800 && code <= 0xdfff) code = 0xfffd;
        if (code < 0x80) bytes.push(code);
        else if (code < 0x800) bytes.push(0xc0 | code >>> 6, 0x80 | code & 63);
        else if (code < 0x10000) bytes.push(0xe0 | code >>> 12, 0x80 | code >>> 6 & 63, 0x80 | code & 63);
        else bytes.push(0xf0 | code >>> 18, 0x80 | code >>> 12 & 63, 0x80 | code >>> 6 & 63, 0x80 | code & 63);
    }
    const bitLength = bytes.length * 8, padded = new Uint8Array(Math.ceil((bytes.length + 9) / 64) * 64);
    padded.set(bytes); padded[bytes.length] = 0x80;
    const view = new DataView(padded.buffer); view.setUint32(padded.length - 4, bitLength >>> 0);
    view.setUint32(padded.length - 8, Math.floor(bitLength / 0x100000000));
    const hash = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19], words = new Uint32Array(64);
    for (let offset = 0; offset < padded.length; offset += 64) {
        for (let at = 0; at < 16; at++) words[at] = view.getUint32(offset + at * 4);
        for (let at = 16; at < 64; at++) {
            const a = words[at - 15], b = words[at - 2];
            words[at] = (rotate(a, 7) ^ rotate(a, 18) ^ a >>> 3) + words[at - 16]
                + (rotate(b, 17) ^ rotate(b, 19) ^ b >>> 10) + words[at - 7];
        }
        let [a, b, c, d, e, f, g, h] = hash;
        for (let at = 0; at < 64; at++) {
            const t1 = h + (rotate(e, 6) ^ rotate(e, 11) ^ rotate(e, 25)) + (e & f ^ ~e & g) + shaConstants[at] + words[at];
            const t2 = (rotate(a, 2) ^ rotate(a, 13) ^ rotate(a, 22)) + (a & b ^ a & c ^ b & c);
            h = g; g = f; f = e; e = d + t1 >>> 0; d = c; c = b; b = a; a = t1 + t2 >>> 0;
        }
        [a, b, c, d, e, f, g, h].forEach((value, at) => { hash[at] = hash[at] + value >>> 0; });
    }
    return hash.map(value => value.toString(16).padStart(8, '0')).join('');
}

const safeAtom = value => value === undefined || value === null ? null
    : typeof value === 'boolean' ? value
        : Number.isSafeInteger(value) && Math.abs(value) <= 1000000 ? value
            : (() => { const serialized = typeof value === 'string' ? value : JSON.stringify(value);
                return typeof serialized === 'string' && serialized.length <= 1000000
                    ? 'sha256:' + u2eDiagnosticHash(serialized) + ':' + serialized.length : null; })();

export function u2eSafeDifference(key, expected, actual, index = null) {
    if (!differenceSet.has(key)) return null;
    return Object.freeze({ key, index: Number.isInteger(index) && index >= 0 && index <= 4095 ? index : null,
        expected: safeAtom(expected), actual: safeAtom(actual) });
}

export function u2eReadbackFailure(checkpoint, expected = undefined, actual = undefined, index = null) {
    return { checkpoint: checkpointSet.has(checkpoint) ? checkpoint : 'readback.unknown',
        difference: expected === undefined && actual === undefined ? null : u2eSafeDifference(checkpoint, expected, actual, index) };
}

/** Comparison only: Word may append the root-path slash to an unchanged HTTPS
 * authority. Do not parse, rewrite, case-fold, decode or otherwise canonicalize
 * any URL, and never apply this exception to images or authored content. */
export function universalSignatureHrefEqual(expected, actual) {
    if (expected === actual) return true;
    if (typeof expected !== 'string' || typeof actual !== 'string') return false;
    const root = /^https:\/\/([A-Za-z0-9.-]+(?::[0-9]{1,5})?)(\/?)(?![\s\S])/;
    const before = root.exec(expected), after = root.exec(actual);
    if (before === null || after === null || before[1] !== after[1] || before[2] === after[2]) return false;
    const [host, port] = before[1].split(':');
    const labels = host.split('.');
    return host.length <= 253 && labels.every(label => /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/.test(label))
        && (port === undefined || Number(port) <= 65535)
        && (!/^\d+(?:\.\d+){3}$/.test(host) || labels.every(label => Number(label) <= 255));
}

export function firstUniversalEvidenceDifference(expected, actual) {
    const unequal = (a, b) => JSON.stringify(a) !== JSON.stringify(b);
    if (unequal(expected.topology, actual.topology)) return { checkpoint: 'evidence.topology', difference: u2eSafeDifference('evidence.topology', expected.topology, actual.topology) };
    for (const [collection, fields] of [['images', ['src', 'width', 'height']], ['links', null], ['colors', null], ['borderColors', null]]) {
        if (!Array.isArray(expected[collection]) || !Array.isArray(actual[collection])) return { checkpoint: 'evidence.' + collection,
            difference: u2eSafeDifference('evidence.' + collection + '.count', Array.isArray(expected[collection]) ? expected[collection].length : null,
                Array.isArray(actual[collection]) ? actual[collection].length : null) };
        if (expected[collection].length !== actual[collection].length) return { checkpoint: 'evidence.' + collection,
            difference: u2eSafeDifference('evidence.' + collection + '.count', expected[collection].length, actual[collection].length) };
        for (let at = 0; at < expected[collection].length; at++) {
            if (fields) {
                for (const field of fields) if (unequal(expected[collection][at][field], actual[collection][at][field])) return {
                    checkpoint: 'evidence.' + collection, difference: u2eSafeDifference('evidence.' + collection + '.' + field, expected[collection][at][field], actual[collection][at][field], at) };
            } else if (collection === 'links' ? !universalSignatureHrefEqual(expected[collection][at], actual[collection][at]) : unequal(expected[collection][at], actual[collection][at])) return { checkpoint: 'evidence.' + collection,
                difference: u2eSafeDifference('evidence.' + collection + (collection === 'links' ? '.href' : '.value'), expected[collection][at], actual[collection][at], at) };
        }
    }
    if (expected.text !== actual.text) return { checkpoint: 'evidence.text', difference: expected.text.length !== actual.text.length
        ? u2eSafeDifference('evidence.text.length', expected.text.length, actual.text.length)
        : u2eSafeDifference('evidence.text.sha256', expected.text, actual.text) };
    return null;
}

function safeCssValue(input) {
    const value = input.trim().toLowerCase().replace(/["']/g, '').replace(/\s+/g, ' ');
    if (value.length > 256 || !/^[a-z0-9#.,%()!\s\/-]+$/.test(value)) return 'rt-redacted';
    let residual = value.replace(/\s*!important$/, '').replace(/#[0-9a-f]{3,8}\b/g, '');
    residual = residual.replace(/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/g, (match, ...parts) => parts.slice(0, 3).every(part => Number(part) <= 255) ? '' : 'invalid');
    let bounded = true;
    residual = residual.replace(/-?\d+(?:\.\d+)?(?:px|pt|%|em|rem)?/g, number => { if (Math.abs(parseFloat(number)) > 1000000) bounded = false; return ''; });
    const words = residual.trim().split(/[\s,/]+/).filter(Boolean);
    return bounded && words.every(word => cssWords.has(word)) ? value : 'rt-redacted';
}
const escapeAttribute = value => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const nearTable = (elements, index) => { for (let at = elements[index]?.parent; Number.isInteger(at); at = elements[at]?.parent) if (elements[at].tag === 'table') return at; return null; };
function inQuote(elements, index) {
    for (let at = index; Number.isInteger(at); at = elements[at]?.parent) {
        const node = elements[at];
        if (node.tag === 'blockquote' || /^(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)$/i.test(node.attrs.id || '')
            || /(?:^|\s)(?:gmail_quote|yahoo_quoted|moz-cite-prefix)(?:\s|$)/.test(node.attrs.class || '')) return true;
    }
    return false;
}
function redactedUrl(value, image) {
    if (!image) return '#rt-redacted-' + (/^(?:https:\/\/|mailto:|tel:)/i.test(value) ? '' : 'invalid-') + u2eDiagnosticHash(value);
    const known = /^(https:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?)\/m\/([0-9a-f]{10}\.png)$/.exec(value);
    if (known && knownAssets.has(known[2])) return '/m/' + known[2] + '#rt-redacted-' + u2eDiagnosticHash(known[1]);
    const candidate = /^([a-z]+):/i.exec(value)?.[1]?.toLowerCase();
    const scheme = !value ? 'empty' : ['https', 'http', 'cid', 'data', 'blob'].includes(candidate) ? candidate : 'other';
    return 'rt-redacted-' + scheme + '-' + u2eDiagnosticHash(value);
}
function safeAttributes(node) {
    const output = [];
    for (const [name, original] of Object.entries(node.attrs)) {
        let value = null;
        if (name === 'id' && markerPattern.test(original)) value = original;
        else if (name === 'class') value = original.split(/\s+/).filter(Boolean).slice(0, 16).map(token => {
            const canonical = token.replace(/^x_/, '');
            return classes.has(canonical) ? token : 'rt-redacted-' + u2eDiagnosticHash(token);
        }).join(' ');
        else if (name === 'style') value = original.split(';').slice(0, 128).flatMap(declaration => {
            const colon = declaration.indexOf(':'), key = declaration.slice(0, colon).trim().toLowerCase();
            return colon >= 0 && cssProperties.has(key) ? [key + ':' + safeCssValue(declaration.slice(colon + 1))] : [];
        }).join(';');
        else if (['width', 'height', 'border', 'cellpadding', 'cellspacing', 'rowspan', 'colspan'].includes(name)) value =
            original.length <= 32 && /^\d+(?:\.\d+)?(?:px|pt|%)?$/i.test(original) ? original : '0';
        else if (['align', 'valign'].includes(name)) value = /^(left|right|center|justify|top|bottom|middle|baseline)$/i.test(original) ? original.toLowerCase() : 'left';
        else if (['bgcolor', 'color'].includes(name)) value = /^(?:#[0-9a-f]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))$/i.test(original) ? original.toLowerCase().replace(/\s+/g, ' ') : '#000000';
        else if (name === 'src') value = redactedUrl(original, true);
        else if (name === 'href') value = redactedUrl(original, false);
        else if (['alt', 'title'].includes(name)) value = 'rt-redacted';
        else if (name === 'dir' && /^(ltr|rtl|auto)$/i.test(original)) value = original.toLowerCase();
        else if (name === 'role' && /^(presentation|none)$/i.test(original)) value = original.toLowerCase();
        else if (name === 'face') value = safeCssValue(original);
        if (value) output.push(' ' + name + '="' + escapeAttribute(value) + '"');
    }
    return output.join('');
}

/** Re-serialize only an unquoted marked shell. All input text is discarded;
 * the five fixed semantic cells get the same token on both sides, regardless
 * of Office adding or splitting SPAN elements. No authored row is serialized. */
export function redactUniversalSignatureHtml(html) {
    try {
        const { elements } = parseManagedHtml(html);
        const markers = elements.map((node, index) => ({ node, index })).filter(value => markerPattern.test(value.node.attrs.id || '') && !inQuote(elements, value.index));
        const roots = [...new Set(markers.map(value => value.node.tag === 'table' ? value.index : value.node.tag === 'td' ? nearTable(elements, value.index) : null))];
        if (roots.length !== 1 || roots[0] === null) return null;
        const rootIndex = roots[0], root = elements[rootIndex];
        const rows = elements.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'tr' && nearTable(elements, value.index) === rootIndex);
        if (![3, 4].includes(rows.length)) return null;
        const author = rows.length === 4 ? rows[0] : null, signatureRows = author ? rows.slice(1) : rows;
        const firstRowCells = elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === signatureRows[0].index && ['td', 'th'].includes(value.node.tag));
        const legalCells = elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === signatureRows[2].index && ['td', 'th'].includes(value.node.tag));
        // Without the expected row boundary, do not risk treating user content
        // as a diagnostic signature fixture.
        if (firstRowCells.length !== 4 || legalCells.length !== 1) return null;
        const textSlots = new Set([...firstRowCells, ...legalCells].map(value => value.index));
        const childMap = new Map();
        elements.forEach((node, index) => { const children = childMap.get(node.parent) || []; children.push(index); childMap.set(node.parent, children); });
        const render = index => {
            const node = elements[index];
            if (author && index === author.index || inQuote(elements, index)) return '';
            if (!allowedTags.has(node.tag)) return '<span data-rt-redacted="' + (['style', 'script', 'iframe', 'object', 'embed', 'input', 'form', 'svg'].includes(node.tag) ? 'active' : 'unknown') + '"></span>';
            const opening = '<' + node.tag + safeAttributes(node) + '>';
            if (['img', 'br', 'wbr'].includes(node.tag)) return opening;
            return opening + (textSlots.has(index) ? 'rt-redacted' : '') + (childMap.get(index) || []).map(render).join('') + '</' + node.tag + '>';
        };
        const shell = render(rootIndex);
        // Preserve only the accepted centering fact, never an ancestor's text,
        // attributes, siblings or other content outside the signature shell.
        const output = u2eCenteringContext(elements, rootIndex).inherited
            ? '<div align="center">' + shell + '</div>' : shell;
        return output.length <= 196608 && /^[\x20-\x7e]*$/.test(output) ? output : null;
    } catch { return null; }
}

export function universalRedactedReadbackFixture(expectedHtml, actualHtml) {
    const expected = redactUniversalSignatureHtml(expectedHtml), actual = redactUniversalSignatureHtml(actualHtml);
    if (expected === null || actual === null || expected.length + actual.length > 196608) return null;
    const fixture = { schema: 1, format: 'u2e-redacted-html-v1', html: actual, expectedHtml: expected,
        sourceLength: actualHtml.length, sha256: u2eDiagnosticHash(actual), expectedSha256: u2eDiagnosticHash(expected) };
    // Leave room for finite metadata below the bounded request budget.
    // Telemetry checks the complete JSON and selects keepalive by its size.
    return validateUniversalRedactedFixture(fixture);
}

const fixtureKeys = ['schema', 'format', 'html', 'expectedHtml', 'sourceLength', 'sha256', 'expectedSha256'];
function safeRedactedHtml(html) {
    if (typeof html !== 'string' || !html.length || html.length > 196608 || !/^[\x20-\x7e]*$/.test(html)) return false;
    if (html.startsWith('<div')) {
        if (!html.startsWith('<div align="center">') || !html.endsWith('</div>')) return false;
        html = html.slice('<div align="center">'.length, -'</div>'.length);
        if (!/^<table(?: |>)/.test(html)) return false;
    }
    const { elements, comments } = parseManagedHtml(html);
    if (comments.length || elements[0]?.tag !== 'table' || elements[0].start !== 0 || elements[0].end !== html.length) return false;
    const outerRows = elements.filter((node, index) => node.tag === 'tr' && nearTable(elements, index) === 0);
    if (outerRows.length !== 3) return false;
    const firstRowIndex = elements.indexOf(outerRows[0]);
    const firstCells = elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === firstRowIndex && ['td', 'th'].includes(value.node.tag));
    if (firstCells.length !== 4) return false;
    const identifiers = elements.map((node, index) => ({ node, index })).filter(value => Object.hasOwn(value.node.attrs, 'id'));
    if (!identifiers.length || identifiers.some(value => ![0, firstCells[0].index].includes(value.index)
        || !markerPattern.test(value.node.attrs.id) || value.index === 0 && value.node.attrs.id.endsWith('-cell'))
        || new Set(identifiers.map(value => markerPattern.exec(value.node.attrs.id)[1])).size !== 1) return false;
    const content = html.replace(/<[^>]*>/g, '');
    if (content !== 'rt-redacted'.repeat(5)) return false;
    for (const node of elements) {
        if (!allowedTags.has(node.tag)) return false;
        for (const [name, value] of Object.entries(node.attrs)) {
            if (!U2E_REDACTED_ATTRIBUTES.includes(name)) return false;
            if (name === 'id' && !markerPattern.test(value)) return false;
            if (name === 'class' && (!value || value.split(' ').length > 16 || value.split(' ').some(token => !classes.has(token.replace(/^x_/, '')) && !/^rt-redacted-[0-9a-f]{64}$/.test(token)))) return false;
            if (name === 'style' && value.split(';').some(declaration => {
                const at = declaration.indexOf(':');
                return at < 0 || !cssProperties.has(declaration.slice(0, at)) || safeCssValue(declaration.slice(at + 1)) !== declaration.slice(at + 1);
            })) return false;
            if (['width', 'height', 'border', 'cellpadding', 'cellspacing', 'rowspan', 'colspan'].includes(name) && (value.length > 32 || !/^\d+(?:\.\d+)?(?:px|pt|%)?$/i.test(value))) return false;
            if (['align', 'valign'].includes(name) && !/^(left|right|center|justify|top|bottom|middle|baseline)$/.test(value)) return false;
            if (['bgcolor', 'color'].includes(name) && !/^(?:#[0-9a-f]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))$/.test(value)) return false;
            if (name === 'src' && !/^rt-redacted-(https|http|cid|data|blob|other|empty)-[0-9a-f]{64}$/.test(value)) {
                const match = /^\/m\/([0-9a-f]{10}\.png)#rt-redacted-[0-9a-f]{64}$/.exec(value);
                if (!match || !knownAssets.has(match[1])) return false;
            }
            if (name === 'href' && !/^#rt-redacted-(?:invalid-)?[0-9a-f]{64}$/.test(value)) return false;
            if (['alt', 'title'].includes(name) && value !== 'rt-redacted') return false;
            if (name === 'dir' && !/^(ltr|rtl|auto)$/.test(value)) return false;
            if (name === 'role' && !/^(presentation|none)$/.test(value)) return false;
            if (name === 'face' && safeCssValue(value) !== value) return false;
            if (name === 'data-rt-redacted' && !/^(active|unknown)$/.test(value)) return false;
        }
    }
    return true;
}

/** Treat a report as untrusted at the telemetry boundary too. Copy only this
 * finite schema, verify digests, and reject every unredacted text/URL token. */
export function validateUniversalRedactedFixture(fixture) {
    try {
        if (!fixture || typeof fixture !== 'object' || Array.isArray(fixture)
            || Object.keys(fixture).length !== fixtureKeys.length || fixtureKeys.some(key => !Object.prototype.hasOwnProperty.call(fixture, key))
            || fixture.schema !== 1 || fixture.format !== 'u2e-redacted-html-v1'
            || !Number.isInteger(fixture.sourceLength) || fixture.sourceLength < 0 || fixture.sourceLength > 500000
            || typeof fixture.html !== 'string' || typeof fixture.expectedHtml !== 'string'
            || fixture.html.length + fixture.expectedHtml.length > 196608
            || !safeRedactedHtml(fixture.html) || !safeRedactedHtml(fixture.expectedHtml)
            || fixture.sha256 !== u2eDiagnosticHash(fixture.html) || fixture.expectedSha256 !== u2eDiagnosticHash(fixture.expectedHtml)) return null;
        const copy = Object.fromEntries(fixtureKeys.map(key => [key, fixture[key]]));
        return JSON.stringify(copy).length <= 252000 ? Object.freeze(copy) : null;
    } catch { return null; }
}

/** Diagnostic replay only: restore harmless deterministic URL surrogates for
 * the unchanged material reader. Text hashes/lengths in difference remain the
 * authoritative text-loss evidence; fixed text tokens cannot replay wording. */
export function universalDiagnosticReplayHtml(html) {
    if (!safeRedactedHtml(html)) return null;
    return html.replace(/src="\/m\/([0-9a-f]{10}\.png)#rt-redacted-([0-9a-f]{64})"/g, 'src="https://$2.invalid/m/$1"')
        .replace(/href="#rt-redacted-([0-9a-f]{64})"/g, 'href="https://redacted.invalid/$1"')
        .replace(/<span data-rt-redacted="active"><\/span>/g, '<meta>');
}
