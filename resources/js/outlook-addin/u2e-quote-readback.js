import { parseManagedHtml } from './managed-template.js';

const MAX_QUOTE_LENGTH = 500000;
const MAX_ELEMENTS = 4096;
const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr']);
const RAW = new Set(['style', 'script', 'textarea', 'title']);
const TAIL_WRAPPERS = new Set(['div', 'body', 'html', 'span', 'font']);
const TOKEN = /<!--[\s\S]*?-->|<(?:style|script|textarea|title)\b(?:[^"'<>]|"[^"]*"|'[^']*')*>[\s\S]*?<\/(?:style|script|textarea|title)\s*>|<!doctype\b[^>]*>|<\?[^>]*\?>|<\/?[a-z][a-z0-9:-]*(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi;
const ATTR = /\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/gi;
const result = (equal, mode, reason = null) => Object.freeze({ equal, mode, reason });

// Complete references only. Unknown/invalid references stay literal; no
// whitespace collapse, NBSP substitution, Unicode folding or URL rewriting.
function htmlValue(raw) {
    return raw.replace(/\r\n?/g, '\n').replace(/&(#[xX][0-9a-fA-F]+|#\d+|amp|AMP|quot|QUOT|apos|lt|LT|gt|GT|nbsp);/g, (entity, code) => {
        if (code[0] !== '#') return { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>', nbsp: '\u00a0' }[code.toLowerCase()];
        const point = /^#x/i.test(code) ? Number.parseInt(code.slice(2), 16) : Number.parseInt(code.slice(1), 10);
        // C1 aliases and invalid codepoints need the complete HTML5 reference
        // algorithm. Do not invent an equivalence for them here.
        return point > 0 && point <= 0x10ffff && !(point >= 0xd800 && point <= 0xdfff)
            && !(point >= 0x80 && point <= 0x9f) ? String.fromCodePoint(point) : entity;
    });
}

// Preserve every declaration and its order. Only CSS syntax whitespace around
// ':' / ';', property-name case, and empty terminal declarations are lexical.
// Values, duplicate overrides, strings, !important and unknown forms are kept.
function cssValue(value) {
    if (/[\\{}]|\/\*|\*\//.test(value)) return ['raw-style', value];
    const declarations = [];
    let quote = '', depth = 0, start = 0;
    const chunks = [];
    for (let index = 0; index <= value.length; index++) {
        const character = value[index];
        if (quote) { if (character === quote) quote = ''; continue; }
        if (character === '"' || character === "'") quote = character;
        else if (character === '(') depth++;
        else if (character === ')') { if (--depth < 0) return ['raw-style', value]; }
        else if ((character === ';' && depth === 0) || index === value.length) {
            chunks.push(value.slice(start, index)); start = index + 1;
        }
    }
    if (quote || depth) return ['raw-style', value];
    for (const chunk of chunks) {
        if (!chunk.trim()) continue;
        const colon = chunk.indexOf(':');
        if (colon < 1) return ['raw-style', value];
        const property = chunk.slice(0, colon).trim();
        const content = chunk.slice(colon + 1).trim();
        if (!/^(?:--[a-z0-9_-]+|-?[a-z][a-z0-9-]*)$/i.test(property) || !content) return ['raw-style', value];
        declarations.push([property.startsWith('--') ? property : property.toLowerCase(), content]);
    }
    return ['ordered-style', declarations];
}

function attributes(opening) {
    const name = /^<([a-z][a-z0-9:-]*)/i.exec(opening);
    // In unquoted HTML attributes, an adjacent slash belongs to the value:
    // src=https://example.invalid/path/> has a different URL from .../path>.
    // Lex attributes first; only a separately delimited slash is tag syntax.
    const source = opening.slice(name[0].length, -1);
    const parsed = [];
    let cursor = 0, lastQuoted = false;
    for (const match of source.matchAll(ATTR)) {
        if (source.slice(cursor, match.index).trim() !== '') throw new Error('quote-attributes');
        const key = match[1].includes(':') ? match[1] : match[1].toLowerCase();
        const value = htmlValue(match[2] ?? match[3] ?? match[4] ?? '');
        parsed.push([key, key === 'style' ? cssValue(value) : value]);
        cursor = match.index + match[0].length;
        lastQuoted = match[2] !== undefined || match[3] !== undefined;
    }
    const tail = source.slice(cursor);
    if (tail.trim() !== '' && !(tail.trim() === '/' && (parsed.length === 0 || lastQuoted || /^\s/.test(tail)))) {
        throw new Error('quote-attributes');
    }
    return parsed.sort((a, b) => a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0);
}

// region().quote is an exact tail of the SDK document, so it may close BODY/
// DIV ancestors opened before the quote boundary. Restore only these missing
// analysis ancestors; record their sequence and never edit the original tail.
function balancedFragment(quote) {
    const stack = [], missing = [];
    for (const match of quote.matchAll(TOKEN)) {
        const token = match[0];
        if (/^<!--|^<!|^<\?/i.test(token)) continue;
        const name = /^<(\/?)([a-z][a-z0-9:-]*)\b/i.exec(token);
        const tag = name[2].toLowerCase();
        if (name[1]) {
            if (stack.length) { if (stack.pop() !== tag) throw new Error('quote-parse'); }
            else {
                if (!TAIL_WRAPPERS.has(tag) || missing.length >= 8) throw new Error('quote-tail');
                missing.push(tag);
            }
        } else {
            // Word may close a missing DIV ancestor before a final empty
            // paragraph. The complete restored tree and missing-wrapper order
            // are compared; no tags or intervening content are removed.
            if (!VOID.has(tag) && !RAW.has(tag) && !/\/\s*>$/.test(token)) stack.push(tag);
        }
    }
    if (stack.length) throw new Error('quote-unclosed');
    const prefix = [...missing].reverse().map(tag => '<' + tag + '>').join('');
    const html = prefix + quote;
    const parsed = parseManagedHtml(html);
    if (parsed.elements.length > MAX_ELEMENTS) throw new Error('quote-budget');
    return { html, parsed, context: missing };
}

function serializationEvidence(quote) {
    const { html, parsed, context } = balancedFragment(quote);
    const events = [];
    for (const node of parsed.elements) {
        const opening = html.slice(node.start, node.openEnd);
        const rawTag = /^<([a-z][a-z0-9:-]*)/i.exec(opening)[1];
        const tag = rawTag.includes(':') ? rawTag : node.tag;
        const leaf = node.end === node.openEnd;
        events.push({ start: node.start, end: node.openEnd,
            data: [VOID.has(node.tag) ? 'void' : leaf ? 'self' : 'open', tag, attributes(opening)] });
        if (RAW.has(node.tag)) {
            // STYLE/SCRIPT text is not HTML-entity decoded. Keep raw program/
            // stylesheet content entirely, rather than ignoring quoted styles.
            events.push({ start: node.openEnd, end: node.close,
                data: ['raw-content', html.slice(node.openEnd, node.close)] });
        }
        if (!VOID.has(node.tag) && !leaf) {
            const closingTag = tag.includes(':') ? /^<\/([a-z][a-z0-9:-]*)/i.exec(html.slice(node.close, node.end))[1] : tag;
            events.push({ start: node.close, end: node.end, data: ['close', closingTag] });
        }
    }
    for (const comment of parsed.comments) events.push({ start: comment.start, end: comment.end,
        data: ['comment', html.slice(comment.start, comment.end)] });
    events.sort((a, b) => a.start - b.start || a.end - b.end);
    const tokens = [];
    let cursor = 0;
    for (const event of events) {
        if (event.start < cursor) throw new Error('quote-overlap');
        if (event.start > cursor) {
            const content = html.slice(cursor, event.start);
            tokens.push(content.includes('<') ? ['literal', content] : ['text', htmlValue(content)]);
        }
        tokens.push(event.data); cursor = event.end;
    }
    if (cursor < html.length) tokens.push(['text', htmlValue(html.slice(cursor))]);
    return JSON.stringify({ context, tokens });
}

/** Classic-only after-write quote comparison. It never normalizes the input
 * string, repairs a quote, retries a write, or exposes quote content in reports.
 * The original 23084-byte pre-write quote was not captured; this is not a claim
 * that the observed 229-character native difference has been reproduced. */
export function compareClassicQuoteReadback(before, after, options = {}) {
    if (typeof before !== 'string' || typeof after !== 'string') return result(false, 'different', 'invalid-input');
    if (before === after) return result(true, 'bytes');
    if (options?.host !== 'Outlook' || options?.phase !== 'readback' || options?.step !== 'readback.quote'
        || !['reply', 'forward'].includes(options?.composeType)
        || options?.bodyWriteStarted !== true || options?.bodyWriteSucceeded !== true) return result(false, 'different', 'outside-classic-after-write');
    if (before.length > MAX_QUOTE_LENGTH || after.length > MAX_QUOTE_LENGTH) return result(false, 'different', 'quote-limit');
    try {
        return serializationEvidence(before) === serializationEvidence(after)
            ? result(true, 'classic-word-serialization') : result(false, 'different', 'quote-content-or-attributes-differ');
    } catch { return result(false, 'different', 'invalid-or-unbalanced-html'); }
}
