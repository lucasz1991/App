// V30-c-1 has its own proof. Historical V11–V15 material is never relaxed.
const FLOW = 'outlook-desktop-flow-v16';
const owns = (value, name) => Object.prototype.hasOwnProperty.call(value, name);
const COLUMNS = [['identity', 220, 24], ['direct', 280, 24], ['company', 230, 16], ['dispatch', 227, 0]];
const LEGACY_COLUMNS = [[200, 16], [260, 16], [230, 16], [247, 20]];

function invalid() {
    throw Object.assign(new Error('NATIVE_LAYOUT_RESTORE_INVALID'), {
        code: 'NATIVE_LAYOUT_RESTORE_INVALID', phase: 'native-layout-preflight',
    });
}

function within(nodes, child, parent) {
    for (let index = child, depth = 0; Number.isInteger(index) && depth <= nodes.length; depth += 1) {
        if (index === parent) return true;
        if (!nodes[index]) return false;
        index = nodes[index].parent;
    }
    return false;
}

function children(nodes, index) {
    return nodes.map((node, position) => ({ node, index: position })).filter(value => value.node.parent === index);
}

function only(nodes, index, tag) {
    const found = children(nodes, index);
    if (found.length !== 1 || found[0].node.tag !== tag) invalid();
    return found[0];
}

function has(node, value) {
    return typeof node?.attrs?.class === 'string' && node.attrs.class.split(/\s+/).includes(value);
}

function properties(node) {
    const result = new Map();
    for (const declaration of (node.attrs.style || '').split(';')) {
        if (!declaration.trim()) continue;
        const colon = declaration.indexOf(':');
        if (colon <= 0) invalid();
        const name = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().replace(/\s*!important\s*$/i, '').toLowerCase();
        if (['all', 'padding-inline', 'padding-inline-start', 'padding-inline-end'].includes(name)) invalid();
        result.set(name, value);
    }
    return result;
}

function pixels(value) {
    const match = /^(\d+(?:\.\d+)?|\.\d+)(px|pt|cm|in)?$/.exec(value || '');
    return match ? Number(match[1]) * ({ pt: 4 / 3, cm: 96 / 2.54, in: 96 }[match[2]] || 1) : NaN;
}

function close(value, expected) {
    return Math.abs(pixels(value) - expected) < 0.05;
}

function numeric(node, name, expected, source = false) {
    const style = properties(node);
    const values = [node.attrs[name], style.get(name)].filter(value => value !== undefined);
    return values.length > 0 && (!source || node.attrs[name] === String(expected) && style.get(name) === expected + 'px')
        && values.every(value => close(value, expected));
}

function padding(node) {
    const result = { top: 0, right: 0, bottom: 0, left: 0 };
    for (const [name, value] of properties(node)) {
        if (name === 'padding') {
            const parts = value.split(/\s+/).map(pixels);
            if (!parts.length || parts.length > 4 || parts.some(part => !Number.isFinite(part))) invalid();
            [result.top, result.right, result.bottom, result.left] = [parts[0], parts[1] ?? parts[0], parts[2] ?? parts[0], parts[3] ?? parts[1] ?? parts[0]];
        } else if (/^padding-(top|right|bottom|left)$/.test(name)) result[name.slice(8)] = pixels(value);
    }
    return result;
}

function exactPadding(node, expected) {
    const actual = padding(node);
    if (!Object.keys(actual).every(name => Math.abs(actual[name] - expected[name]) < 0.05)) invalid();
}

function setStyle(node, declarations) {
    const names = new Set(Object.keys(declarations));
    const kept = (node.attrs.style || '').split(';').filter(value => {
        const colon = value.indexOf(':');
        return colon > 0 && !names.has(value.slice(0, colon).trim().toLowerCase());
    });
    node.attrs.style = kept.join(';') + (kept.length ? ';' : '')
        + Object.entries(declarations).map(([name, value]) => name + ':' + value + ';').join('');
}

function rewritePadding(node, value) {
    for (const key of [...properties(node).keys()]) {
        if (key === 'padding' || /^padding-(top|right|bottom|left)$/.test(key)) setStyle(node, { [key]: '' });
    }
    node.attrs.style = (node.attrs.style || '').split(';').filter(value => !/^\s*padding(?:-(?:top|right|bottom|left))?\s*:/i.test(value)).join(';');
    setStyle(node, { padding: value });
}

function resize(node, width, height = null) {
    node.attrs.width = String(width);
    setStyle(node, { width: width + 'px' });
    if (height !== null) node.attrs.height = String(height);
}

function text(html, node) {
    return html.slice(node.openEnd, node.close).replace(/<!--[\s\S]*?-->|<[^>]*>/g, '')
        .replace(/&nbsp;|&#160;|&#x0*a0;/gi, ' ').replace(/&amp;/gi, '&').replace(/\s+/g, ' ').trim();
}

function safe(node, hidden = false) {
    if (owns(node.attrs, 'id') || owns(node.attrs, 'hidden') || node.attrs['aria-hidden'] === 'true') invalid();
    for (const [name, value] of properties(node)) {
        if (name === 'min-width' && !close(value, 0) || name === 'position' && value !== 'static'
            || name === 'transform' && value !== 'none' || name === 'visibility' && value !== 'visible'
            || name === 'display' && value === 'none' && !hidden) invalid();
        if (['left', 'right', 'margin-left', 'margin-right'].includes(name) && value !== 'auto' && !close(value, 0)) invalid();
    }
}

function image(node, width, height, source) {
    safe(node);
    const style = properties(node);
    if (!numeric(node, 'width', width, source) || (source && !close(node.attrs.height, height))
        || node.attrs.height !== undefined && !close(node.attrs.height, height)
        || style.has('height') && style.get('height') !== 'auto' && !close(style.get('height'), height)) invalid();
    if (style.get('max-width') !== '100%' || style.get('height') !== 'auto') invalid();
}

function descendant(nodes, owner, tag) {
    return nodes.map((node, index) => ({ node, index })).filter(value => value.node.tag === tag && within(nodes, value.index, owner));
}

function fill(value) {
    const sha = '3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583';
    if (value === 'cid:railtime-' + sha.slice(0, 20) + '.png') return true;
    try {
        const url = new URL(value);
        return url.protocol === 'https:' && !url.username && !url.password && !url.search && !url.hash
            && ['/m/' + sha.slice(0, 10) + '.png', '/mail-assets/outlook-hotline-fill-v1.png'].includes(url.pathname);
    } catch { return false; }
}

function zeroBorder(value) {
    return value === 'none' || close(value, 0) || /^(?:0|0px|0pt)\s+(?:none|solid)(?:\s+[^;]+)?$/.test(value || '');
}

function outerFrames(nodes, root, signature, source) {
    const signatureStyle = properties(signature.node);
    if (!close(signatureStyle.get('max-width'), 1040) || source && !has(signature.node, 'rt-v16-shell')) invalid();
    for (let index = root.index; Number.isInteger(index); index = nodes[index].parent) {
        const node = nodes[index], style = properties(node);
        if (source && ['rt-managed-signature-v1', 'rt-combined-compose-frame', 'rt-shell'].some(value => has(node, value))) {
            if (!has(node, 'rt-v16-shell') || !close(style.get('max-width'), 1040) || style.get('margin') !== '0') invalid();
        }
        if (style.has('border-left') && !zeroBorder(style.get('border-left'))
            || style.has('border-left-width') && !close(style.get('border-left-width'), 0)) invalid();
        if (source && has(node, 'rt-v16-no-left') && !zeroBorder(style.get('border-left'))) invalid();
    }
}

function zeroLineTrivia(html, nodes, value) {
    if (!['p', 'div', 'span'].includes(value.node.tag)) return false;
    const nested = nodes.map((node, index) => ({ node, index })).filter(item => within(nodes, item.index, value.index));
    if (nested.some(item => !['p', 'div', 'span', 'br', 'o:p'].includes(item.node.tag)
        || Object.keys(item.node.attrs).some(name => name !== 'style'))) return false;
    const allowed = ['font-size', 'line-height', 'font-family', 'color', 'direction'];
    if (nested.some(item => [...properties(item.node)].some(([name, property]) => !allowed.includes(name)
        || name === 'direction' && property !== 'ltr'
        || ['font-size', 'line-height'].includes(name) && !close(property, 0)))) return false;
    const root = properties(value.node);
    return close(root.get('font-size'), 0) && close(root.get('line-height'), 0) && !text(html, value.node);
}

function retainedMetadata(html, nodes, value, contract) {
    // These indices were already individually proved hidden and owned by the
    // managed-template parser. A sibling which merely looks like a SPAN is not
    // metadata, and a wrapper around a proved leaf does not gain that authority.
    const metadata = [contract.signatureMetadata?.managed, contract.signatureMetadata?.version,
        contract.fluidLayout?.marker?.index];
    if (!metadata.includes(value.index) || !['span', 'div'].includes(value.node.tag)) return false;
    const nested = nodes.map((node, index) => ({ node, index })).filter(item => within(nodes, item.index, value.index));
    if (nested.some(item => !['span', 'div', 'font'].includes(item.node.tag)
        || Object.keys(item.node.attrs).some(name => !['class', 'style', 'hidden', 'aria-hidden'].includes(name)))) return false;
    const style = properties(value.node);
    if (style.has('font') || !(owns(value.node.attrs, 'hidden') || value.node.attrs['aria-hidden'] === 'true'
        || style.get('display') === 'none' || close(style.get('font-size'), 0) && close(style.get('line-height'), 0))) return false;
    const fluid = contract.fluidLayout;
    const versionNode = nodes[contract.signatureMetadata?.version];
    const version = fluid?.version || (versionNode && /RT-SIGNATURE-VERSION:([0-9a-f]{16})/i.exec(text(html, versionNode))?.[1]);
    if (!version) return false;
    let remaining = text(html, value.node).replace(/\s/g, '');
    const known = ['RT-SIGNATURE-MANAGED-V1', 'RT-SIGNATURE-VERSION:' + version];
    if (fluid?.nonce && ['B', 'M'].includes(fluid.profile)) {
        known.push('RT-FLUID-LAYOUT-V16' + fluid.profile + ':' + fluid.nonce + ':' + version);
    }
    let found = 0;
    for (const token of known) {
        if (!remaining.includes(token)) continue;
        remaining = remaining.replace(token, '');
        found += 1;
    }
    return found > 0 && !remaining;
}

function markerStyle(html, node, source) {
    if (node.tag !== 'style' || Object.keys(node.attrs).some(name => name !== 'data-rt-outlook-marker-css')
        || source && node.attrs['data-rt-outlook-marker-css'] !== '1') return false;
    return html.slice(node.openEnd, node.close).trim()
        === '.rt-office-metadata{display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;}';
}

function metadataGap(html, gap, next, nodes, contract) {
    if (!gap.trim()) return true;
    // The canonical marker helper emits its exact comment immediately before
    // the corresponding proved hidden SPAN. Other comments/text gain nothing.
    const metadata = [contract.signatureMetadata?.managed, contract.signatureMetadata?.version];
    if (!next || !metadata.includes(next.index)) return false;
    const comment = /^\s*<!--\s*(RT-SIGNATURE-(?:MANAGED-V1|VERSION:[0-9a-f]{16}))\s*-->\s*$/i.exec(gap);
    const version = contract.fluidLayout?.version
        || /RT-SIGNATURE-VERSION:([0-9a-f]{16})/i.exec(text(html, nodes[contract.signatureMetadata?.version]))?.[1];
    const expected = next.index === contract.signatureMetadata?.managed ? 'RT-SIGNATURE-MANAGED-V1'
        : 'RT-SIGNATURE-VERSION:' + version;
    // In the bounded B profile the version leaf also carries its own fluid
    // token. The comment still names the version, never that appended token.
    return !!comment && comment[1] === expected && retainedMetadata(html, nodes, next, contract);
}

function legalHostContents(html, nodes, legalHost, bar, legalTable, source, contract) {
    const direct = children(nodes, legalHost).sort((left, right) => left.node.start - right.node.start);
    let styles = 0;
    for (const value of direct) {
        if ([bar.index, legalTable.index].includes(value.index)) continue;
        if (value.node.start >= legalTable.node.end && retainedMetadata(html, nodes, value, contract)) continue;
        if (value.node.start >= legalTable.node.end && markerStyle(html, value.node, source) && ++styles <= 2) continue;
        if (source || !zeroLineTrivia(html, nodes, value)) invalid();
    }
    // Text nodes are deliberately absent from the structural parser. Check the
    // actual gaps as well, so bare unmanaged text cannot bypass sibling proof.
    let cursor = nodes[legalHost].openEnd;
    for (const value of direct) {
        if (value.node.start < cursor || !metadataGap(html, html.slice(cursor, value.node.start), value, nodes, contract)) invalid();
        cursor = value.node.end;
    }
    if (html.slice(cursor, nodes[legalHost].close).trim()) invalid();
}

// This checks the additional, presentation-gated content separately; the old
// material proof is never asked to assume that an arbitrary duplicate is safe.
function mobileBar(html, nodes, legalHost, desktop, source, contract) {
    const direct = children(nodes, legalHost);
    // Word/OWA may remove or prefix classes. The received candidate is still
    // exactly one direct DIV here and must pass the complete derived contents.
    const bars = direct.filter(value => value.node.tag === 'div' && (source ? has(value.node, 'rt-v16-mobile-dispatch')
        : descendant(nodes, value.index, 'a').length === 3 && descendant(nodes, value.index, 'img').length === 2));
    if (bars.length !== 1) invalid();
    const bar = bars[0], tables = direct.filter(value => value.node.tag === 'table');
    if (tables.length !== 1 || bar.node.end > tables[0].node.start || source && bar.node.end !== tables[0].node.start) invalid();
    legalHostContents(html, nodes, legalHost, bar, tables[0], source, contract);
    const nested = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, bar.index));
    if (nested.some(value => !['div', 'span', 'font', 'b', 'strong', 'a', 'img'].includes(value.node.tag))) invalid();
    safe(bar.node, true);
    const style = properties(bar.node);
    if (style.get('display') !== 'none' || style.get('mso-hide') !== 'all'
        || style.has('float') && style.get('float') !== 'none'
        || !['#e90032', 'rgb(233,0,50)'].includes((style.get('background-color') || '').replace(/\s/g, ''))
        || !['#fff', '#ffffff', 'white', 'rgb(255,255,255)'].includes((style.get('color') || '').replace(/\s/g, ''))
        || !close(style.get('font-size'), 12) || !close(style.get('line-height'), 18)
        || style.has('background')) invalid();
    const background = /^url\(\s*["']?([^"')]+)["']?\s*\)$/.exec(style.get('background-image') || '');
    if (!background || !fill(background[1])) invalid();
    exactPadding(bar.node, { top: 7, right: 20, bottom: 7, left: 20 });
    const links = nested.filter(value => value.node.tag === 'a');
    const images = nested.filter(value => value.node.tag === 'img');
    if (links.length !== 3 || images.length !== 2 || links.some((value, index) => value.node.attrs.href !== desktop.links[index].node.attrs.href)) invalid();
    if (images.some((value, index) => value.node.attrs.src !== desktop.images[index + 1].node.attrs.src)) invalid();
    images.forEach(value => {
        safe(value.node);
        const size = properties(value.node);
        for (const name of ['width', 'height']) {
            if (!close(value.node.attrs[name], 18) || size.has(name) && !close(size.get(name), 18)) invalid();
        }
    });
    if (!within(nodes, images[0].index, links[0].index) || !within(nodes, images[1].index, links[1].index)
        || text(html, links[0].node) || text(html, links[1].node)) invalid();
    const phone = text(html, links[2].node);
    if (!phone || phone !== text(html, desktop.links[2].node)) invalid();
    const allText = text(html, bar.node).replace(/\s/g, '');
    if (allText !== ('Dispo24/7' + phone).replace(/\s/g, '')) invalid();
    for (const value of nested) {
        const font = properties(value.node);
        if (font.has('font-size') && !close(font.get('font-size'), 12)
            || font.has('line-height') && !close(font.get('line-height'), 18)
            || font.has('direction') && font.get('direction') !== 'ltr'
            || value.node.attrs.dir !== undefined && value.node.attrs.dir.toLowerCase() !== 'ltr'
            || Object.keys(value.node.attrs).some(name => /^on/i.test(name))) invalid();
        if (value.node.tag === 'a') {
            if (!close(properties(value.node).get('margin-left'), 12)) invalid();
            const guarded = { ...value.node, attrs: { ...value.node.attrs } };
            setStyle(guarded, { 'margin-left': '0' });
            safe(guarded);
        } else if (value.index !== bar.index) safe(value.node);
        if (value.index === links[2].index && !['#fff', '#ffffff', 'white', 'rgb(255,255,255)']
            .includes((properties(value.node).get('color') || '').replace(/\s/g, ''))) invalid();
    }
    return { bar, legalTable: tables[0] };
}

/** Validate V16, then delegate unchanged historical semantics on cloned attrs. */
export function verifyV30ReferenceMaterial(html, contract, source, verifyLegacyMaterial) {
    const nodes = contract?.elements, signature = contract?.signature;
    if (typeof html !== 'string' || !Array.isArray(nodes) || !Number.isInteger(signature?.index)
        || typeof verifyLegacyMaterial !== 'function' || nodes[signature.index] !== signature.node) invalid();
    const scoped = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, signature.index));
    let root;
    if (source) {
        const found = scoped.filter(value => value.node.attrs['data-rt-desktop-flow'] === FLOW);
        if (found.length !== 1 || found[0].node.tag !== 'table' || !has(found[0].node, 'rt-v16-columns')) invalid();
        root = found[0];
    } else {
        if (contract.fluidLayout?.family !== 16 || !['B', 'M'].includes(contract.fluidLayout.profile)) invalid();
        const floats = scoped.filter(value => value.node.tag === 'table' && value.node.attrs.align?.toLowerCase() === 'left');
        if (floats.length !== 4 || floats.some(value => value.node.parent !== floats[0].node.parent)) invalid();
        let index = floats[0].node.parent;
        while (Number.isInteger(index) && nodes[index].tag !== 'table') index = nodes[index].parent;
        if (!Number.isInteger(index)) invalid();
        root = { node: nodes[index], index };
    }
    const rows = children(nodes, only(nodes, root.index, 'tbody').index);
    if (rows.length !== 2 || rows.some(value => value.node.tag !== 'tr')) invalid();
    const main = only(nodes, rows[0].index, 'td'), items = children(nodes, main.index);
    const columns = items.filter(value => value.node.tag === 'table');
    if (columns.length !== 4) invalid();
    outerFrames(nodes, root, signature, source);
    exactPadding(main.node, { top: 12, right: 40, bottom: 0, left: 40 });
    const original = nodes.map(node => ({ ...node, attrs: { ...node.attrs } }));
    const replaceFamily = value => typeof value === 'string' ? value.replace(/rt-df16(?=[0-9a-f]{10}(?:\s|$))/g, 'rt-df15').replace(/(^|\s)rt-v16-/g, '$1rt-v15-') : value;
    for (const node of original) {
        if (node.attrs.class) node.attrs.class = replaceFamily(node.attrs.class);
        if (node.attrs['data-rt-desktop-flow'] === FLOW) node.attrs['data-rt-desktop-flow'] = 'outlook-desktop-flow-v15';
        if (node.attrs['data-rt-dispatch-card'] === 'v16') node.attrs['data-rt-dispatch-card'] = 'v15';
    }
    const proxy = { ...contract, elements: original, signature: { ...signature, node: original[signature.index] },
        ...(source ? {} : { fluidLayout: { ...contract.fluidLayout, family: 15 } }) };
    rewritePadding(original[main.index], '12px 0 0 18px');
    for (const [position, [role, width, right]] of COLUMNS.entries()) {
        const column = columns[position];
        if (column.node.attrs.align?.toLowerCase() !== 'left' || !numeric(column.node, 'width', width, source)
            || source && !has(column.node, 'rt-v16-' + role)) invalid();
        safe(column.node);
        const cell = only(nodes, only(nodes, only(nodes, column.index, 'tbody').index, 'tr').index, 'td');
        exactPadding(cell.node, { top: 0, right, bottom: 0, left: 0 });
        resize(original[column.index], LEGACY_COLUMNS[position][0]);
        rewritePadding(original[cell.index], '0 ' + LEGACY_COLUMNS[position][1] + 'px 0 0');
        if (['direct', 'company'].includes(role)) {
            for (const row of descendant(nodes, column.index, 'tr')) {
                const cells = children(nodes, row.index);
                if (cells.length !== 2 || cells.some(value => value.node.tag !== 'td')) continue;
                const icons = descendant(nodes, cells[0].index, 'img');
                if (icons.length !== 1 || !numeric(cells[0].node, 'width', 26, source)) invalid();
                if (!numeric(icons[0].node, 'width', 15, source) || !numeric(icons[0].node, 'height', 15, source)) invalid();
                resize(original[cells[0].index], 27);
                setStyle(original[cells[0].index], { 'min-width': '27px' });
                resize(original[icons[0].index], 18, 18);
                setStyle(original[icons[0].index], { height: '18px' });
            }
        }
    }
    const stage = only(nodes, only(nodes, rows[1].index, 'td').index, 'table');
    if (!numeric(stage.node, 'width', 547, source) || properties(stage.node).get('max-width') !== '100%') invalid();
    const trains = descendant(nodes, stage.index, 'img');
    if (trains.length !== 1) invalid();
    image(trains[0].node, 529, 115, source);
    const dispatchImages = descendant(nodes, columns[3].index, 'img');
    const dispatchLinks = descendant(nodes, columns[3].index, 'a');
    if (dispatchImages.length !== 3 || dispatchLinks.length !== 3) invalid();
    image(dispatchImages[0].node, 199, 38, source);
    const signatureRows = children(nodes, only(nodes, signature.index, 'tbody').index);
    if (signatureRows.length !== 2 || signatureRows.some(value => value.node.tag !== 'tr')) invalid();
    const legalHost = only(nodes, signatureRows[1].index, 'td');
    const bar = mobileBar(html, nodes, legalHost.index, { links: dispatchLinks, images: dispatchImages }, source, contract);
    const legal = only(nodes, only(nodes, only(nodes, bar.legalTable.index, 'tbody').index, 'tr').index, 'td');
    exactPadding(legal.node, { top: 12, right: 40, bottom: 16, left: 40 });
    rewritePadding(original[legal.index], '12px 18px 16px 18px');
    const modern = !source && contract.fluidLayout.profile === 'M';
    if (!modern) {
        resize(original[stage.index], 242);
        resize(original[trains[0].index], 224, 49);
        resize(original[dispatchImages[0].index], 177, 34);
    }
    const proved = verifyLegacyMaterial(html, proxy, source);
    const actual = value => value && { ...value, node: nodes[value.index] };
    return { ...proved, root: actual(proved.root), columns: proved.columns.map(actual), train: actual(proved.train),
        caption: actual(proved.caption), trainImage: actual(proved.trainImage), mobileBar: bar.bar,
        sourceOnly: true, family: 16 };
}
