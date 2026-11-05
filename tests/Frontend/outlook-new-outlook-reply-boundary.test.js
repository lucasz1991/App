import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { currentComposeBodyHtml } from '../../resources/js/outlook-addin/compose-template.js';

// Synthetic structural fixture only. No native mail, contact values or URLs.
const anchor = '<a name="_MailOriginal"></a>';
const blank = '<div style="font-family:Arial;font-size:12pt;color:rgb(0,0,0)">\r\n<br>\r\n</div>';
const separator = '<hr style="display: inline-block; width: 98%;">';
const header = '<div style="font-family:Arial;font-size:12pt;color:rgb(0,0,0)">\r\n'
    + '<b>Von:</b> REDACTED SENDER<br>\r\n<b>Gesendet:</b> REDACTED DATE<br>\r\n'
    + '<b>An:</b> REDACTED RECIPIENT<br>\r\n<b>Betreff:</b> REDACTED SUBJECT</div>';
const prefix = '<html><head></head><body><div><div><br></div>\r\n';
const quote = '<div><p>REDACTED QUOTED BODY</p><table id="x_x_rt-sig-u2-111111111111111111111111"><tr><td>REDACTED QUOTE</td></tr></table></div>';
const suffix = '</div></body></html>';
const build = (parts = {}) => prefix + (parts.anchor ?? anchor) + '\r\n' + (parts.blank ?? blank) + '\r\n'
    + (parts.separator ?? separator) + '\r\n' + (parts.header ?? header) + '\r\n' + quote + suffix;
const html = build();
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const replace = (old, next) => { assert.ok(html.includes(old)); return html.replace(old, next); };

test('the exact empty bookmark, blank DIV, 98 percent HR and bold German header delimit reply and forward', () => {
    for (const input of [html, '\ufeff' + html, html.replace(/\r\n/g, '\n'), build({ blank: '<div></div>\r\n' + blank }),
        build({ separator: '<hr style="WIDTH : 98%; DISPLAY : INLINE-BLOCK">' }),
        build({ anchor: '<a name=_MailOriginal></a>' })]) {
        const point = input.indexOf('<a name');
        for (const type of ['reply', 'forward']) assert.equal(currentComposeBodyHtml(input, type), input.slice(0, point));
    }
});

test('the original prefix, authored bytes and complete quote tail stay byte-exact', () => {
    const authored = '<p data-author="safe">OWN &amp; 🚂 <b>bold</b></p>\r\n';
    const input = html.replace(anchor, authored + anchor), point = input.indexOf(anchor), before = hash(input);
    const current = currentComposeBodyHtml(input, 'reply');
    assert.equal(current, input.slice(0, point));
    assert.ok(current.includes(authored));
    assert.equal(Buffer.compare(Buffer.from(input.slice(current.length)), Buffer.from(input.slice(point))), 0);
    assert.ok(input.slice(current.length).includes('x_x_rt-sig-u2-'));
    assert.ok(input.slice(current.length).endsWith(suffix));
    assert.equal(hash(input), before);
});

test('the sequence is recognized without any signature in the quote', () => {
    const input = html.replace(quote, '<p>REDACTED PLAIN QUOTE</p>');
    assert.equal(currentComposeBodyHtml(input, 'reply'), prefix);
});

test('the same exact sequence is recognized in an Office BODY fragment without a synthetic parent wrapper', () => {
    const tail = anchor + blank + separator + header + quote;
    for (const own of ['', '<div><br></div>\r\n', '<p>OWN &amp; 🚂</p>']) {
        const input = own + tail, before = hash(input);
        assert.equal(currentComposeBodyHtml(input, 'reply'), own);
        assert.equal(input.slice(currentComposeBodyHtml(input, 'reply').length), tail);
        assert.equal(hash(input), before);
    }
});

test('text, comments, title/data attributes, other names and other tags cannot impersonate the bookmark', () => {
    for (const fake of ['_MailOriginal', '<!-- ' + anchor + ' -->', '<span name="_MailOriginal"></span>',
        '<a data-name="_MailOriginal"></a>', '<a title="name=\'_MailOriginal\'"></a>', '<a id="_MailOriginal"></a>',
        '<a name="_mailoriginal"></a>', '<a name="prefix_MailOriginal"></a>',
        '<a name="_MailOriginal" href=""></a>', '<a name="_MailOriginal" href="#quote"></a>',
        '<a name="_MailOriginal">OWN CONTENT</a>']) assert.equal(currentComposeBodyHtml(build({ anchor: fake }), 'reply'), null, fake);
});

test('only direct, empty DIV siblings can connect the bookmark to the separator', () => {
    for (const fake of ['', '<br>', '<span><br></span>', '<div>OWN TEXT</div>', '<div><!-- OWN COMMENT --></div>',
        '<div><img></div>', '<div><div><br></div></div>', '<div style="background-image:url(fake)"><br></div>',
        '<div><br title="hidden"></div>', '<div class="unproved"><br></div>', '<div style="colorr"><br></div>', blank.repeat(9)]) {
        assert.equal(currentComposeBodyHtml(build({ blank: fake }), 'reply'), null, fake);
    }
    for (const join of ['OWN TEXT', '<!-- COMMENT -->', '<span></span>']) {
        assert.equal(currentComposeBodyHtml(replace(anchor + '\r\n', anchor + join + '\r\n'), 'reply'), null);
        assert.equal(currentComposeBodyHtml(replace(separator + '\r\n', separator + join + '\r\n'), 'reply'), null);
    }
});

test('missing, different, duplicated, overridden or ambiguous HR declarations never form a boundary', () => {
    for (const value of ['', 'display:block;width:98%', 'display:inline-block;width:99%', 'display:inline-block',
        'width:98%', 'display:inline-block;width:98%;width:98%', 'display:inline-block;width:98%;border:0',
        'display:inline-block!important;width:98%', 'display:inline-block;width:calc(98%)',
        'display:inline-block;/* comment */width:98%', 'display:inline-block;width:98%;display:none']) {
        assert.equal(currentComposeBodyHtml(build({ separator: '<hr style="' + value + '">' }), 'reply'), null, value);
    }
    assert.equal(currentComposeBodyHtml(build({ separator: '<div style="display:inline-block;width:98%"></div>' }), 'reply'), null);
    assert.equal(currentComposeBodyHtml(build({ separator: separator + separator }), 'reply'), null);
});

test('all four direct bold labels and the original field order are required', () => {
    for (const fake of [header.replace('<b>Von:</b>', '<strong>Von:</strong>'), header.replace('<b>Von:</b>', '<b>From:</b>'),
        header.replace('<b>Von:</b>', '<b><span>Von:</span></b>'), header.replace('<b>An:</b>', '<b>Betreff:</b>'),
        header.replace('<b>Betreff:</b>', '<b>Andere:</b>'), header.replace('<b>Von:</b>', 'OWN TEXT<b>Von:</b>'),
        header.replace('<b>Von:</b>', '<!-- <b>Von:</b> -->'), header.replace('</div>', '<p>OWN TEXT</p></div>'),
        header.replace('<br>\r\n<b>An:</b>', '<b>An:</b>'), '<div>' + header + '</div>']) {
        assert.equal(currentComposeBodyHtml(build({ header: fake }), 'reply'), null, fake);
    }
});

test('table, quote, head and hidden contexts never grant ownership around this bookmark', () => {
    const sequence = anchor + blank + separator + header;
    for (const replacement of ['<table><tr><td>' + sequence + '</td></tr></table>', '<blockquote>' + sequence + '</blockquote>',
        '<div hidden>' + sequence + '</div>', '<div aria-hidden="true">' + sequence + '</div>', '<div aria-hidden="TRUE">' + sequence + '</div>',
        '<div style="display:none">' + sequence + '</div>', '<div style="visibility:hidden">' + sequence + '</div>',
        '<div style="opacity:.0">' + sequence + '</div>', '<div style="opacity:0%">' + sequence + '</div>',
        '<div style="opacity:+0.0%">' + sequence + '</div>', '<div style="opacity:-.0%">' + sequence + '</div>']) {
        const input = prefix + replacement + quote + suffix;
        assert.equal(currentComposeBodyHtml(input, 'reply'), null, replacement);
    }
    assert.equal(currentComposeBodyHtml('<html><head>' + sequence + '</head><body>OWN</body></html>', 'reply'), null);
});

test('C-K22 recognizes a prefixed Outlook quote before any nested historical bookmark', () => {
    const input = prefix + '<div id="x_x_divRplyFwdMsg">' + anchor + blank + separator + header + '</div>' + quote + suffix;
    for (const composeType of ['reply', 'forward']) assert.equal(currentComposeBodyHtml(input, composeType), prefix);
});

test('duplicate anchors, duplicate attributes and malformed HTML fail closed', () => {
    for (const input of [replace(anchor, anchor + anchor), replace(anchor, '<a name="_MailOriginal" name="_MailOriginal"></a>'),
        replace(anchor, '<a name="_MailOriginal">'), html.replace('</body>', ''),
        replace(blank, blank + anchor + blank + separator + header)]) assert.equal(currentComposeBodyHtml(input, 'reply'), null);
});

test('the earliest proven boundary wins without crossing an already detected quote', () => {
    const later = html.replace(quote, '<div id=divRplyFwdMsg>OLDER QUOTE</div>' + quote);
    assert.equal(currentComposeBodyHtml(later, 'reply'), prefix);
    const earlier = prefix + '<div id=divRplyFwdMsg>' + anchor + blank + separator + header + quote + '</div>' + suffix;
    assert.equal(currentComposeBodyHtml(earlier, 'reply'), prefix);
    const crossed = replace(separator, '<div id=divRplyFwdMsg></div>' + separator);
    assert.equal(currentComposeBodyHtml(crossed, 'reply'), null);
});

test('the strict Classic Word form remains unchanged and is not confused with the new sibling form', () => {
    const word = fs.readFileSync(new URL('../Fixtures/mail/u2e-word-inline-boundary/word-inline.redacted.html', import.meta.url), 'utf8');
    const point = word.indexOf('<div class="word-header"');
    assert.ok(point > 0);
    assert.equal(currentComposeBodyHtml(word, 'reply'), word.slice(0, point));
    assert.equal(currentComposeBodyHtml(word, 'forward'), word.slice(0, point));
    assert.equal(currentComposeBodyHtml(html.replace(anchor, '<p class="MsoNormal">' + anchor + '</p>'), 'reply'), null);
});

test('new message and unknown compose types retain the existing behavior', () => {
    for (const input of [html, '<html>malformed', '_MailOriginal']) {
        assert.equal(currentComposeBodyHtml(input, 'newMail'), input);
        assert.equal(currentComposeBodyHtml(input, 'unknown'), null);
    }
});
