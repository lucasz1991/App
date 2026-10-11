import test from 'node:test';
import assert from 'node:assert/strict';
import { areaMenu } from '../../resources/js/area-menu.js';
import { parseHTML } from 'linkedom';

const { document: visualDocument } = parseHTML('<html><body><i class="far fa-users"></i><span class="rt-multi-toggle__label">Personal</span></body></html>');
const createElement = name => visualDocument.createElement(name);

function fixture() {
    const attrs = new Map();
    const listeners = new Map();
    const button = { children: [...visualDocument.body.children], disabled: false, hasAttribute: () => false, getBoundingClientRect: () => ({ left: 96, top: 14, width: 44, height: 44 }), getAttribute: () => null };
    const menu = {
        clientLeft: 1, clientTop: 1, scrollLeft: 0, scrollTop: 0,
        getBoundingClientRect: () => ({ left: 10, top: 10 }),
        querySelector: () => button,
        querySelectorAll: () => [button], getAttribute: key => attrs.get(key) ?? null,
        setAttribute: (key, value) => attrs.set(key, value), removeAttribute: key => attrs.delete(key),
        addEventListener: (name, fn) => listeners.set(name, fn), removeEventListener: name => listeners.delete(name),
    };
    const indicator = { parentElement: menu, style: {}, replaceChildren() {} };
    const component = Object.assign(areaMenu(), { $el: indicator, ink: createElement('span') });
    return { attrs, listeners, button, menu, indicator, component };
}

test('indicator uses actual active bounds and scroll offsets, including RTL coordinates', () => {
    const previousDocument = globalThis.document;
    globalThis.document = visualDocument;
    try {
        const { component, indicator, menu, button, attrs } = fixture();
        component.menu = menu;
        component.paint(button);
        assert.equal(indicator.style.transform, 'translate(85px, 3px)');
        assert.equal(indicator.style.width, '44px');
        assert.equal(component.ink.style.transform, 'translate(-85px, -3px)');
        assert.equal(component.ink.children.length, 1);
        assert.equal(component.ink.querySelectorAll('button, a, [x-data]').length, 0);
        assert.ok(attrs.has('data-area-indicator-ready'));
        menu.scrollLeft = -40;
        component.paint(button);
        assert.equal(indicator.style.transform, 'translate(45px, 3px)');
        button.disabled = true;
        component.paint(button);
        assert.ok(!attrs.has('data-area-indicator-ready'));
    } finally { globalThis.document = previousDocument; }
});

test('native navigation resumes the active marker from the prior geometry after a body swap', () => {
    const previous = Object.fromEntries(['window', 'document', 'MutationObserver', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame'].map(key => [key, globalThis[key]]));
    const frames = [];
    const target = { addEventListener() {}, removeEventListener() {} };
    Object.assign(globalThis, {
        window: target, document: { ...target, createElement },
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
        before.indicator.getBoundingClientRect = () => ({ left: 130, top: 14, width: 44, height: 44 });
        before.component.onNavigating();
        before.component.destroy();
        const after = fixture();
        after.menu.id = 'area-continuity';
        after.menu.getBoundingClientRect = () => ({ left: 10, top: 10, width: 280 });
        after.button.getAttribute = () => 'orders';
        after.button.getBoundingClientRect = () => ({ left: 144, top: 14, width: 44, height: 44 });
        after.indicator.getBoundingClientRect = () => ({});
        after.component.init();
        assert.equal(after.indicator.style.transform, 'translate(119px, 3px)');
        assert.equal(after.component.ink.style.transform, 'translate(-119px, -3px)');
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
        window: target, document: { ...target, createElement },
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

test('an unconfirmed target keeps ink and surface together during loading; failure restores server state', () => {
    const previous = Object.fromEntries(['window', 'document', 'MutationObserver', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame'].map(key => [key, globalThis[key]]));
    const frames = [];
    const target = { addEventListener() {}, removeEventListener() {} };
    Object.assign(globalThis, {
        window: target, document: { ...target, createElement },
        MutationObserver: class { observe() {} disconnect() {} }, ResizeObserver: class { observe() {} disconnect() {} },
        requestAnimationFrame: fn => { frames.push(fn); return frames.length; }, cancelAnimationFrame() {},
    });
    try {
        const { component, button, indicator, menu, attrs } = fixture();
        const next = { ...button, disabled: false, getBoundingClientRect: () => ({ left: 144, top: 14, width: 44, height: 44 }) };
        menu.querySelectorAll = () => [button, next];
        component.init();
        component.onClick({ button: 0, target: { closest: () => next } });
        next.disabled = true;
        attrs.set('aria-busy', 'true');
        component.onRestore(); frames.at(-1)();
        assert.equal(indicator.style.transform, 'translate(133px, 3px)');
        assert.equal(component.ink.style.transform, 'translate(-133px, -3px)');
        attrs.set('aria-busy', 'false');
        component.onRestore(); frames.at(-1)();
        assert.equal(indicator.style.transform, 'translate(85px, 3px)');
        component.destroy();
    } finally { Object.assign(globalThis, previous); }
});
