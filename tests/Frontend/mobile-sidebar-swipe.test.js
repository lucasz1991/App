import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseHTML } from 'linkedom';

import {
    MOBILE_SIDEBAR_BREAKPOINT,
    MOBILE_SIDEBAR_EDGE_ZONE_PX,
    MOBILE_SIDEBAR_SWIPE_EXCLUSION_SELECTOR,
    advanceSidebarDrag,
    beginSidebarDrag,
    isMobileSidebarSwipeExcluded,
    mobileSidebarSwipeThreshold,
    mobileSidebarWidth,
    resolveMobileSidebarSwipe,
    settleSidebarDrag,
} from '../../resources/js/mobile-sidebar-swipe.js';

test('opens the closed mobile sidebar only from the inclusive 28px left edge', () => {
    assert.equal(MOBILE_SIDEBAR_EDGE_ZONE_PX, 28);
    for (const startX of [0, 12, 28, 29, 195, 389]) {
        assert.equal(resolveMobileSidebarSwipe({
            startX,
            startY: 420,
            endX: startX + 90,
            endY: 430,
            sidebarOpen: false,
            viewportWidth: 390,
        }), startX <= 28 ? 'open' : null, `startX=${startX}`);
    }
});

test('closes the open mobile sidebar with the opposite gesture', () => {
    assert.equal(resolveMobileSidebarSwipe({
        startX: 300,
        startY: 420,
        endX: 210,
        endY: 426,
        sidebarOpen: true,
        viewportWidth: 390,
    }), 'close');
});

test('ignores short, vertical, and state-opposite gestures', () => {
    const base = {
        startX: 12,
        startY: 300,
        sidebarOpen: false,
        viewportWidth: 390,
    };

    assert.equal(resolveMobileSidebarSwipe({ ...base, endX: 72, endY: 304 }), null);
    assert.equal(resolveMobileSidebarSwipe({ ...base, endX: 102, endY: 390 }), null);
    assert.equal(resolveMobileSidebarSwipe({ ...base, endX: -78, endY: 304 }), null);
    assert.equal(resolveMobileSidebarSwipe({ ...base, sidebarOpen: true, endX: 102, endY: 304 }), null);
});

test('uses the existing adaptive distance and the shared 1024px boundary', () => {
    assert.equal(mobileSidebarSwipeThreshold(320), 64);
    assert.equal(mobileSidebarSwipeThreshold(390), 78);
    assert.equal(mobileSidebarSwipeThreshold(1000), 110);
    assert.equal(MOBILE_SIDEBAR_BREAKPOINT, 1024);

    assert.equal(resolveMobileSidebarSwipe({
        startX: 100,
        startY: 100,
        endX: 220,
        endY: 100,
        sidebarOpen: false,
        viewportWidth: 1024,
    }), null);
});

test('publishes explicit opt-outs for controls, dialogs, and gesture owners', () => {
    for (const selector of [
        'input',
        'button',
        '[role="dialog"]',
        '[role="slider"]',
        '[data-no-sidebar-swipe]',
    ]) {
        assert.match(MOBILE_SIDEBAR_SWIPE_EXCLUSION_SELECTOR, new RegExp(selector.replace(/[\[\]"]/g, '\\$&')));
    }
});

test('rejects incomplete or invalid coordinates', () => {
    assert.equal(resolveMobileSidebarSwipe({
        startX: 10,
        startY: 20,
        endX: undefined,
        endY: 20,
        sidebarOpen: false,
        viewportWidth: 390,
    }), null);

    for (const startX of [-1, 391, NaN, Infinity]) {
        const input = { startX, startY: 20, endX: 250, endY: 20, sidebarOpen: false, viewportWidth: 390 };
        assert.equal(beginSidebarDrag(input), null);
        assert.equal(resolveMobileSidebarSwipe(input), null);
    }
});

test('drag tracking matches the CSS width contract min(86vw, 300px)', () => {
    assert.equal(mobileSidebarWidth(300), 258);
    assert.equal(mobileSidebarWidth(390), 300);
    assert.equal(mobileSidebarWidth(-1), 300);
});

test('opening drags start only at the left edge, closing drags anywhere', () => {
    assert.equal(beginSidebarDrag({
        startX: MOBILE_SIDEBAR_EDGE_ZONE_PX + 30,
        startY: 300,
        sidebarOpen: false,
        viewportWidth: 390,
    }), null);

    assert.ok(beginSidebarDrag({
        startX: 12,
        startY: 300,
        sidebarOpen: false,
        viewportWidth: 390,
    }));

    assert.ok(beginSidebarDrag({
        startX: 250,
        startY: 300,
        sidebarOpen: true,
        viewportWidth: 390,
    }));

    assert.equal(beginSidebarDrag({
        startX: 12,
        startY: 300,
        sidebarOpen: false,
        viewportWidth: MOBILE_SIDEBAR_BREAKPOINT,
    }), null);
});

test('a drag claims the gesture only on horizontal intent and follows the finger', () => {
    let state = beginSidebarDrag({
        startX: 8,
        startY: 300,
        sidebarOpen: false,
        viewportWidth: 390,
        timestamp: 0,
    });

    // Kleine Bewegung: noch keine Uebernahme.
    state = advanceSidebarDrag(state, { x: 14, y: 302, timestamp: 16 });
    assert.equal(state.claimed, false);
    assert.equal(settleSidebarDrag(state), null);

    // Deutlich horizontal: Geste uebernommen, Fortschritt folgt dem Finger.
    state = advanceSidebarDrag(state, { x: 158, y: 306, timestamp: 64 });
    assert.equal(state.claimed, true);
    assert.ok(state.progress > 0.45 && state.progress < 0.56);

    state = advanceSidebarDrag(state, { x: 308, y: 306, timestamp: 128 });
    assert.equal(state.progress, 1);
    assert.equal(settleSidebarDrag(state), 'open');
});

test('vertical scrolling rejects the drag permanently', () => {
    let state = beginSidebarDrag({
        startX: 8,
        startY: 300,
        sidebarOpen: false,
        viewportWidth: 390,
        timestamp: 0,
    });

    state = advanceSidebarDrag(state, { x: 12, y: 380, timestamp: 32 });
    assert.equal(state.rejected, true);

    state = advanceSidebarDrag(state, { x: 200, y: 380, timestamp: 64 });
    assert.equal(state.claimed, false);
    assert.equal(settleSidebarDrag(state), null);
});

test('release settles by position, a decisive fling wins against position', () => {
    // Weit gezogen, langsam losgelassen -> Position entscheidet (offen).
    let slow = beginSidebarDrag({
        startX: 4,
        startY: 200,
        sidebarOpen: false,
        viewportWidth: 390,
        timestamp: 0,
    });
    slow = advanceSidebarDrag(slow, { x: 220, y: 204, timestamp: 400 });
    slow = advanceSidebarDrag(slow, { x: 221, y: 204, timestamp: 700 });
    assert.equal(settleSidebarDrag(slow), 'open');

    // Nur kurz gezogen, aber krachend geschleudert -> Wurf gewinnt (offen).
    let fling = beginSidebarDrag({
        startX: 4,
        startY: 200,
        sidebarOpen: false,
        viewportWidth: 390,
        timestamp: 0,
    });
    fling = advanceSidebarDrag(fling, { x: 40, y: 202, timestamp: 30 });
    fling = advanceSidebarDrag(fling, { x: 90, y: 203, timestamp: 60 });
    assert.equal(settleSidebarDrag(fling), 'open');

    // Offene Sidebar zurueckgeschleudert -> zu.
    let close = beginSidebarDrag({
        startX: 280,
        startY: 200,
        sidebarOpen: true,
        viewportWidth: 390,
        timestamp: 0,
    });
    close = advanceSidebarDrag(close, { x: 236, y: 201, timestamp: 30 });
    close = advanceSidebarDrag(close, { x: 180, y: 202, timestamp: 60 });
    assert.equal(settleSidebarDrag(close), 'close');
});

// Execute the real app integration, not a copy of its gesture handlers. The
// synthetic DOM supplies layout metrics; no browser, database or session is used.
const appSource = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const integrationSource = appSource.slice(
    appSource.indexOf('function initMobileSidebarSwipe() {'),
    appSource.indexOf('function syncSidebarToggleState() {'),
);
assert.ok(integrationSource.startsWith('function initMobileSidebarSwipe() {'));

function swipeHarness({ open = false, width = 390 } = {}) {
    const { document, window: domWindow } = parseHTML(`<!doctype html><html><body>
        <aside id="app-sidebar"></aside><div class="rt-mobile-sidebar-backdrop"></div>
        <main id="content"><div id="scroller"><div><span id="target">Content</span></div></div></main>
    </body></html>`);
    domWindow.getComputedStyle = (element) => ({ overflowX: element.style.overflowX || 'visible' });
    const windowListeners = new Map();
    const window = {
        innerWidth: width,
        addEventListener(name, handler) {
            const handlers = windowListeners.get(name) || [];
            handlers.push(handler);
            windowListeners.set(name, handlers);
        },
    };
    const actions = [];
    let menuScrolls = 0;
    document.body.classList.toggle('sidebar-enable', open);
    const dependencies = {
        document,
        window,
        Element: domWindow.Element,
        MOBILE_SIDEBAR_BREAKPOINT,
        beginSidebarDrag,
        advanceSidebarDrag,
        settleSidebarDrag,
        resolveMobileSidebarSwipe,
        isMobileSidebarSwipeExcluded,
        setMobileSidebarOpen(value) {
            actions.push(value);
            document.body.classList.toggle('sidebar-enable', value);
        },
        initMenuItemScroll() { menuScrolls += 1; },
    };
    const initialize = new Function(...Object.keys(dependencies), `
        let sidebarSwipeStart = null;
        ${integrationSource}
        return initMobileSidebarSwipe;
    `)(...Object.values(dependencies));
    initialize();
    const target = document.getElementById('target');
    const touch = (x, y = 300, identifier = 1) => ({ clientX: x, clientY: y, identifier });
    function dispatch(type, options = {}) {
        const event = new domWindow.Event(type, { bubbles: true, cancelable: options.cancelable ?? true });
        Object.defineProperties(event, {
            touches: { value: options.touches ?? (type === 'touchend' ? [] : [touch(options.x ?? 8, options.y, options.identifier)]) },
            changedTouches: { value: options.changedTouches ?? [touch(options.x ?? 8, options.y, options.identifier)] },
            timeStamp: { value: options.timeStamp ?? 50 },
        });
        if (options.prevented) event.preventDefault();
        (options.target || target).dispatchEvent(event);
        return event;
    }
    function assertClean() {
        assert.equal(document.body.classList.contains('rt-sidebar-dragging'), false);
        assert.ok(!document.body.style.getPropertyValue('--rt-sidebar-drag-progress'));
    }
    return {
        document, window, target, actions, touch, dispatch, initialize, assertClean,
        get menuScrolls() { return menuScrolls; },
        resize(nextWidth) {
            window.innerWidth = nextWidth;
            for (const handler of windowListeners.get('resize') || []) handler();
        },
    };
}

function setScroller(harness, { overflowX = 'auto', clientWidth = 200, scrollWidth = 600, scrollLeft = 0 } = {}) {
    const scroller = harness.document.getElementById('scroller');
    scroller.style.overflowX = overflowX;
    Object.defineProperties(scroller, {
        clientWidth: { value: clientWidth, configurable: true },
        scrollWidth: { value: scrollWidth, configurable: true },
        scrollLeft: { value: scrollLeft, configurable: true },
    });
    return scroller;
}

test('nested horizontal scrollers own swipes including both scroll limits', () => {
    for (const overflowX of ['auto', 'scroll', 'overlay']) {
        for (const scrollLeft of [0, 200, 400]) {
            const harness = swipeHarness();
            setScroller(harness, { overflowX, scrollLeft });
            assert.equal(isMobileSidebarSwipeExcluded(harness.target), true);
            harness.dispatch('touchstart');
            assert.equal(harness.dispatch('touchmove', { x: 220 }).defaultPrevented, false);
            assert.equal(harness.dispatch('touchend', { x: 220 }).defaultPrevented, false);
            assert.deepEqual(harness.actions, []);
            harness.assertClean();
        }
    }
});

test('scroll exclusion requires actual horizontal overflow and scrollable overflowX', () => {
    const harness = swipeHarness();
    for (const dimensions of [
        { overflowX: 'auto', scrollWidth: 200 },
        { overflowX: 'scroll', scrollWidth: 201 },
        { overflowX: 'visible' },
        { overflowX: 'hidden' },
        { overflowX: 'clip' },
    ]) {
        setScroller(harness, dimensions);
        assert.equal(isMobileSidebarSwipeExcluded(harness.target), false, JSON.stringify(dimensions));
    }
    setScroller(harness, { scrollWidth: 202 });
    assert.equal(isMobileSidebarSwipeExcluded(harness.target), true);
});

test('controls, modal ancestors, and explicit opt-outs never claim sidebar swipes', () => {
    const harness = swipeHarness();
    for (const markup of [
        '<button><span>Button</span></button>',
        '<input>',
        '<textarea></textarea>',
        '<select></select>',
        '<div role="dialog"><span>Modal</span></div>',
        '<div role="slider"><span>Slider</span></div>',
        '<div contenteditable="true"><span>Editable</span></div>',
        '<div data-no-sidebar-swipe><span>Gesture owner</span></div>',
    ]) {
        const wrapper = harness.document.createElement('div');
        wrapper.innerHTML = markup;
        harness.document.body.append(wrapper);
        const target = wrapper.querySelector('span') || wrapper.firstElementChild;
        assert.equal(isMobileSidebarSwipeExcluded(target), true, markup);
        harness.dispatch('touchstart', { target });
        assert.equal(harness.dispatch('touchend', { target, x: 240 }).defaultPrevented, false);
        assert.deepEqual(harness.actions, []);
    }
    assert.equal(isMobileSidebarSwipeExcluded(null), true);
});

test('real handlers follow the edge drag, settle open and initialize only once', () => {
    const harness = swipeHarness();
    harness.initialize();
    harness.dispatch('touchstart', { timeStamp: 0 });
    const move = harness.dispatch('touchmove', { x: 208, y: 304, timeStamp: 80 });
    assert.equal(move.defaultPrevented, true);
    assert.equal(harness.document.body.classList.contains('rt-sidebar-dragging'), true);
    assert.ok(Number(harness.document.body.style.getPropertyValue('--rt-sidebar-drag-progress')) > 0.6);
    assert.equal(harness.dispatch('touchend', { x: 208 }).defaultPrevented, true);
    assert.deepEqual(harness.actions, [true]);
    assert.equal(harness.menuScrolls, 1);
    harness.assertClean();
});

test('real handlers reject middle-origin drags and touchend-only fallbacks', () => {
    for (const withMove of [true, false]) {
        const harness = swipeHarness();
        harness.dispatch('touchstart', { x: 195 });
        if (withMove) assert.equal(harness.dispatch('touchmove', { x: 320 }).defaultPrevented, false);
        assert.equal(harness.dispatch('touchend', { x: 320 }).defaultPrevented, false);
        assert.deepEqual(harness.actions, []);
        harness.assertClean();
    }
});

test('edge touchend fallback opens while vertical intent cannot fall back into opening', () => {
    const fallback = swipeHarness();
    fallback.dispatch('touchstart', { x: 28 });
    assert.equal(fallback.dispatch('touchend', { x: 150 }).defaultPrevented, true);
    assert.deepEqual(fallback.actions, [true]);
    fallback.assertClean();

    const vertical = swipeHarness();
    vertical.dispatch('touchstart');
    assert.equal(vertical.dispatch('touchmove', { x: 10, y: 360 }).defaultPrevented, false);
    assert.equal(vertical.dispatch('touchmove', { x: 250, y: 360 }).defaultPrevented, false);
    assert.equal(vertical.dispatch('touchend', { x: 250, y: 360 }).defaultPrevented, false);
    assert.deepEqual(vertical.actions, []);
    vertical.assertClean();
});

test('native scroll before claiming aborts only the active gesture owner', () => {
    for (const targetKind of ['ancestor', 'document']) {
        const harness = swipeHarness();
        harness.dispatch('touchstart');
        harness.dispatch('scroll', { target: targetKind === 'document' ? harness.document : harness.document.getElementById('scroller') });
        assert.equal(harness.dispatch('touchend', { x: 240 }).defaultPrevented, false);
        assert.deepEqual(harness.actions, []);
        harness.assertClean();
    }
    const unrelated = swipeHarness();
    unrelated.dispatch('touchstart');
    unrelated.dispatch('scroll', { target: unrelated.document.getElementById('app-sidebar') });
    unrelated.dispatch('touchend', { x: 240 });
    assert.deepEqual(unrelated.actions, [true]);
});

test('a non-cancelable move remains native and cannot reopen through the end fallback', () => {
    const harness = swipeHarness();
    harness.dispatch('touchstart');
    assert.equal(harness.dispatch('touchmove', { x: 230, cancelable: false }).defaultPrevented, false);
    assert.equal(harness.dispatch('touchend', { x: 240 }).defaultPrevented, false);
    assert.deepEqual(harness.actions, []);
    harness.assertClean();
});

test('multi-touch, another touch identifier, and owned events cancel without swallowing scroll', () => {
    for (const change of ['multitouch-start', 'multitouch-move', 'identifier-move', 'identifier-end', 'remaining-touch', 'prevented-start', 'prevented-move', 'prevented-end']) {
        const harness = swipeHarness();
        harness.dispatch('touchstart', {
            touches: change === 'multitouch-start' ? [harness.touch(8), harness.touch(9, 310, 2)] : undefined,
            prevented: change === 'prevented-start',
        });
        if (['multitouch-move', 'identifier-move', 'prevented-move'].includes(change)) {
            const move = harness.dispatch('touchmove', {
                x: 200,
                touches: change === 'multitouch-move' ? [harness.touch(200), harness.touch(201, 310, 2)] : undefined,
                identifier: change === 'identifier-move' ? 2 : 1,
                prevented: change === 'prevented-move',
            });
            assert.equal(move.defaultPrevented, change === 'prevented-move');
        }
        const end = harness.dispatch('touchend', {
            x: 240,
            identifier: change === 'identifier-end' ? 2 : 1,
            touches: change === 'remaining-touch' ? [harness.touch(20, 310, 2)] : [],
            prevented: change === 'prevented-end',
        });
        assert.equal(end.defaultPrevented, change === 'prevented-end', change);
        assert.deepEqual(harness.actions, [], change);
        harness.assertClean();
    }
});

test('cancellation, resize and navigation clear painted drags without stale state restoration', () => {
    for (const reason of ['touchcancel', 'resize', 'livewire:navigating', 'sidebar-removed', 'external-open']) {
        const harness = swipeHarness();
        harness.dispatch('touchstart', { timeStamp: 0 });
        harness.dispatch('touchmove', { x: 140, timeStamp: 100 });
        assert.equal(harness.document.body.classList.contains('rt-sidebar-dragging'), true);
        if (reason === 'resize') harness.resize(1200);
        else if (reason === 'sidebar-removed') harness.document.getElementById('app-sidebar').remove();
        else if (reason === 'external-open') harness.document.body.classList.add('sidebar-enable');
        else harness.dispatch(reason);
        assert.equal(harness.dispatch('touchend', { x: 240 }).defaultPrevented, false, reason);
        assert.deepEqual(harness.actions, [], reason);
        assert.equal(harness.document.body.classList.contains('sidebar-enable'), reason === 'external-open');
        harness.assertClean();
    }
});

test('fresh edge gestures still work after cancellation, resize and Livewire navigation', () => {
    const harness = swipeHarness();
    for (const reason of ['touchcancel', 'resize', 'livewire:navigating']) {
        harness.document.body.classList.remove('sidebar-enable');
        harness.dispatch('touchstart');
        if (reason === 'resize') {
            harness.resize(1200);
            harness.resize(390);
        } else harness.dispatch(reason);
        harness.dispatch('touchend', { x: 240 });
        const previousActions = harness.actions.length;
        harness.dispatch('touchstart');
        harness.dispatch('touchend', { x: 240 });
        assert.equal(harness.actions.length, previousActions + 1);
        assert.equal(harness.actions.at(-1), true);
        harness.assertClean();
    }
});

test('an open sidebar still closes outside the edge and preserves backdrop clicks', () => {
    for (const withMove of [true, false]) {
        const harness = swipeHarness({ open: true });
        harness.dispatch('touchstart', { x: 330, timeStamp: 0 });
        if (withMove) assert.equal(harness.dispatch('touchmove', { x: 120, timeStamp: 80 }).defaultPrevented, true);
        assert.equal(harness.dispatch('touchend', { x: 120 }).defaultPrevented, true);
        assert.deepEqual(harness.actions, [false]);
        assert.equal(harness.menuScrolls, 0);
        harness.assertClean();
    }
    const backdrop = swipeHarness({ open: true });
    backdrop.dispatch('click', { target: backdrop.document.querySelector('.rt-mobile-sidebar-backdrop') });
    assert.deepEqual(backdrop.actions, [false]);
});

test('desktop and missing-sidebar pages never capture mobile gestures', () => {
    for (const reason of ['desktop', 'missing-sidebar']) {
        const harness = swipeHarness({ width: reason === 'desktop' ? 1024 : 390 });
        if (reason === 'missing-sidebar') harness.document.getElementById('app-sidebar').remove();
        harness.dispatch('touchstart');
        assert.equal(harness.dispatch('touchmove', { x: 240 }).defaultPrevented, false);
        assert.equal(harness.dispatch('touchend', { x: 240 }).defaultPrevented, false);
        assert.deepEqual(harness.actions, []);
        harness.assertClean();
    }
});
