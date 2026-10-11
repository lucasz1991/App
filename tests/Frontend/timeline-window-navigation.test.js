import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { staffTimeline, timelineShiftPeriod, timelineWindowOffsets, timelinePeriodUrl, timelineBufferedEdges } from '../../resources/js/staff-timeline.js';

function dateOffset(value, days) {
    const date = new Date(`${value}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

async function withWindow(run, { enabled = true, reduced = false, from = '2026-10-05', until = '2026-10-11',
    availableOwner = true, reject = false, deferredNextTick = false, gsap = false } = {}) {
    const { document } = parseHTML('<!doctype html><html><body><div id="timeline"><div id="grid"></div></div></body></html>');
    const root = document.getElementById('timeline');
    Object.assign(root.dataset, { timelineWindowNavigation: String(enabled), timelinePeriodOwner: 'plan-owner', timelineFrom: from, timelineUntil: until });
    Object.assign(root.dataset, { timelineAxisFrom: '2026-10-02', timelineAxisUntil: '2026-10-14', timelineSelectedDays: '7', timelineBufferBefore: '3', timelineBufferAfter: '3' });
    const timeline = staffTimeline();
    const frames = new Map();
    const timers = new Map();
    const observers = [];
    const calls = [];
    const requests = [];
    const replacements = [];
    const events = [];
    const ticks = [];
    const tweens = [];
    let sequence = 0;
    let offset = 0;
    let footerOffset = 0;
    let reduce = reduced;
    const width = () => parseFloat(root.style.getPropertyValue('--timeline-day-width')) || 350;
    const nameWidth = () => parseFloat(root.style.getPropertyValue('--timeline-name-width'))
        || (root.dataset.personnelCompact === 'true' ? 52 : 150);
    const body = {
        clientWidth: 850, offsetWidth: 856, scrollTop: 288, scrollCalls: [],
        get scrollWidth() { return nameWidth() + (13 + Number(timeline.windowPendingBefore) + Number(timeline.windowPendingAfter)) * width(); },
        get scrollLeft() { return offset; },
        set scrollLeft(value) { offset = Math.max(0, Math.min(value, this.scrollWidth - this.clientWidth)); },
        scrollTo(options) { this.scrollCalls.push(options); if (options.behavior === 'instant') this.scrollLeft = options.left; },
    };
    const header = { scrollLeft: 0, clientWidth: 700, querySelectorAll: () => [] };
    const footerListeners = new Map();
    const scrollbar = {
        get scrollLeft() { return footerOffset; },
        set scrollLeft(value) {
            const days = enabled ? Number(root.dataset.timelineSelectedDays) : 13;
            footerOffset = Math.max(0, Math.min(value, days * width() - body.clientWidth + nameWidth()));
        },
        addEventListener(name, callback) { footerListeners.set(name, callback); },
        removeEventListener(name) { footerListeners.delete(name); },
        signal(type, key) { footerListeners.get(type)?.({ type, key }); } };
    const browser = {
        ...(gsap ? { gsap: { to(state, options) {
            const tween = { state, options, killed: false, kill() { this.killed = true; } };
            tweens.push(tween);
            return tween;
        } } } : {}),
        location: { href: 'http://localhost/arbeitsplatz/ansicht/cases?view=shifts&section=plan&status=all&distribution=true#selected' },
        history: { state: { preserved: true }, replaceState(state, title, url) {
            replacements.push({ state, title, url });
            browser.location.href = url;
        } },
        Livewire: { find(id) {
            assert.equal(id, 'plan-owner');
            return availableOwner ? { applyPeriod(nextFrom, nextUntil) {
                calls.push([nextFrom, nextUntil]);
                if (reject) return Promise.reject(new Error('transport failed'));
                return new Promise((resolve, rejectPromise) => requests.push({ resolve, reject: rejectPromise }));
            } } : undefined;
        } },
        matchMedia: () => ({ matches: reduce }),
    };
    class Observer {
        constructor(callback) { this.callback = callback; this.targets = []; this.disconnected = false; observers.push(this); }
        observe(target, options) { this.targets.push({ target, options }); }
        disconnect() { this.disconnected = true; }
    }
    const overrides = {
        document, window: browser,
        matchMedia: browser.matchMedia,
        getComputedStyle: () => ({ getPropertyValue(name) {
            return ({ '--timeline-name-full-width': '150px', '--timeline-name-width': `${nameWidth()}px`,
                '--timeline-day-min-width': '260px', '--timeline-days': '13', '--timeline-personnel-default-compact': '0' })[name]
                || root.style.getPropertyValue(name);
        } }),
        ResizeObserver: Observer, MutationObserver: Observer,
        requestAnimationFrame: callback => { frames.set(++sequence, callback); return sequence; },
        cancelAnimationFrame: id => frames.delete(id),
        setTimeout: (callback, delay) => { timers.set(++sequence, { callback, delay }); return sequence; },
        clearTimeout: id => timers.delete(id),
    };
    const originals = Object.fromEntries(Object.keys(overrides).map(name => [name, Object.getOwnPropertyDescriptor(globalThis, name)]));
    Object.assign(globalThis, overrides);
    Object.assign(timeline, { $el: root, $nextTick: callback => deferredNextTick ? ticks.push(callback) : callback(), $dispatch: event => events.push(event),
        $refs: { timelineBody: body, timelineHeader: header, timelineScrollbar: scrollbar, timelineGrid: document.getElementById('grid') } });
    const flushFrame = () => {
        const active = [...frames.values()];
        frames.clear();
        active.forEach(callback => callback());
    };
    const period = (nextFrom, nextUntil) => {
        root.dataset.timelineFrom = nextFrom;
        root.dataset.timelineUntil = nextUntil;
        root.dataset.timelineAxisFrom = dateOffset(nextFrom, -3);
        root.dataset.timelineAxisUntil = dateOffset(nextUntil, 3);
        timeline.windowObserver?.callback();
    };
    const tick = () => new Promise(resolve => setImmediate(resolve));
    const flushTicks = () => ticks.splice(0).forEach(callback => callback());
    try {
        timeline.init();
        flushTicks();
        await run({ timeline, root, body, header, scrollbar, calls, requests, replacements, events, observers, timers, frames, tweens,
            width, first: () => 3 * width(), maximum: () => body.scrollWidth - body.clientWidth,
            logicalMaximum: () => body.scrollWidth - body.clientWidth - 3 * width(), flushFrame, period, tick, flushTicks,
            finishScroll: () => { if (!body.scrollCalls.length) flushFrame(); body.scrollLeft = body.scrollCalls.at(-1).left; },
            setReduced: value => { reduce = value; },
            expireRequest: () => {
                for (const [id, timer] of timers) if (timer.delay === 15000) { timers.delete(id); timer.callback(); }
            } });
    } finally {
        timeline.destroy();
        for (const [name, descriptor] of Object.entries(originals)) {
            if (descriptor) Object.defineProperty(globalThis, name, descriptor);
            else delete globalThis[name];
        }
    }
}

test('civil-day period shifts cross DST, leap-day, month and year boundaries without changing the inclusive span', () => {
    for (const [from, until, direction, expected] of [
        ['2026-03-28', '2026-03-29', 1, { from: '2026-03-29', until: '2026-03-30' }],
        ['2026-10-25', '2026-10-26', -1, { from: '2026-10-24', until: '2026-10-25' }],
        ['2028-02-28', '2028-02-29', 1, { from: '2028-02-29', until: '2028-03-01' }],
        ['2026-12-31', '2027-01-06', 1, { from: '2027-01-01', until: '2027-01-07' }],
        ['2027-01-01', '2027-01-07', -1, { from: '2026-12-31', until: '2027-01-06' }],
        ['0099-12-31', '0100-01-01', 1, { from: '0100-01-01', until: '0100-01-02' }],
    ]) assert.deepEqual(timelineShiftPeriod(from, until, direction), expected);
});

test('invalid, oversized, reversed and unsupported civil ranges never become a navigation request', () => {
    for (const values of [
        ['2026-02-29', '2026-03-01', 1], ['2026-13-01', '2027-01-01', 1], ['2026-00-01', '2026-01-01', 1],
        ['2026-01-00', '2026-01-01', 1], ['2026-01-01', '2026-04-05', 1], ['2026-01-02', '2026-01-01', 1],
        ['2026-01-01', '2026-01-07', 0], ['2026-01-01', '2026-01-07', '1'], [null, '2026-01-07', 1],
        ['2026-1-01', '2026-01-07', 1], ['0001-01-01', '0001-01-07', -1], ['9999-12-25', '9999-12-31', 1],
    ]) assert.equal(timelineShiftPeriod(...values), null);
    assert.deepEqual(timelineShiftPeriod('2026-01-01', '2026-04-04', 1), { from: '2026-01-02', until: '2026-04-05' });
});

test('window anchor uses exact civil-day delta, clamps edges and rejects unrelated period changes', () => {
    assert.deepEqual(timelineWindowOffsets('2026-10-05', '2026-10-04', 0, 300, 1800), { anchor: 300, target: 0 });
    assert.deepEqual(timelineWindowOffsets('2026-10-05', '2026-10-06', 1800, 300, 1800), { anchor: 1500, target: 1800 });
    assert.deepEqual(timelineWindowOffsets('2026-03-28', '2026-03-29', 0, 300, 0), { anchor: 0, target: 0 });
    assert.equal(timelineWindowOffsets('2026-10-05', '2026-11-05', 1800, 300, 1800), null);
    assert.equal(timelineWindowOffsets('2026-10-05', '2026-10-06', NaN, 300, 1800), null);
});

test('logical selected edges leave exactly three cached columns before and after the selected range', () => {
    assert.deepEqual(timelineBufferedEdges(350, 3850, 3, 3), { first: 1050, last: 2800 });
    assert.deepEqual(timelineBufferedEdges(700, 4200, 3, 3), { first: 2100, last: 2100 });
});

test('period URL changes preserve all other query state, origin, path and hash', () => {
    const result = new URL(timelinePeriodUrl('http://localhost/cases?view=shifts&status=all&distribution=true&customer=4&from=old#row', '2026-10-06', '2026-10-12'));
    assert.equal(result.origin, 'http://localhost');
    assert.equal(result.pathname, '/cases');
    assert.equal(result.hash, '#row');
    assert.deepEqual(Object.fromEntries(result.searchParams), { view: 'shifts', status: 'all', distribution: 'true', customer: '4', from: '2026-10-06', until: '2026-10-12' });
    assert.equal(timelinePeriodUrl('not a URL', '2026-10-06', '2026-10-12'), null);
    assert.equal(timelinePeriodUrl('http://localhost', 'invalid', '2026-10-12'), null);
});

test('mount never changes URL and watches only validated period attributes for lazy updates', async () => {
    await withWindow(({ timeline, root, replacements, calls, observers, body, first }) => {
        timeline.measure();
        assert.equal(timeline.windowNavigationEnabled, true);
        assert.equal(replacements.length, 0);
        assert.equal(calls.length, 0);
        assert.equal(body.scrollLeft, first());
        const observation = observers.flatMap(observer => observer.targets).find(entry => entry.target === root);
        assert.deepEqual(observation.options, { attributes: true, attributeFilter: ['data-timeline-from', 'data-timeline-until', 'data-timeline-axis-from', 'data-timeline-axis-until'] });
    });
});

test('interior arrows retain ordinary native scrolling without requesting new dates', async () => {
    await withWindow(({ timeline, body, calls, width, first }) => {
        body.scrollLeft = first() + width();
        timeline.scrollDay(1);
        assert.deepEqual(body.scrollCalls, [{ left: first() + 2 * width(), behavior: 'smooth' }]);
        assert.equal(calls.length, 0);
        assert.equal(timeline.windowLoading, false);
    });
});

test('selected footer coordinates exclude the six reserve columns and logical edge indicators use the selected range', async () => {
    await withWindow(({ timeline, body, header, scrollbar, first, logicalMaximum, calls }) => {
        assert.equal(scrollbar.scrollLeft, 0);
        assert.equal(timeline.canScrollLeft, false);
        assert.equal(timeline.canScrollRight, true);
        scrollbar.scrollLeft = 500;
        timeline.syncHorizontal(scrollbar);
        assert.equal(body.scrollLeft, first() + 500);
        assert.equal(header.scrollLeft, first() + 500);
        body.scrollLeft = logicalMaximum();
        timeline.syncHorizontal(body, false);
        assert.equal(scrollbar.scrollLeft, logicalMaximum() - first());
        assert.equal(timeline.canScrollLeft, true);
        assert.equal(timeline.canScrollRight, false);
        assert.equal(calls.length, 0);
    });
});

test('ordinary horizontal wheel and native keyboard/snap offsets cannot expose unselected reserve dates', async () => {
    await withWindow(({ timeline, body, header, first, logicalMaximum, width, calls, replacements }) => {
        let prevented = 0;
        const top = body.scrollTop;
        timeline.wheelPersonnel({ deltaX: -width(), deltaY: 20, preventDefault() { prevented++; } });
        assert.equal(body.scrollLeft, first());
        assert.equal(body.scrollTop, top + 20, 'diagonal input retains its vertical portion at the logical edge');
        assert.equal(prevented, 1, 'native horizontal default must not enter the loaded reserve after the clamp');
        body.scrollLeft = 0;
        timeline.syncHorizontal(body);
        assert.equal(body.scrollLeft, first());
        assert.equal(header.scrollLeft, first());
        body.scrollLeft = logicalMaximum();
        timeline.wheelPersonnel({ deltaX: width(), deltaY: 0, preventDefault() { prevented++; } });
        assert.equal(body.scrollLeft, logicalMaximum());
        body.scrollLeft = body.scrollWidth - body.clientWidth;
        timeline.syncHorizontal(body);
        assert.equal(body.scrollLeft, logicalMaximum());
        assert.equal(calls.length, 0);
        assert.equal(replacements.length, 0);
    });
});

test('personnel collapse pins the selected logical end rather than the physical reserve end without inventing direction', async () => {
    await withWindow(({ timeline, body, scrollbar, logicalMaximum, first }) => {
        timeline.applyPersonnelMode();
        body.scrollLeft = logicalMaximum();
        timeline.syncHorizontal(body, false);
        const previousEnd = body.scrollLeft;
        timeline.setCompactRequested(true);
        timeline.applyPersonnelMode();
        assert.equal(body.scrollLeft, previousEnd - 98);
        assert.equal(body.scrollLeft, logicalMaximum());
        assert.equal(scrollbar.scrollLeft, logicalMaximum() - first());
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, true);
        assert.equal(timeline.canScrollRight, false);
        timeline.setCompactRequested(false);
        timeline.applyPersonnelMode();
        assert.equal(body.scrollLeft, previousEnd);
        timeline.syncHorizontal(body);
        assert.equal(timeline.personnelCompact, false);
        assert.equal(body.scrollTop, 288);
    });
});

test('an older personnel end anchor cannot pin a pending arrow to the reserve end', async () => {
    await withWindow(({ timeline, body, logicalMaximum, width }) => {
        body.scrollLeft = logicalMaximum();
        timeline.scrollDay(1);
        const offset = body.scrollLeft + width() / 2;
        body.scrollLeft = offset;
        timeline.rebasePersonnelLayout(true, offset);
        assert.equal(body.scrollLeft, offset);
        assert.equal(timeline.windowLoading, true);
    });
});

test('Livewire-morphed personnel attributes are restored before measurement without losing the selected compact end', async () => {
    await withWindow(({ timeline, root, body, calls, logicalMaximum, width }) => {
        timeline.applyPersonnelMode();
        timeline.setCompactRequested(true);
        timeline.applyPersonnelMode();
        body.scrollLeft = logicalMaximum();
        timeline.syncHorizontal(body, false);
        const end = body.scrollLeft;
        const dayWidth = width();
        delete root.dataset.personnelCompact;
        root.dataset.personnelAnimating = 'true';
        root.style.setProperty('--timeline-name-width', '156px');
        timeline.measure();
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(root.dataset.personnelAnimating, undefined);
        assert.equal(root.style.getPropertyValue('--timeline-name-width') || '', '');
        assert.equal(timeline.appliedPersonnelCompact, true);
        assert.equal(body.scrollLeft, end);
        assert.equal(body.scrollLeft, logicalMaximum());
        assert.equal(timeline.canScrollRight, false);
        assert.equal(width(), dayWidth);
        timeline.scrollDay(1);
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']], 'the settled compact end still advances the loaded window');
    });
});

test('same-value personnel effects restore a removed DOM marker without changing offsets', async () => {
    await withWindow(({ timeline, root, body, tweens }) => {
        timeline.applyPersonnelMode();
        timeline.setCompactRequested(true);
        timeline.applyPersonnelMode();
        const offset = body.scrollLeft;
        delete root.dataset.personnelCompact;
        timeline.applyPersonnelMode();
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(body.scrollLeft, offset);
        assert.equal(tweens.length, 0);
    });
});

test('a morph restores the active personnel tween width and animation marker instead of restarting its motion', async () => {
    await withWindow(({ timeline, root, body, tweens }) => {
        timeline.applyPersonnelMode();
        timeline.setCompactRequested(true);
        timeline.applyPersonnelMode();
        const tween = tweens[0];
        tween.state.width = 100;
        tween.options.onUpdate();
        const offset = body.scrollLeft;
        delete root.dataset.personnelCompact;
        delete root.dataset.personnelAnimating;
        root.style.removeProperty('--timeline-name-width');
        timeline.measure();
        assert.equal(root.dataset.personnelCompact, 'true');
        assert.equal(root.dataset.personnelAnimating, 'true');
        assert.equal(root.style.getPropertyValue('--timeline-name-width'), '100px');
        assert.equal(body.scrollLeft, offset);
        assert.equal(tweens.length, 1);
        assert.equal(tween.killed, false);
        timeline.applyPersonnelMode();
        assert.equal(tweens.length, 1);
    }, { gsap: true });
});

test('pending wheel input can inspect only the single unknown outer column and confirmation returns to the selected span', async () => {
    await withWindow(({ timeline, body, period, flushFrame, width, first, calls }) => {
        timeline.scrollDay(-1);
        flushFrame();
        timeline.wheelPersonnel({ deltaX: -5 * width(), deltaY: 10, preventDefault() {} });
        assert.equal(body.scrollLeft, 0);
        assert.equal(timeline.windowDayLoading('2026-10-01'), true);
        timeline.syncHorizontal(body);
        assert.equal(body.scrollLeft, 0, 'the pending unknown date stays inspectable while its data is missing');
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollLeft, first());
        assert.equal(body.scrollTop, 298);
        assert.equal(body.scrollCalls.length, 1, 'new wheel intent cancels the former arrow target');
        assert.equal(calls.length, 1, 'wheel preview never invents additional selected periods');
    });
});

test('Alpine directive $el on an arrow still resolves the initialized timeline owner and measures the actual root', async () => {
    await withWindow(({ timeline, root, calls, period, flushFrame, body, first, finishScroll }) => {
        const arrow = root.ownerDocument.createElement('button');
        root.append(arrow);
        timeline.$el = arrow; // x-show/x-on magic points at the directive, not the x-data root.
        assert.equal(timeline.windowNavigationEnabled, true);
        timeline.scrollDay(-1);
        assert.deepEqual(calls, [['2026-10-04', '2026-10-10']]);
        finishScroll();
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }]);
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '350px');
        assert.equal(arrow.style.getPropertyValue('--timeline-day-width') || '', '');
    });
});

test('left edge immediately reveals a cached day, marks only the unknown outer column and deduplicates requests', async () => {
    await withWindow(({ timeline, body, header, scrollbar, calls, requests, period, flushFrame, first, replacements, finishScroll }) => {
        timeline.scrollDay(-1);
        timeline.scrollDay(-1);
        timeline.scrollDay(1);
        assert.deepEqual(calls, [['2026-10-04', '2026-10-10']]);
        assert.equal(timeline.windowLoading, true);
        assert.equal(replacements.length, 0);
        assert.equal(body.scrollCalls.length, 0, 'prefix compensation is committed before the first smooth movement');
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }], 'movement begins before the server reply');
        assert.equal(timeline.windowPendingDate, '2026-10-01');
        assert.equal(timeline.windowPendingBefore, true);
        assert.equal(timeline.windowPendingAfter, false);
        assert.equal(timeline.windowDayLoading('2026-10-01'), true);
        assert.equal(timeline.windowDayLoading('2026-10-04'), false);
        assert.match(timeline.windowPendingLabel, /01\.10\./);
        finishScroll();
        body.scrollTop = 640; // New employee scrolling while the background request runs must survive.
        period('2026-10-04', '2026-10-10');
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        assert.equal(body.scrollTop, 640);
        assert.equal(body.scrollLeft, first());
        assert.equal(header.scrollLeft, first());
        assert.equal(scrollbar.scrollLeft, 0);
        flushFrame();
        assert.equal(body.scrollCalls.length, 1, 'a completed immediate movement is not animated a second time');
        assert.equal(timeline.windowPendingDate, '');
        assert.equal(replacements.length, 1);
        assert.equal(new URL(replacements[0].url).searchParams.get('distribution'), 'true');
        requests[0].resolve();
    });
});

test('right logical edge immediately enters a cached day while the next outer column replenishes in the background', async () => {
    await withWindow(({ timeline, body, calls, period, flushFrame, width, logicalMaximum, finishScroll }) => {
        body.scrollLeft = logicalMaximum();
        timeline.scrollDay(1);
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']]);
        assert.equal(timeline.windowPendingDate, '2026-10-15');
        assert.equal(timeline.windowPendingAfter, true);
        assert.equal(timeline.windowPendingBefore, false);
        finishScroll();
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.equal(body.scrollLeft, logicalMaximum());
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: logicalMaximum() + width(), behavior: 'smooth' }]);
        assert.equal(body.scrollTop, 288);
    });
});

test('an early morph resumes only the remaining part of the immediate movement rather than advancing a second day', async () => {
    await withWindow(({ timeline, body, period, flushFrame, width, first }) => {
        timeline.scrollDay(-1);
        flushFrame();
        body.scrollLeft = first() + width() / 2;
        period('2026-10-04', '2026-10-10');
        flushFrame();
        assert.equal(body.scrollLeft, first() + width() / 2);
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }, { left: first(), behavior: 'smooth' }]);
    });
});

test('an early forward morph subtracts the axis delta from current offset and the unfinished target', async () => {
    await withWindow(({ timeline, body, period, flushFrame, width, logicalMaximum }) => {
        body.scrollLeft = logicalMaximum();
        timeline.scrollDay(1);
        flushFrame();
        const target = body.scrollCalls[0].left;
        body.scrollLeft = target - width() / 2;
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.equal(body.scrollLeft, logicalMaximum() - width() / 2);
        flushFrame();
        assert.equal(body.scrollCalls[1].left, logicalMaximum());
    });
});

test('new horizontal wheel intent while data replenishes preserves the user offset and does not resume the obsolete target', async () => {
    await withWindow(({ timeline, body, period, flushFrame, width }) => {
        timeline.scrollDay(-1);
        flushFrame();
        timeline.wheelPersonnel({ deltaX: width(), deltaY: 40, preventDefault() {} });
        const offset = body.scrollLeft;
        const top = body.scrollTop;
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollLeft, offset);
        assert.equal(body.scrollTop, top);
        assert.equal(body.scrollCalls.length, 1);
    });
});

test('new selected-range footer movement while loading has priority over the immediate arrow animation', async () => {
    await withWindow(({ timeline, body, scrollbar, period, flushFrame, first, width }) => {
        timeline.scrollDay(-1);
        flushFrame();
        scrollbar.signal('pointerdown');
        scrollbar.scrollLeft = 500;
        timeline.syncHorizontal(scrollbar);
        assert.equal(body.scrollLeft, first() + width() + 500, 'temporary prefix is included in footer mapping');
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollLeft, first() + width() + 500);
        assert.equal(body.scrollCalls.length, 1);
    });
});

test('delayed mirrored footer layout events cannot cancel the scheduled arrow movement or its corrected target', async () => {
    await withWindow(({ timeline, body, scrollbar, period, flushFrame, first, width }) => {
        timeline.scrollDay(-1);
        const compensated = body.scrollLeft;
        scrollbar.scrollLeft = first(); // A queued mirror from before the temporary prefix, not pointer/key input.
        timeline.syncHorizontal(scrollbar);
        assert.equal(body.scrollLeft, compensated);
        assert.equal(body.scrollCalls.length, 0);
        flushFrame();
        assert.equal(body.scrollCalls[0].left, first());
        body.scrollLeft = first() + width() / 2;
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollCalls.length, 2);
        assert.equal(body.scrollCalls[1].left, first());
    });
});

test('native prefix reanchoring before nextTick is compensated absolutely and cannot advance the target twice', async () => {
    await withWindow(({ timeline, body, first, width, flushTicks, flushFrame, period, finishScroll }) => {
        timeline.scrollDay(-1);
        body.scrollLeft += width(); // Chromium automatically preserves its old day when the new prefix enters.
        assert.equal(body.scrollLeft, first() + width());
        flushTicks();
        assert.equal(body.scrollLeft, first() + width(), 'our compensation must not add a second day');
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }]);
        finishScroll();
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollLeft, first());
        assert.equal(body.scrollCalls.length, 1);
    }, { deferredNextTick: true });
});

test('single selected day keeps its full-width scale despite six preloaded neighboring columns', async () => {
    await withWindow(({ timeline, root, width }) => {
        root.dataset.timelineSelectedDays = '1';
        timeline.measure();
        assert.equal(width(), 700);
        assert.equal(root.dataset.timelineAxisFrom, '2026-10-02');
        assert.equal(root.dataset.timelineAxisUntil, '2026-10-14');
    });
});

test('reduced motion uses the same bounded request and instant edge navigation', async () => {
    await withWindow(({ timeline, body, period, flushFrame, first }) => {
        timeline.scrollDay(-1);
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'instant' }]);
        assert.equal(body.scrollLeft, first());
    }, { reduced: true });
});

test('rejected owner calls recover without changing dates/position/URL and a subsequent arrow can retry', async () => {
    await withWindow(async ({ timeline, body, calls, replacements, first }) => {
        await timeline.scrollDay(-1);
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /erneut versuchen/);
        assert.equal(body.scrollLeft, first());
        assert.equal(body.scrollTop, 288);
        assert.equal(replacements.length, 0);
        await timeline.scrollDay(-1);
        assert.equal(calls.length, 2);
    }, { reject: true });
});

test('missing Livewire owner cannot strand the controls or invent successful dates', async () => {
    await withWindow(async ({ timeline, calls, replacements }) => {
        await timeline.scrollDay(-1);
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /nicht geladen/);
        assert.equal(calls.length, 0);
        assert.equal(replacements.length, 0);
    }, { availableOwner: false });
});

test('completed parent action waits for the child dates while the already-started cached-day animation remains visible', async () => {
    await withWindow(async ({ timeline, requests, body, period, flushFrame, tick, first, finishScroll }) => {
        timeline.scrollDay(-1);
        requests[0].resolve();
        await tick();
        flushFrame();
        assert.equal(timeline.windowLoading, true);
        assert.equal(body.scrollCalls.length, 1);
        finishScroll();
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }]);
    });
});

test('transport timeout releases controls; a late response may confirm dates but cannot restore its obsolete anchor', async () => {
    await withWindow(({ timeline, body, expireRequest, period, flushFrame, first, width, finishScroll }) => {
        timeline.scrollDay(-1);
        finishScroll();
        expireRequest();
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /erneut versuchen/);
        assert.equal(timeline.windowPendingDate, '');
        assert.equal(body.scrollLeft, first(), 'a failed extension returns to the still-selected first day');
        body.scrollLeft = 600;
        body.scrollTop = 500;
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollCalls.length, 1);
        assert.equal(body.scrollLeft, first(), 'a newly confirmed foreign range starts at its selected first day');
        assert.equal(body.scrollTop, 500);
        assert.equal(timeline.windowError, '');
    });
});

test('foreign date-picker period supersedes pending edge restoration and a late promise cannot move it', async () => {
    await withWindow(async ({ timeline, body, requests, period, flushFrame, tick, calls }) => {
        timeline.scrollDay(-1);
        period('2027-02-01', '2027-02-07');
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        body.scrollLeft = 350;
        body.scrollTop = 640;
        requests[0].resolve();
        await tick();
        flushFrame();
        assert.equal(body.scrollCalls.length, 0);
        assert.equal(body.scrollTop, 640);
        assert.equal(calls.length, 1);
        body.scrollLeft = 0;
        timeline.scrollDay(-1);
        assert.deepEqual(calls[1], ['2027-01-31', '2027-02-06']);
    });
});

test('a newer period before the animation frame cancels the scheduled old edge movement', async () => {
    await withWindow(({ timeline, body, period, flushFrame }) => {
        timeline.scrollDay(-1);
        period('2026-10-04', '2026-10-10');
        flushFrame();
        period('2027-02-01', '2027-02-07');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollCalls.length, 0);
        assert.equal(timeline.windowLoading, false);
    });
});

test('standalone/absence timelines keep finite arrow behavior and do not call the planning owner or update URL', async () => {
    await withWindow(({ timeline, body, calls, period, flushFrame, replacements }) => {
        assert.equal(timeline.windowNavigationEnabled, false);
        timeline.scrollDay(-1);
        assert.deepEqual(body.scrollCalls, [{ left: 0, behavior: 'smooth' }]);
        assert.equal(calls.length, 0);
        period('2026-10-04', '2026-10-10');
        flushFrame();
        assert.equal(replacements.length, 0);
    }, { enabled: false });
});

test('malformed date datasets cannot dispatch applyPeriod or rewrite the browser URL', async () => {
    await withWindow(({ timeline, calls, replacements }) => {
        timeline.scrollDay(-1);
        timeline.scrollDay(1);
        assert.equal(timeline.windowLoading, false);
        assert.equal(calls.length, 0);
        assert.equal(replacements.length, 0);
    }, { from: 'not-a-date' });
});

test('destroy clears observers/timers/frames and late completions cannot revive detached navigation', async () => {
    await withWindow(async ({ timeline, requests, observers, frames, timers, replacements, tick }) => {
        timeline.scrollDay(-1);
        timeline.queueMeasure();
        timeline.destroy();
        requests[0].resolve();
        await tick();
        assert.equal(frames.size, 0);
        assert.equal(timers.size, 0);
        assert.equal(observers.every(observer => observer.disconnected), true);
        assert.equal(timeline.windowLoading, false);
        assert.equal(replacements.length, 0);
    });
});
