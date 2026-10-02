import test from 'node:test';
import assert from 'node:assert/strict';
import { staffTimeline, timelineDayWidth, timelineDayOffset } from '../../resources/js/staff-timeline.js';

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
