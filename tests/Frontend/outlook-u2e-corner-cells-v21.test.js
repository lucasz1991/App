import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const source = JSON.parse(fs.readFileSync(new URL('source.json', folder), 'utf8'));
const historical = JSON.parse(fs.readFileSync(new URL('source.c21-legacy.json', folder), 'utf8'));
const previous = JSON.parse(fs.readFileSync(new URL('legacy-c15/source.json', folder), 'utf8'));
const previousManifest = JSON.parse(fs.readFileSync(new URL('legacy-c15/manifest.json', folder), 'utf8'));
const newFiles = ['c01c48d975.png', '8a5901e027.png', '033a29c0b7.png', '097c3bb121.png'];
const oldFiles = ['8a9ee1e161.png', '4b1a2aaba8.png', 'b4b1fbea07.png', 'f1b083c20b.png'];
const hash = value => crypto.createHash('sha256').update(value).digest('hex');

test('the former C21 image-corner source remains an exact historical artifact after v2.2', () => {
    assert.equal(hash(fs.readFileSync(new URL('source.c21-legacy.json', folder), 'utf8').replaceAll('\r\n', '\n')),
        'c5f67411f0109a1d6ad67630bfc78dcd58a0c188596cc9ea63b527c15aa3bbf5');
    assert.equal(historical.version, 'da9b8f7e66f6cd549ecd8203');
    const { document } = parseHTML(historical.html);
    const images = [...document.querySelectorAll('.rt-dispo-corner img')];
    assert.deepEqual(images.map(image => image.getAttribute('src').split('/').at(-1)), newFiles);
    for (const image of images) assert.equal(image.parentElement.getAttribute('bgcolor'), '#e4002b');
    assert.throws(() => validateUniversalComposeDocument(historical), { code: 'U2E_SOURCE_INVALID' });
});

test('C15 source, signature, host serializations and manifest remain exact historical artifacts', () => {
    for (const name of Object.keys(previousManifest.artifacts)) {
        const input = fs.readFileSync(new URL('legacy-c15/' + name, folder), 'utf8').replaceAll('\r\n', '\n');
        assert.equal(hash(input), previousManifest.artifacts[name].sha256);
    }
    assert.equal(hash(fs.readFileSync(new URL('legacy-c15/manifest.json', folder), 'utf8').replaceAll('\r\n', '\n')),
        'f6856afb14cd1c7d0c17f0addc24ae46d848920a517f8f1fdaa9c28bca02465d');
});

test('both old image-corner versions stay present-not-current while quoted history stays byte-identical', () => {
    for (const old of [previous, historical]) {
        const state = universalComposeStateFromBody(source, old.html, 'newMail');
        assert.equal(state.present, true); assert.equal(state.current, false);
        assert.equal(state.version, old.version); assert.equal(state.checkpoint, 'state.version');
        const quote = '<div id="divRplyFwdMsg">Historical header</div><blockquote>' + old.html + '</blockquote>';
        const only = '<html><body><p><br></p>' + quote + '</body></html>';
        assert.equal(universalComposeStateFromBody(source, only, 'reply').present, false);
        const input = '<html><body>' + source.html + quote + '</body></html>', before = hash(input);
        assert.equal(universalComposeStateFromBody(source, input, 'reply').current, true);
        assert.equal(hash(input), before); assert.ok(input.includes(quote));
    }
});

test('redaction preserves both historical corner filename sets without admitting either old source', () => {
    for (const [old, files] of [[previous, oldFiles], [historical, newFiles]]) {
        const fixture = universalRedactedReadbackFixture(old.html, old.html);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        for (const file of files) assert.ok(fixture.html.includes('/m/' + file));
        assert.doesNotMatch(JSON.stringify(fixture), /Fixture author|direct@example|https:\/\/mail\.example/);
        assert.throws(() => validateUniversalComposeDocument(old), { code: 'U2E_SOURCE_INVALID' });
    }
});
