// Office's Word importer may expose BODY STYLE raw text as visible content.
// This projection is only for a newly verified compiler fragment, never a body
// readback. Runtime editable text and quoted history are deliberately opaque.
import { v17OfficeActionProjection } from './v17-office-actions.js';
const FLOW = 'outlook-desktop-flow-v17';
const STYLE_MARKERS = new Set(['data-rt-combined-compose-css', 'data-rt-outlook-template-css',
    'data-rt-outlook-signature-inline-css', 'data-rt-outlook-signature-css', 'data-rt-outlook-mobile-css', 'data-rt-outlook-marker-css']);
const TOKENS = /<!--[\s\S]*?-->|<(?:style|script|textarea|title)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>[\s\S]*?<\/(?:style|script|textarea|title)\s*>|<!doctype\b[^>]*>|<\/?[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi;
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const has = (node, name) => (node.attrs.class || '').split(/\s+/).includes(name);
function invalid() { throw Object.assign(new Error('NATIVE_LAYOUT_RESTORE_INVALID'), { code: 'NATIVE_LAYOUT_RESTORE_INVALID', phase: 'office-wire-preflight' }); }
function within(nodes, index, owner) {
    for (let steps = 0; Number.isInteger(index) && steps <= nodes.length; steps++, index = nodes[index]?.parent) if (index === owner) return true;
    return false;
}
function quoted(nodes, index) {
    for (let steps = 0; Number.isInteger(index) && steps <= nodes.length; steps++, index = nodes[index]?.parent) {
        const node = nodes[index];
        if (node.tag === 'blockquote' || /^(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)$/i.test(node.attrs.id || '')
            || ['gmail_quote', 'yahoo_quoted', 'moz-cite-prefix'].some(name => has(node, name))) return true;
    }
    return false;
}
const escapeAttribute = value => value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
function opening(html, node, changes) {
    let value = html.slice(node.start, node.openEnd);
    for (const [name, replacement] of Object.entries(changes)) {
        const pattern = new RegExp('(\\s)' + name + '\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+)', 'gi');
        let count = 0;
        value = value.replace(pattern, (_match, space) => { count++; return space + name + '="' + escapeAttribute(replacement) + '"'; });
        if (count !== 1) invalid();
    }
    return { start: node.start, end: node.openEnd, value };
}
export function applyV17OfficeWireEdits(html, edits) {
    let cursor = html.length;
    for (const edit of [...edits].sort((a, b) => b.start - a.start)) {
        if (!Number.isInteger(edit.start) || !Number.isInteger(edit.end) || edit.start < 0 || edit.end > cursor || edit.start > edit.end) invalid();
        html = html.slice(0, edit.start) + edit.value + html.slice(edit.end); cursor = edit.start;
    }
    return html;
}
function edits(html, contract, signature, size, sourceLogoUrl = null, mobile = false) {
    const nodes = contract.elements, region = contract.region;
    const result = [];
    for (let index = 0; index < nodes.length; index++) {
        const node = nodes[index];
        if (node.tag !== 'style' || quoted(nodes, index)
            || region && node.start >= region.start && node.end <= region.end) continue;
        const attrs = Object.keys(node.attrs);
        if (attrs.length !== 1 || !STYLE_MARKERS.has(attrs[0]) || node.attrs[attrs[0]] !== '1') invalid();
        result.push({ start: node.start, end: node.end, value: '' });
    }
    const logos = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'img'
        && has(value.node, 'rt-v9-logo') && within(nodes, value.index, signature) && !quoted(nodes, value.index));
    if (logos.length !== 1) invalid();
    const logo = logos[0].node;
    if (logo.attrs.width !== String(size.width) || logo.attrs.height !== String(size.height)
        || sourceLogoUrl !== null && logo.attrs.src !== sourceLogoUrl) invalid();
    let url; try { url = new URL(logo.attrs.src); } catch { invalid(); }
    if (url.protocol !== 'https:' || url.username || url.password || url.hash || url.search) invalid();
    const style = logo.attrs.style || '';
    const widths = [...style.matchAll(/(?:^|;)\s*width\s*:\s*([^;]+)/gi)];
    if (widths.length !== 1 || !new RegExp('^(?:100%|' + size.width + 'px)(?:\\s*!important)?\\s*$', 'i').test(widths[0][1])
        || !/(?:^|;)\s*height\s*:\s*auto(?:\s*!important)?\s*(?:;|$)/i.test(style)
        || !/(?:^|;)\s*max-width\s*:\s*100%(?:\s*!important)?\s*(?:;|$)/i.test(style)) invalid();
    result.push(opening(html, logo, { width: String(size.width), height: String(size.height),
        style: style.replace(/(^|;)(\s*width\s*:\s*)[^;]+/i, '$1$2' + size.width + 'px!important') }));
    const action = v17OfficeActionProjection(html, nodes, signature, mobile);
    result.push(...action.edits);
    result.officeActionsWire = action.officeActionsWire;
    result.defaultDispatchPhoneImage = action.defaultDispatchPhoneImage;
    return result;
}

/** Caller must first prove this exact canonical Source with V17 material guard. */
export function v17OfficeWireEdits(html, contract, material) {
    if (material?.family !== 17 || !Array.isArray(contract?.elements) || !Number.isInteger(contract.signature?.index)
        || !contract.elements.some(node => node.attrs['data-rt-desktop-flow'] === FLOW)) invalid();
    return edits(html, contract, contract.signature.index, { width: 184, height: 32 }, material.sourceLogoUrl);
}

function mobileTree(html) {
    const elements = [], stack = []; let cursor = 0;
    for (const match of html.matchAll(new RegExp(TOKENS.source, TOKENS.flags))) {
        const token = match[0];
        if (html.slice(cursor, match.index).includes('<')) invalid(); cursor = match.index + token.length;
        if (/^<!--|^<!doctype/i.test(token)) continue;
        const name = /^<(\/?)([a-z][a-z0-9:-]*)\b/i.exec(token), tag = name[2].toLowerCase();
        if (name[1]) { const index = stack.pop(); if (elements[index]?.tag !== tag) invalid(); elements[index].close = match.index; elements[index].end = cursor; continue; }
        const open = /^<[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/i.exec(token)[0];
        const attrs = Object.create(null), raw = open.slice(name[0].length, -1).replace(/\/\s*$/, ''); let offset = 0;
        for (const attr of raw.matchAll(/\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/gi)) {
            const key = attr[1].toLowerCase(); if (raw.slice(offset, attr.index).trim() || Object.hasOwn(attrs, key)) invalid();
            attrs[key] = (attr[2] ?? attr[3] ?? attr[4] ?? '').replace(/&amp;/gi, '&').replace(/&quot;/gi, '"'); offset = attr.index + attr[0].length;
        }
        if (raw.slice(offset).trim()) invalid();
        const rawTag = /^(style|script|textarea|title)$/.test(tag), leaf = rawTag || VOID.has(tag) || /\/\s*>$/.test(open);
        const close = rawTag ? new RegExp('<\\/' + tag + '\\s*>$', 'i').exec(token) : null;
        if (rawTag && !close) invalid();
        const node = { tag, attrs, parent: stack.at(-1), start: match.index, openEnd: match.index + open.length,
            close: rawTag ? cursor - close[0].length : leaf ? cursor : null, end: leaf ? cursor : null };
        elements.push(node); if (!leaf) stack.push(elements.length - 1);
        if (elements.length > 4096 || stack.length > 128) invalid();
    }
    if (stack.length || html.slice(cursor).includes('<')) invalid(); return elements;
}

/** Existing mobile protocol validation still owns mode/version/session/budget. */
export function mobileV17OfficeWire(html) {
    if (!/\bdata-rt-desktop-flow\s*=\s*["']outlook-desktop-flow-v17["']/i.test(html)
        || !/\bdata-rt-compact-mobile\s*=\s*["']v17["']/i.test(html)) return html;
    if (html.length > 30000) invalid();
    const nodes = mobileTree(html);
    const flows = nodes.map((node, index) => ({ node, index })).filter(value => value.node.attrs['data-rt-desktop-flow'] === FLOW && !quoted(nodes, value.index));
    if (flows.length !== 1) invalid(); const flow = flows[0];
    const wrappers = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'div'
        && ['rt-mobile-ledger', 'x10'].some(name => has(value.node, name)) && within(nodes, flow.index, value.index));
    if (wrappers.length !== 1) invalid();
    // The physical compiler aliases managed-signature-v1 (for example m1).
    // Its single direct presentation TABLE is the bounded signature owner.
    const signatures = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'table'
        && value.node.parent === wrappers[0].index && within(nodes, flow.index, value.index)
        && value.node.attrs.role === 'presentation');
    if (signatures.length !== 1) invalid();
    const reference = flow.node.attrs['data-rt-mobile-reference'];
    if (reference !== undefined && reference !== 'v1') invalid();
    const spans = nodes.filter(node => node.tag === 'span').map(node => ({ node, value: html.slice(node.openEnd, node.close).replace(/<!--[^]*?-->|<[^>]*>/g, '').trim() }));
    const start = spans.filter(value => value.value === 'RT-COMPOSE-EDITABLE-START-V1'), end = spans.filter(value => value.value === 'RT-COMPOSE-EDITABLE-END-V1');
    if (start.length !== end.length || start.length > 1 || start.length && start[0].node.end > end[0].node.start) invalid();
    const region = start.length ? { start: start[0].node.end, end: end[0].node.start } : null;
    const size = reference === 'v1' ? { width: 104, height: 18 } : { width: 184, height: 32 };
    return applyV17OfficeWireEdits(html, edits(html, { elements: nodes, region }, signatures[0].index, size, null, true));
}
