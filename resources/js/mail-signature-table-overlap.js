/** V27 owns a same-row IMG carrier; the responsive CSS is supplied by PHP. */
export function assertTableOverlapSignature(wrapper, rows) {
    if (rows.length !== 2 || rows[0]?.getAttribute('data-rt-artifact-version') !== 'v27') {
        throw new Error('V27 benoetigt genau zwei Signaturzeilen.');
    }
    const one = (selector) => {
        const nodes = wrapper.querySelectorAll(selector);
        if (nodes.length !== 1) throw new Error(`V27 benoetigt genau einen ${selector}-Knoten.`);
        return nodes[0];
    };
    const carrier = one('td.rt-sign-cell');
    const stage = one('div.rt-sign-stage');
    const contentTable = one('table.rt-sign-content-frame');
    const content = one('td.rt-sign-content');
    const imageCell = one('td.rt-v27-image-cell');
    const anchor = one('table.rt-v27-anchor');
    const slot = one('td.rt-v27-image-slot');
    const image = one('img.rt-sign-train[data-rt-train]');
    if (carrier.parentElement !== rows[0] || stage.parentElement !== carrier
        || contentTable.parentElement !== stage || imageCell.parentElement !== content.parentElement
        || imageCell.nextElementSibling !== content || imageCell.parentElement.children.length !== 2
        || anchor.parentElement !== imageCell || image.parentElement !== slot
        || slot.getAttribute('dir') !== 'rtl' || imageCell.getAttribute('width') !== '1%'
        || image.getAttribute('src') !== '{{TRAIN_SRC}}' || image.hasAttribute('height')
        || wrapper.querySelector('.rt-sign-train-layer,[background],[data-rt-train-mso]')) {
        throw new Error('V27 muss ein proportionales IMG vor den Kontakten in derselben Tabellenzeile halten.');
    }
    return { carrier, stage, contentTable, content, image, imageCell, anchor, slot };
}
