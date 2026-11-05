import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import crypto from 'node:crypto';
import { assertUniversalBundleCompatibility, universalBundleFailureReport, U2E_SOURCE_TEMPLATE, U2E_READER_CONTRACTS } from '../../resources/js/outlook-addin/u2e-bundle-version.js';
import { U2E_BUNDLE_SESSION_KEY, persistUniversalBundleFailure, readUniversalBundleFailure, clearUniversalBundleFailure } from '../../resources/js/outlook-addin/u2e-bundle-version.js';
import { universalTaskpaneStatus } from '../../resources/js/outlook-addin/u2e-taskpane-status.js';
import { universalFailurePayload } from '../../resources/js/outlook-addin/u2e-telemetry.js';
import { validateUniversalComposeDocument } from '../../resources/js/outlook-addin/u2e-compose.js';

const source = JSON.parse(fs.readFileSync(new URL('../Fixtures/mail/u2e-readback-v2/source.json', import.meta.url), 'utf8'));
const build = extra => ({ schema: 1, revision: 'a'.repeat(64), sourceContract: 1, sourceTemplate: U2E_SOURCE_TEMPLATE,
    bundles: { runtime: 'b'.repeat(64), taskpane: 'c'.repeat(64) }, ...extra });
const payload = extra => ({ universalSignature: source, addinBuild: build(), ...extra });
const error = action => { try { action(); assert.fail('Expected rejection'); } catch (cause) { return cause; } };

test('the reader capability is pinned to the current approved C21 template, independently of personal hashes', () => {
    const contracts = JSON.parse(fs.readFileSync(new URL('../../resources/outlook-addin/contracts.json', import.meta.url), 'utf8'));
    const bytes = fs.readFileSync(new URL('../../resources/mail-templates/u2e-universal-v1.html', import.meta.url), 'utf8').replaceAll('\r\n', '\n');
    assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'), contracts.sourceTemplate);
    assert.equal(U2E_SOURCE_TEMPLATE, contracts.sourceTemplate);
    assert.deepEqual(U2E_READER_CONTRACTS, contracts.readerContracts);
    // The envelope contract stays at 1. C-K21 explicitly changes the reviewed
    // template pin; an old bundle detects that pin mismatch before writing.
    assert.equal(contracts.sourceContract, 1);
    assert.equal(contracts.sourceTemplate, '942f8bf2e36712fb616d5acd3096d642baf3731c7952f72cadd1a474796600ba');
});

test('different compatible bundle revisions and personal source hashes never cause stale classification', () => {
    for (const revision of ['a'.repeat(64), 'd'.repeat(64)]) {
        const input = payload({ universalSignature: { ...source, sourceHash: 'f'.repeat(64) }, addinBuild: build({ revision }) });
        assert.equal(assertUniversalBundleCompatibility(input), undefined);
        validateUniversalComposeDocument(input.universalSignature);
    }
});

test('missing pre-C18 metadata is not retrospectively claimed to prove a stale reader', () => {
    for (const input of [{ universalSignature: source }, payload({ addinBuild: null }), { addinBuild: build({ sourceContract: 2 }) }]) {
        assert.equal(assertUniversalBundleCompatibility(input), undefined);
    }
    assert.equal(universalBundleFailureReport({ code: 'U2E_SOURCE_INVALID' }), null);
});

for (const [name, input] of [
    ['global signature', payload()],
    ['global compose', { universalCompose: source, addinBuild: build() }],
    ['legacy alias', { template: { universalCompose: source }, addinBuild: build() }],
    ['template array compose', { templates: [{ universalCompose: source }], addinBuild: build() }],
    ['template array signature', { templates: [{ universalSignature: source }], addinBuild: build() }],
]) {
    test('incompatible reader is detected before insertion for ' + name, () => {
        const cause = error(() => assertUniversalBundleCompatibility({ ...input, addinBuild: build({ sourceContract: 2 }) }));
        assert.equal(cause.code, 'U2E_BUNDLE_STALE');
        const report = universalBundleFailureReport(cause);
        assert.equal(report.bodyWriteStarted, false); assert.equal(report.bodyWriteSucceeded, false);
        assert.equal(report.step, 'bundle.contract'); assert.equal(report.checkpoint, 'bundle.contract');
        assert.equal(report.difference.expected, 2); assert.equal(report.difference.actual, 1);
        const wire = universalFailurePayload(cause, report);
        assert.equal(wire.code, 'U2E_BUNDLE_STALE'); assert.equal(wire.checkpoint, 'bundle.contract');
        const status = universalTaskpaneStatus(report, cause.code);
        assert.equal(status.title, 'Outlook neu starten'); assert.equal(status.blocked, true);
        assert.match(status.detail, /vollständig beenden/); assert.doesNotMatch(status.detail, /hat das Einfügen bestätigt/);
    });
}

test('a changed server template contract pin is explicit evidence, not a personal source hash heuristic', () => {
    const cause = error(() => assertUniversalBundleCompatibility(payload({ addinBuild: build({ sourceTemplate: 'f'.repeat(64) }) })));
    assert.equal(cause.code, 'U2E_BUNDLE_STALE');
    assert.equal(universalBundleFailureReport(cause).difference.key, 'bundle.template');
    assert.doesNotMatch(JSON.stringify(universalFailurePayload(cause, universalBundleFailureReport(cause))), /Fixture author|direct@example|PRIVATE/);
});

for (const [name, mutate] of [
    ['invalid revision', meta => { meta.revision = 'not-a-build'; }],
    ['revision LF', meta => { meta.revision += '\n'; }],
    ['revision array', meta => { meta.revision = ['a'.repeat(64)]; }],
    ['missing template pin', meta => { delete meta.sourceTemplate; }],
    ['numeric-string contract', meta => { meta.sourceContract = '2'; }],
    ['null contract', meta => { meta.sourceContract = null; }],
    ['missing bundle hash', meta => { delete meta.bundles.taskpane; }],
]) {
    test('malformed metadata stays a document error instead of a stale claim: ' + name, () => {
        const meta = build(); mutate(meta);
        const cause = error(() => assertUniversalBundleCompatibility(payload({ addinBuild: meta })));
        assert.equal(cause.code, 'U2E_DOCUMENT_INVALID'); assert.equal(universalBundleFailureReport(cause), null);
    });
}

test('a source validation failure under a compatible contract remains U2E_SOURCE_INVALID', () => {
    const input = payload({ universalSignature: { ...source, html: '<p>Invalid source</p>' } });
    assertUniversalBundleCompatibility(input);
    const cause = error(() => validateUniversalComposeDocument(input.universalSignature));
    assert.equal(cause.code, 'U2E_SOURCE_INVALID'); assert.equal(universalBundleFailureReport(cause), null);
    assert.equal(universalFailurePayload(cause).code, 'U2E_SOURCE_INVALID');
});

function sessionFixture() {
    const values = new Map([['railtime.u2e.compose.v1', 'unchanged-pending-ledger']]), calls = [];
    const item = { sessionData: {
        getAsync(key, callback) { calls.push(['get', key]); callback({ status: 'succeeded', value: values.get(key) || '' }); },
        setAsync(key, value, callback) { calls.push(['set', key]); values.set(key, value); callback({ status: 'succeeded' }); },
    } };
    const office = { context: { mailbox: { item } }, AsyncResultStatus: { Succeeded: 'succeeded' } };
    return { values, calls, item, office };
}

test('the cross-context event marker is bounded finite metadata and never touches the pending ledger', async () => {
    const f = sessionFixture();
    const cause = error(() => assertUniversalBundleCompatibility(payload({ addinBuild: build({ sourceContract: 2 }) })));
    assert.equal(await persistUniversalBundleFailure(f.office, f.item, cause), true);
    const bytes = f.values.get(U2E_BUNDLE_SESSION_KEY), saved = JSON.parse(bytes);
    assert.ok(bytes.length < 512);
    assert.deepEqual(Object.keys(saved), ['schema', 'code', 'at', 'sourceContract', 'readerContract']);
    assert.doesNotMatch(bytes, /html|sourceHash|revision|token|Fixture|@|http/i);
    assert.equal((await readUniversalBundleFailure(f.office, f.item)).report.code, 'U2E_BUNDLE_STALE');
    assert.equal(await clearUniversalBundleFailure(f.office, f.item), true);
    assert.equal((await readUniversalBundleFailure(f.office, f.item)).report, null);
    assert.equal(f.values.get('railtime.u2e.compose.v1'), 'unchanged-pending-ledger');
    assert.equal(f.calls.every(([_method, key]) => key === U2E_BUNDLE_SESSION_KEY), true);
    assert.equal(await persistUniversalBundleFailure(f.office, f.item, { code: 'U2E_SOURCE_INVALID' }), false);
});

test('unknown, raw or oversized session values are not shown, logged or cleared as our marker', async () => {
    for (const value of ['PRIVATE-MAIL-TEXT', 'x'.repeat(513), JSON.stringify({ schema: 1, code: 'U2E_BUNDLE_STALE',
        at: '2026-11-04T08:54:09.000Z', sourceContract: 2, readerContract: 1, html: 'PRIVATE-MAIL-TEXT' })]) {
        const f = sessionFixture(); f.values.set(U2E_BUNDLE_SESSION_KEY, value);
        const result = await readUniversalBundleFailure(f.office, f.item);
        assert.equal(result.readable, false); assert.equal(result.report, null);
        assert.equal(await clearUniversalBundleFailure(f.office, f.item), false);
        assert.equal(f.values.get(U2E_BUNDLE_SESSION_KEY), value);
    }
});

test('missing, throwing or switched-item session APIs fail best effort without changing another item', async () => {
    for (const mode of ['missing', 'throwing', 'switched']) {
        const f = sessionFixture();
        if (mode === 'missing') delete f.item.sessionData;
        if (mode === 'throwing') Object.defineProperty(f.item, 'sessionData', { get() { throw new Error('PRIVATE-HOST-ERROR'); } });
        if (mode === 'switched') f.office.context.mailbox.item = {};
        assert.deepEqual(await readUniversalBundleFailure(f.office, f.item), { readable: false, report: null });
        assert.equal(await clearUniversalBundleFailure(f.office, f.item), false);
        assert.equal(f.values.get('railtime.u2e.compose.v1'), 'unchanged-pending-ledger');
    }
});

test('an unsupported session callback has a deadline and late callbacks cannot produce a persistence guarantee', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const f = sessionFixture(); let callback;
    f.item.sessionData.getAsync = (_key, next) => { callback = next; };
    const reading = readUniversalBundleFailure(f.office, f.item);
    t.mock.timers.tick(501);
    assert.deepEqual(await reading, { readable: false, report: null });
    callback({ status: 'succeeded', value: 'PRIVATE-LATE-RESULT' });
    assert.equal(f.values.get(U2E_BUNDLE_SESSION_KEY), undefined);
    const cause = error(() => assertUniversalBundleCompatibility(payload({ addinBuild: build({ sourceContract: 2 }) })));
    f.item.sessionData.setAsync = () => {};
    const writing = persistUniversalBundleFailure(f.office, f.item, cause);
    t.mock.timers.tick(501);
    assert.equal(await writing, false);
});
