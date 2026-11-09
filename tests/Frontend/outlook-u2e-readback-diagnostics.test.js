import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { parseHTML } from 'linkedom';
import { U2E_READBACK_CHECKPOINTS, U2E_READBACK_DIFFERENCE_KEYS, u2eDiagnosticHash, u2eSafeDifference,
    firstUniversalEvidenceDifference, universalRedactedReadbackFixture, validateUniversalRedactedFixture,
    universalDiagnosticReplayHtml } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { validateUniversalComposeDocument, universalComposeStateFromBody,
    universalComposeDiagnosticFixture } from '../../resources/js/outlook-addin/u2e-compose.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
const change = callback => { const { document } = parseHTML(source.html); callback(document); return document.toString(); };
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
function replaySource(fixture) { return validateUniversalComposeDocument({ ...source, kind: 'signature', html: universalDiagnosticReplayHtml(fixture.expectedHtml) }); }
function changeFixture(fixture, mutate) {
    const copy = { ...fixture, ...mutate(fixture) }; assert.ok(copy.html !== fixture.html, 'the adversarial mutation must change HTML');
    copy.sha256 = hash(copy.html); copy.expectedSha256 = hash(copy.expectedHtml); return copy;
}

test('diagnostic SHA-256 agrees with independent UTF-8 vectors including replacement characters', () => {
    for (const input of ['', 'abc', 'Grüße 🚂', '\ud800', 'x'.repeat(500000)]) assert.equal(u2eDiagnosticHash(input), hash(input));
});

test('difference metadata hashes every string and never exposes private keys, text, URLs or arbitrary objects', () => {
    const privateValue = 'PRIVATE-BODY employee@example.invalid https://private.invalid/?token=secret';
    const difference = u2eSafeDifference('evidence.text.sha256', privateValue, privateValue + '!', 4);
    assert.equal(difference.index, 4); assert.equal(difference.expected, 'sha256:' + hash(privateValue) + ':' + privateValue.length);
    assert.doesNotMatch(JSON.stringify(difference), /PRIVATE|employee|https|token/);
    assert.equal(u2eSafeDifference(privateValue, 1, 2), null);
    assert.deepEqual(u2eSafeDifference('quote.bytes', 0, true, 10000), { key: 'quote.bytes', index: null, expected: 0, actual: true });
});

test('first differing evidence key and values follow the original material comparison order', () => {
    const baseline = { topology: [{ parent: -1, rows: [['td', 1, 4]] }], images: [{ src: 'https://private.invalid/asset', width: 8, height: 8 }],
        links: ['mailto:private@example.invalid'], colors: ['#fff'], borderColors: ['#ffffff'], text: 'Private signature' };
    assert.equal(firstUniversalEvidenceDifference(baseline, structuredClone(baseline)), null);
    for (const [mutate, expectedKey] of [
        [actual => { actual.topology[0].parent = 0; }, 'evidence.topology'],
        [actual => { actual.images[0].src += 'changed'; actual.text = 'Other'; }, 'evidence.images.src'],
        [actual => { actual.links[0] += 'changed'; }, 'evidence.links.href'],
        [actual => { actual.colors[0] = '#000'; }, 'evidence.colors.value'],
        [actual => { actual.borderColors[0] = '#000000'; }, 'evidence.borderColors.value'],
        [actual => { actual.text += '!'; }, 'evidence.text.length'],
        [actual => { actual.text = 'Private signaturX'; }, 'evidence.text.sha256'],
    ]) {
        const actual = structuredClone(baseline); mutate(actual);
        const result = firstUniversalEvidenceDifference(baseline, actual);
        assert.equal(result.difference.key, expectedKey);
        assert.ok(U2E_READBACK_DIFFERENCE_KEYS.includes(result.difference.key));
        assert.doesNotMatch(JSON.stringify(result), /Private|private@|https:/);
    }
});

test('material failures identify the exact finite guard without admitting any damaged material', () => {
    const cases = [
        [dom => dom.querySelector('table').setAttribute('width', '900'), 'shell.width'],
        [dom => dom.querySelector('table').setAttribute('align', 'center'), 'shell.align'],
        [dom => dom.querySelector('table').setAttribute('style', 'display:none'), 'shell.hidden'],
        [dom => dom.querySelector('table').setAttribute('style', 'width:1040px;display:none'), 'shell.hidden'],
        [dom => dom.querySelector('td[width="280"]').setAttribute('width', '281'), 'grid.columns'],
        [dom => dom.querySelector('td[rowspan="2"]').setAttribute('rowspan', '1'), 'grid.rowspan'],
        [dom => dom.querySelector('img').setAttribute('src', 'data:image/png;base64,PRIVATE'), 'images.src'],
        [dom => dom.querySelector('img').setAttribute('width', '2'), 'images.width'],
        [dom => dom.querySelector('img').setAttribute('height', '2'), 'images.height'],
        [dom => dom.querySelector('td[width="280"]').setAttribute('style', 'float:left;width:280px'), 'material.float'],
        [dom => dom.querySelector('td[width="280"]').insertAdjacentHTML('beforeend', '<script>PRIVATE</script>'), 'material.content'],
        [dom => dom.querySelector('td[width="280"]').setAttribute('style', 'width:280px;background-image:url(https://private.invalid)'), 'colors.background-image'],
    ];
    for (const [mutate, checkpoint] of cases) {
        const state = universalComposeStateFromBody(source, change(mutate), 'newMail');
        assert.equal(state.current, false); assert.equal(state.checkpoint, checkpoint); assert.ok(U2E_READBACK_CHECKPOINTS.includes(state.checkpoint));
        assert.doesNotMatch(JSON.stringify(state), /PRIVATE|private\.invalid/);
    }
    assert.equal(universalComposeStateFromBody(source, '<table', 'newMail').checkpoint, 'html.parse');
});

test('redacted actual and expected signatures preserve structure/styles and deterministic URL equality', () => {
    const actual = change(dom => {
        const shell = dom.querySelector('table'); shell.setAttribute('style', 'width:780pt;margin:0;mso-table-lspace:0pt');
        const span = dom.querySelector('.rt-col-person span'); if (span) span.insertAdjacentHTML('beforeend', '<span></span>');
    });
    const fixture = universalComposeDiagnosticFixture(source, actual, 'newMail');
    assert.ok(fixture); assert.ok(Object.isFrozen(fixture));
    assert.ok(validateUniversalRedactedFixture(fixture));
    assert.match(fixture.html, /mso-table-lspace:0pt/);
    assert.equal((fixture.html.match(/rt-redacted(?=<)/g) || []).length, 5);
    assert.equal(universalComposeStateFromBody(replaySource(fixture), universalDiagnosticReplayHtml(fixture.html), 'newMail').current, true);
    const changed = universalComposeDiagnosticFixture(source, source.html.replaceAll('https://mail.example.test/m/', 'https://changed.invalid/m/'), 'newMail');
    const raw = universalComposeStateFromBody(source, source.html.replaceAll('https://mail.example.test/m/', 'https://changed.invalid/m/'), 'newMail');
    const replayed = universalComposeStateFromBody(replaySource(changed), universalDiagnosticReplayHtml(changed.html), 'newMail');
    assert.equal(raw.checkpoint, 'evidence.images'); assert.equal(replayed.checkpoint, raw.checkpoint); assert.equal(replayed.current, false);
});

test('redaction discards author text, external user content, quotes, raw URL/data attributes and CSS strings', () => {
    const dirty = change(dom => {
        dom.querySelector('tr').firstElementChild.innerHTML = '<p>PRIVATE-AUTHOR employee@private.invalid</p><img src="data:image/png;base64,PRIVATE-AUTHOR-DATA">';
        const cell = dom.querySelector('td[width="280"]');
        cell.setAttribute('style', 'font-family:"PRIVATE-FONT";background-image:url(https://private.invalid/?token=PRIVATE-TOKEN);color:#fff');
        cell.setAttribute('onclick', 'PRIVATE-EVENT'); cell.insertAdjacentHTML('beforeend', '<span title="PRIVATE-TITLE">PRIVATE-TEXT</span><script>PRIVATE-SCRIPT</script>');
        dom.querySelector('.rt-col-person img').setAttribute('src', 'data:image/png;base64,PRIVATE-IMAGE');
    });
    const body = '<p>PRIVATE-USER-TEXT</p>' + dirty + '<hr><div id="divRplyFwdMsg">PRIVATE-QUOTE</div>' + source.html;
    const fixture = universalComposeDiagnosticFixture(source, body, 'reply');
    assert.ok(fixture); assert.doesNotMatch(JSON.stringify(fixture), /PRIVATE|private\.invalid|data:image|onclick|<script|Fixture author|Fixture legal/);
    assert.match(fixture.html, /rt-redacted-data-[0-9a-f]{64}/);
    assert.match(fixture.html, /font-family:rt-redacted;background-image:rt-redacted/);
    assert.match(fixture.html, /data-rt-redacted="active"/);
});

test('quoted signatures remain excluded while an unknown header permits only text-free boundary topology', () => {
    const known = '<html><body><p><br></p><hr><div id="divRplyFwdMsg">Old header</div>' + source.html + '</body></html>';
    assert.equal(universalComposeDiagnosticFixture(source, known, 'reply'), null);
    const unknown = '<div style="border-top:solid #ddd 1px"><b>From:</b> private@example.invalid<br><b>Sent:</b> yesterday</div>' + source.html;
    const fixture = universalComposeDiagnosticFixture(source, unknown, 'reply');
    assert.ok(fixture); assert.equal(fixture.format, 'u2e-redacted-compose-boundary-v1');
    assert.ok(validateUniversalRedactedFixture(fixture));
    assert.equal(fixture.html.replace(/<[^>]*>/g, ''), '');
    assert.doesNotMatch(fixture.html, /private|example|From:|Sent:|yesterday|@|style=|class=|href=|src=/i);
});

test('bounded 56KB host-style expansion remains capturable and larger fixtures fail closed', () => {
    const { document } = parseHTML(source.html);
    let added = 0;
    for (const node of document.querySelectorAll('span')) {
        if (added > 60000) break;
        const style = ';' + 'mso-line-height-rule:exactly;'.repeat(110);
        node.setAttribute('style', (node.getAttribute('style') || '') + style); added += style.length;
    }
    const actual = document.toString(); assert.ok(actual.length >= 56686);
    const fixture = universalComposeDiagnosticFixture(source, actual, 'newMail');
    assert.ok(fixture); assert.ok(fixture.html.length + fixture.expectedHtml.length > 49152);
    assert.ok(JSON.stringify(fixture).length < 252000);
    const oversized = { ...fixture, html: fixture.html.repeat(10) };
    assert.equal(validateUniversalRedactedFixture(oversized), null);
});

test('diagnostic replay preserves bad URL schemes, dimensions and forbidden element failures', () => {
    for (const mutate of [
        dom => dom.querySelector('img').setAttribute('src', 'http://mail.example.test/m/b983af0049.png'),
        dom => dom.querySelector('img').setAttribute('width', 'PRIVATE-WIDTH'),
        dom => dom.querySelector('a').setAttribute('href', 'javascript:PRIVATE'),
        dom => dom.querySelector('td[width="280"]').insertAdjacentHTML('beforeend', '<style>PRIVATE-CSS</style>'),
    ]) {
        const actual = change(mutate), original = universalComposeStateFromBody(source, actual, 'newMail');
        const fixture = universalComposeDiagnosticFixture(source, actual, 'newMail');
        assert.ok(fixture);
        const replayed = universalComposeStateFromBody(replaySource(fixture), universalDiagnosticReplayHtml(fixture.html), 'newMail');
        assert.equal(original.current, false); assert.equal(replayed.current, false); assert.equal(replayed.checkpoint, original.checkpoint);
        assert.doesNotMatch(JSON.stringify(fixture), /PRIVATE|javascript:/);
    }
});

test('the telemetry copy validator rejects forged text/URLs, active HTML, extra fields and altered digests', () => {
    const fixture = universalRedactedReadbackFixture(source.html, source.html);
    assert.ok(fixture);
    for (const mutate of [
        value => ({ html: value.html.replace('rt-redacted<', 'PRIVATE-BODY<') }),
        value => ({ html: value.html.replace(/href="[^"]+"/, 'href="https://private.invalid/?token=secret"') }),
        value => ({ html: value.html.replace('<table ', '<table onclick="PRIVATE" ') }),
        value => ({ html: value.html.replace('rt-redacted<', 'rt-redacted<script>PRIVATE</script><') }),
        value => ({ html: value.html.replace('width:1040px', 'width:1040px;content:PRIVATE') }),
    ]) assert.equal(validateUniversalRedactedFixture(changeFixture(fixture, mutate)), null);
    assert.equal(validateUniversalRedactedFixture({ ...fixture, extra: '<p>PRIVATE</p>' }), null);
    assert.equal(validateUniversalRedactedFixture({ ...fixture, sha256: '0'.repeat(64) }), null);
    assert.equal(universalDiagnosticReplayHtml(source.html), null, 'replay refuses non-redacted HTML');
});
