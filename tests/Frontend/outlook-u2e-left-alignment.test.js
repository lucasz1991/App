import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalDiagnosticReplayHtml } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const read = file => fs.readFileSync(new URL(file, folder), 'utf8');
const source = JSON.parse(read('source.json')), signature = JSON.parse(read('signature.json'));
const prior = JSON.parse(read('legacy-v26/source.json'));
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
function changed(callback) {
    const { document } = parseHTML(source.html); callback(document.querySelector('table'), document);
    return document.toString();
}

test('both current insertion kinds are left aligned without changing the former signature content', () => {
    for (const doc of [source, signature]) {
        validateUniversalComposeDocument(doc);
        const shell = parseHTML(doc.html).document.querySelector('table');
        assert.equal(shell.getAttribute('align'), 'left');
        assert.match(shell.getAttribute('style'), /(?:^|;)margin:0 auto 0 0;/);
        assert.match(shell.getAttribute('style'), /(?:^|;)float:none;/);
        assert.match(shell.getAttribute('style'), /(?:^|;)text-align:left;/);
        assert.doesNotMatch(shell.getAttribute('style'), /margin:0 auto;/);
    }
    for (const file of ['source.json', 'signature.json']) {
        const oldDocument = JSON.parse(read('legacy-v26/' + file)), currentDocument = JSON.parse(read(file));
        const projected = oldDocument.html.replaceAll(prior.version, source.version)
            .replace('class="rt-shell" align="center"', 'class="rt-shell" align="left"')
            .replace('margin:0 auto;', 'margin:0 auto 0 0;float:none;text-align:left;');
        assert.equal(currentDocument.html, projected, file + ': only root alignment and derived marker change');
    }
});

test('current Word Rooster and mobile signature projections read current with left layout', () => {
    for (const file of ['word.synthetic.html', 'rooster.synthetic.html']) {
        const state = universalComposeStateFromBody(source, read(file), 'newMail');
        assert.equal(state.current, true, file); assert.equal(state.kind, 'combined');
    }
    const ios = JSON.parse(read('ios.synthetic.redacted.json'));
    const expected = { ...signature, html: universalDiagnosticReplayHtml(ios.expectedHtml) };
    assert.equal(universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(ios.html), 'newMail', { host: 'OutlookIOS' }).current, true);
});

test('finite default-left and zero-margin serializations are accepted without accepting arbitrary offsets', () => {
    for (const style of ['width:1040px;margin:0;', 'width:780pt;margin-left:0pt;margin-right:0pt;',
        'width:1040px;margin:0 auto 0 0;', 'width:1040px;']) {
        const html = changed(shell => { shell.removeAttribute('align'); shell.setAttribute('style', style); });
        assert.equal(universalComposeStateFromBody(source, html, 'newMail').current, true, style);
    }
    for (const style of ['width:1040px;margin:0 auto;', 'width:1040px;margin-left:1px;',
        'width:1040px;margin-left:-1px;', 'width:1040px;float:left;', 'width:1040px;position:absolute;']) {
        const html = changed(shell => shell.setAttribute('style', style));
        assert.equal(universalComposeStateFromBody(source, html, 'newMail').current, false, style);
    }
});

test('explicit local left wins over host center while an implicit table cannot inherit center or right', () => {
    for (const alignment of ['center', 'right']) {
        assert.equal(universalComposeStateFromBody(source, '<div align="' + alignment + '">' + source.html + '</div>', 'newMail').current, true);
        const weak = changed(shell => shell.setAttribute('style', 'width:1040px;margin:0;float:none;'));
        assert.equal(universalComposeStateFromBody(source, '<div align="' + alignment + '">' + weak + '</div>', 'newMail').current, false, 'align=left with zero right margin cannot override host centering');
        const implicit = changed(shell => { shell.removeAttribute('align'); shell.setAttribute('style', 'width:1040px;'); });
        const state = universalComposeStateFromBody(source, '<div align="' + alignment + '">' + implicit + '</div>', 'newMail');
        assert.equal(state.current, false); assert.equal(state.checkpoint, 'shell.align');
    }
});

test('previous centered own drafts remain present noncurrent and quoted centered material remains opaque', () => {
    for (const file of ['source.json', 'word.synthetic.html', 'rooster.synthetic.html']) {
        const bytes = read('legacy-v26/' + file), html = file.endsWith('.json') ? JSON.parse(bytes).html : bytes;
        const before = sha(html), state = universalComposeStateFromBody(source, html, 'newMail');
        assert.equal(state.present, true); assert.equal(state.current, false); assert.equal(state.checkpoint, 'state.version');
        const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + html + '</blockquote>';
        assert.equal(universalComposeStateFromBody(source, '<p><br></p>' + quote, 'reply').present, false);
        assert.equal(universalComposeStateFromBody(source, source.html + quote, 'reply').current, true);
        assert.equal(sha(html), before);
    }
});
