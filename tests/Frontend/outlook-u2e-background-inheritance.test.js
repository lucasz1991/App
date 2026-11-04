import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { parseHTML } from 'linkedom';
import { validateUniversalComposeDocument, universalComposeStateFromBody } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalRedactedReadbackFixture, universalDiagnosticReplayHtml, validateUniversalRedactedFixture } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

// The existing fixture contains synthetic names, text and example.test URLs.
// No private Classic message or downloaded diagnostic pair is copied into Git.
const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback/source.json', import.meta.url), 'utf8'));
const whiteCells = ['.rt-col-person', '.rt-col-direct', '.rt-col-company', '.rt-col-dispo', '.rt-train-cell'];
function mutate(input, change) {
    const { document } = parseHTML(input);
    change(document);
    return document.toString();
}
function clearBackground(node) {
    assert.ok(node);
    node.removeAttribute('bgcolor');
    node.setAttribute('style', (node.getAttribute('style') || '').split(';')
        .filter(declaration => !/^\s*background(?:-color)?\s*:/i.test(declaration)).join(';'));
}
function background(node, value) {
    clearBackground(node);
    node.setAttribute('style', node.getAttribute('style') + ';background:' + value);
}
function state(actual, expected = source) {
    validateUniversalComposeDocument(expected);
    return universalComposeStateFromBody(expected, actual, 'newMail');
}
function normalizedWhite() {
    return mutate(source.html, document => {
        background(document.querySelector('.rt-shell'), 'white');
        for (const selector of whiteCells) clearBackground(document.querySelector(selector));
    });
}
function nestedWhiteSource() {
    return { ...source, html: mutate(source.html, document => {
        const cell = document.querySelector('.rt-col-person .rt-pad');
        background(cell.closest('table'), 'white');
        background(cell, '#ffffff');
    }) };
}

test('the baseline fixture is current and has synthetic contact and author content', () => {
    assert.equal(state(source.html).current, true);
    assert.match(source.html, /Fixture author/);
    assert.match(source.html, /mail\.example\.test/);
    assert.doesNotMatch(source.html, /rail-time\.de|it-technik@/);
});

for (const selector of whiteCells) {
    test('Word may omit the matching white background from ' + selector, () => {
        const actual = mutate(source.html, document => clearBackground(document.querySelector(selector)));
        assert.equal(state(actual).current, true);
    });
}

for (const value of ['white', '#fff', 'rgb(255,255,255)']) {
    test('all white material cells use the equivalent nearest shell background ' + value, () => {
        const actual = mutate(source.html, document => {
            background(document.querySelector('.rt-shell'), value);
            for (const selector of whiteCells) clearBackground(document.querySelector(selector));
        });
        assert.equal(state(actual).current, true);
    });
}

test('a nested material cell may use its own nearest table outside the Dispo region', () => {
    const expected = nestedWhiteSource();
    const actual = mutate(expected.html, document => clearBackground(document.querySelector('.rt-col-person .rt-pad')));
    assert.equal(state(actual, expected).current, true);
});

test('the same nearest-table rule applies to a legal cell when the source requires that table color', () => {
    const expected = { ...source, html: mutate(source.html, document => background(document.querySelector('.rt-shell'), '#e4e8ec')) };
    const actual = mutate(expected.html, document => clearBackground(document.querySelector('.rt-legal')));
    assert.equal(state(actual, expected).current, true);
});

for (const value of ['#000000', '#e4002b', '#e4e8ec']) {
    test('an explicit nonmatching white-cell background remains a mismatch: ' + value, () => {
        const actual = mutate(normalizedWhite(), document => background(document.querySelector('.rt-col-person'), value));
        assert.equal(state(actual).current, false);
    });
}

for (const value of ['transparent', 'linear-gradient(red,red)', 'inherit', '']) {
    test('an invalid or empty explicit cell style never falls back to the white table: ' + JSON.stringify(value), () => {
        const actual = mutate(normalizedWhite(), document => background(document.querySelector('.rt-col-person'), value));
        const result = state(actual);
        assert.equal(result.current, false);
        assert.equal(result.checkpoint, 'colors.conflict');
    });
}

test('an explicitly empty bgcolor attribute is invalid rather than absent', () => {
    const actual = mutate(normalizedWhite(), document => document.querySelector('.rt-col-person').setAttribute('bgcolor', ''));
    const result = state(actual);
    assert.equal(result.current, false);
    assert.equal(result.checkpoint, 'colors.conflict');
});

test('conflicting explicit style and bgcolor values remain invalid', () => {
    const actual = mutate(normalizedWhite(), document => {
        const cell = document.querySelector('.rt-col-person');
        cell.setAttribute('bgcolor', '#ffffff');
        cell.setAttribute('style', cell.getAttribute('style') + ';background-color:#000000');
    });
    assert.equal(state(actual).checkpoint, 'colors.conflict');
});

for (const nearest of ['blank', '#000000', 'transparent', 'empty-bgcolor']) {
    test('a nested cell never skips its ' + nearest + ' nearest table to reach a white grandparent', () => {
        const expected = nestedWhiteSource();
        const actual = mutate(expected.html, document => {
            const cell = document.querySelector('.rt-col-person .rt-pad'), table = cell.closest('table');
            clearBackground(cell);
            if (nearest === 'blank' || nearest === 'empty-bgcolor') clearBackground(table);
            else background(table, nearest);
            if (nearest === 'empty-bgcolor') table.setAttribute('bgcolor', '');
        });
        assert.equal(state(actual, expected).current, false);
    });
}

test('an invalid nearest table is consulted only when the cell actually needs its background', () => {
    const expected = nestedWhiteSource();
    const explicit = mutate(expected.html, document => background(document.querySelector('.rt-col-person .rt-pad').closest('table'), 'transparent'));
    assert.equal(state(explicit, expected).current, true);
    const absent = mutate(explicit, document => clearBackground(document.querySelector('.rt-col-person .rt-pad')));
    assert.equal(state(absent, expected).checkpoint, 'colors.conflict');
});

test('omitting white does not normalize the required legal gray, red panel or white corners', () => {
    for (const change of [
        document => clearBackground(document.querySelector('.rt-legal')),
        document => background(document.querySelector('.rt-legal'), '#ffffff'),
        document => background(document.querySelector('.rt-dispo'), '#ffffff'),
        document => clearBackground(document.querySelector('.rt-dispo-corner td[width="8"]')),
    ]) assert.equal(state(mutate(normalizedWhite(), change)).current, false);
});

test('redaction and diagnostic replay preserve the accepted nearest-table white evidence', () => {
    const fixture = universalRedactedReadbackFixture(source.html, normalizedWhite());
    assert.ok(fixture);
    assert.ok(validateUniversalRedactedFixture(fixture));
    assert.doesNotMatch(JSON.stringify(fixture), /Fixture author|Fixture person|mailto:|mail\.example\.test/);
    const expected = { ...source, kind: 'signature', html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
    assert.equal(state(universalDiagnosticReplayHtml(fixture.html), expected).current, true);
});

test('redaction does not erase a nonmatching explicit color or a blank nearest-table barrier', () => {
    const nested = nestedWhiteSource();
    const cases = [
        { expected: source, actual: mutate(normalizedWhite(), document => background(document.querySelector('.rt-col-person'), '#000000')) },
        { expected: nested, actual: mutate(nested.html, document => {
            const cell = document.querySelector('.rt-col-person .rt-pad');
            clearBackground(cell); clearBackground(cell.closest('table'));
        }) },
    ];
    for (const { expected: sourceDocument, actual } of cases) {
        const fixture = universalRedactedReadbackFixture(sourceDocument.html, actual);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        const expected = { ...sourceDocument, kind: 'signature', html: universalDiagnosticReplayHtml(fixture.expectedHtml) };
        assert.equal(state(universalDiagnosticReplayHtml(fixture.html), expected).current, false);
    }
});
