import test from 'node:test';
import assert from 'node:assert/strict';
import { areaMenu } from '../../resources/js/area-menu.js';

function fixture() {
    const attrs = new Map();
    const listeners = new Map();
    const button = { disabled: false, hasAttribute: () => false, getBoundingClientRect: () => ({ left: 96, top: 14, width: 44, height: 44 }), getAttribute: () => null };
    const menu = {
        clientLeft: 1, clientTop: 1, scrollLeft: 0, scrollTop: 0,
        getBoundingClientRect: () => ({ left: 10, top: 10 }),
        querySelector: () => button,
        setAttribute: (key, value) => attrs.set(key, value), removeAttribute: key => attrs.delete(key),
        addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name),
    };
    const indicator = { parentElement: menu, style: {} };
    const component = Object.assign(areaMenu(), { $el: indicator });
    return { attrs, listeners, button, menu, indicator, component };
}

test('indicator uses actual active bounds and scroll offsets, including RTL coordinates', () => {
    const { component, indicator, menu, button, attrs } = fixture();
    component.menu = menu;
    component.paint(button);
    assert.equal(indicator.style.transform, 'translate(85px, 3px)');
    assert.equal(indicator.style.width, '44px');
    assert.ok(attrs.has('data-area-indicator-ready'));
    menu.scrollLeft = -40;
    component.paint(button);
    assert.equal(indicator.style.transform, 'translate(45px, 3px)');
    button.disabled = true;
    component.paint(button);
    assert.ok(!attrs.has('data-area-indicator-ready'));
});

test('native navigation resumes the active marker from the prior geometry after a body swap', () => {
    const previous = Object.fromEntries(['window', 'document', 'MutationObserver', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame'].map(key => [key, globalThis[key]]));
    const frames = [];
    const target = { addEventListener() {}, removeEventListener() {} };
    Object.assign(globalThis, {
        window: target, document: target,
        MutationObserver: class { observe() {} disconnect() {} },
        ResizeObserver: class { observe() {} disconnect() {} },
        requestAnimationFrame: fn => { frames.push(fn); return frames.length; }, cancelAnimationFrame() {},
    });
    try {
        const before = fixture();
        before.menu.id = 'area-continuity';
        before.menu.getBoundingClientRect = () => ({ left: 10, top: 10, width: 280 });
        before.button.getAttribute = () => 'orders';
        before.button.hasAttribute = () => true;
        before.component.init();
        before.component.paint(before.button);
        before.button.getBoundingClientRect = () => ({ left: 144, top: 14, width: 44, height: 44 });
        before.component.onClick({ button: 0, target: { closest: () => before.button } });
        before.component.destroy();
        const after = fixture();
        after.menu.id = 'area-continuity';
        after.menu.getBoundingClientRect = () => ({ left: 10, top: 10, width: 280 });
        after.button.getAttribute = () => 'orders';
        after.button.getBoundingClientRect = () => ({ left: 144, top: 14, width: 44, height: 44 });
        after.indicator.getBoundingClientRect = () => ({});
        after.component.init();
        assert.equal(after.indicator.style.transform, 'translate(85px, 3px)');
        frames.at(-1)();
        assert.equal(after.indicator.style.transform, 'translate(133px, 3px)');
        after.component.destroy();
    } finally { Object.assign(globalThis, previous); }
});

test('click animation ignores modified/disabled navigation and observers are cleaned up', () => {
    const previous = Object.fromEntries(['window', 'document', 'MutationObserver', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame'].map(key => [key, globalThis[key]]));
    let disconnected = 0;
    const events = new Set();
    const target = { addEventListener: name => events.add(name), removeEventListener: name => events.delete(name) };
    Object.assign(globalThis, {
        window: target, document: target,
        MutationObserver: class { observe() {} disconnect() { disconnected++; } },
        ResizeObserver: class { observe() {} disconnect() { disconnected++; } },
        requestAnimationFrame: () => 1, cancelAnimationFrame: () => {},
    });
    try {
        const { component, button, indicator, attrs } = fixture();
        component.init();
        const click = { target: { closest: () => button }, button: 0 };
        component.onClick({ ...click, ctrlKey: true });
        assert.equal(indicator.style.transform, undefined);
        component.onClick(click);
        assert.equal(indicator.style.transform, 'translate(85px, 3px)');
        component.destroy();
        assert.equal(disconnected, 2);
        assert.equal(events.size, 0);
        assert.ok(!attrs.has('data-area-indicator-ready'));
        component.paint(button);
        assert.ok(!attrs.has('data-area-indicator-ready'));
    } finally { Object.assign(globalThis, previous); }
});
