import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { parseHTML } from 'linkedom';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';

const folder = new URL('../Fixtures/mail/u2e-readback-v2/', import.meta.url);
const source = JSON.parse(fs.readFileSync(new URL('source.json', folder)));
const old = JSON.parse(fs.readFileSync(new URL('legacy-v22/source.json', folder)));

test('v2.3 changes exactly three inline nowrap declarations and its personal version', () => {
    assert.notEqual(source.version, old.version);
    assert.equal((source.html.match(/white-space:nowrap;/g) || []).length, 3);
    assert.equal(source.html.replaceAll(source.version, old.version).replaceAll('white-space:nowrap;', ''), old.html);
    validateUniversalComposeDocument(source);
});

for (const file of ['rooster.synthetic.html', 'word.synthetic.html']) {
    test('phone and 24/7 remain unbroken while the current material reader accepts ' + file, () => {
        const html = fs.readFileSync(new URL(file, folder), 'utf8');
        const { document } = parseHTML(html), dispatch = document.querySelector('table[width="247"]');
        const number = dispatch.querySelector('[class*="rt-cta-number"]');
        const time = dispatch.querySelector('[class*="rt-cta-time"]');
        for (const node of [number, number.closest('td'), time.closest('td')]) assert.match(node.getAttribute('style'), /white-space:nowrap/);
        assert.equal(number.closest('td').getAttribute('width'), '1');
        assert.deepEqual(Object.fromEntries(['present', 'current', 'kind'].map(key => [key, universalComposeStateFromBody(source, html, 'newMail')[key]])),
            { present: true, current: true, kind: 'combined' });
    });
}

test('old v2.2 remains present and noncurrent in an own draft and remains quoted in a reply', () => {
    const state = universalComposeStateFromBody(source, old.html, 'newMail');
    assert.equal(state.present, true); assert.equal(state.current, false);
    const reply = '<div><br></div><div id="divRplyFwdMsg"></div>' + old.html;
    const quoted = universalComposeStateFromBody(source, reply, 'reply');
    assert.equal(quoted.readable, true); assert.equal(quoted.present, false);
});
