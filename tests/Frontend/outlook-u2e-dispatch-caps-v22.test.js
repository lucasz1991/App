import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture, universalDiagnosticReplayHtml } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const source = JSON.parse(fs.readFileSync(new URL('source.json', folder), 'utf8'));
const signature = JSON.parse(fs.readFileSync(new URL('signature.json', folder), 'utf8'));
const previous = JSON.parse(fs.readFileSync(new URL('source.c21-legacy.json', folder), 'utf8'));
const manifest = JSON.parse(fs.readFileSync(new URL('manifest.json', folder), 'utf8'));
const rows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
const cells = row => [...row.children].filter(cell => cell.localName === 'td');
function targets(document) {
    const panel = document.querySelector('table[width="247"]'), panelRows = rows(panel);
    return { panel, panelRows, caps: [cells(panelRows[0])[0], cells(panelRows[3])[0]], head: cells(panelRows[1])[0], actions: cells(panelRows[2])[0] };
}
function change(callback) {
    const { document } = parseHTML(source.html);
    callback(targets(document), document);
    return document.toString();
}
const current = html => universalComposeStateFromBody(source, html, 'newMail');
function removeStyle(node, property) {
    node.setAttribute('style', node.getAttribute('style').split(';').filter(declaration => declaration.split(':')[0].trim() !== property).join(';'));
}
function style(node, property, value) { removeStyle(node, property); node.setAttribute('style', node.getAttribute('style') + ';' + property + ':' + value); }

test('v2.2 B1 and two synthetic hosts are current with two single-cell red caps and four fewer images', () => {
    assert.equal(manifest.templateSha256, 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf');
    assert.equal(manifest.previousCornerVersion, previous.version);
    assert.equal(manifest.nativeClientProof, false);
    for (const name of ['source.json', 'rooster.synthetic.html', 'word.synthetic.html']) {
        const input = fs.readFileSync(new URL(name, folder), 'utf8');
        const html = name.endsWith('.json') ? JSON.parse(input).html : input.trim();
        assert.equal(current(html).current, true, name);
        assert.equal(universalComposeStateFromBody(signature, html, 'newMail').current, true, name);
        const { document } = parseHTML(html), { panelRows, caps, head, actions } = targets(document);
        assert.deepEqual(panelRows.map(row => cells(row).length), [1, 1, 1, 1]);
        for (const cap of caps) {
            assert.equal(cap.querySelectorAll('img').length, 0);
            assert.equal(cap.getAttribute('height'), '8');
            assert.match(cap.getAttribute('style'), /background-color:(?:#e4002b|rgb\(228, 0, 43\))/);
        }
        assert.equal(head.getAttribute('colspan'), null); assert.equal(actions.getAttribute('colspan'), null);
        assert.equal(document.querySelectorAll('img').length, 9);
    }
    const { document: old } = parseHTML(previous.html);
    assert.equal(old.querySelectorAll('img').length, 13);
});

test('current source retains exact separate/zero-spacing/radius and three exact-height targets', () => {
    const { document } = parseHTML(source.html), { panel, caps } = targets(document);
    assert.match(panel.getAttribute('style'), /border-collapse:separate;border-spacing:0;border-radius:8px/);
    assert.match(caps[0].getAttribute('style'), /border-radius:8px 8px 0 0/);
    assert.match(caps[1].getAttribute('style'), /border-radius:0 0 8px 8px/);
    assert.equal((source.html.match(/mso-line-height-rule:exactly/g) || []).length, 3);
    for (const [node, property, value] of [[panel, 'border-collapse', 'collapse'], [panel, 'border-spacing', '1px'], [panel, 'border-radius', '0'], [caps[0], 'border-radius', '0']]) {
        const copy = parseHTML(source.html).document, target = node === panel ? targets(copy).panel : targets(copy).caps[0];
        style(target, property, value);
        assert.throws(() => validateUniversalComposeDocument({ ...source, html: copy.toString() }), { code: 'U2E_SOURCE_INVALID' });
    }
});

test('Word may omit radii and use 6pt cap geometry without changing current material', () => {
    const actual = change(({ panel, caps }) => {
        removeStyle(panel, 'border-radius');
        for (const cap of caps) {
            removeStyle(cap, 'border-radius');
            style(cap, 'height', '6pt'); style(cap, 'line-height', '6pt'); style(cap, 'font-size', '1pt'); style(cap, 'padding', '0cm 0cm 0cm 0cm');
            cap.innerHTML = '<p>&nbsp;</p>';
        }
    });
    assert.equal(current(actual).current, true);
    assert.throws(() => validateUniversalComposeDocument({ ...source, html: actual }), { code: 'U2E_SOURCE_INVALID' });
});

test('Word may move the exact cap line box only into a single empty paragraph', () => {
    const serialized = () => change(({ caps }) => {
        for (const cap of caps) {
            removeStyle(cap, 'line-height');
            cap.innerHTML = '<p class="MsoNormal" style="line-height:6pt;mso-line-height-rule:exactly"><span style="font-size:1pt">&nbsp;</span></p>';
        }
    });
    assert.equal(current(serialized()).current, true);
    assert.throws(() => validateUniversalComposeDocument({ ...source, html: serialized() }), { code: 'U2E_SOURCE_INVALID' });
    for (const content of ['<p style="line-height:7px">&nbsp;</p>', '<p style="line-height:8px">&nbsp;</p><p style="line-height:8px">&nbsp;</p>',
        '<p style="line-height:8px">&nbsp;<br></p>', '<p style="line-height:8px">ï¿½</p>']) {
        const actual = change(({ caps }) => { removeStyle(caps[0], 'line-height'); caps[0].innerHTML = content; });
        assert.equal(current(actual).current, false, content);
    }
});

for (const [name, mutate] of [
    ['missing cap row', ({ panelRows }) => panelRows[0].remove()],
    ['duplicate cap row', ({ panelRows }) => panelRows[0].after(panelRows[0].cloneNode(true))],
    ['second cap cell', ({ panelRows }, document) => panelRows[0].appendChild(document.createElement('td'))],
    ['old head colspan', ({ head }) => head.setAttribute('colspan', '3')],
    ['old actions colspan', ({ actions }) => actions.setAttribute('colspan', '3')],
]) {
    test('exact one-cell panel topology rejects ' + name, () => {
        const actual = change(mutate);
        assert.equal(current(actual).current, false); assert.equal(current(actual).checkpoint, 'borders.structure');
        assert.throws(() => validateUniversalComposeDocument({ ...source, html: actual }), { code: 'U2E_SOURCE_INVALID' });
    });
}

test('caps must remain empty red material rather than become text or image containers', () => {
    assert.equal(current(change(({ caps }) => { caps[0].textContent = 'unexpected'; })).checkpoint, 'caps.structure');
    assert.equal(current(change(({ caps }) => { caps[0].innerHTML = '<div style="height:20px">&nbsp;</div>'; })).checkpoint, 'caps.structure');
    for (const content of ['<span style="display:block;height:20px">&nbsp;</span>', '<p style="margin-top:1px">&nbsp;</p>']) {
        assert.equal(current(change(({ caps }) => { caps[0].innerHTML = content; })).checkpoint, 'caps.visibility');
    }
    const wrong = change(({ caps }) => { caps[0].setAttribute('bgcolor', '#ffffff'); style(caps[0], 'background-color', '#ffffff'); });
    assert.equal(current(wrong).checkpoint, 'colors.corners');
    const withImage = change(({ caps }, document) => caps[0].appendChild(document.querySelector('img').cloneNode(true)));
    assert.equal(current(withImage).current, false);
});

test('eight-pixel caps reject zero, missing or different height, line box and nonzero padding', () => {
    for (const [property, value] of [['height', '7px'], ['height', '9px'], ['height', '0px'], ['line-height', '7px'], ['line-height', '0px'], ['max-height', '0px'], ['padding', '1px'], ['padding-bottom', '1pt']]) {
        const result = current(change(({ caps }) => style(caps[0], property, value)));
        assert.equal(result.current, false, property + ':' + value); assert.equal(result.checkpoint, 'caps.visibility');
    }
    const missing = change(({ caps }) => { caps[0].removeAttribute('height'); removeStyle(caps[0], 'height'); });
    assert.equal(current(missing).checkpoint, 'caps.visibility');
    for (const property of ['display', 'visibility', 'opacity']) {
        const value = { display: 'none', visibility: 'hidden', opacity: '0' }[property];
        assert.equal(current(change(({ caps }) => style(caps[0], property, value))).checkpoint, 'material.hidden');
    }
});

test('dynamic eight-to-ten image contract preserves all non-corner assets and rejects eleven images', () => {
    const onePhone = change((_targets, document) => document.querySelector('img[src$="d92bd192b6.png"]').closest('tr').remove());
    const twoPhones = change((_targets, document) => { const row = document.querySelector('img[src$="d92bd192b6.png"]').closest('tr'); row.after(row.cloneNode(true)); });
    for (const html of [onePhone, twoPhones]) {
        const expected = { ...source, html };
        validateUniversalComposeDocument(expected);
        assert.equal(universalComposeStateFromBody(expected, html, 'newMail').current, true);
    }
    const threePhones = change((_targets, document) => { const row = document.querySelector('img[src$="d92bd192b6.png"]').closest('tr'); row.after(row.cloneNode(true), row.cloneNode(true)); });
    assert.throws(() => validateUniversalComposeDocument({ ...source, html: threePhones }), { code: 'U2E_SOURCE_INVALID' });
    const missingMap = change((_targets, document) => document.querySelector('img[src$="a38bc7d27c.png"]').remove());
    assert.equal(current(missingMap).current, false);
    assert.equal(current(onePhone).current, false, 'the base personal material still requires its phone row');
});

test('cap diagnostics retain class/radius/visibility and reproduce a cap failure after redaction', () => {
    const actual = change(({ caps }) => style(caps[0], 'height', '9px'));
    const fixture = universalRedactedReadbackFixture(source.html, actual);
    assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
    assert.match(fixture.html, /rt-dispo-cap/); assert.match(fixture.expectedHtml, /border-radius:8px/);
    const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
    const result = universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail');
    assert.equal(result.current, false); assert.equal(result.checkpoint, 'caps.visibility');
    assert.doesNotMatch(JSON.stringify(fixture), /Fixture author|direct@example|https:\/\/mail\.example/);
});

test('diagnostic replay preserves both current host serializations including exact zero-cm Word padding', () => {
    for (const name of ['rooster.synthetic.html', 'word.synthetic.html']) {
        const html = fs.readFileSync(new URL(name, folder), 'utf8').trim();
        const fixture = universalRedactedReadbackFixture(source.html, html);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
        assert.equal(universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail').current, true, name);
    }
    const actual = change(({ caps }) => style(caps[0], 'padding', '1cm'));
    const fixture = universalRedactedReadbackFixture(source.html, actual);
    const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
    assert.equal(universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail').checkpoint, 'caps.visibility');
});
