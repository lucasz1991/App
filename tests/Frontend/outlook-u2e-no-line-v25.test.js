import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalDiagnosticReplayHtml, validateUniversalRedactedFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const read = file => fs.readFileSync(new URL(file, folder), 'utf8').replaceAll('\r\n', '\n');
const source = JSON.parse(read('source.json')), previous = JSON.parse(read('legacy-v23/source.json'));
const v25 = JSON.parse(read('legacy-v25/source.json'));
const signature = JSON.parse(read('signature.json')), manifest = JSON.parse(read('manifest.json'));
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const ownRows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
const actionTable = document => [...document.querySelectorAll('table')].find(table => {
    const rows = ownRows(table);
    return rows.length === 1 && rows[0].children.length === 5;
});
const current = html => universalComposeStateFromBody(source, html, 'newMail');

test('C-K25+C-K26 renderer output applies exactly the approved flat no-line changes', () => {
    const line = /<tr class="rt-cta-line"><td\b[^>]*>[\s\S]*?<\/td><\/tr>/g;
    assert.equal([...previous.html.matchAll(line)].length, 1);
    const projected = previous.html.replace(line, '').replaceAll(previous.version, v25.version)
        .replaceAll('padding:0 0 9px', 'padding:0 0 4px')
        .replaceAll('font-size:19px;line-height:21px', 'font-size:19px;line-height:20px')
        .replaceAll('font-size:15px;line-height:19px', 'font-size:15px;line-height:18px')
        .replaceAll('font-size:38px;line-height:40px', 'font-size:34px;line-height:36px')
        .replaceAll('padding:11px 0 5px', 'padding:6px 0 4px')
        .replaceAll('padding:11px 8px 5px 0', 'padding:6px 8px 4px 0');
    assert.equal(projected, v25.html);
    assert.equal(manifest.previousLineVersion, previous.version);
    assert.equal(manifest.previousLineSource, 'legacy-v23/source.json');
    assert.equal(manifest.templateSha256, '0201650e3985cf95b7ef84d112fede5d8338017f6d725a764906da1a99e1c5dc');
    assert.equal(manifest.iosProjection.nativeClientProof, false);
    assert.equal(manifest.iosProjection.basis, 'legacy-v27/ios.synthetic.redacted.json');
    assert.equal(manifest.iosProjection.nativeAncestor, 'ios.ck24-210443.redacted.json');
});

test('synthetic Word and Rooster projections apply only the no-line and flat dispatch deltas', () => {
    for (const file of ['word.synthetic.html', 'rooster.synthetic.html']) {
        const old = parseHTML(read('legacy-v23/' + file).replaceAll(previous.version, v25.version)).document;
        const candidates = [...old.querySelectorAll('td[colspan="5"]')];
        assert.equal(candidates.length, 1); candidates[0].parentNode.remove();
        const panel = old.querySelector('table[width="247"]'), panelRows = ownRows(panel);
        const head = panelRows[1].outerHTML, actionsBefore = panelRows[2].outerHTML;
        const headAfter = head.replaceAll('padding:0 0 9px', 'padding:0 0 4px')
            .replaceAll('line-height:21px', 'line-height:20px').replaceAll('line-height:19px', 'line-height:18px')
            .replaceAll('font-size:38px', 'font-size:34px').replaceAll('line-height:40px', 'line-height:36px');
        const actionsAfter = actionsBefore.replaceAll('padding:11px 0 5px', 'padding:6px 0 4px')
            .replaceAll('padding:11px 8px 5px 0', 'padding:6px 8px 4px 0');
        assert.equal(old.toString().replace(head, headAfter).replace(actionsBefore, actionsAfter).trimEnd(), read('legacy-v25/' + file).trimEnd());
        const actual = parseHTML(read(file)).document, actions = actionTable(actual);
        assert.equal(ownRows(actions).length, 1); assert.equal(ownRows(actions)[0].children.length, 5);
        assert.equal(actual.querySelectorAll('td[colspan="5"],.rt-cta-line').length, 0);
        assert.equal(current(read(file)).current, true);
    }
});

test('the synthetic iOS projection retains the strict cap wrapper and all expected material evidence', () => {
    const fixture = JSON.parse(read('ios.synthetic.redacted.json'));
    assert.ok(validateUniversalRedactedFixture(fixture));
    const html = universalDiagnosticReplayHtml(fixture.html), expected = universalDiagnosticReplayHtml(fixture.expectedHtml);
    const plan = { ...signature, html: expected };
    validateUniversalComposeDocument(plan);
    const state = universalComposeStateFromBody(plan, html, 'newMail', { host: 'OutlookIOS' });
    assert.equal(state.current, true); assert.equal(state.kind, 'signature');
    assert.equal(universalComposeStateFromBody(plan, html, 'newMail', { host: 'OutlookAndroid' }).checkpoint, 'caps.structure');
    assert.doesNotMatch(JSON.stringify(fixture), /Fixture person|Fixture author|direct@example|https:\/\/mail\.example|rt-cta-line|colspan=\\"5/);
});

test('all current synthetic host inputs carry the approved flat head and action typography', () => {
    const ios = JSON.parse(read('ios.synthetic.redacted.json'));
    for (const [host, html] of [['B1', source.html], ['Word', read('word.synthetic.html')], ['Rooster', read('rooster.synthetic.html')], ['iOS projection', ios.html]]) {
        const document = parseHTML(html).document;
        const panel = [...document.querySelectorAll('table')].find(node => node.getAttribute('width') === '247'
            || /(?:^|;)width:247px(?:;|$)/.test(node.getAttribute('style') || ''));
        const panelRows = ownRows(panel), head = [...ownRows(panelRows[1].querySelector('table'))[0].children];
        const actions = [...ownRows(panelRows[2].querySelector('table'))[0].children];
        const styles = node => [node, ...node.querySelectorAll('*')].map(value => value.getAttribute('style') || '');
        assert.ok(styles(head[0]).some(style => /font-size:19px/.test(style) && /line-height:20px/.test(style)), host);
        assert.ok(styles(head[0]).some(style => /font-size:15px/.test(style) && /line-height:18px/.test(style)), host);
        assert.ok(styles(head[1]).some(style => /font-size:34px/.test(style) && /line-height:36px/.test(style)), host);
        for (const cell of head) assert.match(cell.getAttribute('style'), /padding:0 0 4px|padding-bottom:4px/, host);
        const padding = ['6px 0 4px', '6px 0 4px 14px', '6px 0 4px', '6px 8px 4px 0', '6px 0 4px'];
        for (const [index, cell] of actions.entries()) {
            const style = cell.getAttribute('style');
            if (style.includes('padding:')) assert.ok(style.split(';').includes('padding:' + padding[index]), host);
            else { assert.match(style, /padding-top:6px/, host); assert.match(style, /padding-bottom:4px/, host); }
        }
    }
});

test('removing the line grants no white/black action background and no alternate action topology', () => {
    for (const color of ['#ffffff', '#000000', '#e4e8ec']) for (let index = 0; index < 5; index++) {
        const document = parseHTML(source.html).document, cell = document.querySelectorAll('.rt-cta-cell')[index];
        cell.setAttribute('bgcolor', color);
        cell.setAttribute('style', cell.getAttribute('style').replaceAll('#e4002b', color));
        const actual = document.toString(), state = current(actual);
        assert.equal(state.current, false); assert.equal(state.checkpoint, 'colors.corners');
        assert.throws(() => validateUniversalComposeDocument({ ...source, html: actual }), { code: 'U2E_SOURCE_INVALID' });
    }
    for (const addition of ['<tr><td bgcolor="#e4002b"></td></tr>', '<tr style="display:none"><td colspan="5"></td></tr>']) {
        const document = parseHTML(source.html).document;
        actionTable(document).insertAdjacentHTML('beforeend', addition);
        assert.equal(current(document.toString()).current, false);
    }
});

test('v2.3 host exports remain historical own material or quote content without modifying their bytes', () => {
    for (const file of ['legacy-v23/word.synthetic.html', 'legacy-v23/rooster.synthetic.html']) {
        const html = read(file), before = sha(html), old = current(html);
        assert.equal(old.present, true); assert.equal(old.current, false); assert.equal(old.checkpoint, 'state.version');
        const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + html + '</blockquote>';
        assert.equal(universalComposeStateFromBody(source, '<p><br></p>' + quote, 'reply').present, false);
        assert.equal(universalComposeStateFromBody(source, source.html + quote, 'reply').current, true);
        assert.equal(sha(html), before);
    }
});
