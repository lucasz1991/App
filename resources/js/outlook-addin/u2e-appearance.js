// Read-only interpretation of the finite table-centering serializations used
// by the U2e source and Word. This does not identify or claim a signature.
const properties = (node, name) => (node?.attrs?.style || '').split(';').flatMap(declaration => {
    const colon = declaration.indexOf(':');
    return colon >= 0 && declaration.slice(0, colon).trim().toLowerCase() === name
        ? [declaration.slice(colon + 1).trim().toLowerCase().replace(/\s*!important$/, '')] : [];
});

export function u2eCenteringState(node) {
    const align = node?.attrs?.align?.trim().toLowerCase();
    if (align && align !== 'center' || properties(node, 'float').some(value => value !== 'none')) return 'conflict';
    let left = null, right = null;
    for (const declaration of (node?.attrs?.style || '').split(';')) {
        const colon = declaration.indexOf(':');
        if (colon < 0) continue;
        const key = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().toLowerCase().replace(/\s*!important$/, '');
        if (key === 'margin') {
            const parts = value.split(/\s+/);
            if (parts.length < 1 || parts.length > 4) return 'conflict';
            right = parts[1] ?? parts[0]; left = parts[3] ?? parts[1] ?? parts[0];
        } else if (key === 'margin-left') left = value;
        else if (key === 'margin-right') right = value;
    }
    if (left === 'auto' && right === 'auto' || align === 'center' && left === null && right === null) return 'center';
    return align || left !== null || right !== null ? 'conflict' : 'unspecified';
}

const blocksInheritedCenter = node => properties(node, 'text-align').some(value => value !== 'center')
    || properties(node, 'position').some(value => value !== 'static');

/** Only a DIV on the immediate ancestor path can supply Word's moved align.
 * A table/cell boundary or explicit local disagreement ends the search. */
export function u2eCenteringContext(elements, index) {
    const node = elements?.[index], state = u2eCenteringState(node);
    if (!node) return { centered: false, inherited: false };
    if (state === 'center') return { centered: true, inherited: false };
    if (state !== 'unspecified' || blocksInheritedCenter(node)) return { centered: false, inherited: false };
    for (let at = node.parent, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        const ancestor = elements[at];
        if (!ancestor || ['table', 'td', 'th'].includes(ancestor.tag)) break;
        const ancestorState = u2eCenteringState(ancestor);
        if (ancestorState === 'conflict' || blocksInheritedCenter(ancestor)) break;
        if (ancestor.tag === 'div' && ancestor.attrs.align?.trim().toLowerCase() === 'center') {
            return { centered: ancestorState === 'center', inherited: ancestorState === 'center' };
        }
    }
    return { centered: false, inherited: false };
}

const zero = value => /^0(?:\.0+)?(?:px|pt)?$/.test(value);
const leftMarginOverride = node => {
    let left = null, right = null;
    for (const declaration of (node?.attrs?.style || '').split(';')) {
        const colon = declaration.indexOf(':');
        if (colon < 0) continue;
        const key = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().toLowerCase().replace(/\s*!important$/, '');
        if (key === 'margin') {
            const parts = value.split(/\s+/);
            right = parts[1] ?? parts[0]; left = parts[3] ?? parts[1] ?? parts[0];
        } else if (key === 'margin-left') left = value;
        else if (key === 'margin-right') right = value;
    }
    return left !== null && zero(left) && right === 'auto';
};

/** A left shell never floats: quoted messages must start beneath it. Word can
 * omit the source align/margins when serializing its default left position. */
export function u2eLeftAlignmentState(node) {
    const align = node?.attrs?.align?.trim().toLowerCase();
    // TABLE align=left is a legacy text-wrapping instruction. Word may retain
    // it even when float:none is present; only non-table ancestors may use it.
    if (align && (align !== 'left' || node?.tag === 'table') || properties(node, 'float').some(value => value !== 'none')
        || properties(node, 'position').some(value => value !== 'static')) return 'conflict';
    let left = null, right = null;
    for (const declaration of (node?.attrs?.style || '').split(';')) {
        const colon = declaration.indexOf(':');
        if (colon < 0) continue;
        const key = declaration.slice(0, colon).trim().toLowerCase();
        const value = declaration.slice(colon + 1).trim().toLowerCase().replace(/\s*!important$/, '');
        if (key === 'margin') {
            const parts = value.split(/\s+/);
            if (parts.length < 1 || parts.length > 4) return 'conflict';
            right = parts[1] ?? parts[0]; left = parts[3] ?? parts[1] ?? parts[0];
        } else if (key === 'margin-left') left = value;
        else if (key === 'margin-right') right = value;
    }
    if (left !== null && !zero(left) || right !== null && !zero(right) && right !== 'auto') return 'conflict';
    return align === 'left' || left !== null || right !== null ? 'left' : 'unspecified';
}

export function u2eLeftAlignmentContext(elements, index) {
    const node = elements?.[index], state = u2eLeftAlignmentState(node);
    if (!node || state === 'conflict' || properties(node, 'text-align').some(value => !['left', 'start'].includes(value))) return false;
    // align=left plus float:none does not defeat a DIV align=center in WebKit.
    // Explicit zero-left/auto-right margins position the table; its own text
    // alignment separately prevents the host centering the table contents.
    if (leftMarginOverride(node) && properties(node, 'text-align').some(value => ['left', 'start'].includes(value))) return true;
    for (let at = node.parent, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        const ancestor = elements[at];
        if (!ancestor || ['table', 'td', 'th'].includes(ancestor.tag)) break;
        const inherited = u2eLeftAlignmentState(ancestor);
        if (inherited === 'conflict' || properties(ancestor, 'text-align').some(value => !['left', 'start'].includes(value))) return false;
        // A neutral wrapper's zero margins do not establish table alignment;
        // continue until Word's explicit alignment or the outer host boundary.
        if (ancestor.attrs.align?.trim().toLowerCase() === 'left'
            || properties(ancestor, 'text-align').some(value => ['left', 'start'].includes(value))) return true;
    }
    return true;
}
