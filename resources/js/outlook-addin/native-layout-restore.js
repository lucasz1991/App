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

function targetOpening(html, node) {
    if (node.tag === 'table') return tableOpening(html, node);
    if (!['div', 'img'].includes(node.tag) || !Number.isInteger(node.start) || !Number.isInteger(node.openEnd)
        || node.start < 0 || node.openEnd <= node.start || node.openEnd > html.length || owns(node.attrs, 'id')) invalid();
    const opening = html.slice(node.start, node.openEnd);
    if (!new RegExp('^<' + node.tag + '\\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>$', 'i').test(opening) || /\/\s*>$/.test(opening)) invalid();
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
    if (ids.logo) return responsiveRestoreCss(ids);
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

// Published R62/R63 mixed-column core is immutable owned-cleanup evidence.
function legacyMixedCoreCss(ids) {
    // Received Outlook mail can discard an ID ancestor yet keep its final tag
    // selector. A train descendant IMG/DIV rule then damages the whole message.
    // Every new rule therefore contains one owned ID and no combinator or tag.
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    const columns = ['logo', 'person', 'company', 'train'];
    let css = rule('root', 'font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:680px!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;overflow:hidden!important;direction:ltr!important;margin:0 auto 0 0!important;');
    for (const key of columns) {
        css += rule(key, 'display:' + (key === 'train' ? 'inline-table' : 'inline-block') + '!important;vertical-align:' + (key === 'train' ? 'bottom' : 'top') + '!important;width:100%!important;min-width:0!important;max-width:340px!important;direction:ltr!important;text-align:left!important;table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important;box-sizing:border-box!important;'
            + (key === 'train' ? 'border-left:0!important;' : 'border-left:40px solid transparent!important;')
            + 'border-right:40px solid transparent!important;');
    }
    css += rule('logo', 'margin-bottom:10px!important;')
        + rule('company', 'border-top:4px solid transparent!important;')
        + rule('person', 'float:left!important;')
        + rule('train', 'float:left!important;clear:left!important;');
    if (ids.header) css += rule('header', 'color:#ffffff!important;table-layout:auto!important;width:100%!important;');
    // The two-row signature ID owns both its main content and legal row; a
    // color override here would also recolor personal/company text. Legal
    // paint stays in its already compiled inline declarations.
    if (ids.signature) css += rule('signature', 'border-collapse:separate!important;border-spacing:0!important;');
    if (ids.inset) css += rule('inset', 'width:40px!important;min-width:40px!important;max-width:40px!important;');
    if (ids.footer) css += rule('footer', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    if (ids.templateGutter) css += rule('templateGutter', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    return css;
}

// Published R64 two-row core is immutable owned-cleanup evidence.
function legacyRowsCoreCss(ids) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    let css = rule('root', 'font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:680px!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;overflow:hidden!important;direction:ltr!important;margin:0 auto 0 0!important;');
    for (const key of ['logo', 'person', 'company', 'train']) {
        css += rule(key, 'display:' + (['person', 'train'].includes(key) ? 'inline-table' : 'inline-block') + '!important;vertical-align:' + (key === 'train' ? 'bottom' : 'top') + '!important;width:100%!important;min-width:0!important;max-width:340px!important;direction:ltr!important;text-align:left!important;table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important;box-sizing:border-box!important;'
            + (key === 'train' ? 'border-left:0!important;' : 'border-left:40px solid transparent!important;')
            + 'border-right:40px solid transparent!important;');
    }
    css += rule('logo', 'margin-bottom:10px!important;')
        + rule('company', 'border-top:4px solid transparent!important;')
        + rule('person', 'height:50px!important;min-height:50px!important;');
    if (ids.window) css += rule('window', 'overflow:hidden!important;padding-top:9.2%!important;box-sizing:border-box!important;width:100%!important;font-size:0!important;line-height:0!important;direction:ltr!important;');
    if (ids.trainImage) css += rule('trainImage', 'display:block!important;width:100%!important;max-width:100%!important;height:auto!important;margin:0!important;border:0!important;vertical-align:bottom!important;');
    if (ids.header) css += rule('header', 'color:#ffffff!important;table-layout:auto!important;width:100%!important;');
    if (ids.signature) css += rule('signature', 'border-collapse:separate!important;border-spacing:0!important;');
    if (ids.inset) css += rule('inset', 'width:40px!important;min-width:40px!important;max-width:40px!important;');
    if (ids.footer) css += rule('footer', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    if (ids.templateGutter) css += rule('templateGutter', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    return css;
}

// Published R65/R66 intrinsic two-row core is immutable owned-cleanup evidence.
function legacyIntrinsicRowsCoreCss(ids) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    let css = rule('root', 'font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:680px!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;overflow:hidden!important;direction:ltr!important;margin:0 auto 0 0!important;');
    for (const key of ['logo', 'person', 'company', 'train']) {
        css += rule(key, 'display:' + (['person', 'train'].includes(key) ? 'inline-table' : 'inline-block') + '!important;vertical-align:' + (key === 'train' ? 'bottom' : 'top') + '!important;width:100%!important;min-width:0!important;max-width:340px!important;direction:ltr!important;'
            + (key === 'person' ? '' : 'text-align:left!important;') + 'table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important;box-sizing:border-box!important;'
            + (key === 'train' ? 'border-left:0!important;' : 'border-left:40px solid transparent!important;')
            + 'border-right:40px solid transparent!important;');
    }
    css += rule('logo', 'margin-bottom:10px!important;')
        + rule('company', 'border-top:4px solid transparent!important;')
        + rule('person', 'height:50px!important;min-height:50px!important;');
    if (ids.window) css += rule('window', 'padding-top:0!important;');
    if (ids.trainImage) css += rule('trainImage', 'display:block!important;width:100%!important;max-width:100%!important;height:auto!important;margin:0!important;border:0!important;vertical-align:bottom!important;');
    if (ids.header) css += rule('header', 'color:#ffffff!important;table-layout:auto!important;width:100%!important;');
    if (ids.signature) css += rule('signature', 'border-collapse:separate!important;border-spacing:0!important;');
    if (ids.inset) css += rule('inset', 'width:40px!important;min-width:40px!important;max-width:40px!important;');
    if (ids.footer) css += rule('footer', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    if (ids.templateGutter) css += rule('templateGutter', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    return css;
}

// The enlarged train has its own k image ID; historical j CSS stays byte exact.
// Both retain the established two-row, function-free transport fallback.
function responsiveRestoreCss(ids) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    const enlargedTrain = ids.trainImage?.endsWith('-k');
    let css = rule('root', 'font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:680px!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;overflow:hidden!important;direction:ltr!important;margin:0 auto 0 0!important;');
    for (const key of ['logo', 'person', 'company', 'train']) {
        css += rule(key, 'display:' + (['person', 'train'].includes(key) ? 'inline-table' : 'inline-block') + '!important;vertical-align:' + (key === 'train' ? 'bottom' : 'top') + '!important;width:100%!important;min-width:0!important;max-width:' + (key === 'train' && enlargedTrain ? '364' : '340') + 'px!important;direction:ltr!important;'
            + (key === 'person' ? '' : 'text-align:left!important;') + 'table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important;box-sizing:border-box!important;'
            + (key === 'train' ? 'border-left:0!important;' : 'border-left:40px solid transparent!important;')
            + 'border-right:40px solid transparent!important;');
    }
    css += rule('logo', 'margin-bottom:10px!important;')
        + rule('company', 'border-top:0!important;')
        + rule('person', 'height:50px!important;min-height:50px!important;');
    if (ids.window) css += rule('window', 'padding-top:0!important;');
    if (ids.trainImage) css += rule('trainImage', 'display:block!important;width:100%!important;max-width:100%!important;height:auto!important;margin:0!important;border:0!important;vertical-align:bottom!important;');
    if (ids.header) css += rule('header', 'color:#ffffff!important;table-layout:auto!important;width:100%!important;');
    if (ids.signature) css += rule('signature', 'border-collapse:separate!important;border-spacing:0!important;');
    if (ids.inset) css += rule('inset', 'width:40px!important;min-width:40px!important;max-width:40px!important;');
    if (ids.footer) css += rule('footer', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    if (ids.templateGutter) css += rule('templateGutter', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    return css;
}

// Exact R60 core is recognized only for complete owned-carrier cleanup.
// It can never satisfy current-layout readback or acknowledge a new write.
function legacyColumnCoreCss(ids) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    let css = rule('root', 'font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:920px!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;overflow:hidden!important;');
    for (const key of ['logo', 'person', 'company', 'train']) {
        css += rule(key, 'display:table!important;vertical-align:top!important;width:460px!important;min-width:0!important;max-width:100%!important;table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important;box-sizing:border-box!important;'
            + (key === 'train' ? 'border-left:0!important;' : 'border-left:40px solid transparent!important;') + 'border-right:40px solid transparent!important;');
    }
    css += rule('logo', 'float:right!important;margin-bottom:10px!important;')
        + rule('person', 'float:left!important;')
        + rule('company', 'float:right!important;clear:right!important;border-top:4px solid transparent!important;')
        + rule('train', 'float:left!important;clear:left!important;position:sticky!important;top:100%!important;');
    if (ids.header) css += rule('header', 'color:#ffffff!important;table-layout:auto!important;width:100%!important;');
    if (ids.signature) css += rule('signature', 'border-collapse:separate!important;border-spacing:0!important;');
    if (ids.inset) css += rule('inset', 'width:40px!important;min-width:40px!important;max-width:40px!important;');
    if (ids.footer) css += rule('footer', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    if (ids.templateGutter) css += rule('templateGutter', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    return css;
}

// Published R61 (9430) is immutable cleanup evidence, including its old RTL
// container and missing cell-alignment protection. It never confirms success.
function legacyHybridCoreCss(ids) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    let css = rule('root', 'font-size:0!important;line-height:0!important;text-align:left!important;width:100%!important;max-width:680px!important;display:table!important;table-layout:fixed!important;border-collapse:collapse!important;overflow:hidden!important;direction:rtl!important;');
    for (const key of ['logo', 'person', 'company', 'train']) {
        css += rule(key, 'display:' + (key === 'train' ? 'inline-table' : 'inline-block') + '!important;vertical-align:' + (key === 'train' ? 'bottom' : 'top') + '!important;width:100%!important;min-width:0!important;max-width:340px!important;direction:ltr!important;table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important;box-sizing:border-box!important;'
            + (key === 'train' ? 'border-left:0!important;' : 'border-left:40px solid transparent!important;') + 'border-right:40px solid transparent!important;');
    }
    css += rule('logo', 'margin-bottom:10px!important;') + rule('company', 'border-top:4px solid transparent!important;');
    if (ids.header) css += rule('header', 'color:#ffffff!important;table-layout:auto!important;width:100%!important;');
    if (ids.signature) css += rule('signature', 'border-collapse:separate!important;border-spacing:0!important;');
    if (ids.inset) css += rule('inset', 'width:40px!important;min-width:40px!important;max-width:40px!important;');
    if (ids.footer) css += rule('footer', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    if (ids.templateGutter) css += rule('templateGutter', 'border-left:40px solid transparent!important;border-right:40px solid transparent!important;box-sizing:border-box!important;border-collapse:separate!important;border-spacing:0!important;');
    return css;
}

function legacyHybridResponsiveRules(ids) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    const media = ['logo', 'person', 'company', 'train'].map(key => rule(key, 'width:100%!important;min-width:0!important;max-width:100%!important;'
        + (key === 'train' ? '' : 'border-left-width:18px!important;') + 'border-right-width:18px!important;'));
    media.push(rule('logo', 'margin-bottom:10px!important;'));
    if (ids.inset) media.push(rule('inset', 'width:18px!important;min-width:18px!important;max-width:18px!important;'));
    if (ids.footer) media.push(rule('footer', 'border-left-width:18px!important;border-right-width:18px!important;'));
    if (ids.templateGutter) media.push(rule('templateGutter', 'border-left-width:18px!important;border-right-width:18px!important;'));
    return { base: [], media };
}

function responsiveRestoreRule(ids, key, declarations) {
    const id = ids[key];
    if (!/^rt-native-layout-[0-9a-f]{32}-[rpcthslifqwjk]$/.test(id || '') || /[{}]/.test(declarations)) invalid();
    return '#' + id + '{' + declarations + '}';
}

function responsiveRestoreRules(ids, legacyCalc = false, historical = false) {
    const rule = (key, declarations) => responsiveRestoreRule(ids, key, declarations);
    const columns = ['logo', 'person', 'company', 'train'];
    const base = legacyCalc ? columns.map(key => rule(key, 'width:calc(680000px - 100000%)!important;min-width:50%!important;max-width:100%!important;')) : [];
    if (historical === 'rows-r64' && ids.trainImage) base.push(rule('trainImage', 'transform:scale(1.733333)!important;transform-origin:100% 100%!important;'));
    const media = [];
    for (const key of columns) {
        media.push(rule(key, 'width:100%!important;min-width:0!important;max-width:100%!important;' + (legacyCalc ? 'float:none!important;clear:both!important;position:static!important;top:auto!important;' : '')
            + (key === 'train' ? '' : 'border-left-width:18px!important;') + 'border-right-width:18px!important;'));
    }
    media.push(rule('logo', 'margin-bottom:10px!important;'));
    if (ids.inset) media.push(rule('inset', 'width:18px!important;min-width:18px!important;max-width:18px!important;'));
    if (ids.footer) media.push(rule('footer', 'border-left-width:18px!important;border-right-width:18px!important;'));
    if (ids.templateGutter) media.push(rule('templateGutter', 'border-left-width:18px!important;border-right-width:18px!important;'));
    return { base, media };
}

function responsiveRestoreOptionalCss(ids) {
    const { base, media } = responsiveRestoreRules(ids);
    return base.join('') + '@media(max-width:680px){' + media.join('') + '}';
}

function idsScope(ids) {
    if (!ids || typeof ids !== 'object') invalid();
    const root = /^rt-native-layout-([0-9a-f]{32})-r$/.exec(ids.root || '');
    const suffixes = { root: 'r', person: 'p', company: 'c', train: 't', header: 'h', signature: 's', logo: 'l', inset: 'i', footer: 'f', templateGutter: 'q', window: 'w', trainImage: ids.trainImage?.endsWith('-k') ? 'k' : 'j' };
    const keys = Object.keys(ids);
    if (!root || keys.length !== 4 + ['header', 'signature', 'logo', 'inset', 'footer', 'templateGutter', 'window', 'trainImage'].filter(key => owns(ids, key)).length
        || ['root', 'person', 'company', 'train'].some(key => !owns(ids, key))
        || (owns(ids, 'inset') && (!owns(ids, 'logo') || !owns(ids, 'header')))
        || (owns(ids, 'footer') && (!owns(ids, 'logo') || !owns(ids, 'signature')))
        || (owns(ids, 'templateGutter') && !owns(ids, 'logo'))
        || (owns(ids, 'window') !== owns(ids, 'trainImage')) || (owns(ids, 'window') && !owns(ids, 'logo'))
        || keys.some(key => !owns(suffixes, key) || ids[key] !== PREFIX + root[1] + '-' + suffixes[key])) invalid();
    return root[1];
}

export function nativeLayoutRestoreCss(ids) {
    idsScope(ids);
    return restoreCss(ids);
}

export function nativeLayoutRestoreResponsiveCss(ids) {
    idsScope(ids);
    return ids.logo ? responsiveRestoreOptionalCss(ids) : '';
}

/** An empty presentation carrier gives Office a real HTML insertion, not a discarded bare STYLE. */
export function nativeLayoutRestoreStylePayload(ids) {
    const scope = idsScope(ids);
    return `<table id="${PREFIX + scope}-carrier" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;border-spacing:0;margin:0;font-size:0;line-height:0;"><tbody><tr><td style="padding:0;height:0;font-size:0;line-height:0;">`
        + `<style data-rt-native-layout-restore="v1">${restoreCss(ids)}</style>`
        + (ids.logo ? `<style data-rt-native-layout-responsive="v1">${responsiveRestoreOptionalCss(ids)}</style>` : '')
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

function approvedResponsiveSubset(css, ids, historical = false) {
    const text = normalizedRestoreCss(css, ids);
    const expected = historical === 'hybrid-r61' ? legacyHybridResponsiveRules(ids) : responsiveRestoreRules(ids, historical === true, historical);
    const rules = value => {
        const result = [];
        let remainder = value;
        while (remainder) {
            const match = /^#[a-z0-9-]+\{[^{}]*\}/i.exec(remainder);
            if (!match) return null;
            result.push(match[0]);
            remainder = remainder.slice(match[0].length);
        }
        return result;
    };
    const subsequence = (actual, approved, allowFilteredCalc = false) => {
        if (!actual) return false;
        let next = 0;
        for (const rule of actual) {
            const index = approved.findIndex((value, index) => index >= next && (value === rule || (allowFilteredCalc
                && (value.replace('width:calc(680000px-100000%)!important;', '') === rule
                    || (ids.trainImage && value.startsWith('#' + ids.trainImage + '{')
                        && [value.replace('transform:scale(1.733333)!important;', ''), '#' + ids.trainImage + '{}'].includes(rule))))));
            if (index === -1) return false;
            next = index + 1;
        }
        return true;
    };
    const mediaStart = text.indexOf('@');
    let base = text, media = '';
    if (mediaStart !== -1) {
        const prefix = '@media(max-width:680px){';
        if (!text.startsWith(prefix, mediaStart) || !text.endsWith('}')) return false;
        base = text.slice(0, mediaStart);
        media = text.slice(mediaStart + prefix.length, -1);
    }
    return subsequence(rules(base), expected.base.map(rule => normalizedRestoreCss(rule, ids)), true)
        && subsequence(rules(media), expected.media.map(rule => normalizedRestoreCss(rule, ids)));
}

function standaloneSignatureFrame(contract, nodes) {
    const frame = contract.signature;
    const root = contract.root?.node || contract.root;
    const rootIndex = contract.root?.node ? contract.root.index : contract.rootIndex;
    if (!Number.isInteger(frame?.index) || nodes[frame.index] !== frame.node
        || frame.node.tag !== 'table' || root !== frame.node || rootIndex !== frame.index) invalid();
    return frame;
}

function inlineCellProperties(style) {
    const parts = [];
    let start = 0, quote = null, depth = 0;
    for (let offset = 0; offset <= style.length; offset += 1) {
        const char = style[offset] || ';';
        if (quote) {
            if (char === '\\') offset += 1;
            else if (char === quote) quote = null;
        } else if (char === '"' || char === "'") quote = char;
        else if (char === '/' && style[offset + 1] === '*') invalid();
        else if (char === '(') depth += 1;
        else if (char === ')') { depth -= 1; if (depth < 0) invalid(); }
        else if (char === ';' && depth === 0) { parts.push(style.slice(start, offset)); start = offset + 1; }
    }
    if (quote || depth !== 0) invalid();
    return parts.filter(part => part.trim()).map(part => {
        const match = /^\s*([a-z_-][a-z0-9_-]*)\s*:\s*(.*?)\s*$/is.exec(part);
        if (!match) invalid();
        return [match[1].toLowerCase(), match[2].replace(/\s*!important\s*$/i, '').trim().toLowerCase()];
    });
}

/** Parent TABLE recovery cannot defeat an explicit opposite direct TD value. */
export function nativeLayoutRestoreCellsAligned(contract, ids) {
    idsScope(ids);
    if (!ids.logo) return true;
    const nodes = contract?.elements;
    if (!Array.isArray(nodes) || !Number.isInteger(contract.signature?.index)) invalid();
    const cells = [];
    for (const role of ['root', 'logo', 'person', 'company', 'train']) {
        const matches = nodes.map((node, index) => ({ node, index })).filter(value => [ids[role], 'x_' + ids[role]].includes(value.node.attrs.id));
        if (matches.length !== 1 || matches[0].node.tag !== 'table' || isQuoted(nodes, matches[0].index)
            || !within(nodes, matches[0].index, contract.signature.index)) invalid();
        if (role === 'person' && (matches[0].node.attrs.align || '').trim().toLowerCase() !== 'left') invalid();
        if (role === 'person' && inlineCellProperties(matches[0].node.attrs.style || '').some(([name]) => name === 'text-align')) invalid();
        const body = onlyChild(nodes, matches[0].index, 'tbody');
        const rows = children(nodes, body.index);
        if (rows.length !== (role === 'root' ? 2 : 1) || rows.some(row => row.node.tag !== 'tr')) invalid();
        cells.push(...rows.map(row => onlyChild(nodes, row.index, 'td')));
    }
    for (const cell of cells) {
        const align = (cell.node.attrs.align || '').trim().toLowerCase();
        const direction = (cell.node.attrs.dir || '').trim().toLowerCase();
        if ((align && !['left', 'start'].includes(align)) || (direction && direction !== 'ltr')) invalid();
        for (const [name, value] of inlineCellProperties(cell.node.attrs.style || '')) {
            if ((name === 'text-align' && !['left', 'start', 'inherit'].includes(value))
                || (name === 'direction' && !['ltr', 'inherit'].includes(value))) invalid();
        }
    }
    return true;
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

function retainedCarrier(html, contract, ids, legacyColumns = false) {
    const scope = idsScope(ids);
    const nodes = contract?.elements;
    if (typeof html !== 'string' || !Array.isArray(nodes) || !contract.root || !contract.signature) invalid();
    const standalone = contract.kind === 'standalone-signature-v1';
    const frame = standalone ? standaloneSignatureFrame(contract, nodes) : null;
    const actualIds = {};
    const targets = {};
    for (const key of Object.keys(ids)) {
        const owner = key === 'templateGutter' ? (standalone ? null : contract.template?.index) : contract.signature.index;
        const matches = nodes.map((node, index) => ({ node, index })).filter(value => typeof value.node.attrs.id === 'string' && value.node.attrs.id.endsWith(ids[key]));
        const tag = key === 'window' ? 'div' : key === 'trainImage' ? 'img' : 'table';
        if (matches.length !== 1 || matches[0].node.tag !== tag
            || ![ids[key], 'x_' + ids[key]].includes(matches[0].node.attrs.id)
            || !Number.isInteger(owner) || !within(nodes, matches[0].index, owner) || isQuoted(nodes, matches[0].index)) invalid();
        actualIds[key] = matches[0].node.attrs.id;
        targets[key] = matches[0];
    }
    if (ids.window) {
        const trainCell = onlyChild(nodes, onlyChild(nodes, onlyChild(nodes, targets.train.index, 'tbody').index, 'tr').index, 'td');
        let imageParent = targets.window.index;
        const windowChildren = children(nodes, imageParent);
        if (windowChildren.length === 1 && windowChildren[0].node.tag === 'div') {
            if (Object.keys(windowChildren[0].node.attrs).some(name => name !== 'style')) invalid();
            imageParent = windowChildren[0].index;
        }
        if (onlyChild(nodes, trainCell.index, 'div').index !== targets.window.index
            || onlyChild(nodes, imageParent, 'img').index !== targets.trainImage.index) invalid();
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
    if (styles.length < 1 || styles.length > (ids.logo ? 2 : 1) || spans.length !== 1) invalid();
    if (legacyColumns && (!ids.logo || ids.trainImage?.endsWith('-k'))) invalid();
    const css = normalizedRestoreCss(legacyColumns === 'rows-r65' ? legacyIntrinsicRowsCoreCss(ids) : legacyColumns === 'rows-r64' ? legacyRowsCoreCss(ids) : legacyColumns === 'mixed-r63' ? legacyMixedCoreCss(ids) : legacyColumns === 'hybrid-r61' ? legacyHybridCoreCss(ids) : legacyColumns ? legacyColumnCoreCss(ids) : nativeLayoutRestoreCss(ids), ids);
    const core = styles.filter(value => normalizedRestoreCss(html.slice(value.node.openEnd, value.node.close), ids) === css);
    if (core.length !== 1) invalid();
    const retainedCss = html.slice(core[0].node.openEnd, core[0].node.close);
    for (const key of Object.keys(ids)) {
        const selectors = retainedCss.match(new RegExp('#(?:x_)?' + ids[key] + '(?![\\w-])', 'g')) || [];
        if (!selectors.length || selectors.some(selector => selector !== '#' + actualIds[key])) invalid();
    }
    const exactSheets = nodes.filter(node => node.tag === 'style' && normalizedRestoreCss(html.slice(node.openEnd, node.close), ids) === css);
    if (exactSheets.length !== 1 || normalizedRestoreCss(retainedCss, ids) !== css) invalid();
    for (const optional of styles.filter(value => value !== core[0])) {
        const optionalCss = html.slice(optional.node.openEnd, optional.node.close);
        if (!ids.logo || !approvedResponsiveSubset(optionalCss, ids, legacyColumns)) invalid();
        for (const key of Object.keys(ids)) {
            const selectors = optionalCss.match(new RegExp('#(?:x_)?' + ids[key] + '(?![\\w-])', 'g')) || [];
            if (selectors.some(selector => selector !== '#' + actualIds[key])) invalid();
        }
    }
    const metadata = spans[0].node;
    const hidden = owns(metadata.attrs, 'hidden') || metadata.attrs['aria-hidden'] === 'true'
        || /(?:^|;)\s*display\s*:\s*none(?:\s*!important)?\s*(?:;|$)/i.test(metadata.attrs.style || '')
        || (/(?:^|;)\s*font-size\s*:\s*0(?:px|pt)?(?:\s*!important)?\s*(?:;|$)/i.test(metadata.attrs.style || '')
            && /(?:^|;)\s*line-height\s*:\s*0(?:px|pt)?(?:\s*!important)?\s*(?:;|$)/i.test(metadata.attrs.style || ''));
    if (!hidden) invalid();
    const ranges = [...styles.map(value => value.node), metadata].sort((left, right) => right.start - left.start);
    let remaining = html.slice(cell.node.openEnd, cell.node.close);
    for (const node of ranges) remaining = remaining.slice(0, node.start - cell.node.openEnd) + remaining.slice(node.end - cell.node.openEnd);
    if (remaining.includes('<!--') || remaining.replace(/<[^>]*>/g, '').replace(/&nbsp;|&#160;|&#xa0;/gi, ' ').trim() !== '') invalid();
    return carrier.node;
}

/** A success callback is insufficient: a fresh SDK read must retain this exact owned CSS carrier. */
export function nativeLayoutRestoreRetained(html, contract, ids) {
    if (ids?.logo && !ids.window) invalid();
    retainedCarrier(html, contract, ids);
    nativeLayoutRestoreCellsAligned(contract, ids);
    return true;
}

/** Legacy descendant CSS must not survive outside a native-slot replacement. */
export function nativeLayoutRestoreCarrierIsExternal(html, contract, ids) {
    const carrier = retainedCarrier(html, contract, ids);
    const signature = contract.signature.node;
    return carrier.end <= signature.start || carrier.start >= signature.end;
}

export function removeNativeLayoutRestoreCarrier(html, contract, ids) {
    try {
        const carrier = retainedCarrier(html, contract, ids);
        return html.slice(0, carrier.start) + html.slice(carrier.end);
    } catch {
        if (ids?.logo) {
            for (const historical of [true, 'hybrid-r61', 'mixed-r63', 'rows-r64', 'rows-r65']) {
                try {
                    const carrier = retainedCarrier(html, contract, ids, historical);
                    return html.slice(0, carrier.start) + html.slice(carrier.end);
                } catch { /* Unknown/incomplete historical content stays opaque. */ }
            }
        }
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
        || !['outlook-desktop-flow-v4', 'outlook-desktop-flow-v8'].includes(candidates[0].node.attrs['data-rt-desktop-flow'])) invalid();
    const root = candidates[0];
    const responsive = root.node.attrs['data-rt-desktop-flow'] === 'outlook-desktop-flow-v8';
    if (responsive) {
        const ancestors = [];
        for (let index = root.index, depth = 0; Number.isInteger(index); index = nodes[index].parent, depth += 1) {
            if (!nodes[index] || depth > nodes.length) invalid();
            ancestors.push(nodes[index]);
        }
        const hosts = ancestors.filter(node => has(node, 'rt-dflow-host-v4'));
        if (hosts.length > 2 || (hosts.length === 2 && (hosts[0] !== signature.node || hosts[0].tag !== 'table'
            || hosts[1].tag !== 'div' || !has(hosts[1], 'rt-outlook-signature')
            || hosts[0].parent !== nodes.indexOf(hosts[1])))) invalid();
        let expectedHost = null;
        for (const host of hosts) {
            const classes = host.attrs.class.split(/\s+/);
            const scopes = classes.filter(name => /^rts[0-9a-f]{10}$/.test(name));
            const names = classes.filter(name => /^rt-df[0-9][0-9a-f]{10}$/.test(name));
            if (scopes.length !== 1 || names.length !== 1 || names[0] !== 'rt-df8' + scopes[0].slice(3)) invalid();
            if (expectedHost !== null && names[0] !== expectedHost) invalid();
            expectedHost = names[0];
        }
        if (ancestors.some(node => (node.attrs.class || '').split(/\s+/).some(name => /^rt-df[467][0-9a-f]{10}$/.test(name)))) invalid();
    }
    const body = onlyChild(nodes, root.index, 'tbody');
    const rows = children(nodes, body.index);
    if (rows.length !== (responsive ? 2 : 1) || rows.some(row => row.node.tag !== 'tr')) invalid();
    const cells = rows.map(row => onlyChild(nodes, row.index, 'td'));
    const person = one(nodes, root.index, 'rt-personal-left', 'table');
    const company = one(nodes, root.index, 'rt-personal-right', 'table');
    const train = one(nodes, root.index, 'rt-df-train', 'table');
    const logo = responsive ? one(nodes, root.index, 'rt-personal-logo-column', 'table') : null;
    const groups = children(nodes, cells[0].index);
    const order = [...(logo ? [logo.index] : []), person.index, company.index, ...(responsive ? [] : [train.index])];
    if (groups.length !== order.length || groups.some((value, index) => value.index !== order[index])
        || (responsive && onlyChild(nodes, cells[1].index, 'table').index !== train.index)) invalid();
    if (responsive && (person.node.attrs.align !== 'left' || person.node.attrs.height !== '50')) invalid();
    if (responsive && inlineCellProperties(person.node.attrs.style || '').some(([name]) => name === 'text-align')) invalid();
    if (logo) {
        const logoCell = onlyChild(nodes, onlyChild(nodes, onlyChild(nodes, logo.index, 'tbody').index, 'tr').index, 'td');
        const frame = onlyChild(nodes, logoCell.index, 'table');
        if (!has(frame.node, 'rt-personal-logo-frame')) invalid();
    }
    const trainBody = onlyChild(nodes, train.index, 'tbody');
    const trainRow = onlyChild(nodes, trainBody.index, 'tr');
    const trainCell = onlyChild(nodes, trainRow.index, 'td');
    const window = onlyChild(nodes, trainCell.index, 'div');
    if (!has(window.node, 'rt-train-window')) invalid();
    const image = onlyChild(nodes, window.index, 'img');
    const enlargedTrain = has(train.node, 'rt-train-enlarged-v1');
    if ((enlargedTrain && !responsive) || !has(image.node, 'rt-delivery-train')
        || image.node.attrs.width !== (enlargedTrain ? '324' : '300')
        || image.node.attrs.height !== (enlargedTrain ? '70' : responsive ? '65' : '38')) invalid();

    const targets = { root, person, company, train };
    if (logo) targets.logo = logo;
    if (responsive) { targets.window = window; targets.trainImage = image; }
    if (responsive && contract.kind !== 'standalone-signature-v1' && Number.isInteger(contract.template?.index)) {
        const shells = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, contract.template.index)
            && has(value.node, 'rt-shell') && has(value.node, 'design-v27'));
        if (shells.length > 1 || (shells.length && shells[0].node.tag !== 'table')) invalid();
        if (shells.length) targets.templateGutter = shells[0];
    }
    const hotline = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, signature.index)
        && has(value.node, 'design-hotline-row'));
    if (hotline.length > 1) invalid();
    if (hotline.length) {
        if (hotline[0].node.tag !== 'td') invalid();
        let index = hotline[0].node.parent;
        for (let depth = 0; Number.isInteger(index) && nodes[index].tag !== 'table' && depth <= nodes.length; depth += 1) index = nodes[index].parent;
        if (!Number.isInteger(index) || nodes[index].tag !== 'table'
            || !within(nodes, index, signature.index) || within(nodes, index, root.index)) invalid();
        let header = { node: nodes[index], index };
        const headerBody = onlyChild(nodes, index, 'tbody');
        const headerRows = children(nodes, headerBody.index);
        if (!headerRows.length || headerRows.some(value => value.node.tag !== 'tr')
            || !within(nodes, hotline[0].index, headerRows[0].index)) invalid();
        if (responsive && hotline[0].node.attrs['data-rt-brand-hotline'] === 'v1'
            && has(hotline[0].node, 'rt-brand-hotline-cell')) {
            // The owned five-cell raster must stay automatic after Office
            // removes TABLE inline styles and its old fixed mirror survives.
            const raster = onlyChild(nodes, hotline[0].index, 'table');
            const rasterRow = onlyChild(nodes, onlyChild(nodes, raster.index, 'tbody').index, 'tr');
            const rasterCells = children(nodes, rasterRow.index);
            if (rasterCells.length !== 5 || rasterCells.some(value => value.node.tag !== 'td')) invalid();
            const gutter = onlyChild(nodes, rasterCells[0].index, 'table');
            if (!has(gutter.node, 'rt-brand-hotline-gutter')) invalid();
            header = raster;
        }
        targets.header = header;
        if (responsive) {
            const insets = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, header.index)
                && has(value.node, 'rt-brand-hotline-gutter'));
            if (insets.length > 1) invalid();
            if (insets.length) {
                const inset = insets[0];
                if (inset.node.tag !== 'table' || !within(nodes, inset.index, hotline[0].index)) invalid();
                const insetCell = onlyChild(nodes, onlyChild(nodes, onlyChild(nodes, inset.index, 'tbody').index, 'tr').index, 'td');
                const image = onlyChild(nodes, insetCell.index, 'img');
                if (image.node.attrs.width !== '100%' || image.node.attrs.height !== '34') invalid();
                targets.inset = inset;
            }
        }
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
            if (responsive) {
                const footers = nodes.map((node, index) => ({ node, index })).filter(value => within(nodes, value.index, footer.index)
                    && has(value.node, 'rt-brand-legal-table'));
                if (footers.length > 1 || (footers.length && footers[0].node.tag !== 'table')) invalid();
                if (footers.length) {
                    const legal = footers[0];
                    onlyChild(nodes, onlyChild(nodes, onlyChild(nodes, legal.index, 'tbody').index, 'tr').index, 'td');
                    if (/RT-SIGNATURE-MANAGED-V1|RT-SIGNATURE-VERSION:/.test(html.slice(legal.node.openEnd, legal.node.close))) invalid();
                    targets.footer = legal;
                }
            }
        }
    }

    const scope = nonce === undefined ? createNativeLayoutNonce() : nonce;
    if (typeof scope !== 'string' || !/^[0-9a-f]{32}$/.test(scope) || html.includes(PREFIX + scope)) invalid();
    const ids = {};
    const edits = [];
    const suffixes = { root: 'r', person: 'p', company: 'c', train: 't', header: 'h', signature: 's', logo: 'l', inset: 'i', footer: 'f', templateGutter: 'q', window: 'w', trainImage: enlargedTrain ? 'k' : 'j' };
    for (const key of Object.keys(targets)) {
        const { node } = targets[key];
        if (key === 'templateGutter') {
            const owner = contract.template.node;
            if (node.start < owner.start || node.end > owner.end) invalid();
        } else if (key !== 'signature' && (node.start < signature.node.openEnd || node.end > signature.node.close)) invalid();
        const opening = targetOpening(html, node);
        ids[key] = PREFIX + scope + '-' + suffixes[key];
        edits.push({ start: node.start, end: node.openEnd, value: opening.slice(0, -1) + ` id="${ids[key]}">` });
    }
    edits.sort((left, right) => right.start - left.start);
    let modified = html;
    for (const edit of edits) modified = modified.slice(0, edit.start) + edit.value + modified.slice(edit.end);
    const frozenIds = Object.freeze(ids);
    return Object.freeze({ html: modified, ids: frozenIds, stylePayload: nativeLayoutRestoreStylePayload(frozenIds) });
}
