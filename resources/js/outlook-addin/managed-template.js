// Offset-only edits keep Outlook's unrelated HTML and quoted history opaque.
const TOKEN = /<!--[\s\S]*?-->|<(?:style|script|textarea|title)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>[\s\S]*?<\/(?:style|script|textarea|title)\s*>|<!doctype\b[^>]*>|<\?[^>]*\?>|<\/?[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi;
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const MODE = 'combined-v1';
const MARKER = 'RT-TEMPLATE-MANAGED-V1:COMBINED-DOCUMENT';

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
    for (const match of html.matchAll(TOKEN)) {
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
        for (const attribute of attributes.matchAll(attributePattern)) {
            if (attributes.slice(end, attribute.index).trim() !== '') unavailable();
            const key = attribute[1].toLowerCase();
            if (Object.hasOwn(attrs, key)) unavailable();
            attrs[key] = decoded(attribute[2] ?? attribute[3] ?? attribute[4] ?? '');
            end = attribute.index + attribute[0].length;
        }
        if (attributes.slice(end).trim() !== '') unavailable();
        const raw = /^(?:style|script|textarea|title)$/.test(tag);
        const leaf = raw || VOID.has(tag) || /\/\s*>$/.test(opening);
        const closing = raw ? new RegExp(`<\\/${tag}\\s*>$`, 'i').exec(token) : null;
        if (raw && closing === null) unavailable();
        const node = { tag, attrs, parent: stack.at(-1), start: match.index, openEnd: match.index + opening.length,
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

function exactlyOne(values) {
    if (values.length !== 1) unavailable();
    return values[0];
}

function rootContract(html, currentLength) {
    const tree = nodes(html);
    const { elements } = tree;
    const candidates = elements.map((node, index) => ({ node, index })).filter(({ node, index }) => node.start < currentLength
        && !quoted(elements, index) && (has(node, 'rt-combined-compose-document') || Object.hasOwn(node.attrs, 'data-rt-compose-document')));
    const { node: root, index: rootIndex } = exactlyOne(candidates);
    if (root.tag !== 'div' || !has(root, 'rt-combined-compose-document') || root.attrs['data-rt-compose-document'] !== MODE
        || root.end > currentLength || !html.slice(root.start, root.end).includes(MARKER)) unavailable();
    const oneClass = (name, tag) => exactlyOne(elements.map((node, index) => ({ node, index }))
        .filter(value => within(elements, value.index, rootIndex) && has(value.node, name) && value.node.tag === tag));
    const template = oneClass('rt-outlook-template', 'div');
    const signature = oneClass('rt-outlook-signature', 'div');
    const frame = oneClass('rt-combined-compose-frame', 'table');
    if (template.node.end > signature.node.start || !within(elements, template.index, frame.index)
        || !within(elements, signature.index, frame.index)
        || !html.slice(signature.node.start, signature.node.end).includes('RT-SIGNATURE-MANAGED-V1')) unavailable();
    // An unowned marker outside this root is not permission to pick one of two
    // documents. Quoted history is ignored only through a recognized boundary.
    for (const match of html.slice(0, currentLength).matchAll(/RT-TEMPLATE-MANAGED-V1/g)) {
        if (match.index >= root.start && match.index < root.end) continue;
        if (!elements.some((node, index) => node.start <= match.index && node.end > match.index && quoted(elements, index))) unavailable();
    }
    return { ...tree, root, rootIndex, template, signature };
}

function editableRegion(html, contract, allowLegacy) {
    const { elements, template, comments } = contract;
    const candidates = elements.map((node, index) => ({ node, index })).filter(({ node, index }) => within(elements, index, contract.rootIndex)
        && (has(node, 'rt-compose-editable') || Object.hasOwn(node.attrs, 'data-rt-compose-editable')));
    if (candidates.length) {
        const { node, index } = exactlyOne(candidates);
        if (node.tag !== 'div' || !has(node, 'rt-compose-editable') || node.attrs['data-rt-compose-editable'] !== 'v1'
            || !within(elements, index, template.index) || quoted(elements, index)) unavailable();
        return { start: node.openEnd, end: node.close, legacy: false };
    }
    if (!allowLegacy) unavailable();
    const start = exactlyOne(comments.filter(node => node.value === 'RT_APPLICATION_CONTENT_START'
        && node.start > template.node.openEnd && node.end < template.node.close));
    const end = exactlyOne(comments.filter(node => node.value === 'RT_APPLICATION_CONTENT_END'
        && node.start > start.end && node.end < template.node.close));
    const paragraphs = elements.filter(node => node.tag === 'p' && has(node, 'design-spacer') && node.start >= start.end && node.end <= end.start);
    const paragraph = exactlyOne(paragraphs);
    const parent = elements[paragraph.parent];
    if (!parent || parent.tag !== 'td' || !has(parent, 'rt-native-template-pad')) unavailable();
    // Compatibility is deliberately limited to the old default. Old edited
    // greetings or other application content cannot be silently discarded.
    if (elements.some((node, index) => within(elements, index, template.index)
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
    const content = bodyHtml.slice(oldRegion.start, oldRegion.end);
    if (/RT-(?:TEMPLATE|SIGNATURE)-MANAGED|data-rt-compose-document\s*=/i.test(content)) unavailable();
    const replacement = nextHtml.slice(0, nextRegion.start) + content + nextHtml.slice(nextRegion.end);
    const html = bodyHtml.slice(0, oldContract.root.start) + replacement + bodyHtml.slice(oldContract.root.end);
    return Object.freeze({ html, legacy: oldRegion.legacy });
}
