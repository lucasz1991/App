import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { staffTimeline, timelineShiftPeriod, timelineWindowOffsets, timelinePeriodUrl, timelineBufferedEdges, timelineDiscardUnchangedRootUpdate } from '../../resources/js/staff-timeline.js';

function dateOffset(value, days) {
    const date = new Date(`${value}T00:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    if (date.getUTCFullYear() < 1) return '0001-01-01';
    if (date.getUTCFullYear() > 9999) return '9999-12-31';
    return date.toISOString().slice(0, 10);
}

async function withWindow(run, { enabled = true, reduced = false, from = '2026-10-05', until = '2026-10-11',
    availableOwner = true, reject = false, deferredNextTick = false, gsap = false, hooks = false } = {}) {
    const { document } = parseHTML('<!doctype html><html><body><div id="timeline"><div id="grid"></div></div></body></html>');
    const root = document.getElementById('timeline');
    Object.assign(root.dataset, { timelineWindowNavigation: String(enabled), timelinePeriodOwner: 'plan-owner', timelineFrom: from, timelineUntil: until });
    Object.assign(root.dataset, { timelineAxisFrom: /^\d{4}-\d{2}-\d{2}$/.test(from) ? dateOffset(from, -3) : '2026-10-02',
        timelineAxisUntil: dateOffset(until, 3), timelineSelectedDays: '7', timelineBufferBefore: '3', timelineBufferAfter: '3', timelineId: 'timeline-owner' });
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
    const commitHooks = new Set();
    const beginCommit = (id, calls = [], fields = {}) => {
        const successes = [], failures = [];
        const payload = { calls, ...fields };
        for (const hook of commitHooks) hook({ component: { id }, commit: payload,
            succeed: callback => successes.push(callback), fail: callback => failures.push(callback) });
        return { payload, succeed: () => successes.forEach(callback => callback()), fail: () => failures.forEach(callback => callback()) };
    };
    let sequence = 0;
    let offset = 0;
    let footerOffset = 0;
    let reduce = reduced;
    const width = () => parseFloat(root.style.getPropertyValue('--timeline-day-width')) || 350;
    const nameWidth = () => parseFloat(root.style.getPropertyValue('--timeline-name-width'))
        || (root.dataset.personnelCompact === 'true' ? 52 : 150);
    const body = {
        clientWidth: 850, offsetWidth: 856, scrollTop: 288, scrollCalls: [],
        get scrollWidth() { return nameWidth() + (enabled ? timeline.windowDisplayDayCount || 13 : 13) * width(); },
        get scrollLeft() { return offset; },
        set scrollLeft(value) { offset = Math.max(0, Math.min(value, this.scrollWidth - this.clientWidth)); },
        scrollTo(options) { this.scrollCalls.push(options); if (options.behavior === 'instant') this.scrollLeft = options.left; },
    };
    const header = { scrollLeft: 0, clientWidth: 700, querySelectorAll: () => [] };
    const footerListeners = new Map();
    const scrollbar = {
        get scrollLeft() { return footerOffset; },
        set scrollLeft(value) {
            const days = enabled ? timeline.windowDisplaySelectedDayCount : 13;
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
        Livewire: { ...(hooks ? { hook(name, callback) {
            assert.equal(name, 'commit'); commitHooks.add(callback); return () => commitHooks.delete(callback);
        } } : {}), find(id) {
            assert.equal(id, 'plan-owner');
            return availableOwner ? { applyPeriod(nextFrom, nextUntil) {
                calls.push([nextFrom, nextUntil]);
                if (reject) return Promise.reject(new Error('transport failed'));
                const commit = hooks ? beginCommit(id, [{ method: 'applyPeriod', params: [nextFrom, nextUntil] }]) : null;
                return new Promise((resolve, rejectPromise) => requests.push({ resolve, reject: rejectPromise, commit }));
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
        await run({ timeline, root, body, header, scrollbar, calls, requests, replacements, events, observers, timers, frames, tweens, commitHooks, beginCommit,
            width, first: () => 3 * width(), maximum: () => body.scrollWidth - body.clientWidth,
            logicalMaximum: () => body.scrollWidth - body.clientWidth - 3 * width(), flushFrame, period, tick, flushTicks,
            finishScroll: () => { flushFrame(); body.scrollLeft = body.scrollCalls.at(-1).left; },
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
        assert.deepEqual(observation.options, { attributes: true, attributeFilter: ['data-timeline-from', 'data-timeline-until', 'data-timeline-axis-from', 'data-timeline-axis-until',
            'data-timeline-focus-shift-id', 'data-timeline-focus-date'] });
    });
});

test('scaffold date click navigates immediately while contents load without extra period requests or confirmation replay', async () => {
    await withWindow(({ timeline, root, body, header, logicalMaximum, calls, period, flushFrame, first }) => {
        header.querySelectorAll = () => timeline.windowScaffoldDays.map(day => ({ dataset: { timelineDate: day.date } }));
        root.dataset.timelineFocusShiftId = '81';
        root.dataset.timelineFocusDate = '2026-10-10';
        timeline.measure();
        assert.equal(body.scrollCalls.length, 0, 'restored focus cannot move the window');
        body.scrollLeft = logicalMaximum();
        timeline.scrollDay(1);
        flushFrame();
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']]);
        assert.equal(timeline.windowContentLoading, true);
        body.scrollLeft = first();
        const beforeConfirmation = body.scrollCalls.length;
        timeline.requestDistributionFocus({ owner: 'plan-owner', shiftId: 81, date: '2026-10-12', selected: false });
        assert.equal(body.scrollCalls.length, beforeConfirmation + 1, 'known structure navigates without waiting for loaded contents or focus truth');
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']], 'presentation focus must not request another period');
        assert.equal(root.dataset.timelineFocusDate, '2026-10-10', 'presentation cannot change server selection');
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.equal(body.scrollCalls.length, beforeConfirmation + 1, 'confirmed contents do not replay the click');
        body.scrollLeft = logicalMaximum();
        timeline.scrollDay(1);
        flushFrame();
        const afterNavigation = body.scrollCalls.length;
        period('2026-10-07', '2026-10-13');
        flushFrame();
        timeline.measure();
        assert.equal(body.scrollCalls.length, afterNavigation, 'same selected shift cannot replay after its date index changes or period resets');
    }, { reduced: true });
});

test('a structurally rendered selected day with unloaded contents is immediately navigable and stays inside logical selected edges', async () => {
    await withWindow(({ timeline, root, body, header, logicalMaximum, calls, flushFrame, first }) => {
        header.querySelectorAll = () => timeline.windowScaffoldDays.map(day => ({ dataset: { timelineDate: day.date, pending: String(day.pending) } }));
        body.scrollLeft = logicalMaximum();
        for (let index = 0; index < 4; index++) timeline.scrollDay(1);
        flushFrame();
        assert.equal(timeline.windowDisplayPeriod.until, '2026-10-15');
        assert.equal(timeline.windowDayLoading('2026-10-15'), true);
        assert.equal(root.dataset.timelineUntil, '2026-10-11');
        body.scrollLeft = first();
        const before = calls.length;
        timeline.requestDistributionFocus({ owner: 'plan-owner', shiftId: 81, date: '2026-10-15', selected: false });
        assert.equal(body.scrollLeft, logicalMaximum());
        assert.equal(calls.length, before, 'blank date structure does not require a focus-only period request');
        assert.equal(root.dataset.timelineUntil, '2026-10-11', 'confirmed scope remains server-owned');
        assert.equal(body.scrollTop, 288);
    }, { reduced: true });
});

test('new picker selection and actual footer input cancel active focus without late tween or data response overwriting the newer viewport', async () => {
    for (const input of ['picker', 'footer']) {
        await withWindow(({ timeline, body, header, tweens, scrollbar, first, width }) => {
            header.querySelectorAll = () => timeline.windowScaffoldDays.map(day => ({ dataset: { timelineDate: day.date } }));
            timeline.requestDistributionFocus({ owner: 'plan-owner', shiftId: 81, date: '2026-10-10', selected: false });
            const focus = tweens[0];
            focus.state.left = first() + width();
            focus.options.onUpdate();
            if (input === 'picker') {
                const detail = { owner: 'plan-owner', from: '2026-11-01', until: '2026-11-07' };
                timeline.requestWindowPeriod({ detail });
                detail.promise.catch(() => {});
                assert.equal(detail.handled, true);
            } else {
                scrollbar.signal('pointerdown');
                scrollbar.scrollLeft = 0;
                timeline.syncHorizontal(scrollbar);
            }
            assert.equal(focus.killed, true);
            const offset = body.scrollLeft;
            focus.state.left = offset + width();
            focus.options.onUpdate();
            focus.options.onComplete();
            assert.equal(body.scrollLeft, offset);
            assert.equal(body.scrollTop, 288);
        }, { gsap: true });
    }
});

test('scoped server failures never rewind immediate presentation focus or invent another scroll', async () => {
    await withWindow(({ timeline, root, body, header, beginCommit, flushFrame }) => {
        header.querySelectorAll = () => timeline.windowScaffoldDays.map(day => ({ dataset: { timelineDate: day.date } }));
        root.dataset.timelineFocusShiftId = '81';
        root.dataset.timelineFocusDate = '2026-10-10';
        timeline.requestDistributionFocus({ owner: 'plan-owner', shiftId: 81, date: '2026-10-10', selected: false });
        assert.equal(body.scrollCalls.length, 1);
        const offset = body.scrollLeft;
        beginCommit('another-owner', [{ method: 'selectDistributionShift', params: [81] }]).fail();
        beginCommit('plan-owner', [{ method: 'selectDistributionShift', params: [81] }]).fail();
        flushFrame();
        assert.equal(body.scrollCalls.length, 1);
        assert.equal(body.scrollLeft, offset);
        assert.equal(root.dataset.timelineFocusShiftId, '81');
    }, { hooks: true, reduced: true });
});

test('same-date reselect navigates immediately and successful parent or child commits cannot replay it', async () => {
    await withWindow(({ timeline, root, body, header, beginCommit, flushFrame, first }) => {
        header.querySelectorAll = () => timeline.windowScaffoldDays.map(day => ({ dataset: { timelineDate: day.date } }));
        root.dataset.timelineFocusShiftId = '81';
        root.dataset.timelineFocusDate = '2026-10-10';
        const selection = { owner: 'plan-owner', shiftId: 81, date: '2026-10-10', selected: false };
        timeline.requestDistributionFocus(selection);
        assert.equal(body.scrollCalls.length, 1);
        timeline.requestDistributionFocus({ ...selection, selected: true });
        body.scrollLeft = first();
        timeline.requestDistributionFocus(selection);
        assert.equal(body.scrollCalls.length, 2, 'reselection needs neither a new attribute nor a later response');
        beginCommit('another-owner', [{ method: 'selectDistributionShift', params: [81] }]).succeed();
        flushFrame();
        assert.equal(body.scrollCalls.length, 2);
        beginCommit('plan-owner', [{ method: 'selectDistributionShift', params: [81] }]).succeed();
        assert.equal(body.scrollCalls.length, 2);
        flushFrame();
        assert.equal(body.scrollCalls.length, 2);
        beginCommit('timeline-owner').succeed();
        flushFrame();
        assert.equal(body.scrollCalls.length, 2, 'success without new click intent cannot replay focus');
    }, { hooks: true, reduced: true });
});

test('observed server focus attributes reactively refresh the scaffold highlight without creating scroll intent or observer recursion', async () => {
    await withWindow(({ timeline, root, body, flushFrame }) => {
        const revision = timeline.windowRevision;
        root.dataset.timelineFocusShiftId = '81';
        root.dataset.timelineFocusDate = '2026-10-10';
        timeline.windowObserver.callback([{ attributeName: 'data-timeline-focus-shift-id' }, { attributeName: 'data-timeline-focus-date' }]);
        assert.equal(timeline.windowRevision, revision + 1, 'one focus mutation batch invalidates the nonreactive dataset-backed header once');
        assert.equal(timeline.windowScaffoldDays.find(day => day.date === '2026-10-10').focus, true);
        flushFrame();
        assert.equal(body.scrollCalls.length, 0);
        assert.equal(Object.hasOwn(timeline, 'distributionFocusIntent'), false);
        assert.equal(timeline.windowRevision, revision + 1, 'measuring or a header repaint does not observe a new root focus mutation');
        timeline.windowObserver.callback([{ attributeName: 'data-timeline-from' }]);
        flushFrame();
        assert.equal(timeline.windowRevision, revision + 1, 'ordinary unchanged period attributes do not unnecessarily repaint focus');
        timeline.destroy();
        timeline.windowObserver.callback([{ attributeName: 'data-timeline-focus-date' }]);
        assert.equal(timeline.windowRevision, revision + 1, 'detached observers cannot mutate reactive state');
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

test('horizontal wheel advances dates at either logical edge without waiting for a response and preserves diagonal vertical input', async () => {
    await withWindow(({ timeline, body, header, first, logicalMaximum, width, calls, replacements, flushFrame }) => {
        let prevented = 0;
        const top = body.scrollTop;
        timeline.wheelPersonnel({ deltaX: -width(), deltaY: 20, preventDefault() { prevented++; } });
        flushFrame();
        assert.equal(body.scrollLeft, first());
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-04');
        assert.equal(body.scrollTop, top + 20, 'diagonal input retains its vertical portion at the logical edge');
        assert.equal(prevented, 1, 'the bounded presentation frame owns horizontal wheel input');
        body.scrollLeft = 0;
        timeline.syncHorizontal(body);
        assert.equal(body.scrollLeft, first());
        assert.equal(header.scrollLeft, first());
        body.scrollLeft = logicalMaximum();
        timeline.wheelPersonnel({ deltaX: width(), deltaY: 0, preventDefault() { prevented++; } });
        flushFrame();
        assert.equal(body.scrollLeft, logicalMaximum());
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-05');
        body.scrollLeft = body.scrollWidth - body.clientWidth;
        timeline.syncHorizontal(body);
        assert.equal(body.scrollLeft, logicalMaximum());
        assert.equal(calls.length, 1, 'latest reverse intent waits behind the active request');
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

test('pending wheel movement rolls a bounded scaffold with per-date loading and an older response cannot rewind it', async () => {
    await withWindow(({ timeline, body, period, flushFrame, width, first, calls }) => {
        timeline.scrollDay(-1);
        flushFrame();
        timeline.wheelPersonnel({ deltaX: -5 * width(), deltaY: 10, preventDefault() {} });
        flushFrame();
        assert.equal(body.scrollLeft, first());
        assert.equal(timeline.windowDisplayPeriod.from, '2026-09-30');
        assert.equal(timeline.windowScaffoldDays.length, 13);
        assert.equal(timeline.windowDayLoading('2026-10-01'), true);
        timeline.syncHorizontal(body);
        assert.equal(body.scrollLeft, first(), 'visible selection remains within the fixed scaffold');
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollLeft, first());
        assert.equal(body.scrollTop, 298);
        assert.equal(body.scrollCalls.length, 2, 'wheel movement executes before either response');
        assert.deepEqual(calls, [['2026-10-04', '2026-10-10'], ['2026-09-30', '2026-10-06']]);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-09-30');
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

test('left edge immediately reveals a cached day in a constant-size frame with only the new outer date pending', async () => {
    await withWindow(({ timeline, body, header, scrollbar, calls, requests, period, flushFrame, first, replacements, finishScroll }) => {
        timeline.scrollDay(-1);
        assert.deepEqual(calls, [['2026-10-04', '2026-10-10']]);
        assert.equal(timeline.windowLoading, true);
        assert.equal(replacements.length, 0);
        assert.equal(body.scrollCalls.length, 0, 'prefix compensation is committed before the first smooth movement');
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }], 'movement begins before the server reply');
        assert.equal(timeline.windowScaffoldDays.length, 13);
        assert.equal(timeline.windowScaffoldDays[0].date, '2026-10-01');
        assert.equal(timeline.windowScaffoldDays[0].pending, true);
        assert.equal(timeline.windowDayLoading('2026-10-01'), true);
        assert.equal(timeline.windowDayLoading('2026-10-04'), false);
        assert.match(timeline.windowScaffoldDays[0].label, /01\.10\./);
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
        assert.equal(timeline.windowScaffoldDays.some(day => day.pending), false);
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
        assert.equal(timeline.windowScaffoldDays.at(-1).date, '2026-10-15');
        assert.equal(timeline.windowScaffoldDays.at(-1).pending, true);
        assert.equal(timeline.windowContentOffset, -1);
        finishScroll();
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.equal(body.scrollLeft, logicalMaximum());
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: logicalMaximum(), behavior: 'smooth' }]);
        assert.equal(body.scrollTop, 288);
    });
});

test('an early morph updates loaded data without interrupting the existing native movement or advancing twice', async () => {
    await withWindow(({ timeline, body, period, flushFrame, width, first }) => {
        timeline.scrollDay(-1);
        flushFrame();
        body.scrollLeft = first() + width() / 2;
        period('2026-10-04', '2026-10-10');
        flushFrame();
        assert.equal(body.scrollLeft, first() + width() / 2);
        flushFrame();
        assert.deepEqual(body.scrollCalls, [{ left: first(), behavior: 'smooth' }]);
    });
});

test('an early forward morph does not change the optimistic frame or its unfinished target', async () => {
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
        assert.equal(body.scrollCalls.length, 1);
        assert.equal(body.scrollCalls[0].left, logicalMaximum());
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
        assert.equal(body.scrollLeft, first() + 500, 'footer maps the optimistic selected interval without a growing prefix');
        period('2026-10-04', '2026-10-10');
        flushFrame();
        flushFrame();
        assert.equal(body.scrollLeft, first() + 500);
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
        assert.equal(body.scrollCalls.length, 1);
        assert.equal(body.scrollCalls[0].left, first());
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
        assert.equal(root.dataset.timelineAxisUntil, '2026-10-08');
    }, { until: '2026-10-05' });
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

test('rejected owner calls keep the latest scaffold readable with explicit retry and do not publish unconfirmed dates', async () => {
    await withWindow(async ({ timeline, body, calls, replacements, first, finishScroll }) => {
        await timeline.scrollDay(-1);
        finishScroll();
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /erneut versuchen/);
        assert.equal(body.scrollLeft, first());
        assert.equal(body.scrollTop, 288);
        assert.equal(replacements.length, 0);
        assert.equal(timeline.windowContentLoading, true);
        await timeline.retryWindow();
        assert.equal(calls.length, 2);
        assert.deepEqual(calls[0], calls[1]);
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
        assert.equal(body.scrollCalls.length, 1, 'the newer picker cancels only future movement, not an already-issued animation');
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
        assert.equal(body.scrollCalls.length, 1);
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

test('rapid forward edge clicks remain immediate and coalesce intermediate ranges behind exactly one transport', async () => {
    await withWindow(async ({ timeline, body, logicalMaximum, calls, requests, period, flushFrame, replacements, tick }) => {
        body.scrollLeft = logicalMaximum();
        for (let index = 0; index < 8; index++) timeline.scrollDay(1);
        flushFrame();
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-13');
        assert.equal(timeline.windowScaffoldDays.length, 13);
        assert.equal(timeline.windowContentOffset, -8);
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']]);
        assert.equal(timeline.windowScaffoldDays.filter(day => day.pending).length, 8);
        assert.equal(body.scrollTop, 288);
        const offset = body.scrollLeft;
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12'], ['2026-10-13', '2026-10-19']]);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-13');
        assert.equal(body.scrollLeft, offset);
        assert.equal(replacements.length, 0, 'intermediate confirmed response must not overwrite the latest visible period URL');
        requests[0].resolve();
        await tick();
        period('2026-10-13', '2026-10-19');
        flushFrame();
        requests[1].resolve();
        await tick();
        assert.equal(timeline.windowContentLoading, false);
        assert.equal(timeline.windowContentOffset, 0);
        assert.equal(timeline.windowScaffoldDays.some(day => day.pending), false);
        assert.equal(replacements.length, 1);
        assert.equal(new URL(replacements[0].url).searchParams.get('from'), '2026-10-13');
    });
});

test('hundreds of pending moves keep date structure bounded and earlier data may fall entirely outside it', async () => {
    await withWindow(({ timeline, calls, flushFrame }) => {
        for (let index = 0; index < 300; index++) timeline.shiftWindow(-1);
        flushFrame();
        assert.equal(timeline.windowScaffoldDays.length, 13);
        assert.equal(timeline.windowContentOffset, 300);
        assert.equal(timeline.windowScaffoldDays.every(day => day.pending), true);
        assert.equal(calls.length, 1);
        assert.equal(timeline.windowDisplayPeriod.from, dateOffset('2026-10-05', -300));
    });
});

test('pending reversal replaces the queued target and never starts overlapping parent requests', async () => {
    await withWindow(({ timeline, calls, period, flushFrame, body, logicalMaximum }) => {
        body.scrollLeft = logicalMaximum();
        timeline.scrollDay(1);
        timeline.shiftWindow(1);
        timeline.shiftWindow(-1);
        timeline.shiftWindow(-1);
        timeline.shiftWindow(-1);
        flushFrame();
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-04');
        assert.equal(calls.length, 1);
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.deepEqual(calls[1], ['2026-10-04', '2026-10-10']);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-04');
    });
});

test('pending columns are date-specific, while previously loaded reserve days stay readable', async () => {
    await withWindow(({ timeline }) => {
        timeline.shiftWindow(1);
        assert.equal(timeline.windowDayLoading('2026-10-12'), false);
        assert.equal(timeline.windowDayLoading('2026-10-14'), false);
        assert.equal(timeline.windowDayLoading('2026-10-15'), true);
        timeline.shiftWindow(1);
        timeline.shiftWindow(1);
        timeline.shiftWindow(1);
        assert.deepEqual(timeline.windowScaffoldDays.filter(day => day.pending).map(day => day.date),
            ['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18']);
    });
});

test('timeout preserves all navigation freedom and retry waits for the actual active transport before draining newest intent', async () => {
    await withWindow(async ({ timeline, calls, requests, expireRequest, period, flushFrame, tick }) => {
        timeline.shiftWindow(1);
        expireRequest();
        assert.equal(timeline.windowLoading, false);
        assert.match(timeline.windowError, /erneut versuchen/);
        timeline.shiftWindow(1);
        timeline.shiftWindow(1);
        await timeline.retryWindow();
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-08');
        assert.equal(calls.length, 1, 'UI timeout must not start another still-overlapping applyPeriod transport');
        requests[0].resolve();
        await tick();
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.deepEqual(calls[1], ['2026-10-08', '2026-10-14']);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-08');
    });
});

test('planning period picker shares the serialized transport and immediately changes the bounded scaffold and selected scale', async () => {
    await withWindow(async ({ timeline, root, calls, requests, period, flushFrame, tick, body }) => {
        timeline.shiftWindow(1);
        const detail = { owner: 'plan-owner', from: '2027-03-28', until: '2027-03-28', handled: false, promise: null };
        timeline.requestWindowPeriod({ detail });
        assert.equal(detail.handled, true);
        assert.equal(timeline.windowDisplaySelectedDayCount, 1);
        assert.equal(timeline.windowDisplayDayCount, 7);
        assert.equal(timeline.windowScaffoldDays.every(day => day.pending), true);
        assert.equal(calls.length, 1);
        timeline.measure();
        assert.equal(root.style.getPropertyValue('--timeline-day-width'), '700px');
        assert.equal(String(root.style.getPropertyValue('--timeline-selected-days')), '1');
        assert.equal(body.scrollTop, 288);
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.deepEqual(calls[1], ['2027-03-28', '2027-03-28']);
        requests[0].resolve();
        await tick();
        period('2027-03-28', '2027-03-28');
        flushFrame();
        requests[1].resolve();
        await detail.promise;
        assert.equal(timeline.windowContentLoading, false);
        assert.equal(timeline.windowDisplaySelectedDayCount, 1);
    });
});

test('picker events are scoped to exact planning owner and malformed or oversized periods retain native server validation', async () => {
    await withWindow(({ timeline, calls }) => {
        for (const detail of [
            { owner: 'another-owner', from: '2026-10-01', until: '2026-10-07' },
            { owner: 'plan-owner', from: 'bad', until: '2026-10-07' },
            { owner: 'plan-owner', from: '2026-01-01', until: '2026-12-31' },
        ]) {
            timeline.requestWindowPeriod({ detail });
            assert.equal(detail.handled, undefined);
            assert.equal(detail.promise, undefined);
        }
        assert.equal(calls.length, 0);
    });
});

test('a newer picker rejects the superseded waiting selection and only the newest period is loaded after current transport', async () => {
    await withWindow(async ({ timeline, calls, period, flushFrame }) => {
        const first = { owner: 'plan-owner', from: '2026-11-01', until: '2026-11-07' };
        timeline.requestWindowPeriod({ detail: first });
        const rejected = assert.rejects(first.promise, /neuere Auswahl/);
        const second = { owner: 'plan-owner', from: '2026-12-01', until: '2026-12-07' };
        timeline.requestWindowPeriod({ detail: second });
        await rejected;
        assert.equal(calls.length, 1);
        period('2026-11-01', '2026-11-07');
        flushFrame();
        assert.deepEqual(calls[1], ['2026-12-01', '2026-12-07']);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-12-01');
        period('2026-12-01', '2026-12-07');
        flushFrame();
        await second.promise;
    });
});

test('picker rejection reports failure without destroying the selected day structure', async () => {
    await withWindow(async ({ timeline }) => {
        const detail = { owner: 'plan-owner', from: '2026-11-01', until: '2026-11-07' };
        timeline.requestWindowPeriod({ detail });
        await assert.rejects(detail.promise, /nicht geladen/);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-11-01');
        assert.equal(timeline.windowScaffoldDays.length, 13);
        assert.equal(timeline.windowContentLoading, true);
        assert.match(timeline.windowError, /erneut versuchen/);
    }, { reject: true });
});

test('94 selected days plus reserve never produce more than 100 structural date columns', async () => {
    await withWindow(({ timeline }) => {
        assert.equal(timeline.windowDisplaySelectedDayCount, 94);
        assert.equal(timeline.windowDisplayDayCount, 100);
        timeline.shiftWindow(1, 100);
        assert.equal(timeline.windowDisplayDayCount, 100);
    }, { until: dateOffset('2026-10-05', 93) });
});

test('structural reserve clamps safely at supported calendar boundaries without invented year zero or year10000', async () => {
    for (const [from, until, first, last] of [
        ['0001-01-01', '0001-01-07', '0001-01-01', '0001-01-10'],
        ['9999-12-25', '9999-12-31', '9999-12-22', '9999-12-31'],
    ]) {
        await withWindow(({ timeline, calls }) => {
            assert.equal(timeline.windowScaffoldDays[0].date, first);
            assert.equal(timeline.windowScaffoldDays.at(-1).date, last);
            assert.equal(timeline.windowDisplayDayCount, 10);
            timeline.shiftWindow(from === '0001-01-01' ? -1 : 1);
            assert.equal(calls.length, 0);
        }, { from, until });
    }
});

test('finite timeline getters preserve actual loaded geometry without phantom reserve or loading state', async () => {
    await withWindow(({ timeline, root }) => {
        assert.equal(timeline.windowDisplayDayCount, 13);
        assert.equal(timeline.windowContentOffset, 0);
        assert.equal(timeline.windowContentLoading, false);
        assert.equal(timeline.windowScaffoldDays[0].date, root.dataset.timelineAxisFrom);
        assert.equal(timeline.windowScaffoldDays.at(-1).date, root.dataset.timelineAxisUntil);
        const detail = { owner: 'plan-owner', from: '2026-11-01', until: '2026-11-07' };
        timeline.requestWindowPeriod({ detail });
        assert.equal(detail.handled, undefined);
    }, { enabled: false });
});

test('a queued period waits for parent promise, parent commit, child reactive commit and next frame before reusing the request pool', async () => {
    await withWindow(async ({ timeline, calls, requests, period, flushFrame, tick, beginCommit }) => {
        timeline.shiftWindow(1);
        timeline.shiftWindow(1);
        const child = beginCommit('timeline-owner');
        period('2026-10-06', '2026-10-12');
        flushFrame();
        assert.equal(calls.length, 1, 'child attributes alone do not mean the parent commit is settled');
        requests[0].resolve();
        await tick();
        flushFrame();
        assert.equal(calls.length, 1, 'busy parent and child pools still hold the queue');
        requests[0].commit.succeed();
        flushFrame();
        assert.equal(calls.length, 1, 'the empty reactive child commit must settle before another parent request');
        child.succeed();
        assert.equal(calls.length, 1, 'pool cleanup completes only after the current success callback');
        flushFrame();
        assert.deepEqual(calls[1], ['2026-10-07', '2026-10-13']);
    }, { hooks: true });
});

test('unrelated component requests never delay the timeline serialized latest target', async () => {
    await withWindow(async ({ timeline, calls, requests, period, flushFrame, tick, beginCommit }) => {
        const unrelated = beginCommit('ai-assistant');
        timeline.shiftWindow(1);
        timeline.shiftWindow(1);
        period('2026-10-06', '2026-10-12');
        requests[0].resolve();
        requests[0].commit.succeed();
        await tick();
        flushFrame();
        assert.equal(calls.length, 2);
        unrelated.succeed();
        flushFrame();
        assert.equal(calls.length, 2);
    }, { hooks: true });
});

test('scoped HTTP failure releases actual transport even when the action promise never rejects and explicit retry works', async () => {
    await withWindow(async ({ timeline, calls, requests, flushFrame }) => {
        timeline.shiftWindow(1);
        requests[0].commit.fail();
        assert.equal(timeline.windowLoading, false);
        assert.equal(timeline.windowContentLoading, true);
        assert.match(timeline.windowError, /erneut versuchen/);
        flushFrame();
        assert.equal(calls.length, 1, 'failure does not create an automatic retry loop');
        timeline.retryWindow();
        assert.equal(calls.length, 2, 'finished failed HTTP transport no longer blocks retry despite its unresolved method promise');
        assert.deepEqual(calls[1], calls[0]);
    }, { hooks: true });
});

test('a pre-existing child request defers the new period request but cannot block its immediate structural display', async () => {
    await withWindow(({ timeline, calls, beginCommit, flushFrame }) => {
        const child = beginCommit('timeline-owner');
        timeline.shiftWindow(1);
        assert.equal(timeline.windowDisplayPeriod.from, '2026-10-06');
        assert.equal(timeline.windowScaffoldDays.length, 13);
        assert.equal(calls.length, 0);
        child.succeed();
        flushFrame();
        assert.deepEqual(calls, [['2026-10-06', '2026-10-12']]);
    }, { hooks: true });
});

test('commit tracking is removed on destroy and late callbacks cannot restart the queue or create frames', async () => {
    await withWindow(({ timeline, calls, requests, commitHooks, frames }) => {
        assert.equal(commitHooks.size, 1);
        timeline.shiftWindow(1);
        timeline.shiftWindow(1);
        timeline.destroy();
        assert.equal(commitHooks.size, 0);
        requests[0].commit.succeed();
        assert.equal(calls.length, 1);
        assert.equal(frames.size, 0);
    }, { hooks: true });
});

function unchangedRootCommit() {
    const plain = {
        rangeFrom: '2026-10-05', rangeTo: '2026-10-11', distributionOpen: true,
        selectedServiceId: null, serviceDetailVersion: 0, servicePerPage: 50,
        paginators: { distributionShiftsPage: 1, distributionOrdersPage: 1, servicesPage: 2 },
        serviceFilters: { status: 'all', choices: ['draft', 'accepted'] },
    };
    const data = { ...plain,
        paginators: [{ ...plain.paginators }, { s: 'arr' }],
        serviceFilters: [{ status: 'all', choices: [['draft', 'accepted'], { s: 'arr' }] }, { s: 'arr' }],
    };
    const reordered = Object.fromEntries(Object.entries(plain).filter(([key]) => key !== 'servicePerPage'));
    reordered.servicePerPage = 50;
    return { snapshot: JSON.stringify({ data, memo: { id: 'plan-owner' }, checksum: 'signed-snapshot-unchanged' }),
        updates: { '': reordered }, calls: [{ method: 'applyPeriod', params: ['2026-10-11', '2026-10-17'] }] };
}

test('only complete unchanged top-level reordered root data is removed after recursive snapshot synth unwrap', () => {
    const commit = unchangedRootCommit();
    const snapshot = commit.snapshot;
    const calls = commit.calls;
    assert.equal(timelineDiscardUnchangedRootUpdate(commit), true);
    assert.deepEqual(commit.updates, {});
    assert.equal(commit.snapshot, snapshot, 'signed snapshot, synth metadata and checksum remain byte-for-byte unchanged');
    assert.equal(commit.calls, calls, 'server-authorized actions retain their original references and parameters');
    assert.equal(JSON.parse(commit.snapshot).data.paginators[1].s, 'arr');
});

test('discarding a root no-op preserves every sibling update, action and checksum', () => {
    const commit = unchangedRootCommit();
    commit.updates.search = 'Dienst';
    commit.updates['paginators.servicesPage'] = 3;
    const sibling = { starts_at: '2026-10-11' };
    commit.updates.form = sibling;
    const snapshot = commit.snapshot;
    const calls = JSON.stringify(commit.calls);
    assert.equal(timelineDiscardUnchangedRootUpdate(commit), true);
    assert.deepEqual(Object.keys(commit.updates), ['search', 'paginators.servicesPage', 'form']);
    assert.equal(commit.updates.form, sibling);
    assert.equal(commit.updates.search, 'Dienst');
    assert.equal(commit.updates['paginators.servicesPage'], 3);
    assert.equal(commit.snapshot, snapshot);
    assert.equal(JSON.stringify(commit.calls), calls);
});

test('real root value and strict-type changes are never suppressed', () => {
    for (const mutate of [
        root => { root.rangeFrom = '2026-10-30'; },
        root => { root.servicePerPage = '50'; },
        root => { root.selectedServiceId = 1; },
        root => { root.serviceDetailVersion = false; },
        root => { root.paginators.servicesPage = 3; },
        root => { root.serviceFilters.choices[0] = 'confirmed'; },
    ]) {
        const commit = unchangedRootCommit();
        mutate(commit.updates['']);
        const updates = JSON.stringify(commit.updates);
        assert.equal(timelineDiscardUnchangedRootUpdate(commit), false);
        assert.equal(JSON.stringify(commit.updates), updates);
    }
});

test('missing, extra and substituted public root keys remain subject to ordinary Livewire server validation', () => {
    for (const mutate of [
        root => { delete root.servicePerPage; },
        root => { root.additionalPublicValue = null; },
        root => { delete root.servicePerPage; root.additionalPublicValue = 50; },
    ]) {
        const commit = unchangedRootCommit();
        mutate(commit.updates['']);
        assert.equal(timelineDiscardUnchangedRootUpdate(commit), false);
        assert.equal(Object.hasOwn(commit.updates, ''), true);
    }
});

test('nested object order, array order, array shape and nested key changes remain real updates', () => {
    for (const mutate of [
        root => { root.paginators = { servicesPage: 2, distributionOrdersPage: 1, distributionShiftsPage: 1 }; },
        root => { root.serviceFilters = { choices: ['draft', 'accepted'], status: 'all' }; },
        root => { root.serviceFilters.choices.reverse(); },
        root => { root.serviceFilters.choices = { 0: 'draft', 1: 'accepted' }; },
        root => { root.serviceFilters.choices.push('approved'); },
        root => { delete root.paginators.servicesPage; },
        root => { root.serviceFilters.extra = null; },
    ]) {
        const commit = unchangedRootCommit();
        mutate(commit.updates['']);
        const root = commit.updates[''];
        assert.equal(timelineDiscardUnchangedRootUpdate(commit), false);
        assert.equal(commit.updates[''], root);
    }
});

test('malformed snapshots, non-object root replacements and absent empty-path updates are never changed', () => {
    for (const commit of [
        { updates: { '': {} } },
        { snapshot: 'invalid-json', updates: { '': {} } },
        { snapshot: JSON.stringify({ data: null }), updates: { '': {} } },
        { snapshot: JSON.stringify({ data: {} }), updates: { '': [] } },
        { snapshot: JSON.stringify({ data: {} }), updates: { '': null } },
        { snapshot: JSON.stringify({ data: {} }), updates: { '': 'value' } },
        { snapshot: JSON.stringify({ data: {} }), updates: { search: 'value' } },
    ]) {
        const before = JSON.stringify(commit);
        assert.equal(timelineDiscardUnchangedRootUpdate(commit), false);
        assert.equal(JSON.stringify(commit), before);
    }
});

test('synthesized scalar and array snapshots compare their public values while opaque objects are not treated as no-ops', () => {
    const commit = { snapshot: JSON.stringify({ data: { status: ['confirmed', { s: 'enm', class: 'Status' }],
        model: [null, { s: 'mdl', class: 'Model' }], rows: [[{ id: 1, enabled: true }, { id: 2, enabled: false }], { s: 'clct' }] } }),
        updates: { '': { rows: [{ id: 1, enabled: true }, { id: 2, enabled: false }], model: null, status: 'confirmed' } } };
    assert.equal(timelineDiscardUnchangedRootUpdate(commit), true);
    const opaque = { snapshot: JSON.stringify({ data: { value: '2026-10-11T00:00:00.000Z' } }),
        updates: { '': { value: new Date('2026-10-11T00:00:00.000Z') } } };
    assert.equal(timelineDiscardUnchangedRootUpdate(opaque), false, 'JSON serialization equivalence alone is not proof of strict public-data equality');
});

test('frozen canonical snapshot public data and synth metadata are never mutated by the no-op proof', () => {
    const data = Object.freeze({ rangeFrom: '2026-10-05', paginators: Object.freeze([
        Object.freeze({ staffPage: 2, servicesPage: 3 }), Object.freeze({ s: 'arr' }),
    ]) });
    const snapshot = Object.freeze({ data, checksum: 'frozen-signed-snapshot' });
    const commit = { snapshot, updates: { '': { paginators: { staffPage: 2, servicesPage: 3 }, rangeFrom: '2026-10-05' } } };
    assert.equal(timelineDiscardUnchangedRootUpdate(commit), true);
    assert.equal(commit.snapshot, snapshot);
    assert.equal(snapshot.data, data);
    assert.equal(snapshot.data.paginators[1].s, 'arr');
    assert.equal(snapshot.checksum, 'frozen-signed-snapshot');
});

test('unsupported cyclic snapshot data fails closed and retains the complete invalid root update', () => {
    const cyclic = {};
    cyclic.self = cyclic;
    const root = { value: cyclic };
    const commit = { snapshot: { data: { value: cyclic }, checksum: 'unchanged' }, updates: { '': root } };
    assert.equal(timelineDiscardUnchangedRootUpdate(commit), false);
    assert.equal(commit.updates[''], root);
    assert.equal(commit.snapshot.data.value, cyclic);
    assert.equal(commit.snapshot.checksum, 'unchanged');
});

test('the compatibility no-op check runs only for the embedded timeline period owner, never child or unrelated components', async () => {
    await withWindow(({ beginCommit }) => {
        for (const id of ['timeline-owner', 'another-owner', 'ai-assistant']) {
            const fields = unchangedRootCommit();
            const root = fields.updates[''];
            const transaction = beginCommit(id, fields.calls, fields);
            assert.equal(transaction.payload.updates[''], root);
            transaction.succeed();
        }
        const fields = unchangedRootCommit();
        const transaction = beginCommit('plan-owner', fields.calls, fields);
        assert.equal(Object.hasOwn(transaction.payload.updates, ''), false);
        transaction.succeed();
    }, { hooks: true });
});

test('finite timelines never register or apply the buffered owner compatibility hook', async () => {
    await withWindow(({ beginCommit, commitHooks }) => {
        assert.equal(commitHooks.size, 0);
        const fields = unchangedRootCommit();
        const root = fields.updates[''];
        const transaction = beginCommit('plan-owner', fields.calls, fields);
        assert.equal(transaction.payload.updates[''], root);
    }, { hooks: true, enabled: false });
});
