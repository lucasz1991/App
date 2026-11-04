import test from 'node:test';
import assert from 'node:assert/strict';
import { currentComposeBodyHtml } from '../../resources/js/outlook-addin/compose-template.js';

const prefix = '<html><body><div><p>Current &amp; unchanged 🚂</p>';
const suffix = '</div></body></html>';

test('the existing Outlook DIV reply marker accepts quoted and Word unquoted ID attributes', () => {
    for (const marker of [
        '<div id=divRplyFwdMsg>Quoted history</div>',
        '<div id="divRplyFwdMsg">Quoted history</div>',
        "<DIV ID='x_divRplyFwdMsg'>Quoted history</DIV>",
        '<div class=MsoNormal ID = divRplyFwdMsg style="border-top:solid gray 1pt">Quoted history</div>',
    ]) {
        for (const type of ['reply', 'forward']) {
            const body = prefix + marker + suffix;
            assert.equal(currentComposeBodyHtml(body, type), prefix);
            assert.equal(body.slice(prefix.length), marker + suffix, 'quote bytes are untouched');
        }
    }
});

test('lookalike values, data-id, titles, comments and raw text do not create the known Outlook boundary', () => {
    for (const candidate of [
        '<div data-id=divRplyFwdMsg>Quoted history</div>',
        '<div data-id="divRplyFwdMsg">Quoted history</div>',
        '<div id=divRplyFwdMsg-extra>Quoted history</div>',
        '<div id="prefix-divRplyFwdMsg">Quoted history</div>',
        '<span id=divRplyFwdMsg>Quoted history</span>',
        '<div title="<div id=divRplyFwdMsg>">Own text</div>',
        '<div title="id=\'divRplyFwdMsg\'">Own text</div>',
        '<!-- <div id="divRplyFwdMsg">Fake</div> -->',
        '<script>"<div id=divRplyFwdMsg>Fake</div>"</script>',
        '<title><div id=divRplyFwdMsg>Fake</div></title>',
        '<p>&lt;div id=divRplyFwdMsg&gt;Fake&lt;/div&gt;</p>',
    ]) assert.equal(currentComposeBodyHtml(prefix + candidate + suffix, 'reply'), null);
});

test('the first real known boundary preserves the complete preceding own prefix byte for byte', () => {
    const own = prefix + '<div title="id=\'divRplyFwdMsg\'">Own</div><!-- <div id=divRplyFwdMsg></div> -->';
    const quote = '<div id=divRplyFwdMsg>History<div id="divRplyFwdMsg">Older history</div></div>' + suffix;
    const body = own + quote;
    assert.equal(currentComposeBodyHtml(body, 'reply'), own);
    assert.equal(body.slice(currentComposeBodyHtml(body, 'reply').length), quote);
    assert.equal(currentComposeBodyHtml(body, 'newMail'), body);
    assert.equal(currentComposeBodyHtml(body, 'unknown'), null);
});

test('an unknown Word header, malformed document or duplicate ID remains unreadable', () => {
    for (const body of [
        prefix + '<div style="border-top:solid gray 1pt"><b>From:</b> Synthetic sender</div>' + suffix,
        prefix + '<div id=divRplyFwdMsg>Unclosed',
        prefix + '<div id=divRplyFwdMsg id=other>Ambiguous</div>' + suffix,
    ]) assert.equal(currentComposeBodyHtml(body, 'reply'), null);
});
