import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';
import { parseManagedHtml } from '../../resources/js/outlook-addin/managed-template.js';
import { u2eCenteringContext } from '../../resources/js/outlook-addin/u2e-appearance.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
const fixtureRoot = new URL('../Fixtures/mail/u2e-classic-word-2357/', import.meta.url);
const change = callback => { const { document } = parseHTML(source.html); callback(document); return document.querySelector('table').outerHTML; };
function bareShell(dom) { const shell = dom.querySelector('table'); shell.removeAttribute('align'); shell.setAttribute('style', 'width:780pt;border-collapse:collapse;background:white'); }
const bare = () => change(bareShell);
function stripOwnColor(node) {
    node.removeAttribute('bgcolor'); node.setAttribute('style', (node.getAttribute('style') || '').split(';').filter(value => !/^\s*background(?:-color)?\s*:/i.test(value)).join(';'));
}
function wordDispo(dom) {
    const dispatch = dom.querySelector('table[width="247"]');
    stripOwnColor(dispatch); dispatch.setAttribute('style', dispatch.getAttribute('style') + ';background:#E4002B');
    const omitted = [...dispatch.querySelectorAll('td')].filter(cell => cell.closest('table') === dispatch && (cell.getAttribute('width') === '231' || cell.getAttribute('colspan') === '3'));
    assert.equal(omitted.length, 4); omitted.forEach(stripOwnColor); return { dispatch, omitted };
}

test('Word may move only the shell centering to its own ancestor DIV before any table or cell boundary', () => {
    const html = '<div><div align="center"><div>' + bare() + '</div></div></div>';
    assert.equal(universalComposeStateFromBody(source, html, 'newMail').current, true);
    const { elements } = parseManagedHtml(html), index = elements.findIndex(node => node.tag === 'table');
    assert.deepEqual(u2eCenteringContext(elements, index), { centered: true, inherited: true });
    const root = elements[index]; assert.equal(root.attrs.align, undefined); assert.doesNotMatch(root.attrs.style, /margin/);
});

test('an unrelated or contradicted center container never supplies shell ownership or alignment', () => {
    const denied = [
        '<div align="center"></div>' + bare(),
        '<div align="center"><table><tr><td>' + bare() + '</td></tr></table></div>',
        '<div align="center"><table>' + bare() + '</table></div>',
        '<div align="center"><div align="left">' + bare() + '</div></div>',
        '<div align="center" style="float:left">' + bare() + '</div>',
        '<div align="center" style="margin:0 20px">' + bare() + '</div>',
        '<div align="center" style="text-align:left">' + bare() + '</div>',
        '<div align="center" style="position:absolute">' + bare() + '</div>',
        '<div align="center">' + change(dom => { bareShell(dom); dom.querySelector('table').setAttribute('align', 'left'); }) + '</div>',
        '<div align="center">' + change(dom => { bareShell(dom); dom.querySelector('table').setAttribute('style', 'width:1040px;margin:0'); }) + '</div>',
    ];
    for (const html of denied) {
        const state = universalComposeStateFromBody(source, html, 'newMail');
        assert.equal(state.current, false); assert.equal(state.checkpoint, 'shell.align');
    }
    const unmarked = '<div align="center">' + change(dom => { bareShell(dom); for (const node of dom.querySelectorAll('[id]')) node.removeAttribute('id'); }) + '</div>';
    assert.equal(universalComposeStateFromBody(source, unmarked, 'newMail').present, false);
});

test('only empty Dispo cell colors inherit the closest table background inside that same Dispo', () => {
    const actual = change(dom => { wordDispo(dom); bareShell(dom); });
    assert.equal(universalComposeStateFromBody(source, '<div align="center">' + actual + '</div>', 'newMail').current, true);
    const onlyColor = change(dom => wordDispo(dom));
    assert.equal(universalComposeStateFromBody(source, onlyColor, 'newMail').current, true);
});

test('explicit colors, transparent values, gradients and background images never fall through to the Dispo table', () => {
    for (const value of ['background:transparent', 'background:none', 'background-color:transparent', 'background-color:#0000ff',
        'background:#e4002b url(https://private.invalid/image)', 'background-image:linear-gradient(#e4002b,#e4002b)',
        'background-image:url(https://private.invalid/image)']) {
        const html = change(dom => { const { omitted } = wordDispo(dom); omitted[0].setAttribute('style', omitted[0].getAttribute('style') + ';' + value); });
        const state = universalComposeStateFromBody(source, html, 'newMail');
        assert.equal(state.current, false); assert.match(state.checkpoint, /^colors\./);
    }
    const conflict = change(dom => { const { omitted } = wordDispo(dom); omitted[0].setAttribute('bgcolor', '#ffffff'); omitted[0].setAttribute('style', omitted[0].getAttribute('style') + ';background:#e4002b'); });
    assert.equal(universalComposeStateFromBody(source, conflict, 'newMail').checkpoint, 'colors.conflict');
});

test('the fallback never crosses another table or repairs a missing Dispo table color', () => {
    const cases = [
        dom => { const { dispatch } = wordDispo(dom); stripOwnColor([...dispatch.querySelectorAll('td')].find(cell => cell.closest('table') !== dispatch)); },
        dom => { const { dispatch } = wordDispo(dom); stripOwnColor(dispatch); dom.querySelector('table').setAttribute('bgcolor', '#e4002b'); },
    ];
    for (const mutate of cases) assert.equal(universalComposeStateFromBody(source, change(mutate), 'newMail').current, false);
    // C-K11 extends the same nearest-table rule to material cells outside Dispo.
    const whiteCompany = change(dom => { wordDispo(dom); stripOwnColor(dom.querySelector('td[width="213"]')); });
    assert.equal(universalComposeStateFromBody(source, whiteCompany, 'newMail').current, true);
    // C-K21 corners now equal their red nearest table, so Word may omit them too.
    const redCorner = change(dom => { const { dispatch } = wordDispo(dom); stripOwnColor(dispatch.querySelector('td[width="8"]')); });
    assert.equal(universalComposeStateFromBody(source, redCorner, 'newMail').current, true);
});

test('ancestor centering and inherited Dispo colors do not turn quoted material into the current signature', () => {
    const word = '<div align="center">' + change(dom => { wordDispo(dom); bareShell(dom); }) + '</div>';
    assert.equal(universalComposeStateFromBody(source, '<blockquote>' + word + '</blockquote>', 'newMail').present, false);
    const reply = '<p><br></p><hr><div id="divRplyFwdMsg">Quoted header</div>' + word;
    const state = universalComposeStateFromBody(source, reply, 'reply');
    assert.equal(state.present, false); assert.equal(state.current, false);
});

test('the redacted exact 23:57 fixture remains historical and cannot admit a legacy source after the template revision', () => {
    const fixtureSource = JSON.parse(fs.readFileSync(new URL('source.json', fixtureRoot), 'utf8'));
    const actual = fs.readFileSync(new URL('actual.redacted.html', fixtureRoot), 'utf8');
    const manifest = JSON.parse(fs.readFileSync(new URL('manifest.json', fixtureRoot), 'utf8'));
    for (const [name, entry] of Object.entries(manifest.artifacts)) assert.equal(crypto.createHash('sha256').update(fs.readFileSync(new URL(name, fixtureRoot))).digest('hex'), entry.sha256);
    assert.throws(() => validateUniversalComposeDocument(fixtureSource), { code: 'U2E_SOURCE_INVALID' });
    const state = universalComposeStateFromBody(source, actual, 'newMail');
    assert.equal(state.present, true); assert.equal(state.kind, 'combined'); assert.equal(state.current, false);
    assert.equal(state.checkpoint, 'state.version');
    assert.equal(manifest.original.material.present, true); assert.equal(manifest.original.material.images, 13);
    assert.equal(manifest.original.material.links, 4); assert.equal(manifest.original.material.kind, 'combined');
    assert.equal(manifest.original.sha256, '6d6b0cbb3d8302e5c6df2f936332d07d2a075524d89cf31767d9be2bea62a7c2');
});
