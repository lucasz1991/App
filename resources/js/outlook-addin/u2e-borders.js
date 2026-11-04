// Read-only, finite evidence for U2e's four grid borders and borderless CTA cells. Neither this
// parser nor its caller repairs HTML or substitutes a host-dependent color.
const fail = checkpoint => { throw Object.assign(new Error('SIGNATURE_LAYOUT_UNCONFIRMED'), { code: 'SIGNATURE_LAYOUT_UNCONFIRMED', checkpoint }); };
const styles = new Set(['none', 'hidden', 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset']);
const colors = Object.freeze({ white: '#ffffff', black: '#000000', red: '#ff0000', blue: '#0000ff', green: '#008000', gray: '#808080', grey: '#808080' });
export const U2E_TOP_BORDER_PROPERTIES = Object.freeze(['border', 'border-top', 'border-width', 'border-style', 'border-color',
    'border-top-width', 'border-top-style', 'border-top-color', 'mso-border-top-alt']);
export const U2E_BORDER_IMAGE_PROPERTIES = Object.freeze(['border-image', 'border-image-source', 'border-image-slice',
    'border-image-width', 'border-image-outset', 'border-image-repeat', '-webkit-border-image']);
export const U2E_AMBIGUOUS_TOP_BORDER_PROPERTIES = Object.freeze(['all',
    ...['block', 'inline'].flatMap(axis => ['', '-start', '-end'].flatMap(edge => ['', '-width', '-style', '-color'].map(component => 'border-' + axis + edge + component))),
    ...['before', 'after', 'start', 'end'].flatMap(edge => ['', '-width', '-style', '-color'].map(component => '-webkit-border-' + edge + component)),
    'mso-border-alt', 'mso-border-top', 'mso-border-top-color', 'mso-border-top-style', 'mso-border-top-width',
    'mso-border-top-color-alt', 'mso-border-top-style-alt', 'mso-border-top-width-alt']);
const topProperties = new Set(U2E_TOP_BORDER_PROPERTIES), imageProperties = new Set(U2E_BORDER_IMAGE_PROPERTIES), ambiguousProperties = new Set(U2E_AMBIGUOUS_TOP_BORDER_PROPERTIES);

function width(value) {
    if (value === '0') return 0;
    const match = /^(\d+(?:\.\d+)?|\.\d+)(px|pt)$/.exec(value);
    if (!match) return null;
    const number = Number(match[1]) * (match[2] === 'pt' ? 4 / 3 : 1);
    return Number.isFinite(number) && number <= 1000000 ? number : null;
}
function color(value) {
    if (Object.hasOwn(colors, value)) return colors[value];
    if (/^#[0-9a-f]{6}$/.test(value)) return value;
    if (/^#[0-9a-f]{3}$/.test(value)) return '#' + [...value.slice(1)].map(c => c + c).join('');
    const match = /^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/.exec(value);
    return match && match.slice(1).every(number => Number(number) <= 255)
        ? '#' + match.slice(1).map(number => Number(number).toString(16).padStart(2, '0')).join('') : null;
}
function tokens(value) {
    const result = value.match(/rgb\([^()]*\)|[^\s]+/g) || [];
    if (result.join(' ').replace(/\s+/g, '') !== value.replace(/\s+/g, '')) fail('borders.invalid');
    return result;
}
function shorthand(value) {
    const result = { width: null, style: 'none', color: null }, found = new Set();
    const values = tokens(value);
    if (!values.length || values.length > 3) fail('borders.invalid');
    for (const token of values) {
        const number = width(token), hex = color(token);
        const key = number !== null ? 'width' : styles.has(token) ? 'style' : hex !== null ? 'color' : null;
        if (key === null || found.has(key)) fail('borders.invalid');
        found.add(key); result[key] = key === 'width' ? number : key === 'color' ? hex : token;
    }
    return result;
}
function component(value, kind, multiple) {
    const values = tokens(value);
    if (values.length < 1 || values.length > (multiple ? 4 : 1)) fail('borders.invalid');
    const parsed = values.map(token => kind === 'width' ? width(token) : kind === 'color' ? color(token) : styles.has(token) ? token : null);
    if (parsed.some(value => value === null)) fail('borders.invalid');
    return parsed[0];
}
function visible(result) {
    if (!Number.isFinite(result.width) || result.width <= 0 || result.style !== 'solid' || !/^#[0-9a-f]{6}$/.test(result.color || '')) fail('borders.invalid');
    return { width: result.width, style: result.style, color: result.color };
}

/** CSS cascade per top-border component, retaining px/pt's actual dimensions. */
function readTopBorder(node, absent = false) {
    const style = node?.attrs?.style === undefined && absent ? '' : node?.attrs?.style;
    if (typeof style !== 'string' || /\/\*|\*\/|[{}\\]/.test(style)) fail('borders.invalid');
    const result = { width: null, style: null, color: null }, priority = { width: -1, style: -1, color: -1 };
    let alternate = null, alternatePriority = -1;
    const assign = (values, important) => {
        for (const [key, value] of Object.entries(values)) if (important >= priority[key]) {
            result[key] = value; priority[key] = important;
        }
    };
    for (const declaration of style.split(';')) {
        if (!declaration.trim()) continue;
        const colon = declaration.indexOf(':');
        if (colon < 0) fail('borders.invalid');
        const property = declaration.slice(0, colon).trim().toLowerCase();
        let value = declaration.slice(colon + 1).trim().toLowerCase();
        const important = /\s*!important$/.test(value) ? 1 : 0;
        value = value.replace(/\s*!important$/, '').trim();
        if (imageProperties.has(property)) {
            if (value !== 'none') fail('borders.image');
            continue;
        }
        if (!topProperties.has(property)) {
            // Logical edges and other Word alternatives can affect the physical
            // top edge depending on external writing-mode or host semantics.
            if (ambiguousProperties.has(property)) fail('borders.invalid');
            continue;
        }
        if (!value || /[!]|(?:url|var|calc|env)\s*\(|(?:^|\s)(?:inherit|initial|unset|revert|revert-layer|currentcolor|transparent)(?:\s|$)/.test(value)) fail('borders.invalid');
        if (property === 'mso-border-top-alt') {
            const parsed = absent ? shorthand(value) : visible(shorthand(value));
            if (important >= alternatePriority) { alternate = parsed; alternatePriority = important; }
        } else if (property === 'border' || property === 'border-top') assign(shorthand(value), important);
        else {
            const kind = property.split('-').pop();
            assign({ [kind]: component(value, kind, !property.startsWith('border-top-')) }, important);
        }
    }
    if (absent) {
        const invisible = value => value.width === 0 || value.style === 'none' || value.style === 'hidden' || value.style === null;
        if (!invisible(result) || alternate && !invisible(alternate)) fail('borders.cta');
        return null;
    }
    const output = visible(result);
    if (alternate && alternate.color !== output.color) fail('borders.invalid');
    return output;
}

export const u2eTopBorder = node => readTopBorder(node);

const nearestTable = (elements, index) => {
    for (let at = elements[index]?.parent, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) {
        if (elements[at]?.tag === 'table') return at;
    }
    return null;
};
const descendants = (elements, index, root) => {
    for (let at = index, count = 0; Number.isInteger(at) && count <= elements.length; count++, at = elements[at]?.parent) if (at === root) return true;
    return false;
};
const rows = (elements, table) => elements.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'tr' && nearestTable(elements, value.index) === table);
const cells = (elements, row) => elements.map((node, index) => ({ node, index })).filter(value => value.node.parent === row && ['td', 'th'].includes(value.node.tag));

/** Structural line and action cells; host classes/IDs are not required. */
export function u2eCtaMaterial(elements, grid, dispatch) {
    if (!Array.isArray(elements) || !Array.isArray(grid) || grid.length !== 4 || !Array.isArray(dispatch) || dispatch.length !== 1
        || grid.some(value => value?.node?.tag !== 'td' || elements[value.index] !== value.node)
        || dispatch[0]?.node?.tag !== 'table' || elements[dispatch[0].index] !== dispatch[0].node) fail('borders.structure');
    const dispatchRows = rows(elements, dispatch[0].index);
    if (dispatchRows.length !== 4) fail('borders.structure');
    const actionContainer = cells(elements, dispatchRows[2].index);
    if (actionContainer.length !== 1 || actionContainer[0].node.tag !== 'td' || actionContainer[0].node.attrs.colspan !== '3') fail('borders.structure');
    const actionTables = elements.map((node, index) => ({ node, index })).filter(value => value.node.tag === 'table'
        && descendants(elements, value.index, actionContainer[0].index));
    if (actionTables.length !== 1) fail('borders.structure');
    const actionRows = rows(elements, actionTables[0].index);
    if (actionRows.length !== 2) fail('borders.structure');
    const lineCells = cells(elements, actionRows[0].index);
    if (lineCells.length !== 1 || lineCells[0].node.tag !== 'td' || lineCells[0].node.attrs.colspan !== '5'
        || ![undefined, '1'].includes(lineCells[0].node.attrs.rowspan)) fail('borders.structure');
    const actionCells = cells(elements, actionRows[1].index);
    if (actionCells.length !== 5 || actionCells.some(value => value.node.tag !== 'td')) fail('borders.structure');
    return { line: lineCells[0], lineRow: actionRows[0], actionCells };
}

/** Only the four grid colors remain evidence; CTA top borders must be absent. */
export function u2eTopBorderEvidence(elements, grid, dispatch) {
    const { actionCells } = u2eCtaMaterial(elements, grid, dispatch);
    actionCells.forEach(value => readTopBorder(value.node, true));
    return grid.map(value => u2eTopBorder(value.node));
}
