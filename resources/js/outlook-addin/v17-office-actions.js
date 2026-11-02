const DEFAULT_PHONE = '+491601881839';
const PHONE_LABEL = '+49 160 1881839';
const PHONE_PATH = '/m/ef89fd23c6.png';
export const V17_DEFAULT_PHONE_ASSET = Object.freeze({ path: PHONE_PATH, sha256: 'ef89fd23c6e9791cc03dfddb31e7f8e319b0b1992b1a0e839b4ebb771b18c674', width: 108, height: 20 });
function invalid() { throw Object.assign(new Error('NATIVE_LAYOUT_RESTORE_INVALID'), { code: 'NATIVE_LAYOUT_RESTORE_INVALID', phase: 'office-actions-preflight' }); }
const has = (node, name) => (node.attrs.class || '').split(/\s+/).some(value => value === name || value === 'x_' + name);
const children = (nodes, index) => nodes.map((node, index) => ({ node, index })).filter(value => value.node.parent === index);
function one(values) { if (values.length !== 1) invalid(); return values[0]; }
function only(nodes, index, tag) { const value = one(children(nodes, index)); if (value.node.tag !== tag) invalid(); return value; }
function within(nodes, index, parent) { for (let i = 0; Number.isInteger(index) && i <= nodes.length; i++, index = nodes[index]?.parent) if (index === parent) return true; return false; }
function props(node) {
    const values = new Map(), priorities = new Map();
    for (const declaration of (node.attrs.style || '').split(';')) {
        if (!declaration.trim()) continue; const colon = declaration.indexOf(':'); if (colon < 1) invalid();
        const name = declaration.slice(0, colon).trim().toLowerCase(), important = /!important\s*$/i.test(declaration);
        if (!priorities.get(name) || important) { values.set(name, declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase()); priorities.set(name, important); }
    }
    return values;
}
function pixels(value) { const m = /^(\d+(?:\.\d+)?)(px|pt|cm|in)?$/.exec(value || ''); return m ? Number(m[1]) * ({ pt: 4 / 3, cm: 96 / 2.54, in: 96 }[m[2]] || 1) : NaN; }
const close = (value, expected) => Math.abs(pixels(value) - expected) < .05;
function setStyle(node, changes) {
    const names = new Set(Object.keys(changes)); node.attrs.style = (node.attrs.style || '').split(';').filter(value => !names.has(value.slice(0, value.indexOf(':')).trim().toLowerCase())).filter(Boolean).join(';') + ';'
        + Object.entries(changes).filter(([, value]) => value !== null).map(([key, value]) => key + ':' + value).join(';') + ';';
}
function opening(html, node, changes) {
    let value = html.slice(node.start, node.openEnd);
    for (const [name, replacement] of Object.entries(changes)) {
        let count = 0; const pattern = new RegExp('(\\s)' + name + '\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+)', 'gi');
        const encoded = replacement.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        value = value.replace(pattern, (_m, gap) => { count++; return gap + name + '="' + encoded + '"'; });
        if (!count && node.attrs[name] === undefined) value = value.replace(/\/?\s*>$/, ' ' + name + '="' + encoded + '">'); else if (count !== 1) invalid();
    }
    return { start: node.start, end: node.openEnd, value };
}
function shape(nodes, owner) {
    const scoped = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'table' && within(nodes, value.index, owner));
    let candidates = scoped.filter(value => has(value.node, 'rt-v17-dispatch-actions'));
    if (!candidates.length) candidates = scoped.filter(value => {
        const body = children(nodes, value.index); if (body.length !== 1 || body[0].node.tag !== 'tbody') return false;
        const rows = children(nodes, body[0].index); return rows.length === 1 && rows[0].node.tag === 'tr' && children(nodes, rows[0].index).length === 3;
    });
    const action = one(candidates);
    const row = only(nodes, only(nodes, action.index, 'tbody').index, 'tr'), cells = children(nodes, row.index);
    if (cells.length !== 3 || cells.some(value => value.node.tag !== 'td')) invalid();
    const links = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'a' && within(nodes, value.index, cells[2].index));
    return { action, cells, link: one(links) };
}

/** Only after the caller has proved the canonical fragment and its ownership. */
export function v17OfficeActionProjection(html, nodes, owner, mobile = false) {
    const { action, cells, link } = shape(nodes, owner), span = only(nodes, link.index, 'span'), edits = [];
    const append = (value, tail, attrs = {}) => { const style = value.node.attrs.style || ''; edits.push(opening(html, value.node, { ...attrs, style: style.endsWith(tail) ? style : style + tail })); };
    if (!mobile) {
        append(action, ';width:215px!important;max-width:100%;table-layout:auto!important;', { width: '215' });
        cells.forEach((cell, position) => append(cell, ';width:' + [62, 32, 121][position] + 'px!important;'
            + (position === 1 ? 'padding-right:0!important;' : 'white-space:nowrap!important;')
            + (position === 2 ? 'word-break:normal!important;overflow-wrap:normal!important;' : ''), { width: String([62, 32, 121][position]), ...(position === 1 ? {} : { nowrap: '' }) }));
    }
    append(link, ';white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important;');
    append(span, ';white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important;');
    const defaultPhoneImage = link.node.attrs.href === 'tel:' + DEFAULT_PHONE;
    if (defaultPhoneImage) {
        if (children(nodes, span.index).length || html.slice(span.node.openEnd, span.node.close).replace(/&nbsp;|&#160;/gi, ' ').replace(/\s/g, '') !== DEFAULT_PHONE) invalid();
        const phoneIcons = nodes.filter(node => node.tag === 'img' && within(nodes, nodes.indexOf(node), cells[1].index));
        if (phoneIcons.length !== 1) invalid(); let url; try { url = new URL(phoneIcons[0].attrs.src); } catch { invalid(); }
        if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash) invalid();
        edits.push({ start: span.node.openEnd, end: span.node.close, value: '<img src="' + url.origin + PHONE_PATH + '" width="' + (mobile ? 94 : 108) + '" height="' + (mobile ? 17 : 20) + '" alt="' + PHONE_LABEL + '" style="display:block;width:' + (mobile ? 94 : 108) + 'px;height:' + (mobile ? 'auto' : '20px') + ';border:0;">' });
    }
    return { edits, officeActionsWire: !mobile, defaultDispatchPhoneImage: defaultPhoneImage };
}

/** Readback-only finite inverse; no original HTML or caller nodes are changed. */
export function normalizeV17OfficeActions(html, nodes, owner, source) {
    if (source) return { html, officeActionsWire: false, defaultDispatchPhoneImage: false };
    const { action, cells, link } = shape(nodes, owner), style = props(action.node);
    const officeActionsWire = close(style.get('width'), 215) || close(action.node.attrs.width, 215);
    const images = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'img' && within(nodes, value.index, link.index));
    const image = images.length === 1 ? images[0] : null;
    if (!officeActionsWire && !image) return { html, officeActionsWire: false, defaultDispatchPhoneImage: false };
    if (officeActionsWire) {
        if (!close(style.get('width'), 215) || !close(action.node.attrs.width, 215) || style.get('table-layout') !== 'auto' || style.get('max-width') !== '100%') invalid();
        for (const [index, cell] of cells.entries()) {
            const values = props(cell.node); if (!close(values.get('width'), [62, 32, 121][index]) || cell.node.attrs.width !== undefined && !close(cell.node.attrs.width, [62, 32, 121][index])
                || index !== 1 && (!Object.hasOwn(cell.node.attrs, 'nowrap') || values.get('white-space') !== 'nowrap')
                || index === 1 && !close(values.get('padding-right'), 0)
                || index === 2 && (values.get('word-break') !== 'normal' || values.get('overflow-wrap') !== 'normal')) invalid();
            if (index !== 1) delete cell.node.attrs.nowrap;
            if (index === 2) delete cell.node.attrs.width; else cell.node.attrs.width = String(index === 0 ? '100%' : 32);
            setStyle(cell.node, { width: index === 2 ? null : index === 0 ? '100%' : '32px', ...(index === 1 ? { 'padding-right': '8px' } : {}), 'word-break': null, 'overflow-wrap': null });
        }
        action.node.attrs.width = '100%'; setStyle(action.node, { width: '100%', 'max-width': null, 'table-layout': null });
    }
    const defaultDispatchPhoneImage = Boolean(image);
    if (image) {
        const paint = props(image.node), attrs = image.node.attrs; let url; try { url = new URL(attrs.src); } catch { invalid(); }
        const phoneIcon = nodes.filter((node, index) => node.tag === 'img' && within(nodes, index, cells[1].index));
        if (phoneIcon.length !== 1 || url.origin !== new URL(phoneIcon[0].attrs.src).origin || url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== PHONE_PATH
            || link.node.attrs.href !== 'tel:' + DEFAULT_PHONE || attrs.alt !== PHONE_LABEL || attrs.width !== '108' || attrs.height !== '20'
            || Object.keys(attrs).some(name => !['src', 'width', 'height', 'alt', 'style'].includes(name))
            || paint.size !== 4 || paint.get('display') !== 'block' || !close(paint.get('width'), 108) || !close(paint.get('height'), 20) || !close(paint.get('border'), 0)) invalid();
        const parent = nodes[image.node.parent];
        if (!['a', 'span', 'b'].includes(parent?.tag) || children(nodes, image.node.parent).length !== 1
            || html.slice(parent.openEnd, image.node.start).trim() || html.slice(image.node.end, parent.close).trim()) invalid();
        const oldLength = image.node.end - image.node.start; if (oldLength < PHONE_LABEL.length) invalid();
        html = html.slice(0, image.node.start) + PHONE_LABEL.padEnd(oldLength, ' ') + html.slice(image.node.end); image.node.parent = null;
    }
    if (officeActionsWire || image) {
        const runs = nodes.map((node, index) => ({ node, index })).filter(value => value.index === link.index || value.node.tag === 'span' && within(nodes, value.index, link.index));
        for (const value of runs) {
            const paint = props(value.node); if (paint.get('white-space') !== 'nowrap' || paint.get('word-break') !== 'normal' || paint.get('overflow-wrap') !== 'normal') invalid();
            setStyle(value.node, { 'white-space': null, 'word-break': null, 'overflow-wrap': null });
        }
    }
    return { html, officeActionsWire, defaultDispatchPhoneImage };
}
