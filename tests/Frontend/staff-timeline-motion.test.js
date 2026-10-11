import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { staffTimeline } from '../../resources/js/staff-timeline.js';

function withMotion(run, { gsap = true, reduced = false, deferredNativeScroll = false,
    fullNameWidth = 180, clientWidth = 900, compactDefault = false } = {}) {
    const { document, window: domWindow } = parseHTML(`<!doctype html><html><body>
        <div id="timeline">
            <div data-timeline-person-column><button data-timeline-person-toggle>Toggle</button></div>
            <div data-timeline-person-column><button id="person">Person</button></div>
            <div id="grid">
                <div class="rt-personnel-timeline-track" data-timeline-lanes="1">
                    <div class="rt-personnel-timeline-event" data-time-start="25" data-time-width="1" data-time-lane="0"><span class="rt-personnel-timeline-time-text">08–10</span></div>
                    <div class="rt-personnel-timeline-event" data-time-start="27" data-time-width="1" data-time-lane="0"><span class="rt-personnel-timeline-time-text">10–12</span></div>
                </div>
            </div>
        </div>
    </body></html>`);
    const root = document.getElementById('timeline');
    const timeline = staffTimeline();
    const tweens = [];
    const frames = new Map();
    const timers = new Map();
    const cancelledFrames = [];
    let frameId = 0;
    let timerId = 0;
    let bodyOffset = 0;
    let fullWidth = fullNameWidth;
    let reduceMotion = reduced;
    const currentWidth = () => parseFloat(root.style.getPropertyValue('--timeline-name-width'))
        || (root.dataset.personnelCompact === 'true' ? 52 : fullWidth);
    const dayWidth = () => parseFloat(root.style.getPropertyValue('--timeline-day-width')) || 360;
    const maximum = () => Math.max(0, currentWidth() + 7 * dayWidth() - body.clientWidth);
    const clamp = (offset) => Math.max(0, Math.min(offset, maximum()));
    const media = { get matches() { return reduceMotion; } };
    const matchMedia = (query) => {
        assert.equal(query, '(prefers-reduced-motion: reduce)');
        return media;
    };
    const engine = {
        to(state, options) {
            const tween = { state, options, from: state.width, killed: false,
                kill() { this.killed = true; } };
            tweens.push(tween);
            return tween;
        },
    };
    const body = {
        clientWidth,
        offsetWidth: clientWidth + 15,
        scrollTop: 144,
        scrollWrites: 0,
        scrollCalls: [],
        get scrollWidth() { return Math.max(this.clientWidth, currentWidth() + 7 * dayWidth()); },
        get scrollLeft() { return deferredNativeScroll ? bodyOffset : bodyOffset = clamp(bodyOffset); },
        set scrollLeft(value) { this.scrollWrites++; bodyOffset = clamp(value); },
        set nativeOffset(value) { bodyOffset = clamp(value); },
        scrollTo(options) { this.scrollCalls.push(options); },
    };
    const mirror = () => {
        let offset = 0;
        return {
            get scrollLeft() { return offset = clamp(offset); },
            set scrollLeft(value) { offset = clamp(value); },
        };
    };
    Object.assign(timeline, { $el: root, $nextTick: (callback) => callback(), $refs: {
        timelineBody: body,
        timelineHeader: mirror(),
        timelineScrollbar: mirror(),
        timelineGrid: document.getElementById('grid'),
    } });
    for (const label of root.querySelectorAll('.rt-personnel-timeline-time-text')) {
        label.getBoundingClientRect = () => ({ width: 90 });
    }
    const overrides = {
        document,
        Element: domWindow.Element,
        window: { ...(gsap ? { gsap: engine } : {}), matchMedia },
        matchMedia,
        getComputedStyle: () => ({ getPropertyValue(name) {
            return ({ '--timeline-name-full-width': `${fullWidth}px`,
                '--timeline-personnel-default-compact': compactDefault ? '1' : '0',
                '--timeline-name-compact-width': '52px', '--timeline-name-width': `${currentWidth()}px`,
                '--timeline-day-min-width': '260px', '--timeline-days': '7' })[name]
                || root.style.getPropertyValue(name);
        } }),
        requestAnimationFrame: (callback) => { frames.set(++frameId, callback); return frameId; },
        cancelAnimationFrame: (id) => { cancelledFrames.push(id); frames.delete(id); },
        setTimeout: (callback, delay) => { timers.set(++timerId, { callback, delay }); return timerId; },
        clearTimeout: (id) => timers.delete(id),
    };
    const originals = Object.fromEntries(Object.keys(overrides).map((name) =>
        [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
    Object.assign(globalThis, overrides);
    const advance = (tween, width, complete = false) => {
        tween.state.width = width;
        tween.options.onUpdate?.();
        if (complete) tween.options.onComplete?.();
    };
    try {
        run({ timeline, root, body, document, tweens, frames, timers, cancelledFrames, currentWidth, maximum, advance,
            setReduced: (value) => { reduceMotion = value; }, setFullWidth: (value) => { fullWidth = value; } });
    } finally {
        timeline.destroy();
        for (const [name, descriptor] of Object.entries(originals)) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else delete globalThis[name];
        }
    }
}

function compact(timeline, value = true) {
    timeline.setCompactRequested(value);
    timeline.applyPersonnelMode();
}

test('initial layout removes an interrupted inline width without playing an entrance tween', () => {
    withMotion(({ timeline, root, tweens, currentWidth }) => {
        root.dataset.personnelCompact = 'true';
        root.dataset.personnelAnimating = 'true';
        root.style.setProperty('--timeline-name-width', '93px');
        timeline.applyPersonnelMode();
        assert.equal(tweens.length, 0);
        assert.equal(root.dataset.personnelCompact, 'false');
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        assert.equal(currentWidth(), 180);
    });
});

test('tablet default has no entrance motion and explicit expansion reverses from the current shared width', () => {
    withMotion(({ timeline, root, tweens, currentWidth, advance }) => {
        root.dataset.personnelAnimating = 'true';
        root.style.setProperty('--timeline-name-width', '93px');
        timeline.applyPersonnelMode();
        assert.equal(currentWidth(), 52);
        assert.equal(tweens.length, 0);
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
        timeline.togglePersonnelColumn();
        timeline.applyPersonnelMode();
        const expansion = tweens[0];
        assert.equal(expansion.from, 52);
        assert.equal(expansion.options.width, 180);
        advance(expansion, 110);
        timeline.togglePersonnelColumn();
        timeline.applyPersonnelMode();
        const collapse = tweens[1];
        assert.equal(expansion.killed, true);
        assert.equal(collapse.from, 110);
        assert.equal(collapse.options.width, 52);
        advance(collapse, 52, true);
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
    }, { compactDefault: true, clientWidth: 794 });
});

test('collapse interpolates one shared width and rapid expansion starts at its rendered width', () => {
    withMotion(({ timeline, root, tweens, currentWidth, advance }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        const collapse = tweens[0];
        assert.equal(tweens.length, 1);
        assert.equal(collapse.from, 180);
        assert.equal(collapse.options.width, 52);
        assert.equal(collapse.options.duration, .22);
        assert.equal(currentWidth(), 180, 'the compact CSS selector must not replace the captured start');
        advance(collapse, 116);
        assert.equal(currentWidth(), 116);
        compact(timeline, false);
        const expand = tweens[1];
        assert.equal(collapse.killed, true);
        assert.equal(expand.from, 116);
        assert.equal(expand.options.width, 180);
        advance(expand, 180, true);
        assert.equal(root.dataset.personnelCompact, 'false');
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        advance(collapse, 52, true);
        assert.equal(currentWidth(), 180, 'callbacks from the replaced tween cannot write over the new mode');
    });
});

test('GSAP absence and reduced motion apply both final modes immediately', () => {
    for (const options of [{ gsap: false }, { reduced: true }]) {
        withMotion(({ timeline, root, tweens, currentWidth, body }) => {
            timeline.applyPersonnelMode();
            compact(timeline);
            assert.equal(currentWidth(), 52);
            assert.equal(root.dataset.personnelCompact, 'true');
            compact(timeline, false);
            assert.equal(currentWidth(), 180);
            assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
            assert.equal(root.hasAttribute('data-personnel-animating'), false);
            assert.equal(tweens.length, 0);
            assert.equal(body.scrollWrites, 0);
        }, options);
    }
});

test('intermediate measurements preserve the day scale, event lanes and native vertical position', () => {
    withMotion(({ timeline, root, body, tweens, advance }) => {
        timeline.applyPersonnelMode();
        timeline.measure();
        body.nativeOffset = 200;
        timeline.syncHorizontal(body, false);
        const track = timeline.$refs.timelineGrid.querySelector('.rt-personnel-timeline-track');
        const eventStyles = () => [...track.querySelectorAll('.rt-personnel-timeline-event')]
            .map((event) => event.getAttribute('style'));
        const before = eventStyles();
        const lanes = track.style.getPropertyValue('--timeline-lanes');
        compact(timeline);
        const collapse = tweens[0];
        for (const width of [160, 116, 72, 52]) {
            advance(collapse, width);
            timeline.measure();
            assert.equal(root.style.getPropertyValue('--timeline-day-width'), '360px');
            assert.deepEqual(eventStyles(), before);
            assert.equal(track.style.getPropertyValue('--timeline-lanes'), lanes);
            assert.equal(body.scrollLeft, 200);
            assert.equal(body.scrollTop, 144);
            assert.equal(body.scrollWrites, 0);
        }
        advance(collapse, 52, true);
    });
});

test('each native right-edge clamp is mirrored without inventing a leftward gesture', () => {
    withMotion(({ timeline, body, tweens, advance, maximum }) => {
        timeline.applyPersonnelMode();
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        compact(timeline);
        for (const width of [152, 116, 84, 52]) {
            advance(tweens[0], width);
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, true);
            assert.equal(body.scrollLeft, maximum());
            assert.equal(timeline.$refs.timelineHeader.scrollLeft, maximum());
            assert.equal(timeline.$refs.timelineScrollbar.scrollLeft, maximum());
            assert.equal(timeline.canScrollRight, false);
            assert.equal(body.scrollWrites, 0);
        }
        advance(tweens[0], 52, true);
        body.nativeOffset = maximum() - 20;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false, 'actual input away from the edge must still expand');
    });
});

test('delayed body clamps cannot replay an earlier footer clamp or reverse the animation', () => {
    withMotion(({ timeline, body, tweens, advance, maximum }) => {
        timeline.applyPersonnelMode();
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        compact(timeline);
        const footer = timeline.$refs.timelineScrollbar;
        for (const width of [152, 116, 84]) {
            const oldOffset = body.scrollLeft;
            advance(tweens[0], width);
            assert.equal(body.scrollLeft, oldOffset, 'body correction is intentionally deferred');
            assert.equal(footer.scrollLeft, maximum());
            timeline.syncHorizontal(footer);
            assert.equal(body.scrollLeft, oldOffset, 'mirrored footer cannot restart native body scrolling');
            body.nativeOffset = maximum();
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, true);
            assert.equal(body.scrollWrites, 0);
        }
        advance(tweens[0], 52, true);
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true, 'the final delayed clamp must survive tween completion');
        assert.equal(body.scrollWrites, 0);
    }, { deferredNativeScroll: true });
});

test('delayed end snapping after expansion remains layout movement until real input leaves the edge', () => {
    withMotion(({ timeline, body, tweens, advance, maximum }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        advance(tweens[0], 52, true);
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        compact(timeline, false);
        const expansion = tweens[1];
        for (const width of [84, 116, 152]) {
            advance(expansion, width);
            body.nativeOffset = maximum();
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, false);
        }
        advance(expansion, 180, true);
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false, 'the final delayed expansion snap is not right input');
        body.nativeOffset = maximum() - 20;
        timeline.syncHorizontal(body);
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true, 'ordinary right input determines the next preference');
        assert.equal(body.scrollWrites, 0);
    }, { deferredNativeScroll: true });
});

test('a delayed native end snap after resizing cannot compact staff without user input', () => {
    withMotion(({ timeline, root, body, tweens, maximum }) => {
        timeline.applyPersonnelMode();
        timeline.measure();
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        assert.equal(body.scrollLeft, 1800);
        assert.equal(timeline.canScrollRight, false);
        assert.equal(timeline.personnelCompact, false);

        body.clientWidth = 500;
        body.offsetWidth = 515;
        timeline.measure();
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '320px');
        assert.equal(maximum(), 1920);
        assert.equal(body.scrollLeft, 1800, 'the resize snap has not reached the body yet');
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false, 'a resize snap must not become a rightward gesture');
        assert.equal(root.dataset.personnelCompact, 'false');
        assert.equal(tweens.length, 0);
        assert.equal(timeline.$refs.timelineHeader.scrollLeft, 1920);
        assert.equal(timeline.$refs.timelineScrollbar.scrollLeft, 1920);

        body.nativeOffset = 1900;
        timeline.syncHorizontal(body);
        body.nativeOffset = 1920;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true, 'subsequent actual right input still compacts staff');
        body.nativeOffset = 1900;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false, 'subsequent actual left input still expands staff');
        assert.equal(body.scrollTop, 144);
        assert.equal(body.scrollWrites, 0);
    }, { deferredNativeScroll: true });
});

test('horizontal wheel input scrolls the hidden body and reverses intent while vertical input remains native', () => {
    withMotion(({ timeline, tweens, advance, currentWidth }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        advance(tweens[0], 116);
        let prevented = 0;
        const event = (deltaX, deltaY) => ({ deltaX, deltaY, preventDefault() { prevented++; } });
        timeline.wheelPersonnel(event(0, -30));
        timeline.wheelPersonnel(event(-.5, 0));
        timeline.wheelPersonnel(event(-5, 30));
        assert.equal(timeline.personnelCompact, true);
        assert.equal(prevented, 0, 'vertical and sub-pixel wheel input remain native');
        timeline.wheelPersonnel(event(30, 4));
        assert.equal(timeline.$refs.timelineBody.scrollLeft, 30);
        assert.equal(timeline.$refs.timelineBody.scrollTop, 148, 'diagonal trackpad input also keeps its vertical component');
        assert.equal(prevented, 1);
        timeline.wheelPersonnel(event(-30, 0));
        assert.equal(timeline.personnelCompact, false);
        assert.equal(timeline.$refs.timelineBody.scrollLeft, 0);
        assert.equal(prevented, 2);
        timeline.applyPersonnelMode();
        assert.equal(tweens[0].killed, true);
        assert.equal(tweens[1].from, 116);
        assert.equal(currentWidth(), 116);
    });
});

test('right wheel direction survives an interior native snap-back and an opposite wheel changes intent immediately', () => {
    withMotion(({ timeline, body, timers }) => {
        timeline.wheelPersonnel({ deltaX: 360, deltaY: 0, preventDefault() {} });
        for (const offset of [300, 360, 300, 265]) {
            body.nativeOffset = offset;
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, true, `right wheel followed by native offset ${offset}`);
            assert.equal(timeline.$refs.timelineHeader.scrollLeft, offset);
            assert.equal(timeline.$refs.timelineScrollbar.scrollLeft, offset);
        }
        assert.equal(timers.size, 1, 'one bounded fallback is refreshed while native scrolling continues');
        timeline.wheelPersonnel({ deltaX: -30, deltaY: 0, preventDefault() {} });
        assert.equal(timeline.personnelCompact, false);
        for (const offset of [230, 245, 265]) {
            body.nativeOffset = offset;
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, false, 'a correction toward the right does not reverse left wheel intent');
        }
        timeline.finishHorizontalIntent();
        assert.equal(timers.size, 0);
        body.nativeOffset = 275;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true, 'native offset direction resumes after scrollend');
        body.nativeOffset = 255;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
        assert.equal(body.scrollWrites, 2);
    });
});

test('day arrow direction survives native smooth-scroll overshoot and snapping until scrollend', () => {
    withMotion(({ timeline, body, timers }) => {
        timeline.applyPersonnelMode();
        timeline.measure();
        body.nativeOffset = 400;
        timeline.syncHorizontal(body, false);
        timeline.scrollDay(1);
        for (const offset of [600, 750, 720]) {
            body.nativeOffset = offset;
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, true);
        }
        assert.deepEqual(body.scrollCalls[0], { left: 720, behavior: 'smooth' });
        timeline.finishHorizontalIntent();
        assert.equal(timers.size, 0);
        body.nativeOffset = 700;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
        assert.equal(body.scrollWrites, 0);
    });
});

test('a manual header counter-command wins over the remaining native wheel sequence', () => {
    withMotion(({ timeline, body, timers }) => {
        timeline.wheelPersonnel({ deltaX: 360, deltaY: 0, preventDefault() {} });
        assert.equal(timeline.personnelCompact, true);
        timeline.togglePersonnelColumn();
        assert.equal(timeline.personnelCompact, false);
        for (const offset of [100, 360, 265]) {
            body.nativeOffset = offset;
            timeline.syncHorizontal(body);
            assert.equal(timeline.personnelCompact, false, 'old wheel movement must not undo the explicit header command');
        }
        timeline.finishHorizontalIntent();
        assert.equal(timers.size, 0);
        body.nativeOffset = 280;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true);
    });
});

test('mirrored footer events retain wheel intent while actual footer input cancels it and determines direction', () => {
    withMotion(({ timeline, body, timers }) => {
        body.nativeOffset = 300;
        timeline.syncHorizontal(body, false);
        timeline.wheelPersonnel({ deltaX: 360, deltaY: 0, preventDefault() {} });
        body.nativeOffset = 360;
        timeline.syncHorizontal(body);
        timeline.syncHorizontal(timeline.$refs.timelineScrollbar);
        assert.equal(timeline.horizontalIntent, 1);
        assert.equal(timers.size, 1);
        assert.equal(body.scrollWrites, 1);
        timeline.$refs.timelineScrollbar.scrollLeft = 320;
        timeline.syncHorizontal(timeline.$refs.timelineScrollbar);
        assert.equal(body.scrollLeft, 320);
        assert.equal(body.scrollWrites, 2);
        assert.equal(timeline.personnelCompact, false);
        assert.equal(timeline.horizontalIntent, null);
        assert.equal(timers.size, 0);
    });
});

test('an edge gesture without movement releases its intent through a bounded idle fallback and destroy clears that timer', () => {
    withMotion(({ timeline, body, maximum, timers }) => {
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        timeline.wheelPersonnel({ deltaX: 30, deltaY: 0, preventDefault() {} });
        assert.equal(timeline.horizontalIntent, 1);
        assert.equal(timers.size, 1);
        const [id, timer] = [...timers][0];
        assert.ok(timer.delay > 0 && timer.delay <= 500, 'fallback must have a short finite bound');
        timers.delete(id);
        timer.callback();
        assert.equal(timeline.horizontalIntent, null);
        assert.equal(timeline.intentTimer, null);
        body.nativeOffset = maximum() - 20;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
        timeline.wheelPersonnel({ deltaX: 30, deltaY: 0, preventDefault() {} });
        assert.equal(timers.size, 1);
        timeline.destroy();
        assert.equal(timers.size, 0);
        assert.equal(timeline.intentTimer, null);
        assert.equal(timeline.horizontalIntent, null);
        assert.equal(body.scrollWrites, 1);
    });
});

test('a same-position body event cannot consume the pending delayed layout correction', () => {
    withMotion(({ timeline, body, maximum }) => {
        timeline.applyPersonnelMode();
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        compact(timeline);
        assert.equal(body.scrollLeft, 1800);
        assert.equal(timeline.layoutScrollOffset, 1672);
        timeline.syncHorizontal(body); // e.g. a vertical scroll before the horizontal layout correction
        assert.equal(timeline.layoutScrollOffset, 1672);
        body.nativeOffset = 1672;
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true);
        assert.equal(timeline.layoutScrollOffset, null);
    }, { gsap: false, deferredNativeScroll: true });
});

test('native footer input can reverse a running tween without losing its new offset', () => {
    withMotion(({ timeline, body, tweens, advance }) => {
        timeline.applyPersonnelMode();
        body.nativeOffset = 300;
        timeline.syncHorizontal(body, false);
        compact(timeline);
        advance(tweens[0], 116);
        timeline.$refs.timelineScrollbar.scrollLeft = 260;
        timeline.syncHorizontal(timeline.$refs.timelineScrollbar);
        assert.equal(body.scrollLeft, 260);
        assert.equal(timeline.personnelCompact, false);
        timeline.applyPersonnelMode();
        assert.equal(tweens[0].killed, true);
        assert.equal(tweens[1].from, 116);
        assert.equal(body.scrollWrites, 1, 'only native footer input may write the body offset');
    });
});

test('header hover does not fight an explicit compact button, while actual staff hover still expands', () => {
    withMotion(({ timeline, document }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        timeline.pointerPersonnel({ type: 'pointerover', pointerType: 'mouse',
            target: document.querySelector('[data-timeline-person-toggle]') });
        assert.equal(timeline.personnelCompact, true);
        timeline.pointerPersonnel({ type: 'pointerover', pointerType: 'mouse',
            target: document.getElementById('person') });
        assert.equal(timeline.personnelCompact, false);
        assert.equal(timeline.compactRequested, true);
    });
});

test('changing reduced motion during a tween immediately returns width ownership to CSS', () => {
    withMotion(({ timeline, root, tweens, advance, setReduced, currentWidth }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        advance(tweens[0], 116);
        setReduced(true);
        advance(tweens[0], 90);
        assert.equal(tweens[0].killed, true);
        assert.equal(currentWidth(), 52);
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
    });
});

test('a responsive full-width change during expansion cannot leave a stale desktop inline width', () => {
    withMotion(({ timeline, root, tweens, advance, setFullWidth, currentWidth }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        advance(tweens[0], 52, true);
        compact(timeline, false);
        advance(tweens[1], 100);
        setFullWidth(124);
        timeline.measure();
        assert.equal(currentWidth(), 124);
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
        assert.equal(tweens[1].killed, true);
        advance(tweens[1], 180, true);
        assert.equal(currentWidth(), 124);
    });
});

test('day arrows set their direction explicitly and retain native smooth or instant scrolling', () => {
    withMotion(({ timeline, body, setReduced }) => {
        timeline.applyPersonnelMode();
        timeline.measure();
        body.nativeOffset = 400;
        timeline.syncHorizontal(body, false);
        timeline.scrollDay(1);
        assert.equal(timeline.compactRequested, true);
        assert.deepEqual(body.scrollCalls[0], { left: 720, behavior: 'smooth' });
        setReduced(true);
        timeline.scrollDay(-1);
        assert.equal(timeline.compactRequested, false);
        assert.deepEqual(body.scrollCalls[1], { left: 0, behavior: 'instant' });
        assert.equal(body.scrollWrites, 0);
    });
});

test('destroy kills its tween and queued layout work; late callbacks cannot touch cleared state', () => {
    withMotion(({ timeline, root, body, tweens, advance, frames, cancelledFrames, currentWidth }) => {
        timeline.applyPersonnelMode();
        compact(timeline);
        const tween = tweens[0];
        advance(tween, 116);
        timeline.queueMeasure();
        const frame = timeline.resizeFrame;
        assert.equal(frames.size, 1);
        timeline.destroy();
        assert.equal(tween.killed, true);
        assert.equal(frames.size, 0);
        assert.ok(cancelledFrames.includes(frame));
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        assert.equal(root.hasAttribute('data-personnel-animating'), false);
        const finalWidth = currentWidth();
        const finalHeader = timeline.$refs.timelineHeader.scrollLeft;
        advance(tween, 90, true);
        assert.equal(currentWidth(), finalWidth);
        assert.equal(timeline.$refs.timelineHeader.scrollLeft, finalHeader);
        assert.equal(body.scrollWrites, 0);
    });
});

function withFocusMotion(run, options = {}) {
    withMotion(fixture => {
        const { timeline, root, body, currentWidth } = fixture;
        root.dataset.timelinePeriodOwner = 'focus-owner';
        const header = timeline.$refs.timelineHeader;
        Object.defineProperty(header, 'clientWidth', { get: () => body.clientWidth - currentWidth() });
        header.querySelectorAll = () => Array.from({ length: 7 }, (_, index) =>
            ({ dataset: { timelineDate: `2026-10-${String(5 + index).padStart(2, '0')}` } }));
        const nativeScrollTo = body.scrollTo.bind(body);
        body.scrollTo = options => { nativeScrollTo(options); if (options.behavior === 'instant') body.scrollLeft = options.left; };
        timeline.applyPersonnelMode();
        timeline.measure();
        const click = (date = '2026-10-10', selected = false, shiftId = 81, owner = 'focus-owner') =>
            timeline.requestDistributionFocus({ owner, date, selected, shiftId });
        const advanceFocus = (tween, left, complete = false) => {
            tween.state.left = left;
            tween.options.onUpdate?.();
            if (complete) tween.options.onComplete?.();
        };
        run({ ...fixture, header, click, advanceFocus });
    }, options);
}

test('offscreen sidebar click starts a 420ms GSAP focus before server response and synchronizes every horizontal frame', () => {
    withFocusMotion(({ timeline, root, body, header, click, tweens, timers, advanceFocus }) => {
        assert.equal(click(), true);
        const focus = tweens[0];
        assert.equal(focus.state.left, 0);
        assert.equal(focus.options.left, 1800);
        assert.equal(focus.options.duration, 0.42);
        assert.equal(focus.options.ease, 'power2.inOut');
        assert.equal(root.dataset.timelineFocusShiftId, undefined, 'presentation does not wait for or change server selection');
        advanceFocus(focus, 900);
        assert.equal(body.scrollLeft, 900);
        assert.equal(header.scrollLeft, 900);
        assert.equal(timeline.$refs.timelineScrollbar.scrollLeft, 900);
        assert.equal(body.scrollTop, 144);
        timeline.lastScrollLeft = 400;
        timeline.syncHorizontal(body);
        assert.equal(timeline.compactRequested, false, 'self-generated scroll events cannot infer a personnel preference');
        timeline.queueIntentRelease();
        for (const [id, timer] of timers) if (timer.delay === 350) { timers.delete(id); timer.callback(); }
        timeline.finishHorizontalIntent();
        assert.equal(focus.killed, false, 'scrollend and ordinary intent timeout cannot cut the longer focus animation');
        advanceFocus(focus, 1800, true);
        assert.equal(body.scrollLeft, 1800);
        assert.equal(header.scrollLeft, 1800);
        assert.equal(timeline.$refs.timelineScrollbar.scrollLeft, 1800);
        assert.equal(body.scrollTop, 144);
        assert.equal(focus.killed, true);
        advanceFocus(focus, 100, true);
        assert.equal(body.scrollLeft, 1800, 'late completed tween callbacks are inert');
    });
});

test('fully or partly visible click performs no tween, scroll write, native scroll call or personnel preference change', () => {
    for (const [offset, date] of [[0, '2026-10-06'], [500, '2026-10-06'], [500, '2026-10-08'],
        [719.9, '2026-10-06'], [720.1, '2026-10-09']]) {
        withFocusMotion(({ timeline, body, click, tweens }) => {
            body.nativeOffset = offset;
            const writes = body.scrollWrites;
            const mode = timeline.compactRequested;
            click(date);
            assert.equal(tweens.length, 0);
            assert.equal(body.scrollWrites, writes);
            assert.equal(body.scrollCalls.length, 0);
            assert.equal(body.scrollLeft, offset);
            assert.equal(timeline.compactRequested, mode);
        });
    }
});

test('rapid offscreen clicks replace only their own tween, while visible or deselect clicks stop it without adding movement', () => {
    withFocusMotion(({ timeline, body, click, tweens, advanceFocus }) => {
        click();
        const first = tweens[0];
        advanceFocus(first, 900);
        assert.equal(click('2026-10-05', false, 82), true);
        const second = tweens[1];
        assert.equal(first.killed, true);
        assert.equal(second.state.left, 900);
        assert.equal(second.options.left, 0);
        advanceFocus(first, 1800, true);
        assert.equal(body.scrollLeft, 900, 'superseded completion cannot jump over the newer click');
        const writes = body.scrollWrites;
        const scrollCalls = body.scrollCalls.length;
        click('2026-10-07', false, 83);
        assert.equal(second.killed, true);
        assert.equal(tweens.length, 2);
        assert.equal(body.scrollWrites, writes);
        assert.equal(body.scrollCalls.length, scrollCalls);
        click();
        const third = tweens[2];
        assert.equal(click('2026-10-10', true), true);
        assert.equal(third.killed, true);
        assert.equal(tweens.length, 3);
        advanceFocus(third, 1800, true);
        assert.equal(body.scrollLeft, 900);
        assert.equal(timeline.compactRequested, false);
    });
});

test('wheel on either axis, arrow, footer input and body pointer/key cancellation stop focus and late frames cannot override user input', () => {
    for (const cancel of [
        timeline => timeline.wheelPersonnel({ deltaX: 0, deltaY: 40 }),
        timeline => timeline.wheelPersonnel({ deltaX: -40, deltaY: 10, preventDefault() {} }),
        timeline => timeline.scrollDay(-1),
        timeline => timeline.noteWindowGesture(),
        timeline => timeline.cancelDistributionFocus(),
    ]) {
        withFocusMotion(({ timeline, body, click, tweens, advanceFocus }) => {
            click();
            const focus = tweens[0];
            advanceFocus(focus, 900);
            cancel(timeline);
            assert.equal(focus.killed, true);
            const offset = body.scrollLeft;
            const scrollCalls = body.scrollCalls.length;
            advanceFocus(focus, 1800, true);
            assert.equal(body.scrollLeft, offset);
            assert.equal(body.scrollCalls.length, scrollCalls);
        });
    }
});

test('an existing personnel right-edge tween cannot pin over focus navigation, including after focus completes', () => {
    withFocusMotion(({ timeline, root, body, click, tweens, advance, advanceFocus, currentWidth, maximum }) => {
        body.nativeOffset = maximum();
        timeline.syncHorizontal(body, false);
        compact(timeline);
        const personnel = tweens[0];
        assert.equal(personnel.state.atEnd, true);
        click('2026-10-05');
        const focus = tweens[1];
        assert.equal(personnel.state.atEnd, false, 'a new focus explicitly releases the old width-tween endpoint');
        advanceFocus(focus, 900);
        advance(personnel, 110);
        assert.equal(body.scrollLeft, 900);
        assert.equal(currentWidth(), 110);
        assert.equal(root.dataset.personnelAnimating, 'true');
        timeline.measure();
        assert.equal(body.scrollLeft, 900, 'morph/measure preserves both active tween states');
        advanceFocus(focus, 0, true);
        advance(personnel, 52, true);
        assert.equal(body.scrollLeft, 0, 'a delayed personnel completion cannot return to its former right edge');
        assert.equal(body.scrollTop, 144);
    });
});

test('reduced motion navigates immediately without GSAP and changing that preference ends an active focus safely', () => {
    withFocusMotion(({ body, click, tweens, header }) => {
        click();
        assert.equal(tweens.length, 0);
        assert.deepEqual(body.scrollCalls, [{ left: 1800, behavior: 'instant' }]);
        assert.equal(body.scrollLeft, 1800);
        assert.equal(header.scrollLeft, 1800);
    }, { reduced: true });
    withFocusMotion(({ body, click, tweens, advanceFocus, setReduced }) => {
        click();
        const focus = tweens[0];
        advanceFocus(focus, 400);
        setReduced(true);
        advanceFocus(focus, 500);
        assert.equal(body.scrollLeft, 1800);
        assert.equal(focus.killed, true);
        assert.equal(body.scrollTop, 144);
    });
});

test('GSAP absence keeps native smooth fallback cancellable with bounded cleanup and no deferred server replay', () => {
    withFocusMotion(({ timeline, body, click, timers }) => {
        click();
        assert.deepEqual(body.scrollCalls, [{ left: 1800, behavior: 'smooth' }]);
        assert.equal([...timers.values()].filter(timer => timer.delay === 800).length, 1);
        body.nativeOffset = 900;
        timeline.syncHorizontal(body);
        assert.equal(timeline.compactRequested, false);
        timeline.cancelDistributionFocus();
        assert.equal(body.scrollLeft, 900);
        assert.equal(timers.size, 0);
        assert.deepEqual(body.scrollCalls.at(-1), { left: 900, behavior: 'instant' });
        click();
        body.nativeOffset = 1800;
        for (const [id, timer] of timers) if (timer.delay === 800) { timers.delete(id); timer.callback(); }
        assert.equal(timers.size, 0);
        assert.equal(body.scrollLeft, 1800);
        timeline.measure();
        assert.equal(body.scrollCalls.length, 3);
    }, { gsap: false });
});

test('destroy and wrong-owner clicks cannot leave focus resources or allow late callbacks to touch another lifecycle', () => {
    withFocusMotion(({ timeline, body, click, tweens, advanceFocus, timers, frames }) => {
        click();
        const focus = tweens[0];
        advanceFocus(focus, 600);
        assert.equal(click('2026-10-05', false, 82, 'another-owner'), false);
        assert.equal(focus.killed, false);
        timeline.destroy();
        assert.equal(focus.killed, true);
        assert.equal(timers.size, 0);
        assert.equal(frames.size, 0);
        advanceFocus(focus, 1800, true);
        assert.equal(body.scrollLeft, 600);
        assert.equal(click(), false);
    });
});
