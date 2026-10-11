import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { timelineDetailCalendar, timelineEventDetails } from '../../resources/js/timeline-event-details.js';

const seed = (first = '2026-10-05', last = '2026-10-06') => ({ gridStart: '2026-09-28', count: 35,
    month: '2026-10', first, last, monthLabel: 'Oktober 2026', rangeLabel: '05.10.2026 bis 06.10.2026' });

function fixture() {
    const { document } = parseHTML('<section class="rt-staff-timeline-layout"><div data-timeline-id="own" class="rt-personnel-timeline"><button></button><button></button></div><div data-timeline-shared-detail></div></section><section class="rt-staff-timeline-layout"><div data-timeline-id="foreign"><button></button></div></section>');
    const host = document.querySelector('[data-timeline-shared-detail]');
    const buttons = [...document.querySelectorAll('button')];
    const detail = { kind: 'shift', title: 'Nachtdienst <img onerror="alert(1)">', status: 'Bestätigt', state: 'confirmed',
        person: { name: 'Mitarbeiter', avatar: '/avatar.png' }, start: { time: '22:00' }, end: { time: '06:00' },
        metadata: [], workload: [], map: { point: null }, calendar: seed(), shiftId: 42, absenceId: null };
    buttons.forEach(button => { button.dataset.timelineDetail = JSON.stringify(detail); });
    const events = [], ticks = [];
    const controller = timelineEventDetails('own');
    Object.assign(controller, { $el: host, $dispatch: (name, detail) => events.push({ name, detail }), $nextTick: fn => ticks.push(fn) });
    controller.init();
    return { controller, buttons, events, ticks, document, detail };
}

test('calendar derives the same civil-day grid without a server call or DST elapsed-hour arithmetic', () => {
    const days = timelineDetailCalendar(seed('2026-10-24', '2026-10-26'));
    assert.equal(days.length, 35);
    assert.equal(days[0].date, '2026-09-28');
    assert.equal(days.at(-1).date, '2026-11-01');
    assert.equal(days.filter(day => day.outside).length, 4);
    assert.deepEqual(days.filter(day => day.selected).map(day => day.date), ['2026-10-24', '2026-10-25', '2026-10-26']);
    assert.equal(days.find(day => day.date === '2026-10-24').start, true);
    assert.equal(days.find(day => day.date === '2026-10-26').end, true);
});

test('42-cell calendars and half-open midnight endings retain exact selected dates', () => {
    const days = timelineDetailCalendar({ gridStart: '2026-07-27', count: 42, month: '2026-08', first: '2026-08-31', last: '2026-08-31' });
    assert.equal(days.length, 42);
    assert.equal(days.at(-1).date, '2026-09-06');
    assert.deepEqual(days.filter(day => day.selected).map(day => day.date), ['2026-08-31']);
    assert.equal(days.find(day => day.selected).start, true);
    assert.equal(days.find(day => day.selected).end, true);
});

test('invalid civil-date seeds and unbounded calendars fail closed', () => {
    for (const invalid of [null, {}, { ...seed(), count: 10000 }, { ...seed(), gridStart: '2026-02-31' }, { ...seed(), gridStart: '0000-01-01' }]) {
        assert.deepEqual(timelineDetailCalendar(invalid), []);
    }
    assert.equal(timelineDetailCalendar({ ...seed(), last: null }).filter(day => day.selected).length, 0);
});

test('only the own native badge opens and focuses one shared dialog immediately', () => {
    const { controller, buttons, events, ticks, detail } = fixture();
    controller.openEvent({ detail: { anchor: buttons[2] } });
    assert.equal(events.length, 0, 'foreign timeline cannot open this shared detail');
    controller.openEvent({ detail: { anchor: buttons[0] } });
    assert.deepEqual(controller.detail, detail);
    assert.equal(controller.detailTab, 'period');
    assert.equal(controller.detailVisible, true);
    assert.deepEqual(events[0], { name: 'rt-anchor-dropdown-open', detail: { id: 'rt-dropdown-timeline-event-own', anchor: buttons[0] } });
    ticks.shift()();
    assert.deepEqual(events[1], { name: 'rt-anchor-dropdown-focus', detail: { id: 'rt-dropdown-timeline-event-own' } });
    assert.equal(controller.detail.title, 'Nachtdienst <img onerror="alert(1)">', 'title remains text data, never HTML');
});

test('switching badges resets tabs and restores old trigger accessibility after primitive cleanup', () => {
    const { controller, buttons, events } = fixture();
    controller.openEvent({ detail: { anchor: buttons[0] } });
    controller.detailTab = 'assignment';
    buttons[1].dataset.timelineDetail = JSON.stringify({ ...controller.detail, title: 'Andere Schicht' });
    controller.$dispatch = (name, detail) => {
        events.push({ name, detail });
        if (name === 'rt-anchor-dropdown-open') for (const attr of ['aria-controls', 'aria-haspopup', 'aria-expanded']) buttons[0].removeAttribute(attr);
    };
    controller.openEvent({ detail: { anchor: buttons[1] } });
    assert.equal(controller.detail.title, 'Andere Schicht');
    assert.equal(controller.detailTab, 'period');
    assert.equal(buttons[0].getAttribute('aria-expanded'), 'false');
    assert.equal(buttons[0].getAttribute('aria-haspopup'), 'dialog');
    assert.equal(buttons[0].getAttribute('aria-controls'), 'rt-dropdown-timeline-event-own-content');
});

test('same badge toggles closed; late scheduled focus cannot reopen it', () => {
    const { controller, buttons, events, ticks } = fixture();
    controller.openEvent({ detail: { anchor: buttons[0] } });
    controller.openEvent({ detail: { anchor: buttons[0] } });
    assert.equal(controller.detailVisible, false);
    assert.equal(events.at(-1).name, 'rt-anchor-dropdown-close');
    ticks.shift()();
    assert.equal(events.some(event => event.name === 'rt-anchor-dropdown-focus'), false);
    assert.equal(buttons[0].getAttribute('aria-expanded'), 'false');
});

test('foreign close/reset events have no effect; own layer close prevents delayed focus', () => {
    const { controller, buttons, events, ticks } = fixture();
    controller.openEvent({ detail: { anchor: buttons[0] } });
    controller.clearDetail({ detail: { timelineId: 'foreign' } });
    controller.detailClosed({ detail: { id: 'rt-dropdown-foreign' } });
    assert.equal(controller.detailVisible, true);
    controller.detailClosed({ detail: { id: controller.eventPanelId() } });
    ticks.shift()();
    assert.equal(controller.detailVisible, false);
    assert.equal(events.filter(event => event.name === 'rt-anchor-dropdown-focus').length, 0);
});

test('malformed badge facts never open the dialog or send a business request', () => {
    const { controller, buttons, events } = fixture();
    for (const payload of ['{', JSON.stringify({ kind: 'shift', title: 'Missing facts' }), JSON.stringify({ ...controller.detail, kind: 'unknown' })]) {
        buttons[0].dataset.timelineDetail = payload;
        controller.openEvent({ detail: { anchor: buttons[0] } });
    }
    assert.equal(events.length, 0);
    assert.equal(controller.detailVisible, false);
});

test('confirmed timeline mutations close stale details; shared-calendar mutations do not', () => {
    const oldObserver = globalThis.MutationObserver;
    let callback, disconnected = false;
    globalThis.MutationObserver = class {
        constructor(fn) { callback = fn; }
        observe() {}
        disconnect() { disconnected = true; }
    };
    try {
        const { controller, buttons, document, events } = fixture();
        controller.openEvent({ detail: { anchor: buttons[0] } });
        callback([{ type: 'childList', target: document.querySelector('[data-timeline-shared-detail]') }]);
        assert.equal(controller.detailVisible, true);
        callback([{ type: 'attributes', attributeName: 'data-timeline-detail', target: buttons[0] }]);
        assert.equal(controller.detailVisible, false);
        assert.equal(events.at(-1).name, 'rt-anchor-dropdown-close');
        controller.destroy();
        assert.equal(disconnected, true);
        controller.openEvent({ detail: { anchor: buttons[1] } });
        assert.equal(controller.detailVisible, false);
    } finally { globalThis.MutationObserver = oldObserver; }
});
