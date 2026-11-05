import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture, universalDiagnosticReplayHtml } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const source = JSON.parse(fs.readFileSync(new URL('source.json', folder), 'utf8'));
const signature = JSON.parse(fs.readFileSync(new URL('signature.json', folder), 'utf8'));
const previous = JSON.parse(fs.readFileSync(new URL('legacy-c15/source.json', folder), 'utf8'));
const previousManifest = JSON.parse(fs.readFileSync(new URL('legacy-c15/manifest.json', folder), 'utf8'));
const newFiles = ['c01c48d975.png', '8a5901e027.png', '033a29c0b7.png', '097c3bb121.png'];
const oldFiles = ['8a9ee1e161.png', '4b1a2aaba8.png', 'b4b1fbea07.png', 'f1b083c20b.png'];
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const current = html => universalComposeStateFromBody(source, html, 'newMail');
const rows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
function corners(document) {
    const panelRows = rows(document.querySelector('table[width="247"]'));
    return [panelRows[0], panelRows[3]].flatMap(row => [...row.children].filter((_node, index) => index === 0 || index === 2));
}
function change(callback) {
    const { document } = parseHTML(source.html);
    callback(corners(document), document);
    return document.toString();
}
function setBackground(node, value) {
    node.setAttribute('bgcolor', value);
    node.setAttribute('style', (node.getAttribute('style') || '').split(';')
        .filter(declaration => !/^\s*background(?:-color)?\s*:/i.test(declaration)).join(';') + ';background-color:' + value);
}

test('C21 current B1 and both synthetic hosts retain four red corner cells and the white CTA line', () => {
    assert.notEqual(source.version, previous.version);
    assert.equal(previous.version, '494c11d6ceab48d58f14ecbd');
    for (const name of ['source.json', 'rooster.synthetic.html', 'word.synthetic.html']) {
        const input = fs.readFileSync(new URL(name, folder), 'utf8');
        const html = name.endsWith('.json') ? JSON.parse(input).html : input.trim();
        assert.equal(current(html).current, true, name);
        assert.equal(universalComposeStateFromBody(signature, html, 'newMail').current, true, name);
        const { document } = parseHTML(html), cells = corners(document);
        assert.equal(cells.length, 4);
        assert.deepEqual(cells.map(cell => cell.querySelector('img').getAttribute('src').split('/').at(-1)), newFiles);
        for (const cell of cells) {
            assert.match(cell.getAttribute('style'), /background-color:(?:#e4002b|rgb\(228, 0, 43\))/);
            assert.equal(cell.querySelector('img').getAttribute('width'), '8');
            assert.equal(cell.querySelector('img').getAttribute('height'), '8');
        }
    }
    validateUniversalComposeDocument(source); validateUniversalComposeDocument(signature);
    const { document } = parseHTML(source.html);
    assert.equal(document.querySelector('.rt-cta-line td').getAttribute('bgcolor'), '#ffffff');
    for (const cell of document.querySelectorAll('.rt-cta-cell')) assert.doesNotMatch(cell.getAttribute('style'), /border-top/);
});

test('C21 preserves the previous C15 source, signature, host serializations and manifest exactly', () => {
    for (const name of Object.keys(previousManifest.artifacts)) {
        const input = fs.readFileSync(new URL('legacy-c15/' + name, folder), 'utf8').replaceAll('\r\n', '\n');
        assert.equal(hash(input), previousManifest.artifacts[name].sha256);
    }
    assert.equal(hash(fs.readFileSync(new URL('legacy-c15/manifest.json', folder), 'utf8').replaceAll('\r\n', '\n')),
        'f6856afb14cd1c7d0c17f0addc24ae46d848920a517f8f1fdaa9c28bca02465d');
});

test('each white or changed current-version corner is rejected rather than normalized', () => {
    for (let index = 0; index < 4; index++) {
        for (const color of ['#ffffff', '#000000', '#e4002c']) {
            const html = change(cells => setBackground(cells[index], color));
            assert.equal(current(html).current, false);
            assert.equal(current(html).checkpoint, 'colors.corners');
            assert.throws(() => validateUniversalComposeDocument({ ...source, html }), { code: 'U2E_SOURCE_INVALID' });
        }
    }
});

test('Word may omit a matching red corner color only through the existing nearest-table rule', () => {
    const html = change(cells => {
        for (const cell of cells) {
            cell.removeAttribute('bgcolor');
            cell.setAttribute('style', cell.getAttribute('style').split(';')
                .filter(declaration => !/^\s*background(?:-color)?\s*:/i.test(declaration)).join(';'));
        }
    });
    assert.equal(current(html).current, true);
});

test('old corner assets, reordered new corners and intrinsic-size attributes never pass as the current source', () => {
    for (let index = 0; index < 4; index++) {
        const old = change(cells => cells[index].querySelector('img').setAttribute('src', 'https://mail.example.test/m/' + oldFiles[index]));
        assert.equal(current(old).checkpoint, 'images.src');
        assert.throws(() => validateUniversalComposeDocument({ ...source, html: old }), { code: 'U2E_SOURCE_INVALID' });
    }
    const swapped = change(cells => cells[0].querySelector('img').setAttribute('src', 'https://mail.example.test/m/' + newFiles[1]));
    assert.equal(current(swapped).checkpoint, 'images.order');
    for (const dimension of ['width', 'height']) {
        const oversized = change(cells => cells[0].querySelector('img').setAttribute(dimension, '24'));
        assert.equal(current(oversized).checkpoint, 'images.' + dimension);
    }
});

test('C15 own material stays present-not-current and its quoted bytes are never rewritten', () => {
    assert.throws(() => validateUniversalComposeDocument(previous), { code: 'U2E_SOURCE_INVALID' });
    for (const name of ['source.json', 'rooster.synthetic.html', 'word.synthetic.html']) {
        const input = fs.readFileSync(new URL('legacy-c15/' + name, folder), 'utf8');
        const html = name.endsWith('.json') ? JSON.parse(input).html : input.trim();
        const state = current(html);
        assert.equal(state.present, true); assert.equal(state.current, false);
        assert.equal(state.version, previous.version); assert.equal(state.checkpoint, 'state.version');
    }
    const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + previous.html + '</blockquote>';
    const only = '<html><body><p><br></p>' + quote + '</body></html>';
    assert.equal(universalComposeStateFromBody(source, only, 'reply').present, false);
    const input = '<html><body>' + source.html + quote + '</body></html>', before = hash(input);
    assert.equal(universalComposeStateFromBody(source, input, 'reply').current, true);
    assert.equal(hash(input), before); assert.ok(input.includes(quote));
});

test('redaction retains new and historical corner filenames without granting historical source admission', () => {
    for (const document of [source, previous]) {
        const fixture = universalRedactedReadbackFixture(document.html, document.html);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        for (const file of document === source ? newFiles : oldFiles) assert.ok(fixture.html.includes('/m/' + file));
        assert.doesNotMatch(JSON.stringify(fixture), /Fixture author|direct@example|https:\/\/mail\.example/);
        const actual = universalDiagnosticReplayHtml(fixture.html);
        const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
        if (document === source) assert.equal(universalComposeStateFromBody(expected, actual, 'newMail').current, true);
        else assert.throws(() => validateUniversalComposeDocument({ ...previous, kind: 'signature', html: actual }), { code: 'U2E_SOURCE_INVALID' });
    }
});
