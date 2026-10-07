import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { staffTimeline, timelineDayWidth, timelineDayOffset, timelineEventLanes } from '../../resources/js/staff-timeline.js';
import { timelinePlanning } from '../../resources/js/timeline-planning-actions.js';

function planningFixture() {
    const events = [];
    const planner = timelinePlanning();
    Object.assign(planner, { $wire: { $id: 'qa', assignmentOpen: false }, $dispatch: (name, detail) => events.push({ name, detail }), $nextTick: (fn) => fn() });
    const cell = (user, date = '2027-05-13') => {
        const anchor = { dataset: { user: String(user), date }, isConnected: true, removeAttribute(name) { delete this[name]; }, contains: () => false };
        anchor.closest = () => anchor;
        return anchor;
    };
    return { planner, events, cell };
}

test('cell opens one anchored panel immediately; late closed results cannot reopen it', async () => {
    const { planner, cell, events } = planningFixture();
    let resolve;
    planner.request = () => new Promise((done) => { resolve = done; });
    planner.openPlanner({ target: cell(2) });
    assert.equal(planner.plannerVisible, true);
    assert.equal(planner.plannerLoading, true);
    assert.equal(events[0].detail.id, 'rt-dropdown-timeline-planner-qa');
    planner.closePlanner();
    resolve();
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerVisible, false);
    assert.equal(planner.$wire.assignmentOpen, false);
    assert.equal(events.some((event) => event.name === 'rt-anchor-dropdown-focus'), false);
});

test('rapid cell changes serialize requests and only focus the latest selection', async () => {
    const { planner, cell, events } = planningFixture();
    const calls = [];
    const resolves = [];
    planner.request = (method, args) => { calls.push([method, args]); return new Promise((resolve) => resolves.push(resolve)); };
    planner.openPlanner({ target: cell(1) });
    planner.openPlanner({ target: cell(2) });
    planner.openPlanner({ target: cell(3) });
    assert.equal(calls.length, 1);
    resolves.shift()();
    await new Promise((done) => setImmediate(done));
    assert.deepEqual(calls.map((call) => call[1][0]), [1, 3]);
    resolves.shift()();
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerLoading, false);
    assert.equal(events.filter((event) => event.name === 'rt-anchor-dropdown-focus').length, 1);
});

test('results stay hidden through the Livewire morph and a closed render cannot reveal stale content', async () => {
    const { planner, cell } = planningFixture();
    const ticks = [];
    planner.$nextTick = (fn) => ticks.push(fn);
    planner.request = async () => {};
    planner.openPlanner({ target: cell(1) });
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerLoading, true);
    assert.equal(planner.plannerReady, false);
    ticks.shift()();
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerReady, true);
    assert.equal(planner.plannerLoading, false);
    ticks.shift()();
    planner.openPlanner({ target: cell(2) });
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerReady, false);
    planner.closePlanner();
    ticks.shift()();
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerReady, false);
    assert.equal(planner.plannerVisible, false);
});

test('hover preview is debounced, cached, never opens an assignment and ignores obsolete anchors', async () => {
    const { planner, cell } = planningFixture();
    const first = cell(1), next = cell(2);
    let calls = 0;
    planner.request = async () => { calls++; return { state: 'suitable', label: '1 passend', detail: 'Vorläufig' }; };
    planner.hoverCell({ target: first, pointerType: 'touch' });
    assert.equal(planner.hoverAnchor, null);
    planner.hoverCell({ target: first, pointerType: 'mouse' });
    planner.clearHover();
    planner.hoverCell({ target: next, pointerType: 'mouse' });
    await new Promise((done) => setTimeout(done, 310));
    assert.equal(calls, 1);
    assert.equal(next.dataset.fit, 'suitable');
    assert.equal(first.dataset.fit, undefined);
    assert.equal(planner.plannerVisible, false);
    planner.clearHover();
    planner.hoverCell({ target: next, pointerType: 'mouse' });
    await new Promise((done) => setTimeout(done, 310));
    assert.equal(calls, 1);
    planner.invalidate();
    assert.equal(planner.hoverCache.size, 0);
    assert.equal(next.dataset.fit, undefined);
});

test('failed selection shows an error without leaving a permanent loading panel', async () => {
    const { planner, cell } = planningFixture();
    planner.request = async () => { throw new Error('offline'); };
    planner.openPlanner({ target: cell(1) });
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.plannerLoading, false);
    assert.match(planner.plannerError, /erneut versuchen/);
});

test('suggestion switch loads immediately, rejects duplicate clicks and waits for the confirmed render', async () => {
    const { planner } = planningFixture();
    const input = { checked: true, isConnected: true };
    const ticks = [];
    const calls = [];
    let resolve;
    planner.$wire.showSuggestions = false;
    planner.$nextTick = (callback) => ticks.push(callback);
    planner.request = (method, args) => {
        calls.push([method, args]);
        return new Promise((done) => { resolve = done; });
    };

    const pending = planner.changeSuggestions({ target: input });
    assert.equal(planner.suggestionsLoading, true);
    assert.equal(input.checked, false, 'native checkbox must not claim an unconfirmed state');
    await planner.changeSuggestions({ target: input });
    assert.deepEqual(calls, [['toggleSuggestions', []]]);

    resolve();
    await new Promise((done) => setImmediate(done));
    assert.equal(planner.suggestionsLoading, true, 'keep feedback until Livewire DOM morph');
    planner.$wire.showSuggestions = true;
    ticks.shift()();
    await pending;
    assert.equal(input.checked, true);
    assert.equal(planner.suggestionsLoading, false);
    assert.equal(planner.suggestionsError, '');
});

test('suggestion switch restores state on commit failure and can be retried', async () => {
    const { planner } = planningFixture();
    const input = { checked: false, isConnected: true };
    planner.$wire.showSuggestions = true;
    let hook;
    let fail;
    let cleaned = 0;
    planner.$wire.$hook = (event, callback) => {
        assert.equal(event, 'commit');
        hook = callback;
        return () => { cleaned++; };
    };
    planner.$wire.toggleSuggestions = () => new Promise(() => {});
    const pending = planner.changeSuggestions({ target: input });
    assert.equal(input.checked, true);
    hook({ commit: { calls: [{ method: 'previewCell' }] }, fail: () => assert.fail('unrelated commit must not reject toggle') });
    hook({ commit: { calls: [{ method: 'toggleSuggestions' }] }, fail: (callback) => { fail = callback; } });
    fail();
    await pending;
    assert.equal(planner.suggestionsLoading, false);
    assert.equal(input.checked, true);
    assert.equal(cleaned, 1);
    assert.match(planner.suggestionsError, /erneut schalten/);

    planner.$wire.toggleSuggestions = async () => { planner.$wire.showSuggestions = false; };
    await planner.changeSuggestions({ target: input });
    assert.equal(input.checked, false);
    assert.equal(planner.suggestionsError, '');
    assert.equal(cleaned, 2);
});

test('teleported switches use their explicit timeline owner, never the header owner or another timeline', async () => {
    const previousLivewire = globalThis.Livewire;
    const calls = [];
    const owners = {
        first: { showSuggestions: false, toggleSuggestions: async () => { calls.push('first'); owners.first.showSuggestions = true; } },
        second: { showSuggestions: true, toggleSuggestions: async () => { calls.push('second'); owners.second.showSuggestions = false; } },
    };
    globalThis.Livewire = { find: (id) => owners[id] };
    // Livewire exposes unknown properties as callable action fallbacks. Accessing
    // the destination header's magic would therefore call a nonexistent action.
    const headerWire = new Proxy({}, { get: () => assert.fail('teleported suggestion control read the header component') });
    try {
        const first = timelinePlanning('first');
        const second = timelinePlanning('second');
        for (const planner of [first, second]) {
            planner.$wire = headerWire;
            planner.$nextTick = (callback) => callback();
        }
        assert.equal(first.suggestionsEnabled, false);
        assert.equal(second.suggestionsEnabled, true);
        const firstInput = { checked: true, isConnected: true };
        await first.changeSuggestions({ target: firstInput });
        assert.equal(firstInput.checked, true);
        assert.equal(first.suggestionsEnabled, true);
        assert.equal(second.suggestionsEnabled, true);
        assert.deepEqual(calls, ['first']);

        const secondInput = { checked: false, isConnected: true };
        await second.changeSuggestions({ target: secondInput });
        assert.equal(secondInput.checked, false);
        assert.equal(second.suggestionsEnabled, false);
        assert.equal(first.suggestionsEnabled, true);
        assert.deepEqual(calls, ['first', 'second']);
    } finally {
        globalThis.Livewire = previousLivewire;
    }
});

test('disposed suggestion switch cannot start or repaint detached UI', async () => {
    const { planner } = planningFixture();
    const input = { checked: true, isConnected: true };
    planner.$wire.showSuggestions = false;
    let reject;
    planner.request = () => new Promise((resolve, fail) => { reject = fail; });
    const pending = planner.changeSuggestions({ target: input });
    planner.disposed = true;
    input.isConnected = false;
    reject(new Error('network failed after timeline was unmounted'));
    await pending;
    assert.equal(planner.suggestionsError, '');
    planner.request = () => assert.fail('unmounted component must not request');
    input.checked = true;
    await planner.changeSuggestions({ target: input });
    assert.equal(input.checked, true, 'unmounted component must not repaint a stale control');
});

test('responsive widths fit complete days, including narrow phones and single-day ranges', () => {
    for (const [available, minimum, days, expected] of [[1200, 190, 7, 200], [147, 190, 7, 147], [440, 190, 1, 440], [570, 190, 7, 190], [500, 190, 94, 250]]) {
        assert.equal(timelineDayWidth(available, minimum, days), expected);
        assert.equal(available % expected, 0);
    }
});

test('day navigation snaps to full columns and clamps both range edges', () => {
    assert.equal(timelineDayOffset(0, 200, -1, 400), 0);
    assert.equal(timelineDayOffset(0, 200, 1, 400), 200);
    assert.equal(timelineDayOffset(205, 200, 1, 400), 400);
    assert.equal(timelineDayOffset(400, 200, 1, 400), 400);
    assert.equal(timelineDayOffset(200, 200, -1, 400), 0);
});

test('short duty badges keep readable time text inside the day without stretching the colored duty', () => {
    const [start, finish] = timelineEventLanes([
        { start: 0, duration: 2, labelWidth: 76, lane: 0 },
        { start: 98, duration: 2, labelWidth: 76, lane: 0 },
    ], 200);
    assert.deepEqual(start, { lane: 0, labelOffset: 42, badgeWidth: 76 });
    assert.deepEqual(finish, { lane: 0, labelOffset: -38, badgeWidth: 76 });
});

test('long duty badges span the actual duration while full-day badges keep their text inside the edges', () => {
    const positions = timelineEventLanes([
        { start: 25, duration: 50, labelWidth: 76, lane: 0 },
        { start: 0, duration: 100, labelWidth: 60, lane: 1 },
    ], 240);
    assert.deepEqual(positions[0], { lane: 0, labelOffset: 60, badgeWidth: 120 });
    assert.deepEqual(positions[1], { lane: 1, labelOffset: 120, badgeWidth: 232 });
});

test('overlapping readable time badges get separate compact lanes while separated duties share a lane', () => {
    const positions = timelineEventLanes([
        { start: 25, duration: 8, labelWidth: 80, lane: 0 },
        { start: 34, duration: 8, labelWidth: 80, lane: 0 },
        { start: 75, duration: 8, labelWidth: 80, lane: 0 },
    ], 240);
    assert.deepEqual(positions.map(position => position.lane), [0, 1, 0]);
});

test('true overlap lanes remain distinct regardless of label width and input order', () => {
    const positions = timelineEventLanes([
        { start: 50, duration: 25, labelWidth: 40, lane: 1 },
        { start: 25, duration: 50, labelWidth: 40, lane: 0 },
    ], 300);
    assert.deepEqual(positions.map(position => position.lane), [1, 0]);
});

function harness() {
    const timeline = staffTimeline();
    timeline.$refs = {
        timelineBody: { scrollLeft: 0, clientWidth: 1180, scrollWidth: 1580 },
        timelineHeader: { scrollLeft: 0 },
        timelineScrollbar: { scrollLeft: 0 },
    };
    return timeline;
}

function personnelHarness(run, { fullNameWidth = 180, clientWidth = 900, days = 7, deferredNativeScroll = false } = {}) {
    const { document, window } = parseHTML(`<!doctype html><html><body>
        <div id="timeline">
            <div data-timeline-person-column><button data-timeline-person-toggle>Toggle</button></div>
            <div id="person-one" data-timeline-person-column>
                <div data-rt-dropdown-root data-rt-dropdown-id="person-one-preview"><button id="person-button">Person</button></div>
            </div>
            <div id="person-two" data-timeline-person-column><button id="second-person-button">Person</button></div>
            <div id="timeline-grid">
                <div class="rt-personnel-timeline-track" data-timeline-lanes="1">
                    <div class="rt-personnel-timeline-event" data-time-start="25" data-time-width="1" data-time-lane="0"><span class="rt-personnel-timeline-time-text">08–10</span></div>
                    <div class="rt-personnel-timeline-event" data-time-start="27" data-time-width="1" data-time-lane="0"><span class="rt-personnel-timeline-time-text">10–12</span></div>
                </div>
                <button id="day-cell">Day</button>
            </div>
        </div>
        <div data-rt-dropdown-panel data-rt-dropdown-owner="person-one-preview">
            <button id="preview-action">Preview</button>
            <div data-rt-dropdown-root data-rt-dropdown-id="nested-preview"><button>Nested trigger</button></div>
        </div>
        <div data-rt-dropdown-panel data-rt-dropdown-owner="nested-preview"><button id="nested-action">Nested action</button></div>
        <div data-rt-dropdown-root data-rt-dropdown-id="unrelated-preview"></div>
        <div data-rt-dropdown-panel data-rt-dropdown-owner="unrelated-preview"><button id="unrelated-action">Other preview</button></div>
        <div data-timeline-person-column><button id="other-timeline-person">Other timeline</button></div>
    </body></html>`);
    const timeline = harness();
    const root = document.getElementById('timeline');
    const frames = new Map();
    let nextFrame = 0;
    let offset = 0;
    let footerOffset = 0;
    const activeWidth = () => root.getAttribute('data-personnel-compact') === 'true' ? 52 : fullNameWidth;
    const dayWidth = () => parseFloat(root.style.getPropertyValue('--timeline-day-width')) || 360;
    const body = {
        clientWidth,
        offsetWidth: clientWidth + 15,
        scrollTop: 144,
        scrollWrites: 0,
        get scrollWidth() { return Math.max(this.clientWidth, activeWidth() + days * dayWidth()); },
        get scrollLeft() {
            return deferredNativeScroll ? offset : offset = Math.max(0, Math.min(offset, this.scrollWidth - this.clientWidth));
        },
        set scrollLeft(value) { this.scrollWrites++; offset = Math.max(0, Math.min(value, this.scrollWidth - this.clientWidth)); },
        set nativeOffset(value) { offset = Math.max(0, Math.min(value, this.scrollWidth - this.clientWidth)); },
    };
    Object.assign(timeline, {
        $el: root,
        $nextTick: (callback) => callback(),
    });
    timeline.$refs.timelineBody = body;
    timeline.$refs.timelineScrollbar = {
        get scrollLeft() { return footerOffset = Math.max(0, Math.min(footerOffset, body.scrollWidth - body.clientWidth)); },
        set scrollLeft(value) { footerOffset = Math.max(0, Math.min(value, body.scrollWidth - body.clientWidth)); },
    };
    timeline.$refs.timelineGrid = document.getElementById('timeline-grid');
    for (const label of root.querySelectorAll('.rt-personnel-timeline-time-text')) {
        label.getBoundingClientRect = () => ({ width: 90 });
    }
    const overrides = {
        document,
        Element: window.Element,
        getComputedStyle: () => ({ getPropertyValue(name) {
            return ({ '--timeline-name-full-width': `${fullNameWidth}px`, '--timeline-name-width': `${activeWidth()}px`,
                '--timeline-day-min-width': '260px', '--timeline-days': String(days) })[name] || root.style.getPropertyValue(name);
        } }),
        requestAnimationFrame: (callback) => { frames.set(++nextFrame, callback); return nextFrame; },
        cancelAnimationFrame: (id) => frames.delete(id),
    };
    const originals = Object.fromEntries(Object.keys(overrides).map((name) => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
    Object.assign(globalThis, overrides);
    try {
        run({ timeline, root, body, document, frames });
    } finally {
        for (const [name, descriptor] of Object.entries(originals)) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else delete globalThis[name];
        }
    }
}

test('rightward scrolling compacts staff, leftward scrolling expands and vertical or tiny changes do not toggle', () => {
    const timeline = harness();
    const body = timeline.$refs.timelineBody;
    assert.equal(timeline.personnelCompact, false);
    body.scrollTop = 180;
    timeline.syncHorizontal(body);
    assert.equal(timeline.compactRequested, false);
    body.scrollLeft = .25;
    timeline.syncHorizontal(body);
    assert.equal(timeline.compactRequested, false);
    body.scrollLeft = 120;
    timeline.syncHorizontal(body);
    assert.equal(timeline.personnelCompact, true);
    body.scrollTop = 360;
    timeline.syncHorizontal(body);
    assert.equal(timeline.personnelCompact, true);
    body.scrollLeft = 100;
    timeline.syncHorizontal(body);
    assert.equal(timeline.personnelCompact, false);
    body.scrollLeft = 101;
    timeline.syncHorizontal(body);
    assert.equal(timeline.personnelCompact, true);
});

test('mouse movement between staff rows and owned previews keeps temporary expansion without changing direction preference', () => {
    personnelHarness(({ timeline, document }) => {
        const first = document.getElementById('person-button');
        const second = document.getElementById('second-person-button');
        const preview = document.getElementById('preview-action');
        const day = document.getElementById('day-cell');
        timeline.setCompactRequested(true);
        timeline.pointerPersonnel({ type: 'pointerover', pointerType: 'touch', target: first });
        assert.equal(timeline.personnelCompact, true);
        timeline.pointerPersonnel({ type: 'pointerover', pointerType: 'mouse', target: first });
        assert.equal(timeline.personnelCompact, false);
        assert.equal(timeline.compactRequested, true);
        for (const target of [second, preview]) {
            timeline.pointerPersonnel({ type: 'pointerout', pointerType: 'mouse', target: first, relatedTarget: target });
            assert.equal(timeline.personnelCompact, false);
        }
        timeline.pointerPersonnel({ type: 'pointerout', pointerType: 'mouse', target: preview, relatedTarget: day });
        assert.equal(timeline.personnelCompact, true);
        timeline.pointerPersonnel({ type: 'pointerover', pointerType: 'mouse', target: document.getElementById('other-timeline-person') });
        assert.equal(timeline.personnelCompact, true);
    });
});

test('focus follows staff and nested teleported preview ownership but does not latch the header toggle or other timelines', () => {
    personnelHarness(({ timeline, document }) => {
        timeline.setCompactRequested(true);
        for (const id of ['person-button', 'preview-action', 'nested-action']) {
            timeline.focusPersonnel(document.getElementById(id));
            assert.equal(timeline.personnelFocused, true, id);
            assert.equal(timeline.personnelCompact, false, id);
            assert.equal(timeline.compactRequested, true);
        }
        for (const target of [document.querySelector('[data-timeline-person-toggle]'), document.getElementById('unrelated-action'),
            document.getElementById('other-timeline-person'), document.getElementById('day-cell'), null]) {
            timeline.focusPersonnel(target);
            assert.equal(timeline.personnelFocused, false);
            assert.equal(timeline.personnelCompact, true);
        }
    });
});

test('an explicit header toggle changes the effective state even while hover and focus temporarily expand staff', () => {
    personnelHarness(({ timeline, document }) => {
        timeline.setCompactRequested(true);
        timeline.pointerPersonnel({ type: 'pointerover', pointerType: 'mouse', target: document.getElementById('person-button') });
        timeline.focusPersonnel(document.getElementById('preview-action'));
        assert.equal(timeline.personnelCompact, false);
        timeline.togglePersonnelColumn();
        assert.equal(timeline.personnelHovered, false);
        assert.equal(timeline.personnelFocused, false);
        assert.equal(timeline.personnelCompact, true);
        timeline.togglePersonnelColumn();
        assert.equal(timeline.personnelCompact, false);
    });
});

test('compact mode retains day scale, event badge lanes and vertical position across repeated measurements', () => {
    personnelHarness(({ timeline, root, body }) => {
        timeline.applyPersonnelMode();
        timeline.measure();
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '360px');
        body.scrollLeft = 200;
        timeline.syncHorizontal(body, false);
        const track = timeline.$refs.timelineGrid.querySelector('.rt-personnel-timeline-track');
        const eventStyles = () => [...track.querySelectorAll('.rt-personnel-timeline-event')].map(event => event.getAttribute('style'));
        const originalEvents = eventStyles();
        const originalLanes = track.style.getPropertyValue('--timeline-lanes');
        assert.equal(Number(originalLanes), 2);
        const originalWrites = body.scrollWrites;
        timeline.setCompactRequested(true);
        timeline.applyPersonnelMode();
        timeline.measure();
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '360px');
        assert.deepEqual(eventStyles(), originalEvents);
        assert.equal(track.style.getPropertyValue('--timeline-lanes'), originalLanes);
        assert.equal(body.scrollLeft, 200);
        assert.equal(body.scrollTop, 144);
        assert.equal(body.scrollWrites, originalWrites, 'layout must not restart native scrolling');
        timeline.setCompactRequested(false);
        timeline.applyPersonnelMode();
        timeline.measure();
        assert.equal(root.dataset.personnelCompact, 'false');
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '360px');
        assert.deepEqual(eventStyles(), originalEvents);
        assert.equal(body.scrollTop, 144);
    });
});

test('native right-edge clamping becomes a layout baseline and cannot be interpreted as a leftward gesture', () => {
    personnelHarness(({ timeline, root, body, frames }) => {
        timeline.applyPersonnelMode();
        body.scrollLeft = body.scrollWidth - body.clientWidth;
        timeline.syncHorizontal(body, false);
        assert.equal(body.scrollLeft, 1800);
        const originalWrites = body.scrollWrites;
        timeline.setCompactRequested(true);
        timeline.applyPersonnelMode();
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(body.scrollLeft, 1672);
        assert.equal(timeline.$refs.timelineHeader.scrollLeft, 1672);
        assert.equal(timeline.$refs.timelineScrollbar.scrollLeft, 1672);
        timeline.syncHorizontal(body); // native event generated by the layout clamp
        assert.equal(timeline.personnelCompact, true);
        assert.equal(timeline.canScrollRight, false);
        assert.equal(body.scrollWrites, originalWrites);
        assert.equal(frames.size, 0, 'mode changes need no second layout frame');
        body.scrollLeft = 1652; // actual user scroll left after the clamp
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
        timeline.applyPersonnelMode();
        assert.equal(body.scrollLeft, 1652);
        assert.equal(timeline.canScrollRight, true);
    });
});

test('delayed native end snapping after collapse and expansion does not reverse personnel mode or replay a clamped footer mirror', () => {
    personnelHarness(({ timeline, root, body }) => {
        const scrollbar = timeline.$refs.timelineScrollbar;
        timeline.applyPersonnelMode();
        timeline.measure();
        body.nativeOffset = 1800;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true);
        timeline.applyPersonnelMode();
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(body.scrollLeft, 1800, 'the body has not applied its native layout correction yet');
        assert.equal(scrollbar.scrollLeft, 1672, 'the footer may already be clamped to its smaller range');
        timeline.measure(); // ResizeObserver may measure before the pending native body scroll.
        timeline.syncHorizontal(scrollbar);
        assert.equal(body.scrollLeft, 1800, 'a clamped mirrored footer event must not restart the body scroll');
        assert.equal(body.scrollWrites, 0);
        body.nativeOffset = 1672;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true, 'delayed native collapse adjustment must not expand staff');
        assert.equal(timeline.$refs.timelineHeader.scrollLeft, 1672);
        assert.equal(scrollbar.scrollLeft, 1672);

        timeline.setCompactRequested(false);
        timeline.applyPersonnelMode();
        assert.equal(root.dataset.personnelCompact, 'false');
        assert.equal(body.scrollLeft, 1672, 'the expanded range snaps to its end in a later native event');
        timeline.measure();
        body.nativeOffset = 1800;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false, 'delayed native expansion adjustment must not collapse staff');
        assert.equal(timeline.$refs.timelineHeader.scrollLeft, 1800);
        assert.equal(scrollbar.scrollLeft, 1800);
        assert.equal(body.scrollWrites, 0);

        body.nativeOffset = 1780;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
        body.nativeOffset = 1800;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true, 'ordinary user input still determines direction');
        timeline.applyPersonnelMode();
        body.nativeOffset = 1672;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true);
        body.nativeOffset = 1652;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false, 'actual user left input after the layout event must be honored');
        assert.equal(body.scrollTop, 144);
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '360px');
    }, { deferredNativeScroll: true });
});

test('measurement rebases horizontal position without inventing a direction or removing an active compact preference', () => {
    personnelHarness(({ timeline, body }) => {
        body.scrollLeft = 240;
        timeline.measure();
        assert.equal(timeline.personnelCompact, false);
        timeline.setCompactRequested(true);
        body.scrollLeft = 220;
        timeline.measure();
        assert.equal(timeline.personnelCompact, true);
        body.scrollLeft = 210;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
    });
});

test('mobile day sizing preserves both ordinary and workload-enabled full staff widths in compact mode', () => {
    for (const [fullNameWidth, expected] of [[124, 196], [170, 150]]) {
        personnelHarness(({ timeline, root }) => {
            timeline.applyPersonnelMode();
            timeline.measure();
            assert.equal(root.style.getPropertyValue('--timeline-day-width'), `${expected}px`);
            timeline.setCompactRequested(true);
            timeline.applyPersonnelMode();
            timeline.measure();
            assert.equal(root.style.getPropertyValue('--timeline-day-width'), `${expected}px`);
        }, { clientWidth: 320, fullNameWidth });
    }
});

test('body scroll mirrors header and footer, with honest edge indicators', () => {
    const timeline = harness();
    const { timelineBody: body, timelineHeader: header, timelineScrollbar: scrollbar } = timeline.$refs;
    timeline.syncHorizontal(body);
    assert.equal(timeline.canScrollLeft, false);
    assert.equal(timeline.canScrollRight, true);
    for (const offset of [200, 400, 0]) {
        body.scrollLeft = offset;
        timeline.syncHorizontal(body);
        assert.equal(header.scrollLeft, offset);
        assert.equal(scrollbar.scrollLeft, offset);
        assert.equal(timeline.canScrollLeft, offset > 0);
        assert.equal(timeline.canScrollRight, offset < 400);
    }
});

test('mirrored footer events do not cancel smooth body scrolling; native footer input still works', () => {
    const timeline = harness();
    const { timelineBody: body, timelineScrollbar: scrollbar } = timeline.$refs;
    body.scrollLeft = 80;
    timeline.syncHorizontal(body);
    timeline.setCompactRequested(false);
    body.scrollLeft = 100; // next animation frame, before the mirrored event arrives
    timeline.syncHorizontal(scrollbar);
    assert.equal(body.scrollLeft, 100);
    assert.equal(timeline.personnelCompact, false, 'mirrored event must not be interpreted as a new user direction');
    timeline.syncHorizontal(body);
    assert.equal(timeline.personnelCompact, true);
    scrollbar.scrollLeft = 300; // user moves the bottom scrollbar
    timeline.syncHorizontal(scrollbar);
    assert.equal(body.scrollLeft, 300);
    assert.equal(timeline.$refs.timelineHeader.scrollLeft, 300);
    scrollbar.scrollLeft = 200;
    timeline.syncHorizontal(scrollbar);
    assert.equal(body.scrollLeft, 200);
    assert.equal(timeline.personnelCompact, false);
});

test('non-overflowing ranges show neither direction indicator', () => {
    const timeline = harness();
    timeline.$refs.timelineBody.scrollWidth = 1180;
    timeline.syncHorizontal(timeline.$refs.timelineBody);
    assert.equal(timeline.canScrollLeft, false);
    assert.equal(timeline.canScrollRight, false);
});

test('destroy disconnects observers and cancels pending layout work', () => {
    const timeline = harness();
    let disconnected = false;
    let contentDisconnected = false;
    let cancelled;
    timeline.observer = { disconnect() { disconnected = true; } };
    timeline.contentObserver = { disconnect() { contentDisconnected = true; } };
    timeline.resizeFrame = 12;
    const previous = globalThis.cancelAnimationFrame;
    globalThis.cancelAnimationFrame = (id) => { cancelled = id; };
    try { timeline.destroy(); } finally { globalThis.cancelAnimationFrame = previous; }
    assert.equal(disconnected, true);
    assert.equal(contentDisconnected, true);
    assert.equal(cancelled, 12);
});
