import test from 'node:test';
import assert from 'node:assert/strict';
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
    body.scrollLeft = 100; // next animation frame, before the mirrored event arrives
    timeline.syncHorizontal(scrollbar);
    assert.equal(body.scrollLeft, 100);
    scrollbar.scrollLeft = 300; // user moves the bottom scrollbar
    timeline.syncHorizontal(scrollbar);
    assert.equal(body.scrollLeft, 300);
    assert.equal(timeline.$refs.timelineHeader.scrollLeft, 300);
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
    let cancelled;
    timeline.observer = { disconnect() { disconnected = true; } };
    timeline.resizeFrame = 12;
    const previous = globalThis.cancelAnimationFrame;
    globalThis.cancelAnimationFrame = (id) => { cancelled = id; };
    try { timeline.destroy(); } finally { globalThis.cancelAnimationFrame = previous; }
    assert.equal(disconnected, true);
    assert.equal(cancelled, 12);
});
