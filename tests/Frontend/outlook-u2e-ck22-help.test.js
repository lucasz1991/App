import test from 'node:test';
import assert from 'node:assert/strict';
import { universalComposeBoundaryHelp } from '../../resources/js/outlook-addin/u2e-compose-help.js';

const report = { code: 'U2E_NEW_DRAFT_REQUIRED', phase: 'preflight', step: 'snapshot.region', composeType: 'forward',
    bodyWriteStarted: false, bodyWriteSucceeded: false };

test('reply and forward boundary failures give a manual route without demanding a fresh forward', () => {
    for (const composeType of ['reply', 'forward']) {
        const advice = universalComposeBoundaryHelp(report.code, { ...report, composeType });
        assert.match(advice.taskpane, /manuell oberhalb des Zitats kopieren/);
        assert.match(advice.taskpane, /Es wurde nichts eingefügt/);
        assert.match(advice.mobile, /RT-COMPOSE/);
        assert.ok(advice.mobile.length <= 150);
        assert.doesNotMatch(advice.taskpane, /Diagnosebericht|erneut einfügen|neue Nachricht öffnen/);
    }
});

test('other preflight failures and any attempted write retain their existing distinct advice', () => {
    for (const changes of [{ phase: 'readback' }, { step: 'source.state' }, { composeType: 'newMail' },
        { code: 'U2E_SOURCE_INVALID' }, { bodyWriteStarted: true }, { bodyWriteSucceeded: true }]) {
        assert.equal(universalComposeBoundaryHelp(report.code, { ...report, ...changes }), null);
    }
    assert.equal(universalComposeBoundaryHelp('U2E_INSERT_UNCERTAIN', report), null);
    assert.equal(universalComposeBoundaryHelp(report.code, null), null);
});
