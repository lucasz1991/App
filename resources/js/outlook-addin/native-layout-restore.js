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
    const selector = key => '#' + ids[key];
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
            + '{color:#ffffff!important;}';
    }
    if (ids.signature) {
        const legalRow = `${selector('signature')}>tbody>tr:last-child`;
        css += ['td', 'div', 'p', 'span', 'strong', 'b', 'font', 'a'].map(tag => `${legalRow} ${tag}`).join(',')
            + '{color:#718096!important;}';
    }
    return css;
}

function idsScope(ids) {
    if (!ids || typeof ids !== 'object') invalid();
    const root = /^rt-native-layout-([0-9a-f]{32})-r$/.exec(ids.root || '');
    const suffixes = { root: 'r', person: 'p', company: 'c', train: 't', header: 'h', signature: 's' };
    const keys = Object.keys(ids);
    if (!root || keys.length !== 4 + Number(owns(ids, 'header')) + Number(owns(ids, 'signature'))
        || ['root', 'person', 'company', 'train'].some(key => !owns(ids, key))
        || keys.some(key => !owns(suffixes, key) || ids[key] !== PREFIX + root[1] + '-' + suffixes[key])) invalid();
    return root[1];
}

export function nativeLayoutRestoreCss(ids) {
    idsScope(ids);
    return restoreCss(ids);
}

/** An empty presentation carrier gives Office a real HTML insertion, not a discarded bare STYLE. */
export function nativeLayoutRestoreStylePayload(ids) {
    const scope = idsScope(ids);
    return `<table id="${PREFIX + scope}-carrier" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;border-spacing:0;margin:0;font-size:0;line-height:0;"><tbody><tr><td style="padding:0;height:0;font-size:0;line-height:0;">`
        + `<style data-rt-native-layout-restore="v1">${restoreCss(ids)}</style>`
        + `<span hidden aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;">RT-NATIVE-LAYOUT-RESTORE-V1:${scope}</span>`
        + '</td></tr></tbody></table>';
}

function isQuoted(nodes, index) {
    for (let current = index, depth = 0; Number.isInteger(current) && depth <= nodes.length; depth += 1) {
        const node = nodes[current];
        if (node.tag === 'blockquote' || /^(?:x_)?(?:divrplyfwdmsg|mail-editor-reference-message-container|stopspelling)$/i.test(node.attrs.id || '')
            || /(?:^|\s)(?:x_)?(?:gmail_quote|yahoo_quoted|moz-cite-prefix)(?:\s|$)/.test(node.attrs.class || '')) return true;
        current = node.parent;
    }
    return false;
}

function normalizedRestoreCss(css, ids) {
    let normalized = css;
    // Accept only the observed Office x_ rewrite of our exact generated ID selectors.
    for (const id of Object.values(ids)) normalized = normalized.replace(new RegExp('#x_' + id + '(?![\\w-])', 'g'), '#' + id);
    return normalized.replace(/\s+/g, '').replace(/;}/g, '}');
}

function standaloneSignatureFrame(contract, nodes) {
    const frame = contract.signature;
    const root = contract.root?.node || contract.root;
    const rootIndex = contract.root?.node ? contract.root.index : contract.rootIndex;
    if (!Number.isInteger(frame?.index) || nodes[frame.index] !== frame.node
        || frame.node.tag !== 'table' || root !== frame.node || rootIndex !== frame.index) invalid();
    return frame;
}

/** Any same-operation carrier, even invalid/quoted, prevents another prepend. */
export function nativeLayoutRestoreCarrierPresent(html, contract, ids) {
    const scope = idsScope(ids);
    if (typeof html !== 'string' || !Array.isArray(contract?.elements)) invalid();
    const carrierId = PREFIX + scope + '-carrier';
    return contract.elements.some(node => (typeof node?.attrs?.id === 'string' && node.attrs.id.endsWith(carrierId))
        || (node?.tag === 'style' && html.slice(node.openEnd, node.close).includes(PREFIX + scope)))
        || html.includes('RT-NATIVE-LAYOUT-RESTORE-V1:' + scope);
}

function retainedCarrier(html, contract, ids) {
    const scope = idsScope(ids);
    const nodes = contract?.elements;
    if (typeof html !== 'string' || !Array.isArray(nodes) || !contract.root || !contract.signature) invalid();
    const standalone = contract.kind === 'standalone-signature-v1';
    const frame = standalone ? standaloneSignatureFrame(contract, nodes) : null;
    const actualIds = {};
    const targets = {};
    for (const key of Object.keys(ids)) {
        const matches = nodes.map((node, index) => ({ node, index })).filter(value => typeof value.node.attrs.id === 'string' && value.node.attrs.id.endsWith(ids[key]));
        if (matches.length !== 1 || matches[0].node.tag !== 'table'
            || ![ids[key], 'x_' + ids[key]].includes(matches[0].node.attrs.id)
            || !within(nodes, matches[0].index, contract.signature.index) || isQuoted(nodes, matches[0].index)) invalid();
        actualIds[key] = matches[0].node.attrs.id;
        targets[key] = matches[0];
    }
    const carrierId = PREFIX + scope + '-carrier';
    const carriers = nodes.map((node, index) => ({ node, index })).filter(value => typeof value.node.attrs.id === 'string' && value.node.attrs.id.endsWith(carrierId));
    if (carriers.length !== 1 || carriers[0].node.tag !== 'table'
        || ![carrierId, 'x_' + carrierId].includes(carriers[0].node.attrs.id) || isQuoted(nodes, carriers[0].index)) invalid();
    const carrier = carriers[0];
    const root = frame?.node || contract.root;
    const outsideRoot = carrier.node.end <= root.start || carrier.node.start >= root.end;
    if (!outsideRoot) {
        const rootBody = onlyChild(nodes, standalone ? frame.index : contract.rootIndex, 'tbody');
        const rows = standalone ? children(nodes, rootBody.index) : null;
        if (standalone && (rows.length !== 2 || rows.some(value => value.node.tag !== 'tr'))) invalid();
        const rootRow = standalone ? rows[0] : onlyChild(nodes, rootBody.index, 'tr');
        const rootCell = onlyChild(nodes, rootRow.index, 'td');
        let parent = carrier.node.parent;
        let wrappers = 0;
        while (parent !== rootCell.index) {
            if (!Number.isInteger(parent) || nodes[parent].tag !== 'div' || wrappers >= 2) invalid();
            parent = nodes[parent].parent;
            wrappers += 1;
        }
        if (standalone) {
            if (!within(nodes, targets.root.index, rootCell.index)
                || carrier.node.end > targets.root.node.start
                || (targets.header && (!within(nodes, targets.header.index, rootCell.index)
                    || carrier.node.end > targets.header.node.start))) invalid();
        } else if (!contract.template || carrier.node.end > contract.template.node.start
            || carrier.node.end > contract.signature.node.start
            || within(nodes, carrier.index, contract.template.index) || within(nodes, carrier.index, contract.signature.index)) invalid();
    }
    const body = onlyChild(nodes, carrier.index, 'tbody');
    const row = onlyChild(nodes, body.index, 'tr');
    const cell = onlyChild(nodes, row.index, 'td');
    const descendants = nodes.map((node, index) => ({ node, index })).filter(value => value.index !== cell.index && within(nodes, value.index, cell.index));
    if (descendants.some(value => !['style', 'span', 'div', 'br'].includes(value.node.tag))) invalid();
    const styles = descendants.filter(value => value.node.tag === 'style');
    const token = 'RT-NATIVE-LAYOUT-RESTORE-V1:' + scope;
    const spans = descendants.filter(value => value.node.tag === 'span' && html.slice(value.node.openEnd, value.node.close).replace(/\s+/g, '') === token);
    if (styles.length !== 1 || spans.length !== 1) invalid();
    const css = normalizedRestoreCss(nativeLayoutRestoreCss(ids), ids);
    const retainedCss = html.slice(styles[0].node.openEnd, styles[0].node.close);
    for (const key of Object.keys(ids)) {
        const selectors = retainedCss.match(new RegExp('#(?:x_)?' + ids[key] + '(?![\\w-])', 'g')) || [];
        if (!selectors.length || selectors.some(selector => selector !== '#' + actualIds[key])) invalid();
    }
    const exactSheets = nodes.filter(node => node.tag === 'style' && normalizedRestoreCss(html.slice(node.openEnd, node.close), ids) === css);
    if (exactSheets.length !== 1 || normalizedRestoreCss(retainedCss, ids) !== css) invalid();
    const metadata = spans[0].node;
    const hidden = owns(metadata.attrs, 'hidden') || metadata.attrs['aria-hidden'] === 'true'
        || /(?:^|;)\s*display\s*:\s*none(?:\s*!important)?\s*(?:;|$)/i.test(metadata.attrs.style || '')
        || (/(?:^|;)\s*font-size\s*:\s*0(?:px|pt)?(?:\s*!important)?\s*(?:;|$)/i.test(metadata.attrs.style || '')
            && /(?:^|;)\s*line-height\s*:\s*0(?:px|pt)?(?:\s*!important)?\s*(?:;|$)/i.test(metadata.attrs.style || ''));
    if (!hidden) invalid();
    const ranges = [styles[0].node, metadata].sort((left, right) => right.start - left.start);
    let remaining = html.slice(cell.node.openEnd, cell.node.close);
    for (const node of ranges) remaining = remaining.slice(0, node.start - cell.node.openEnd) + remaining.slice(node.end - cell.node.openEnd);
    if (remaining.includes('<!--') || remaining.replace(/<[^>]*>/g, '').replace(/&nbsp;|&#160;|&#xa0;/gi, ' ').trim() !== '') invalid();
    return carrier.node;
}

/** A success callback is insufficient: a fresh SDK read must retain this exact owned CSS carrier. */
export function nativeLayoutRestoreRetained(html, contract, ids) {
    retainedCarrier(html, contract, ids);
    return true;
}

export function removeNativeLayoutRestoreCarrier(html, contract, ids) {
    try {
        const carrier = retainedCarrier(html, contract, ids);
        return html.slice(0, carrier.start) + html.slice(carrier.end);
    } catch {
        return html;
    }
}

/**
 * The contract must come from the managed-template parser for this exact trusted
 * incoming document, before editable content is spliced into its new shell.
 * The coordinator must verify every ID after the SDK write before prepending stylePayload.
 */
export function prepareNativeLayoutRestore(html, contract, nonce) {
    if (typeof html !== 'string' || !Array.isArray(contract?.elements)
        || !Number.isInteger(contract?.signature?.index)) invalid();
    const nodes = contract.elements;
    if (nodes.some(node => !node || !node.attrs || typeof node.attrs !== 'object')) invalid();
    const owner = contract.signature.index;
    if (!nodes[owner] || nodes[owner] !== contract.signature.node) invalid();
    if (contract.kind === 'standalone-signature-v1') standaloneSignatureFrame(contract, nodes);
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

    // Only the existing two-row signature's known gray legal row opts in.
    const signatureBodies = children(nodes, signature.index);
    const signatureRows = signatureBodies.length === 1 && signatureBodies[0].node.tag === 'tbody'
        ? children(nodes, signatureBodies[0].index) : [];
    if (signatureRows.length === 2 && signatureRows.every(value => value.node.tag === 'tr')
        && within(nodes, root.index, signatureRows[0].index) && !within(nodes, root.index, signatureRows[1].index)) {
        const footer = signatureRows[1];
        const footerCells = children(nodes, footer.index);
        const footerHtml = html.slice(footer.node.openEnd, footer.node.close);
        const forbidden = nodes.some((node, index) => within(nodes, index, footer.index)
            && (has(node, 'rt-managed-template-v1') || has(node, 'rt-compose-editable')
                || has(node, 'rt-compose-editable-v1') || owns(node.attrs, 'data-rt-compose-document') || owns(node.attrs, 'data-rt-compose-editable')));
        if (footerCells.length === 1 && footerCells[0].node.tag === 'td' && !forbidden
            && /(?:^|;)\s*color\s*:\s*(?:#718096|rgb\(\s*113\s*,\s*128\s*,\s*150\s*\))\s*(?:!important\s*)?(?:;|$)/i.test(footerCells[0].node.attrs.style || '')
            && /RT-SIGNATURE-MANAGED-V1(?=-->|[\s"'<>]|$)/.test(footerHtml)
            && /RT-SIGNATURE-VERSION:[0-9a-f]{16}(?=-->|[\s"'<>]|$)/i.test(footerHtml)) {
            if (targets.header && within(nodes, targets.header.index, footer.index)) invalid();
            targets.signature = signature;
        }
    }

    const scope = nonce === undefined ? createNativeLayoutNonce() : nonce;
    if (typeof scope !== 'string' || !/^[0-9a-f]{32}$/.test(scope) || html.includes(PREFIX + scope)) invalid();
    const ids = {};
    const edits = [];
    const suffixes = { root: 'r', person: 'p', company: 'c', train: 't', header: 'h', signature: 's' };
    for (const key of Object.keys(targets)) {
        const { node } = targets[key];
        if (key !== 'signature' && (node.start < signature.node.openEnd || node.end > signature.node.close)) invalid();
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
