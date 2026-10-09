import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { revealStaffTimeline, clearStaffTimelineReveals, timelineRevealDelays, TIMELINE_REVEAL, TIMELINE_READY_ATTRIBUTE } from '../../resources/js/staff-timeline.js';

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

test('employees enter together first, then the shifts glide in from the right without moving their time positions', () => fixture(({ root, grid, body, person, event, calls }) => {
    revealStaffTimeline(root, grid, body);
    assert.equal(calls.length, 2);
    assert.deepEqual(calls[0].targets.slice(0, 1), [person]);
    assert.deepEqual(calls[1].targets, [event]);
    assert.deepEqual(calls[0].from, { opacity: 0 });
    assert.equal(calls[0].to.delay, 0);
    assert.equal(calls[0].to.stagger, undefined);
    assert.deepEqual(calls[1].from, { opacity: 0, translate: `${TIMELINE_REVEAL.shifts.offset}px 0px` });
    assert.equal(calls[1].to.translate, '0px 0px');
    assert.ok(calls[1].to.stagger(0) >= TIMELINE_REVEAL.people.duration);
    for (const call of calls) {
        assert.equal(call.to.opacity, 1);
        for (const field of ['x', 'y', 'scale', 'width', 'height', 'left']) assert.equal(call.to[field], undefined);
    }
    assert.equal(event.style.getPropertyValue('--event-left'), '25%');
    assert.equal(event.style.getPropertyValue('--event-width'), '10%');
    assert.equal(event.style.getPropertyValue('transform'), 'translateX(2px)');
    assert.equal(body.scrollLeft, 215);
    assert.equal(body.scrollTop, 100);
    assert.equal(root.style.getPropertyValue('--timeline-name-width'), undefined);
}));

test('shift delays run row by row from top to bottom and within a row from left to right', () => {
    const { document } = parseHTML(`<div>
        <div class="rt-personnel-timeline-track" id="a"><b class="rt-personnel-timeline-event" data-time-start="60"></b><b class="rt-personnel-timeline-event" data-time-start="10"></b></div>
        <div class="rt-personnel-timeline-track" id="b"><b class="rt-personnel-timeline-event" data-time-start="5"></b></div>
    </div>`);
    const [late, early, nextRow] = [...document.querySelectorAll('.rt-personnel-timeline-event')];
    const delays = timelineRevealDelays([late, early, nextRow]);
    const { start, row, item } = TIMELINE_REVEAL.shifts;
    assert.equal(delays[1], start);
    assert.equal(delays[0], start + item);
    assert.equal(delays[2], start + row);
});

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

test('completion restores prior inline opacity, translate and only owned styles', () => fixture(({ root, grid, body, person, event, calls }) => {
    person.style.setProperty('opacity', '.8');
    event.style.setProperty('translate', '3px 0px');
    revealStaffTimeline(root, grid, body);
    calls.forEach(call => call.to.onComplete());
    assert.equal(person.style.getPropertyValue('opacity'), '.8');
    assert.equal(event.style.getPropertyValue('opacity'), undefined);
    assert.equal(event.style.getPropertyValue('translate'), '3px 0px');
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

test('the whole loaded page enters together, including rows below the visible area, with bounded row delays', () => fixture(({ root, grid, body, calls, document, measure }) => {
    for (let i = 0; i < 300; i++) {
        const target = document.createElement('div');
        target.className = i % 2 ? 'rt-personnel-timeline-name' : 'rt-personnel-timeline-event';
        measure(target);
        grid.append(target);
    }
    revealStaffTimeline(root, grid, body);
    assert.equal(calls[0].targets.length, 152);
    assert.equal(calls[1].targets.length, 151);
    assert.ok(calls[0].targets.includes(document.getElementById('outside')));
    const { start, row, item } = TIMELINE_REVEAL.shifts;
    const maxDelay = Math.max(...calls[1].targets.map((_, index) => calls[1].to.stagger(index)));
    assert.ok(maxDelay <= start + TIMELINE_REVEAL.rows * row + 151 * item);
}));

test('the first reveal releases the CSS pre-hide in every path, also without motion engine or with reduced motion', () => {
    for (const options of [{}, { gsap: false }, { reduced: true }, { enabled: false }]) {
        fixture(({ root, grid, body, document, calls }) => {
            assert.equal(document.documentElement.hasAttribute(TIMELINE_READY_ATTRIBUTE), false);
            revealStaffTimeline(root, grid, body);
            assert.equal(document.documentElement.getAttribute(TIMELINE_READY_ATTRIBUTE), 'true');
            // With an engine the start values are already written when the marker appears.
            if (calls.length) assert.equal(calls.at(-1).targets[0].style.getPropertyValue('opacity'), '0');
        }, options);
    }
});

test('the pre-hide only applies to animated timelines and always has a no-script fallback', async () => {
    const { readFile } = await import('node:fs/promises');
    const css = await readFile(new URL('../../resources/css/operations-planning.css', import.meta.url), 'utf8');
    const block = css.slice(css.indexOf('html:not([data-rt-timeline-ready])'));
    assert.match(css, /@media \(prefers-reduced-motion: no-preference\) \{\s*html:not\(\[data-rt-timeline-ready\]\) \[data-timeline-motion='true'\]/);
    assert.match(block.slice(0, 260), /animation: rt-timeline-reveal-fallback [^;]+ 3s forwards/);
    assert.match(css, /@keyframes rt-timeline-reveal-fallback \{ to \{ opacity: 1; \} \}/);
});
