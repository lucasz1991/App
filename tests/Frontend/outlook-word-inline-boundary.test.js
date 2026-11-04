import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseManagedHtml } from '../../resources/js/outlook-addin/managed-template.js';
import { currentComposeBodyHtml } from '../../resources/js/outlook-addin/compose-template.js';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';

const directory = new URL('../Fixtures/mail/u2e-word-inline-boundary/', import.meta.url);
const html = fs.readFileSync(new URL('word-inline.redacted.html', directory), 'utf8').replace(/\r\n/g, '\n');
const hash = input => crypto.createHash('sha256').update(input).digest('hex');
const start = html.indexOf('<div class="word-header"');
const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback/source.json', import.meta.url), 'utf8'));
const replace = (before, after) => { assert.ok(html.includes(before)); return html.replace(before, after); };

test('the minimal structural fixture is immutable, synthetic and separate from private original hashes', () => {
    const manifest = JSON.parse(fs.readFileSync(new URL('manifest.json', directory), 'utf8'));
    assert.equal(hash(html), manifest.fixture.sha256);
    assert.equal(Buffer.byteLength(html), manifest.fixture.bytes);
    assert.equal(start, manifest.fixture.boundaryUtf16);
    assert.equal(manifest.nativeClientAcceptance, false);
    assert.equal(Object.hasOwn(manifest, 'originalHashes'), false);
    assert.doesNotMatch(html, /rail.?time|https?:|mailto:|tel:|@/i);
    assert.ok(start > 0);
    const anchors = parseManagedHtml(html).elements.filter(node => node.tag === 'a' && node.attrs.name === '_MailOriginal');
    assert.equal(anchors.length, 1);
});

test('the exact named A inside P and the proven Word header DIV form a reply/forward boundary', () => {
    for (const input of [
        html,
        '\ufeff' + html,
        html.replace(/\n/g, '\r\n'),
        html.replace('1.0pt', '1pt'),
        html.replace('border:none;border-top:solid #E1E1E1 1.0pt', 'BORDER : NONE ; BORDER-TOP : SOLID #e1e1e1 1pt'),
    ]) {
        const point = input.indexOf('<div class="word-header"');
        for (const type of ['reply', 'forward']) assert.equal(currentComposeBodyHtml(input, type), input.slice(0, point));
    }
});

test('Word marker text, comments, data attributes, other values and element types cannot create a boundary', () => {
    for (const input of [
        replace('<a name="_MailOriginal">', '<a data-name="_MailOriginal">'),
        replace('<a name="_MailOriginal">', '<a id="_MailOriginal">'),
        replace('<a name="_MailOriginal">', '<a name="_MailOriginal" href="https://example.invalid/">'),
        replace('<a name="_MailOriginal">', '<a name="_mailoriginal">'),
        replace('<a name="_MailOriginal">', '<a name="prefix_MailOriginal">'),
        replace('<a name="_MailOriginal">', '<a title="name=\'_MailOriginal\'">'),
        replace('<a name="_MailOriginal"><span>REDACTED HEADER</span></a>', '_MailOriginal'),
        replace('<a name="_MailOriginal"><span>REDACTED HEADER</span></a>', '<!-- <a name="_MailOriginal">FAKE</a> -->'),
        replace('<a name="_MailOriginal"><span>REDACTED HEADER</span></a>', '<span name="_MailOriginal">FAKE</span>'),
        replace('<a name="_MailOriginal"><span>REDACTED HEADER</span></a>', '<script>"<a name=\'_MailOriginal\'>FAKE</a>"</script>'),
    ]) assert.equal(currentComposeBodyHtml(input, 'reply'), null);
});

test('wrong, missing, conflicting, duplicate or overridden border styles remain unrecognized', () => {
    const original = 'border:none;border-top:solid #E1E1E1 1.0pt';
    for (const style of [
        'border:none;border-top:solid #E1E1E2 1.0pt',
        'border:none;border-top:solid #E1E1E1 2pt',
        'border:none;border-top:dashed #E1E1E1 1pt',
        'border-top:solid #E1E1E1 1pt',
        'border:none',
        'border-top:solid #E1E1E1 1pt;border:none',
        original + ';border-top:solid #E1E1E1 1pt',
        original + ';border-top:solid red 2pt',
        original + ';border:solid red 2pt',
        original + ';border-top-color:red',
    ]) assert.equal(currentComposeBodyHtml(replace(original, style), 'reply'), null);
});

test('hidden, head, table and wrong-parent contexts cannot make an owned region from a Word marker', () => {
    const header = parseManagedHtml(html).elements.find(node => node.tag === 'div' && node.attrs.class === 'word-header');
    const originalHeader = html.slice(header.start, header.end);
    for (const input of [
        replace('border:none;', 'display:none;border:none;'),
        replace('border:none;', 'visibility:hidden;border:none;'),
        replace('<div>\n<div class="word-header"', '<div style="display:none">\n<div class="word-header"'),
        replace('<p class="MsoNormal"><a name="_MailOriginal">', '<p class="MsoNormal"><span><a name="_MailOriginal">').replace('</a><span>REDACTED HEADER CONTINUATION', '</a></span><span>REDACTED HEADER CONTINUATION'),
        html.replace(originalHeader, '<table><tr><td>' + originalHeader + '</td></tr></table>'),
        '<html><head>' + originalHeader + '</head><body><p>Own text</p></body></html>',
    ]) assert.equal(currentComposeBodyHtml(input, 'reply'), null);
});

test('duplicate anchors, two headers, ambiguous matching ancestors and malformed markup fail closed', () => {
    const header = parseManagedHtml(html).elements.find(node => node.tag === 'div' && node.attrs.class === 'word-header');
    const originalHeader = html.slice(header.start, header.end);
    for (const input of [
        replace('</a><span>REDACTED HEADER CONTINUATION', '</a><a name="_MailOriginal">OTHER</a><span>REDACTED HEADER CONTINUATION'),
        html.replace(originalHeader, originalHeader + originalHeader),
        html.replace(originalHeader, '<div style="border:none;border-top:solid #E1E1E1 1pt">' + originalHeader + '</div>'),
        replace('<a name="_MailOriginal">', '<a name="_MailOriginal" name="other">'),
        html.replace('</div></body></html>', '</body></html>'),
    ]) assert.equal(currentComposeBodyHtml(input, 'reply'), null);
});

test('own content before the anchor or outside the single MsoNormal header paragraph is never absorbed into a quote', () => {
    const paragraph = '<p class="MsoNormal"><a name="_MailOriginal">';
    const headerEnd = 'REDACTED HEADER CONTINUATION</span></p>';
    for (const input of [
        replace(paragraph, '<p class="MsoNormal">OWN BEFORE ANCHOR<a name="_MailOriginal">'),
        replace(paragraph, 'OWN BEFORE PARAGRAPH' + paragraph),
        replace(paragraph, '<p class="MsoNormal">OWN PARAGRAPH</p>' + paragraph),
        replace(headerEnd, headerEnd + '<p class="MsoNormal">OWN AFTER PARAGRAPH</p>'),
        replace(headerEnd, headerEnd + 'OWN AFTER PARAGRAPH'),
        replace(paragraph, '<!-- OWN COMMENT BEFORE PARAGRAPH -->' + paragraph),
        replace(paragraph, '<p><a name="_MailOriginal">'),
        replace(paragraph, '<p class="Other"><a name="_MailOriginal">'),
    ]) assert.equal(currentComposeBodyHtml(input, 'reply'), null);
});

test('prefix and complete quote suffix are preserved exactly, including author bytes and following siblings', () => {
    const author = '<p data-own="1">OWN &amp; 🚂 <b>text</b><a href="https://author.example.invalid/path?q=1">link</a></p>\r\n';
    const input = html.slice(0, start) + author + html.slice(start);
    const before = hash(input), point = start + author.length;
    const prefix = currentComposeBodyHtml(input, 'reply');
    assert.equal(prefix, input.slice(0, point));
    assert.ok(prefix.includes(author));
    assert.equal(input.slice(prefix.length), input.slice(point));
    assert.ok(input.slice(prefix.length).includes('<p class="quoted-content">REDACTED QUOTED TEXT</p>'));
    assert.equal(hash(input), before);
});

test('region closes only its analysis copy and ignores a matching U2e shell in quoted siblings', () => {
    const quoted = html.replace('<p class="quoted-content">', source.html + '<p class="quoted-content">');
    const before = hash(quoted), state = universalComposeStateFromBody(source, quoted, 'reply');
    assert.equal(state.readable, true);
    assert.equal(state.present, false);
    assert.equal(state.current, false);
    assert.equal(hash(quoted), before);
    const owned = html.slice(0, start) + source.html + html.slice(start);
    const snapshot = JSON.stringify(source), actualBefore = hash(owned);
    validateUniversalComposeDocument(source);
    assert.equal(universalComposeStateFromBody(source, owned, 'reply').current, true);
    assert.equal(JSON.stringify(source), snapshot);
    assert.equal(hash(owned), actualBefore);
});

test('an earlier existing boundary stays unchanged before one or repeated older Word markers', () => {
    const prefix = '<html><body><p>OWN</p>';
    for (const history of [html, html + html]) {
        const quote = '<div id=divRplyFwdMsg>' + history + '</div></body></html>';
        assert.equal(currentComposeBodyHtml(prefix + quote, 'reply'), prefix);
    }
});

test('an earlier strict Word header excludes matching quoted U2e before a later legacy boundary', () => {
    const quote = source.html + '<div id=divRplyFwdMsg>REDACTED OLDER HISTORY</div>';
    const input = html.replace('<p class="quoted-content">', quote + '<p class="quoted-content">');
    const snapshot = hash(input), prefix = currentComposeBodyHtml(input, 'reply');
    assert.equal(prefix, input.slice(0, start));
    assert.equal(input.slice(prefix.length), input.slice(start));
    const state = universalComposeStateFromBody(source, input, 'reply');
    assert.equal(state.readable, true);
    assert.equal(state.present, false);
    assert.equal(state.current, false);
    assert.equal(hash(input), snapshot);
});

test('new-message and unknown-type behavior stays unchanged', () => {
    for (const input of [html, '<html><body>Malformed', '_MailOriginal']) {
        assert.equal(currentComposeBodyHtml(input, 'newMail'), input);
        assert.equal(currentComposeBodyHtml(input, 'unknown'), null);
    }
});
