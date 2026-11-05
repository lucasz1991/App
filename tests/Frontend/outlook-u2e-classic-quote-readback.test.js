import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { createHash } from 'node:crypto';
import { compareClassicQuoteReadback } from '../../resources/js/outlook-addin/u2e-quote-readback.js';
import { currentComposeBodyHtml } from '../../resources/js/outlook-addin/compose-template.js';

const folder = new URL('../Fixtures/mail/u2e-quote-readback/', import.meta.url);
const before = fs.readFileSync(new URL('word-before.synthetic.html', folder), 'utf8');
const after = fs.readFileSync(new URL('word-after.synthetic.html', folder), 'utf8');
const context = { host: 'Outlook', phase: 'readback', step: 'readback.quote', composeType: 'reply',
    bodyWriteStarted: true, bodyWriteSucceeded: true };
const equal = (left, right, options = context) => compareClassicQuoteReadback(left, right, options).equal;
const hash = value => createHash('sha256').update(value).digest('hex');

test('byte equality remains the default for every host and phase', () => {
    for (const host of ['Outlook', 'OutlookWebApp', 'OutlookIOS', 'OutlookAndroid', 'unknown']) {
        assert.deepEqual(compareClassicQuoteReadback(before, before, { host, phase: 'preflight' }),
            { equal: true, mode: 'bytes', reason: null });
    }
    assert.equal(equal('', ''), true);
});

test('only Classic after a confirmed write may equate lexical HTML serializations', () => {
    assert.notEqual(before, after);
    assert.deepEqual(compareClassicQuoteReadback(before.replaceAll('\n', '\r\n'), after, context),
        { equal: true, mode: 'classic-word-serialization', reason: null });
    assert.equal(equal(before, after, { ...context, composeType: 'forward' }), true);
    for (const change of [{ host: 'OutlookWebApp' }, { host: 'OutlookIOS' }, { host: 'OutlookAndroid' },
        { host: 'outlook' }, { host: null }, { phase: 'preflight' }, { step: 'readback.material' },
        { composeType: 'newMail' }, { bodyWriteStarted: false }, { bodyWriteSucceeded: false },
        { bodyWriteSucceeded: undefined }]) {
        assert.equal(equal(before, after, { ...context, ...change }), false);
    }
    assert.equal(equal(before, after, {}), false);
});

test('every quoted text character and meaningful whitespace is preserved', () => {
    for (const [needle, replacement] of [['Unchanged quote', 'Changed quote'], ['with spacing.', 'with  spacing.'],
        ['text&nbsp;', 'text '], ['Fixture Sender', 'Fixture Receiver'], ['Fixture</p>', 'Fixture extra</p>']]) {
        const changed = before.replace(needle, replacement);
        assert.ok(changed !== before, needle);
        assert.equal(equal(before, changed), false, needle);
    }
    // HTML5 named references are case-sensitive; mixed-case aliases are not
    // guessed. Numeric CR and literal source newline are distinct as well.
    assert.equal(equal('<p>&Amp;</p>', '<p>&amp;</p>'), false);
    assert.equal(equal('<p>&NBSP;</p>', '<p>&nbsp;</p>'), false);
    assert.equal(equal('<p>&#13;</p>', '<p>\n</p>'), false);
    assert.equal(equal('<p>&#128;</p>', '<p>\u0080</p>'), false);
});

test('all link and image targets, order and dimensions stay exact after entity decoding', () => {
    for (const [needle, replacement] of [['?a=1&amp;b=2', '?a=1&amp;b=3'],
        ['mailto:sender@example.invalid', 'mailto:other@example.invalid'], ['tel:+49123456789', 'tel:+49123456780'],
        ['https://example.invalid/logo.png', 'https://example.invalid/other.png'], ['width=184', 'width=185'],
        ['id=image001', 'id=image001-new']]) {
        const changed = before.replace(needle, replacement);
        assert.ok(changed !== before, needle); assert.equal(equal(before, changed), false, needle);
    }
    const swapped = before.replace('logo.png', 'temporary.png').replace('phone.png', 'logo.png').replace('temporary.png', 'phone.png');
    assert.equal(equal(before, swapped), false);
    assert.equal(equal('<a href="https://example.invalid">Link</a>', '<a href="https://example.invalid/">Link</a>'), false);
});

test('an adjacent slash stays part of an unquoted HTTPS, HTTP or CID image target', () => {
    for (const target of ['https://example.invalid/path', 'http://example.invalid/path', 'cid:fixture-logo']) {
        assert.equal(equal('<img src=' + target + '/>', '<img src=' + target + '>'), false, target);
        assert.equal(equal('<img src=' + target + '>', '<img src=' + target + '/>'), false, target);
        assert.equal(equal('<img src="' + target + '"/>', '<img src="' + target + '">'), true, target);
        assert.equal(equal('<img src=' + target + ' />', '<img src=' + target + '>'), true, target);
    }
});

test('style values, declaration order, duplicate overrides and CSS strings are never discarded', () => {
    for (const [left, right] of [
        ['color:red;color:blue', 'color:blue;color:red'],
        ['font-size:11pt', 'font-size:12pt'], ['display:block', 'display:none'],
        ['white-space:normal', 'white-space:nowrap'], ['font-family:"A B"', 'font-family:"A  B"'],
        ['mso-line-height-rule:exactly', 'mso-line-height-rule:at-least'],
        ['color:red!important', 'color:red'], ['--Name:red', '--name:red'],
        ['color:/*preserved*/red', 'color:red'], ['font-family:A\\ B', 'font-family:A B'],
    ]) assert.equal(equal('<p style=\'' + left + '\'>Text</p>', '<p style=\'' + right + '\'>Text</p>'), false, left);
    assert.equal(equal('<p style="color:red;;">Text</p>', '<p STYLE=" COLOR : red ; ">Text</p>'), true);
});

test('full structure, classes, bookmarks, comments and raw styles remain part of the evidence', () => {
    for (const [needle, replacement] of [['class=MsoNormal', 'class=Other'], ['name="_MailOriginal"', 'name="_MailChanged"'],
        ['<b>Von:</b>', '<b><span>Von:</span></b>'], ['<td width=260>', '<td width=260 colspan=2>'],
        ['Preserved field contents', 'Changed field contents'], ['<o:p>', '<o:P>'], ['</o:p>', '</o:P>']]) {
        const changed = before.replace(needle, replacement);
        assert.ok(changed !== before, needle); assert.equal(equal(before, changed), false, needle);
    }
    assert.equal(equal('<style>.q{color:red}</style><p>T</p>', '<style>.q{color:blue}</style><p>T</p>'), false);
    assert.equal(equal('<p>T</p><!--keep-->', '<p>T</p><!--changed-->'), false);
    assert.equal(equal('<!doctype html><p>T</p>', '<!doctype different><p>T</p>'), false);
});

test('malformed or unknown fragment boundaries fail closed; no arbitrary outer tags are removed', () => {
    for (const changed of [before.replace('</td>', '</div>'), before.replace('width=1040', 'width=1040 WIDTH=1040'),
        before + '</td>', before.replace('</body>', ''), '<p>Unclosed', '<p><script>unterminated']) {
        assert.equal(equal(before, changed), false);
    }
    assert.equal(equal(null, null), false);
    assert.equal(equal('x'.repeat(500001), 'x'.repeat(500000) + 'y'), false);
});

const privateBase = new URL('../../.lmzdev/artifacts/temp/codex-uebergabe-signatur/belege/umsetzung/C/pruefung-claude-0411/', import.meta.url);
const nativeFiles = [
    ['Classic compose19:35', 'ck21-ecken-1745/empfang/verfassen-classic-pilot-antwort.html', 23313],
    ['Classic compose17:54', 'ck19-transportregel-1330/empfang/verfassen-classic-v10-antwort.html', 23313],
    ['Classic old compose23:39', 'classic-antwort/Ent-1103-2339-AW-Test-aus-Desktop-Outlook-ohne-einf-gung-da-automatisc.html', null],
];
for (const [label, name, length] of nativeFiles) {
    const location = new URL(name, privateBase);
    test('optional private actual Word quote with explicitly synthetic lexical variant: ' + label,
        { skip: !fs.existsSync(location) }, () => {
            const html = fs.readFileSync(location, 'utf8');
            const own = currentComposeBodyHtml(html, 'reply');
            assert.equal(typeof own, 'string');
            const trailingHr = /<hr\b[^>]*>\s*$/i.exec(own);
            const quote = html.slice(trailingHr ? trailingHr.index : own.length);
            if (length !== null) {
                assert.equal(quote.length, length);
                assert.equal(hash(quote), 'd71fa89552a3f6faa54db648c3d32264101441b4cf7d38e0be496c7e30c41178');
            }
            const lexicalVariant = quote.replace(/class=MsoNormal\b/, 'CLASS="MsoNormal"');
            assert.ok(lexicalVariant !== quote, 'Word fixture needs an ordinary paragraph class');
            assert.equal(equal(quote, lexicalVariant), true);
            const textChanged = quote.replace('Von:', 'Vxn:');
            assert.ok(textChanged !== quote, 'Word fixture needs its real header label');
            assert.equal(equal(quote, textChanged), false);
            const replacedLink = quote.replace(/\bhref=(?:"[^"]*"|'[^']*'|[^\s>]+)/i, 'href="https://changed.invalid/"');
            const linkChanged = replacedLink !== quote ? replacedLink
                : quote.replace('</body>', '<a href="https://changed.invalid/">Inserted link</a></body>');
            assert.ok(linkChanged !== quote, 'Word fixture needs a replaceable target or body tail');
            assert.equal(equal(quote, linkChanged), false);
        });
}
