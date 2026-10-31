// Offset-only edits keep Outlook's unrelated HTML and quoted history opaque.
import { inferredNativeGridRow, verifyFluidLayoutMaterial } from './native-layout-restore.js';
const TOKEN = /<!--[\s\S]*?-->|<(?:style|script|textarea|title)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>[\s\S]*?<\/(?:style|script|textarea|title)\s*>|<!doctype\b[^>]*>|<\?[^>]*\?>|<\/?[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi;
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const MODE = 'combined-v1';
const MARKER = 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT';
const EDITABLE_START = 'RT-COMPOSE-EDITABLE-START-V1';
const EDITABLE_END = 'RT-COMPOSE-EDITABLE-END-V1';
const MARKER_END = '(?=-->|[\\s"\'<>]|$)';
const combinedMarker = new RegExp(MARKER + MARKER_END);
const otherTemplateMarker = new RegExp(`RT-TEMPLATE-MANAGED-V1(?!:COMBINED-DOCUMENT${MARKER_END})`);
const owns = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
export const V15_READBACK_CONTRACT_STAGES = Object.freeze(['not-run', 'fluid-marker-shape', 'fluid-marker-hidden',
    'fluid-marker-occurrence', 'editable-markers', 'editable-marker-hidden', 'editable-start-carrier',
    'editable-end-carrier', 'editable-cell', 'document-markers', 'document-ownership', 'signature-footer', 'contract-complete']);

function contractCheckpoint(observer, stage, shape = null) {
    if (typeof observer === 'function') observer(stage, shape);
}

function matches(value, pattern) {
    const expression = new RegExp(pattern.source, pattern.flags);
    const result = [];
    let match;
    while ((match = expression.exec(value)) !== null) result.push(match);
    return result;
}

function unavailable() {
    throw Object.assign(new Error('TEMPLATE_REPLACEMENT_UNAVAILABLE'), { code: 'TEMPLATE_REPLACEMENT_UNAVAILABLE', phase: 'template-replace-preflight' });
}

function decoded(value) {
    return value.replace(/&(#x[0-9a-f]+|#\d+|amp|quot|apos|lt|gt|nbsp);/gi, (entity, code) => {
        if (code[0] !== '#') return { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: '\u00a0' }[code.toLowerCase()];
        const point = /^#x/i.test(code) ? parseInt(code.slice(2), 16) : parseInt(code.slice(1), 10);
        return point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : entity;
    });
}

function nodes(html) {
    const elements = [];
    const comments = [];
    const stack = [];
    let cursor = 0;
    for (const match of matches(html, TOKEN)) {
        const token = match[0];
        if (html.slice(cursor, match.index).includes('<')) unavailable();
        cursor = match.index + token.length;
        if (token.startsWith('<!--')) {
            comments.push({ value: token.slice(4, -3).trim(), start: match.index, end: cursor });
            continue;
        }
        if (/^<!(?:doctype)|^<\?/i.test(token)) continue;
        const name = /^<(\/?)([a-z][a-z0-9:-]*)\b/i.exec(token);
        const tag = name[2].toLowerCase();
        if (name[1]) {
            const index = stack.pop();
            if (index === undefined || elements[index].tag !== tag) unavailable();
            elements[index].close = match.index;
            elements[index].end = cursor;
            continue;
        }
        const opening = /^<[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/i.exec(token)[0];
        const attrs = Object.create(null);
        const attributes = opening.slice(name[0].length, -1).replace(/\/\s*$/, '');
        let end = 0;
        const attributePattern = /\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/gi;
        for (const attribute of matches(attributes, attributePattern)) {
            if (attributes.slice(end, attribute.index).trim() !== '') unavailable();
            const key = attribute[1].toLowerCase();
            if (owns(attrs, key)) unavailable();
            attrs[key] = decoded(attribute[2] ?? attribute[3] ?? attribute[4] ?? '');
            end = attribute.index + attribute[0].length;
        }
        if (attributes.slice(end).trim() !== '') unavailable();
        const raw = /^(?:style|script|textarea|title)$/.test(tag);
        const leaf = raw || VOID.has(tag) || /\/\s*>$/.test(opening);
        const closing = raw ? new RegExp(`<\\/${tag}\\s*>$`, 'i').exec(token) : null;
        if (raw && closing === null) unavailable();
        const node = { tag, attrs, parent: stack[stack.length - 1], start: match.index, openEnd: match.index + opening.length,
            close: raw ? cursor - closing[0].length : leaf ? cursor : null,
            end: leaf ? cursor : null };
        elements.push(node);
        if (!leaf) stack.push(elements.length - 1);
    }
    if (stack.length || html.slice(cursor).includes('<')) unavailable();
    return { elements, comments };
}

function has(node, name) {
    return (node.attrs.class || '').split(/\s+/).some(value => value === name || value === `x_${name}`);
}

function within(elements, child, parent) {
    for (let node = child; node !== undefined; node = elements[node].parent) {
        if (node === parent) return true;
    }
    return false;
}

function quoted(elements, index) {
    for (let current = index; current !== undefined; current = elements[current].parent) {
        const node = elements[current];
        if (node.tag === 'blockquote' || /^(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)$/i.test(node.attrs.id || '')
            || ['gmail_quote', 'yahoo_quoted', 'moz-cite-prefix'].some(name => has(node, name))) return true;
    }
    return false;
}

function quotedOffset(elements, offset) {
    return elements.some((node, index) => node.start <= offset && node.end > offset && quoted(elements, index));
}

function exactlyOne(values) {
    if (values.length !== 1) unavailable();
    return values[0];
}

function scopedTable(node, prefix, length) {
    const expression = new RegExp(`^(?:x_)?${prefix}[0-9a-f]{${length}}$`);
    return (node.attrs.class || '').split(/\s+/).filter(value => expression.test(value)).length === 1;
}

function activeMarker(html, elements, node, pattern) {
    return matches(html.slice(node.openEnd, node.close), new RegExp(pattern.source, pattern.flags.includes('g') ? pattern.flags : pattern.flags + 'g'))
        .filter(match => !quotedOffset(elements, node.openEnd + match.index));
}

function singleTableCell(elements, table) {
    const child = (parent, tag) => {
        const result = exactlyOne(elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === parent));
        if (result.node.tag !== tag) unavailable();
        return result;
    };
    return child(child(child(table, 'tbody').index, 'tr').index, 'td');
}

function markerSpans(html, tree, currentLength) {
    const candidates = tree.elements.map((node, index) => ({ node, index })).filter(({ node, index }) => node.tag === 'span'
        && node.end <= currentLength && !quoted(tree.elements, index)).map(value => ({ ...value,
        value: decoded(html.slice(value.node.openEnd, value.node.close).replace(/<!--[\s\S]*?-->|<[^>]*>/g, '')).trim() }))
        .filter(value => /^RT-(?:COMPOSE-EDITABLE-|TEMPLATE-MANAGED-|SIGNATURE-MANAGED-|SIGNATURE-VERSION:|FLUID-LAYOUT-V(?:1[1234]:|15))/.test(value.value));
    return candidates.filter(value => !candidates.some(outer => outer.index !== value.index && outer.value === value.value
        && within(tree.elements, value.index, outer.index)));
}

function tableAncestor(elements, index) {
    for (let current = index; current !== undefined; current = elements[current].parent) {
        if (elements[current].tag === 'table') return current;
    }
    unavailable();
}

function commonTable(elements, left, right) {
    for (let current = left; current !== undefined; current = elements[current].parent) {
        if (elements[current].tag === 'table' && within(elements, right, current)) return current;
    }
    unavailable();
}

function metadataHidden(node, elements) {
    // Observed Office profiles retain font-size:0 and line-height:0, while
    // setAsync also removes max-height/overflow from the hidden metadata spans.
    const values = Object.create(null);
    for (const declaration of (node.attrs.style || '').split(';')) {
        const colon = declaration.indexOf(':');
        if (colon <= 0) continue;
        const name = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase();
        if (name === 'font' || name === 'all') return false;
        if (['font-size', 'line-height'].includes(name) && !/^0(?:px|pt)?$/.test(value)) return false;
        values[name] = value;
    }
    if (owns(node.attrs, 'hidden') || node.attrs['aria-hidden'] === 'true'
        || /(?:^|;)\s*display\s*:\s*none(?:\s*!important)?\s*(?:;|$)/i.test(node.attrs.style || '')) return true;
    // Office moved the template marker's zero font onto its immediate DIV.
    // Resolve only the nearest explicit inherited value, bounded by this TD.
    for (let current = node.parent; current !== undefined; current = elements[current].parent) {
        const parent = elements[current];
        const inherited = Object.create(null);
        for (const declaration of (parent.attrs.style || '').split(';')) {
            const colon = declaration.indexOf(':');
            if (colon <= 0) continue;
            const name = declaration.slice(0, colon).trim().toLowerCase();
            if ((name === 'font' || name === 'all') && (!owns(values, 'font-size') || !owns(values, 'line-height'))) return false;
            if (!['font-size', 'line-height'].includes(name)) continue;
            const value = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase();
            if (!owns(values, name) && !/^0(?:px|pt)?$/.test(value)) return false;
            inherited[name] = value;
        }
        for (const name of Object.keys(inherited)) if (!owns(values, name)) values[name] = inherited[name];
        if (parent.tag === 'td' || ['font-size', 'line-height'].every(name => owns(values, name))) break;
    }
    return ['font-size', 'line-height'].every(name => /^0(?:px|pt)?$/.test(values[name] || ''));
}

/** Structural facts only; no text, attribute values or offsets leave this helper. */
function v15FluidMarkerShape(html, tree, fluid) {
    const { elements } = tree, selected = [];
    let truncated = false;
    for (let index = 0; index < elements.length; index++) {
        if (!within(elements, index, fluid.index)) continue;
        if (selected.length === 64) { truncated = true; break; }
        selected.push({ node: elements[index], index });
    }
    const text = node => decoded(html.slice(node.openEnd, node.close).replace(/<!--[\s\S]*?-->|<[^>]*>/g, '')).trim();
    const token = 'RT-FLUID-LAYOUT-V15' + fluid.profile + ':' + fluid.nonce + ':' + fluid.version;
    const exact = selected.filter(value => value.index !== fluid.index && value.node.tag === 'span' && text(value.node).toLowerCase() === token.toLowerCase());
    const leaves = exact.filter(value => !exact.some(inner => inner.index !== value.index && within(elements, inner.index, value.index)));
    let remainder = html.slice(fluid.node.openEnd, fluid.node.close);
    for (const leaf of [...leaves].sort((a, b) => b.node.start - a.node.start)) {
        const start = leaf.node.start - fluid.node.openEnd;
        remainder = remainder.slice(0, start) + remainder.slice(leaf.node.end - fluid.node.openEnd);
    }
    const fonts = [];
    for (const declaration of (fluid.node.attrs.style || '').split(';')) {
        const colon = declaration.indexOf(':');
        if (colon <= 0) continue;
        const name = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase();
        if (name === 'font' || name === 'all') fonts.push('conflict');
        if (name !== 'font-size') continue;
        fonts.push(/^0(?:px|pt)?$/.test(value) ? 'zero'
            : /^[0-9]+(?:\.[0-9]+)?(?:px|pt|em|rem|in|cm|mm|%)?$/.test(value) && Number.parseFloat(value) > 0 ? 'nonzero' : 'conflict');
    }
    const outerFont = !fonts.length ? 'missing' : fonts.every(value => value === fonts[0]) ? fonts[0] : 'conflict';
    const allowedAttributes = ['style', 'class', 'lang', 'xml:lang', 'dir', 'hidden', 'aria-hidden'];
    const ownZero = { 'font-size': false, 'line-height': false };
    let ownConflict = false;
    if (leaves.length === 1) {
        for (const declaration of (leaves[0].node.attrs.style || '').split(';')) {
            const colon = declaration.indexOf(':');
            if (colon <= 0) continue;
            const name = declaration.slice(0, colon).trim().toLowerCase();
            const value = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase();
            if (name === 'font' || name === 'all') ownConflict = true;
            if (!owns(ownZero, name)) continue;
            if (/^0(?:px|pt)?$/.test(value)) ownZero[name] = true;
            else ownConflict = true;
        }
    }
    return {
        outerTag: ['span', 'div', 'p', 'td', 'table'].includes(fluid.node.tag) ? fluid.node.tag : 'other', outerFont,
        innerExactTokenCount: leaves.length === 0 ? '0' : leaves.length === 1 ? '1' : 'many',
        innerExplicitHidden: leaves.length === 1 && (owns(leaves[0].node.attrs, 'hidden')
            || /(?:^|;)\s*display\s*:\s*none(?:\s*!important)?\s*(?:;|$)/i.test(leaves[0].node.attrs.style || '')),
        innerHiddenByExistingRule: leaves.length === 1 && metadataHidden(leaves[0].node, elements),
        innerOwnZeroMetrics: leaves.length === 1 && !ownConflict && ownZero['font-size'] && ownZero['line-height'],
        outsideText: decoded(remainder.replace(/<!--[\s\S]*?-->|<[^>]*>/g, '')).trim() !== '',
        descendantOtherTags: selected.some(value => value.index !== fluid.index && value.node.tag !== 'span'),
        unexpectedAttributes: selected.some(value => Object.keys(value.node.attrs).some(name => !allowedAttributes.includes(name))),
        nodeCount: selected.length, nodeCountTruncated: truncated,
        pureSpanSingleChain: !truncated && selected.every(value => value.node.tag === 'span'
            && selected.filter(child => child.node.parent === value.index).length <= 1),
    };
}

function boundaryCarrier(html, tree, span) {
    const { elements, comments } = tree;
    const parent = elements[span.node.parent];
    if (parent?.tag === 'td') return { ...span, cellIndex: span.node.parent };
    // setAsync adds one separate metadata-only DIV around each boundary.
    // Promote the complete DIV so the transplanted region stays balanced.
    if (parent?.tag !== 'div' || elements[parent.parent]?.tag !== 'td'
        || Object.keys(parent.attrs).some(name => !['style', 'class'].includes(name))
        || comments.some(comment => comment.start >= parent.openEnd && comment.end <= parent.close)
        || elements.some((node, index) => index !== span.node.parent && within(elements, index, span.node.parent) && !['span', 'br'].includes(node.tag))) unavailable();
    const remainder = html.slice(parent.openEnd, span.node.start) + html.slice(span.node.end, parent.close);
    if (decoded(remainder.replace(/<[^>]*>/g, '')).trim() !== '') unavailable();
    return { node: parent, index: span.node.parent, cellIndex: parent.parent };
}

function sentinelContract(html, currentLength, tree, spans, diagnosticObserver = null) {
    const { elements } = tree;
    contractCheckpoint(diagnosticObserver, 'editable-markers');
    const boundaries = spans.filter(value => value.value.startsWith('RT-COMPOSE-EDITABLE-'));
    // A completely empty region can be coalesced into one hidden span. There
    // must then be no other TD content; text between the tokens is not editable.
    const mergedEmpty = boundaries.length === 1 && /^RT-COMPOSE-EDITABLE-START-V1\s*RT-COMPOSE-EDITABLE-END-V1$/.test(boundaries[0].value);
    if (!mergedEmpty && boundaries.length !== 2) unavailable();
    const start = mergedEmpty ? boundaries[0] : exactlyOne(boundaries.filter(value => value.value === EDITABLE_START));
    const end = mergedEmpty ? boundaries[0] : exactlyOne(boundaries.filter(value => value.value === EDITABLE_END));
    contractCheckpoint(diagnosticObserver, 'editable-marker-hidden');
    if (!metadataHidden(start.node, elements) || !metadataHidden(end.node, elements)) unavailable();
    contractCheckpoint(diagnosticObserver, 'editable-start-carrier');
    const startBoundary = boundaryCarrier(html, tree, start);
    contractCheckpoint(diagnosticObserver, 'editable-end-carrier');
    const endBoundary = boundaryCarrier(html, tree, end);
    contractCheckpoint(diagnosticObserver, 'editable-cell');
    if (startBoundary.cellIndex !== endBoundary.cellIndex || (!mergedEmpty && startBoundary.node.end > endBoundary.node.start)) unavailable();
    const cellIndex = startBoundary.cellIndex;
    const editableIndex = tableAncestor(elements, cellIndex);
    if (singleTableCell(elements, editableIndex).index !== cellIndex) unavailable();
    const cell = elements[cellIndex];
    const outer = html.slice(cell.openEnd, startBoundary.node.start) + html.slice(endBoundary.node.end, cell.close);
    if (decoded(outer).trim() !== '') unavailable();
    contractCheckpoint(diagnosticObserver, 'document-markers');
    const templateMarker = exactlyOne(spans.filter(value => value.value.startsWith('RT-TEMPLATE-MANAGED-V1')));
    const signatureMarker = exactlyOne(spans.filter(value => value.value.startsWith('RT-SIGNATURE-MANAGED-')));
    const separateVersions = spans.filter(value => value.value.startsWith('RT-SIGNATURE-VERSION:'));
    // setAsync may coalesce the two adjacent hidden signature spans. Only the
    // exact two compiler tokens may share that span; arbitrary text may not.
    const merged = /^RT-SIGNATURE-MANAGED-V1\s*RT-SIGNATURE-VERSION:([0-9a-f]{16})$/i.exec(signatureMarker.value);
    const version = merged ? signatureMarker : exactlyOne(separateVersions);
    if (templateMarker.value !== MARKER || (merged ? separateVersions.length !== 0 : signatureMarker.value !== 'RT-SIGNATURE-MANAGED-V1')
        || (!merged && !/^RT-SIGNATURE-VERSION:[0-9a-f]{16}$/i.test(version.value))
        || !metadataHidden(templateMarker.node, elements) || !metadataHidden(signatureMarker.node, elements) || !metadataHidden(version.node, elements)
        || templateMarker.node.end > elements[editableIndex].start
        || within(elements, templateMarker.index, editableIndex)) unavailable();
    contractCheckpoint(diagnosticObserver, 'document-ownership');
    const templateIndex = commonTable(elements, templateMarker.index, editableIndex);
    const signatureIndex = tableAncestor(elements, signatureMarker.index);
    if (signatureIndex !== tableAncestor(elements, version.index) || elements[templateIndex].end > elements[signatureIndex].start
        || within(elements, signatureIndex, templateIndex) || within(elements, templateIndex, signatureIndex)) unavailable();
    const rootIndex = commonTable(elements, templateIndex, signatureIndex);
    const root = elements[rootIndex];
    const carrier = singleTableCell(elements, rootIndex);
    if (root.end > currentLength || !within(elements, templateIndex, carrier.index) || !within(elements, signatureIndex, carrier.index)) unavailable();
    const roleCounts = {};
    for (let index = 0; index < elements.length; index++) {
        const node = elements[index];
        if (node.start >= currentLength || quoted(elements, index)) continue;
        for (const original of (node.attrs.class || '').split(/\s+/)) {
            const name = original.replace(/^x_/, '');
            if (!/^rt-(?:managed-(?:combined|template|signature)|compose-editable)-v/.test(name)) continue;
            roleCounts[name] = (roleCounts[name] || 0) + 1;
            if (roleCounts[name] > 1 || node.tag !== 'table') unavailable();
            const correct = name === 'rt-managed-combined-v1' ? index === rootIndex
                : name === 'rt-managed-template-v1' ? within(elements, templateIndex, index) && within(elements, index, rootIndex) && !within(elements, signatureIndex, index)
                    : name === 'rt-managed-signature-v1' ? within(elements, signatureIndex, index) && within(elements, index, rootIndex) && !within(elements, templateIndex, index)
                        : name === 'rt-compose-editable-v1' ? index === editableIndex : false;
            if (!correct) unavailable();
        }
        if ((within(elements, index, rootIndex) || within(elements, rootIndex, index))
            && owns(node.attrs, 'data-rt-compose-document') && node.attrs['data-rt-compose-document'] !== MODE) unavailable();
        if (owns(node.attrs, 'data-rt-compose-editable') && (index !== editableIndex || node.attrs['data-rt-compose-editable'] !== 'v1')) unavailable();
    }
    for (const match of matches(html.slice(0, currentLength), /RT-TEMPLATE-MANAGED-V1/g)) {
        if ((match.index < root.start || match.index >= root.end) && !quotedOffset(elements, match.index)) unavailable();
    }
    return { ...tree, root, rootIndex, template: { node: elements[templateIndex], index: templateIndex },
        signature: { node: elements[signatureIndex], index: signatureIndex }, kind: 'text-v1',
        signatureMetadata: { managed: signatureMarker.index, version: version.index },
        region: { start: startBoundary.node.end, end: mergedEmpty ? startBoundary.node.end : endBoundary.node.start, legacy: false } };
}

function stableTableContract(html, currentLength, tree, candidates) {
    const { elements } = tree;
    const { node: root, index: rootIndex } = exactlyOne(candidates);
    if (root.tag !== 'table' || !has(root, 'rt-combined-compose-frame') || root.end > currentLength || !scopedTable(root, 'rtc', 12)) unavailable();
    const owner = (name, frameName, prefix, length) => {
        const result = exactlyOne(elements.map((node, index) => ({ node, index })).filter(value => within(elements, value.index, rootIndex)
            && !quoted(elements, value.index) && has(value.node, name)));
        if (result.node.tag !== 'table' || !has(result.node, frameName) || !scopedTable(result.node, prefix, length)) unavailable();
        return result;
    };
    const template = owner('rt-managed-template-v1', 'rt-native-compose-frame', 'rtt', 12);
    const signature = owner('rt-managed-signature-v1', 'rt-combined-signature-frame', 'rts', 10);
    if (template.node.end > signature.node.start || within(elements, signature.index, template.index)
        || !activeMarker(html, elements, template.node, combinedMarker).length
        || !activeMarker(html, elements, signature.node, /RT-SIGNATURE-MANAGED-V1(?=-->|[\s"'<>]|$)/).length) unavailable();
    const versions = activeMarker(html, elements, signature.node, /RT-SIGNATURE-VERSION:([0-9a-f]{16})(?=-->|[\s"'<>]|$)/);
    if (!versions.length || versions.some(match => match[1].toLowerCase() !== versions[0][1].toLowerCase())) unavailable();
    for (let index = rootIndex; index !== undefined; index = elements[index].parent) {
        const mode = elements[index].attrs['data-rt-compose-document'];
        if (mode !== undefined && mode !== MODE) unavailable();
    }
    if (elements.some((node, index) => within(elements, index, rootIndex) && !quoted(elements, index)
        && owns(node.attrs, 'data-rt-compose-document') && node.attrs['data-rt-compose-document'] !== MODE)) unavailable();
    if (activeMarker(html, elements, root, otherTemplateMarker).length) unavailable();
    for (const match of matches(html.slice(0, currentLength), /RT-TEMPLATE-MANAGED-V1/g)) {
        if ((match.index < root.start || match.index >= root.end) && !quotedOffset(elements, match.index)) unavailable();
    }
    return { ...tree, root, rootIndex, template, signature, kind: 'table-v1' };
}

function rootContract(html, currentLength) {
    const tree = nodes(html);
    const { elements } = tree;
    const spans = markerSpans(html, tree, currentLength);
    if (spans.some(value => value.value.startsWith('RT-COMPOSE-EDITABLE-'))) return sentinelContract(html, currentLength, tree, spans);
    const stable = elements.map((node, index) => ({ node, index })).filter(({ node, index }) => node.start < currentLength
        && !quoted(elements, index) && has(node, 'rt-managed-combined-v1'));
    if (stable.length) return stableTableContract(html, currentLength, tree, stable);
    const candidates = elements.map((node, index) => ({ node, index })).filter(({ node, index }) => node.start < currentLength
        && !quoted(elements, index) && (has(node, 'rt-combined-compose-document') || owns(node.attrs, 'data-rt-compose-document')));
    const { node: root, index: rootIndex } = exactlyOne(candidates);
    const rootHtml = html.slice(root.start, root.end);
    if (root.tag !== 'div' || !has(root, 'rt-combined-compose-document') || root.attrs['data-rt-compose-document'] !== MODE
        || root.end > currentLength
        || !matches(rootHtml, new RegExp(combinedMarker.source, 'g')).some(match => !quotedOffset(elements, root.start + match.index))
        || matches(rootHtml, new RegExp(otherTemplateMarker.source, 'g')).some(match => !quotedOffset(elements, root.start + match.index))) unavailable();
    const oneClass = (name, tag) => exactlyOne(elements.map((node, index) => ({ node, index }))
        .filter(value => within(elements, value.index, rootIndex) && !quoted(elements, value.index) && has(value.node, name) && value.node.tag === tag));
    const template = oneClass('rt-outlook-template', 'div');
    const signature = oneClass('rt-outlook-signature', 'div');
    const frame = oneClass('rt-combined-compose-frame', 'table');
    if (template.node.end > signature.node.start || !within(elements, template.index, frame.index)
        || !within(elements, signature.index, frame.index)
        || !new RegExp('RT-SIGNATURE-MANAGED-V1' + MARKER_END).test(html.slice(signature.node.start, signature.node.end))) unavailable();
    // An unowned marker outside this root is not permission to pick one of two
    // documents. Quoted history is ignored only through a recognized boundary.
    for (const match of matches(html.slice(0, currentLength), /RT-TEMPLATE-MANAGED-V1/g)) {
        if (match.index >= root.start && match.index < root.end) continue;
        if (!quotedOffset(elements, match.index)) unavailable();
    }
    return { ...tree, root, rootIndex, template, signature, kind: 'div-v1' };
}

/** Positive ownership contract for this exact byte string, without DOM rewriting. */
export function managedTemplateContract(html, currentHtml = html) {
    if (typeof html !== 'string' || typeof currentHtml !== 'string' || !html.startsWith(currentHtml)) unavailable();
    return rootContract(html, currentHtml.length);
}

/** Separate native-signature ownership: never infer a combined/editable document. */
export function standaloneSignatureContract(html, currentHtml = html, ids = null, expectedVersion = null) {
    if (typeof html !== 'string' || typeof currentHtml !== 'string' || !html.startsWith(currentHtml)) unavailable();
    const tree = nodes(html);
    const { elements } = tree;
    if (matches(currentHtml, /RT-TEMPLATE-MANAGED-V1|RT-COMPOSE-EDITABLE-(?:START|END)-V\d+/g).some(match => !quotedOffset(elements, match.index))) unavailable();
    const spans = markerSpans(html, tree, currentHtml.length);
    const managed = exactlyOne(spans.filter(value => value.value.startsWith('RT-SIGNATURE-MANAGED-')));
    const merged = /^RT-SIGNATURE-MANAGED-V1\s*RT-SIGNATURE-VERSION:([0-9a-f]{16})$/i.exec(managed.value);
    const versions = spans.filter(value => value.value.startsWith('RT-SIGNATURE-VERSION:'));
    const version = merged ? managed : exactlyOne(versions);
    const token = merged ? merged[1] : /^RT-SIGNATURE-VERSION:([0-9a-f]{16})$/i.exec(version.value)?.[1];
    if ((!merged && managed.value !== 'RT-SIGNATURE-MANAGED-V1') || (merged && versions.length)
        || !token || (expectedVersion !== null && token.toLowerCase() !== expectedVersion.toLowerCase())
        || !metadataHidden(managed.node, elements) || !metadataHidden(version.node, elements)) unavailable();
    const signatureIndex = tableAncestor(elements, managed.index);
    const signature = elements[signatureIndex];
    if (signatureIndex !== tableAncestor(elements, version.index) || signature.end > currentHtml.length || quoted(elements, signatureIndex)) unavailable();
    const owners = elements.map((node, index) => ({ node, index })).filter(value => value.node.start < currentHtml.length && !quoted(elements, value.index)
        && has(value.node, 'rt-managed-signature-v1'));
    let inferred = null;
    if (!ids) {
        const retained = /^(?:x_)?(rt-native-layout-[0-9a-f]{32}-)s$/.exec(signature.attrs.id || '');
        if (retained) {
            inferred = { root: retained[1] + 'r', person: retained[1] + 'p', company: retained[1] + 'c', train: retained[1] + 't', signature: retained[1] + 's' };
            if (elements.some(node => (node.attrs.id || '').endsWith(retained[1] + 'h'))) inferred.header = retained[1] + 'h';
            if (elements.some(node => (node.attrs.id || '').endsWith(retained[1] + 'l'))) inferred.logo = retained[1] + 'l';
            if (elements.some(node => (node.attrs.id || '').endsWith(retained[1] + 'i'))) inferred.inset = retained[1] + 'i';
            if (elements.some(node => (node.attrs.id || '').endsWith(retained[1] + 'f'))) inferred.footer = retained[1] + 'f';
            if (elements.some(node => (node.attrs.id || '').endsWith(retained[1] + 'w'))) inferred.window = retained[1] + 'w';
            for (const [key, suffix] of [['dispatch', 'd'], ['personCell', 'a'], ['companyCell', 'b'], ['dispatchCell', 'e'], ['trainCell', 'g'], ['lineCell', 'o']]) {
                if (elements.some(node => (node.attrs.id || '').endsWith(retained[1] + suffix))) inferred[key] = retained[1] + suffix;
            }
            // Only V9's retained dispatch anchor permits structural TD recovery.
            // The readback guard still proves each direct cell and inline fallback.
            if (inferred.dispatch) {
                for (const [key, suffix] of [['personCell', 'a'], ['companyCell', 'b'], ['dispatchCell', 'e'], ['trainCell', 'g'], ['lineCell', 'o'], ['footer', 'f']]) inferred[key] = retained[1] + suffix;
                const gridRow = inferredNativeGridRow(elements, retained[1]);
                if (gridRow) inferred.gridRow = gridRow;
            }
            const trainImages = ['j', 'k'].filter(suffix => elements.some(node => (node.attrs.id || '').endsWith(retained[1] + suffix)));
            if (trainImages.length > 1) unavailable();
            if (trainImages.length === 1) inferred.trainImage = retained[1] + trainImages[0];
        }
    }
    const boundIds = ids || inferred;
    if (boundIds) {
        const found = elements.map((node, index) => ({ node, index })).filter(value => typeof value.node.attrs.id === 'string' && value.node.attrs.id.endsWith(boundIds.signature || '\u0000'));
        if (!boundIds.signature || found.length !== 1 || found[0].index !== signatureIndex
            || ![boundIds.signature, `x_${boundIds.signature}`].includes(signature.attrs.id)
            || owners.some(value => value.index !== signatureIndex)) unavailable();
    } else if (owners.length !== 1 || owners[0].index !== signatureIndex || signature.tag !== 'table' || !scopedTable(signature, 'rts', 10)) unavailable();
    if (elements.some((node, index) => node.start < currentHtml.length && !quoted(elements, index)
        && ((node.attrs.class || '').split(/\s+/).some(name => /^(?:x_)?rt-managed-(?:combined|template)-v/.test(name)
            || (/^(?:x_)?rt-managed-signature-v/.test(name) && !/^(?:x_)?rt-managed-signature-v1$/.test(name)))
            || owns(node.attrs, 'data-rt-compose-document') || owns(node.attrs, 'data-rt-compose-editable')))) unavailable();
    return { ...tree, kind: 'standalone-signature-v1', root: signature, rootIndex: signatureIndex,
        signature: { node: signature, index: signatureIndex }, signatureMetadata: { managed: managed.index, version: version.index },
        version: token.toLowerCase(), layoutIds: boundIds };
}

/** Explicit fluid ownership protocols; no CSS target IDs are invented here. */
export function v15ReadbackParses(html) {
    if (typeof html !== 'string' || !/RT-FLUID-LAYOUT-V15[MB]:[0-9a-f]{32}:[0-9a-f]{16}/i.test(html)) return false;
    try { nodes(html); return true; } catch { return false; }
}

export function fluidLayoutContract(html, currentHtml = html, expectedNonce = null, expectedVersion = null, diagnosticObserver = null) {
    if (typeof html !== 'string' || typeof currentHtml !== 'string' || !html.startsWith(currentHtml)) unavailable();
    const original = nodes(html);
    // Word may omit optional TBODY tags. Model their actual implied DOM shape
    // only in this explicitly nonce-bound family, never in historical guards.
    const elements = original.elements.map(node => ({ ...node, attrs: { ...node.attrs } }));
    for (let index = 0, count = elements.length; index < count; index++) {
        if (elements[index].tag !== 'table') continue;
        const rows = elements.map((node, position) => ({ node, index: position })).filter(value => value.node.parent === index);
        if (!rows.length || rows.some(value => value.node.tag !== 'tr')) continue;
        const body = elements.length;
        elements.push({ tag: 'tbody', attrs: {}, parent: index, start: rows[0].node.start, openEnd: rows[0].node.start,
            close: rows[rows.length - 1].node.end, end: rows[rows.length - 1].node.end });
        for (const row of rows) elements[row.index].parent = body;
    }
    const trace = /RT-FLUID-LAYOUT-V15[MB]:[0-9a-f]{32}:[0-9a-f]{16}/i.test(currentHtml) ? diagnosticObserver : null;
    contractCheckpoint(trace, 'fluid-marker-shape');
    const tree = { ...original, elements }, spans = markerSpans(html, tree, currentHtml.length);
    const found = [];
    const canonical = spans.map(span => {
        const profile = /^RT-FLUID-LAYOUT-V15([MB]):([0-9a-f]{32}):([0-9a-f]{16})$/i.exec(span.value);
        const mergedProfile = /^(RT-SIGNATURE-(?:MANAGED-V1\s*RT-SIGNATURE-VERSION:|VERSION:)([0-9a-f]{16}))\s*RT-FLUID-LAYOUT-V15([MB]):([0-9a-f]{32}):([0-9a-f]{16})$/i.exec(span.value);
        if (profile) {
            found.push({ ...span, family: 15, profile: profile[1].toUpperCase(), nonce: profile[2].toLowerCase(), version: profile[3].toLowerCase() });
            return null;
        }
        if (mergedProfile) {
            if (mergedProfile[2].toLowerCase() !== mergedProfile[5].toLowerCase()) unavailable();
            found.push({ ...span, family: 15, profile: mergedProfile[3].toUpperCase(), nonce: mergedProfile[4].toLowerCase(), version: mergedProfile[5].toLowerCase() });
            return { ...span, value: mergedProfile[1] };
        }
        const separate = /^RT-FLUID-LAYOUT-V(11|12|13|14):([0-9a-f]{32}):([0-9a-f]{16})$/i.exec(span.value);
        const merged = /^(RT-SIGNATURE-(?:MANAGED-V1\s*RT-SIGNATURE-VERSION:|VERSION:)([0-9a-f]{16}))\s*RT-FLUID-LAYOUT-V(11|12|13|14):([0-9a-f]{32}):([0-9a-f]{16})$/i.exec(span.value);
        if (separate) {
            found.push({ ...span, family: Number(separate[1]), nonce: separate[2].toLowerCase(), version: separate[3].toLowerCase() });
            return null;
        }
        if (merged) {
            if (merged[2].toLowerCase() !== merged[5].toLowerCase()) unavailable();
            found.push({ ...span, family: Number(merged[3]), nonce: merged[4].toLowerCase(), version: merged[5].toLowerCase() });
            return { ...span, value: merged[1] };
        }
        return span;
    }).filter(Boolean);
    const fluid = exactlyOne(found);
    contractCheckpoint(trace, 'fluid-marker-hidden', typeof trace === 'function' && fluid.family === 15 ? v15FluidMarkerShape(html, tree, fluid) : null);
    if (!metadataHidden(fluid.node, elements) || (expectedNonce !== null && fluid.nonce !== expectedNonce)
        || (expectedVersion !== null && fluid.version !== expectedVersion.toLowerCase())) unavailable();
    contractCheckpoint(trace, 'fluid-marker-occurrence');
    const occurrences = matches(currentHtml, /RT-FLUID-LAYOUT-V(?:1[1234]:|15)/g).filter(match => !quotedOffset(elements, match.index)
        && !tree.comments.some(comment => match.index >= comment.start && match.index < comment.end));
    if (occurrences.length !== 1 || occurrences[0].index < fluid.node.openEnd || occurrences[0].index >= fluid.node.close) unavailable();
    const template = canonical.filter(span => span.value.startsWith('RT-TEMPLATE-MANAGED-'));
    let contract;
    if (template.length) {
        contract = sentinelContract(html, currentHtml.length, tree, canonical, trace);
    } else {
        contractCheckpoint(trace, 'document-markers');
        if (canonical.some(span => span.value.startsWith('RT-COMPOSE-EDITABLE-'))
            || matches(currentHtml, /RT-TEMPLATE-MANAGED-V1/g).some(match => !quotedOffset(elements, match.index))) unavailable();
        const managed = exactlyOne(canonical.filter(span => span.value.startsWith('RT-SIGNATURE-MANAGED-')));
        const merged = /^RT-SIGNATURE-MANAGED-V1\s*RT-SIGNATURE-VERSION:([0-9a-f]{16})$/i.exec(managed.value);
        const versions = canonical.filter(span => span.value.startsWith('RT-SIGNATURE-VERSION:'));
        const version = merged ? managed : exactlyOne(versions);
        const token = merged?.[1] || /^RT-SIGNATURE-VERSION:([0-9a-f]{16})$/i.exec(version.value)?.[1];
        if ((!merged && managed.value !== 'RT-SIGNATURE-MANAGED-V1') || (merged && versions.length)
            || token?.toLowerCase() !== fluid.version || !metadataHidden(managed.node, elements) || !metadataHidden(version.node, elements)) unavailable();
        contractCheckpoint(trace, 'document-ownership');
        const signatureIndex = tableAncestor(elements, managed.index), signature = elements[signatureIndex];
        if (signatureIndex !== tableAncestor(elements, version.index) || signature.end > currentHtml.length || quoted(elements, signatureIndex)) unavailable();
        contract = { ...tree, kind: 'standalone-signature-v1', root: signature, rootIndex: signatureIndex,
            signature: { node: signature, index: signatureIndex }, signatureMetadata: { managed: managed.index, version: version.index }, version: fluid.version };
    }
    contractCheckpoint(trace, 'signature-footer');
    if (!within(elements, fluid.index, contract.signature.index) || quoted(elements, fluid.index)) unavailable();
    const bodies = elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === contract.signature.index);
    if (bodies.length !== 1 || bodies[0].node.tag !== 'tbody') unavailable();
    const rows = elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === bodies[0].index);
    if (rows.length !== 2 || rows.some(value => value.node.tag !== 'tr') || !within(elements, fluid.index, rows[1].index)) unavailable();
    contractCheckpoint(trace, 'contract-complete');
    return { ...contract, fluidLayout: { family: fluid.family, nonce: fluid.nonce, version: fluid.version, marker: { node: fluid.node, index: fluid.index }, ...(fluid.family === 15 ? { profile: fluid.profile } : {}) } };
}

export function hasUnquotedFluidLayoutMarker(html, currentHtml = html) {
    if (typeof currentHtml !== 'string' || !html.startsWith(currentHtml)) unavailable();
    const tree = nodes(html);
    return matches(currentHtml, /RT-FLUID-LAYOUT-V(?:1[1234]:|15)/g).some(match => !quotedOffset(tree.elements, match.index)
        && !tree.comments.some(comment => match.index >= comment.start && match.index < comment.end));
}

export function hasUnquotedSignatureLayoutCarrier(html, currentHtml = html) {
    if (typeof currentHtml !== 'string' || !html.startsWith(currentHtml)) unavailable();
    const tree = nodes(html);
    return tree.elements.some((node, index) => node.start < currentHtml.length && !quoted(tree.elements, index)
        && /rt-native-layout-[0-9a-f]{32}-carrier$/.test(node.attrs.id || ''))
        || matches(currentHtml, /RT-NATIVE-LAYOUT-RESTORE-V1:[0-9a-f]{32}/g).some(match => !quotedOffset(tree.elements, match.index));
}

function editableRegion(html, contract, allowLegacy) {
    const { elements, template, comments } = contract;
    if (contract.kind === 'text-v1') return contract.region;
    if (contract.kind === 'table-v1') {
        const { node, index } = exactlyOne(elements.map((element, position) => ({ node: element, index: position }))
            .filter(value => within(elements, value.index, contract.rootIndex) && !quoted(elements, value.index)
                && (has(value.node, 'rt-compose-editable') || has(value.node, 'rt-compose-editable-v1') || owns(value.node.attrs, 'data-rt-compose-editable'))));
        if (node.tag !== 'table' || !has(node, 'rt-compose-editable') || !has(node, 'rt-compose-editable-v1')
            || !within(elements, index, template.index)
            || (owns(node.attrs, 'data-rt-compose-editable') && node.attrs['data-rt-compose-editable'] !== 'v1')) unavailable();
        const child = (parent, tag) => {
            const found = exactlyOne(elements.map((element, position) => ({ node: element, index: position })).filter(value => value.node.parent === parent));
            if (found.node.tag !== tag) unavailable();
            return found;
        };
        const body = child(index, 'tbody');
        const row = child(body.index, 'tr');
        const cell = child(row.index, 'td');
        return { start: cell.node.openEnd, end: cell.node.close, legacy: false };
    }
    const candidates = elements.map((node, index) => ({ node, index })).filter(({ node, index }) => within(elements, index, contract.rootIndex) && !quoted(elements, index)
        && (has(node, 'rt-compose-editable') || owns(node.attrs, 'data-rt-compose-editable')));
    if (candidates.length) {
        const { node, index } = exactlyOne(candidates);
        if (node.tag !== 'div' || !has(node, 'rt-compose-editable') || node.attrs['data-rt-compose-editable'] !== 'v1'
            || !within(elements, index, template.index) || quoted(elements, index)) unavailable();
        return { start: node.openEnd, end: node.close, legacy: false };
    }
    if (!allowLegacy) unavailable();
    const start = exactlyOne(comments.filter(node => node.value === 'RT_APPLICATION_CONTENT_START'
        && node.start > template.node.openEnd && node.end < template.node.close && !quotedOffset(elements, node.start)));
    const end = exactlyOne(comments.filter(node => node.value === 'RT_APPLICATION_CONTENT_END'
        && node.start > start.end && node.end < template.node.close && !quotedOffset(elements, node.start)));
    const paragraphs = elements.filter((node, index) => node.tag === 'p' && has(node, 'design-spacer') && !quoted(elements, index) && node.start >= start.end && node.end <= end.start);
    const paragraph = exactlyOne(paragraphs);
    if (elements.filter((node, index) => node.tag === 'p' && has(node, 'design-spacer') && !quoted(elements, index) && within(elements, index, template.index)).length !== 1) unavailable();
    const parent = elements[paragraph.parent];
    if (!parent || parent.tag !== 'td' || !has(parent, 'rt-native-template-pad')) unavailable();
    // Compatibility is deliberately limited to the old default. Old edited
    // greetings or other application content cannot be silently discarded.
    if (elements.some((node, index) => within(elements, index, template.index) && !quoted(elements, index)
        && (has(node, 'design-salutation') || has(node, 'design-greeting')))) unavailable();
    const surrounding = html.slice(start.end, paragraph.start) + html.slice(paragraph.end, end.start);
    if (decoded(surrounding.replace(/<!--[^]*?-->|<[^>]*>/g, '')).trim() !== ''
        || /<(?!\/?(?:table|tbody|tr|td|br)\b|!--)[a-z]/i.test(surrounding)) unavailable();
    return { start: paragraph.start, end: paragraph.end, legacy: true };
}

/** Replace one known current-message shell while preserving all other bytes. */
export function managedTemplateReplacement(bodyHtml, currentHtml, nextHtml) {
    if (typeof bodyHtml !== 'string' || typeof currentHtml !== 'string' || !bodyHtml.startsWith(currentHtml)
        || typeof nextHtml !== 'string') unavailable();
    const oldContract = hasUnquotedFluidLayoutMarker(bodyHtml, currentHtml)
        ? fluidLayoutContract(bodyHtml, currentHtml) : rootContract(bodyHtml, currentHtml.length);
    if (oldContract.fluidLayout) verifyFluidLayoutMaterial(bodyHtml, oldContract);
    const nextContract = rootContract(nextHtml, nextHtml.length);
    const oldRegion = editableRegion(bodyHtml, oldContract, true);
    const nextRegion = editableRegion(nextHtml, nextContract, false);
    if (oldContract.kind === 'text-v1' && nextContract.kind !== 'text-v1') unavailable();
    if (oldContract.kind === 'table-v1' && nextContract.kind === 'div-v1') unavailable();
    // The combined compiler emits one optional carrier sheet before its root.
    // Put that exact owned sheet inside the new root so repeated replacements
    // remove it with their shell. Unrelated old outside styles stay byte-exact.
    const outside = nextContract.elements.map((node, index) => ({ node, index })).filter(value => !within(nextContract.elements, value.index, nextContract.rootIndex));
    if (nextContract.kind !== 'div-v1') {
        if (outside.length > 1 || outside.some(({ node, index }) => node.tag !== 'div' || !within(nextContract.elements, nextContract.rootIndex, index)
            || !has(node, 'rt-combined-compose-document') || node.attrs['data-rt-compose-document'] !== MODE)) unavailable();
    } else if (outside.some(({ node }) => node.tag !== 'style' || node.attrs['data-rt-combined-compose-css'] !== '1'
        || Object.keys(node.attrs).length !== 1)) unavailable();
    const prefix = nextHtml.slice(0, nextContract.root.start);
    const suffix = nextHtml.slice(nextContract.root.end);
    if ((prefix + suffix).replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>|<\/?div\b(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi, '').trim() !== '') unavailable();
    const content = bodyHtml.slice(oldRegion.start, oldRegion.end);
    for (const match of matches(content, /RT-(?:TEMPLATE|SIGNATURE)-MANAGED|RT-COMPOSE-EDITABLE-(?:START|END)-V\d+|data-rt-compose-document\s*=/gi)) {
        if (!quotedOffset(oldContract.elements, oldRegion.start + match.index)) unavailable();
    }
    const replacement = nextContract.kind !== 'div-v1'
        ? nextHtml.slice(nextContract.root.start, nextRegion.start) + content + nextHtml.slice(nextRegion.end, nextContract.root.end)
        : nextHtml.slice(nextContract.root.start, nextContract.root.openEnd) + prefix + suffix
            + nextHtml.slice(nextContract.root.openEnd, nextRegion.start) + content + nextHtml.slice(nextRegion.end, nextContract.root.end);
    const html = bodyHtml.slice(0, oldContract.root.start) + replacement + bodyHtml.slice(oldContract.root.end);
    return Object.freeze({ html, legacy: oldRegion.legacy });
}
