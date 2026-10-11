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

function paginationFixture(run) {
    const previous = Object.fromEntries(['window', 'document', 'MutationObserver', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame', 'setTimeout', 'clearTimeout'].map(key => [key, globalThis[key]]));
    const { document } = parseHTML(`<html><body><div data-pagination-controls>
        <span data-indicator></span>
        <button data-table-page-indicator-option aria-current="page" disabled><span data-table-page-label>1</span></button>
        <button data-table-page-indicator-option><span data-table-page-label>2</span></button>
        <button data-table-page-indicator-option><span data-table-page-label>3</span></button>
        <span data-table-page-indicator-option aria-current="page" aria-hidden="true"><span data-table-page-label>1 / 3</span></span>
    </div></body></html>`);
    const frames = new Map();
    const timers = new Map();
    const events = new Map();
    const observers = [];
    const resizeObservers = [];
    let sequence = 0;
    const menu = document.querySelector('[data-pagination-controls]');
    const indicator = menu.querySelector('[data-indicator]');
    const buttons = [...menu.querySelectorAll('button')];
    const fraction = menu.querySelector('span[data-table-page-indicator-option]');
    const bounds = new Map(buttons.map((button, index) => [button, { left: 144 + index * 44, top: 610, width: 44, height: 44 }]));
    bounds.set(fraction, { left: 144, top: 610, width: 0, height: 0 });
    menu.getBoundingClientRect = () => ({ left: 100, top: 610, width: 220, height: 44 });
    Object.assign(menu, { clientLeft: 0, clientTop: 0, scrollLeft: 0, scrollTop: 0 });
    [...buttons, fraction].forEach(button => { button.getBoundingClientRect = () => bounds.get(button); });
    Object.assign(globalThis, {
        document,
        window: { addEventListener: (name, fn) => events.set(name, fn), removeEventListener: name => events.delete(name) },
        MutationObserver: class {
            constructor(callback) { this.callback = callback; this.disconnected = false; observers.push(this); }
            observe(target, options) { this.target = target; this.options = options; }
            disconnect() { this.disconnected = true; }
        },
        ResizeObserver: class {
            constructor(callback) { this.callback = callback; this.disconnected = false; this.targets = []; resizeObservers.push(this); }
            observe(target) { this.targets.push(target); }
            disconnect() { this.disconnected = true; }
        },
        requestAnimationFrame: callback => { frames.set(++sequence, callback); return sequence; },
        cancelAnimationFrame: id => frames.delete(id),
        setTimeout: callback => { timers.set(++sequence, callback); return sequence; },
        clearTimeout: id => timers.delete(id),
    });
    const component = Object.assign(areaMenu({
        optionSelector: '[data-table-page-indicator-option]',
        visualSelector: '[data-table-page-label]',
        allowDisabledActive: true,
    }), { $el: indicator });
    const flushFrame = () => {
        const callbacks = [...frames.values()];
        frames.clear();
        callbacks.forEach(callback => callback());
    };
    const changePage = page => {
        buttons.forEach((button, index) => {
            if (index + 1 === page) { button.setAttribute('aria-current', 'page'); button.disabled = true; }
            else { button.removeAttribute('aria-current'); button.disabled = false; }
        });
        fraction.firstElementChild.textContent = `${page} / 3`;
    };
    try {
        component.init();
        flushFrame();
        run({ component, menu, indicator, buttons, fraction, bounds, frames, timers, events, observers, resizeObservers, flushFrame, changePage });
    } finally {
        component.destroy();
        Object.assign(globalThis, previous);
    }
}

test('configured pagination reuses clipped ink for plain numeric labels and confirmed disabled current', () => {
    paginationFixture(({ component, indicator, buttons, menu }) => {
        assert.equal(component.activeButton(), buttons[0]);
        assert.equal(indicator.style.transform, 'translate(44px, 0px)');
        assert.equal(indicator.style.width, '44px');
        assert.equal(component.ink.style.transform, 'translate(-44px, 0px)');
        assert.equal(component.ink.children.length, 3);
        assert.deepEqual([...component.ink.children].map(item => item.textContent), ['1', '2', '3']);
        assert.equal(component.ink.querySelectorAll('button, a, [x-data], [wire\\:click]').length, 0);
        assert.equal(component.ink.getAttribute('aria-hidden'), 'true');
        assert.ok(menu.hasAttribute('data-area-indicator-ready'));
        assert.equal(menu.hasAttribute('data-area-menu'), false);
    });
});

test('pagination does not paint an unrelated disabled option even when loading is busy', () => {
    paginationFixture(({ component, menu, buttons }) => {
        buttons[1].disabled = true;
        menu.setAttribute('aria-busy', 'true');
        component.paint(buttons[1]);
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), false);
        component.paint(buttons[0]);
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), true);
        buttons[2].setAttribute('aria-disabled', 'true');
        component.paint(buttons[2]);
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), false);
    });
});

test('responsive pagination chooses the visible mobile fraction, excluding hidden numeric ink', () => {
    paginationFixture(({ component, menu, buttons, fraction, bounds, indicator, flushFrame }) => {
        buttons.forEach(button => bounds.set(button, { left: 0, top: 0, width: 0, height: 0 }));
        bounds.set(fraction, { left: 150, top: 610, width: 52, height: 44 });
        component.onRestore(); flushFrame();
        assert.equal(component.activeButton(), fraction);
        assert.equal(indicator.style.transform, 'translate(50px, 0px)');
        assert.equal(indicator.style.width, '52px');
        assert.equal(component.ink.children.length, 1);
        assert.equal(component.ink.firstElementChild.textContent, '1 / 3');
        bounds.set(fraction, { left: 0, top: 0, width: 0, height: 0 });
        component.onRestore(); flushFrame();
        assert.equal(component.activeButton(), null);
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), false);
        bounds.set(buttons[0], { left: 144, top: 610, width: 44, height: 44 });
        component.onRestore(); flushFrame();
        assert.equal(component.activeButton(), buttons[0]);
        assert.equal(indicator.style.transform, 'translate(44px, 0px)');
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), true);
    });
});

test('arrow or edge-scroll server mutations move the same marker without owning page state', () => {
    paginationFixture(({ component, changePage, buttons, observers, indicator, flushFrame }) => {
        changePage(3);
        observers[0].callback(); flushFrame();
        assert.equal(component.activeButton(), buttons[2]);
        assert.equal(component.pendingButton, null);
        assert.equal(indicator.style.transform, 'translate(132px, 0px)');
        assert.equal(component.ink.style.transform, 'translate(-132px, 0px)');
        changePage(2);
        observers[0].callback(); flushFrame();
        assert.equal(indicator.style.transform, 'translate(88px, 0px)');
    });
});

test('only the own pending click retains a disabled loading target and failure restores confirmed current', () => {
    paginationFixture(({ component, menu, buttons, indicator, flushFrame }) => {
        component.onClick({ button: 0, target: buttons[1].firstElementChild });
        assert.equal(component.pendingButton, buttons[1]);
        assert.equal(indicator.style.transform, 'translate(88px, 0px)');
        buttons[1].disabled = true;
        menu.setAttribute('aria-busy', 'true');
        component.onRestore(); flushFrame();
        assert.equal(indicator.style.transform, 'translate(88px, 0px)');
        menu.removeAttribute('aria-busy');
        component.onRestore(); flushFrame();
        assert.equal(component.pendingButton, null);
        assert.equal(indicator.style.transform, 'translate(44px, 0px)');
        assert.equal(component.ink.style.transform, 'translate(-44px, 0px)');
    });
});

test('confirmed numeric click converges to server current without replay or persistent destination', () => {
    paginationFixture(({ component, menu, buttons, changePage, indicator, flushFrame }) => {
        component.onClick({ button: 0, target: buttons[2].firstElementChild });
        menu.setAttribute('aria-busy', 'true');
        component.onRestore(); flushFrame();
        changePage(3);
        component.onRestore(); flushFrame();
        assert.equal(component.pendingButton, null);
        assert.equal(indicator.style.transform, 'translate(132px, 0px)');
        menu.removeAttribute('aria-busy');
        component.onRestore(); flushFrame();
        assert.equal(indicator.style.transform, 'translate(132px, 0px)');
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), true);
    });
});

test('cancelled click before loading returns to confirmed page; modified and disabled clicks stay native', () => {
    paginationFixture(({ component, buttons, indicator, timers, flushFrame }) => {
        component.onClick({ button: 0, ctrlKey: true, target: buttons[1].firstElementChild });
        assert.equal(component.pendingButton, null);
        component.onClick({ button: 0, target: buttons[0].firstElementChild });
        assert.equal(component.pendingButton, null);
        component.onClick({ button: 0, target: buttons[1].firstElementChild });
        assert.equal(indicator.style.transform, 'translate(88px, 0px)');
        const callback = [...timers.values()][0];
        callback(); flushFrame();
        assert.equal(component.pendingButton, null);
        assert.equal(indicator.style.transform, 'translate(44px, 0px)');
    });
});

test('responsive resize cancels a now-hidden numeric intent and follows the visible confirmed fraction', () => {
    paginationFixture(({ component, buttons, fraction, bounds, indicator, flushFrame }) => {
        component.onClick({ button: 0, target: buttons[1].firstElementChild });
        buttons.forEach(button => bounds.set(button, { left: 0, top: 0, width: 0, height: 0 }));
        bounds.set(fraction, { left: 150, top: 610, width: 52, height: 44 });
        component.onRestore(); flushFrame();
        assert.equal(component.pendingButton, null);
        assert.equal(indicator.style.transform, 'translate(50px, 0px)');
        assert.equal(component.ink.textContent, '1 / 3');
    });
});

test('same label signature does not replace decorative children or observe its own marker styles', () => {
    paginationFixture(({ component, observers, buttons }) => {
        let rebuilt = 0;
        const original = component.ink.replaceChildren.bind(component.ink);
        component.ink.replaceChildren = (...children) => { rebuilt++; original(...children); };
        component.paint(buttons[0]);
        component.paint(buttons[1]);
        assert.equal(rebuilt, 0);
        assert.equal(observers[0].options.childList, true);
        assert.equal(observers[0].options.attributeFilter.includes('style'), false);
        assert.equal(observers[0].options.attributeFilter.includes('data-area-indicator-ready'), false);
    });
});

test('pagination teardown releases observers, frames, timers, pending target and window listeners', () => {
    paginationFixture(({ component, buttons, menu, frames, timers, events, observers, resizeObservers }) => {
        component.onClick({ button: 0, target: buttons[1].firstElementChild });
        component.schedule();
        assert.equal(timers.size, 1);
        assert.equal(frames.size, 1);
        component.destroy();
        assert.equal(timers.size, 0);
        assert.equal(frames.size, 0);
        assert.equal(events.size, 0);
        assert.equal(component.pendingButton, null);
        assert.equal(menu.hasAttribute('data-area-indicator-ready'), false);
        assert.ok(observers.every(observer => observer.disconnected));
        assert.ok(resizeObservers.every(observer => observer.disconnected));
        component.onRestore();
        assert.equal(frames.size, 0);
    });
});
