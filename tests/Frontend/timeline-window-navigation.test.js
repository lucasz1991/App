import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { staffTimeline, timelineShiftPeriod, timelineWindowOffsets, timelinePeriodUrl } from '../../resources/js/staff-timeline.js';

async function withWindow(run, { enabled = true, reduced = false, from = '2026-10-05', until = '2026-10-11',
    availableOwner = true, reject = false } = {}) {
    const { document } = parseHTML('<!doctype html><html><body><div id="timeline"><div id="grid"></div></div></body></html>');
    const root = document.getElementById('timeline');
    Object.assign(root.dataset, { timelineWindowNavigation: String(enabled), timelinePeriodOwner: 'plan-owner', timelineFrom: from, timelineUntil: until });
    const timeline = staffTimeline();
    const frames = new Map();
    const timers = new Map();
    const observers = [];
    const calls = [];
    const requests = [];
    const replacements = [];
    const events = [];
    let sequence = 0;
    let offset = 0;
    let reduce = reduced;
    const width = () => parseFloat(root.style.getPropertyValue('--timeline-day-width')) || 350;
    const body = {
        clientWidth: 850, offsetWidth: 856, scrollTop: 288, scrollCalls: [],
        get scrollWidth() { return 150 + 7 * width(); },
        get scrollLeft() { return offset; },
        set scrollLeft(value) { offset = Math.max(0, Math.min(value, this.scrollWidth - this.clientWidth)); },
        scrollTo(options) { this.scrollCalls.push(options); if (options.behavior === 'instant') this.scrollLeft = options.left; },
    };
    const header = { scrollLeft: 0, clientWidth: 700, querySelectorAll: () => [] };
    const scrollbar = { scrollLeft: 0 };
    const browser = {
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
            return ({ '--timeline-name-full-width': '150px', '--timeline-name-width': '150px',
                '--timeline-day-min-width': '260px', '--timeline-days': '7', '--timeline-personnel-default-compact': '0' })[name]
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
    Object.assign(timeline, { $el: root, $nextTick: callback => callback(), $dispatch: event => events.push(event),
        $refs: { timelineBody: body, timelineHeader: header, timelineScrollbar: scrollbar, timelineGrid: document.getElementById('grid') } });
    const flushFrame = () => {
        const active = [...frames.values()];
        frames.clear();
        active.forEach(callback => callback());
    };
    const period = (nextFrom, nextUntil) => {
        root.dataset.timelineFrom = nextFrom;
        root.dataset.timelineUntil = nextUntil;
        timeline.windowObserver?.callback();
    };
    const tick = () => new Promise(resolve => setImmediate(resolve));
    try {
        timeline.init();
        await run({ timeline, root, body, header, scrollbar, calls, requests, replacements, events, observers, timers, frames,
            width, maximum: () => body.scrollWidth - body.clientWidth, flushFrame, period, tick,
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
    await withWindow(({ timeline, root, replacements, calls, observers }) => {
        timeline.measure();
        assert.equal(timeline.windowNavigationEnabled, true);
        assert.equal(replacements.length, 0);
        assert.equal(calls.length, 0);
        const observation = observers.flatMap(observer => observer.targets).find(entry => entry.target === root);
        assert.deepEqual(observation.options, { attributes: true, attributeFilter: ['data-timeline-from', 'data-timeline-until'] });
    });
});

test('interior arrows retain ordinary native scrolling without requesting new dates', async () => {
    await withWindow(({ timeline, body, calls, width }) => {
        body.scrollLeft = width();
        timeline.scrollDay(1);
        assert.deepEqual(body.scrollCalls, [{ left: 2 * width(), behavior: 'smooth' }]);
        assert.equal(calls.length, 0);
        assert.equal(timeline.windowLoading, false);
    });
});

test('Alpine directive $el on an arrow still resolves the initialized timeline owner and measures the actual root', async () => {
    await withWindow(({ timeline, root, calls, period, flushFrame, body }) => {
        const arrow = root.ownerDocument.createElement('button');
        root.append(arrow);
        timeline.$el = arrow; // x-show/x-on magic points at the directive, not the x-data root.
        assert.equal(timeline.windowNavigationEnabled, true);
        timeline.scrollDay(-1);
        assert.deepEqual(calls, [['2026-10-04', '2026-10-10']]);
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        assert.deepEqual(body.scrollCalls, [{ left: 0, behavior: 'smooth' }]);
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '350px');
        assert.equal(arrow.style.getPropertyValue('--timeline-day-width') || '', '');
    });
});

test('left edge lazily prepends one day, deduplicates requests and restores the anchor plus vertical position', async () => {
    await withWindow(({ timeline, body, header, scrollbar, calls, requests, period, flushFrame, width, replacements }) => {
        timeline.scrollDay(-1);
        timeline.scrollDay(-1);
        timeline.scrollDay(1);
        assert.deepEqual(calls, [['2026-10-04', '2026-10-10']]);
        assert.equal(timeline.windowLoading, true);
        assert.equal(replacements.length, 0);
        body.scrollTop = 0; // A server morph/row reflow must not lose the previous employee position.
        period('2026-10-04', '2026-10-10');
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        assert.equal(body.scrollTop, 288);
        assert.equal(body.scrollLeft, width());
        assert.equal(header.scrollLeft, width());
        assert.equal(scrollbar.scrollLeft, width());
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: 0, behavior: 'smooth' }]);
        assert.equal(replacements.length, 1);
        assert.equal(new URL(replacements[0].url).searchParams.get('distribution'), 'true');
        requests[0].resolve();
    });
});

test('right edge removes one old day, appends one day and animates to the corresponding new right edge', async () => {
    await withWindow(({ timeline, body, calls, period, flushFrame, width, maximum }) => {
        body.scrollLeft = maximum();
        timeline.scrollDay(1);
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']]);
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.equal(body.scrollLeft, maximum() - width());
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: maximum(), behavior: 'smooth' }]);
        assert.equal(body.scrollTop, 288);
    });
});

test('reduced motion uses the same bounded request and instant edge navigation', async () => {
    await withWindow(({ timeline, body, period, flushFrame }) => {
        timeline.scrollDay(-1);
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: 0, behavior: 'instant' }]);
        assert.equal(body.scrollLeft, 0);
    }, { reduced: true });
});

test('rejected owner calls recover without changing dates/position/URL and a subsequent arrow can retry', async () => {
    await withWindow(async ({ timeline, body, calls, replacements }) => {
        await timeline.scrollDay(-1);
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /erneut versuchen/);
        assert.equal(body.scrollLeft, 0);
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

test('completed parent action waits for the child dates instead of assuming the reactive morph already happened', async () => {
    await withWindow(async ({ timeline, requests, body, period, flushFrame, tick }) => {
        timeline.scrollDay(-1);
        requests[0].resolve();
        await tick();
        flushFrame();
        assert.equal(timeline.windowLoading, true);
        assert.equal(body.scrollCalls.length, 0);
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(timeline.windowLoading, false);
        assert.deepEqual(body.scrollCalls, [{ left: 0, behavior: 'smooth' }]);
    });
});

test('transport timeout releases controls; a late response may confirm dates but cannot restore its obsolete anchor', async () => {
    await withWindow(({ timeline, body, expireRequest, period, flushFrame }) => {
        timeline.scrollDay(-1);
        expireRequest();
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /erneut versuchen/);
        body.scrollLeft = 600;
        body.scrollTop = 500;
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollCalls.length, 0);
        assert.equal(body.scrollLeft, 600);
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
