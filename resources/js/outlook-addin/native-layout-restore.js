// Only the verified incoming native signature is annotated; editable/quoted HTML stays opaque.
const PREFIX = 'rt-native-layout-';
const FLOW = 'data-rt-personal-flow';
const owns = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

function invalid() {
    throw Object.assign(new Error('NATIVE_LAYOUT_RESTORE_INVALID'), {
        code: 'NATIVE_LAYOUT_RESTORE_INVALID', phase: 'native-layout-preflight',
    });
}

function has(node, name) {
    return typeof node?.attrs?.class === 'string' && node.attrs.class.split(/\s+/).includes(name);
}

function within(nodes, child, parent) {
    for (let index = child, depth = 0; Number.isInteger(index) && depth <= nodes.length; depth += 1) {
        if (index === parent) return true;
        if (!nodes[index]) return false;
        index = nodes[index].parent;
    }
    return false;
}

function children(nodes, parent) {
    return nodes.map((node, index) => ({ node, index })).filter(value => value.node.parent === parent);
}

function one(nodes, owner, name, tag) {
    const found = nodes.map((node, index) => ({ node, index }))
        .filter(value => within(nodes, value.index, owner) && has(value.node, name));
    if (found.length !== 1 || found[0].node.tag !== tag) invalid();
    return found[0];
}

function onlyChild(nodes, owner, tag) {
    const found = children(nodes, owner);
    if (found.length !== 1 || found[0].node.tag !== tag) invalid();
    return found[0];
}

function tableOpening(html, node) {
    if (!Number.isInteger(node.start) || !Number.isInteger(node.openEnd)
        || !Number.isInteger(node.close) || !Number.isInteger(node.end)
        || node.start < 0 || node.openEnd <= node.start || node.close < node.openEnd || node.end > html.length) invalid();
    const opening = html.slice(node.start, node.openEnd);
    if (!/^<table\b(?:[^"'<>]|"[^"]*"|'[^']*')*>$/i.test(opening) || /\/\s*>$/.test(opening)
        || owns(node.attrs, 'id')) invalid();
    return opening;
}

/** A caller may supply its own cryptographically fresh 128-bit operation nonce. */
export function createNativeLayoutNonce(provider = globalThis.crypto) {
    if (typeof provider?.getRandomValues !== 'function') invalid();
    const bytes = provider.getRandomValues(new Uint8Array(16));
    if (!(bytes instanceof Uint8Array) || bytes.length !== 16) invalid();
    return Array.from(bytes, value => ('0' + value.toString(16)).slice(-2)).join('');
}

function restoreCss(ids) {
    // Outlook can prefix an ID with x_; the coordinator verifies unique suffix ownership first.
    const selector = key => `[id$="${ids[key]}"]`;
    const train = selector('train');
    let css = `${selector('root')}{overflow:hidden!important;}`
        + `${selector('person')}{float:left!important;}`
        + `${selector('company')}{float:right!important;}`
        + `${train}{float:left!important;clear:left!important;position:sticky!important;top:100%!important;}`
        + `${train}>tbody>tr>td>div{overflow:hidden!important;direction:rtl!important;max-width:420px!important;}`
        + `${train}>tbody>tr>td>div>img{width:520px!important;max-width:none!important;height:auto!important;}`;
    if (ids.header) {
        const firstRow = `${selector('header')}>tbody>tr:first-child`;
        css += ['td', 'div', 'p', 'span', 'strong', 'a'].map(tag => `${firstRow} ${tag}`).join(',')
            + '{color:#ffffff!important;-webkit-text-fill-color:#ffffff!important;}';
    }
    return css;
}

/** Exact trusted factory also lets the coordinator remove its previous owned sheet. */
export function nativeLayoutRestoreStylePayload(ids) {
    if (!ids || typeof ids !== 'object') invalid();
    const root = /^rt-native-layout-([0-9a-f]{32})-r$/.exec(ids.root || '');
    const suffixes = { root: 'r', person: 'p', company: 'c', train: 't', header: 'h' };
    const keys = Object.keys(ids);
    if (!root || keys.length !== (owns(ids, 'header') ? 5 : 4)
        || ['root', 'person', 'company', 'train'].some(key => !owns(ids, key))
        || keys.some(key => !owns(suffixes, key) || ids[key] !== PREFIX + root[1] + '-' + suffixes[key])) invalid();
    return `<style data-rt-native-layout-restore="v1">${restoreCss(ids)}</style>`;
}

/**
 * The contract must come from the managed-template parser for this exact trusted
 * incoming document, before editable content is spliced into its new shell.
 * The coordinator must verify every ID after setAsync before prepending stylePayload.
 */
export function prepareNativeLayoutRestore(html, contract, nonce) {
    if (typeof html !== 'string' || !Array.isArray(contract?.elements)
        || !Number.isInteger(contract?.signature?.index)) invalid();
    const nodes = contract.elements;
    if (nodes.some(node => !node || !node.attrs || typeof node.attrs !== 'object')) invalid();
    const owner = contract.signature.index;
    if (!nodes[owner] || nodes[owner] !== contract.signature.node) invalid();
    const signature = one(nodes, owner, 'rt-managed-signature-v1', 'table');
    const candidates = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, signature.index)
        && (has(value.node, 'rt-personal-columns') || owns(value.node.attrs, FLOW)));
    if (!candidates.length) return null;
    if (candidates.length !== 1 || candidates[0].node.tag !== 'table'
        || !has(candidates[0].node, 'rt-personal-columns') || candidates[0].node.attrs[FLOW] !== 'v1'
        || candidates[0].node.attrs['data-rt-personal-columns'] !== 'v1'
        || candidates[0].node.attrs['data-rt-desktop-flow'] !== 'outlook-desktop-flow-v4') invalid();
    const root = candidates[0];
    const body = onlyChild(nodes, root.index, 'tbody');
    const row = onlyChild(nodes, body.index, 'tr');
    const cell = onlyChild(nodes, row.index, 'td');
    const person = one(nodes, root.index, 'rt-personal-left', 'table');
    const company = one(nodes, root.index, 'rt-personal-right', 'table');
    const train = one(nodes, root.index, 'rt-df-train', 'table');
    const groups = children(nodes, cell.index);
    if (groups.length !== 3 || groups.some((value, index) => value.index !== [person.index, company.index, train.index][index])) invalid();
    const trainBody = onlyChild(nodes, train.index, 'tbody');
    const trainRow = onlyChild(nodes, trainBody.index, 'tr');
    const trainCell = onlyChild(nodes, trainRow.index, 'td');
    const window = onlyChild(nodes, trainCell.index, 'div');
    if (!has(window.node, 'rt-train-window')) invalid();
    const image = onlyChild(nodes, window.index, 'img');
    if (!has(image.node, 'rt-delivery-train') || image.node.attrs.width !== '300' || image.node.attrs.height !== '38') invalid();

    const targets = { root, person, company, train };
    const hotline = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, signature.index)
        && has(value.node, 'design-hotline-row'));
    if (hotline.length > 1) invalid();
    if (hotline.length) {
        if (hotline[0].node.tag !== 'td') invalid();
        let index = hotline[0].node.parent;
        for (let depth = 0; Number.isInteger(index) && nodes[index].tag !== 'table' && depth <= nodes.length; depth += 1) index = nodes[index].parent;
        if (!Number.isInteger(index) || nodes[index].tag !== 'table'
            || !within(nodes, index, signature.index) || within(nodes, index, root.index)) invalid();
        const header = { node: nodes[index], index };
        const headerBody = onlyChild(nodes, index, 'tbody');
        const headerRows = children(nodes, headerBody.index);
        if (!headerRows.length || headerRows.some(value => value.node.tag !== 'tr')
            || !within(nodes, hotline[0].index, headerRows[0].index)) invalid();
        targets.header = header;
    }

    const scope = nonce === undefined ? createNativeLayoutNonce() : nonce;
    if (typeof scope !== 'string' || !/^[0-9a-f]{32}$/.test(scope) || html.includes(PREFIX + scope)) invalid();
    const ids = {};
    const edits = [];
    const suffixes = { root: 'r', person: 'p', company: 'c', train: 't', header: 'h' };
    for (const key of Object.keys(targets)) {
        const { node } = targets[key];
        if (node.start < signature.node.openEnd || node.end > signature.node.close) invalid();
        const opening = tableOpening(html, node);
        ids[key] = PREFIX + scope + '-' + suffixes[key];
        edits.push({ start: node.start, end: node.openEnd, value: opening.slice(0, -1) + ` id="${ids[key]}">` });
    }
    edits.sort((left, right) => right.start - left.start);
    let modified = html;
    for (const edit of edits) modified = modified.slice(0, edit.start) + edit.value + modified.slice(edit.end);
    const frozenIds = Object.freeze(ids);
    return Object.freeze({ html: modified, ids: frozenIds, stylePayload: nativeLayoutRestoreStylePayload(frozenIds) });
}
