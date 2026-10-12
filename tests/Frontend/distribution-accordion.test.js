import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { distributionPanel } from '../../resources/js/distribution-accordion.js';

const dates = ['2027-05-09', '2027-05-10', '2027-05-11'];

test('only explicitly opening a known day dispatches a reveal to its own timeline', () => fixture(({ panel, root, requests, refresh }) => {
    root.dataset.distributionOwner = 'shift-owner-17';
    panel.toggleDay(dates[1]); // Close the initially open day.
    assert.deepEqual(requests, []);
    panel.toggleDay(dates[0]);
    assert.deepEqual(requests, [['operations-timeline-day-reveal', { owner: 'shift-owner-17', date: dates[0] }]]);
    panel.toggleDay(dates[2]);
    assert.equal(requests.length, 2);
    panel.toggleDay(dates[2]);
    panel.toggleDay('not-a-day');
    panel.revealDay(dates[1]);
    refresh({ distributionContext: 'changed-context' });
    assert.equal(requests.length, 2, 'close, invalid date, focus reveal and metadata refresh are not navigation gestures');
}));

function fixture(run, { days = dates, context = '2027-05-10|2027-05-16|all|1', selected = null,
    preferred = '2027-05-10', initial = {}, observer = true } = {}) {
    const { document } = parseHTML('<div data-pending-distribution></div>');
    const root = document.querySelector('[data-pending-distribution]');
    Object.assign(root.dataset, { distributionDays: JSON.stringify(days), distributionContext: context,
        distributionSelected: JSON.stringify(selected), distributionPreferredDay: preferred });
    const original = Object.getOwnPropertyDescriptor(globalThis, 'MutationObserver');
    const observers = [], focusCalls = [], requests = [], scrolls = [];
    class Observer {
        constructor(callback) { this.callback = callback; this.disconnects = 0; observers.push(this); }
        observe(target, options) { this.target = target; this.options = options; }
        disconnect() { this.disconnects++; }
    }
    if (observer) globalThis.MutationObserver = Observer;
    else delete globalThis.MutationObserver;
    const panel = distributionPanel(initial);
    Object.assign(panel, { $el: root, $wire: new Proxy({}, { get: (_, key) => {
        requests.push(key); throw new Error('Accordion must never read or call Livewire.');
    } }), $dispatch: (...args) => requests.push(args) });
    root.scrollIntoView = (...args) => scrolls.push(args);
    const setDays = values => {
        root.dataset.distributionDays = JSON.stringify(values);
        for (const button of root.querySelectorAll('[data-distribution-day-toggle]')) button.remove();
        for (const date of values) {
            const button = document.createElement('button');
            button.dataset.distributionDayToggle = date;
            button.focus = () => focusCalls.push(date);
            root.append(button);
        }
    };
    const refresh = values => {
        Object.assign(root.dataset, values);
        observers[0]?.callback([{ target: root, type: 'attributes' }]);
    };
    setDays(days);
    panel.init();
    try { run({ panel, root, document, observers, refresh, setDays, focusCalls, requests, scrolls }); }
    finally {
        panel.destroy();
        if (original) Object.defineProperty(globalThis, 'MutationObserver', original);
        else delete globalThis.MutationObserver;
    }
}

test('initial day prefers the selected range start rather than the preceding overnight bucket', () => fixture(({ panel, root, observers }) => {
    assert.equal(panel.distributionTab, 'shifts');
    assert.equal(panel.distributionMode, 'list');
    assert.equal(panel.openDay, '2027-05-10');
    assert.deepEqual(panel.days, dates);
    assert.equal(observers.length, 1);
    assert.equal(observers[0].target, root);
    assert.deepEqual(observers[0].options, { attributes: true, attributeFilter: [
        'data-distribution-days', 'data-distribution-context', 'data-distribution-selected', 'data-distribution-preferred-day',
    ] });
}));

test('a real selected shift takes initial priority over the preferred day', () => fixture(({ panel }) => {
    assert.equal(panel.openDay, '2027-05-11');
}, { selected: { id: 81, date: '2027-05-11' } }));

test('malformed selected-shift ids cannot override the preferred day on initialization or a new context', () => {
    for (const id of [0, -1, '81', null, Number.MAX_SAFE_INTEGER + 1]) {
        fixture(({ panel, refresh }) => {
            assert.equal(panel.openDay, '2027-05-10');
            refresh({ distributionContext: 'another-page', distributionSelected: JSON.stringify({ id, date: '2027-05-09' }) });
            assert.equal(panel.openDay, '2027-05-10');
        }, { selected: { id, date: '2027-05-11' } });
    }
});

test('missing preferred and off-page selection fall back to the first displayed day, with truthful empty state', () => {
    fixture(({ panel }) => assert.equal(panel.openDay, dates[0]), { preferred: '2027-05-12', selected: { id: 81, date: '2027-05-14' } });
    fixture(({ panel }) => {
        assert.deepEqual(panel.days, []);
        assert.equal(panel.openDay, null);
        assert.equal(panel.distributionTab, 'orders');
    }, { days: [], initial: { distributionTab: 'orders' } });
});

test('opening a different day replaces the old one and clicking again closes all without business or timeline effects', () => fixture(({ panel, requests, scrolls }) => {
    panel.toggleDay('2027-05-11');
    assert.equal(panel.openDay, '2027-05-11');
    panel.toggleDay('2027-05-09');
    assert.equal(panel.openDay, '2027-05-09');
    panel.toggleDay('2027-05-09');
    assert.equal(panel.openDay, null);
    panel.toggleDay('2027-05-25');
    panel.revealDay('2027-05-25');
    assert.equal(panel.openDay, null);
    panel.revealDay('2027-05-10');
    assert.equal(panel.openDay, '2027-05-10');
    assert.deepEqual(requests, []);
    assert.deepEqual(scrolls, []);
}));

test('same selection and unrelated morphs preserve a manual close instead of reopening the chosen shift repeatedly', () => fixture(({ panel, refresh }) => {
    panel.toggleDay('2027-05-11');
    assert.equal(panel.openDay, null);
    refresh({ distributionSelected: JSON.stringify({ id: 81, date: '2027-05-11' }) });
    refresh({ distributionDays: JSON.stringify(dates), distributionPreferredDay: '2027-05-09' });
    assert.equal(panel.openDay, null);
    panel.refreshDays();
    assert.equal(panel.openDay, null);
}, { selected: { id: 81, date: '2027-05-11' } }));

test('changed selection reveals its own day, including a different shift on the same previously closed day', () => fixture(({ panel, refresh }) => {
    panel.toggleDay('2027-05-10');
    refresh({ distributionSelected: JSON.stringify({ id: 81, date: '2027-05-11' }) });
    assert.equal(panel.openDay, '2027-05-11');
    panel.toggleDay('2027-05-11');
    refresh({ distributionSelected: JSON.stringify({ id: 82, date: '2027-05-11' }) });
    assert.equal(panel.openDay, '2027-05-11');
    panel.toggleDay('2027-05-11');
    refresh({ distributionSelected: 'null' });
    assert.equal(panel.openDay, null, 'clearing native selection never invents a new open group');
}));

test('new range, customer and named-page context resets use current metadata without mutating tab or auto/list mode', () => fixture(({ panel, refresh }) => {
    panel.distributionTab = 'orders';
    panel.distributionMode = 'auto';
    for (const context of ['2027-05-10|2027-05-16|all|2', '2027-05-10|2027-05-16|4|2', '2027-05-17|2027-05-23|4|1']) {
        panel.toggleDay(panel.openDay);
        assert.equal(panel.openDay, null);
        refresh({ distributionContext: context, distributionPreferredDay: '2027-05-11' });
        assert.equal(panel.openDay, '2027-05-11');
        assert.equal(panel.distributionTab, 'orders');
        assert.equal(panel.distributionMode, 'auto');
    }
}));

test('removing the open day selects an available fallback; an empty page never retains stale dates', () => fixture(({ panel, refresh }) => {
    panel.toggleDay('2027-05-11');
    refresh({ distributionDays: JSON.stringify(['2027-05-09', '2027-05-10']) });
    assert.equal(panel.openDay, '2027-05-10');
    refresh({ distributionDays: '[]' });
    assert.equal(panel.openDay, null);
    assert.deepEqual(panel.days, []);
    refresh({ distributionDays: JSON.stringify(['2027-05-12']), distributionContext: 'next-page' });
    assert.equal(panel.openDay, '2027-05-12');
}));

test('manual closed state survives tab and inline-auto round trips without adding requests or changing a chosen shift', () => fixture(({ panel, refresh, requests }) => {
    panel.toggleDay('2027-05-11');
    for (const [tab, mode] of [['orders', 'list'], ['shifts', 'auto'], ['shifts', 'list']]) {
        panel.distributionTab = tab;
        panel.distributionMode = mode;
        refresh({ distributionSelected: JSON.stringify({ id: 81, date: '2027-05-11' }) });
        assert.equal(panel.openDay, null);
        assert.equal(panel.distributionTab, tab);
        assert.equal(panel.distributionMode, mode);
    }
    assert.deepEqual(requests, []);
}, { selected: { id: 81, date: '2027-05-11' } }));

test('malformed and oversized metadata cannot fabricate extra groups; server day keys stay unique and bounded to one page', () => fixture(({ panel, refresh }) => {
    for (const raw of ['{', 'null', '{}', 'true', 'x'.repeat(8193)]) {
        refresh({ distributionDays: raw });
        assert.deepEqual(panel.days, []);
        assert.equal(panel.openDay, null);
    }
    const many = Array.from({ length: 30 }, (_, index) => `2027-05-${String(index + 1).padStart(2, '0')}`);
    refresh({ distributionDays: JSON.stringify(['invalid', null, 5, '2027-05-01', ...many]), distributionContext: 'bounded' });
    assert.equal(panel.days.length, 25);
    assert.equal(panel.days[0], '2027-05-01');
    assert.equal(panel.days.at(-1), '2027-05-25');
    refresh({ distributionSelected: '{' });
    assert.equal(panel.openDay, '2027-05-10');
    refresh({ distributionSelected: JSON.stringify({ id: 81, date: '2027-05-31' }) });
    assert.equal(panel.openDay, '2027-05-10');
}));

test('keyboard arrows wrap and Home/End move focus only; unrelated keys and nested targets are not intercepted', () => fixture(({ panel, root, document, focusCalls }) => {
    const buttons = [...root.querySelectorAll('[data-distribution-day-toggle]')];
    let prevented = 0;
    const event = (key, target) => ({ key, target, preventDefault: () => prevented++ });
    panel.navigateDays(event('ArrowUp', buttons[0]));
    panel.navigateDays(event('ArrowDown', buttons[2]));
    panel.navigateDays(event('End', buttons[0]));
    panel.navigateDays(event('Home', buttons[2]));
    assert.deepEqual(focusCalls, ['2027-05-11', '2027-05-09', '2027-05-11', '2027-05-09']);
    assert.equal(panel.openDay, '2027-05-10');
    assert.equal(prevented, 4);
    const nested = document.createElement('span'); buttons[0].append(nested);
    panel.navigateDays(event('ArrowDown', nested));
    panel.navigateDays(event('Enter', buttons[0]));
    panel.navigateDays(event('Tab', buttons[0]));
    assert.equal(prevented, 4);
    assert.equal(focusCalls.length, 4);
}));

test('Alpine event-button $el context cannot replace the captured root for keyboard navigation or observer metadata', () => fixture(({ panel, root, refresh, focusCalls, requests, scrolls }) => {
    const buttons = [...root.querySelectorAll('[data-distribution-day-toggle]')];
    panel.$el = buttons[0];
    let prevented = 0;
    panel.navigateDays({ key: 'ArrowDown', target: buttons[0], preventDefault: () => prevented++ });
    assert.equal(prevented, 1);
    assert.deepEqual(focusCalls, ['2027-05-10']);
    assert.equal(panel.openDay, '2027-05-10', 'keyboard navigation is focus only');
    refresh({ distributionSelected: JSON.stringify({ id: 81, date: '2027-05-11' }) });
    assert.deepEqual(panel.days, dates);
    assert.equal(panel.openDay, '2027-05-11', 'metadata is read from the original panel, not the event button');
    assert.deepEqual(requests, []);
    assert.deepEqual(scrolls, []);
}));

test('destroy disconnects the single observer and a queued callback cannot repaint detached presentation state', () => fixture(({ panel, root, observers }) => {
    const late = observers[0].callback;
    panel.toggleDay('2027-05-11');
    panel.destroy();
    assert.equal(observers[0].disconnects, 1);
    root.dataset.distributionDays = '[]';
    late([{ target: root, type: 'attributes' }]);
    assert.equal(panel.openDay, '2027-05-11');
    assert.deepEqual(panel.days, dates);
    panel.destroy();
    assert.equal(observers[0].disconnects, 1, 'cleanup is idempotent');
}));

test('without MutationObserver initialization and explicit local navigation still work', () => fixture(({ panel, observers }) => {
    assert.equal(observers.length, 0);
    assert.equal(panel.openDay, '2027-05-10');
    panel.toggleDay('2027-05-09');
    assert.equal(panel.openDay, '2027-05-09');
}, { observer: false }));
