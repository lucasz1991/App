import test from 'node:test';
import assert from 'node:assert/strict';
import { confirmedOfficeWrite, hasUncertainWrite, wasSignatureWriteConfirmed } from '../../resources/js/outlook-addin/office-write.js';
import { diagnoseStep, diagnosticReport, recordDiagnostic } from '../../resources/js/outlook-addin/diagnostics.js';

const office = { AsyncResultStatus: { Succeeded: 'succeeded' } };

test('a timed out write remains blocked; late success clears only its own operation', async () => {
    const item = {};
    let first, second;
    const one = confirmedOfficeWrite(office, item, 'signature-write', 'SIGNATURE_INSERT_UNCERTAIN', cb => { first = cb; }, { timeoutMs: 2 });
    const two = confirmedOfficeWrite(office, item, 'attachment-write', 'INLINE_ATTACHMENT_UNCERTAIN', cb => { second = cb; }, { timeoutMs: 2 });
    await Promise.all([
        assert.rejects(one, { code: 'SIGNATURE_INSERT_UNCERTAIN', reason: 'timeout' }),
        assert.rejects(two, { code: 'INLINE_ATTACHMENT_UNCERTAIN', reason: 'timeout' }),
    ]);
    assert.equal(hasUncertainWrite(item), true);
    first({ status: 'succeeded' });
    assert.equal(wasSignatureWriteConfirmed(item), true);
    assert.equal(hasUncertainWrite(item), true);
    second({ status: 'succeeded' });
    assert.equal(hasUncertainWrite(item), false);
});

test('an immediate host exception is distinguished from timeout, with no retry', async () => {
    const item = {};
    let writes = 0;
    await assert.rejects(confirmedOfficeWrite(office, item, 'signature-write', 'SIGNATURE_INSERT_UNCERTAIN', () => {
        writes++;
        throw Object.assign(new Error('secret user text'), { code: 'API_UNSUPPORTED' });
    }), { code: 'SIGNATURE_INSERT_UNCERTAIN', reason: 'exception', officeCode: 'API_UNSUPPORTED' });
    assert.equal(writes, 1);
    assert.equal(hasUncertainWrite(item), true);
});

test('definite host failure carries phase and error code without locking future read checks', async () => {
    const item = {};
    await assert.rejects(confirmedOfficeWrite(office, item, 'signature-write', 'SIGNATURE_INSERT_UNCERTAIN', cb => {
        cb({ status: 'failed', error: { code: 5001, message: 'private message' } });
    }), { code: 'OFFICE_WRITE_FAILED', reason: 'callback', phase: 'signature-write', officeCode: '5001' });
    assert.equal(hasUncertainWrite(item), false);
});

test('diagnostics are bounded and never serialize body, identity, token, or arbitrary error text', async () => {
    await assert.rejects(diagnoseStep('bootstrap', async () => { throw { code: 'HTTP_403', message: 'secret-token' }; }));
    recordDiagnostic('sender-read', 'failed', { code: 'private@example.test', officeCode: 'Bearer secret-token', html: '<p>private</p>', message: 'private message' });
    for (let i = 0; i < 50; i++) recordDiagnostic('preflight', 'started');
    const report = diagnosticReport(office, { token: 'secret-token', html: 'private message', mailbox: 'private@example.test', automaticTemplate: true });
    assert.equal(report.events.length, 40);
    assert.equal(report.checks.automaticTemplate, true);
    assert.doesNotMatch(JSON.stringify(report), /secret-token|private@example|private message|<p>/);
});
