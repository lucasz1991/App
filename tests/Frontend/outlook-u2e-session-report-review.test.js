import test from 'node:test';
import assert from 'node:assert/strict';
import { readUniversalSessionReport, persistUniversalSessionReport, claimUniversalDiagnostic,
    universalDiagnosticId, UNIVERSAL_REPORT_SESSION_KEY } from '../../resources/js/outlook-addin/u2e-session-report.js';

const composeKey = 'railtime.u2e.compose.v1';
const claim = 'pending:' + 'a'.repeat(24) + ':' + 'b'.repeat(32);
const report = { schema: 1, claim, kind: 'combined', code: 'SIGNATURE_LAYOUT_UNCONFIRMED',
    originalCode: 'SIGNATURE_LAYOUT_UNCONFIRMED', phase: 'readback', step: 'readback.material',
    checkpoint: 'evidence.colors', at: '2026-11-04T08:54:09.000Z',
    bodyWriteStarted: true, bodyWriteSucceeded: true };

function environment() {
    const data = new Map([[composeKey, claim], [UNIVERSAL_REPORT_SESSION_KEY, JSON.stringify(report)]]);
    const writes = [];
    const item = { sessionData: {
        getAsync: (key, callback) => callback({ status: 'ok', value: data.get(key) }),
        setAsync: (key, value, callback) => { writes.push(key); data.set(key, value); callback({ status: 'ok' }); },
    } };
    const office = { AsyncResultStatus: { Succeeded: 'ok' }, context: { mailbox: { item } } };
    return { data, writes, item, office };
}

test('a final session callback followed by a draft switch cannot return the old explanation', async () => {
    const a = environment();
    let reads = 0;
    const originalGet = a.item.sessionData.getAsync;
    a.item.sessionData.getAsync = (key, callback) => {
        reads++;
        originalGet(key, callback);
        if (reads === 3) a.office.context.mailbox.item = {};
    };
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    assert.equal(reads, 3);
    assert.deepEqual(a.writes, []);
    assert.equal(a.data.get(composeKey), claim);
});

test('a report is explanatory only: raw data is omitted and compose lock is untouched', async () => {
    const a = environment();
    assert.equal(await persistUniversalSessionReport(a.office, a.item, claim, {
        ...report, html: '<p>PRIVATE</p>', message: 'PRIVATE', evidence: { text: 'PRIVATE' },
        fixture: 'PRIVATE', accessToken: 'PRIVATE', sender: 'PRIVATE',
    }), true);
    const saved = JSON.parse(a.data.get(UNIVERSAL_REPORT_SESSION_KEY));
    assert.deepEqual(Object.keys(saved).sort(), ['schema', 'state', 'kind', 'code', 'originalCode',
        'checkpoint', 'step', 'phase', 'at', 'bodyWriteStarted', 'bodyWriteSucceeded', 'claim'].sort());
    assert.doesNotMatch(JSON.stringify(saved), /PRIVATE/);
    assert.deepEqual(a.writes, [UNIVERSAL_REPORT_SESSION_KEY]);
    assert.equal(a.data.get(composeKey), claim);
});

test('false success and an unbound report cannot be persisted or recovered', async () => {
    const a = environment();
    assert.equal(await persistUniversalSessionReport(a.office, a.item, claim,
        { ...report, bodyWriteStarted: false, bodyWriteSucceeded: true }), false);
    a.data.set(UNIVERSAL_REPORT_SESSION_KEY, JSON.stringify({ ...report, claim: claim.replace(/b/g, 'c') }));
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    assert.deepEqual(a.writes, []);
});

test('a changed claim on final verification rejects metadata without changing either lock', async () => {
    const a = environment();
    let reads = 0;
    a.item.sessionData.getAsync = (key, callback) => {
        if (++reads === 3) a.data.set(composeKey, 'pending:' + 'c'.repeat(24) + ':' + 'd'.repeat(32));
        callback({ status: 'ok', value: a.data.get(key) });
    };
    assert.equal(await readUniversalSessionReport(a.office, a.item), null);
    assert.deepEqual(a.writes, []);
});

test('dedupe separates checkpoints and drafts while retaining only finite keys', async () => {
    const a = environment();
    const args = [report.code, report.checkpoint, report.step];
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, ...args), true);
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, ...args), false);
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, report.code, 'quote.bytes', report.step), true);
    const b = environment();
    assert.equal(await claimUniversalDiagnostic(b.office, b.item, ...args), true);
    assert.equal(await claimUniversalDiagnostic(a.office, a.item, 'PRIVATE', 'PRIVATE', 'PRIVATE'), false);
    assert.doesNotMatch(a.data.get('railtime.u2e.diagnostics.v1'), /PRIVATE/);
    assert.equal(a.data.get(composeKey), claim);
});

test('cross-runtime diagnostic identity accepts only a strict pending nonce without leaking its value', async () => {
    const a = environment();
    const args = [report.code, report.checkpoint, report.step];
    const id = await universalDiagnosticId(a.office, a.item, ...args);
    assert.match(id, /^[0-9a-f]{64}$/);
    assert.notEqual(id, claim);
    const b = environment();
    assert.equal(await universalDiagnosticId(b.office, b.item, ...args), id);
    for (const value of [claim + '\n', claim + '\r\n', 'applied:' + 'a'.repeat(24) + ':combined',
        'pending:' + 'a'.repeat(24) + ':' + 'B'.repeat(32), '']) {
        a.data.set(composeKey, value);
        assert.equal(await universalDiagnosticId(a.office, a.item, ...args), null);
    }
    a.data.set(composeKey, claim.replace(/b/g, 'c'));
    assert.notEqual(await universalDiagnosticId(a.office, a.item, ...args), id);
    assert.deepEqual(a.writes, []);
});

test('diagnostic identity is not returned after an immediate callback draft switch', async () => {
    const a = environment();
    const originalGet = a.item.sessionData.getAsync;
    a.item.sessionData.getAsync = (key, callback) => {
        originalGet(key, callback);
        a.office.context.mailbox.item = {};
    };
    assert.equal(await universalDiagnosticId(a.office, a.item, report.code, report.checkpoint, report.step), null);
    assert.deepEqual(a.writes, []);
});
