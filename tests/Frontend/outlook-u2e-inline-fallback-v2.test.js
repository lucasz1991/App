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
const c13 = JSON.parse(fs.readFileSync(new URL('source.c13-legacy.json', folder), 'utf8'));
const manifest = JSON.parse(fs.readFileSync(new URL('manifest.json', folder), 'utf8'));
const sha256 = value => crypto.createHash('sha256').update(value).digest('hex');
const rows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
const cells = row => [...row.querySelectorAll('td')].filter(cell => cell.parentNode === row);
function targets(document) {
    const shell = document.querySelector('table'), grid = cells(rows(shell)[1]);
    const dispatch = grid[3].querySelector('table[width="247"]'), dispatchRows = rows(dispatch);
    const actions = cells(dispatchRows[2])[0].querySelector('table'), actionRows = rows(actions);
    return { grid, exact: [...cells(dispatchRows[0]), ...cells(dispatchRows[3]), cells(actionRows[0])[0], cells(actionRows[1])[2]],
        author: cells(rows(shell)[0])[0], inner: grid[0].querySelector('td') };
}
function declarations(node, name) {
    return (node.getAttribute('style') || '').split(';').filter(value => value.split(':')[0].trim().toLowerCase() === name);
}
function remove(node, name) {
    node.setAttribute('style', (node.getAttribute('style') || '').split(';').filter(value => value.split(':')[0].trim().toLowerCase() !== name).join(';'));
}
function append(node, declaration) { node.setAttribute('style', (node.getAttribute('style') || '') + ';' + declaration); }
function mutate(change) { const { document } = parseHTML(source.html); change(targets(document), document); return { ...source, html: document.toString() }; }
const current = (html, type = 'newMail') => universalComposeStateFromBody(source, html, type);

test('C21 manifest pins the actual B1 output while preserving the exact historical C13 source', () => {
    assert.equal(manifest.kind, 'synthetic-c-k21-v22-host-serializations');
    assert.equal(manifest.nativeClientProof, false); assert.equal(manifest.privateInputUsed, false);
    assert.equal(manifest.templateSha256, 'f297c0bb222061eb3b78c85889e139274067d5744707d7807d5b6e8545e676b2');
    assert.equal(source.version, manifest.version); assert.equal(signature.version, source.version);
    assert.equal(c13.version, manifest.previousVersion); assert.notEqual(source.version, c13.version);
    assert.equal(sha256(fs.readFileSync(new URL('source.c13-legacy.json', folder), 'utf8').replaceAll('\r\n', '\n')), 'c3a729fa720c8fa622e988e873451a76238017539fd457ff7b5d1ccaaf1f162e');
    const { document } = parseHTML(source.html), { grid, exact } = targets(document);
    assert.equal(grid.length, 4); assert.equal(exact.length, 4);
    for (const cell of grid) for (const [name, value] of [['display', 'inline-block'], ['max-width', '100%'], ['box-sizing', 'border-box']]) {
        assert.deepEqual(declarations(cell, name).map(item => item.split(':').slice(1).join(':').trim()), [value]);
    }
    for (const cell of exact) assert.deepEqual(declarations(cell, 'mso-line-height-rule').map(item => item.split(':')[1].trim()), ['exactly']);
    assert.equal((source.html.match(/mso-line-height-rule:exactly/g) || []).length, 4);
    validateUniversalComposeDocument(source); validateUniversalComposeDocument(signature);
});

for (const name of ['rooster.synthetic.html', 'word.synthetic.html']) {
    test('C15 simulated host serialization remains current without a native acceptance claim: ' + name, () => {
        const html = fs.readFileSync(new URL(name, folder), 'utf8').trim();
        assert.equal(current(html).current, true);
        assert.equal(universalComposeStateFromBody(signature, html, 'newMail').current, true);
        const { document } = parseHTML(html), grid = cells(rows(document.querySelector('table'))[1]);
        for (const cell of grid) {
            assert.equal(/display:inline-block/.test(cell.getAttribute('style') || ''), name.startsWith('rooster'));
        }
    });
}

for (const [name, change] of [
    ['missing grid display', ({ grid }) => remove(grid[0], 'display')],
    ['missing grid max-width', ({ grid }) => remove(grid[1], 'max-width')],
    ['missing grid box-sizing', ({ grid }) => remove(grid[2], 'box-sizing')],
    ['duplicate grid display', ({ grid }) => append(grid[0], 'display:inline-block')],
    ['duplicate grid max-width', ({ grid }) => append(grid[1], 'max-width:100%')],
    ['duplicate grid box-sizing', ({ grid }) => append(grid[2], 'box-sizing:border-box')],
    ['conflicting grid display', ({ grid }) => append(grid[0], 'display:block')],
    ['conflicting grid max-width', ({ grid }) => append(grid[1], 'max-width:99%')],
    ['conflicting grid box-sizing', ({ grid }) => append(grid[2], 'box-sizing:content-box')],
    ['inline-block moved into inner cell', ({ grid, inner }) => { remove(grid[0], 'display'); append(inner, 'display:inline-block'); }],
    ['foreign inline-block on author', ({ author }) => append(author, 'display:inline-block')],
    ['missing exact-height target', ({ exact }) => remove(exact[0], 'mso-line-height-rule')],
    ['duplicate exact-height target', ({ exact }) => append(exact[1], 'mso-line-height-rule:exactly')],
    ['conflicting exact-height value', ({ exact }) => append(exact[2], 'mso-line-height-rule:at-least')],
    ['nonliteral exact-height value', ({ exact }) => { remove(exact[3], 'mso-line-height-rule'); append(exact[3], 'mso-line-height-rule:exactly!important'); }],
    ['same four counts but exact-height moved to author', ({ exact, author }) => { remove(exact[0], 'mso-line-height-rule'); append(author, 'mso-line-height-rule:exactly'); }],
    ['same four counts but exact-height moved to inner cell', ({ exact, inner }) => { remove(exact[3], 'mso-line-height-rule'); append(inner, 'mso-line-height-rule:exactly'); }],
    ['foreign MSO property', ({ grid }) => append(grid[0], 'mso-padding-alt:0')],
    ['table-layout remains forbidden', ({ grid }) => append(grid[0], 'table-layout:fixed')],
    ['wrong exact-height corner position', ({ exact }) => exact[0].setAttribute('height', '9')],
]) {
    test('source-only admission rejects ' + name, () => {
        assert.throws(() => validateUniversalComposeDocument(mutate(change)), { code: 'U2E_SOURCE_INVALID' });
    });
}

test('C15 source admission is structural and does not trust the rt-col classes as authority', () => {
    const noClasses = mutate((_target, document) => {
        for (const cell of document.querySelectorAll('td.rt-col')) cell.removeAttribute('class');
    });
    validateUniversalComposeDocument(noClasses);
    assert.equal(current(noClasses.html).current, true);
});

test('received-body comparison still rejects altered geometry instead of trusting the new inline fallback', () => {
    for (const changed of [
        mutate(({ grid }) => grid[0].setAttribute('width', '259')),
        mutate(({ grid }) => append(grid[1], 'width:279px')),
        mutate(({ grid }) => grid[3].setAttribute('rowspan', '1')),
    ]) {
        const state = current(changed.html);
        assert.equal(state.current, false); assert.notEqual(state.checkpoint, 'source.invalid');
    }
});

test('C13 own material stays present but not current; quoted C13 material never becomes the own signature', () => {
    assert.throws(() => validateUniversalComposeDocument(c13), { code: 'U2E_SOURCE_INVALID' });
    const own = current(c13.html);
    assert.equal(own.present, true); assert.equal(own.current, false); assert.equal(own.version, c13.version);
    assert.equal(own.checkpoint, 'state.version');
    const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + c13.html.replaceAll('id="rt-sig-', 'id="x_x_rt-sig-') + '</blockquote>';
    const quotedOnly = '<html><body><p><br></p>' + quote + '</body></html>';
    assert.equal(current(quotedOnly, 'reply').present, false);
    const input = '<html><body>' + source.html + quote + '</body></html>', before = sha256(input);
    assert.equal(current(input, 'reply').current, true);
    assert.equal(sha256(input), before); assert.ok(input.includes(quote));
});

test('redaction retains only the finite border-box addition and still reproduces a real material difference', () => {
    const actual = mutate(({ grid }) => {
        grid[0].setAttribute('bgcolor', '#000000'); remove(grid[0], 'background-color'); append(grid[0], 'background-color:#000000');
    }).html;
    const fixture = universalRedactedReadbackFixture(source.html, actual);
    assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
    assert.match(fixture.expectedHtml, /box-sizing:border-box/);
    assert.doesNotMatch(JSON.stringify(fixture), /Fixture person|Fixture author|direct@example|https:\/\/mail\.example/);
    const expected = { ...signature, html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
    validateUniversalComposeDocument(expected);
    const replay = universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail');
    assert.equal(replay.current, false); assert.equal(replay.checkpoint, 'evidence.colors');
});
