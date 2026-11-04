import test from 'node:test';
import assert from 'node:assert/strict';
import { U2E_READBACK_CHECKPOINTS } from '../../resources/js/outlook-addin/u2e-readback-diagnostics.js';
import { U2E_REPORT_STEPS } from '../../resources/js/outlook-addin/u2e-session-report.js';
import { U2E_CHECKPOINT_LABELS, U2E_STEP_LABELS, U2E_STATUS_FALLBACK_LABEL,
    u2eCheckpointLabel } from '../../resources/js/outlook-addin/u2e-status-labels.js';

test('every admitted checkpoint has a finite German label and both maps are immutable', () => {
    assert.deepEqual(Object.keys(U2E_CHECKPOINT_LABELS).sort(), [...U2E_READBACK_CHECKPOINTS].sort());
    assert.deepEqual(Object.keys(U2E_STEP_LABELS).sort(), [...U2E_REPORT_STEPS].sort());
    assert.ok(Object.isFrozen(U2E_CHECKPOINT_LABELS));
    assert.ok(Object.isFrozen(U2E_STEP_LABELS));
    for (const [checkpoint, label] of Object.entries(U2E_CHECKPOINT_LABELS)) {
        assert.equal(u2eCheckpointLabel(checkpoint), label);
        assert.equal(typeof label, 'string');
        assert.ok(label.length > 5 && label.length < 100);
    }
    assert.throws(() => { U2E_CHECKPOINT_LABELS['colors.line'] = 'changed'; }, TypeError);
});

test('a known checkpoint wins, otherwise a known execution step supplies the explanation', () => {
    assert.equal(u2eCheckpointLabel('colors.line', 'session.read'), 'Weiße Trennlinie über den Dispo-Symbolen');
    assert.equal(u2eCheckpointLabel('borders.cta'), 'Unerwarteter Rahmen an der Dispo-Symbolzeile');
    assert.equal(u2eCheckpointLabel('evidence.borderColors'), 'Rahmenfarben der Signatur');
    assert.equal(u2eCheckpointLabel(null, 'snapshot.region'), 'Eigenen Nachrichtenteil und Zitatgrenze prüfen');
    assert.equal(u2eCheckpointLabel('unknown-old-checkpoint', 'session.read'), 'Frühere Einfügung dieser Nachricht prüfen');
    for (const [step, label] of Object.entries(U2E_STEP_LABELS)) assert.equal(u2eCheckpointLabel(null, step), label);
});

test('unknown, inherited or non-string input never echoes private values or coerces an object', () => {
    const hostile = { toString() { throw new Error('must not coerce input'); } };
    for (const value of [undefined, null, false, 42, [], hostile, 'PRIVATE-BODY', '<script>PRIVATE</script>',
        'private@example.test', 'https://private.example', 'constructor', 'toString', '__proto__', ' colors.line']) {
        assert.equal(u2eCheckpointLabel(value, value), U2E_STATUS_FALLBACK_LABEL);
    }
    assert.equal(U2E_STATUS_FALLBACK_LABEL, 'Prüfschritt nicht verfügbar');
});
