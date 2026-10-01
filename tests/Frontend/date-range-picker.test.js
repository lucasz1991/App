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
