import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { universalComposeBoundaryFixture, universalRedactedReadbackFixture, u2eDiagnosticHash } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { universalFailurePayload } from '../../resources/js/outlook-addin/u2e-telemetry.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url)));
const raw = '<div><p>PRIVATE-AUTHOR</p></div><div id="unknown"><a href="mailto:private@example.test">PRIVATE-QUOTE</a></div>';
const fixture = universalComposeBoundaryFixture(source.html, raw);
const report = { code: 'U2E_NEW_DRAFT_REQUIRED', phase: 'preflight', step: 'snapshot.region', composeType: 'forward',
    sourceLength: source.html.length, bodyLength: raw.length, bodyLengthOrigin: 'original', fixture };
const error = { code: report.code };

function inHost(hostName, run) {
    const previous = globalThis.Office;
    globalThis.Office = { context: { mailbox: { diagnostics: { hostName } } } };
    try { return run(); } finally { globalThis.Office = previous; }
}

test('pilot boundary telemetry carries only validated inert topology for mobile and web preflight', () => {
    assert.ok(fixture);
    assert.equal(fixture.format, 'u2e-redacted-compose-boundary-v1');
    for (const host of ['OutlookIOS', 'OutlookAndroid', 'OutlookWebApp']) {
        const payload = inHost(host, () => universalFailurePayload(error, report, source.html.length, true));
        assert.deepEqual(payload.fixture, fixture);
        assert.doesNotMatch(JSON.stringify(payload), /PRIVATE-|private@example|mailto:|unknown/);
    }
});

test('fixture transport is refused outside the exact host, phase, step and context contract', () => {
    assert.equal(inHost('Outlook', () => universalFailurePayload(error, report, null, true)).fixture, undefined);
    for (const changes of [{ phase: 'write' }, { step: 'source.state' }, { composeType: 'newMail' }]) {
        assert.equal(inHost('OutlookIOS', () => universalFailurePayload(error, { ...report, ...changes }, null, true)).fixture, undefined);
    }
    assert.equal(inHost('OutlookIOS', () => universalFailurePayload(error, report, null, false)).fixture, undefined);
    const signaturePair = universalRedactedReadbackFixture(source.html, source.html);
    assert.equal(inHost('OutlookIOS', () => universalFailurePayload(error, { ...report, fixture: signaturePair }, null, true)).fixture, undefined);
    assert.equal(inHost('OutlookIOS', () => universalFailurePayload({ code: 'SIGNATURE_LAYOUT_UNCONFIRMED' },
        { ...report, code: 'SIGNATURE_LAYOUT_UNCONFIRMED' }, null, true)).fixture, undefined);
});

test('even a matching digest cannot smuggle body text through boundary telemetry', () => {
    const html = '<div data-rt-boundary="1"><p>PRIVATE-BODY</p></div>';
    const unsafe = { ...fixture, html, sha256: u2eDiagnosticHash(html) };
    assert.equal(inHost('OutlookIOS', () => universalFailurePayload(error, { ...report, fixture: unsafe }, null, true)).fixture, undefined);
});
