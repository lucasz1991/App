// V17 is an independent late linked-delivery projection. Historical V16 stays
// unchanged; only proved new material receives a virtual inverse for reuse.
const VERIFIED_V17_ASSET_CONTRACT = [
    { role: 'contact', filename: 'contact-location-brand-v2.png', sha256: 'ef78563be5b36fb14bbfb9aec2f08e8ab55493648b31aecac64418693ad4a52b', extension: 'png', previousPath: '/m/097e33e85e.png' },
    { role: 'contact', filename: 'contact-phone-brand-v2.png', sha256: 'ad1ce232f6020548515acfb00b50bfd79cfc08ba9392553419a699a6d80a207b', extension: 'png', previousPath: '/m/741a9d43f5.png' },
    { role: 'contact', filename: 'contact-mobile-brand-v2.png', sha256: '237a6ef4b921b7ec54a858137f54879b21c756b3a417be245bfa2d91e4d5a6d2', extension: 'png', previousPath: '/m/8f6e4932f8.png' },
    { role: 'contact', filename: 'contact-email-brand-v2.png', sha256: '74e44dae98fa9e3115df12d89fa8c979ac5afadff1fe02899dc0e22614bafaee', extension: 'png', previousPath: '/m/cfa8c68211.png' },
    { role: 'contact', filename: 'contact-web-brand-v2.png', sha256: 'aee56057875f1695049f7aa018988742e37a15110b175b4926ffd828ce7c255e', extension: 'png', previousPath: '/m/09d2f890b7.png' },
    { role: 'caption', filename: 'outlook-dispo-card-caption-alpha-v3.png', sha256: '4a55285090810c491f92cbf1f8ffb928ec50daef77ddc2b36a1d3b4f587151ce', extension: 'png', previousPath: '/m/464054a84f.png' },
    { role: 'dispatch-mail', filename: 'outlook-dispo-mail-kraeftig-v3.png', sha256: '19125261c2bc8274933b7a1c9efd1c5db23cd751c65fc1f88d2dcae22e4481b', extension: 'png', previousPath: '/m/36ecdeda31.png' },
    { role: 'dispatch-phone', filename: 'outlook-dispo-telefon-kraeftig-v3.png', sha256: '1111670ac86e12aff6ad7809e11959b7af3c1275d4c5f2832b60934992f8f19a', extension: 'png', previousPath: '/m/a3564d9bc8.png' },
    { role: 'dispatch-whatsapp', filename: 'outlook-dispo-whatsapp-weiss-v3.png', sha256: 'f9d88b899d574851c64d02c9835a5b5fcf8d7c68e05d6c9b3f5618d3ac38bbd9', extension: 'png' },
    { role: 'train', filename: 'zug-dampf-outlook-brand-v2-light.gif', sha256: '6aeef403af2bd639b8fee076312a37b6b3e27f22c148ae835ca8d5d43fcc3a2e', extension: 'gif', previousPath: '/mail-assets/zug-dampf-outlook-brand-v1-light.gif' },
    { role: 'train', filename: 'zug-dampf-outlook-brand-v2-dark.gif', sha256: '92913c81a7a44a28560e23f61907ae38b992b31dd10494ab4f3d9d13fc20ca24', extension: 'gif', previousPath: '/mail-assets/zug-dampf-outlook-brand-v1-dark.gif' },
    { role: 'train', filename: 'zug-dampf-outlook-brand-v2-light-mirrored.gif', sha256: '11ded023b6a25d319c87a8fb25f105c98e8b58a5f9b0fcb711cb6ff3dc10bee6', extension: 'gif', previousPath: '/mail-assets/zug-dampf-outlook-brand-v1-light-mirrored.gif' },
    { role: 'train', filename: 'zug-dampf-outlook-brand-v2-dark-mirrored.gif', sha256: 'c5ae93d54d0d2ed7a30ea3b8402a5598f57e25271d267b37719f899361308c5a', extension: 'gif', previousPath: '/mail-assets/zug-dampf-outlook-brand-v1-dark-mirrored.gif' },
];
const FLOW = 'outlook-desktop-flow-v17';
const TRAIN_OFFSET = 'clamp(-8px,calc((500px - 100%)/10),0px)';
const CONTACT_SPACE = ';padding-bottom:3px!important;';
const COLUMNS = [
    ['identity', 220, 'clamp(220px,calc((500px - 100%)*1000),100%)', '220px'],
    ['direct', 280, 'min(280px,52%)', 'clamp(248px,calc(100% - 220px),280px)'],
    ['company', 210, 'min(210px,48%)', '230px'],
    ['dispatch', 247, '247px', '227px'],
];

const owns = (value, name) => Object.prototype.hasOwnProperty.call(value, name);

function invalid() {
    throw Object.assign(new Error('NATIVE_LAYOUT_RESTORE_INVALID'), {
        code: 'NATIVE_LAYOUT_RESTORE_INVALID', phase: 'native-layout-preflight',
    });
}

function within(nodes, child, parent) {
    for (let index = child, depth = 0; Number.isInteger(index) && depth <= nodes.length; depth++) {
        if (index === parent) return true;
        if (!nodes[index]) return false;
        index = nodes[index].parent;
    }
    return false;
}

const children = (nodes, index) => nodes.map((node, index) => ({ node, index })).filter(value => value.node.parent === index);
const descendants = (nodes, owner, tag) => nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === tag && within(nodes, value.index, owner));
const has = (node, name) => (node.attrs.class || '').split(/\s+/).some(value => value === name || value === 'x_' + name);
function one(values) { if (values.length !== 1) invalid(); return values[0]; }
function only(nodes, index, tag) {
    const value = one(children(nodes, index));
    if (value.node.tag !== tag) invalid();
    return value;
}

function properties(node) {
    const result = new Map(), priorities = new Map();
    for (const declaration of (node.attrs.style || '').split(';')) {
        if (!declaration.trim()) continue;
        const colon = declaration.indexOf(':');
        if (colon <= 0) invalid();
        const name = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase();
        if (['all', 'padding-inline', 'padding-inline-start', 'padding-inline-end'].includes(name)) invalid();
        const important = /!important\s*$/i.test(declaration.slice(colon + 1).trim());
        if (!priorities.get(name) || important) { result.set(name, value); priorities.set(name, important); }
    }
    return result;
}

function setStyle(node, declarations) {
    const names = new Set(Object.keys(declarations));
    const kept = (node.attrs.style || '').split(';').filter(value => {
        const colon = value.indexOf(':');
        return colon > 0 && !names.has(value.slice(0, colon).trim().toLowerCase());
    });
    node.attrs.style = kept.join(';') + (kept.length ? ';' : '') + Object.entries(declarations)
        .filter(([, value]) => value !== null).map(([name, value]) => name + ':' + value + ';').join('');
}

function pixels(value) {
    const match = /^(\d+(?:\.\d+)?|\.\d+)(px|pt|cm|in)?$/.exec(value || '');
    return match ? Number(match[1]) * ({ pt: 4 / 3, cm: 96 / 2.54, in: 96 }[match[2]] || 1) : NaN;
}
const close = (value, expected) => Math.abs(pixels(value) - expected) < 0.05;
const expression = value => (value || '').replace(/\s+/g, '').toLowerCase();
function color(value, expected) {
    const hex = /^#([0-9a-f]{6})$/.exec(expected);
    const rgb = hex && [0, 2, 4].map(offset => Number.parseInt(hex[1].slice(offset, offset + 2), 16));
    return expression(value) === expected || rgb && expression(value) === 'rgb(' + rgb.join(',') + ')';
}

function padding(node) {
    const value = { top: 0, right: 0, bottom: 0, left: 0 }, priorities = {};
    const assign = (side, number, important) => {
        if (!priorities[side] || important) { value[side] = number; priorities[side] = important; }
    };
    for (const declaration of (node.attrs.style || '').split(';')) {
        const colon = declaration.indexOf(':'); if (colon <= 0) continue;
        const name = declaration.slice(0, colon).trim().toLowerCase();
        const important = /!important\s*$/i.test(declaration.slice(colon + 1).trim());
        const raw = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '');
        if (name === 'padding') {
            const parts = raw.split(/\s+/).map(pixels);
            if (!parts.length || parts.length > 4 || parts.some(part => !Number.isFinite(part))) invalid();
            const numbers = [parts[0], parts[1] ?? parts[0], parts[2] ?? parts[0], parts[3] ?? parts[1] ?? parts[0]];
            ['top', 'right', 'bottom', 'left'].forEach((side, index) => assign(side, numbers[index], important));
        } else if (/^padding-(top|right|bottom|left)$/.test(name)) assign(name.slice(8), pixels(raw), important);
    }
    return value;
}

function exactPadding(node, expected) {
    const actual = padding(node);
    if (Object.keys(actual).some(name => !Number.isFinite(actual[name]) || Math.abs(actual[name] - expected[name]) >= 0.05)) invalid();
}

function verifySheet(html, nodes, scoped, source) {
    // The all-recipient inline projection removes only its two canonical sheets.
    // No delivered V17 signature may depend on either sheet surviving Office.
    if (scoped.some(value => value.node.tag === 'style' && (['data-rt-compact-css', 'data-rt-v30-css'].some(name => owns(value.node.attrs, name))
        || /rt-v17-mobile-dispatch|\.(?:x_)?rt-v17-dispatch\b[^{}]*\{[^{}]*\bdisplay\s*:\s*none/i.test(html.slice(value.node.openEnd, value.node.close))))) invalid();
}

function childGaps(html, nodes, parent) {
    let cursor = nodes[parent].openEnd;
    for (const child of children(nodes, parent).sort((a, b) => a.node.start - b.node.start)) {
        if (child.node.start < cursor || html.slice(cursor, child.node.start).trim()) invalid();
        cursor = child.node.end;
    }
    if (html.slice(cursor, nodes[parent].close).trim()) invalid();
}
function expectExpression(node, property, expected) {
    if (expression(properties(node).get(property)) !== expression(expected)) invalid();
}
function rewritePadding(node, value) {
    setStyle(node, { padding: value, 'padding-top': null, 'padding-right': null, 'padding-bottom': null, 'padding-left': null });
}
function cellOf(nodes, table) { return only(nodes, only(nodes, only(nodes, table, 'tbody').index, 'tr').index, 'td'); }
function text(html, node) {
    return html.slice(node.openEnd, node.close).replace(/<!--[\s\S]*?-->|<[^>]*>/g, '')
        .replace(/&nbsp;|&#160;|&#x0*a0;/gi, ' ').replace(/&amp;/gi, '&').replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
        .replace(/&#x([a-f0-9]+);/gi, (_, n) => String.fromCodePoint(Number.parseInt(n, 16))).replace(/&quot;/gi, '"').replace(/&#039;|&apos;/gi, "'").trim();
}
const BOLD = [301,367,474,586,586,889,722,238,367,367,432,586,367,367,367,390,586,586,586,586,586,586,586,586,586,586,367,367,586,586,586,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,612,722,667,944,667,667,611,402,355,402,586,586,586,556,611,556,611,575,370,611,611,298,367,556,295,889,611,611,611,611,427,556,396,611,556,784,556,556,528,434,586,434,586];
const REGULAR = [301,367,355,556,556,889,706,191,367,367,389,584,367,367,367,524,556,556,556,556,556,556,556,556,556,556,367,367,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,367,355,367,524,556,524,556,557,500,557,556,370,556,556,285,367,504,295,833,556,556,557,557,389,500,396,556,500,744,501,500,500,367,524,367,584];
function fits(value, size, limit, bold) {
    if (!value || /[^\x20-\x7e]/.test(value)) return false;
    return [...value].reduce((sum, char) => sum + (bold ? BOLD : REGULAR)[char.charCodeAt(0) - 32] * size / 1000, 0) <= limit;
}
function raisedSource(html, nodes, root, direct, source = true) {
    if (has(root.node, 'rt-system-columns')) return false;
    const identity = one(children(nodes, direct.node.parent).filter(value => value.node.tag === 'table' && close(value.node.attrs.width, 220)));
    const names = descendants(nodes, identity.index, 'p').filter(value => source ? has(value.node, 'rt-sign-name') : close(properties(value.node).get('font-size'), 22) && properties(value.node).get('font-weight') === 'bold');
    const roles = descendants(nodes, identity.index, 'p').filter(value => source ? has(value.node, 'rt-personal-role') : close(properties(value.node).get('font-size'), 10) && close(properties(value.node).get('letter-spacing'), 1.6));
    if (names.length !== 1 || roles.length !== 1) return false;
    const name = text(html, names[0].node), role = text(html, roles[0].node);
    const values = descendants(nodes, direct.index, 'p').filter(value => source ? has(value.node, 'rt-delivery-contact-value') : close(properties(value.node).get('font-size'), 13) && close(properties(value.node).get('line-height'), 19));
    return fits(name, 22, 184, true) && fits(role.toUpperCase(), 10, 174 - role.length * 1.6, true)
        && values.length <= 2 && values.every(value => fits(text(html, value.node), 13, 222, false));
}

function normalizeFluid(html, actual, root, source, contract) {
    const nodes = actual.map(node => ({ ...node, attrs: { ...node.attrs } }));
    if (source && (root.node.attrs['data-rt-compact-mobile'] !== 'v17' || !has(root.node, 'rt-v17-mobile-inline'))
        || owns(root.node.attrs, 'data-rt-compact-mobile') && root.node.attrs['data-rt-compact-mobile'] !== 'v17') invalid();
    expectExpression(root.node, 'max-width', '1040px');
    setStyle(nodes[root.index], { 'max-width': null });
    delete nodes[root.index].attrs['data-rt-compact-mobile'];
    if (nodes[root.index].attrs.class) nodes[root.index].attrs.class = nodes[root.index].attrs.class.replace(/(?:^|\s)(?:x_)?rt-v17-mobile-inline(?=\s|$)/g, '');
    const rows = children(nodes, only(nodes, root.index, 'tbody').index);
    if (rows.length !== 2) invalid();
    const main = only(nodes, rows[0].index, 'td'), columns = children(nodes, main.index).filter(value => value.node.tag === 'table');
    if (columns.length !== 4) invalid();
    const system = has(root.node, 'rt-system-columns');
    const widths = ['220px', '280px', '210px', '247px'];
    const previousWidths = ['clamp(220px,calc((500px - 100%)*1000),100%)', 'min(280px,52%)', system ? '210px' : 'min(210px,48%)', '247px'];
    columns.forEach((column, position) => {
        expectExpression(column.node, 'width', widths[position]);
        setStyle(column.node, { width: previousWidths[position] });
    });
    const cells = columns.map(column => cellOf(nodes, column.index));
    if (!system) {
        const logo = one(descendants(nodes, columns[0].index, 'table').filter(value => close(value.node.attrs.width, 184)));
        const image = one(descendants(nodes, logo.index, 'img')), logoCell = cellOf(nodes, logo.index);
        expectExpression(logo.node, 'width', '184px');
        expectExpression(image.node, 'width', '100%'); expectExpression(image.node, 'height', 'auto');
        if (properties(logo.node).get('float') !== 'none' || !close(properties(logo.node).get('margin'), 0)
            || !close(image.node.attrs.width, 184) || image.node.attrs.height !== undefined && !close(image.node.attrs.height, 32)) invalid();
        setStyle(logo.node, { float: null, margin: null });
        setStyle(image.node, { width: '184px' });
    }
    exactPadding(cells[3].node, { top: 0, right: 0, bottom: 0, left: 0 });
    const block = only(nodes, cells[3].index, 'table'); expectExpression(block.node, 'max-width', '100%');
    setStyle(block.node, { 'max-width': '247px' });
    const gutter = value => {
        const actual = padding(value.node);
        if (Math.abs(actual.left - 20) >= 0.05 || Math.abs(actual.right - 20) >= 0.05) invalid();
        for (const side of ['left', 'right']) expectExpression(value.node, 'padding-' + side, '20px');
        setStyle(value.node, { 'padding-left': null, 'padding-right': null });
    };
    gutter(main);
    const signatureRows = children(nodes, only(nodes, contract.signature.index, 'tbody').index);
    const legalHost = only(nodes, signatureRows[1].index, 'td');
    const legal = cellOf(nodes, one(children(nodes, legalHost.index).filter(value => value.node.tag === 'table')).index); gutter(legal);
    // Template padding is bound by the known direct rows, never arbitrary
    // styled content inside the editable message region.
    if (contract.template) {
        const templateBody = only(nodes, contract.template.index, 'tbody');
        const templateRows = children(nodes, templateBody.index);
        if (templateRows.length !== 2 || templateRows.some(value => value.node.tag !== 'tr')) invalid();
        for (const row of templateRows) gutter(only(nodes, row.index, 'td'));
    }
    const train = only(nodes, only(nodes, rows[1].index, 'td').index, 'table');
    expectExpression(train.node, 'width', '100%'); expectExpression(train.node, 'max-width', '547px');
    if (train.node.attrs.width !== '100%') invalid();
    train.node.attrs.width = '547'; setStyle(train.node, { width: 'min(547px,100%)', 'max-width': '100%!important' });
    const offset = properties(train.node).get('margin-top');
    if (!close(offset, 0)) invalid();
    // Numeric v3 always has zero physical overlap. This historical class is
    // used solely to reconstruct the exact pre-inline source for validation.
    const eligible = raisedSource(html, nodes, root, columns[1], source);
    if (has(root.node, 'rt-v17-raised') && !eligible) invalid();
    setStyle(train.node, { 'margin-top': eligible ? TRAIN_OFFSET : '0' });
    for (const column of columns.slice(1, 3)) for (const value of descendants(nodes, column.index, 'p').filter(value => has(value.node, 'rt-delivery-contact-value') || close(properties(value.node).get('line-height'), 19))) {
        expectExpression(value.node, 'line-height', '19px'); expectExpression(value.node, 'word-break', 'normal');
        // These are exactly the two old CSS declarations now carried inline.
    }
    return nodes;
}

function linkedAsset(value, catalog, role, origin) {
    let url;
    try { url = new URL(value); } catch { invalid(); }
    if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash || origin && url.origin !== origin) invalid();
    const matching = catalog.filter(asset => asset.role === role && (asset.extension === 'gif'
        ? ['/mail-assets/' + asset.filename] : ['/m/' + asset.sha256.slice(0, 10) + '.png', '/mail-assets/' + asset.filename]).includes(url.pathname));
    const asset = one(matching);
    return { asset, origin: url.origin, previous: url.origin + asset.previousPath };
}

function rootFor(nodes, scoped, source, contract) {
    if (source) {
        const root = one(scoped.filter(value => value.node.attrs['data-rt-desktop-flow'] === FLOW));
        if (root.node.tag !== 'table' || !has(root.node, 'rt-v17-columns') || !has(root.node, 'rt-v17-compact')
            || !has(root.node, 'rt-v17-single-dispatch')) invalid();
        return root;
    }
    if (contract.fluidLayout?.family !== 17 || !['M', 'B'].includes(contract.fluidLayout.profile)) invalid();
    const floats = scoped.filter(value => value.node.tag === 'table' && value.node.attrs.align?.toLowerCase() === 'left');
    if (floats.length !== 4 || floats.some(value => value.node.parent !== floats[0].node.parent)) invalid();
    let index = floats[0].node.parent;
    while (Number.isInteger(index) && nodes[index].tag !== 'table') index = nodes[index].parent;
    if (!Number.isInteger(index)) invalid();
    return { node: nodes[index], index };
}

/** Prove only V17 changes, then invoke unchanged V16 on cloned material. */
export function verifyCompactReferenceMaterial(html, contract, source, verifyV16Material) {
    const actualNodes = contract?.elements, signature = contract?.signature;
    if (typeof html !== 'string' || !Array.isArray(actualNodes) || !Number.isInteger(signature?.index)
        || actualNodes[signature.index] !== signature.node || typeof verifyV16Material !== 'function') invalid();
    const actualScoped = actualNodes.map((node, index) => ({ node, index })).filter(value => within(actualNodes, value.index, signature.index));
    const actualRoot = rootFor(actualNodes, actualScoped, source, contract);
    verifySheet(html, actualNodes, actualScoped, source);
    const nodes = normalizeFluid(html, actualNodes, actualRoot, source, contract);
    const scoped = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, signature.index));
    const root = { ...actualRoot, node: nodes[actualRoot.index] }, system = has(root.node, 'rt-system-columns');
    const rows = children(nodes, only(nodes, root.index, 'tbody').index);
    if (rows.length !== 2 || rows.some(value => value.node.tag !== 'tr')) invalid();
    const main = only(nodes, rows[0].index, 'td'), columns = children(nodes, main.index).filter(value => value.node.tag === 'table');
    if (columns.length !== 4) invalid();
    const virtual = nodes.map(node => ({ ...node, attrs: { ...node.attrs } }));
    for (const [position, [role, width, compact, previous]] of COLUMNS.entries()) {
        const column = columns[position], style = properties(column.node);
        if (!close(column.node.attrs.width, width) || expression(style.get('width')) !== expression(system && role === 'company' ? '210px' : compact)
            || style.get('max-width') !== '100%' || source && !has(column.node, 'rt-v17-' + role)) invalid();
        virtual[column.index].attrs.width = String(role === 'company' ? 230 : role === 'dispatch' ? 227 : width);
        setStyle(virtual[column.index], { width: previous });
    }
    const train = only(nodes, only(nodes, rows[1].index, 'td').index, 'table');
    const margin = properties(train.node).get('margin-top'), raisedTrain = expression(margin) === expression(TRAIN_OFFSET);
    if (!raisedTrain && !close(margin, 0) || source && (has(root.node, 'rt-v17-raised') !== raisedTrain
        || raisedSource(html, nodes, root, columns[1]) !== raisedTrain)
        || !source && has(root.node, 'rt-v17-raised') && !raisedTrain) invalid();
    if (properties(train.node).get('table-layout') !== 'fixed') invalid();
    setStyle(virtual[train.index], { 'margin-top': null, ...(!system ? { 'table-layout': null } : {}) });
    const trainImage = one(descendants(nodes, train.index, 'img'));
    const dispatchCell = only(nodes, only(nodes, only(nodes, columns[3].index, 'tbody').index, 'tr').index, 'td');
    const block = only(nodes, dispatchCell.index, 'table');
    const blockCell = only(nodes, only(nodes, only(nodes, block.index, 'tbody').index, 'tr').index, 'td');
    const dispatchImages = descendants(nodes, block.index, 'img');
    const dispatchLinks = descendants(nodes, block.index, 'a');
    if (dispatchImages.length !== 4 || dispatchLinks.length !== 4) invalid();
    const caption = linkedAsset(dispatchImages[0].node.attrs.src, VERIFIED_V17_ASSET_CONTRACT, 'caption');
    const origin = caption.origin;
    for (const [position, role] of ['caption', 'dispatch-mail', 'dispatch-whatsapp', 'dispatch-phone'].entries()) {
        const image = dispatchImages[position], asset = linkedAsset(image.node.attrs.src, VERIFIED_V17_ASSET_CONTRACT, role, origin);
        if (role !== 'dispatch-whatsapp') virtual[image.index].attrs.src = asset.previous;
    }
    const captionNode = dispatchImages[0];
    if (!close(captionNode.node.attrs.width, 215) || captionNode.node.attrs.height !== undefined && !close(captionNode.node.attrs.height, 50)
        || source && captionNode.node.attrs.height !== '50' || !close(properties(captionNode.node).get('width'), 215)) invalid();
    virtual[captionNode.index].attrs.width = '199'; virtual[captionNode.index].attrs.height = '38';
    setStyle(virtual[captionNode.index], { width: '199px' });
    expectExpression(block.node, 'max-width', '247px'); setStyle(virtual[block.index], { 'max-width': '227px' });
    exactPadding(blockCell.node, { top: 8, right: 16, bottom: 5, left: 16 }); rewritePadding(virtual[blockCell.index], '8px 14px 5px');
    const wa = dispatchLinks[1], waImage = dispatchImages[2];
    if (wa.node.attrs.href !== 'https://wa.me/491601881848' || source && wa.node.attrs.class !== 'rt-v17-dispatch-whatsapp'
        || owns(wa.node.attrs, 'class') && !['rt-v17-dispatch-whatsapp', 'x_rt-v17-dispatch-whatsapp'].includes(wa.node.attrs.class)
        || waImage.node.parent !== wa.index || children(nodes, wa.index).length !== 1 || text(html, wa.node)
        || Object.keys(wa.node.attrs).some(name => !['class', 'href', 'style'].includes(name))) invalid();
    const waStyle = properties(wa.node);
    if (waStyle.size !== 5 || !color(waStyle.get('color'), '#ffffff') || waStyle.get('text-decoration') !== 'none'
        || waStyle.get('display') !== 'inline-block' || waStyle.get('vertical-align') !== 'middle' || !close(waStyle.get('margin-left'), 14)) invalid();
    const waPaint = properties(waImage.node);
    if (waImage.node.attrs.alt !== 'WhatsApp' || Object.keys(waImage.node.attrs).some(name => !['src', 'width', 'height', 'alt', 'style'].includes(name))
        || waPaint.size !== 4 || waPaint.get('display') !== 'block' || !close(waPaint.get('width'), 24) || !close(waPaint.get('height'), 24) || !close(waPaint.get('border'), 0)) invalid();
    for (const image of dispatchImages.slice(1)) {
        const style = properties(image.node);
        if (!close(image.node.attrs.width, 24) || !close(image.node.attrs.height, 24) || !close(style.get('width'), 24) || !close(style.get('height'), 24)) invalid();
        if (image.index !== waImage.index) {
            virtual[image.index].attrs.width = '25'; virtual[image.index].attrs.height = '25';
            setStyle(virtual[image.index], { width: '25px', height: '25px', display: 'block' });
        }
    }
    // Only this completely proved extra action is removed from the virtual tree.
    // Original HTML, positions, user text and quoted messages remain untouched.
    virtual[wa.index].parent = null; virtual[waImage.index].parent = null;
    setStyle(virtual[dispatchLinks[0].index], { display: null, 'vertical-align': null });
    const trainAsset = linkedAsset(trainImage.node.attrs.src, VERIFIED_V17_ASSET_CONTRACT, 'train', origin);
    virtual[trainImage.index].attrs.src = trainAsset.previous;
    const fill = origin + '/m/3ee5033b5b.png';
    for (const value of [block, blockCell]) {
        const style = properties(value.node);
        if (owns(value.node.attrs, 'background') || [...style.keys()].some(name => ['background', 'background-image', 'background-repeat'].includes(name))
            || !color(style.get('background-color'), '#e90032') || !close(style.get('border-radius'), 8)
            || owns(value.node.attrs, 'bgcolor') && !color(value.node.attrs.bgcolor, '#e90032')
            || source && !color(value.node.attrs.bgcolor, '#e90032')) invalid();
        virtual[value.index].attrs.bgcolor = '#e90032'; virtual[value.index].attrs.background = fill;
        setStyle(virtual[value.index], { 'background-color': '#e90032', 'background-image': 'url(' + fill + ')', 'background-repeat': 'repeat' });
    }
    const actions = one(children(nodes, blockCell.index).filter(value => value.node.tag === 'table'));
    const divider = /^(\S+)\s+solid\s+(.+)$/.exec(properties(actions.node).get('border-top') || '');
    if (!divider || !close(divider[1], 1) || !color(divider[2], '#ffffff')) invalid();
    setStyle(virtual[actions.index], { 'border-top': '1px solid #ff6b8b' });

    const actionRow = only(nodes, only(nodes, actions.index, 'tbody').index, 'tr'), actionCells = children(nodes, actionRow.index);
    childGaps(html, nodes, blockCell.index);
    for (const cell of actionCells) childGaps(html, nodes, cell.index);
    if (actionCells.length !== 3 || actionCells.some(value => value.node.tag !== 'td')
        || dispatchLinks[0].node.parent !== actionCells[0].index || wa.node.parent !== actionCells[0].index
        || dispatchLinks[2].node.parent !== actionCells[1].index || dispatchLinks[3].node.parent !== actionCells[2].index
        || children(nodes, actionCells[0].index).length !== 2) invalid();
    const oldPads = ['10px 14px 10px 0', '10px 0', '10px 0 10px 5px'];
    for (const [index, cell] of actionCells.entries()) {
        exactPadding(cell.node, { top: 8, right: index === 1 ? 8 : 0, bottom: 8, left: 0 });
        const style = properties(cell.node);
        if (style.get('vertical-align') !== 'middle' || index !== 1 && style.get('white-space') !== 'nowrap'
            || index === 2 && style.get('text-align') !== 'right') invalid();
        if (index === 0 && (cell.node.attrs.width !== '100%' || style.get('width') !== '100%')
            || index === 1 && (!close(cell.node.attrs.width, 32) || !close(style.get('width'), 32))) invalid();
        if (index < 2) virtual[cell.index].attrs.width = String(index === 0 ? 39 : 25);
        rewritePadding(virtual[cell.index], oldPads[index]);
        setStyle(virtual[cell.index], { 'white-space': null, 'text-align': null, ...(index < 2 ? { width: index === 0 ? '39px' : '25px' } : {}) });
    }
    for (const value of descendants(nodes, dispatchLinks[3].index, 'span')) {
        const style = properties(value.node);
        if (!close(style.get('font-size'), 13) || !close(style.get('line-height'), 24)) invalid();
        setStyle(virtual[value.index], { 'font-size': '12px', 'line-height': '25px' });
    }

    for (const column of columns.slice(1, 3)) for (const row of descendants(nodes, column.index, 'tr')) {
        const cells = children(nodes, row.index);
        if (cells.length !== 2 || cells.some(value => value.node.tag !== 'td')) continue;
        const icons = descendants(nodes, cells[0].index, 'img');
        if (icons.length !== 1) invalid();
        for (const cell of cells) {
            exactPadding(cell.node, { top: 0, right: 0, bottom: 3, left: 0 });
            if (source && !(cell.node.attrs.style || '').endsWith(CONTACT_SPACE)) invalid();
            setStyle(virtual[cell.index], { 'padding-top': null, 'padding-bottom': null, padding: '0' });
        }
        const icon = linkedAsset(icons[0].node.attrs.src, VERIFIED_V17_ASSET_CONTRACT, 'contact', origin);
        virtual[icons[0].index].attrs.src = icon.previous;
    }

    const signatureRows = children(nodes, only(nodes, signature.index, 'tbody').index);
    if (signatureRows.length !== 2) invalid();
    const legalHost = only(nodes, signatureRows[1].index, 'td');
    const legalTable = one(children(nodes, legalHost.index).filter(value => value.node.tag === 'table'));
    const legal = only(nodes, only(nodes, only(nodes, legalTable.index, 'tbody').index, 'tr').index, 'td');
    exactPadding(legal.node, { top: 8, right: 40, bottom: 8, left: 40 });
    setStyle(virtual[legal.index], { padding: '12px 40px 16px' });
    const legalPaint = properties(legalHost.node);
    if (!color(legalPaint.get('background') ?? legalPaint.get('background-color'), '#dde1e6')
        || [...legalPaint.keys()].some(name => /^background-/.test(name) && name !== 'background-color')
        || owns(legalHost.node.attrs, 'bgcolor') && !color(legalHost.node.attrs.bgcolor, '#dde1e6')) invalid();
    setStyle(virtual[legalHost.index], { background: '#f3f5f7', 'background-color': null });
    if (owns(legalHost.node.attrs, 'bgcolor')) virtual[legalHost.index].attrs.bgcolor = '#f3f5f7';
    const lines = children(nodes, legal.index);
    if (lines.length !== 1 || lines[0].node.tag !== 'div') invalid();
    for (const line of lines) {
        const style = properties(line.node);
        if (!close(style.get('line-height'), 15) || !close(style.get('margin'), 0)) invalid();
        setStyle(virtual[line.index], { 'line-height': '16px' });
        const runs = children(nodes, line.index);
        if (runs.length !== 3 || runs[0].node.tag !== 'span' || runs[1].node.tag !== 'br' || runs[2].node.tag !== 'span'
            || Object.keys(runs[1].node.attrs).length) invalid();
        for (const span of [runs[0], runs[2]]) {
            const font = properties(span.node);
            if (!close(font.get('font-size'), 10.5) || !close(font.get('line-height'), 15) || !color(font.get('color'), '#47515e')) invalid();
            setStyle(virtual[span.index], { 'line-height': '16px', color: '#5c6875' });
        }
    }

    const inverseScope = new Set(scoped.map(value => value.index));
    for (let index = nodes[signature.index].parent; Number.isInteger(index); index = nodes[index].parent) {
        const node = nodes[index];
        if (source && ['rt-managed-signature-v1', 'rt-combined-compose-frame', 'rt-shell'].some(name => has(node, name))
            && !has(node, 'rt-v17-shell')) invalid();
        inverseScope.add(index);
    }
    for (const index of inverseScope) {
        const attrs = virtual[index].attrs;
        if (attrs.class) attrs.class = attrs.class.replace(/(?:^|\s)(?:x_)?rt-v17-(?:compact|raised)(?=\s|$)/g, '')
            .replace(/rt-df17(?=[a-f0-9]{10}(?:\s|$))/g, 'rt-df16').replace(/(^|\s)rt-v17-/g, '$1rt-v16-');
        if (attrs['data-rt-desktop-flow'] === FLOW) attrs['data-rt-desktop-flow'] = 'outlook-desktop-flow-v16';
        if (attrs['data-rt-dispatch-card'] === 'v17') attrs['data-rt-dispatch-card'] = 'v16';
    }
    let virtualHtml = html;
    if (!source) {
        const marker = contract.fluidLayout.marker.node, token = 'RT-FLUID-LAYOUT-V17' + contract.fluidLayout.profile + ':' + contract.fluidLayout.nonce + ':' + contract.fluidLayout.version;
        const content = html.slice(marker.openEnd, marker.close), offset = content.indexOf(token);
        if (offset < 0 || content.lastIndexOf(token) !== offset) invalid();
        const start = marker.openEnd + offset;
        virtualHtml = html.slice(0, start) + token.replace('V17', 'V16') + html.slice(start + token.length);
    }
    const proxy = { ...contract, elements: virtual, signature: { ...signature, node: virtual[signature.index] },
        ...(source ? {} : { fluidLayout: { ...contract.fluidLayout, family: 16 } }) };
    const proved = verifyV16Material(virtualHtml, proxy, source);
    if (proved.singleDispatch !== true) invalid();
    const actual = value => value && { ...value, node: actualNodes[value.index] };
    return { ...proved, root: actual(proved.root), columns: proved.columns.map(actual), train: actual(proved.train),
        caption: actual(proved.caption), trainImage: actual(proved.trainImage), family: 17, singleDispatch: true, raisedTrain, fluidInline: true };
}
