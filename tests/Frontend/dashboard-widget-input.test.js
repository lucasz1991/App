import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { parseHTML } from 'linkedom';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const start = source.indexOf("Alpine.data('dashboardWidgetGrid',");
const end = source.indexOf('\n}));', start) + '\n}));'.length;
assert.ok(start >= 0 && end > start, 'Actual dashboard controller source must be available.');
const controllerSource = source.slice(start, end);

const mapBlade = readFileSync(new URL('../../resources/views/dashboard/widgets/operations_dispatch_map.blade.php', import.meta.url), 'utf8');
const pointArrowDown = mapBlade.match(/data-dispatch-marker="[^"]+"[\s\S]*?x-on:keydown\.arrow-down\.prevent\.stop="([^"]+)"/)?.[1];
assert.ok(pointArrowDown, 'Actual map point ArrowDown expression must be available.');
const dropdownBlade = readFileSync(new URL('../../resources/views/components/ui/dropdown/anchor-dropdown.blade.php', import.meta.url), 'utf8');
const focusHoverMethod = dropdownBlade.match(/^    focusHoverPanel\(\) \{([\s\S]*?)^    \},/m)?.[1];
assert.ok(focusHoverMethod, 'Actual shared dropdown keyboard focus method must be available.');

function fixture() {
    const { document, window } = parseHTML(`
        <html><body>
            <div id="grid" data-editing="false">
                <article data-widget-item data-widget-key="operations_dispatch_map" draggable="true">
                    <h2 id="title">Dispositionskarte</h2>
                    <label id="label"><span id="label-child">Datum</span><input id="date" type="date"></label>
                    <select id="select"><option>Heute</option></select>
                    <textarea id="textarea"></textarea>
                    <button id="button">Nächster Tag</button>
                    <a id="link" href="/plan">Plan öffnen</a>
                    <div id="resize" class="widget-resize-handle"></div>
                </article>
            </div>
        </body></html>
    `);
    const timers = new Map();
    const calls = [];
    let nextId = 1;
    let factory;
    new Function('Alpine', 'window', 'document', 'navigator', 'performance', 'setTimeout', 'clearTimeout', controllerSource)(
        { data(name, callback) { assert.equal(name, 'dashboardWidgetGrid'); factory = callback; } },
        Object.assign(window, { matchMedia: () => ({ matches: true }) }),
        document,
        { vibrate() {} },
        { now: () => 100 },
        (callback, delay) => { const id = nextId++; timers.set(id, { callback, delay }); return id; },
        (id) => { timers.delete(id); },
    );
    const component = factory();
    component.$root = document.getElementById('grid');
    component.$wire = {
        toggleEditing() { calls.push('toggleEditing'); },
        reorder(keys) { calls.push(['reorder', keys]); },
    };
    component.bindLongPress();
    component.bindReorder();

    function event(id, type, fields = {}) {
        const value = new window.Event(type, { bubbles: true, cancelable: true });
        Object.assign(value, { clientX: 20, clientY: 30, ...fields });
        document.getElementById(id).dispatchEvent(value);
        return value;
    }
    return { document, component, timers, calls, event };
}

test('date input, select, textarea and label gestures never enter dashboard editing', () => {
    for (const id of ['date', 'select', 'textarea', 'label', 'label-child']) {
        const state = fixture();
        const event = state.event(id, 'pointerdown');
        assert.equal(state.timers.size, 0, id);
        assert.equal(state.component.pressTimer, null, id);
        assert.equal(state.component.pressStart, null, id);
        assert.equal(event.defaultPrevented, false, 'Field retains native gesture: ' + id);
        assert.deepEqual(state.calls, [], id);
    }
});

test('existing buttons, links and resize handles still retain their own gesture', () => {
    for (const id of ['button', 'link', 'resize']) {
        const state = fixture();
        state.event(id, 'pointerdown');
        assert.equal(state.timers.size, 0, id);
        assert.deepEqual(state.calls, [], id);
    }
});

test('holding ordinary card content still invokes the existing native editing action', () => {
    const state = fixture();
    state.event('title', 'pointerdown');
    assert.equal(state.timers.size, 1);
    const timer = [...state.timers.values()][0];
    assert.equal(timer.delay, 550);
    timer.callback();
    assert.deepEqual(state.calls, ['toggleEditing']);
    assert.equal(state.component.pressTimer, null);
});

test('releasing, cancelling or moving beyond tolerance cancels the card hold', () => {
    for (const type of ['pointerup', 'pointercancel', 'pointermove']) {
        const state = fixture();
        state.event('title', 'pointerdown');
        state.event('title', type, { clientX: 35, clientY: 30 });
        assert.equal(state.timers.size, 0, type);
        assert.equal(state.component.pressTimer, null, type);
        assert.equal(state.component.pressStart, null, type);
        assert.deepEqual(state.calls, [], type);
    }
});

test('existing editing mode never schedules a second long hold', () => {
    const state = fixture();
    state.component.$root.dataset.editing = 'true';
    state.event('title', 'pointerdown');
    assert.equal(state.timers.size, 0);
});

test('dragging on a form field or its label cannot start a widget reorder', () => {
    for (const id of ['date', 'select', 'textarea', 'label', 'label-child', 'button', 'link', 'resize']) {
        const state = fixture();
        const event = state.event(id, 'dragstart', { dataTransfer: { effectAllowed: 'uninitialized', setDragImage() { assert.fail('Field drag must never set card ghost.'); } } });
        assert.equal(event.defaultPrevented, true, id);
        assert.equal(state.component.dragKey, null, id);
        assert.equal(state.document.querySelector('[data-widget-item]').classList.contains('is-dragging'), false, id);
    }
});

test('ordinary card drag preserves the existing reorder server action', () => {
    const state = fixture();
    const transfer = { effectAllowed: '', setDragImage() {} };
    const event = state.event('title', 'dragstart', { dataTransfer: transfer });
    assert.equal(event.defaultPrevented, false);
    assert.equal(state.component.dragKey, 'operations_dispatch_map');
    assert.equal(transfer.effectAllowed, 'move');
    state.event('title', 'dragend');
    assert.equal(state.component.dragKey, null);
    assert.deepEqual(state.calls, [['reorder', ['operations_dispatch_map']]]);
});

test('form controls and labels keep the native context menu while card hold remains protected', () => {
    for (const id of ['date', 'select', 'textarea', 'label', 'label-child']) {
        const state = fixture();
        assert.equal(state.event(id, 'contextmenu').defaultPrevented, false, id);
    }
    const state = fixture();
    assert.equal(state.event('title', 'contextmenu').defaultPrevented, true);
});

function pointKeyboardFixture(hiddenEntries = []) {
    const { document } = parseHTML(`
        <html><body><button id="point">Hamburg</button><div id="panel">
            <a id="shift-first" href="/shift/1" data-dispatch-point-entry="shift">First shift</a>
            <a id="shift-second" href="/shift/2" data-dispatch-point-entry="shift">Second shift</a>
            <a id="inquiry-first" href="/inquiry/1" data-dispatch-point-entry="inquiry">First inquiry</a>
            <a id="inquiry-second" href="/inquiry/2" data-dispatch-point-entry="inquiry">Second inquiry</a>
        </div></body></html>
    `);
    const nextTicks = [];
    const opens = [];
    let active = document.getElementById('point');
    for (const entry of document.querySelectorAll('[data-dispatch-point-entry]')) {
        // Supply the visibility produced by Alpine x-show, without simulating layout.
        entry.style.display = hiddenEntries.includes(entry.id) ? 'none' : 'block';
        entry.focus = () => { active = entry; };
    }
    const dropdown = new Function(`return ({ focusHoverPanel() { ${focusHoverMethod} } });`)();
    Object.assign(dropdown, {
        openOnHover: true,
        $refs: { panel: document.getElementById('panel') },
        $nextTick: callback => { nextTicks.push(callback); },
        clearHoverTimers() {},
        openDropdown(pinned) { opens.push(pinned); },
    });
    return {
        open() {
            new Function('getComputedStyle', `with (this) { ${pointArrowDown} }`).call(dropdown, entry => ({ display: entry.style.display }));
            while (nextTicks.length) nextTicks.shift()();
        },
        active: () => active.id,
        opens,
    };
}

test('map point ArrowDown enters the first visible inquiry when shift samples are filtered out', () => {
    const state = pointKeyboardFixture(['shift-first', 'shift-second']);
    state.open();
    assert.deepEqual(state.opens, [true], 'Keyboard opening retains the shared pinned popup behavior.');
    assert.equal(state.active(), 'inquiry-first');
});

test('map point ArrowDown still enters the first sample when all types are visible', () => {
    const state = pointKeyboardFixture();
    state.open();
    assert.equal(state.active(), 'shift-first');
});

test('map point ArrowDown skips a hidden leading sample within the same type', () => {
    const state = pointKeyboardFixture(['shift-first', 'inquiry-first', 'inquiry-second']);
    state.open();
    assert.equal(state.active(), 'shift-second');
});
