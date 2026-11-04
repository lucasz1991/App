import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { universalSignatureHrefEqual, firstUniversalEvidenceDifference } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
function withLink(doc, href) { const { document } = parseHTML(doc.html); document.querySelector('a').setAttribute('href', href); return { ...doc, html: document.toString() }; }
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const evidence = href => ({ topology: [], images: [], links: [href], colors: [], borderColors: [], text: 'Unchanged signature' });

test('only a literal HTTPS root-path slash with identical authority bytes is equivalent in either direction', () => {
    for (const authority of ['www.example.test', 'EXAMPLE.test', 'example.test:443', 'example.test:0443', '127.0.0.1:8443']) {
        const empty = 'https://' + authority, slash = empty + '/';
        assert.equal(universalSignatureHrefEqual(empty, slash), true);
        assert.equal(universalSignatureHrefEqual(slash, empty), true);
    }
    for (const strict of ['mailto:person@example.test', 'tel:+491234', 'https://wa.me/491234', 'HTTP://EXAMPLE.test']) {
        assert.equal(universalSignatureHrefEqual(strict, strict), true, 'existing byte equality remains valid');
        assert.equal(universalSignatureHrefEqual(strict, strict + '/'), false);
    }
});

test('authority, scheme, port, credentials, query, fragment, path and malformed-authority changes remain unequal', () => {
    const denied = [
        ['http://example.test', 'http://example.test/'], ['HTTPS://example.test', 'HTTPS://example.test/'],
        ['https://EXAMPLE.test', 'https://example.test/'], ['https://example.test', 'https://other.test/'],
        ['https://example.test', 'https://example.test:443/'], ['https://example.test:0443', 'https://example.test:443/'],
        ['https://user@example.test', 'https://user@example.test/'], ['https://user:pass@example.test', 'https://user:pass@example.test/'],
        ['https://example.test?', 'https://example.test/?'], ['https://example.test#', 'https://example.test/#'],
        ['https://example.test?q=1', 'https://example.test/?q=1'], ['https://example.test#part', 'https://example.test/#part'],
        ['https://example.test/path', 'https://example.test/path/'], ['https://example.test/', 'https://example.test//'],
        ['https://example.test', 'https://example.test/%2f'], ['https://%65xample.test', 'https://%65xample.test/'],
        ['https://example.test', 'https://example.test\\'], ['https://example.test', ' https://example.test/'],
        ['https://.example.test', 'https://.example.test/'], ['https://example..test', 'https://example..test/'],
        ['https://-example.test', 'https://-example.test/'], ['https://example-.test', 'https://example-.test/'],
        ['https://example.test:65536', 'https://example.test:65536/'], ['https://999.0.0.1', 'https://999.0.0.1/'],
        ['https://' + 'a'.repeat(64) + '.test', 'https://' + 'a'.repeat(64) + '.test/'],
    ];
    for (const [before, after] of denied) {
        assert.equal(universalSignatureHrefEqual(before, after), false);
        assert.equal(universalSignatureHrefEqual(after, before), false);
    }
});

test('a final CR, LF, Unicode line separator, paragraph separator or other whitespace is never ignored', () => {
    for (const suffix of ['\r', '\n', '\r\n', '\u2028', '\u2029', '\t', ' ']) {
        assert.equal(universalSignatureHrefEqual('https://example.test', 'https://example.test/' + suffix), false);
        assert.equal(universalSignatureHrefEqual('https://example.test' + suffix, 'https://example.test/'), false);
        assert.equal(universalSignatureHrefEqual('https://example.test/\n', 'https://example.test\n'), false);
    }
});

test('current-state and first-difference comparison agree without changing either source or actual HTML', () => {
    for (const [before, after, current] of [
        ['https://root.example.test', 'https://root.example.test/', true],
        ['https://root.example.test/', 'https://root.example.test', true],
        ['https://root.example.test/path', 'https://root.example.test/path/', false],
        ['https://ROOT.example.test', 'https://root.example.test/', false],
    ]) {
        const expected = withLink(source, before), actual = withLink(expected, after), sourceBefore = JSON.stringify(expected), actualBefore = actual.html;
        validateUniversalComposeDocument(expected);
        const state = universalComposeStateFromBody(expected, actual.html, 'newMail');
        assert.equal(state.current, current);
        const difference = firstUniversalEvidenceDifference(evidence(before), evidence(after));
        assert.equal(difference === null, current);
        if (!current) {
            assert.equal(state.checkpoint, 'evidence.links');
            assert.equal(difference.difference.expected, 'sha256:' + hash(before) + ':' + before.length);
            assert.equal(difference.difference.actual, 'sha256:' + hash(after) + ':' + after.length);
        }
        assert.equal(JSON.stringify(expected), sourceBefore); assert.equal(actual.html, actualBefore);
        assert.match(expected.html, /href="https:\/\//); assert.ok(expected.html.includes(before)); assert.ok(actual.html.includes(after));
    }
});

test('approved link equality does not suppress subsequent color differences or normalize image evidence', () => {
    const expected = evidence('https://root.example.test'), actual = evidence('https://root.example.test/');
    expected.colors = ['#ffffff']; actual.colors = [''];
    const snapshot = JSON.stringify({ expected, actual }), difference = firstUniversalEvidenceDifference(expected, actual);
    assert.equal(difference.checkpoint, 'evidence.colors'); assert.equal(difference.difference.index, 0);
    assert.equal(difference.difference.expected, 'sha256:' + hash('#ffffff') + ':7');
    assert.equal(difference.difference.actual, 'sha256:' + hash('') + ':0');
    assert.equal(JSON.stringify({ expected, actual }), snapshot);
    expected.images = [{ src: 'https://image.example.test', width: 8, height: 8 }];
    actual.images = [{ src: 'https://image.example.test/', width: 8, height: 8 }];
    assert.equal(firstUniversalEvidenceDifference(expected, actual).difference.key, 'evidence.images.src');
});

test('the preserved legacy Word fixture remains noncurrent even when its root link is equivalent', () => {
    const directory = new URL('../Fixtures/mail/u2e-classic-word-2357/', import.meta.url);
    const originalSource = fs.readFileSync(new URL('source.json', directory), 'utf8');
    const originalActual = fs.readFileSync(new URL('actual.redacted.html', directory), 'utf8');
    assert.throws(() => validateUniversalComposeDocument(JSON.parse(originalSource)), { code: 'U2E_SOURCE_INVALID' });
    const before = withLink(source, 'https://root.example.test');
    const after = withLink({ html: originalActual }, 'https://root.example.test/');
    const state = universalComposeStateFromBody(before, after.html, 'newMail');
    assert.equal(state.present, true); assert.equal(state.kind, 'combined'); assert.equal(state.current, false);
    assert.equal(state.checkpoint, 'state.version');
    assert.equal(fs.readFileSync(new URL('source.json', directory), 'utf8'), originalSource);
    assert.equal(fs.readFileSync(new URL('actual.redacted.html', directory), 'utf8'), originalActual);
});
