import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture, universalDiagnosticReplayHtml } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const read = name => JSON.parse(fs.readFileSync(new URL(name, folder), 'utf8'));
const source = read('source.json'), signature = read('signature.json'), legacy = read('legacy-v23/source.json');
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function change(mutate) { const { document } = parseHTML(source.html); mutate(document); return document.toString(); }
const current = html => universalComposeStateFromBody(source, html, 'newMail');
const actionTable = document => document.querySelector('.rt-cta-cell').closest('table');
const oldLine = parseHTML(legacy.html).document.querySelector('.rt-cta-line').outerHTML;

test('versioned fixtures pin actual synthetic B1 output without native-client proof', () => {
    const manifest = read('manifest.json');
    assert.equal(manifest.nativeClientProof, false); assert.equal(manifest.privateInputUsed, false);
    assert.notEqual(source.version, legacy.version);
    for (const [name, expected] of Object.entries(manifest.artifacts)) {
        const content = fs.readFileSync(new URL(name, folder), 'utf8').replaceAll('\r\n', '\n');
        assert.equal(hash(content), expected.sha256, name);
    }
    validateUniversalComposeDocument(source); validateUniversalComposeDocument(signature);
    const { document } = parseHTML(source.html), cells = document.querySelectorAll('.rt-cta-cell');
    assert.equal(cells.length, 5); assert.equal(document.querySelectorAll('.rt-cta-line,td[colspan="5"]').length, 0);
    const padding = ['6px 0 4px', '6px 0 4px 14px', '6px 0 4px', '6px 8px 4px 0', '6px 0 4px'];
    for (const [index, cell] of [...cells].entries()) {
        assert.doesNotMatch(cell.getAttribute('style'), /border-top/);
        assert.equal(cell.getAttribute('style').split(';').find(value => value.startsWith('padding:')), 'padding:' + padding[index]);
    }
});

for (const name of ['source.json', 'rooster.synthetic.html', 'word.synthetic.html']) {
    test('line-free material is current under explicitly synthetic serialization ' + name, () => {
        const content = fs.readFileSync(new URL(name, folder), 'utf8');
        const actual = name.endsWith('.json') ? JSON.parse(content).html : content.trim();
        assert.equal(current(actual).current, true);
        assert.equal(universalComposeStateFromBody(signature, actual, 'newMail').current, true);
    });
}

for (const [name, mutate] of [
    ['legacy white separator', document => actionTable(document).insertAdjacentHTML('afterbegin', oldLine)],
    ['red extra row', document => actionTable(document).insertAdjacentHTML('afterbegin', oldLine.replaceAll('#ffffff', '#e4002b'))],
    ['empty extra row', document => actionTable(document).insertAdjacentHTML('afterbegin', '<tr></tr>')],
    ['missing CTA cell', document => document.querySelectorAll('.rt-cta-cell')[2].remove()],
    ['extra CTA cell', document => document.querySelector('.rt-cta-cell').parentNode.appendChild(document.createElement('td'))],
    ['CTA colspan', document => document.querySelector('.rt-cta-cell').setAttribute('colspan', '5')],
    ['CTA rowspan', document => document.querySelector('.rt-cta-cell').setAttribute('rowspan', '2')],
]) test(name + ' fails exact one-row five-cell contract', () => {
    const actual = change(mutate), result = current(actual);
    assert.equal(result.current, false); assert.equal(result.checkpoint, 'borders.structure');
    assert.throws(() => validateUniversalComposeDocument({ ...source, html: actual }), { code: 'U2E_SOURCE_INVALID' });
});

test('CTA borders stay absent while every grid cell keeps its visible red border', () => {
    assert.equal(current(change(document => { for (const node of document.querySelectorAll('.rt-cta-cell')) node.removeAttribute('style'); })).current, true);
    const missing = current(change(document => document.querySelector('.rt-col').removeAttribute('style')));
    assert.equal(missing.current, false); assert.equal(missing.checkpoint, 'borders.invalid');
    for (let index = 0; index < 5; index++) for (const declaration of ['border-top:1px solid white', 'border-top:1px solid black', 'mso-border-top-alt:solid white 1pt']) {
        const result = current(change(document => { const node = document.querySelectorAll('.rt-cta-cell')[index]; node.setAttribute('style', node.getAttribute('style') + ';' + declaration); }));
        assert.equal(result.current, false); assert.equal(result.checkpoint, 'borders.cta');
    }
    for (const declaration of ['border:none', 'border:0', 'border-top:0 solid white', 'border-top:hidden']) {
        assert.equal(current(change(document => { for (const node of document.querySelectorAll('.rt-cta-cell')) node.setAttribute('style', node.getAttribute('style') + ';' + declaration); })).current, true);
    }
});

test('old line-bearing v2.3 stays present/noncurrent and its quoted bytes remain untouched', () => {
    assert.throws(() => validateUniversalComposeDocument(legacy), { code: 'U2E_SOURCE_INVALID' });
    const result = current(legacy.html);
    assert.equal(result.present, true); assert.equal(result.current, false); assert.equal(result.checkpoint, 'state.version');
    assert.equal(result.version, legacy.version);
    const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + legacy.html.replaceAll('id="rt-sig-', 'id="x_x_rt-sig-') + '</blockquote>';
    assert.equal(universalComposeStateFromBody(source, '<p><br></p>' + quote, 'reply').present, false);
    const input = source.html + quote, before = hash(input);
    assert.equal(universalComposeStateFromBody(source, input, 'reply').current, true);
    assert.equal(hash(input), before); assert.ok(input.endsWith(quote));
});

test('redaction preserves rejected extra rows and CTA borders and historical line diagnostics', () => {
    for (const [actual, checkpoint] of [
        [change(document => actionTable(document).insertAdjacentHTML('afterbegin', oldLine)), 'borders.structure'],
        [change(document => { const node = document.querySelector('.rt-cta-cell'); node.setAttribute('style', node.getAttribute('style') + ';border-top:1px solid white'); }), 'borders.cta'],
    ]) {
        const fixture = universalRedactedReadbackFixture(source.html, actual);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        assert.doesNotMatch(JSON.stringify(fixture), /Fixture author|direct@example|https:\/\/mail\.example/);
        const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
        const result = universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail');
        assert.equal(result.current, false); assert.equal(result.checkpoint, checkpoint);
    }
    const oldFixture = universalRedactedReadbackFixture(legacy.html, legacy.html);
    assert.ok(validateUniversalRedactedFixture(oldFixture)); assert.match(oldFixture.html, /rt-cta-line/);
});
