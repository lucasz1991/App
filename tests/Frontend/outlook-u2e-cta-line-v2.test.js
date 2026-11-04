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
const legacy = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback/source.json', import.meta.url), 'utf8'));
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function change(mutate) { const { document } = parseHTML(source.html); mutate(document); return document.toString(); }
const current = html => universalComposeStateFromBody(source, html, 'newMail');
const line = document => document.querySelector('.rt-cta-line td');
function setBackground(node, color) {
    node.setAttribute('bgcolor', color);
    node.setAttribute('style', (node.getAttribute('style') || '').split(';').filter(value => !/^\s*background(?:-color)?\s*:/i.test(value)).join(';') + ';background-color:' + color);
}

test('versioned fixtures are generated from B1 with synthetic data and do not claim native host proof', () => {
    const manifest = JSON.parse(fs.readFileSync(new URL('manifest.json', folder), 'utf8'));
    assert.equal(manifest.nativeClientProof, false); assert.equal(manifest.privateInputUsed, false);
    assert.notEqual(source.version, legacy.version);
    for (const [name, expected] of Object.entries(manifest.artifacts)) {
        const content = fs.readFileSync(new URL(name, folder), 'utf8').replaceAll('\r\n', '\n');
        assert.equal(hash(content), expected.sha256);
    }
    validateUniversalComposeDocument(source); validateUniversalComposeDocument(signature);
    const { document } = parseHTML(source.html), cells = document.querySelectorAll('.rt-cta-cell');
    assert.equal(cells.length, 5); assert.equal(document.querySelectorAll('.rt-cta-line').length, 1);
    assert.equal(line(document).getAttribute('colspan'), '5');
    const padding = ['11px 0 5px', '11px 0 5px 14px', '11px 0 5px', '11px 8px 5px 0', '11px 0 5px'];
    for (const [index, cell] of [...cells].entries()) {
        assert.doesNotMatch(cell.getAttribute('style'), /border-top/);
        assert.equal(cell.getAttribute('style').split(';').find(value => value.startsWith('padding:')), 'padding:' + padding[index]);
    }
});

for (const name of ['source.json', 'rooster.synthetic.html', 'word.synthetic.html']) {
    test('new template is current under the explicitly synthetic serialization ' + name, () => {
        const content = fs.readFileSync(new URL(name, folder), 'utf8');
        const actual = name.endsWith('.json') ? JSON.parse(content).html : content.trim();
        assert.equal(current(actual).current, true);
        assert.equal(universalComposeStateFromBody(signature, actual, 'newMail').current, true);
    });
}

test('black line color is retained as evidence and cannot become an admitted source', () => {
    const actual = change(document => setBackground(line(document), '#000000'));
    const result = current(actual);
    assert.equal(result.present, true); assert.equal(result.current, false);
    assert.equal(result.checkpoint, 'evidence.colors'); assert.equal(result.difference.key, 'evidence.colors.value');
    assert.throws(() => validateUniversalComposeDocument({ ...source, html: actual }), { code: 'U2E_SOURCE_INVALID' });
});

for (const [name, mutate] of [
    ['missing line row', document => document.querySelector('.rt-cta-line').remove()],
    ['duplicate line row', document => { const row = document.querySelector('.rt-cta-line'); row.after(row.cloneNode(true)); }],
    ['wrong colspan', document => line(document).setAttribute('colspan', '4')],
    ['additional line cell', document => document.querySelector('.rt-cta-line').appendChild(document.createElement('td'))],
]) {
    test(name + ' fails the exact two-row action table contract', () => {
        const result = current(change(mutate));
        assert.equal(result.current, false); assert.equal(result.checkpoint, 'borders.structure');
    });
}

for (const [property, value] of [['height', '0px'], ['line-height', '0px'], ['font-size', '0px'], ['max-height', '0px'], ['height', 'auto']]) {
    test('line visibility rejects ' + property + ':' + value, () => {
        const result = current(change(document => {
            const node = line(document);
            node.setAttribute('style', node.getAttribute('style') + ';' + property + ':' + value);
        }));
        assert.equal(result.current, false); assert.equal(result.checkpoint, 'line.visibility');
    });
}

test('hidden line and missing white background cannot be concealed by ancestor color', () => {
    for (const declaration of ['display:none', 'visibility:hidden', 'opacity:0']) {
        const actual = change(document => { const node = document.querySelector('.rt-cta-line'); node.setAttribute('style', declaration); });
        assert.equal(current(actual).current, false); assert.equal(current(actual).checkpoint, 'material.hidden');
    }
    const missing = change(document => {
        const node = line(document); node.removeAttribute('bgcolor');
        node.setAttribute('style', node.getAttribute('style').split(';').filter(value => !/^\s*background(?:-color)?\s*:/i.test(value)).join(';'));
    });
    assert.equal(current(missing).current, false); assert.equal(current(missing).checkpoint, 'evidence.colors');
});

test('zero-percent opacity cannot make the required white line invisible while remaining current', () => {
    for (const opacity of ['0%', '+0.0%', '.0%', '-0%']) {
        for (const selector of ['.rt-cta-line', '.rt-cta-line td']) {
            const result = current(change(document => {
                const node = document.querySelector(selector);
                node.setAttribute('style', (node.getAttribute('style') || '') + ';opacity:' + opacity);
            }));
            assert.equal(result.current, false); assert.equal(result.checkpoint, 'material.hidden');
        }
    }
});

test('an omitted CTA style means no border while a grid cell still requires its visible red border', () => {
    const borderless = change(document => { for (const node of document.querySelectorAll('.rt-cta-cell')) node.removeAttribute('style'); });
    assert.equal(current(borderless).current, true);
    const missingGridStyle = change(document => document.querySelector('.rt-col').removeAttribute('style'));
    assert.equal(current(missingGridStyle).current, false);
    assert.equal(current(missingGridStyle).checkpoint, 'borders.invalid');
});

test('each visible CTA top edge is rejected even when white and matching the previous template', () => {
    for (let index = 0; index < 5; index++) {
        for (const declaration of ['border-top:1px solid white', 'border-top:1px solid black', 'mso-border-top-alt:solid white 1pt']) {
            const result = current(change(document => {
                const node = document.querySelectorAll('.rt-cta-cell')[index];
                node.setAttribute('style', node.getAttribute('style') + ';' + declaration);
            }));
            assert.equal(result.current, false); assert.equal(result.checkpoint, 'borders.cta');
        }
    }
});

test('Word may explicitly serialize an invisible CTA border without changing the white background line', () => {
    for (const declaration of ['border:none', 'border:0', 'border-top:0 solid white', 'border-top:hidden']) {
        const actual = change(document => { for (const node of document.querySelectorAll('.rt-cta-cell')) node.setAttribute('style', node.getAttribute('style') + ';' + declaration); });
        assert.equal(current(actual).current, true);
    }
});

test('legacy own material is present but not current and legacy source envelopes are rejected', () => {
    assert.throws(() => validateUniversalComposeDocument(legacy), { code: 'U2E_SOURCE_INVALID' });
    const result = current(legacy.html);
    assert.equal(result.present, true); assert.equal(result.current, false); assert.equal(result.checkpoint, 'state.version');
    assert.equal(result.version, legacy.version);
});

test('legacy quotes including double host prefixes are excluded without rewriting any history', () => {
    const quote = '<div id=divRplyFwdMsg>Historical header</div><blockquote>' + legacy.html.replaceAll('id="rt-sig-', 'id="x_x_rt-sig-') + '</blockquote></body></html>';
    const empty = '<html><body><p><br></p>' + quote;
    assert.equal(universalComposeStateFromBody(source, empty, 'reply').present, false);
    const input = '<html><body>' + source.html + quote, before = hash(input);
    assert.equal(universalComposeStateFromBody(source, input, 'reply').current, true);
    assert.equal(hash(input), before); assert.ok(input.endsWith(quote));
    const ownDouble = source.html.replaceAll('id="rt-sig-', 'id="x_x_rt-sig-');
    assert.equal(current(ownDouble).current, false);
});

test('signature-only redaction preserves the new line and reproduces black hidden and missing failures', () => {
    const cases = [
        [change(document => setBackground(line(document), '#000000')), 'evidence.colors'],
        [change(document => document.querySelector('.rt-cta-line').remove()), 'borders.structure'],
        [change(document => document.querySelector('.rt-cta-line').setAttribute('style', 'display:none')), 'material.hidden'],
        [change(document => { const node = document.querySelector('.rt-cta-cell'); node.setAttribute('style', node.getAttribute('style') + ';border-top:1px solid white'); }), 'borders.cta'],
    ];
    for (const [actual, checkpoint] of cases) {
        const fixture = universalRedactedReadbackFixture(source.html, actual);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        assert.doesNotMatch(JSON.stringify(fixture), /Fixture author|direct@example|https:\/\/mail.example/);
        const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
        const result = universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail');
        assert.equal(result.current, false); assert.equal(result.checkpoint, checkpoint);
    }
});
