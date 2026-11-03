// Deliberately separate from published templates/signatures. This diagnostic
// builds one fixed, nonpersonal test; callers cannot supply HTML or CSS.
export const HEAD_PROBE_SUBJECT = '[TEST] RailTime – CSS-Transportprobe R783';
export const HEAD_BODY_PROBE_SUBJECT = '[TEST] RailTime – CSS-Transportprobe Body R786';
export const HEAD_PROBE_MAILBOX = 'it-technik@rail-time.de';
export const HEAD_PROBE_SESSION_KEY = 'railtime.head-probe.v1';
// Block A uses a separate fixed document and verifier. The existing CSS
// experiments retain their original V17 source and retention contracts.
export { U2E_PROBE_SUBJECT, U2E_PROBE_MAILBOX, U2E_PROBE_SESSION_KEY,
    u2eProbeContextAvailable, u2eProbeDocument, inspectU2eProbe } from './u2e-editor-probe.js';
const address = value => typeof value === 'string' ? value.trim().toLowerCase() : '';

export function headProbeContextAvailable(office, binding, method = 'setSignatureAsync') {
    return binding?.schema === 1 && address(binding.mailboxAddress) === HEAD_PROBE_MAILBOX
        && address(binding.senderAddress) === HEAD_PROBE_MAILBOX
        && Array.isArray(binding.allowedSenderAddresses) && binding.allowedSenderAddresses.some(value => address(value) === HEAD_PROBE_MAILBOX)
        && address(office?.context?.mailbox?.userProfile?.emailAddress) === HEAD_PROBE_MAILBOX
        && ['setSignatureAsync', 'setAsync'].includes(method)
        && typeof office?.context?.mailbox?.item?.body?.[method] === 'function';
}

/** Only called after the existing current-source/empty-draft proof. Keep its
 * original bytes; the existing parser proves an unambiguous document shell. */
export function headBodyProbeDocument(original, elements, sourceRoot, nonce) {
    const invalid = () => { throw Object.assign(new Error('HEAD_PROBE_SOURCE_INVALID'), { code: 'HEAD_PROBE_SOURCE_INVALID' }); };
    if (typeof original !== 'string' || original.length > 500000 || !Array.isArray(elements) || elements.length > 4096
        || !sourceRoot || original.slice(sourceRoot.start, sourceRoot.openEnd)[0] !== '<') invalid();
    const probe = headProbeDocument(nonce);
    const style = probe.html.slice(probe.html.indexOf('<style'), probe.html.indexOf('</head>'));
    const body = probe.html.slice(probe.html.indexOf('<body>') + 6, probe.html.indexOf('</body>'));
    const tagged = tag => elements.map((node, index) => ({ node, index })).filter(value => value.node.tag === tag);
    const html = tagged('html'), head = tagged('head'), bodies = tagged('body');
    let result;
    if (!html.length && !head.length && !bodies.length) {
        if (/<!doctype\b/i.test(original)) invalid();
        result = '<!doctype html><html><head><meta charset="utf-8">' + style + '</head><body>' + original + body + '</body></html>';
    } else {
        if (html.length !== 1 || head.length !== 1 || bodies.length !== 1 || html[0].node.parent !== undefined
            || head[0].node.parent !== html[0].index || bodies[0].node.parent !== html[0].index
            || head[0].node.end > bodies[0].node.start
            || sourceRoot.start < bodies[0].node.openEnd || sourceRoot.end > bodies[0].node.close
            || elements.some(node => node.parent === undefined && node !== html[0].node)
            || elements.filter(node => node.parent === html[0].index).length !== 2) invalid();
        const outside = original.slice(0, html[0].node.start) + original.slice(html[0].node.end);
        if (outside.replace(/<!doctype\s+html\s*>|<!--[\s\S]*?-->/gi, '').trim()
            || (outside.match(/<!doctype\b/gi) || []).length > 1) invalid();
        result = original.slice(0, head[0].node.close) + style + original.slice(head[0].node.close, bodies[0].node.close)
            + body + original.slice(bodies[0].node.close);
    }
    if (result.length > 500000) invalid();
    return Object.freeze({ ...probe, html: result });
}

export function headProbeDocument(nonce) {
    if (!/^[0-9a-f]{32}$/.test(nonce)) throw new Error('HEAD_PROBE_NONCE_INVALID');
    const id = 'rt-head-probe-' + nonce;
    const css = '#' + id + '{width:360px;max-width:100%;font-family:Arial,sans-serif}'
        + '#' + id + ' .r783-cell{display:inline-block;width:160px;padding:8px;background:#c62032;color:#ffffff}'
        + '@media only screen and (max-width:520px){#' + id + ' .r783-cell{display:block!important;width:100%!important;background:#12436a!important}}';
    return Object.freeze({ nonce, id, css, html: '<!doctype html><html><head><meta charset="utf-8"><style type="text/css" data-rt-head-probe="v1">' + css
        + '</style></head><body><div id="' + id + '" data-rt-head-probe="v1" style="width:360px;max-width:100%;font-family:Arial,sans-serif;">'
        + '<div class="r783-cell" style="display:inline-block;width:160px;padding:8px;background:#c62032;color:#ffffff;box-sizing:border-box;">Alpha</div>'
        + '<div class="r783-cell" style="display:inline-block;width:160px;padding:8px;background:#c62032;color:#ffffff;box-sizing:border-box;">Beta</div></div></body></html>' });
}

function inside(nodes, index, owner) {
    for (let n = 0; Number.isInteger(index) && n <= nodes.length; n++, index = nodes[index]?.parent) if (index === owner) return true;
    return false;
}

/** The existing managed parser supplies the tree; no second HTML parser. */
export function inspectHeadProbe(html, elements, document) {
    const empty = { schema: 1, attempted: true, probeCount: 0, rectangles: false, nonceBound: false,
        headStyles: 0, bodyStyles: 0, probeHeadStyles: 0, probeBodyStyles: 0, exactCss: false, mediaRetained: false };
    if (typeof html !== 'string' || html.length > 500000 || !Array.isArray(elements) || elements.length > 4096) return { report: empty, root: null };
    const roots = elements.map((node, index) => ({ node, index })).filter(value => [document.id, 'x_' + document.id].includes(value.node.attrs.id));
    const report = { ...empty, probeCount: Math.min(roots.length, 2) };
    const ancestor = (index, tag) => elements.some((node, owner) => node.tag === tag && inside(elements, index, owner));
    const normalize = value => value.replace(/\s+/g, '').replace(/#x_rt-head-probe-/g, '#rt-head-probe-').replace(/\.x_r783-cell/g, '.r783-cell').toLowerCase();
    for (const [index, node] of elements.entries()) {
        if (node.tag !== 'style') continue;
        const head = ancestor(index, 'head'); report[head ? 'headStyles' : 'bodyStyles']++;
        const css = html.slice(node.openEnd, node.close);
        if (!css.includes(document.nonce)) continue;
        report[head ? 'probeHeadStyles' : 'probeBodyStyles']++;
        report.exactCss ||= normalize(css) === normalize(document.css);
        report.mediaRetained ||= /@media\s+only\s+screen\s+and\s*\(\s*max-width\s*:\s*520px\s*\)/i.test(css);
    }
    if (roots.length !== 1 || roots[0].node.tag !== 'div') return { report, root: null };
    const root = roots[0], scoped = elements.filter((node, index) => index !== root.index && inside(elements, index, root.index));
    const value = html.slice(root.node.openEnd, root.node.close).replace(/<[^>]*>/g, '').replace(/&nbsp;|&#160;/gi, ' ').replace(/\s+/g, '');
    report.rectangles = value === 'AlphaBeta' && scoped.length <= 12 && scoped.every(node => ['div', 'p', 'span', 'font', 'br'].includes(node.tag));
    report.nonceBound = true;
    return { report, root };
}
