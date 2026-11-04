import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { u2eTopBorder } from '../../resources/js/outlook-addin/u2e-borders.js';
import { universalComposeStateFromBody, validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture, universalDiagnosticReplayHtml,
    firstUniversalEvidenceDifference, u2eDiagnosticHash } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
const signature = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/signature.json', import.meta.url), 'utf8'));
const folder = new URL('../Fixtures/mail/u2e-cta-border-0746/', import.meta.url);
const border = style => u2eTopBorder({ attrs: { style } });
const mutate = change => { const { document } = parseHTML(source.html); change(document); return document.toString(); };
const state = html => universalComposeStateFromBody(source, html, 'newMail');
function setTop(cell, declaration) {
    assert.ok(cell);
    cell.setAttribute('style', (cell.getAttribute('style') || '').split(';')
        .filter(value => !/^\s*(?:border(?:-top)?(?:-width|-style|-color)?|mso-border-top-alt)\s*:/i.test(value)).join(';') + ';' + declaration);
}
function replay(fixture) {
    return universalComposeStateFromBody({ ...source, kind: 'signature', html: universalDiagnosticReplayHtml(fixture.expectedHtml) },
        universalDiagnosticReplayHtml(fixture.html), 'newMail');
}

test('white px and Word pt forms retain real dimensions instead of pretending one pt equals one px', () => {
    assert.deepEqual(border('border-top:1px solid #fff'), { width: 1, style: 'solid', color: '#ffffff' });
    assert.deepEqual(border('border:none;border-top:solid white 1.0pt'), { width: 4 / 3, style: 'solid', color: '#ffffff' });
    assert.deepEqual(border('border-top:.75pt solid rgb(255, 255, 255)'), { width: 1, style: 'solid', color: '#ffffff' });
    assert.equal(border('BORDER-TOP: SOLID RGB(0, 0, 0) 1PX').color, '#000000', 'black is evidence, never repaired');
});

test('component shorthands select the top of one to four values and obey order and importance', () => {
    const cases = [
        ['border-width:1px 2px 3px 4px;border-style:solid dashed dotted double;border-color:white black red blue', '#ffffff'],
        ['border-top:1px solid white;border-top-color:black', '#000000'],
        ['border-top-color:black;border-top:1px solid white', '#ffffff'],
        ['border-top:1px solid white!important;border-top-color:black', '#ffffff'],
        ['border-top:1px solid white;border-top-color:black!important', '#000000'],
        ['border-top-color:black!important;border:1px solid white', '#000000'],
        ['border:0;border-top:1px solid white', '#ffffff'],
    ];
    for (const [style, color] of cases) assert.equal(border(style).color, color);
});

for (const declaration of ['', 'border-top:0 solid white', 'border-top:none', 'border-top:1px white',
    'border-top:solid white', 'border-top:1px dashed white', 'border-top:-1px solid white',
    'border-top:NaNpx solid white', 'border-top:1000001px solid white', 'border-top:1px solid transparent',
    'border-top:1px solid currentcolor', 'border-top:var(--private)', 'border-top:calc(1px) solid white',
    'border-top:1px solid white;border:none', 'border-top:1px solid white;border-top-style:none']) {
    test('incomplete or invisible CTA border is rejected: ' + JSON.stringify(declaration), () => {
        assert.throws(() => border(declaration), error => error.checkpoint === 'borders.invalid');
    });
}

test('Word alternate border must be positive solid and the same color without forcing width equality', () => {
    assert.equal(border('border-top:1px solid white;mso-border-top-alt:solid white 1pt').width, 1);
    for (const value of ['solid black 1pt', 'dashed white 1pt', 'solid white 0pt', 'solid transparent 1pt']) {
        assert.throws(() => border('border-top:1px solid white;mso-border-top-alt:' + value), error => error.checkpoint === 'borders.invalid');
    }
});

test('border images and unproved logical edge or Word overrides cannot hide a top-edge change', () => {
    for (const property of ['border-image', 'border-image-source', 'border-image-slice']) {
        assert.throws(() => border('border-top:1px solid white;' + property + ':url(https://private.invalid)'), error => error.checkpoint === 'borders.image');
    }
    for (const declaration of ['border-block-start:1px solid black', 'border-inline-start-color:black', 'mso-border-alt:solid black 1pt']) {
        assert.throws(() => border('border-top:1px solid white;' + declaration), error => error.checkpoint === 'borders.invalid');
    }
});

test('each of the four red grid edges is compared in structural order in the new template', () => {
    assert.equal(state(source.html).current, true);
    for (let index = 0; index < 4; index++) {
        const actual = mutate(document => {
            const cells = [...document.querySelectorAll('.rt-col')];
            assert.equal(cells.length, 4);
            setTop(cells[index], 'border-top:1px solid #000000');
        });
        const result = state(actual);
        assert.throws(() => validateUniversalComposeDocument({ ...source, html: actual }), { code: 'U2E_SOURCE_INVALID' });
        assert.equal(result.current, false);
        assert.equal(result.checkpoint, 'evidence.borderColors');
        assert.equal(result.difference.key, 'evidence.borderColors.value');
        assert.equal(result.difference.index, index);
    }
});

test('the new line and borderless CTA roles survive synthetic Word class removal', () => {
    const actual = mutate(document => {
        for (const cell of document.querySelectorAll('.rt-col')) setTop(cell, 'border:none;border-top:solid #E4002B 2pt');
        for (const cell of document.querySelectorAll('.rt-cta-cell')) {
            setTop(cell, 'border:none');
            cell.removeAttribute('class');
        }
        const line = document.querySelector('.rt-cta-line td');
        line.setAttribute('height', '.75pt');
        line.setAttribute('style', line.getAttribute('style').replaceAll('height:1px', 'height:.75pt').replace('font-size:1px', 'font-size:1pt'));
    });
    assert.equal(state(actual).current, true);
    const changedWidth = mutate(document => setTop(document.querySelector('.rt-col'), 'border-top:3px solid #e4002b'));
    assert.equal(state(changedWidth).current, true, 'C-K10 compares color, with a separate positive-width guard');
    const missingOne = mutate(document => setTop(document.querySelectorAll('.rt-cta-cell')[4], ''));
    assert.equal(state(missingOne).current, true, 'the CTA now intentionally has no top border');
    const missingCell = mutate(document => document.querySelectorAll('.rt-cta-cell')[4].remove());
    assert.equal(state(missingCell).current, false);
    assert.equal(state(missingCell).checkpoint, 'borders.structure');
});

test('the original received black-border fixture stays intact and is rejected as a legacy template version', () => {
    const bytes = fs.readFileSync(new URL('received-0746.redacted.json', folder), 'utf8');
    const fixture = JSON.parse(bytes), manifest = JSON.parse(fs.readFileSync(new URL('manifest.json', folder), 'utf8'));
    assert.equal(crypto.createHash('sha256').update(bytes.replaceAll('\r\n', '\n')).digest('hex'), manifest.fixtureFileSha256);
    assert.ok(validateUniversalRedactedFixture(fixture));
    assert.doesNotMatch(fixture.html + fixture.expectedHtml, /https?:\/\/|mailto:|tel:|@/);
    assert.equal(manifest.authorAndQuoteExcluded, true);
    const version = manifest.version;
    const legacy = { ...source, kind: 'signature', html: universalDiagnosticReplayHtml(fixture.expectedHtml),
        version, marker: 'rt-sig-u2-' + version, header: 'u2e-' + version };
    assert.throws(() => validateUniversalComposeDocument(legacy), { code: 'U2E_SOURCE_INVALID' });
    const result = universalComposeStateFromBody(signature, universalDiagnosticReplayHtml(fixture.html), 'newMail');
    assert.equal(result.present, true); assert.equal(result.current, false);
    assert.equal(result.checkpoint, 'state.version');
    assert.equal(result.version, version);
});

test('diagnostic replay retains top longhands, important cascade, image rejection and Word alternate color', () => {
    for (const [declaration, checkpoint] of [
        ['border-top:1px solid white;border-top-color:black', 'borders.cta'],
        ['border-top:1px solid white;border-top-width:2px', 'borders.cta'],
        ['border-top:1px solid white;border-top-style:solid', 'borders.cta'],
        ['border-top:1px solid white;mso-border-top-alt:solid black 1pt', 'borders.cta'],
        ['border-top:1px solid white;border-image-source:url(https://private.invalid)', 'borders.image'],
    ]) {
        const actual = mutate(document => setTop(document.querySelector('.rt-cta-cell'), declaration));
        const raw = state(actual), fixture = universalRedactedReadbackFixture(source.html, actual);
        assert.equal(raw.current, false); assert.equal(raw.checkpoint, checkpoint);
        assert.ok(fixture); assert.ok(validateUniversalRedactedFixture(fixture));
        assert.doesNotMatch(JSON.stringify(fixture), /private\.invalid/);
        const restored = replay(fixture);
        assert.equal(restored.current, false); assert.equal(restored.checkpoint, checkpoint);
    }
});

test('finite first-difference metadata also rejects missing or shortened border color evidence', () => {
    const expected = { topology: [], images: [], links: [], colors: [], borderColors: ['#e4002b', '#ffffff'], text: '' };
    const missing = { ...expected }; delete missing.borderColors;
    const short = { ...expected, borderColors: ['#e4002b'] };
    for (const actual of [missing, short]) {
        const result = firstUniversalEvidenceDifference(expected, actual);
        assert.equal(result.checkpoint, 'evidence.borderColors');
        assert.equal(result.difference.key, 'evidence.borderColors.count');
    }
});
