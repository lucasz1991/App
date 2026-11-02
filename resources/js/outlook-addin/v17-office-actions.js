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
const PAIR = 'rt-v17-action-pair', PHONE = 'rt-v17-phone-action';
const groupCell = (width, content) => '<td width="' + width + '" valign="middle" style="width:' + width + 'px;padding:0;vertical-align:middle;">' + content + '</td>';
function group(width, name, cells) {
    return '<table class="' + name + '" role="presentation" width="' + width + '" border="0" cellspacing="0" cellpadding="0" style="width:' + width + 'px;border-collapse:collapse;table-layout:fixed;"><tbody><tr>' + cells.join('') + '</tr></tbody></table>';
}
function groupProjection(html, nodes, cells) {
    const left = children(nodes, cells[0].index), phone = children(nodes, cells[1].index);
    if (left.length !== 2 || left.some(value => value.node.tag !== 'a') || phone.length !== 1 || phone[0].node.tag !== 'a'
        || left[1].node.attrs.href !== 'https://wa.me/491601881848' || !close(props(left[1].node).get('margin-left'), 14)) invalid();
    const wa = opening(html, left[1].node, { style: (left[1].node.attrs.style || '').replace(/(^|;)(\s*margin-left\s*:\s*)14px(?=;|$)/i, '$1$20px') }).value
        + html.slice(left[1].node.openEnd, left[1].node.end);
    return [
        { start: cells[0].node.openEnd, end: cells[0].node.close, value: group(56, PAIR, [groupCell(24, html.slice(left[0].node.start, left[0].node.end)), groupCell(8, '&nbsp;'), groupCell(24, wa)]) },
        { start: cells[1].node.openEnd, end: cells[1].node.close, value: group(32, PHONE, [groupCell(2, '&nbsp;'), groupCell(24, html.slice(phone[0].node.start, phone[0].node.end)), groupCell(6, '&nbsp;')]) },
    ];
}
function normalizeGroup(html, nodes, cell, name, widths, linkSlots) {
    let container = cell, direct = children(nodes, cell.index);
    if (direct.length === 1 && direct[0].node.tag === 'div') {
        const host = direct[0], style = props(host.node), left = name === PAIR;
        const allowed = ['direction','line-height','font-family','font-size','color', ...(left ? ['white-space'] : [])];
        if (Object.keys(host.node.attrs).some(key => !['style','class'].includes(key))
            || host.node.attrs.class !== undefined && host.node.attrs.class !== 'elementToProof'
            || style.size !== allowed.length || [...style.keys()].some(key => !allowed.includes(key))
            || style.get('direction') !== 'ltr' || !close(style.get('line-height'),19) || !close(style.get('font-size'),13)
            || !['#ffffff','rgb(255,255,255)'].includes((style.get('color') || '').replace(/\s/g,''))
            || style.get('font-family')?.replace(/["'\s]/g,'') !== 'trebuchetms,arial,sans-serif'
            || left && style.get('white-space') !== 'nowrap') invalid();
        if (html.slice(cell.node.openEnd,host.node.start).trim() || html.slice(host.node.end,cell.node.close).trim()) invalid();
        container = host; direct = children(nodes,host.index);
    }
    const candidates = direct.filter(value => value.node.tag === 'table');
    if (!candidates.length) return { html, present: false };
    const table = one(candidates); if (direct.length !== 1) invalid();
    const allowedAttrs = ['class','role','width','border','cellspacing','cellpadding','style'];
    if (Object.keys(table.node.attrs).some(key => !allowedAttrs.includes(key))
        || table.node.attrs.class !== undefined && ![name, 'x_' + name, 'MsoNormalTable'].includes(table.node.attrs.class)
        || table.node.attrs.role !== undefined && table.node.attrs.role !== 'presentation'
        || !close(table.node.attrs.width, widths.reduce((a,b) => a+b, 0))) invalid();
    for (const key of ['border','cellspacing','cellpadding']) if (table.node.attrs[key] !== undefined && !close(table.node.attrs[key], 0)) invalid();
    const style = props(table.node);
    if ([...style.keys()].some(key => !['width','border-collapse','table-layout','mso-table-lspace','mso-table-rspace'].includes(key))
        || !close(style.get('width'), widths.reduce((a,b) => a+b, 0)) || style.get('border-collapse') !== 'collapse' || style.get('table-layout') !== 'fixed'
        || ['mso-table-lspace','mso-table-rspace'].some(key => style.has(key) && !close(style.get(key), 0))) invalid();
    const body = only(nodes, table.index, 'tbody'), row = only(nodes, body.index, 'tr'), slots = children(nodes, row.index);
    if (Object.keys(body.node.attrs).length || Object.keys(row.node.attrs).length || slots.length !== 3 || slots.some(value => value.node.tag !== 'td')) invalid();
    const anchors = [];
    for (const [position, slot] of slots.entries()) {
        const paint = props(slot.node);
        if (Object.keys(slot.node.attrs).some(key => !['width','valign','style'].includes(key))
            || !close(slot.node.attrs.width, widths[position]) || slot.node.attrs.valign !== undefined && slot.node.attrs.valign !== 'middle'
            || [...paint.keys()].some(key => !['width','padding','vertical-align'].includes(key)) || !close(paint.get('width'), widths[position])
            || !/^(?:0(?:px|pt)?)(?:\s+0(?:px|pt)?){0,3}$/.test(paint.get('padding') || '') || paint.get('vertical-align') !== 'middle') invalid();
        if (linkSlots.includes(position)) {
            const anchor = only(nodes, slot.index, 'a'); anchors.push(anchor);
            if (html.slice(slot.node.openEnd, anchor.node.start).trim() || html.slice(anchor.node.end, slot.node.close).trim()) invalid();
        } else if (children(nodes, slot.index).length || !/^(?:\s|&nbsp;|&#160;|&#x0*a0;)*$/i.test(html.slice(slot.node.openEnd, slot.node.close))) invalid();
    }
    const gaps = owner => { let cursor = nodes[owner].openEnd; for (const value of children(nodes, owner)) { if (html.slice(cursor, value.node.start).trim()) invalid(); cursor = value.node.end; } if (html.slice(cursor, nodes[owner].close).trim()) invalid(); };
    for (const owner of [cell.index, container.index, table.index, body.index, row.index]) gaps(owner);
    if (name === PAIR && (!close(props(anchors[1].node).get('margin-left'), 0) || anchors[1].node.attrs.href !== 'https://wa.me/491601881848')) invalid();
    if (name === PAIR) setStyle(anchors[1].node, { 'margin-left': '14px' });
    // Keep the original indices/offsets. Only the proven wrapper bytes become
    // virtual whitespace; exact original links/images remain opaque and owned.
    let cursor = table.node.start;
    for (const anchor of anchors) { html = html.slice(0, cursor) + ' '.repeat(anchor.node.start - cursor) + html.slice(anchor.node.start); cursor = anchor.node.end; nodes[anchor.index].parent = container.index; }
    html = html.slice(0, cursor) + ' '.repeat(table.node.end - cursor) + html.slice(table.node.end);
    nodes[table.index].parent = null;
    return { html, present: true };
}
function shape(nodes, owner) {
    const scoped = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'table' && within(nodes, value.index, owner));
    let candidates = scoped.filter(value => has(value.node, 'rt-v17-dispatch-actions'));
    if (!candidates.length) candidates = scoped.filter(value => {
        const body = children(nodes, value.index); if (body.length !== 1 || body[0].node.tag !== 'tbody') return false;
        const rows = children(nodes, body[0].index), cells = rows.length === 1 && rows[0].node.tag === 'tr' ? children(nodes, rows[0].index) : [];
        return cells.length === 3 && nodes.some((node,index) => node.tag === 'a' && /^tel:/.test(node.attrs.href || '') && within(nodes,index,cells[2].index));
    });
    const action = one(candidates);
    const row = only(nodes, only(nodes, action.index, 'tbody').index, 'tr'), cells = children(nodes, row.index);
    if (cells.length !== 3 || cells.some(value => value.node.tag !== 'td')) invalid();
    const links = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'a' && within(nodes, value.index, cells[2].index));
    return { action, cells, link: one(links) };
}

function proveDefaultPhoneImage(html, nodes, cells, link, image) {
    const paint = props(image.node), attrs = image.node.attrs; let url; try { url = new URL(attrs.src); } catch { invalid(); }
    const phoneIcon = nodes.filter((node, index) => node.tag === 'img' && within(nodes, index, cells[1].index));
    if (phoneIcon.length !== 1 || url.origin !== new URL(phoneIcon[0].attrs.src).origin || url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || url.pathname !== PHONE_PATH
        || link.node.attrs.href !== 'tel:' + DEFAULT_PHONE || attrs.alt !== PHONE_LABEL || attrs.width !== '108' || attrs.height !== '20'
        || Object.keys(attrs).some(name => !['src', 'width', 'height', 'alt', 'style'].includes(name))
        || paint.size !== 4 || paint.get('display') !== 'block' || !close(paint.get('width'), 108) || !close(paint.get('height'), 20) || !close(paint.get('border'), 0)) invalid();
    const parent = nodes[image.node.parent];
    if (!['a', 'span', 'b'].includes(parent?.tag) || children(nodes, image.node.parent).length !== 1
        || html.slice(parent.openEnd, image.node.start).trim() || html.slice(image.node.end, parent.close).trim()) invalid();
}

/** Only after the caller has proved the canonical fragment and its ownership. */
export function v17OfficeActionProjection(html, nodes, owner, mobile = false) {
    const { action, cells, link } = shape(nodes, owner), span = only(nodes, link.index, 'span'), edits = [];
    const defaultPhoneImage = link.node.attrs.href === 'tel:' + DEFAULT_PHONE;
    const append = (value, tail, attrs = {}) => { const style = value.node.attrs.style || ''; edits.push(opening(html, value.node, { ...attrs, style: style.endsWith(tail) ? style : style + tail })); };
    if (!mobile) {
        append(action, ';width:215px!important;max-width:100%;table-layout:auto!important;', { width: '215' });
        const widths = defaultPhoneImage ? [75, 32, 108] : [62, 32, 121];
        cells.forEach((cell, position) => append(cell, ';width:' + widths[position] + 'px!important;'
            + (position === 1 ? 'padding-right:0!important;' : 'white-space:nowrap!important;')
            + (position === 2 ? 'padding-left:0!important;text-align:left!important;word-break:normal!important;overflow-wrap:normal!important;' : ''), { width: String(widths[position]), ...(position === 1 ? {} : { nowrap: '' }) }));
        edits.push(...groupProjection(html, nodes, cells));
    }
    append(link, ';white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important;');
    append(span, ';white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important;');
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
    const officeGeometry = close(style.get('width'), 215) || close(action.node.attrs.width, 215);
    const pair = normalizeGroup(html, nodes, cells[0], PAIR, [24,8,24], [0,2]); html = pair.html;
    const phone = normalizeGroup(html, nodes, cells[1], PHONE, [2,24,6], [1]); html = phone.html;
    if (pair.present !== phone.present || pair.present && !officeGeometry) invalid();
    const officeActionsWire = officeGeometry && pair.present && phone.present;
    const images = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'img' && within(nodes, value.index, link.index));
    const image = images.length === 1 ? images[0] : null;
    if (image) proveDefaultPhoneImage(html, nodes, cells, link, image);
    // Actual OWA strips overflow-wrap even while retaining nowrap and normal
    // word-break. Only the already proved, fixed white-number image plus both
    // physical action groups can make that absent declaration immaterial.
    const missingImageWrap = officeActionsWire && Boolean(image);
    const normalWrap = (paint, role) => paint.get('overflow-wrap') === 'normal' || missingImageWrap && !paint.has('overflow-wrap')
        && [...paint.keys()].every(key => (role === 'cell'
            ? ['width','padding','padding-left','vertical-align','text-align','white-space','word-break']
            : ['color','text-decoration','font-family','font-size','line-height','font-weight','white-space','word-break']).includes(key));
    if (!officeGeometry && !image) return { html, officeActionsWire: false, defaultDispatchPhoneImage: false };
    if (officeGeometry) {
        if (!close(style.get('width'), 215) || !close(action.node.attrs.width, 215) || style.get('table-layout') !== 'auto' || style.get('max-width') !== '100%') invalid();
        const widths = officeActionsWire && link.node.attrs.href === 'tel:' + DEFAULT_PHONE ? [75,32,108] : [62,32,121];
        for (const [index, cell] of cells.entries()) {
            const values = props(cell.node); if (!close(values.get('width'), widths[index]) || cell.node.attrs.width !== undefined && !close(cell.node.attrs.width, widths[index])
                || index !== 1 && (!Object.hasOwn(cell.node.attrs, 'nowrap') || values.get('white-space') !== 'nowrap')
                || index === 1 && !close(values.get('padding-right'), 0)
                || index === 2 && (values.get('word-break') !== 'normal' || !normalWrap(values, 'cell')
                    || officeActionsWire && (!close(values.get('padding-left'), 0) || values.get('text-align') !== 'left'))) invalid();
            if (index !== 1) delete cell.node.attrs.nowrap;
            if (index === 2) delete cell.node.attrs.width; else cell.node.attrs.width = String(index === 0 ? '100%' : 32);
            setStyle(cell.node, { width: index === 2 ? null : index === 0 ? '100%' : '32px', ...(index === 1 ? { 'padding-right': '8px' } : {}),
                ...(index === 2 && officeActionsWire ? { 'padding-left': null, 'text-align': 'right' } : {}), 'word-break': null, 'overflow-wrap': null });
        }
        action.node.attrs.width = '100%'; setStyle(action.node, { width: '100%', 'max-width': null, 'table-layout': null });
    }
    const defaultDispatchPhoneImage = Boolean(image);
    if (image) {
        const oldLength = image.node.end - image.node.start; if (oldLength < PHONE_LABEL.length) invalid();
        html = html.slice(0, image.node.start) + PHONE_LABEL.padEnd(oldLength, ' ') + html.slice(image.node.end); image.node.parent = null;
    }
    if (officeGeometry || image) {
        const runs = nodes.map((node, index) => ({ node, index })).filter(value => value.index === link.index || value.node.tag === 'span' && within(nodes, value.index, link.index));
        for (const value of runs) {
            const paint = props(value.node); if (paint.get('white-space') !== 'nowrap' || paint.get('word-break') !== 'normal' || !normalWrap(paint, 'run')) invalid();
            setStyle(value.node, { 'white-space': null, 'word-break': null, 'overflow-wrap': null });
        }
    }
    return { html, officeActionsWire, defaultDispatchPhoneImage };
}
