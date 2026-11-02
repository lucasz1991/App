// Only the proved V17 row receives the unconditional seven-node inline hybrid.
// Modern inline-block cells wrap; Word keeps its physical four-cell row. The
// finite wrapper is normalized virtually, without removing content or offsets.
const FLOW = 'outlook-desktop-flow-v17';
const CLASS = 'rt-v17-inline-columns';
const WIDTHS = [220, 280, 210, 247];
const ROLES = ['identity', 'direct', 'company', 'dispatch'];
const TOTAL = 957;
const has = (node, name) => (node.attrs.class || '').split(/\s+/).some(value => value === name || value === 'x_' + name);
function invalid() { throw Object.assign(new Error('NATIVE_LAYOUT_RESTORE_INVALID'), { code: 'NATIVE_LAYOUT_RESTORE_INVALID', phase: 'mso-columns-preflight' }); }
function within(nodes, index, owner) {
    for (let n = 0; Number.isInteger(index) && n <= nodes.length; n++, index = nodes[index]?.parent) if (index === owner) return true;
    return false;
}
const children = (nodes, owner) => nodes.map((node, index) => ({ node, index })).filter(value => value.node.parent === owner);
function one(values) { if (values.length !== 1) invalid(); return values[0]; }
function only(nodes, owner, tag) { const value = one(children(nodes, owner)); if (value.node.tag !== tag) invalid(); return value; }
function pixels(value) {
    const match = /^(\d+(?:\.\d+)?|\.\d+)(px|pt|cm|in)?$/i.exec((value || '').trim());
    return match ? Number(match[1]) * ({ pt: 4 / 3, cm: 96 / 2.54, in: 96 }[match[2]?.toLowerCase()] || 1) : NaN;
}
const close = (value, expected) => Math.abs(pixels(value) - expected) < 0.05;
function properties(node) {
    const result = new Map();
    for (const declaration of (node.attrs.style || '').split(';')) {
        if (!declaration.trim()) continue;
        const match = /^\s*([a-z-]+)\s*:\s*(.*?)\s*(?:!important)?\s*$/i.exec(declaration);
        if (!match || result.has(match[1].toLowerCase())) invalid();
        result.set(match[1].toLowerCase(), match[2].replace(/\s*!important\s*$/i, '').toLowerCase());
    }
    return result;
}
function attributes(node, allowed) {
    if (Object.keys(node.attrs).some(name => !allowed.includes(name))) invalid();
}
function gaps(html, nodes, owner) {
    let cursor = nodes[owner].openEnd;
    for (const child of children(nodes, owner).sort((a, b) => a.node.start - b.node.start)) {
        if (child.node.start < cursor || html.slice(cursor, child.node.start).trim()) invalid();
        cursor = child.node.end;
    }
    if (html.slice(cursor, nodes[owner].close).trim()) invalid();
}
function body(nodes, table, ghost = true) {
    const values = children(nodes, table);
    if (values.length === 1 && values[0].node.tag === 'tbody') {
        if (ghost && Object.keys(values[0].node.attrs).length) invalid();
        return values[0].index;
    }
    return table;
}
function zero(value) {
    const parts = (value || '').trim().split(/\s+/);
    return parts.length > 0 && parts.length <= 4 && parts.every(part => close(part, 0));
}
function ghostTable(node, modern) {
    attributes(node, ['class', 'role', 'width', 'border', 'cellspacing', 'cellpadding', 'style']);
    if (!(node.attrs.width === '100%' || !modern && close(node.attrs.width, TOTAL))
        || node.attrs.role !== undefined && node.attrs.role !== 'presentation'
        || node.attrs.class !== undefined && ![CLASS, 'x_' + CLASS, ...(!modern ? ['MsoNormalTable'] : [])].includes(node.attrs.class)) invalid();
    for (const name of ['border', 'cellspacing', 'cellpadding']) if (node.attrs[name] !== undefined && !close(node.attrs[name], 0)) invalid();
    const style = properties(node);
    if ([...style.keys()].some(name => !['width', 'max-width', 'table-layout', 'border-collapse', 'mso-table-lspace', 'mso-table-rspace'].includes(name))
        || !(style.get('width') === '100%' || !modern && close(style.get('width'), TOTAL))
        || style.get('table-layout') !== 'fixed' || style.get('border-collapse') !== 'collapse'
        || modern && style.get('max-width') !== '100%' || style.has('max-width') && style.get('max-width') !== '100%'
        || ['mso-table-lspace', 'mso-table-rspace'].some(name => style.has(name) && !close(style.get(name), 0))) invalid();
}
function rowStyle(node, modern) {
    attributes(node, ['style']);
    const style = properties(node);
    if ([...style.keys()].some(name => !['display', 'width', 'font-size', 'line-height'].includes(name))
        || modern && (style.get('display') !== 'block' || style.get('width') !== '100%' || !close(style.get('font-size'), 0) || !close(style.get('line-height'), 0))
        || style.has('display') && style.get('display') !== 'block' || style.has('width') && style.get('width') !== '100%'
        || ['font-size', 'line-height'].some(name => style.has(name) && !close(style.get(name), 0))) invalid();
}
function ghostCell(node, width, modern) {
    attributes(node, ['width', 'valign', 'style']);
    if (!close(node.attrs.width, width) || node.attrs.valign !== undefined && node.attrs.valign !== 'top') invalid();
    const style = properties(node);
    if ([...style.keys()].some(name => !['display', 'width', 'max-width', 'padding', 'vertical-align', 'font-size', 'line-height'].includes(name))
        || !close(style.get('width'), width) || !zero(style.get('padding')) || style.get('vertical-align') !== 'top'
        || modern && (style.get('display') !== 'inline-block' || style.get('max-width') !== '100%' || !close(style.get('font-size'), 0) || !close(style.get('line-height'), 0))
        || style.has('display') && style.get('display') !== 'inline-block' || style.has('max-width') && style.get('max-width') !== '100%'
        || ['font-size', 'line-height'].some(name => style.has(name) && !close(style.get(name), 0))) invalid();
}
function validateContract(html, contract) {
    if (typeof html !== 'string' || html.length > 1000000 || !Array.isArray(contract?.elements)
        || contract.elements.length > 10000 || !Number.isInteger(contract.signature?.index)
        || contract.elements[contract.signature.index] !== contract.signature.node) invalid();
}
const cellOpen = width => '<td width="' + width + '" valign="top" style="display:inline-block!important;width:' + width + 'px;max-width:100%;padding:0;vertical-align:top;font-size:0;line-height:0;">';

/** Called only after the complete canonical V17 material/source proof. */
export function v17MsoColumnEdits(html, contract, material) {
    validateContract(html, contract);
    if (material?.family !== 17 || !Array.isArray(material.columns) || material.columns.length !== 4
        || !material.root || material.root.node.attrs['data-rt-desktop-flow'] !== FLOW) invalid();
    const nodes = contract.elements, columns = material.columns;
    const main = columns[0].node.parent;
    if (!Number.isInteger(main) || nodes[main]?.tag !== 'td' || !within(nodes, main, material.root.index)
        || !within(nodes, material.root.index, contract.signature.index)) invalid();
    const direct = children(nodes, main), tables = direct.filter(value => value.node.tag === 'table');
    if (tables.length !== 4 || direct[direct.length - 1]?.node.tag !== 'br'
        || direct[direct.length - 1].node.attrs.clear !== 'all' || direct.length !== 5) invalid();
    columns.forEach((column, position) => {
        if (nodes[column.index] !== column.node || column.index !== tables[position].index || column.node.parent !== main
            || !has(column.node, 'rt-v17-' + ROLES[position]) || !close(column.node.attrs.width, WIDTHS[position])) invalid();
    });
    const opening = '<table class="' + CLASS + '" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;max-width:100%;border-collapse:collapse;table-layout:fixed;mso-table-lspace:0;mso-table-rspace:0;"><tbody><tr style="display:block!important;width:100%!important;font-size:0;line-height:0;">';
    return [
        { start: columns[0].node.start, end: columns[0].node.start, value: opening + cellOpen(WIDTHS[0]) },
        ...columns.slice(0, -1).map((column, position) => ({ start: column.node.end, end: column.node.end, value: '</td>' + cellOpen(WIDTHS[position + 1]) })),
        { start: columns[3].node.end, end: columns[3].node.end, value: '</td></tr></tbody></table>' },
    ];
}

/**
 * Proves exactly one inline hybrid before exposing its unchanged children
 * to the historical V17 proof. Indices and original HTML offsets stay identical.
 */
export function normalizeV17MsoColumns(html, contract) {
    validateContract(html, contract);
    const nodes = contract.elements;
    if (contract.fluidLayout?.family !== 17) return { elements: nodes, msoColumns: false };
    if (!['M', 'B'].includes(contract.fluidLayout.profile)) invalid();
    const modern = contract.fluidLayout.profile === 'M';
    const candidates = nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'table'
        && within(nodes, value.index, contract.signature.index)
        && (has(value.node, CLASS) || (() => {
            const content = children(nodes, value.index), body = content.length === 1 && content[0].node.tag === 'tbody' ? children(nodes, content[0].index) : content;
            if (body.length !== 1 || body[0].node.tag !== 'tr') return false;
            const cells = children(nodes, body[0].index);
            return cells.length === 4 && cells.every((cell, position) => cell.node.tag === 'td' && close(cell.node.attrs.width, WIDTHS[position]));
        })()));
    if (!candidates.length) return { elements: nodes, msoColumns: false };
    const ghost = one(candidates), main = ghost.node.parent;
    if (!Number.isInteger(main) || nodes[main].tag !== 'td' || children(nodes, main).filter(value => value.node.tag === 'table').length !== 1) invalid();
    const row = nodes[main].parent;
    if (!Number.isInteger(row) || nodes[row].tag !== 'tr' || children(nodes, row).length !== 1) invalid();
    const rowParent = nodes[row].parent;
    const root = nodes[rowParent]?.tag === 'tbody' ? nodes[rowParent].parent : rowParent;
    if (!Number.isInteger(root) || nodes[root]?.tag !== 'table' || !within(nodes, root, contract.signature.index)) invalid();
    // The flow is the first signature row's top-level table, never a nested
    // table inside a contact/card or the legal row, even after class loss.
    const signatureRows = children(nodes, body(nodes, contract.signature.index, false));
    if (signatureRows.length !== 2 || !within(nodes, root, signatureRows[0].index)) invalid();
    for (let index = nodes[root].parent; index !== contract.signature.index; index = nodes[index]?.parent) {
        if (!Number.isInteger(index) || nodes[index].tag === 'table') invalid();
    }
    const flowRows = children(nodes, body(nodes, root));
    if (flowRows.length !== 2 || flowRows[0].index !== row || flowRows.some(value => value.node.tag !== 'tr')) invalid();
    ghostTable(ghost.node, modern);
    const ghostBody = body(nodes, ghost.index), ghostRows = children(nodes, ghostBody);
    if (ghostRows.length !== 1 || ghostRows[0].node.tag !== 'tr') invalid();
    rowStyle(ghostRows[0].node, modern);
    const cells = children(nodes, ghostRows[0].index);
    if (cells.length !== 4 || cells.some(value => value.node.tag !== 'td')) invalid();
    const columns = cells.map((cell, position) => {
        ghostCell(cell.node, WIDTHS[position], modern);
        const column = only(nodes, cell.index, 'table');
        if (!close(column.node.attrs.width, WIDTHS[position])) invalid();
        gaps(html, nodes, cell.index);
        return column;
    });
    for (const index of new Set([main, ghost.index, ghostBody, ghostRows[0].index])) gaps(html, nodes, index);
    const virtual = nodes.map(node => ({ ...node, attrs: { ...node.attrs } }));
    virtual[ghost.index].parent = null;
    for (const column of columns) virtual[column.index].parent = main;
    return { elements: virtual, msoColumns: true, inlineHybrid: true };
}
