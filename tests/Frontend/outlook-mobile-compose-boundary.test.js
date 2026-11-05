import test from 'node:test';
import assert from 'node:assert/strict';
import { currentComposeBodyHtml, mobileCurrentReplyBodyMode } from '../../resources/js/outlook-addin/compose-template.js';

const authored = '<div><br></div>';
const mobile = (prefix = '') => '<div id="' + prefix + 'ms-outlook-mobile-body-separator-line"><div><br></div></div>'
    + '<div id="' + prefix + 'ms-outlook-mobile-signature">Gesendet von Outlook für iOS <a href="https://aka.ms/example">Outlook</a></div>';
const hr = '<hr style="display: inline-block; width: 98%;" tabindex="-1">';
const header = prefix => '<div id="' + prefix + 'divRplyFwdMsg"><b>From:</b> private@example.invalid</div>';
const strictHeader = '<div><font><b>Von:</b> private@example.invalid<br><b>Gesendet:</b> yesterday<br><b>An:</b> secret@example.invalid<br><b>Betreff:</b> PRIVATE</font></div>';

test('C-K22 mobile separator and default signature remain editable before the exact HR/header quote boundary', () => {
    for (const prefix of ['', 'x_', 'x_x_', 'x_x_x_x_']) for (const type of ['reply', 'forward']) {
        const current = authored + mobile(prefix), quote = hr + header(prefix) + '<div>UNTOUCHED QUOTE</div>';
        assert.equal(currentComposeBodyHtml(current + quote, type), current);
        assert.equal((current + quote).slice(currentComposeBodyHtml(current + quote, type).length), quote);
    }
});

test('C-K22 paired native mobile HR and finite header labels can establish a missing header-id boundary', () => {
    assert.equal(currentComposeBodyHtml(authored + mobile() + hr + strictHeader + '<div>QUOTE</div>', 'forward'), authored + mobile());
    assert.equal(currentComposeBodyHtml(authored + mobile() + '<hr>' + strictHeader, 'reply'), authored + mobile());
});

test('C-K22 bare mobile anchors and arbitrary HRs never authorize an unknown reply/forward scope', () => {
    for (const html of [authored + mobile(), authored + '<hr>' + strictHeader, authored + mobile() + '<hr><div>Arbitrary text</div>',
        authored + mobile() + '<hr style="display:block;width:98%">' + strictHeader,
        authored + mobile() + '<hr width="98%">' + strictHeader,
        authored + mobile() + hr + strictHeader.replace('<b>An:</b>', '<b>Cc:</b>'),
        authored + mobile() + '<p>AUTHORED GAP</p>' + hr + strictHeader,
        authored + '<div id="ms-outlook-mobile-body-separator-line">AUTHORED</div>' + hr + strictHeader]) {
        assert.equal(currentComposeBodyHtml(html, 'reply'), null, html);
        assert.equal(currentComposeBodyHtml(html, 'forward'), null, html);
    }
});

test('C-K22 known marker IDs are parsed attributes, scoped outside hidden or quoted containers', () => {
    for (const html of [
        '<div data-id="x_divRplyFwdMsg">PRIVATE</div>', '<div title="id=\'divRplyFwdMsg\'">PRIVATE</div>',
        '<!-- <div id="divRplyFwdMsg"></div> -->', '<div id="x_x_x_x_x_divRplyFwdMsg">PRIVATE</div>',
        '<div hidden><div id="divRplyFwdMsg">PRIVATE</div></div>',
        '<div style="display:none"><div id="x_divRplyFwdMsg">PRIVATE</div></div>',
        '<table><tr><td><div id="divRplyFwdMsg">PRIVATE</div></td></tr></table>',
        '<head><div id="mail-editor-reference-message-container">PRIVATE</div></head>',
        '<div style="visibility:hidden"><hr id="stopspelling"></div>',
    ]) assert.equal(currentComposeBodyHtml(html, 'reply'), null, html);
    for (const marker of ['x_mail-editor-reference-message-container', 'x_x_divRplyFwdMsg']) {
        assert.equal(currentComposeBodyHtml(authored + '<div id="' + marker + '">QUOTE</div>', 'reply'), authored);
    }
    assert.equal(currentComposeBodyHtml(authored + '<hr id="x_stopspelling"><div>QUOTE</div>', 'forward'), authored);
});

test('C-K22 current-only scope requires documented versioned mobile replies, not forwards or Exchange versions', () => {
    const office = (hostName, hostVersion) => ({ context: { mailbox: { diagnostics: { hostName, hostVersion } } }, MailboxEnums: { BodyMode: { HostConfig: 1 } } });
    for (const host of ['OutlookIOS', 'OutlookAndroid']) {
        assert.equal(mobileCurrentReplyBodyMode(office(host, '4.2538.0'), 'reply'), 1);
        assert.equal(mobileCurrentReplyBodyMode(office(host, '4.2537.99'), 'reply'), null);
        assert.equal(mobileCurrentReplyBodyMode(office(host, '4.2538.0'), 'forward'), null);
    }
    assert.equal(mobileCurrentReplyBodyMode(office('OutlookWebApp', '99.9999.0'), 'reply'), null);
    assert.equal(mobileCurrentReplyBodyMode(office('OutlookIOS', ''), 'reply'), null);
});
