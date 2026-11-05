import { parseManagedHtml } from './managed-template.js';
import { currentComposeBodyHtml, isMobileComposeHost, mobileCurrentReplyBodyMode, isTemplateInsertionBlocked, isStandaloneSignatureInsertionBlocked } from './compose-template.js';
import { assertMailboxBinding } from './mailbox-guard.js';
import { confirmedOfficeWrite, hasUncertainWrite } from './office-write.js';
import { createNativeLayoutNonce } from './native-layout-restore.js';
import { u2eCenteringState, u2eCenteringContext } from './u2e-appearance.js';
import { u2eTopBorderEvidence, u2eCtaMaterial } from './u2e-borders.js';
import { firstUniversalEvidenceDifference, u2eReadbackFailure,
    universalRedactedReadbackFixture, universalComposeBoundaryFixture } from './u2e-readback-diagnostics.js';
import { persistUniversalSessionReport, readUniversalSessionReport } from './u2e-session-report.js';
import { compareClassicQuoteReadback } from './u2e-quote-readback.js';

export const UNIVERSAL_COMPOSE_SESSION_KEY = 'railtime.u2e.compose.v1';
const operations = new WeakMap();
const lastReports = new WeakMap();
const plans = new WeakMap();
// Failed snapshot HTML stays private in this module. Errors/reports never carry
// authored text; only the finite, validated topology fixture may leave it.
const failedSnapshots = new WeakMap();
const oldKeys = ['railtime.template.inserted.v1', 'railtime.signature.layout.v1', 'railtime.head-probe.v1', 'railtime.u2e-probe.v1'];
const idPattern = /^(?:x_)?rt-sig-u2-([0-9a-f]{24})(-cell)?$/;
const documentKeys = ['schema', 'kind', 'html', 'version', 'marker', 'header', 'sourceHash', 'media'];
const assetSizes = Object.freeze({
    'b983af0049.png': [184, 32], '9dfa25b253.png': [15, 15], 'd92bd192b6.png': [15, 15],
    '1593d63cca.png': [15, 15], 'a38bc7d27c.png': [15, 15],
    '19125261c2.png': [24, 24], 'f9d88b899d.png': [24, 24], '1111670ac8.png': [24, 24], '14d7943c88.png': [500, 83],
});
const failure = (code, phase = 'u2e-compose-preflight') => Object.assign(new Error(code), { code, phase });
const layoutFailure = (checkpoint, expected, actual, index = null, phase = 'u2e-compose-preflight') =>
    Object.assign(failure('SIGNATURE_LAYOUT_UNCONFIRMED', phase), u2eReadbackFailure(checkpoint, expected, actual, index));
const indexed = elements => elements.map((node, index) => ({ node, index }));
function inside(elements, child, owner) {
    for (let at = child, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        if (at === owner) return true;
    }
    return false;
}
function nearest(elements, child, tag) {
    for (let at = elements[child]?.parent, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        if (elements[at]?.tag === tag) return at;
    }
    return null;
}
function quoted(elements, index) {
    for (let at = index, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        const node = elements[at];
        if (node.tag === 'blockquote' || /^(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)$/i.test(node.attrs.id || '')
            || /(?:^|\s)(?:gmail_quote|yahoo_quoted|moz-cite-prefix)(?:\s|$)/.test(node.attrs.class || '')) return true;
    }
    return false;
}
const children = (elements, owner, tags) => indexed(elements).filter(value => value.node.parent === owner && (!tags || tags.includes(value.node.tag)));
const tableRows = (elements, table) => indexed(elements).filter(value => value.node.tag === 'tr' && nearest(elements, value.index, 'table') === table);
const rowCells = (elements, row) => children(elements, row, ['td', 'th']);
const property = (node, name) => (node.attrs.style || '').split(';').flatMap(value => {
    const colon = value.indexOf(':');
    return colon >= 0 && value.slice(0, colon).trim().toLowerCase() === name
        ? [value.slice(colon + 1).trim().toLowerCase().replace(/\s*!important$/, '')] : [];
});
function dimension(node, name, expected) {
    const values = [...(node.attrs[name] === undefined ? [] : [node.attrs[name]]), ...property(node, name)];
    return values.length > 0 && values.every(value => {
        const match = /^(\d+(?:\.\d+)?)(pt|px)?$/i.exec(String(value).trim());
        return match && Math.abs(Number(match[1]) * (match[2]?.toLowerCase() === 'pt' ? 4 / 3 : 1) - expected) < 0.1;
    });
}
const span = (node, name) => node.attrs[name] === undefined ? 1 : /^\d+$/.test(node.attrs[name]) ? Number(node.attrs[name]) : -1;
function resemblesU2eShell(elements, value) {
    if (value.node.tag !== 'table') return false;
    if (idPattern.test(value.node.attrs.id || '') || /(?:^|\s)(?:x_)?rt-shell(?:\s|$)/.test(value.node.attrs.class || '')) return true;
    if (!dimension(value.node, 'width', 1040)) return false;
    const rows = tableRows(elements, value.index);
    const gridRow = rows.length === 4 ? rows[1] : rows.length === 3 ? rows[0] : null;
    if (!gridRow) return false;
    const grid = rowCells(elements, gridRow.index);
    return grid.length === 4 && grid.every((cell, at) => dimension(cell.node, 'width', [260, 280, 213, 287][at]))
        && indexed(elements).some(candidate => candidate.node.tag === 'table' && inside(elements, candidate.index, grid[3].index) && dimension(candidate.node, 'width', 247));
}
function unmarkedU2eShell(html) {
    const { elements } = parseManagedHtml(html);
    return indexed(elements).some(value => !quoted(elements, value.index) && resemblesU2eShell(elements, value)
        && !indexed(elements).some(marker => inside(elements, marker.index, value.index) && idPattern.test(marker.node.attrs.id || '')));
}
function hidden(node) {
    return Object.prototype.hasOwnProperty.call(node.attrs, 'hidden') || property(node, 'display').some(value => value === 'none')
        || property(node, 'visibility').some(value => ['hidden', 'collapse'].includes(value))
        || property(node, 'opacity').some(value => Number(value) === 0 || /^[+-]?(?:0+(?:\.0*)?|\.0+)%$/.test(value))
        || property(node, 'mso-hide').some(value => value === 'all');
}
function hiddenAncestor(elements, index) {
    for (let at = index, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        if (hidden(elements[at])) return true;
    }
    return false;
}
function decode(text) {
    const named = { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: ' ', zwnj: '', zwj: '', ndash: '–', mdash: '—', middot: '·',
        auml: 'ä', Auml: 'Ä', ouml: 'ö', Ouml: 'Ö', uuml: 'ü', Uuml: 'Ü', szlig: 'ß', euro: '€', copy: '©', reg: '®', trade: '™',
        hellip: '…', lsquo: '‘', rsquo: '’', ldquo: '“', rdquo: '”' };
    return text.replace(/&(#x[0-9a-f]+|#\d+|[a-z][a-z0-9]+);/gi, (original, key) => {
        if (key[0] !== '#') return named[key] ?? named[key.toLowerCase()] ?? original;
        const code = /^#x/i.test(key) ? parseInt(key.slice(2), 16) : parseInt(key.slice(1), 10);
        return code > 0 && code <= 0x10ffff ? String.fromCodePoint(code) : original;
    });
}
const text = value => decode(value.replace(/<!--[\s\S]*?-->|<[^>]*>/g, ' ')).replace(/[\u200c\u200d\ufeff]/g, '').replace(/\s+/g, ' ').trim();
function color(value) {
    const source = String(value || '').trim().toLowerCase();
    if (source === 'white') return '#ffffff';
    if (/^#[0-9a-f]{3}$/.test(source)) return '#' + [...source.slice(1)].map(c => c + c).join('');
    const rgb = /^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/.exec(source);
    return rgb && rgb.slice(1).every(c => Number(c) <= 255) ? '#' + rgb.slice(1).map(c => Number(c).toString(16).padStart(2, '0')).join('') : source;
}

function backgroundColor(node) {
    if (property(node, 'background-image').some(value => value !== 'none')) throw layoutFailure('colors.background-image');
    const values = [...(Object.prototype.hasOwnProperty.call(node.attrs, 'bgcolor') ? [node.attrs.bgcolor] : []), ...property(node, 'background-color'), ...property(node, 'background')].map(color);
    if (!values.length) return '';
    // Only equivalent finite color serializations are interchangeable. A
    // conflicting declaration, gradient or image is not a host normalization.
    if (values.some(value => !/^#[0-9a-f]{6}$/.test(value) || value !== values[0])) throw layoutFailure('colors.conflict');
    return values[0];
}

const centered = (node, elements = null, index = null) => elements && Number.isInteger(index)
    ? u2eCenteringContext(elements, index).centered : u2eCenteringState(node) === 'center';

function materialBackgroundColor(elements, value) {
    const explicit = backgroundColor(value.node);
    if (explicit || value.node.tag !== 'td') return explicit;
    const tableIndex = nearest(elements, value.index, 'table');
    // Word can omit an equal cell color. Only that cell's closest table may
    // supply it. An empty table is never skipped in search of another parent.
    return tableIndex !== null ? backgroundColor(elements[tableIndex]) : '';
}

// Host typography may use pt while the source uses px. Check positive finite
// dimensions only, without equating their values or claiming pixel parity.
function visibleCtaLine(line, row) {
    const positive = (value, attribute = false) => {
        const match = /^(\d+(?:\.\d+)?|\.\d+)(px|pt)?$/i.exec(String(value).trim());
        return Boolean(match && (attribute || match[2]) && Number(match[1]) > 0 && Number(match[1]) <= 1000000);
    };
    const heights = [...(line.attrs.height === undefined ? [] : [{ value: line.attrs.height, attribute: true }]),
        ...property(line, 'height').map(value => ({ value, attribute: false }))];
    if (!heights.length || heights.some(value => !positive(value.value, value.attribute))) throw layoutFailure('line.visibility');
    for (const node of [line, row]) {
        if (hidden(node)) throw layoutFailure('line.visibility');
        for (const name of ['height', 'line-height', 'font-size', 'max-height']) {
            if (property(node, name).some(value => !positive(value))) throw layoutFailure('line.visibility');
        }
    }
}

/** Datensparse read-only extraction, also useful for incompatible legacy
 * fixtures. It never claims U2e ownership or a successful insertion. */
export function universalComposePresentationEvidence(html) {
    try {
        const { elements } = parseManagedHtml(html);
        const colors = {};
        for (const node of elements.filter(value => value.tag === 'td')) {
            let value;
            try { value = backgroundColor(node) || 'unset'; } catch { value = 'invalid'; }
            colors[value] = (colors[value] || 0) + 1;
        }
        const tables = indexed(elements).filter(value => value.node.tag === 'table');
        return { schema: 1, readable: true, tables: tables.length, centeredTables: tables.filter(value => centered(value.node, elements, value.index)).length, colors };
    } catch { return { schema: 1, readable: false, tables: 0, centeredTables: 0, colors: {} }; }
}

function visibleDispatchCaps(html, elements, dispatch) {
    const rows = tableRows(elements, dispatch.index);
    const caps = [rows[0], rows[3]].map(row => ({ row, cell: rowCells(elements, row.index)[0] }));
    for (const { row, cell } of caps) {
        const descendants = cell ? indexed(elements).filter(value => value.index !== cell.index && inside(elements, value.index, cell.index)) : [];
        const paragraphs = descendants.filter(value => value.node.tag === 'p');
        if (!cell || text(html.slice(cell.node.openEnd, cell.node.close)) !== ''
            || paragraphs.length > 1 || descendants.some(value => !['p', 'span', 'font', 'o:p'].includes(value.node.tag)
                || Object.hasOwn(value.node.attrs, 'data-rt-redacted'))) throw layoutFailure('caps.structure');
        // Word can move the exact line box from the cell to its single empty
        // paragraph. Height remains on the cell; extra lines are not allowed.
        const line = property(cell.node, 'line-height').length ? cell.node : paragraphs.length === 1 ? paragraphs[0].node : null;
        if (!dimension(cell.node, 'height', 8) || !line || !dimension(line, 'line-height', 8)) throw layoutFailure('caps.visibility');
        try { visibleCtaLine(cell.node, row.node); } catch { throw layoutFailure('caps.visibility'); }
        // Word may serialize zero as 0cm. Nonzero padding would enlarge an
        // otherwise nominally 8px table cell and is never a cap normalization.
        const zero = value => value.trim().split(/\s+/).every(token => /^0(?:\.0+)?(?:px|pt|cm|mm|in|pc|em|rem|%)?$/.test(token));
        if ([cell, ...descendants].some(value => ['padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left']
            .some(name => property(value.node, name).some(candidate => !zero(candidate)))
            || value !== cell && ['height', 'min-height', 'max-height'].some(name => property(value.node, name).length
                && !dimension(value.node, name, 8)))) throw layoutFailure('caps.visibility');
    }
}

function region(html, composeType, { office = null, currentMessageOnly = false } = {}) {
    if (currentMessageOnly && mobileCurrentReplyBodyMode(office, composeType) !== null) {
        return { current: html, currentHtml: html, quote: '' };
    }
    const current = currentComposeBodyHtml(html, composeType);
    if (current === null) return null;
    let boundary = current.length;
    if (composeType !== 'newMail') {
        const separator = /<hr\b[^>]*>\s*$/i.exec(current);
        if (separator) boundary = separator.index;
    }
    const prefix = html.slice(0, boundary);
    // A reply prefix commonly ends inside BODY/DIV wrappers. Parse the complete
    // SDK document and close only those ancestors in a read-only analysis copy.
    // Neither the prefix nor quoted bytes submitted to Office are rewritten.
    const tree = parseManagedHtml(html);
    const closers = tree.elements.filter(node => node.openEnd <= boundary && node.close >= boundary && node.end > boundary)
        .sort((a, b) => b.start - a.start).map(node => '</' + node.tag + '>').join('');
    return { current: prefix, currentHtml: prefix + closers, quote: composeType === 'newMail' ? '' : html.slice(boundary) };
}

function material(html, expectedMarker = null, source = false) {
    let tree;
    try { tree = parseManagedHtml(html); } catch { throw layoutFailure('html.parse'); }
    const elements = tree.elements;
    const markers = indexed(elements).filter(value => idPattern.test(value.node.attrs.id || '') && !quoted(elements, value.index));
    if (!markers.length) return { present: false, tree };
    if (markers.length > 2) throw layoutFailure('markers.count', 2, markers.length);
    if (markers.some(value => !['table', 'td'].includes(value.node.tag))) throw layoutFailure('markers.tag');
    const versions = [...new Set(markers.map(value => idPattern.exec(value.node.attrs.id)[1]))];
    const roots = [...new Set(markers.map(value => value.node.tag === 'table' ? value.index : nearest(elements, value.index, 'table')))];
    if (versions.length !== 1 || roots.length !== 1 || roots[0] === null) throw layoutFailure('markers.binding');
    const rootIndex = roots[0], root = elements[rootIndex], marker = 'rt-sig-u2-' + versions[0];
    const outer = tableRows(elements, rootIndex);
    const kind = outer.length === 4 ? 'combined' : outer.length === 3 ? 'signature' : null;
    // A previous version is still owned/present. Do not reinterpret its old
    // topology under the new template, and never repair it in place.
    if (expectedMarker && marker !== expectedMarker) return { present: true, version: versions[0], marker, kind, tree };
    if (!kind) throw layoutFailure('shell.rows', [3, 4], outer.length);
    if (!dimension(root, 'width', 1040)) throw layoutFailure('shell.width');
    if (!centered(root, elements, rootIndex)) throw layoutFailure('shell.align');
    if (hiddenAncestor(elements, rootIndex)) throw layoutFailure('shell.hidden');
    const author = kind === 'combined' ? outer[0] : null, signatureRows = author ? outer.slice(1) : outer;
    const authorCells = author && rowCells(elements, author.index), grid = rowCells(elements, signatureRows[0].index), train = rowCells(elements, signatureRows[1].index), legal = rowCells(elements, signatureRows[2].index);
    if (author && (authorCells.length !== 1 || span(authorCells[0].node, 'colspan') !== 4)) throw layoutFailure('grid.author');
    if (grid.length !== 4 || grid.some((cell, index) => cell.node.tag !== 'td' || !dimension(cell.node, 'width', [260, 280, 213, 287][index]))) throw layoutFailure('grid.columns');
    if (span(grid[3].node, 'rowspan') !== 2) throw layoutFailure('grid.rowspan', 2, span(grid[3].node, 'rowspan'));
    if (train.length !== 1 || span(train[0].node, 'colspan') !== 3) throw layoutFailure('grid.train');
    if (legal.length !== 1 || span(legal[0].node, 'colspan') !== 4) throw layoutFailure('grid.legal');
    const tableMarkers = markers.filter(value => value.node.tag === 'table'), cellMarkers = markers.filter(value => value.node.tag === 'td');
    if (tableMarkers.length > 1 || tableMarkers.some(value => idPattern.exec(value.node.attrs.id)[2])
        || cellMarkers.length > 1 || cellMarkers.some(value => value.index !== grid[0].index || source && !idPattern.exec(value.node.attrs.id)[2])
        || source && (tableMarkers.length !== 1 || cellMarkers.length !== 1)) throw layoutFailure('markers.position');
    const shells = indexed(elements).filter(value => value.node.tag === 'table' && !quoted(elements, value.index)
        && !(author && inside(elements, value.index, author.index))
        && resemblesU2eShell(elements, value));
    if (shells.length !== 1 || shells[0].index !== rootIndex) throw layoutFailure('shell.count', 1, shells.length);
    const own = indexed(elements).filter(value => inside(elements, value.index, rootIndex) && !(author && inside(elements, value.index, author.index)));
    if (own.some(value => hidden(value.node))) throw layoutFailure('material.hidden');
    if (own.some(value => property(value.node, 'float').some(candidate => candidate !== 'none'))) throw layoutFailure('material.float');
    if (own.some(value => ['style', 'meta', 'script', 'iframe', 'object', 'embed', 'svg', 'input', 'form'].includes(value.node.tag))) throw layoutFailure('material.content');
    const tables = own.filter(value => value.node.tag === 'table');
    const topology = tables.map(value => ({
        parent: tables.findIndex(parent => parent.index === nearest(elements, value.index, 'table')),
        rows: tableRows(elements, value.index).filter(row => !author || row.index !== author.index)
            .map(row => rowCells(elements, row.index).map(cell => [cell.node.tag, span(cell.node, 'rowspan'), span(cell.node, 'colspan')])),
    }));
    const dispatch = tables.filter(value => dimension(value.node, 'width', 247) && inside(elements, value.index, grid[3].index));
    if (dispatch.length !== 1) throw layoutFailure('dispatch.count', 1, dispatch.length);
    const imageFiles = [], imageOrigins = new Set();
    const images = own.filter(value => value.node.tag === 'img').map(value => {
        const src = value.node.attrs.src || '', match = /^(https:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?)\/m\/([0-9a-f]{10}\.png)$/.exec(src), size = match && assetSizes[match[2]];
        if (!size) throw layoutFailure('images.src', undefined, undefined, imageFiles.length);
        if (!dimension(value.node, 'width', size[0])) throw layoutFailure('images.width', undefined, undefined, imageFiles.length);
        if (!dimension(value.node, 'height', size[1])) throw layoutFailure('images.height', undefined, undefined, imageFiles.length);
        imageFiles.push(match[2]); imageOrigins.add(match[1]);
        return { src, width: size[0], height: size[1] };
    });
    if (images.length < 8 || images.length > 10 || images[0].width !== 184 || images[images.length - 1].width !== 500) throw layoutFailure('images.count');
    const expectedImages = ['b983af0049.png', '9dfa25b253.png', ...Array(images.length - 8).fill('d92bd192b6.png'),
        '1593d63cca.png', 'a38bc7d27c.png', '19125261c2.png', 'f9d88b899d.png',
        '1111670ac8.png', '14d7943c88.png'];
    if (imageOrigins.size !== 1) throw layoutFailure('images.origin', 1, imageOrigins.size);
    if (JSON.stringify(imageFiles) !== JSON.stringify(expectedImages)) throw layoutFailure('images.order', expectedImages, imageFiles);
    const links = own.filter(value => value.node.tag === 'a').map(value => {
        if (text(html.slice(value.node.openEnd, value.node.close)) !== ''
            || own.filter(image => image.node.tag === 'img' && inside(elements, image.index, value.index)).length !== 1) throw layoutFailure('links.content');
        if (!/^(?:https:\/\/|mailto:|tel:)/i.test(value.node.attrs.href || '')) throw layoutFailure('links.scheme');
        return value.node.attrs.href;
    });
    if (links.length !== 4) throw layoutFailure('links.count', 4, links.length);
    const colors = own.filter(value => value.node.tag === 'td').map(value => materialBackgroundColor(elements, value));
    if (materialBackgroundColor(elements, legal[0]) !== '#e4e8ec') throw layoutFailure('colors.legal', '#e4e8ec', materialBackgroundColor(elements, legal[0]));
    if (backgroundColor(dispatch[0].node) !== '#e4002b') throw layoutFailure('colors.dispatch', '#e4002b', backgroundColor(dispatch[0].node));
    let borders, cta;
    try { cta = u2eCtaMaterial(elements, grid, dispatch); borders = u2eTopBorderEvidence(elements, grid, dispatch); }
    catch (error) { throw layoutFailure(['borders.structure', 'borders.invalid', 'borders.image', 'borders.cta'].includes(error?.checkpoint) ? error.checkpoint : 'borders.invalid'); }
    visibleDispatchCaps(html, elements, dispatch[0]);
    visibleCtaLine(cta.line.node, cta.lineRow.node);
    if (source && materialBackgroundColor(elements, cta.line) !== '#ffffff') throw layoutFailure('colors.line');
    if (own.filter(value => value.node.tag === 'td' && value.index !== cta.line.index && inside(elements, value.index, dispatch[0].index)).some(value => {
            // C-K21 v2.2 caps and panel share red. A missing equal cell
            // color still uses only its nearest table.
            return materialBackgroundColor(elements, value) !== '#e4002b';
        })) throw layoutFailure('colors.corners');
    const borderColors = borders.map(value => value.color);
    if (source && JSON.stringify(borderColors) !== JSON.stringify(Array(4).fill('#e4002b'))) throw layoutFailure('borders.invalid');
    let signatureHtml = html.slice(root.openEnd, root.close);
    if (author) signatureHtml = signatureHtml.slice(0, author.node.start - root.openEnd) + signatureHtml.slice(author.node.end - root.openEnd);
    return { present: true, tree, root, rootIndex, author, kind, version: versions[0], marker,
        evidence: { topology, images, links, colors, borderColors, text: text(signatureHtml) } };
}

// Source-only admission for the C-K15 additions. Received/compose HTML still
// uses the unchanged material reader and its existing comparison evidence.
function validateUniversalSourceStyles(plan) {
    const elements = plan.tree.elements, outer = tableRows(elements, plan.rootIndex);
    const grid = rowCells(elements, outer[plan.kind === 'combined' ? 1 : 0].index);
    const dispatch = indexed(elements).filter(value => value.node.tag === 'table'
        && dimension(value.node, 'width', 247) && inside(elements, value.index, grid[3].index));
    if (dispatch.length !== 1) throw failure('U2E_SOURCE_INVALID');
    const rows = tableRows(elements, dispatch[0].index), cta = u2eCtaMaterial(elements, grid, dispatch);
    if (rows.length !== 4) throw failure('U2E_SOURCE_INVALID');
    const top = rowCells(elements, rows[0].index), bottom = rowCells(elements, rows[3].index);
    if ([top, bottom].some(cells => cells.length !== 1 || cells[0].node.tag !== 'td'
        || !dimension(cells[0].node, 'height', 8) || !dimension(cells[0].node, 'line-height', 8))) throw failure('U2E_SOURCE_INVALID');
    if (JSON.stringify(property(dispatch[0].node, 'border-collapse')) !== JSON.stringify(['separate'])
        || JSON.stringify(property(dispatch[0].node, 'border-spacing')) !== JSON.stringify(['0'])
        || JSON.stringify(property(dispatch[0].node, 'border-radius')) !== JSON.stringify(['8px'])
        || JSON.stringify(property(top[0].node, 'border-radius')) !== JSON.stringify(['8px 8px 0 0'])
        || JSON.stringify(property(bottom[0].node, 'border-radius')) !== JSON.stringify(['0 0 8px 8px'])) throw failure('U2E_SOURCE_INVALID');
    const gridIds = new Set(grid.map(cell => cell.index));
    const exactIds = new Set([...top, ...bottom, cta.line, cta.actionCells[2]].map(cell => cell.index));
    if (gridIds.size !== 4 || exactIds.size !== 4) throw failure('U2E_SOURCE_INVALID');
    for (const { node, index } of indexed(elements)) {
        const isGrid = gridIds.has(index), isExact = exactIds.has(index);
        if (isGrid && [['display', 'inline-block'], ['max-width', '100%'], ['box-sizing', 'border-box']]
            .some(([name, value]) => JSON.stringify(property(node, name)) !== JSON.stringify([value]))) throw failure('U2E_SOURCE_INVALID');
        if (isExact && JSON.stringify(property(node, 'mso-line-height-rule')) !== JSON.stringify(['exactly'])) throw failure('U2E_SOURCE_INVALID');
        const remaining = String(node.attrs.style || '').split(';').filter(declaration =>
            !(isGrid && /^\s*display\s*:\s*inline-block\s*$/i.test(declaration))
            && !(isExact && /^\s*mso-line-height-rule\s*:\s*exactly\s*$/i.test(declaration))).join(';');
        if (/(?:mso-|table-layout\s*:|display\s*:\s*inline-block)/i.test(remaining)) throw failure('U2E_SOURCE_INVALID');
    }
}

export function validateUniversalComposeDocument(document) {
    if (document && plans.has(document)) return document;
    if (!document || typeof document !== 'object' || Array.isArray(document)
        || Object.keys(document).some(key => !documentKeys.includes(key)) || documentKeys.some(key => !Object.prototype.hasOwnProperty.call(document, key))
        || document.schema !== 1 || !['combined', 'signature'].includes(document.kind)
        || typeof document.html !== 'string' || !document.html.trim() || document.html.length > 27000 || document.html.includes('\0')
        || !/^[0-9a-f]{24}$/.test(document.version) || document.marker !== 'rt-sig-u2-' + document.version
        || document.header !== 'u2e-' + document.version || !/^[0-9a-f]{64}$/.test(document.sourceHash)
        || !Array.isArray(document.media) || document.media.length) throw failure('U2E_SOURCE_INVALID');
    try {
        const parsed = parseManagedHtml(document.html);
        if (parsed.elements.some(node => ['html', 'head', 'body', 'meta', 'style', 'script', 'iframe', 'object', 'embed', 'form', 'input', 'svg'].includes(node.tag)
            || Object.keys(node.attrs).some(key => /^on/i.test(key)) || hidden(node)
            || node.attrs.dir?.toLowerCase() === 'rtl')
            || /\[if\b|RT-(?:FLUID-LAYOUT|TEMPLATE-MANAGED|SIGNATURE-MANAGED|COMPOSE-EDITABLE)/i.test(document.html)) throw failure('U2E_SOURCE_INVALID');
        const plan = material(document.html, document.marker, true);
        if (!plan.present || !plan.evidence || plan.kind !== document.kind) throw failure('U2E_SOURCE_INVALID');
        validateUniversalSourceStyles(plan);
        const outside = document.html.slice(0, plan.root.start) + document.html.slice(plan.root.end);
        if (text(outside) || parsed.elements.some(node => (node.end <= plan.root.start || node.start >= plan.root.end)
            && !['div', 'p', 'span', 'br', 'font'].includes(node.tag))) throw failure('U2E_SOURCE_INVALID');
        const copy = Object.freeze({ ...document, media: Object.freeze([]) });
        plans.set(copy, plan);
        return copy;
    } catch { throw failure('U2E_SOURCE_INVALID'); }
}

export function universalComposeStateFromBody(document, html, composeType, scope = {}) {
    const state = { readable: false, present: false, current: false, kind: null, version: null };
    let checkpoint = 'source.invalid';
    try {
        const source = validateUniversalComposeDocument(document);
        checkpoint = 'html.parse';
        const scoped = region(html, composeType, scope);
        if (!scoped) return { ...state, ...u2eReadbackFailure('scope.boundary') };
        const actual = material(scoped.currentHtml, source.marker);
        if (!actual.present) return { ...state, readable: true, ...u2eReadbackFailure('state.present', true, false) };
        const expected = plans.get(source);
        const compatible = source.kind === 'signature' || actual.kind === 'combined';
        const evidenceDifference = compatible && actual.version === source.version
            ? firstUniversalEvidenceDifference(expected.evidence, actual.evidence) : null;
        const current = compatible && actual.version === source.version && evidenceDifference === null;
        const diagnostic = current ? {} : !compatible ? u2eReadbackFailure('state.kind', source.kind, actual.kind)
            : actual.version !== source.version ? u2eReadbackFailure('state.version', source.version, actual.version)
                : evidenceDifference || u2eReadbackFailure('readback.unknown');
        return { readable: true, present: true, kind: actual.kind, version: actual.version, current, ...diagnostic };
    } catch (error) { return { ...state, ...u2eReadbackFailure(error?.checkpoint || checkpoint), ...(error?.difference ? { difference: error.difference } : {}) }; }
}

export function universalComposeBlocked(item) {
    const operation = item && operations.get(item);
    return Boolean(operation && operation.report.state !== 'complete');
}

export function universalComposeReport(item) {
    const operation = item && operations.get(item);
    const report = operation?.report ?? (item && lastReports.get(item));
    return report ? { ...report } : null;
}

/** Diagnostic extraction uses the same conservative reply boundary as the
 * production reader. Never hand an entire conversation to the low-level
 * signature redactor. Unknown boundaries cannot produce a signature fixture;
 * snapshot.region failures use the separate text-free boundary format. */
export function universalComposeDiagnosticFixture(document, html, composeType, scope = {}) {
    try {
        const source = validateUniversalComposeDocument(document), scoped = region(html, composeType, scope);
        return scoped ? universalRedactedReadbackFixture(source.html, scoped.currentHtml) : null;
    } catch { return null; }
}

function readCall(office, invoke, code = 'U2E_CONTEXT_UNREADABLE') {
    return new Promise((resolve, reject) => {
        let settled = false;
        const finish = (error, value) => {
            if (settled) return;
            settled = true; clearTimeout(timer);
            if (error) reject(error); else resolve(value);
        };
        const timer = setTimeout(() => finish(failure(code)), 10000);
        try { invoke(result => result?.status === office.AsyncResultStatus.Succeeded ? finish(null, result.value)
            : finish(Object.assign(failure(code), { officeCode: String(result?.error?.code || '') }))); }
        catch { finish(failure(code)); }
    });
}

async function session(office, item, key) {
    try {
        const value = await readCall(office, callback => item.sessionData.getAsync(key, callback), 'U2E_SESSION_UNAVAILABLE');
        if (value === undefined || value === null) return '';
        if (typeof value !== 'string') throw failure('U2E_SESSION_UNAVAILABLE');
        return value;
    } catch (error) { if (error?.officeCode === '9050') return ''; throw error; }
}

function blankCurrent(html) {
    try {
        const { elements, comments } = parseManagedHtml(html), heads = elements.filter(node => node.tag === 'head');
        if (heads.length > 1 || comments.some(comment => !heads.some(head => comment.start >= head.start && comment.end <= head.end))
            || elements.some(node => ['script', 'iframe', 'object', 'embed', 'input', 'form'].includes(node.tag))
            || elements.some(node => !heads.some(head => node.start >= head.start && node.end <= head.end)
            && !['html', 'body', 'div', 'p', 'span', 'font', 'br', 'o:p'].includes(node.tag))) return false;
        let visible = html;
        for (const head of [...heads].sort((a, b) => b.start - a.start)) visible = visible.slice(0, head.start) + visible.slice(head.end);
        return text(visible) === '';
    } catch { return false; }
}

function mobileStandardSignature(html, office) {
    if (!isMobileComposeHost(office)) return false;
    const platform = String(office?.context?.platform || '').toLowerCase();
    const host = String(office?.context?.mailbox?.diagnostics?.hostName || '').toLowerCase();
    const device = platform === 'ios' || host === 'outlookios' ? 'iOS' : platform === 'android' || host === 'outlookandroid' ? 'Android' : null;
    if (!device) return false;
    try {
        const { elements, comments } = parseManagedHtml(html), heads = elements.filter(node => node.tag === 'head');
        const inHead = node => heads.some(head => node.start >= head.start && node.end <= head.end);
        if (heads.length > 1 || comments.some(comment => !inHead(comment))
            || elements.some(node => ['script', 'iframe', 'object', 'embed', 'input', 'form'].includes(node.tag))
            || elements.some(node => !inHead(node) && (!['html', 'body', 'div', 'p', 'span', 'font', 'br', 'o:p', 'a'].includes(node.tag) || hidden(node)))) return false;
        const links = elements.filter(node => node.tag === 'a' && !inHead(node));
        if (links.length > 1 || links.some(node => !/^https:\/\/aka\.ms\/[A-Za-z0-9]+$/.test(node.attrs.href || ''))) return false;
        let visible = html;
        for (const head of [...heads].sort((a, b) => b.start - a.start)) visible = visible.slice(0, head.start) + visible.slice(head.end);
        return ['Gesendet von Outlook für ' + device, 'Sent from Outlook for ' + device, 'Get Outlook for ' + device].includes(text(visible));
    } catch { return false; }
}

function authorEvidence(html, excludedRoot = null) {
    let input = excludedRoot ? html.slice(0, excludedRoot.start) + html.slice(excludedRoot.end) : html;
    const { elements } = parseManagedHtml(input), heads = elements.filter(node => node.tag === 'head');
    for (const head of [...heads].sort((a, b) => b.start - a.start)) input = input.slice(0, head.start) + input.slice(head.end);
    const clean = parseManagedHtml(input).elements;
    return JSON.stringify({ text: text(input), images: clean.filter(node => node.tag === 'img').map(node => [node.attrs.src, node.attrs.width, node.attrs.height]),
        links: clean.filter(node => node.tag === 'a').map(node => node.attrs.href) });
}

async function snapshot(office, item, report = null, lengthOrigin = 'original') {
    if (report) report.step = 'snapshot.type';
    const type = await readCall(office, callback => item.getComposeTypeAsync(callback));
    const composeType = type?.composeType;
    if (!['newMail', 'reply', 'forward'].includes(composeType)) throw failure('U2E_CONTEXT_UNREADABLE');
    if (report) { report.composeType = composeType; report.step = 'snapshot.format'; }
    const format = await readCall(office, callback => item.body.getTypeAsync(callback));
    if (format !== office.CoercionType.Html) throw failure('U2E_HTML_REQUIRED');
    if (report) report.step = 'snapshot.body';
    const bodyMode = mobileCurrentReplyBodyMode(office, composeType), currentMessageOnly = bodyMode !== null;
    // The SDK guarantees current-reply scope only on versioned mobile hosts.
    // All three snapshots use the same option; a failed scoped read never
    // retries FullBody and never turns an unknown forward into a new message.
    const html = await readCall(office, callback => currentMessageOnly
        ? item.body.getAsync(office.CoercionType.Html, { bodyMode }, callback)
        : item.body.getAsync(office.CoercionType.Html, callback));
    if (typeof html !== 'string' || html.length > 500000) throw failure('U2E_CONTEXT_UNREADABLE');
    if (report) { report.bodyLength = html.length; report.bodyLengthOrigin = lengthOrigin; report.step = 'snapshot.region'; }
    const scoped = region(html, composeType, { office, currentMessageOnly });
    if (!scoped) {
        const error = failure('U2E_NEW_DRAFT_REQUIRED');
        failedSnapshots.set(error, { html, composeType });
        throw error;
    }
    return { html, composeType, currentMessageOnly, ...scoped };
}

/** InternetHeaders is a documented mobile Mailbox-1.8 exception: mobile still
 * reports Mailbox 1.5. Require the concrete method and a canonical app version,
 * rather than granting capability from a server/unknown version or platform. */
export function supportsUniversalVersionHeader(office, item) {
    if (typeof item?.internetHeaders?.setAsync !== 'function') return false;
    if (!isMobileComposeHost(office)) return office?.context?.requirements?.isSetSupported?.('Mailbox', '1.8') === true;
    const diagnostics = office?.context?.mailbox?.diagnostics;
    if (!['OutlookIOS', 'OutlookAndroid'].includes(diagnostics?.hostName)) return false;
    const match = /^(\d+)\.(\d+)\.(\d+)(?:\.\d+)?$/.exec(String(diagnostics?.hostVersion || ''));
    if (!match || !match.slice(1).every(value => Number.isSafeInteger(Number(value)))) return false;
    const [major, minor] = match.slice(1).map(Number);
    return major > 4 || major === 4 && minor >= 2405;
}

async function settledLegacy(office, item) {
    if (isTemplateInsertionBlocked(item) || isStandaloneSignatureInsertionBlocked(item) || hasUncertainWrite(item)) throw failure('U2E_INSERT_UNCERTAIN');
    for (const key of oldKeys) {
        const value = await session(office, item, key);
        if (!['', '1'].includes(value)) throw failure('U2E_INSERT_UNCERTAIN');
        if (['railtime.head-probe.v1', 'railtime.u2e-probe.v1'].includes(key) && value) throw failure('U2E_NEW_DRAFT_REQUIRED');
    }
}

/** A source-bound single write. No body replacement, clearing, repair or send. */
export async function insertUniversalComposeDocument(office, item, document, { binding, beforeWrite = () => {} } = {}) {
    const source = validateUniversalComposeDocument(document), prior = operations.get(item);
    if (prior && prior.report.state !== 'complete') {
        prior.report = { ...prior.report, code: 'U2E_INSERT_UNCERTAIN' };
        throw failure('U2E_INSERT_UNCERTAIN');
    }
    if (!item || typeof item?.sessionData?.getAsync !== 'function' || typeof item?.sessionData?.setAsync !== 'function'
        || typeof item?.getComposeTypeAsync !== 'function' || typeof item?.body?.getTypeAsync !== 'function' || typeof item?.body?.getAsync !== 'function') throw failure('U2E_API_UNAVAILABLE');
    const mobile = isMobileComposeHost(office), method = source.kind === 'combined' && !mobile ? 'prependAsync' : 'setSignatureAsync';
    if (typeof item.body[method] !== 'function') throw failure('U2E_API_UNAVAILABLE');
    const operation = { claimed: false, report: { schema: 1, state: 'preflight', attempted: false, kind: source.kind, method, headerWritten: false,
        phase: 'preflight', sourceLength: source.html.length, bodyLength: null, mobileStandardSignatureIgnored: false,
        bodyWriteStarted: false, bodyWriteSucceeded: false, at: new Date().toISOString() } };
    let lastSnapshot = null;
    operations.set(item, operation);
    const guard = async () => {
        operation.report.step = 'binding';
        await beforeWrite(); await assertMailboxBinding(office, item, binding);
        if (office?.context?.mailbox?.item !== item) throw failure('COMPOSE_ITEM_CHANGED');
    };
    const guardClaim = async expected => {
        await guard();
        operation.report.step = 'session.claim';
        if (await session(office, item, UNIVERSAL_COMPOSE_SESSION_KEY) !== expected) throw failure('U2E_INSERT_UNCERTAIN');
        // No caller hook follows the claim check. A late item/sender change in
        // that read must still fail before the adjacent mutation/acknowledgement.
        operation.report.step = 'binding';
        await assertMailboxBinding(office, item, binding);
        if (office?.context?.mailbox?.item !== item) throw failure('COMPOSE_ITEM_CHANGED');
    };
    try {
        await guard(); operation.report.step = 'legacy'; await settledLegacy(office, item);
        operation.report.step = 'session.read';
        const priorClaim = await session(office, item, UNIVERSAL_COMPOSE_SESSION_KEY);
        if (priorClaim && !/^applied:[0-9a-f]{24}:(?:combined|signature)$/.test(priorClaim)) {
            const previous = await readUniversalSessionReport(office, item);
            operation.report = { ...operation.report, ...(previous || { bodyWriteStarted: null, bodyWriteSucceeded: null, at: null }),
                state: 'uncertain', code: 'U2E_INSERT_UNCERTAIN' };
            operation.recovered = true;
            throw failure('U2E_INSERT_UNCERTAIN');
        }
        const original = await snapshot(office, item, operation.report);
        lastSnapshot = original;
        operation.report.bodyLength = original.html.length;
        await guard();
        operation.report.step = 'source.state';
        const state = universalComposeStateFromBody(source, original.html, original.composeType, { office, currentMessageOnly: original.currentMessageOnly });
        if (state.current) {
            await guardClaim(priorClaim);
            operation.report = { ...operation.report, state: 'complete', current: true, phase: 'complete' };
            return { status: 'already-present', ...state };
        }
        if (!state.readable || priorClaim && !state.present) throw Object.assign(failure('SIGNATURE_LAYOUT_UNCONFIRMED'),
            u2eReadbackFailure(state.checkpoint || 'state.unreadable'), { difference: state.difference || null });
        const standardSignature = source.kind === 'combined' && mobile && mobileStandardSignature(original.currentHtml, office);
        operation.report.mobileStandardSignatureIgnored = standardSignature;
        if (state.present || unmarkedU2eShell(original.currentHtml)
            || /RT-(?:FLUID-LAYOUT|TEMPLATE-MANAGED|SIGNATURE-MANAGED|COMPOSE-EDITABLE)/i.test(original.current)
            || !standardSignature && /id\s*=\s*["'](?:x_)?Signature["']/i.test(original.current)
            || source.kind === 'combined' && !blankCurrent(original.currentHtml) && !standardSignature) throw failure('U2E_NEW_DRAFT_REQUIRED');
        const ownAuthor = authorEvidence(original.currentHtml);
        if (!supportsUniversalVersionHeader(office, item)) throw failure('U2E_HEADER_UNAVAILABLE');
        const token = 'pending:' + source.version + ':' + createNativeLayoutNonce();
        operation.claim = token;
        await guardClaim(priorClaim); operation.claimed = true; operation.report = { ...operation.report, attempted: true, state: 'pending', phase: 'claim' };
        operation.report.step = 'session.claim';
        await readCall(office, callback => item.sessionData.setAsync(UNIVERSAL_COMPOSE_SESSION_KEY, token, callback), 'U2E_INSERT_UNCERTAIN');
        if (await session(office, item, UNIVERSAL_COMPOSE_SESSION_KEY) !== token) throw failure('U2E_INSERT_UNCERTAIN');
        await guardClaim(token);
        operation.report.phase = 'header';
        operation.report.step = 'header.write';
        await confirmedOfficeWrite(office, item, 'u2e-header-write', 'U2E_INSERT_UNCERTAIN', callback => item.internetHeaders.setAsync({ 'X-RailTime-Template': source.header }, callback));
        operation.report.headerWritten = true;
        await guard(); operation.report.step = 'legacy'; await settledLegacy(office, item);
        const latest = await snapshot(office, item, operation.report);
        operation.report.step = 'context.compare';
        if (latest.html !== original.html || latest.composeType !== original.composeType || latest.currentMessageOnly !== original.currentMessageOnly) throw failure('U2E_CONTEXT_CHANGED');
        operation.report.step = 'session.claim';
        if (await session(office, item, UNIVERSAL_COMPOSE_SESSION_KEY) !== token) throw failure('U2E_INSERT_UNCERTAIN');
        await guardClaim(token);
        operation.report.phase = 'write';
        operation.report.step = 'body.write';
        operation.report.bodyWriteStarted = true;
        await confirmedOfficeWrite(office, item, 'u2e-compose-write', 'U2E_INSERT_UNCERTAIN',
            callback => item.body[method](source.html, { coercionType: office.CoercionType.Html }, callback), { deferSignatureConfirmation: true });
        operation.report.bodyWriteSucceeded = true;
        operation.report.at = new Date().toISOString();
        await guard();
        operation.report.phase = 'readback';
        const actual = await snapshot(office, item, operation.report, 'actual');
        operation.report.step = 'readback.material';
        const verified = universalComposeStateFromBody(source, actual.html, actual.composeType, { office, currentMessageOnly: actual.currentMessageOnly });
        lastSnapshot = actual;
        operation.report.bodyLength = actual.html.length;
        if (actual.composeType !== original.composeType || actual.currentMessageOnly !== original.currentMessageOnly) {
            operation.report.step = 'readback.compose_type';
            throw layoutFailure('compose.type', original.composeType, actual.composeType, null, 'u2e-compose-readback');
        }
        if (!verified.current) throw Object.assign(failure('SIGNATURE_LAYOUT_UNCONFIRMED', 'u2e-compose-readback'),
            u2eReadbackFailure(verified.checkpoint || 'state.unreadable'), { difference: verified.difference || null });
        operation.report.step = 'readback.quote';
        const quoteReadback = compareClassicQuoteReadback(original.quote, actual.quote, {
            host: office?.context?.mailbox?.diagnostics?.hostName, phase: operation.report.phase,
            step: operation.report.step, composeType: actual.composeType,
            bodyWriteStarted: operation.report.bodyWriteStarted, bodyWriteSucceeded: operation.report.bodyWriteSucceeded,
        });
        if (!quoteReadback.equal) throw layoutFailure('quote.bytes', original.quote, actual.quote, null, 'u2e-compose-readback');
        const actualMaterial = material(actual.currentHtml);
        operation.report.step = 'readback.author';
        if (source.kind === 'signature' && authorEvidence(actual.currentHtml, actualMaterial.root) !== ownAuthor) throw layoutFailure('author.signature', ownAuthor,
            authorEvidence(actual.currentHtml, actualMaterial.root), null, 'u2e-compose-author');
        if (source.kind === 'combined') {
            const expected = plans.get(source);
            if (!actualMaterial.author || authorEvidence(source.html.slice(expected.author.node.start, expected.author.node.end))
                !== authorEvidence(actual.currentHtml.slice(actualMaterial.author.node.start, actualMaterial.author.node.end))) throw layoutFailure('author.combined',
                authorEvidence(source.html.slice(expected.author.node.start, expected.author.node.end)), actualMaterial.author
                    ? authorEvidence(actual.currentHtml.slice(actualMaterial.author.node.start, actualMaterial.author.node.end)) : null, null, 'u2e-compose-author');
        }
        operation.report.step = 'session.verify';
        if (await session(office, item, UNIVERSAL_COMPOSE_SESSION_KEY) !== token) throw failure('U2E_INSERT_UNCERTAIN');
        await guardClaim(token);
        const terminal = 'applied:' + source.version + ':' + source.kind;
        operation.report.step = 'session.finalize';
        await readCall(office, callback => item.sessionData.setAsync(UNIVERSAL_COMPOSE_SESSION_KEY, terminal, callback), 'U2E_INSERT_UNCERTAIN');
        operation.report.step = 'session.verify';
        if (await session(office, item, UNIVERSAL_COMPOSE_SESSION_KEY) !== terminal) throw failure('U2E_INSERT_UNCERTAIN');
        await guardClaim(terminal); operation.report = { ...operation.report, state: 'complete', current: true, phase: 'complete' };
        return { status: 'applied', ...verified };
    } catch (error) {
        const code = /^[A-Z][A-Z0-9_]{0,79}$/.test(error?.code || '') ? error.code : 'UNAVAILABLE';
        if (operation.recovered) throw error;
        operation.report = { ...operation.report, code, originalCode: code };
        if (code === 'SIGNATURE_LAYOUT_UNCONFIRMED') {
            Object.assign(operation.report, u2eReadbackFailure(error?.checkpoint || 'readback.unknown'), { difference: error?.difference || null });
            if (String(binding?.mailboxAddress || '').toLowerCase() === 'it-technik@rail-time.de' && lastSnapshot) {
                try {
                    const fixture = universalComposeDiagnosticFixture(source, lastSnapshot.html, lastSnapshot.composeType, { office, currentMessageOnly: lastSnapshot.currentMessageOnly });
                    if (fixture) operation.report.fixture = fixture;
                } catch { /* Redaction cannot replace or widen the original failure. */ }
            }
        }
        const failedSnapshot = failedSnapshots.get(error);
        failedSnapshots.delete(error);
        const diagnosticsHost = String(office?.context?.mailbox?.diagnostics?.hostName || '');
        if (code === 'U2E_NEW_DRAFT_REQUIRED' && operation.report.phase === 'preflight' && operation.report.step === 'snapshot.region'
            && ['OutlookIOS', 'OutlookAndroid', 'OutlookWebApp'].includes(diagnosticsHost)
            && String(binding?.mailboxAddress || '').toLowerCase() === 'it-technik@rail-time.de' && failedSnapshot) {
            Object.assign(operation.report, u2eReadbackFailure('scope.boundary'));
            const fixture = universalComposeBoundaryFixture(source.html, failedSnapshot.html);
            if (fixture) operation.report.fixture = fixture;
        }
        if (operation.claimed) {
            operation.report = { ...operation.report, state: 'uncertain' };
            if (error?.code !== 'SIGNATURE_LAYOUT_UNCONFIRMED') operation.report.code = 'U2E_INSERT_UNCERTAIN';
            await persistUniversalSessionReport(office, item, operation.claim, operation.report);
            if (error?.code === 'SIGNATURE_LAYOUT_UNCONFIRMED') throw error;
            throw failure('U2E_INSERT_UNCERTAIN', error?.phase);
        }
        lastReports.set(item, { ...operation.report, state: 'rejected' });
        operations.delete(item); throw error;
    }
}
