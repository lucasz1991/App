import test from 'node:test';
import assert from 'node:assert/strict';
import { workClockActivities } from '../../resources/js/work-clock-activities.js';
import { projectCapture } from '../../resources/js/work-time-capture.js';

const labels = { work: 'Arbeit', preparation: 'Vorbereitung', driving: 'Fahrt', shunting: 'Rangieren', travel: 'Anreise',
    waiting: 'Wartezeit', on_call: 'Bereitschaft', internal: 'Interne Arbeit', training: 'Schulung' };

test('each activity appears once, direct suggestions honor the active type, and search only filters', () => {
    const picker = workClockActivities(labels);
    picker.active = { kind: 'preparation', suggested_kinds: ['shunting', 'preparation', 'driving'], recent_kinds: ['driving', 'shunting', 'internal'] };
    assert.deepEqual(picker.quickKinds, ['shunting', 'driving']);
    assert.deepEqual(picker.recentKinds, ['internal']);
    const all = picker.groups.flatMap(group => group.kinds);
    assert.equal(all.length, 9);
    assert.equal(new Set(all).size, 9);
    picker.search = '  FaHrT  ';
    assert.deepEqual(picker.groups[0].kinds, ['driving']);
    picker.search = 'Zugnummer';
    assert.deepEqual(picker.groups[0].kinds, []);
});

test('a search choice captures exactly once and an outdated correction cannot affect another section', async () => {
    const picker = workClockActivities(labels);
    const saved = [];
    picker.$refs = { activityDialog: { open: true, close() { this.open = false; } } };
    picker.ready = true;
    picker.active = { capture_id: 'a', revision: 2, status: 'running', kind: 'driving' };
    picker.openedEntry = 'a';
    picker.openedRevision = 2;
    picker.capture = async data => saved.push(data);
    await picker.chooseActivity('driving');
    assert.equal(saved.length, 0);
    picker.$refs.activityDialog.open = true;
    picker.correcting = true;
    await picker.chooseActivity('shunting');
    assert.deepEqual(saved[0], { action: 'activity', kind: 'shunting', revision: 2, entry_capture_id: 'a', correct_activity: true });
    picker.$refs.activityDialog.open = true;
    picker.active.revision += 1;
    await picker.chooseActivity('waiting');
    assert.equal(saved.length, 1);
    assert.match(picker.error, /erneut auswählen/);
});

test('offline corrections and pauses preserve activity times and the original clock', () => {
    const event = (action, revision, at, extra = {}) => ({ action, revision, occurred_at: at, entry_capture_id: 'a', ...extra });
    let row = projectCapture(null, event('start', 0, '2026-11-27T07:00:00Z', { work_context: 'internal' }));
    row = projectCapture(row, event('activity', 1, '2026-11-27T09:12:00Z', { kind: 'shunting' }));
    row = projectCapture(row, event('activity', 2, '2026-11-27T09:13:00Z', { kind: 'driving', correct_activity: true }));
    assert.equal(row.activity_since, '2026-11-27T09:12:00Z');
    assert.equal(row.can_correct_activity, true);
    row = projectCapture(row, event('pause', 3, '2026-11-27T11:00:00Z'));
    assert.equal(row.previous_kind, 'driving');
    row = projectCapture(row, event('resume', 4, '2026-11-27T11:30:00Z'));
    assert.equal(row.kind, 'driving');
    assert.equal(row.can_correct_activity, false);
    assert.equal(row.starts_at, '2026-11-27T07:00:00Z');
    assert.equal(row.pause_seconds, 1800);
});
