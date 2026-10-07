import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { revealStaffTimeline, clearStaffTimelineReveals } from '../../resources/js/staff-timeline.js';

function fixture(run, { reduced = false, gsap = true, enabled = true } = {}) {
    const { document } = parseHTML(`<div id="root" data-timeline-motion="${enabled}"><div id="grid">
        <div class="rt-personnel-timeline-name" id="person"></div>
        <div class="rt-personnel-timeline-name" id="outside"></div>
        <div class="rt-personnel-timeline-event" id="event" style="--event-left:25%;--event-width:10%;transform:translateX(2px)"></div>
    </div></div>`);
    const root = document.getElementById('root'), grid = document.getElementById('grid');
    const person = document.getElementById('person'), event = document.getElementById('event');
    const rect = { top: 10, bottom: 58, left: 0, right: 100, width: 100, height: 48 };
    const measure = element => {
        element.getBoundingClientRect = () => rect;
        element.style.getPropertyPriority = () => '';
    };
    [person, event, document.getElementById('outside')].forEach(measure);
    document.getElementById('outside').getBoundingClientRect = () => ({ ...rect, top: 999, bottom: 1047 });
    const body = { getBoundingClientRect: () => ({ top: 0, bottom: 500, left: 0, right: 1000 }), scrollLeft: 215, scrollTop: 100 };
    const listeners = new Set(), calls = [];
    const media = { matches: reduced, addEventListener(name, fn) { assert.equal(name, 'change'); listeners.add(fn); },
        removeEventListener(name, fn) { assert.equal(name, 'change'); listeners.delete(fn); } };
    const engine = { fromTo(targets, from, to) {
        targets.forEach(target => target.style.setProperty('opacity', String(from.opacity)));
        const tween = { killed: false, kill() { this.killed = true; } };
        calls.push({ targets, from, to, tween });
        return tween;
    } };
    const originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
    globalThis.window = { gsap: gsap ? engine : null, matchMedia: () => media };
    try { run({ root, grid, body, person, event, document, measure, media, listeners, calls }); }
    finally {
        clearStaffTimelineReveals(root);
        if (originalWindow) Object.defineProperty(globalThis, 'window', originalWindow);
        else delete globalThis.window;
    }
}

test('visible personnel appear before time bands with opacity only and bounded duration', () => fixture(({ root, grid, body, person, event, calls }) => {
    revealStaffTimeline(root, grid, body);
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[0].targets, [person]);
    assert.deepEqual(calls[1].targets, [event]);
    assert.equal(calls[0].to.delay, 0);
    assert.equal(calls[1].to.delay, .04);
    for (const call of calls) {
        assert.deepEqual(Object.keys(call.from), ['opacity']);
        assert.equal(call.to.opacity, 1);
        assert.ok(call.to.duration + call.to.delay + call.to.stagger.amount <= .3);
        for (const field of ['x', 'y', 'scale', 'width', 'height']) assert.equal(call.to[field], undefined);
    }
    assert.equal(event.style.getPropertyValue('--event-left'), '25%');
    assert.equal(event.style.getPropertyValue('--event-width'), '10%');
    assert.equal(event.style.getPropertyValue('transform'), 'translateX(2px)');
    assert.equal(body.scrollLeft, 215);
    assert.equal(body.scrollTop, 100);
    assert.equal(root.style.getPropertyValue('--timeline-name-width'), undefined);
}));

test('measure and resize never replay existing rows; newly inserted visible bands reveal once', () => fixture(({ root, grid, body, calls, document, measure }) => {
    revealStaffTimeline(root, grid, body);
    revealStaffTimeline(root, grid, body);
    assert.equal(calls.length, 2);
    const inserted = document.createElement('div');
    inserted.className = 'rt-personnel-timeline-event';
    measure(inserted);
    grid.append(inserted);
    revealStaffTimeline(root, grid, body);
    assert.equal(calls.length, 3);
    assert.deepEqual(calls[2].targets, [inserted]);
}));

test('completion restores prior inline opacity and only owned styles', () => fixture(({ root, grid, body, person, event, calls }) => {
    person.style.setProperty('opacity', '.8');
    revealStaffTimeline(root, grid, body);
    calls.forEach(call => call.to.onComplete());
    assert.equal(person.style.getPropertyValue('opacity'), '.8');
    assert.equal(event.style.getPropertyValue('opacity'), undefined);
    assert.equal(event.style.getPropertyValue('transform'), 'translateX(2px)');
    assert.equal(root.querySelectorAll('[data-timeline-revealing]').length, 0);
    assert.ok(calls.every(call => call.tween.killed));
}));

test('destroy removes own media listener, kills all batches and stale callbacks stay inert', () => fixture(({ root, grid, body, calls, listeners, event }) => {
    revealStaffTimeline(root, grid, body);
    assert.equal(listeners.size, 1);
    clearStaffTimelineReveals(root);
    assert.equal(listeners.size, 0);
    assert.ok(calls.every(call => call.tween.killed));
    event.style.setProperty('opacity', '.9');
    calls.forEach(call => call.to.onComplete());
    assert.equal(event.style.getPropertyValue('opacity'), '.9');
}));

test('live reduced-motion change finishes all reveals without new animations', () => fixture(({ root, grid, body, calls, listeners, media, event }) => {
    revealStaffTimeline(root, grid, body);
    media.matches = true;
    listeners.forEach(callback => callback());
    assert.ok(calls.every(call => call.tween.killed));
    assert.equal(event.style.getPropertyValue('opacity'), undefined);
    revealStaffTimeline(root, grid, body);
    assert.equal(calls.length, 2);
}));

for (const options of [{ reduced: true }, { gsap: false }, { enabled: false }]) {
    test(`content is immediately visible for ${JSON.stringify(options)}`, () => fixture(({ root, grid, body, event, calls }) => {
        revealStaffTimeline(root, grid, body);
        assert.equal(calls.length, 0);
        assert.equal(event.style.getPropertyValue('opacity'), undefined);
        assert.equal(root.querySelectorAll('[data-timeline-revealing]').length, 0);
    }, options));
}

test('cached history cleanup restores only marked interrupted entrance styles', () => fixture(({ root, event, person }) => {
    event.dataset.timelineRevealing = 'true';
    event.dataset.timelineRevealOpacity = '.7';
    event.dataset.timelineRevealPriority = '';
    event.style.setProperty('opacity', '0');
    person.style.setProperty('opacity', '.6');
    clearStaffTimelineReveals(root);
    assert.equal(event.style.getPropertyValue('opacity'), '.7');
    assert.equal(person.style.getPropertyValue('opacity'), '.6');
    assert.equal(event.dataset.timelineRevealing, undefined);
}));

test('long lists cap visible work and do not animate out-of-viewport rows', () => fixture(({ root, grid, body, calls, document, measure }) => {
    for (let i = 0; i < 300; i++) {
        const target = document.createElement('div');
        target.className = i % 2 ? 'rt-personnel-timeline-name' : 'rt-personnel-timeline-event';
        measure(target);
        grid.append(target);
    }
    revealStaffTimeline(root, grid, body);
    assert.equal(calls[0].targets.length, 24);
    assert.equal(calls[1].targets.length, 48);
    assert.equal(calls.some(call => call.targets.includes(document.getElementById('outside'))), false);
}));
