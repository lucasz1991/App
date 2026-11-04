import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { parseHTML } from 'linkedom';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture, universalDiagnosticReplayHtml, u2eDiagnosticHash } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { validateUniversalComposeDocument, universalComposeStateFromBody } from '../../resources/js/outlook-addin/u2e-compose.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback/source.json', import.meta.url), 'utf8'));
function withoutOwnCenter(change = () => {}) {
    const { document } = parseHTML(source.html);
    const shell = document.querySelector('table[width="1040"]');
    shell.removeAttribute('align');
    shell.setAttribute('style', (shell.getAttribute('style') || '').split(';').filter(value => !/^\s*margin(?:-left|-right)?\s*:/i.test(value)).join(';'));
    change(shell, document);
    return document.toString();
}
const rehash = (fixture, html) => ({ ...fixture, html, sha256: u2eDiagnosticHash(html) });

test('accepted ancestor centering becomes one canonical wrapper without outside text or attributes', () => {
    const actual = '<div align="center" data-private="PRIVATE-ATTRIBUTE"><p>PRIVATE-BEFORE</p><div align="center">' + withoutOwnCenter() + '</div><p>PRIVATE-AFTER private@example.invalid</p></div>';
    const fixture = universalRedactedReadbackFixture(source.html, actual);
    assert.ok(fixture);
    assert.match(fixture.html, /^<div align="center"><table/);
    assert.equal((fixture.html.match(/<div align="center">/g) || []).length, 1);
    assert.doesNotMatch(JSON.stringify(fixture), /PRIVATE|private@example|data-private/);
    const expected = validateUniversalComposeDocument({ ...source, kind: 'signature', html: universalDiagnosticReplayHtml(fixture.expectedHtml) });
    assert.equal(universalComposeStateFromBody(expected, universalDiagnosticReplayHtml(fixture.html), 'newMail').current, true);
});

test('table and cell barriers or explicit centering disagreements never gain a wrapper', () => {
    for (const [prefix, suffix] of [['<div>', '</div>'], ['<div align="center"><div align="left">', '</div></div>'],
        ['<div align="center"><table><tr><td>', '</td></tr></table></div>'],
        ['<div align="center"><div style="margin-left:0">', '</div></div>'],
        ['<div align="center"><div style="float:left">', '</div></div>'],
        ['<div align="center"><div style="text-align:right">', '</div></div>'],
        ['<div align="center"><div style="position:absolute">', '</div></div>']]) {
        const fixture = universalRedactedReadbackFixture(source.html, prefix + withoutOwnCenter() + suffix);
        assert.ok(fixture); assert.match(fixture.html, /^<table/);
    }
    const conflict = universalRedactedReadbackFixture(source.html, '<div align="center">' + withoutOwnCenter(shell => shell.setAttribute('align', 'left')) + '</div>');
    assert.ok(conflict); assert.match(conflict.html, /^<table/);
});

test('the telemetry boundary rejects wrapper text, attributes, siblings and additional wrappers', () => {
    const fixture = universalRedactedReadbackFixture(source.html, '<div align="center">' + withoutOwnCenter() + '</div>');
    assert.ok(fixture);
    const shell = fixture.html.slice('<div align="center">'.length, -'</div>'.length);
    for (const html of ['<div align="left">' + shell + '</div>', '<div align="center" title="rt-redacted">' + shell + '</div>',
        '<div align="center">rt-redacted' + shell + '</div>', '<div align="center"><div align="center">' + shell + '</div></div>',
        '<div align="center">' + shell + '<p>rt-redacted</p></div>', '<div align="center">' + shell + '</div><p>rt-redacted</p>']) {
        assert.equal(validateUniversalRedactedFixture(rehash(fixture, html)), null);
    }
});

test('cell-only Word identifiers remain safe while conflicting or misplaced marker IDs are rejected', () => {
    const actual = withoutOwnCenter(shell => shell.removeAttribute('id'));
    const fixture = universalRedactedReadbackFixture(source.html, '<div align="center">' + actual + '</div>');
    assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
    const marker = source.marker;
    for (const html of [fixture.html.replace(marker + '-cell', 'rt-sig-u2-' + 'b'.repeat(24) + '-cell').replace('<table ', '<table id="' + marker + '" '),
        fixture.html.replace(marker + '-cell', 'PRIVATE-ID'), fixture.html.replace('width="280"', 'id="' + marker + '-cell" width="280"'),
        fixture.html.replace('id="' + marker + '-cell"', '')]) {
        assert.equal(validateUniversalRedactedFixture(rehash(fixture, html)), null);
    }
});
