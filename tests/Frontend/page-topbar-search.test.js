import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(new URL('../../resources/views/components/tables/search-field.blade.php', import.meta.url), 'utf8');
const match = template.match(/x-data="(\{[\s\S]*?\})"\s+x-cloak/);
assert.ok(match, 'The shared search controller is the source under test.');

function setup({ page = true, topbarPage = true, mobile = true } = {}) {
    const listeners = new Map();
    const classes = new Set();
    const focused = [];
    const cancelled = [];
    const frames = new Map();
    let sequence = 0;
    let changes;
    const media = {
        matches: mobile,
        addEventListener: (event, callback) => { changes = callback; },
        removeEventListener: (event, callback) => { assert.equal(callback, changes); changes = null; },
    };
    const document = {
        addEventListener: (event, callback) => listeners.set(event, callback),
        removeEventListener: (event, callback) => {
            assert.equal(listeners.get(event), callback);
            listeners.delete(event);
        },
        documentElement: {
            classList: {
                toggle: (name, active) => active ? classes.add(name) : classes.delete(name),
                remove: (name) => classes.delete(name),
            },
        },
    };
    const events = [];
    const window = {
        matchMedia: () => media,
        requestAnimationFrame: (callback) => { frames.set(++sequence, callback); return sequence; },
        cancelAnimationFrame: (id) => { cancelled.push(id); frames.delete(id); },
        dispatchEvent: (event) => events.push(event),
    };
    const source = match[1]
        .replace(/value: @entangle[^\n]*,/, "value: '',")
        .replace('isTopbar: @js($isTopbarSearch)', 'isTopbar: true')
        .replace('isPageSearch: @js($isPageSearch)', `isPageSearch: ${page}`)
        .replace('isPageTopbarSearch: @js($isPageTopbarSearch)', `isPageTopbarSearch: ${topbarPage}`)
        .replace(/layerId: @js[^\n]*,/, "layerId: 'page-list-search',");
    const state = new Function('window', 'document', 'CustomEvent', `return (${source});`)(window, document, class {
        constructor(type, options) { this.type = type; this.detail = options.detail; }
    });
    const rootClasses = new Set();
    state.$root = {
        classList: { add: (name) => rootClasses.add(name) },
        contains: () => true,
        closest: () => null,
    };
    state.$refs = {
        input: { focus: (options) => focused.push(['input', options]) },
        trigger: { focus: (options) => focused.push(['trigger', options]) },
    };
    state.$nextTick = (callback) => callback();
    state.init();
    return { state, listeners, classes, focused, cancelled, frames, events, rootClasses,
        resize: (matches) => changes?.({ matches }) };
}

test('page-topbar search reuses the existing mobile dialog while ordinary page search stays inline', () => {
    const current = setup();
    assert.equal(current.state.isExpanded(), false);
    current.state.open();
    assert.equal(current.state.isMobileLayerOpen(), true);
    assert.equal(current.classes.has('rt-topbar-search-open'), true);
    assert.equal(current.rootClasses.has('is-mobile-layer'), true);
    assert.deepEqual(current.focused, [['input', { preventScroll: true }]]);
    assert.equal(current.events[0].detail.id, 'page-list-search');
    const ordinary = setup({ topbarPage: false });
    ordinary.state.open();
    assert.equal(ordinary.state.isMobileLayerOpen(), false);
    assert.equal(ordinary.classes.has('rt-topbar-search-open'), false);
});

test('one trusted topbar tap focuses immediately and repeated opens replace the owned frame', () => {
    const current = setup({ mobile: false });
    current.state.open();
    assert.equal(current.focused.length, 1);
    assert.equal(current.state.isMobileLayerOpen(), false);
    current.state.open();
    assert.deepEqual(current.cancelled, [1]);
    assert.equal(current.frames.size, 1);
    for (const callback of current.frames.values()) callback();
    assert.equal(current.focused.length, 3);
});

test('Escape and another topbar layer release the dialog without clearing its current query', () => {
    const current = setup();
    current.state.value = 'Demir';
    current.state.open();
    current.state.handleEscape();
    assert.equal(current.state.value, 'Demir');
    assert.equal(current.state.expanded, false);
    assert.equal(current.classes.size, 0);
    assert.deepEqual(current.focused.at(-1), ['trigger', { preventScroll: true }]);
    current.state.open();
    current.state.handleLayerOpen({ detail: { group: 'topbar', id: 'topbar-preferences' } });
    assert.equal(current.state.expanded, false);
    assert.equal(current.classes.size, 0);
    assert.equal(current.state.value, 'Demir');
});

test('navigation and destruction clean up the original listener, media subscription and focus frame', () => {
    const current = setup();
    current.state.open();
    current.listeners.get('livewire:navigating')();
    assert.equal(current.state.expanded, false);
    assert.equal(current.classes.size, 0);
    assert.equal(current.frames.size, 0);
    current.state.open();
    current.state.destroy();
    assert.equal(current.listeners.size, 0);
    assert.equal(current.frames.size, 0);
    assert.equal(current.classes.size, 0);
    current.resize(false);
    assert.equal(current.state.mobile, true);
});

test('viewport changes release page scroll locking and global search keeps its original mobile behavior', () => {
    const current = setup();
    current.state.open();
    current.resize(false);
    assert.equal(current.classes.size, 0);
    current.resize(true);
    assert.equal(current.classes.has('rt-topbar-search-open'), true);
    current.state.clear();
    assert.equal(current.state.value, '');
    assert.deepEqual(current.focused.at(-1), ['input', { preventScroll: true }]);
    const global = setup({ page: false, topbarPage: false });
    global.state.open();
    assert.equal(global.state.isMobileLayerOpen(), true);
});
