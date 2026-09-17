import assert from 'node:assert/strict';
import test from 'node:test';

import { dateField } from '../../resources/js/date-field.js';

/**
 * Baut die Komponente ohne Alpine auf. Getestet wird ausschliesslich die
 * reine Logik — Eingabe auswerten, Monatsraster erzeugen, Popover verorten.
 */
const build = (config = {}, value = '') => {
    const field = dateField({ locale: 'de-DE', weekStart: 1, ...config });
    field.value = value;
    field.$refs = {};
    field.$nextTick = (fn) => fn?.();
    field.$watch = () => {};
    field.syncFromValue();

    return field;
};

test('zeigt den ISO-Wert in deutscher Schreibweise', () => {
    assert.equal(build({}, '2026-08-01').display, '01.08.2026');
});

test('nimmt getippte Datumsangaben in mehreren Schreibweisen an', () => {
    const field = build();

    assert.equal(field.parseTyped('01.08.2026'), '2026-08-01');
    assert.equal(field.parseTyped('1.8.26'), '2026-08-01');
    assert.equal(field.parseTyped('2026-08-01'), '2026-08-01');
    assert.equal(field.parseTyped('1/8/2026'), '2026-08-01');
});

test('verwirft Scheindaten, die der Date-Konstruktor still verschieben wuerde', () => {
    const field = build();

    assert.equal(field.parseTyped('31.02.2026'), null);
    assert.equal(field.parseTyped('irgendwas'), null);
    assert.equal(field.parseTyped('1.8'), null);
});

test('stellt bei unlesbarer Eingabe den letzten gueltigen Wert wieder her', () => {
    const field = build({}, '2026-08-01');
    field.display = '99.99.9999';
    field.commitTyped();

    assert.equal(field.value, '2026-08-01');
    assert.equal(field.display, '01.08.2026');
});

test('leert den Wert, wenn das Feld geleert wird', () => {
    const field = build({}, '2026-08-01');
    field.display = '';
    field.commitTyped();

    assert.equal(field.value, '');
});

test('beachtet min und max', () => {
    const field = build({ min: '2026-08-01', max: '2026-08-31' }, '2026-08-10');

    assert.equal(field.isSelectable('2026-07-31'), false);
    assert.equal(field.isSelectable('2026-09-01'), false);
    assert.equal(field.isSelectable('2026-08-01'), true);

    field.select('2026-07-31');
    assert.equal(field.value, '2026-08-10', 'Ausserhalb der Grenzen darf nichts uebernommen werden.');
});

test('beginnt das Monatsraster am Montag und umfasst sechs Wochen', () => {
    const field = build({}, '2026-08-01');
    const days = field.days;

    assert.equal(days.length, 42);
    // Der 1. August 2026 ist ein Samstag — die Woche beginnt am 27. Juli.
    assert.equal(days[0].iso, '2026-07-27');
    assert.equal(days[0].outside, true);
    assert.equal(days.find((day) => day.iso === '2026-08-01').selected, true);
});

test('haelt das Popover im sichtbaren Bereich und kippt es bei Platzmangel nach oben', () => {
    const field = build({}, '2026-08-01');
    global.window = { innerWidth: 390, innerHeight: 844 };

    field.$refs.anchor = {
        getBoundingClientRect: () => ({ left: 300, right: 380, top: 700, bottom: 744 }),
    };
    field.position();

    const style = Object.fromEntries(
        field.panelStyle.split(';').filter(Boolean).map((part) => part.split(':')),
    );

    assert.equal(style.width, '304px');
    // 300 + 304 waere rechts draussen — die linke Kante wird zurueckgezogen.
    assert.equal(Number.parseInt(style.left, 10) + 304 <= 390, true);
    // Unterhalb bleiben nur 100 px, oberhalb 700 — also nach oben kippen.
    assert.equal(Number.parseInt(style.top, 10) < 700, true);

    delete global.window;
});
