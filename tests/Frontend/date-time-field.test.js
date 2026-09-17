import assert from 'node:assert/strict';
import test from 'node:test';
import { dateTimeField } from '../../resources/js/date-time-field.js';

const build = (value = '') => {
    const field = dateTimeField({value});
    field.$refs = {time: {disabled: false, readOnly: false}};
    field.$watch = () => {};
    field.init();
    return field;
};

test('splits a server local datetime without converting its time zone', () => {
    const field = build('2026-10-25T02:30');
    assert.equal(field.datePart, '2026-10-25');
    assert.equal(field.timePart, '02:30');
    assert.equal(field.compose(), '2026-10-25T02:30');
});
test('date changes preserve hours and minutes', () => {
    const field = build('2026-09-17T23:45');
    field.datePart = '2026-09-18'; field.commit();
    assert.equal(field.value, '2026-09-18T23:45');
});
test('time changes preserve date and midnight is not treated as empty', () => {
    const field = build('2026-09-17T12:45');
    field.timePart = '00:00'; field.commit();
    assert.equal(field.value, '2026-09-17T00:00');
});
test('external edit or form reset updates both visible parts', () => {
    const field = build('2026-09-17T12:45');
    field.value = '2026-12-24T08:15'; field.sync();
    assert.equal(field.datePart, '2026-12-24'); assert.equal(field.timePart, '08:15');
    field.value = ''; field.sync();
    assert.equal(field.datePart, ''); assert.equal(field.timePart, '');
});
test('partial dates are not silently discarded as an empty optional value', () => {
    const field = build();
    field.datePart = '2026-09-17'; field.commit(); field.sync();
    assert.equal(field.value, '2026-09-17T');
    assert.equal(field.datePart, '2026-09-17');
    field.timePart = '08:00'; field.commit();
    assert.equal(field.value, '2026-09-17T08:00');
});
test('time-first entry and clearing one part preserve validation state', () => {
    const field = build();
    field.timePart = '08:00'; field.commit(); field.sync();
    assert.equal(field.value, 'T08:00');
    field.datePart = '2026-09-17'; field.commit();
    field.timePart = ''; field.commit();
    assert.equal(field.value, '2026-09-17T');
    field.datePart = ''; field.commit();
    assert.equal(field.value, '');
});
test('disabled and readonly controls never commit', () => {
    for (const lock of ['disabled','readOnly']) {
        const field = build('2026-09-17T08:00');
        field.$refs.time[lock] = true; field.timePart = '09:00'; field.commit();
        assert.equal(field.value, '2026-09-17T08:00');
    }
});
test('seconds supplied by existing consumers survive a date-only change', () => {
    const field = build('2026-09-17T08:00:30');
    field.datePart = '2026-09-18'; field.commit();
    assert.equal(field.value, '2026-09-18T08:00:30');
});
