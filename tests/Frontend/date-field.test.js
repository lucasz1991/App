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

    assert.equal(style.width, '336px');
    // Der Anker rechts darf das breitere Wochenraster nicht hinausschieben.
    assert.equal(Number.parseInt(style.left, 10) + 336 <= 390, true);
    // Unterhalb bleiben nur 100 px, oberhalb 700 — also nach oben kippen.
    assert.equal(Number.parseInt(style.top, 10) < 700, true);

    delete global.window;
});

test('uebernimmt initialen ISO-Wert aus Livewire-Konfiguration', () => {
    const field = dateField({ value: '2026-09-17', locale: 'de-DE' });
    field.$refs = {};
    field.syncFromValue();

    assert.equal(field.value, '2026-09-17');
    assert.equal(field.display, '17.09.2026');
});

test('externe Serveraktionen aktualisieren Anzeige und Kalender auch bei offenem Popover', () => {
    const field = build({}, '2026-09-17');
    const watchers = new Map();
    field.$watch = (key, callback) => watchers.set(key, callback);
    field.init();
    field.open = true;
    field.value = '2026-10-02';
    watchers.get('value')();

    assert.equal(field.display, '02.10.2026');
    assert.equal(field.viewMonth, 9);
    assert.equal(field.focusedIso, '2026-10-02');
    assert.equal(field.open, true);
    field.open = false;
    field.value = '2026-08-31';
    watchers.get('value')();
    assert.equal(field.display, '31.08.2026');
    assert.equal(field.viewMonth, 7);
});

test('clearable=false verhindert Leeren ueber Fussaktion und Texteingabe', () => {
    const field = build({ clearable: false }, '2026-09-17');
    field.clear();
    assert.equal(field.value, '2026-09-17');
    field.display = '';
    field.commitTyped();
    assert.equal(field.value, '2026-09-17');
    assert.equal(field.display, '17.09.2026');
});

test('readonly und disabled gelten fuer Tastatur, Oeffnen und Auswahl', () => {
    for (const locked of ['readonly', 'disabled']) {
        const field = build({ [locked]: true }, '2026-09-17');
        field.display = '18.09.2026';
        field.commitTyped();
        field.select('2026-09-18');
        field.clear();
        field.openPanel();
        field.shiftMonth(1);
        assert.equal(field.value, '2026-09-17');
        assert.equal(field.viewMonth, 8);
        assert.equal(field.open, false);
    }
});

test('Monats- und Jahresspruenge erhalten den Tag soweit der Zielmonat ihn besitzt', () => {
    const field = build({}, '2028-01-31');
    field.shiftMonth(1);
    assert.equal(field.focusedIso, '2028-02-29');
    field.shiftMonth(12);
    assert.equal(field.focusedIso, '2029-02-28');
    assert.equal(field.value, '2028-01-31', 'Navigation allein darf keine Auswahl speichern.');
});

test('Pfeiltasten sowie Home und End verwenden die laufende Kalenderwoche', () => {
    const field = build({}, '2026-09-17');
    const press = (key) => field.handleGridKeydown({ key, preventDefault() {} });
    press('Home');
    assert.equal(field.focusedIso, '2026-09-14');
    press('End');
    assert.equal(field.focusedIso, '2026-09-20');
    press('ArrowRight');
    assert.equal(field.focusedIso, '2026-09-21');
    press('ArrowUp');
    assert.equal(field.focusedIso, '2026-09-14');
    assert.equal(field.value, '2026-09-17');
});

test('PageUp/PageDown und Umschalt wechseln Monate und Jahre mit Fokus', () => {
    const field = build({}, '2028-02-29');
    field.handleGridKeydown({ key: 'PageDown', shiftKey: true, preventDefault() {} });
    assert.equal(field.focusedIso, '2029-02-28');
    field.handleGridKeydown({ key: 'PageUp', preventDefault() {} });
    assert.equal(field.focusedIso, '2029-01-28');
});

test('Tastaturnavigation bleibt auf auswaehlbaren Tagen innerhalb min/max', () => {
    const field = build({ min: '2026-09-16', max: '2026-09-18' }, '2026-09-17');
    field.handleGridKeydown({ key: 'Home', preventDefault() {} });
    assert.equal(field.focusedIso, '2026-09-16');
    field.handleGridKeydown({ key: 'End', preventDefault() {} });
    assert.equal(field.focusedIso, '2026-09-18');
    assert.equal(field.canShiftMonth(1), false);
    assert.equal(field.canShiftMonth(-1), false);
    assert.equal(field.days.filter(day => day.focused && !day.disabled).length, 1);
    assert.equal(field.isSelectable('2026-02-31'), false);
});

test('Escape schliesst von jeder Popoverzone und stellt den Ausloeserfokus wieder her', () => {
    const field = build({}, '2026-09-17');
    let focused = 0;
    let prevented = 0;
    field.returnFocusTo = { focus: () => focused++ };
    field.open = true;
    field.handlePanelKeydown({ key: 'Escape', preventDefault: () => prevented++, stopPropagation() {} });
    assert.equal(field.open, false);
    assert.equal(focused, 1);
    assert.equal(prevented, 1);
});

test('auch ein niedriger oder durch Bildschirmtastatur begrenzter Viewport wird eingehalten', () => {
    const field = build({}, '2026-09-17');
    global.window = { innerWidth: 390, innerHeight: 844, visualViewport: { offsetLeft: 0, offsetTop: 100, width: 320, height: 240 } };
    field.$refs.anchor = { getBoundingClientRect: () => ({ left: 280, top: 200, bottom: 244 }) };
    field.position();
    const style = Object.fromEntries(field.panelStyle.split(';').filter(Boolean).map(part => part.split(':')));
    const top = Number.parseInt(style.top, 10);
    const height = Number.parseInt(style['max-height'], 10);
    assert.equal(style.width, '304px');
    assert.ok(top >= 108);
    assert.ok(top + height <= 332);
    assert.ok(height < 220, 'Kein Mindesthoehen-Fallback darf den mobilen Viewport sprengen.');
    delete global.window;
});

test('liefert sechs semantische Wochenreihen und markiert die Auswahl genau einmal', () => {
    const field = build({}, '2026-09-17');
    assert.equal(field.weeks.length, 6);
    assert.ok(field.weeks.every(week => week.length === 7));
    assert.equal(field.days.filter(day => day.selected).length, 1);
});

test('Fokus ausserhalb schliesst ohne Fokusdiebstahl und entfernt globale Listener', () => {
    const field = build({}, '2026-09-17');
    const listeners = new Map();
    const removed = [];
    const host = {
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name) => removed.push(name),
    };
    global.window = { ...host, visualViewport: { ...host } };
    global.document = { ...host };
    field.$refs.anchor = { contains: () => false };
    field.$refs.panel = { contains: () => false };
    field.returnFocusTo = { focus: () => assert.fail('Aussenfokus darf nicht zum Ausloeser zurueckspringen.') };
    field.open = true;
    field.bindReposition();
    listeners.get('focusin')({ target: {} });
    assert.equal(field.open, false);
    assert.equal(field.repositionHandler, null);
    assert.equal(field.focusHandler, null);
    assert.ok(removed.includes('focusin'));
    assert.equal(removed.filter(name => name === 'resize').length, 2);
    delete global.window;
    delete global.document;
});

test('Tab verlaesst den Popover am Rand zum Ausloeser', () => {
    const field = build({}, '2026-09-17');
    const first = { getClientRects: () => [1] };
    const last = { getClientRects: () => [1] };
    let returned = false;
    field.$refs.panel = { querySelectorAll: () => [first, last] };
    field.returnFocusTo = { focus: () => { returned = true; } };
    field.open = true;
    field.handlePanelKeydown({ key: 'Tab', target: last, preventDefault() {} });
    assert.equal(field.open, false);
    assert.equal(returned, true);
});

test('Popoverhoehe enthaelt die Kontur und erzeugt bei genug Platz keinen 2px-Scrollbereich', () => {
    const field = build({}, '2026-09-17');
    global.window = { innerWidth: 1280, innerHeight: 900 };
    field.$refs.anchor = { getBoundingClientRect: () => ({ left: 350, top: 150, bottom: 194 }) };
    field.$refs.panel = { scrollHeight: 408, offsetHeight: 408, clientHeight: 406 };
    field.position();
    assert.match(field.panelStyle, /max-height:410px;/);

    // Nach Anwendung der neuen Hoehe bleibt der Messwert stabil.
    field.$refs.panel = { scrollHeight: 408, offsetHeight: 410, clientHeight: 408 };
    field.position();
    assert.match(field.panelStyle, /max-height:410px;/);

    // Reduzierte Testdoubles ohne Box-Masse erzeugen keinen NaN-Wert.
    field.$refs.panel = { scrollHeight: 408 };
    field.position();
    assert.match(field.panelStyle, /max-height:408px;/);
    assert.doesNotMatch(field.panelStyle, /NaN/);
    delete global.window;
});

test('nach Livewire-Morph gelten aktuelle disabled/readonly-Attribute statt alter Initialkonfiguration', () => {
    const field = build({ disabled: true, readonly: true }, '2026-09-17');
    field.$refs.display = { disabled: false, readOnly: false };
    assert.equal(field.locked, false, 'Serverseitiges Freigeben muss ohne neue Alpine-Instanz wirken.');
    field.$refs.display.disabled = true;
    assert.equal(field.locked, true);
    field.$refs.display.disabled = false;
    field.$refs.display.readOnly = true;
    assert.equal(field.locked, true);
    field.select('2026-09-18');
    assert.equal(field.value, '2026-09-17');
});
