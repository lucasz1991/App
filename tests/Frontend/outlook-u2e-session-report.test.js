import test from 'node:test';
import assert from 'node:assert/strict';
import { UNIVERSAL_REPORT_SESSION_KEY, persistUniversalSessionReport, readUniversalSessionReport,
    claimUniversalDiagnostic, universalDiagnosticId } from '../../resources/js/outlook-addin/u2e-session-report.js';

const composeKey = 'railtime.u2e.compose.v1';
const claim = 'pending:' + 'a'.repeat(24) + ':' + 'b'.repeat(32);
const report = { kind: 'combined', code: 'SIGNATURE_LAYOUT_UNCONFIRMED', originalCode: 'SIGNATURE_LAYOUT_UNCONFIRMED',
    phase: 'readback', step: 'readback.material', checkpoint: 'evidence.colors', at: '2026-11-04T08:54:09.000Z',
    bodyWriteStarted: true, bodyWriteSucceeded: true };
function environment(data = new Map([[composeKey, claim]])) {
    const writes = [];
    const item = { sessionData: {
        getAsync: (key, cb) => cb({ status: 'ok', value: data.get(key) }),
        setAsync: (key, value, cb) => { writes.push([key, value]); data.set(key, value); cb({ status: 'ok' }); },
    } };
    const office = { AsyncResultStatus: { Succeeded: 'ok' }, context: { mailbox: { item } } };
    return { office, item, data, writes };
}

test('a readback reason survives a new runtime without changing the pending lock or persisting mail data', async () => {
    const a = environment();
    assert.equal(await persistUniversalSessionReport(a.office, a.item, claim, { ...report,
        html: 'PRIVATE-BODY', difference: { actual: 'PRIVATE' }, fixture: 'PRIVATE', sender: 'PRIVATE' }), true);
    const b = environment(a.data);
    const recovered = await readUniversalSessionReport(b.office, b.item);
    assert.deepEqual(recovered, { schema: 1, state: 'uncertain', ...report });
    assert.equal(a.data.get(composeKey), claim);
    assert.deepEqual(a.writes.map(([key]) => key), [UNIVERSAL_REPORT_SESSION_KEY]);
    assert.doesNotMatch(JSON.stringify(recovered), /PRIVATE|pending|claim/);
    assert.doesNotMatch(a.data.get(UNIVERSAL_REPORT_SESSION_KEY), /PRIVATE/);
});

test('old pending, applied, malformed and mismatched metadata never claim a successful insertion', async () => {
    const a = environment();
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    await persistUniversalSessionReport(a.office, a.item, claim, report);
    for (const next of ['applied:' + 'a'.repeat(24) + ':combined', claim.replace(/b/g, 'c'), '']) {
        a.data.set(composeKey, next);
        assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    }
    a.data.set(composeKey, claim);
    for (const change of [{ bodyWriteStarted: false }, { bodyWriteSucceeded: 'true' }, { at: 'PRIVATE' }, { step: 'PRIVATE' }]) {
        a.data.set(UNIVERSAL_REPORT_SESSION_KEY, JSON.stringify({ ...report, schema: 1, claim, ...change }));
        assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    }
});

test('header-only failure and an unknown body result stay distinct from acknowledged body write', async () => {
    const a = environment();
    for (const facts of [
        { bodyWriteStarted: false, bodyWriteSucceeded: false, phase: 'header', step: 'header.write' },
        { bodyWriteStarted: true, bodyWriteSucceeded: false, phase: 'write', step: 'body.write' },
    ]) {
        assert.equal(await persistUniversalSessionReport(a.office, a.item, claim, { ...report, ...facts }), true);
        const recovered = await readUniversalSessionReport(a.office, a.item);
        assert.equal(recovered.bodyWriteStarted, facts.bodyWriteStarted);
        assert.equal(recovered.bodyWriteSucceeded, false);
    }
});

test('stale item and a lock changed during the read invalidate explanatory metadata', async () => {
    const a = environment();
    await persistUniversalSessionReport(a.office, a.item, claim, report);
    const get = a.item.sessionData.getAsync;
    a.item.sessionData.getAsync = (key, cb) => {
        get(key, cb);
        if (key === UNIVERSAL_REPORT_SESSION_KEY) a.data.set(composeKey, 'applied:' + 'a'.repeat(24) + ':combined');
    };
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    a.office.context.mailbox.item = {};
    assert.equal(await persistUniversalSessionReport(a.office, a.item, claim, report), false);
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
});

test('one draft/code/checkpoint reservation survives runtime reload and permits distinct reasons and other drafts', async () => {
    const a = environment();
    const args = [report.code, report.checkpoint, report.step];
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, ...args), true);
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, ...args), false);
    const b = environment(a.data);
    assert.equal(await claimUniversalDiagnostic(b.office, b.item, ...args), false);
    assert.equal(await claimUniversalDiagnostic(b.office, b.item, report.code, 'borders.cta', report.step), true);
    assert.equal(await claimUniversalDiagnostic(b.office, b.item, 'U2E_INSERT_UNCERTAIN', report.checkpoint, report.step), true);
    const c = environment();
    assert.equal(await claimUniversalDiagnostic(c.office, c.item, ...args), true);
    assert.equal(a.data.get(composeKey), claim);
});

test('unavailable session metadata cannot alter compose locks and uses local diagnostic dedupe', async () => {
    const a = environment();
    a.item.sessionData = null;
    assert.equal(await persistUniversalSessionReport(a.office, a.item, claim, report), false);
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, report.code, report.checkpoint, report.step), true);
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, report.code, report.checkpoint, report.step), false);
});

test('server diagnostic identity is nonce-based, finite and stable across runtimes but never shared applied versions', async () => {
    const a = environment(), b = environment(a.data), c = environment();
    const args = [report.code, report.checkpoint, report.step];
    const id = await universalDiagnosticId(a.office, a.item, ...args);
    assert.match(id, /^[0-9a-f]{64}$/);
    assert.equal(await universalDiagnosticId(b.office, b.item, ...args), id);
    c.data.set(composeKey, claim.replace(/b/g, 'c'));
    assert.notEqual(await universalDiagnosticId(c.office, c.item, ...args), id);
    assert.notEqual(await universalDiagnosticId(a.office, a.item, report.code, 'quote.bytes', report.step), id);
    assert.notEqual(await universalDiagnosticId(a.office, a.item, 'U2E_INSERT_UNCERTAIN', report.checkpoint, report.step), id);
    a.data.set(composeKey, 'applied:' + 'a'.repeat(24) + ':combined');
    assert.equal(await universalDiagnosticId(a.office, a.item, ...args), null);
    assert.equal(await universalDiagnosticId(b.office, b.item, 'PRIVATE-CODE', 'PRIVATE-CHECKPOINT', null), null);
});
