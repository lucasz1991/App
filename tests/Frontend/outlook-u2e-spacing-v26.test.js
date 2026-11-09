import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalDiagnosticReplayHtml, validateUniversalRedactedFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const read = file => fs.readFileSync(new URL(file, folder), 'utf8').replaceAll('\r\n', '\n');
const json = file => JSON.parse(read(file));
const source = json('source.json'), signature = json('signature.json'), old = json('legacy-v25/source.json');
const manifest = json('manifest.json');
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const rows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
const authorCell = document => rows(document.querySelector('table[width="1040"]'))[0].querySelector('td');

function projectLeft(shell) {
    shell.setAttribute('align', 'left');
    shell.setAttribute('style', [...(shell.getAttribute('style') || '').split(';').filter(value => value && !/^margin(?:-left|-right)?:/i.test(value)), 'margin:0 auto 0 0', 'float:none', 'text-align:left'].join(';'));
}

test('C-K27 source pins current v2.6 and exercises the actual generated farewell with a legacy true name option', () => {
    assert.equal(manifest.templateSha256, '1bfd6e33fbc15a5e278ce29cf039721cedd9b4f0a2a5506c4012df3d5483207d');
    assert.equal(manifest.previousSpacingSource, 'legacy-v25/source.json');
    assert.equal(manifest.previousSpacingVersion, old.version); assert.notEqual(source.version, old.version);
    assert.deepEqual(manifest.wordingProjection, { generatedBy: 'OutlookUniversalPresentation::render', showGreeting: true,
        showFarewell: true, requestedFarewellName: true, appliedFarewellName: false, authoredNameBytesPreserved: true });
    const document = parseHTML(source.html).document, author = authorCell(document), paragraphs = [...author.querySelectorAll('p')];
    assert.equal(paragraphs.length, 3); assert.equal(paragraphs[0].textContent, 'Guten Tag,');
    assert.match(paragraphs[1].textContent, /^Fixture author:/);
    assert.equal(paragraphs[2].textContent, 'Mit freundlichen Grüßen,');
    assert.equal(paragraphs[2].querySelectorAll('br').length, 0);
    assert.doesNotMatch(author.textContent, /Fixture person/);
    assert.ok(document.querySelector('.rt-col-person').textContent.includes('Fixture person'), 'identity stays in signature');
    assert.match(author.getAttribute('style'), /padding:32px 40px 48px/);
    validateUniversalComposeDocument(source); validateUniversalComposeDocument(signature);
});

test('v2.6 changes no signature material beyond the derived version and preserves the prior flat no-line design', () => {
    const priorSignature = json('legacy-v25/signature.json');
    assert.equal(priorSignature.html.replaceAll(old.version, source.version).replace('class="rt-shell" align="center"', 'class="rt-shell" align="left"').replace('margin:0 auto;', 'margin:0 auto 0 0;float:none;text-align:left;'), signature.html);
    assert.equal((source.html.match(/white-space:nowrap;/g) || []).length, 3);
    assert.doesNotMatch(source.html, /rt-cta-line|colspan="5"/);
});

test('current Word and Rooster are explicit wording/spacing projections of unchanged previous material', () => {
    const expectedAuthor = authorCell(parseHTML(source.html).document);
    for (const file of ['word.synthetic.html', 'rooster.synthetic.html']) {
        const prior = parseHTML(read('legacy-v25/' + file).replaceAll(old.version, source.version)).document;
        const cell = authorCell(prior);
        cell.setAttribute('style', cell.getAttribute('style').replace('padding:32px 40px 30px', 'padding:32px 40px 48px'));
        cell.innerHTML = expectedAuthor.innerHTML;
        projectLeft(prior.querySelector('table'));
        assert.equal(prior.toString().trimEnd(), read(file).trimEnd());
        const state = universalComposeStateFromBody(source, read(file), 'newMail');
        assert.equal(state.current, true); assert.equal(state.kind, 'combined');
        const document = parseHTML(read(file)).document, author = authorCell(document);
        assert.equal(author.querySelectorAll('p')[2].textContent, 'Mit freundlichen Grüßen,');
        assert.doesNotMatch(author.textContent, /Fixture person/);
    }
});

test('synthetic redacted iOS changes marker only and remains current only under canonical iOS context', () => {
    const previous = json('legacy-v26/ios.synthetic.redacted.json'), current = json('ios.synthetic.redacted.json'), previousSource = json('legacy-v26/source.json');
    assert.ok(validateUniversalRedactedFixture(previous)); assert.ok(validateUniversalRedactedFixture(current));
    const dom = parseHTML(previous.html.replaceAll(previousSource.version, source.version)).document;
    projectLeft(dom.querySelector('table'));
    assert.equal(dom.toString().trimEnd(), current.html);
    assert.equal(manifest.iosProjection.nativeClientProof, false);
    assert.equal(manifest.iosProjection.basis, 'legacy-v26/ios.synthetic.redacted.json');
    const expected = { ...signature, html: universalDiagnosticReplayHtml(current.expectedHtml) };
    const actual = universalDiagnosticReplayHtml(current.html);
    assert.equal(universalComposeStateFromBody(expected, actual, 'newMail', { host: 'OutlookIOS' }).current, true);
    assert.equal(universalComposeStateFromBody(expected, actual, 'newMail', { host: 'OutlookAndroid' }).checkpoint, 'caps.structure');
});

test('LIVE v2.5 own drafts remain present/noncurrent and old quotes remain opaque', () => {
    for (const file of ['source.json', 'word.synthetic.html', 'rooster.synthetic.html']) {
        const content = read('legacy-v25/' + file), html = file.endsWith('.json') ? JSON.parse(content).html : content;
        const before = sha(html), state = universalComposeStateFromBody(source, html, 'newMail');
        assert.equal(state.present, true); assert.equal(state.current, false); assert.equal(state.checkpoint, 'state.version');
        const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + html + '</blockquote>';
        assert.equal(universalComposeStateFromBody(source, '<p><br></p>' + quote, 'reply').present, false);
        assert.equal(universalComposeStateFromBody(source, source.html + quote, 'reply').current, true);
        assert.equal(sha(html), before);
    }
});
