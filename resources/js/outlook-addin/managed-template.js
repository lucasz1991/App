// Offset-only edits keep Outlook's unrelated HTML and quoted history opaque.
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
        .filter(value => /^RT-(?:COMPOSE-EDITABLE-|TEMPLATE-MANAGED-|SIGNATURE-MANAGED-|SIGNATURE-VERSION:)/.test(value.value));
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

function sentinelContract(html, currentLength, tree, spans) {
    const { elements } = tree;
    const boundaries = spans.filter(value => value.value.startsWith('RT-COMPOSE-EDITABLE-'));
    // A completely empty region can be coalesced into one hidden span. There
    // must then be no other TD content; text between the tokens is not editable.
    const mergedEmpty = boundaries.length === 1 && /^RT-COMPOSE-EDITABLE-START-V1\s*RT-COMPOSE-EDITABLE-END-V1$/.test(boundaries[0].value);
    if (!mergedEmpty && boundaries.length !== 2) unavailable();
    const start = mergedEmpty ? boundaries[0] : exactlyOne(boundaries.filter(value => value.value === EDITABLE_START));
    const end = mergedEmpty ? boundaries[0] : exactlyOne(boundaries.filter(value => value.value === EDITABLE_END));
    if (start.node.parent !== end.node.parent || elements[start.node.parent]?.tag !== 'td' || (!mergedEmpty && start.node.end > end.node.start)) unavailable();
    const hidden = node => owns(node.attrs, 'hidden') || node.attrs['aria-hidden'] === 'true'
        || /(?:^|;)\s*display\s*:\s*none(?:\s*!important)?\s*(?:;|$)/i.test(node.attrs.style || '');
    if (!hidden(start.node) || !hidden(end.node)) unavailable();
    const cellIndex = start.node.parent;
    const editableIndex = tableAncestor(elements, cellIndex);
    if (singleTableCell(elements, editableIndex).index !== cellIndex) unavailable();
    const cell = elements[cellIndex];
    const outer = html.slice(cell.openEnd, start.node.start) + html.slice(end.node.end, cell.close);
    if (decoded(outer).trim() !== '') unavailable();
    const templateMarker = exactlyOne(spans.filter(value => value.value.startsWith('RT-TEMPLATE-MANAGED-V1')));
    const signatureMarker = exactlyOne(spans.filter(value => value.value.startsWith('RT-SIGNATURE-MANAGED-')));
    const separateVersions = spans.filter(value => value.value.startsWith('RT-SIGNATURE-VERSION:'));
    // setAsync may coalesce the two adjacent hidden signature spans. Only the
    // exact two compiler tokens may share that span; arbitrary text may not.
    const merged = /^RT-SIGNATURE-MANAGED-V1\s*RT-SIGNATURE-VERSION:([0-9a-f]{16})$/i.exec(signatureMarker.value);
    const version = merged ? signatureMarker : exactlyOne(separateVersions);
    if (templateMarker.value !== MARKER || (merged ? separateVersions.length !== 0 : signatureMarker.value !== 'RT-SIGNATURE-MANAGED-V1')
        || (!merged && !/^RT-SIGNATURE-VERSION:[0-9a-f]{16}$/i.test(version.value))
        || !hidden(templateMarker.node) || !hidden(signatureMarker.node) || !hidden(version.node)
        || templateMarker.node.end > elements[editableIndex].start
        || within(elements, templateMarker.index, editableIndex)) unavailable();
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
        region: { start: start.node.end, end: mergedEmpty ? start.node.end : end.node.start, legacy: false } };
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
    const oldContract = rootContract(bodyHtml, currentHtml.length);
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
