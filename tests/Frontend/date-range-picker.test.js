import assert from 'node:assert/strict';
import test from 'node:test';
import { dateRangePicker } from '../../resources/js/date-range-picker.js';

const build = (config = {}) => {
    const picker = dateRangePicker({ today: '2026-10-01', from: '2026-09-28', until: '2026-10-04', maxDays: 94, ...config });
    picker.$watch = () => {};
    picker.$nextTick = (fn) => fn();
    picker.events = [];
    picker.$dispatch = (name, data) => picker.events.push({ name, data });
    picker.init();
    return picker;
};

test('displays the current range across two months without changing committed dates', () => {
    const picker = build();
    assert.deepEqual(picker.months, ['2026-09-01', '2026-10-01']);
    picker.select('2026-10-15', 1);
    picker.select('2026-10-10', 1);
    assert.equal(picker.draftFrom, '2026-10-10');
    assert.equal(picker.draftUntil, '2026-10-15');
    assert.equal(picker.state('2026-10-12'), 'between');
    assert.equal(picker.from, '2026-09-28');
    picker.cancel();
    assert.equal(picker.draftFrom, '2026-09-28');
    assert.equal(picker.events.at(-1).name, 'date-range-cancel');
});

test('same-day ranges are valid but incomplete, reversed and impossible typed dates cannot apply', () => {
    const picker = build();
    picker.select('2026-10-01', 1);
    assert.equal(picker.canApply, false);
    picker.select('2026-10-01', 1);
    assert.equal(picker.canApply, true);
    assert.equal(picker.summary, '1 Tag ausgewählt');
    picker.updateTyped(0, '31.02.2026');
    assert.equal(picker.canApply, false);
    picker.updateTyped(0, '05.10.2026');
    assert.equal(picker.canApply, false);
    picker.updateTyped(1, '2026-10-06');
    assert.equal(picker.canApply, true);
});

test('week and month presets cross DST, year and leap-day boundaries as civil dates', () => {
    const picker = build({ today: '2028-03-01' });
    const lastMonth = picker.presets.find(p => p.key === 'last-month');
    assert.equal(lastMonth.until, '2028-02-29');
    picker.today = '2026-10-25';
    picker.choosePreset(picker.presets.find(p => p.key === 'week'));
    assert.equal(picker.summary, '7 Tage ausgewählt');
    assert.equal(picker.draftFrom, '2026-10-19');
    picker.today = '2026-12-31';
    assert.equal(picker.presets.find(p => p.key === 'week').until, '2027-01-03');
});

test('range limit and min/max bounds are enforced before applying', () => {
    const picker = build();
    picker.updateTyped(0, '01.01.2026');
    picker.updateTyped(1, '04.04.2026');
    assert.equal(picker.canApply, true, '94 inclusive days are permitted');
    picker.updateTyped(1, '05.04.2026');
    assert.equal(picker.canApply, false);
    const bounded = build({ min: '2026-10-01', max: '2026-10-31' });
    assert.equal(bounded.presets.find(p => p.key === 'week').disabled, true);
    assert.equal(bounded.isSelectable('2026-11-01'), false);
});

test('apply waits for one atomic callback and preserves the draft if the server fails', async () => {
    let received;
    const picker = build({ onApply: async range => { received = range; throw new Error('failed'); } });
    picker.choosePreset(picker.presets.find(p => p.key === 'next-week'));
    await picker.apply();
    assert.deepEqual(received, { from: '2026-10-05', until: '2026-10-11' });
    assert.equal(picker.from, '2026-09-28');
    assert.equal(picker.draftFrom, '2026-10-05');
    assert.equal(picker.busy, false);
    assert.equal(picker.events.length, 0);
    assert.ok(picker.failure);
    const standalone = build();
    standalone.choosePreset(standalone.presets.find(p => p.key === 'today'));
    await standalone.apply();
    assert.equal(standalone.from, '2026-10-01');
    assert.equal(standalone.events.at(-1).name, 'date-range-applied');
});

test('calendar starts on Monday and keyboard navigation crosses month boundaries', () => {
    const picker = build();
    assert.equal(picker.days(1)[0].iso, '2026-09-28');
    assert.equal(picker.days(1).length, 42);
    picker.$el = { querySelector: () => ({ focus() {} }) };
    picker.handleKey({ key: 'ArrowRight', target: { dataset: { rangeDate: '2026-10-31' } }, preventDefault() {} }, 1);
    assert.equal(picker.focused[1], '2026-11-01');
    assert.equal(picker.months[1], '2026-11-01');
});

test('optional single selection displays one month and highlights only the selected anchor, not the backing week', () => {
    const picker = build({ selectionMode: 'single', presets: ['week'] });
    assert.equal(picker.isSingle, true);
    assert.deepEqual(picker.months, ['2026-09-01']);
    assert.deepEqual(picker.focused, ['2026-09-28']);
    assert.equal(picker.draftFrom, '2026-09-28');
    assert.equal(picker.draftUntil, '2026-10-04');
    assert.equal(picker.state('2026-09-28'), 'start');
    assert.equal(picker.state('2026-09-29'), '');
    assert.equal(picker.state('2026-10-04'), '');
    picker.hover = '2026-10-10';
    assert.equal(picker.state('2026-10-10'), '');
    assert.equal(picker.summary, '28.09.2026');
});

test('single date selection immediately creates its seven-day civil backing window across DST, leap-day and year boundaries', () => {
    for (const [anchor, until] of [
        ['2026-03-29', '2026-04-04'],
        ['2026-10-25', '2026-10-31'],
        ['2028-02-26', '2028-03-03'],
        ['2026-12-28', '2027-01-03'],
    ]) {
        const picker = build({ selectionMode: 'single' });
        picker.select(anchor);
        assert.equal(picker.draftFrom, anchor);
        assert.equal(picker.draftUntil, until);
        assert.equal(picker.selectingEnd, false);
        assert.equal(picker.canApply, true);
        assert.equal(picker.state(anchor), 'start');
        assert.equal(picker.state(until), '');
        assert.equal(picker.from, '2026-09-28', 'draft selection does not mutate the committed Livewire range');
    }
});

test('reselecting the same anchor or another day never enters a second end-date selection', () => {
    const picker = build({ selectionMode: 'single' });
    picker.select('2026-10-08');
    picker.select('2026-10-08');
    assert.equal(picker.draftFrom, '2026-10-08');
    assert.equal(picker.draftUntil, '2026-10-14');
    assert.equal(picker.selectingEnd, false);
    assert.equal(picker.canApply, true);
    picker.select('2026-10-05');
    assert.equal(picker.draftFrom, '2026-10-05');
    assert.equal(picker.draftUntil, '2026-10-11');
    assert.equal(picker.selectingEnd, false);
});

test('single calendar offers only Diese Woche and chooses Monday as the left-edge anchor', () => {
    const picker = build({ selectionMode: 'single', today: '2026-10-11', presets: ['week'] });
    assert.deepEqual(picker.presets.map(preset => preset.key), ['week']);
    assert.equal(picker.presets[0].label, 'Diese Woche');
    picker.choosePreset(picker.presets[0]);
    assert.equal(picker.draftFrom, '2026-10-05');
    assert.equal(picker.draftUntil, '2026-10-11');
    assert.equal(picker.summary, '05.10.2026');
    assert.deepEqual(picker.months, ['2026-10-01']);
    assert.deepEqual(picker.focused, ['2026-10-05']);
    assert.equal(picker.state('2026-10-06'), '');
    assert.equal(picker.selectingEnd, false);
});

test('preset filtering safely ignores unknown keys while null and absent configuration preserve range defaults', () => {
    assert.equal(build({ presets: null }).presets.length, 7);
    assert.equal(build().presets.length, 7);
    assert.deepEqual(build({ presets: ['missing', 'week'] }).presets.map(preset => preset.key), ['week']);
    assert.deepEqual(build({ presets: [] }).presets, []);
    assert.deepEqual(build({ selectionMode: 'single', presets: null }).presets.map(preset => preset.key), ['week']);
    assert.deepEqual(build({ selectionMode: 'single' }).presets.map(preset => preset.key), ['week']);
    assert.equal(build({ selectionMode: 'unsupported' }).isSingle, false);
    assert.equal(build({ selectionMode: 'unsupported' }).months.length, 2);
});

test('single typed date computes its backing end and invalid input cannot retain a previous selected date', () => {
    const picker = build({ selectionMode: 'single' });
    picker.updateTyped(0, '25.10.2026');
    assert.equal(picker.draftFrom, '2026-10-25');
    assert.equal(picker.draftUntil, '2026-10-31');
    assert.deepEqual(picker.months, ['2026-10-01']);
    assert.deepEqual(picker.focused, ['2026-10-25']);
    assert.equal(picker.canApply, true);
    picker.updateTyped(0, '31.02.2026');
    assert.equal(picker.fromText, '31.02.2026');
    assert.equal(picker.draftFrom, '');
    assert.equal(picker.draftUntil, '');
    assert.equal(picker.canApply, false);
    assert.equal(picker.validationMessage, 'Datum wählen.');
});

test('single anchor availability also checks the hidden backing week without checking an extra week at its end', () => {
    const picker = build({ selectionMode: 'single', min: '2026-10-01', max: '2026-10-31' });
    assert.equal(picker.isSelectable('2026-10-25'), true);
    assert.equal(picker.isSelectable('2026-10-26'), false);
    picker.select('2026-10-25');
    assert.equal(picker.canApply, true, 'exactly seven valid days through the maximum are allowed');
    assert.equal(picker.draftUntil, '2026-10-31');
    picker.updateTyped(0, '26.10.2026');
    assert.equal(picker.canApply, false);
    assert.equal(build({ selectionMode: 'single', maxDays: 6 }).isSelectable('2026-10-05'), false);
});

test('single reset-on-open restores the newest committed anchor and derives its end without exposing a stale range', () => {
    const picker = dateRangePicker({ selectionMode: 'single', resetOnOpen: true, today: '2026-10-11',
        from: '2026-10-05', until: '2026-10-11', presets: ['week'] });
    const watchers = new Map();
    picker.$watch = (key, callback) => watchers.set(key, callback);
    picker.$dispatch = () => {};
    picker.init();
    picker.select('2026-10-17');
    picker.from = '2026-11-03';
    picker.until = '2026-10-11';
    watchers.get('open')(true);
    assert.equal(picker.draftFrom, '2026-11-03');
    assert.equal(picker.draftUntil, '2026-11-09');
    assert.deepEqual(picker.months, ['2026-11-01']);
    assert.equal(picker.fromText, '03.11.2026');
    assert.equal(picker.selectingEnd, false);
    assert.equal(picker.touched, false);
});

test('single apply preserves the existing atomic range callback and waits for the shared planning queue', async () => {
    let resolve;
    const received = [];
    const picker = build({ selectionMode: 'single', onApply: range => {
        received.push(range);
        return new Promise(done => { resolve = done; });
    } });
    picker.select('2026-12-28');
    const applying = picker.apply();
    await picker.apply();
    assert.equal(picker.busy, true);
    assert.deepEqual(received, [{ from: '2026-12-28', until: '2027-01-03' }]);
    assert.equal(picker.events.length, 0);
    resolve();
    await applying;
    assert.equal(picker.busy, false);
    assert.deepEqual(picker.events.at(-1), { name: 'date-range-applied', data: { from: '2026-12-28', until: '2027-01-03' } });
});

test('single apply failure retains the selected anchor and uses date-specific feedback', async () => {
    const picker = build({ selectionMode: 'single', onApply: async () => { throw new Error('transport failed'); } });
    picker.select('2026-10-08');
    await picker.apply();
    assert.equal(picker.draftFrom, '2026-10-08');
    assert.equal(picker.draftUntil, '2026-10-14');
    assert.equal(picker.from, '2026-09-28');
    assert.match(picker.failure, /^Das Datum/);
    assert.equal(picker.events.length, 0);
    assert.equal(picker.busy, false);
});

test('single keyboard navigation keeps one calendar while moving focus across month boundaries', () => {
    const picker = build({ selectionMode: 'single', from: '2026-10-25', until: '2026-10-31' });
    let focused;
    picker.$el = { querySelector: selector => ({ focus() { focused = selector; } }) };
    picker.handleKey({ key: 'ArrowRight', target: { dataset: { rangeDate: '2026-10-31' } }, preventDefault() {} }, 0);
    assert.deepEqual(picker.months, ['2026-11-01']);
    assert.deepEqual(picker.focused, ['2026-11-01']);
    assert.match(focused, /data-range-calendar="0"/);
    assert.equal(picker.draftFrom, '2026-10-25', 'focus movement does not silently change the selected anchor');
});
