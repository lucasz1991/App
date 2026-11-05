// Read-only offsets. These markers never authorize a write or HTML replacement.
export const OUTLOOK_COMPOSE_BOUNDARY_IDS = Object.freeze([
    'ms-outlook-mobile-body-separator-line', 'ms-outlook-mobile-signature',
    'divrplyfwdmsg', 'mail-editor-reference-message-container', 'stopspelling',
]);
const ids = new Set(OUTLOOK_COMPOSE_BOUNDARY_IDS);
export function canonicalOutlookBoundaryId(value) {
    if (typeof value !== 'string') return null;
    const match = /^(?:x_){0,4}([a-z][a-z0-9-]*)$/i.exec(value);
    return match && ids.has(match[1].toLowerCase()) ? match[1].toLowerCase() : null;
}
export function outlookBoundaryNodeHidden(node) {
    const style = node?.attrs?.style || '';
    return Object.hasOwn(node?.attrs || {}, 'hidden') || (node?.attrs?.['aria-hidden'] || '').toLowerCase() === 'true'
        || /\\|\/\*|\*\//.test(style)
        || /(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*(?:hidden|collapse)|mso-hide\s*:\s*all|opacity\s*:\s*[+-]?(?:0(?:\.0*)?|\.0+)%?)(?:\s*!important)?\s*(?:;|$)/i.test(style);
}
export function safeOutlookBoundaryContext(elements, index) {
    const parent = elements[elements[index]?.parent];
    if (parent && !['div', 'body'].includes(parent.tag)) return false;
    for (let at = index, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        const node = elements[at];
        if (!node || ['head', 'table', 'td', 'th', 'blockquote', 'script', 'style', 'template', 'title', 'a', 'form'].includes(node.tag)
            || outlookBoundaryNodeHidden(node)
            || at !== index && canonicalOutlookBoundaryId(node.attrs.id)
            || /(?:^|\s)(?:gmail_quote|yahoo_quoted|moz-cite-prefix)(?:\s|$)/i.test(node.attrs.class || '')) return false;
    }
    return true;
}
const blank = html => /^\s*$/.test(html.replace(/<[^>]*>/g, '').replace(/&(?:nbsp|#160|#xa0);/gi, ' ').replace(/[\u200b\u200c\u200d\ufeff]/g, ''));
function emptySeparator(html, elements, index) {
    const node = elements[index];
    if (!blank(html.slice(node.openEnd, node.close))) return false;
    for (let at = index + 1; at < elements.length && elements[at].start < node.close; at++) {
        if (!['div', 'p', 'span', 'br'].includes(elements[at].tag) || outlookBoundaryNodeHidden(elements[at])) return false;
    }
    return true;
}
function nativeHr(node) {
    if (node?.tag !== 'hr' || Object.keys(node.attrs).some(key => !['style', 'tabindex'].includes(key))
        || node.attrs.tabindex !== undefined && node.attrs.tabindex !== '-1') return false;
    if (!node.attrs.style) return true;
    const values = new Map();
    for (const declaration of node.attrs.style.split(';')) {
        if (!declaration.trim()) continue;
        const parts = declaration.split(':').map(value => value.trim().toLowerCase());
        if (parts.length !== 2 || values.has(parts[0])) return false;
        values.set(parts[0], parts[1]);
    }
    return values.size === 2 && values.get('display') === 'inline-block' && values.get('width') === '98%';
}
function headerFields(html, elements, index) {
    const header = elements[index];
    if (header?.tag !== 'div') return false;
    const descendants = elements.slice(index + 1).filter(node => node.start < header.close);
    if (descendants.some(node => !['font', 'span', 'b', 'br', 'div', 'p'].includes(node.tag) || outlookBoundaryNodeHidden(node))) return false;
    const labels = descendants.filter(node => node.tag === 'b').map(node => html.slice(node.openEnd, node.close).trim());
    return JSON.stringify(labels) === JSON.stringify(['Von:', 'Gesendet:', 'An:', 'Betreff:'])
        || JSON.stringify(labels) === JSON.stringify(['From:', 'Sent:', 'To:', 'Subject:']);
}

/** A mobile signature/separator is part of the editable current message, not
 * itself the quote boundary. Only its following HR/header sequence proves it. */
export function mobileComposeQuoteBoundary(html, elements, before = Infinity) {
    let boundary = null;
    const values = elements.map((node, index) => ({ node, index }));
    for (const anchor of values) {
        const id = canonicalOutlookBoundaryId(anchor.node.attrs.id);
        if (anchor.node.tag !== 'div' || !['ms-outlook-mobile-body-separator-line', 'ms-outlook-mobile-signature'].includes(id)
            || !safeOutlookBoundaryContext(elements, anchor.index)
            || id === 'ms-outlook-mobile-body-separator-line' && !emptySeparator(html, elements, anchor.index)) continue;
        const siblings = values.filter(value => value.node.parent === anchor.node.parent);
        let next = siblings.findIndex(value => value.index === anchor.index) + 1, end = anchor.node.end;
        const adjacent = value => value && /^\s*$/.test(html.slice(end, value.node.start)) && safeOutlookBoundaryContext(elements, value.index);
        for (let count = 0; count < 8 && next < siblings.length; count++) {
            const candidate = siblings[next], candidateId = canonicalOutlookBoundaryId(candidate.node.attrs.id);
            if (!adjacent(candidate) || candidate.node.tag !== 'div') break;
            if (candidateId !== 'ms-outlook-mobile-signature' && !emptySeparator(html, elements, candidate.index)) break;
            end = candidate.node.end; next++;
        }
        const hr = siblings[next++];
        if (!adjacent(hr) || !nativeHr(hr.node)) continue;
        end = hr.node.end;
        const header = siblings[next];
        if (!adjacent(header) || header.node.tag !== 'div' || header.node.start >= before
            || canonicalOutlookBoundaryId(header.node.attrs.id) !== 'divrplyfwdmsg' && !headerFields(html, elements, header.index)) continue;
        if (boundary === null || hr.node.start < boundary) boundary = hr.node.start;
    }
    return boundary;
}
