// Offset-only edits keep Outlook's unrelated HTML and quoted history opaque.
const TOKEN = /<!--[\s\S]*?-->|<(?:style|script|textarea|title)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>[\s\S]*?<\/(?:style|script|textarea|title)\s*>|<!doctype\b[^>]*>|<\?[^>]*\?>|<\/?[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi;
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const MODE = 'combined-v1';
const MARKER = 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT';
const MARKER_END = '(?=-->|[\\s"\'<>]|$)';
const combinedMarker = new RegExp(MARKER + MARKER_END);
const otherTemplateMarker = new RegExp(`RT-TEMPLATE-MANAGED-V1(?!:COMBINED-DOCUMENT${MARKER_END})`);
const owns = (value, key) => Object.prototype.hasOwnProperty.call(value, key);
let contractWarnings = 0;

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

function rootContract(html, currentLength) {
    const tree = nodes(html);
    const { elements } = tree;
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
    return { ...tree, root, rootIndex, template, signature };
}

function editableRegion(html, contract, allowLegacy) {
    const { elements, template, comments } = contract;
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

/** Counts and fixed ownership labels only; never return user HTML/text/URLs. */
export function inspectManagedTemplateContract(html, currentHtml = null) {
    const source = typeof html === 'string' ? html.slice(0, 500000) : '';
    const known = ['rt-combined-compose-document', 'rt-combined-compose-frame', 'rt-outlook-template', 'rt-outlook-signature',
        'rt-compose-editable', 'rt-native-template-pad', 'rt-office-metadata', 'design-spacer', 'design-salutation', 'design-greeting'];
    const counts = {};
    known.forEach(name => { counts[name] = 0; });
    const roots = [];
    const scopes = [];
    let editableV1 = 0;
    let editableOther = 0;
    const attribute = (tag, name) => {
        const match = new RegExp(`\\s${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)')`, 'i').exec(tag);
        return match ? decoded(match[1] ?? match[2]) : null;
    };
    for (const match of matches(source, /<[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi)) {
        const tag = match[0];
        const classes = (attribute(tag, 'class') || '').split(/\s+/);
        const hasClass = name => classes.includes(name) || classes.includes(`x_${name}`);
        known.forEach(name => { if (hasClass(name)) counts[name] = Math.min(999, counts[name] + 1); });
        classes.filter(name => /^(?:x_)?(?:rtc[0-9a-f]{12}|rtt[0-9a-f]{12}|rts[0-9a-f]{10})$/.test(name)).forEach(name => {
            if (scopes.length < 8 && !scopes.includes(name)) scopes.push(name);
        });
        if (hasClass('rt-combined-compose-document') && roots.length < 3) {
            const mode = attribute(tag, 'data-rt-compose-document');
            roots.push({ tag: /^<div\b/i.test(tag) ? 'div' : 'other', officePrefixed: classes.includes('x_rt-combined-compose-document'),
                mode: mode === null ? 'missing' : ['combined-v1', 'combined-native-v1'].includes(mode) ? mode : 'other',
                withinCurrent: typeof currentHtml === 'string' ? match.index < currentHtml.length : null });
        }
        if (hasClass('rt-compose-editable')) {
            const value = attribute(tag, 'data-rt-compose-editable');
            if (value === 'v1') editableV1 = Math.min(999, editableV1 + 1);
            else if (value !== null) editableOther = Math.min(999, editableOther + 1);
        }
    }
    let parseable = false;
    try { nodes(source); parseable = true; } catch { /* Diagnostic only. */ }
    return { schema: 1, length: typeof html === 'string' ? html.length : null, truncated: typeof html === 'string' && html.length > source.length,
        currentLength: typeof currentHtml === 'string' ? currentHtml.length : null, parseable, counts, roots, scopes, editableV1, editableOther,
        appStart: Math.min(999, matches(source, /<!--\s*RT_APPLICATION_CONTENT_START\s*-->/g).length),
        appEnd: Math.min(999, matches(source, /<!--\s*RT_APPLICATION_CONTENT_END\s*-->/g).length),
        markers: { combined: combinedMarker.test(source), signature: /RT-SIGNATURE-MANAGED-V1(?=-->|[\s"'<>]|$)/.test(source),
            version: /RT-SIGNATURE-VERSION:[0-9a-f]{16}(?=-->|[\s"'<>]|$)/i.test(source) } };
}

/** Replace one known current-message shell while preserving all other bytes. */
export function managedTemplateReplacement(bodyHtml, currentHtml, nextHtml) {
    try {
        return replacementPlan(bodyHtml, currentHtml, nextHtml);
    } catch (error) {
        if (error?.code === 'TEMPLATE_REPLACEMENT_UNAVAILABLE' && contractWarnings < 3) {
            contractWarnings++;
            try {
                console.warn('[RailTime] template-replace-contract', JSON.stringify({ sdkBody: inspectManagedTemplateContract(bodyHtml, currentHtml), incoming: inspectManagedTemplateContract(nextHtml, nextHtml) }));
            } catch { /* Diagnostics never change the fail-closed result. */ }
        }
        throw error;
    }
}

function replacementPlan(bodyHtml, currentHtml, nextHtml) {
    if (typeof bodyHtml !== 'string' || typeof currentHtml !== 'string' || !bodyHtml.startsWith(currentHtml)
        || typeof nextHtml !== 'string') unavailable();
    const oldContract = rootContract(bodyHtml, currentHtml.length);
    const nextContract = rootContract(nextHtml, nextHtml.length);
    const oldRegion = editableRegion(bodyHtml, oldContract, true);
    const nextRegion = editableRegion(nextHtml, nextContract, false);
    // The combined compiler emits one optional carrier sheet before its root.
    // Put that exact owned sheet inside the new root so repeated replacements
    // remove it with their shell. Unrelated old outside styles stay byte-exact.
    const outside = nextContract.elements.filter(node => node.start < nextContract.root.start || node.start >= nextContract.root.end);
    if (outside.some(node => node.tag !== 'style' || node.attrs['data-rt-combined-compose-css'] !== '1'
        || Object.keys(node.attrs).length !== 1)) unavailable();
    const prefix = nextHtml.slice(0, nextContract.root.start);
    const suffix = nextHtml.slice(nextContract.root.end);
    if ((prefix + suffix).replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '').trim() !== '') unavailable();
    const content = bodyHtml.slice(oldRegion.start, oldRegion.end);
    for (const match of matches(content, /RT-(?:TEMPLATE|SIGNATURE)-MANAGED|data-rt-compose-document\s*=/gi)) {
        if (!quotedOffset(oldContract.elements, oldRegion.start + match.index)) unavailable();
    }
    const replacement = nextHtml.slice(nextContract.root.start, nextContract.root.openEnd) + prefix + suffix
        + nextHtml.slice(nextContract.root.openEnd, nextRegion.start) + content + nextHtml.slice(nextRegion.end, nextContract.root.end);
    const html = bodyHtml.slice(0, oldContract.root.start) + replacement + bodyHtml.slice(oldContract.root.end);
    return Object.freeze({ html, legacy: oldRegion.legacy });
}
