import test from 'node:test';
import assert from 'node:assert/strict';
import { parseHTML } from 'linkedom';
import { profileTabMotion } from '../../resources/js/profile-tab-motion.js';

function fixture(run, { variant = 'sub', reduceMotion = false, noGsap = false, legacyMedia = false, noResizeObserver = false } = {}) {
    const keys = ['window', 'document', 'MutationObserver', 'ResizeObserver', 'requestAnimationFrame', 'cancelAnimationFrame'];
    const previous = Object.fromEntries(keys.map(key => [key, globalThis[key]]));
    const { document, window: domWindow } = parseHTML('<html><body><nav><span data-profile-marker></span></nav></body></html>');
    const menu = document.querySelector('nav');
    const marker = menu.firstElementChild;
    const optionAttribute = variant === 'main' ? 'data-profile-group' : 'data-panel-tab';
    const selectedAttribute = variant === 'main' ? 'aria-current' : 'aria-selected';
    const selectedValue = variant === 'main' ? 'page' : 'true';
    const bounds = new Map();
    const frames = new Map();
    const observers = [];
    const resizeObservers = [];
    const events = new Map();
    const menuEvents = new Map();
    const mediaEvents = new Map();
    const tweens = [];
    let sequence = 0;

    menu.getBoundingClientRect = () => ({ left: 100, top: 200, width: 420, height: 52 });
    Object.assign(menu, { clientLeft: 2, clientTop: 1, scrollLeft: 0, scrollTop: 0 });
    const nativeAdd = menu.addEventListener.bind(menu);
    const nativeRemove = menu.removeEventListener.bind(menu);
    menu.addEventListener = (name, callback, options) => { menuEvents.set(name, callback); nativeAdd(name, callback, options); };
    menu.removeEventListener = (name, callback, options) => { menuEvents.delete(name); nativeRemove(name, callback, options); };

    function makeButton(key, label, index, width = [108, 148, 116][index] ?? 108) {
        const button = document.createElement('button');
        button.setAttribute(optionAttribute, key);
        button.setAttribute(selectedAttribute, index === 0 ? selectedValue : 'false');
        button.setAttribute('wire:click', `setProfileTab('${key}')`);
        button.setAttribute('tabindex', index === 0 ? '0' : '-1');
        button.innerHTML = `<i class="far fa-id-card" aria-hidden="true"></i><span>${label}</span>`;
        bounds.set(button, { left: [112, 220, 368][index] ?? 112, top: 204, width, height: 44 });
        button.getBoundingClientRect = () => bounds.get(button);
        return button;
    }
    const buttons = ['Profil', 'Betrieb', 'Personalakte'].map((key, index) => makeButton(key, key, index));
    menu.append(...buttons);

    const media = { matches: reduceMotion };
    const mediaAdd = (name, callback) => mediaEvents.set(name, callback);
    const mediaRemove = name => mediaEvents.delete(name);
    if (legacyMedia) {
        media.addListener = callback => mediaAdd('change', callback);
        media.removeListener = () => mediaRemove('change');
    } else {
        media.addEventListener = mediaAdd;
        media.removeEventListener = mediaRemove;
    }
    const gsap = {
        to(target, vars) {
            const from = Object.fromEntries(['x', 'y', 'width', 'height'].map(key => [key, target[key]]));
            const tween = {
                target, vars, from, killed: false,
                kill() { this.killed = true; },
                advance(progress) {
                    if (this.killed) return;
                    Object.keys(from).forEach(key => { target[key] = from[key] + (vars[key] - from[key]) * progress; });
                    vars.onUpdate?.();
                    if (progress === 1) vars.onComplete?.();
                },
            };
            tweens.push(tween);
            return tween;
        },
    };
    Object.assign(globalThis, {
        document,
        window: {
            gsap: noGsap ? undefined : gsap,
            matchMedia: () => media,
            getComputedStyle: element => element.tagName === 'I' ? {
                fontFamily: 'Font Awesome', fontSize: '12px', fontWeight: '400', fontStyle: 'normal',
                lineHeight: '12px', letterSpacing: 'normal', direction: 'ltr',
            } : {
                fontFamily: 'Plus Jakarta Sans', fontSize: '12px', fontWeight: '600', fontStyle: 'normal',
                lineHeight: '18px', letterSpacing: '-0.1px', direction: 'ltr', textAlign: 'center',
                columnGap: '6px', rowGap: '6px', paddingTop: '8px', paddingRight: '12px', paddingBottom: '8px', paddingLeft: '12px',
            },
            addEventListener: (name, callback) => events.set(name, callback),
            removeEventListener: name => events.delete(name),
        },
        MutationObserver: class {
            constructor(callback) { this.callback = callback; this.disconnected = false; observers.push(this); }
            observe(target, options) { this.target = target; this.options = options; }
            disconnect() { this.disconnected = true; }
        },
        ResizeObserver: noResizeObserver ? undefined : class {
            constructor(callback) { this.callback = callback; this.disconnected = false; this.targets = []; resizeObservers.push(this); }
            observe(target) { this.targets.push(target); }
            disconnect() { this.disconnected = true; this.targets = []; }
        },
        requestAnimationFrame: callback => { frames.set(++sequence, callback); return sequence; },
        cancelAnimationFrame: id => frames.delete(id),
    });
    const component = Object.assign(profileTabMotion({ variant }), { $el: marker });
    function flush() {
        const callbacks = [...frames.values()];
        frames.clear();
        callbacks.forEach(callback => callback());
    }
    function mutate(target = buttons[0], attributeName = selectedAttribute, type = 'attributes') {
        observers.at(-1)?.callback([{ target, attributeName, type }]);
    }
    function select(index, options = buttons) {
        options.forEach((button, position) => button.setAttribute(selectedAttribute, position === index ? selectedValue : 'false'));
        mutate(options[index]);
        flush();
    }
    try {
        component.init();
        flush();
        run({ component, document, domWindow, menu, marker, buttons, bounds, frames, observers, resizeObservers, events,
            menuEvents, mediaEvents, media, tweens, flush, mutate, select, makeButton, selectedAttribute, selectedValue });
    } finally {
        component.destroy();
        Object.assign(globalThis, previous);
    }
}

function transforms(marker) {
    const outer = [...marker.style.transform.matchAll(/-?[\d.]+/g)].map(match => Number(match[0]));
    const inner = [...marker.firstElementChild.style.transform.matchAll(/-?[\d.]+/g)].map(match => Number(match[0]));
    return { outer, inner };
}

test('main selection initializes an inset underline and never clones controls or counts', () => {
    fixture(({ menu, marker, buttons, tweens }) => {
        assert.equal(marker.style.width, '84px');
        assert.equal(marker.style.height, '2px');
        assert.equal(marker.style.transform, 'translate(22px, 45px) scale(1, 1)');
        assert.equal(marker.children.length, 0);
        assert.equal(tweens.length, 0);
        assert.ok(menu.hasAttribute('data-profile-motion-ready'));
        assert.equal(buttons[0].getAttribute('aria-current'), 'page');
        assert.equal(buttons[1].getAttribute('aria-current'), 'false');
    }, { variant: 'main' });
});

test('sub selection starts as one real rounded-button footprint with stationary inert ink', () => {
    fixture(({ menu, marker, buttons }) => {
        assert.equal(marker.style.width, '108px');
        assert.equal(marker.style.height, '44px');
        assert.equal(marker.style.transform, 'translate(10px, 3px) scale(1, 1)');
        const ink = marker.firstElementChild;
        assert.equal(ink.className, 'employee-profile__selection-ink');
        assert.equal(ink.getAttribute('aria-hidden'), 'true');
        assert.equal(ink.style.transform, 'scale(1, 1) translate(-10px, -3px)');
        assert.deepEqual([...ink.children].map(item => item.textContent), ['Profil', 'Betrieb', 'Personalakte']);
        assert.equal(ink.children[1].style.left, '118px');
        assert.equal(ink.children[1].style.width, '148px');
        assert.equal(ink.querySelectorAll('button, a, [tabindex], [wire\\:click], [x-data]').length, 0);
        assert.ok(menu.hasAttribute('data-profile-motion-ready'));
        assert.equal(buttons[0].getAttribute('aria-selected'), 'true');
    });
});

test('inert white label and icon metrics match computed native controls rather than nav inheritance', () => {
    fixture(({ marker }) => {
        const item = marker.firstElementChild.firstElementChild;
        assert.equal(item.style.fontFamily, 'Plus Jakarta Sans');
        assert.equal(item.style.fontSize, '12px');
        assert.equal(item.style.fontWeight, '600');
        assert.equal(item.style.lineHeight, '18px');
        assert.equal(item.style.letterSpacing, '-0.1px');
        assert.equal(item.style.direction, 'ltr');
        assert.equal(item.style.columnGap, '6px');
        assert.equal(item.style.paddingLeft, '12px');
        assert.equal(item.firstElementChild.style.fontFamily, 'Font Awesome');
        assert.equal(item.firstElementChild.style.fontSize, '12px');
        assert.equal(item.firstElementChild.style.fontWeight, '400');
        assert.equal(item.style.color, undefined);
        assert.equal(item.firstElementChild.style.color, undefined);
    });
});

test('only confirmed selection creates a 240ms transform animation with existing GSAP', () => {
    fixture(({ marker, select, tweens, buttons }) => {
        select(1);
        assert.equal(tweens.length, 1);
        const tween = tweens[0];
        assert.equal(tween.vars.duration, 0.24);
        assert.equal(tween.vars.ease, 'power3.out');
        assert.equal(tween.vars.overwrite, true);
        assert.deepEqual(tween.from, { x: 10, y: 3, width: 108, height: 44 });
        assert.equal(marker.style.width, '148px');
        assert.equal(marker.style.height, '44px');
        assert.equal(buttons[1].getAttribute('aria-selected'), 'true');
        assert.equal(buttons[1].getAttribute('tabindex'), '-1');
        tween.advance(1);
        assert.equal(marker.style.transform, 'translate(118px, 3px) scale(1, 1)');
        assert.equal(marker.firstElementChild.style.transform, 'scale(1, 1) translate(-118px, -3px)');
    });
});

test('white ink is inverse scaled and stationary at every sampled unequal-width animation frame', () => {
    fixture(({ marker, select, tweens }) => {
        select(1);
        const base = { width: marker.style.width, height: marker.style.height };
        for (const fraction of [0, 0.1, 0.25, 0.5, 0.75, 1]) {
            tweens[0].advance(fraction);
            const { outer, inner } = transforms(marker);
            assert.ok(Math.abs(outer[2] * inner[0] - 1) < 1e-9);
            assert.ok(Math.abs(outer[3] * inner[1] - 1) < 1e-9);
            assert.ok(Math.abs(outer[0] + outer[2] * inner[0] * inner[2]) < 1e-9);
            assert.ok(Math.abs(outer[1] + outer[3] * inner[1] * inner[3]) < 1e-9);
            assert.equal(marker.style.width, base.width);
            assert.equal(marker.style.height, base.height);
        }
    });
});

test('click, focus and keyboard input do not speculate selection or add navigation handlers', () => {
    fixture(({ marker, buttons, domWindow, menuEvents, tweens, flush }) => {
        const before = marker.style.transform;
        buttons[1].dispatchEvent(new domWindow.Event('click', { bubbles: true }));
        buttons[1].dispatchEvent(new domWindow.Event('focus', { bubbles: true }));
        buttons[1].dispatchEvent(new domWindow.Event('keydown', { bubbles: true }));
        flush();
        assert.equal(marker.style.transform, before);
        assert.equal(tweens.length, 0);
        assert.deepEqual([...menuEvents.keys()], ['scroll']);
        assert.equal(buttons[1].getAttribute('aria-selected'), 'false');
    });
});

test('loading and failed native selection keep the confirmed tab, including a disabled current', () => {
    fixture(({ marker, buttons, mutate, flush, tweens }) => {
        buttons[1].disabled = true;
        buttons[0].disabled = true;
        mutate(buttons[1], 'disabled');
        flush();
        assert.equal(marker.style.transform, 'translate(10px, 3px) scale(1, 1)');
        assert.equal(tweens.length, 0);
        buttons[1].disabled = false;
        mutate(buttons[1], 'disabled');
        flush();
        assert.equal(marker.style.transform, 'translate(10px, 3px) scale(1, 1)');
        assert.equal(buttons[0].getAttribute('aria-selected'), 'true');
    });
});

test('main underline follows confirmed native group changes without writing semantic attributes', () => {
    fixture(({ select, tweens, marker, buttons }) => {
        select(2);
        assert.equal(tweens.length, 1);
        tweens[0].advance(1);
        assert.equal(marker.style.width, '92px');
        assert.equal(marker.style.height, '2px');
        assert.equal(marker.style.transform, 'translate(278px, 45px) scale(1, 1)');
        assert.equal(buttons[2].getAttribute('aria-current'), 'page');
        assert.equal(buttons[2].getAttribute('aria-selected'), null);
        assert.equal(buttons[2].getAttribute('wire:click'), "setProfileTab('Personalakte')");
    }, { variant: 'main' });
});

test('replacing grouped subtab roster snaps directly and never slides through an unrelated label', () => {
    fixture(({ menu, marker, buttons, makeButton, mutate, flush, tweens, select }) => {
        select(1);
        tweens[0].advance(0.5);
        const replacement = ['operationsOverview', 'shifts', 'locations'].map((key, index) => makeButton(key, key, index));
        menu.replaceChildren(marker, ...replacement);
        mutate(menu, undefined, 'childList');
        flush();
        assert.equal(tweens.length, 1);
        assert.equal(tweens[0].killed, true);
        assert.equal(marker.style.transform, 'translate(10px, 3px) scale(1, 1)');
        assert.equal(marker.firstElementChild.textContent, 'operationsOverviewshiftslocations');
        assert.equal(buttons[0].isConnected, false);
    });
});

test('morphed label visuals refresh without replaying an unchanged confirmed selection', () => {
    fixture(({ marker, buttons, mutate, flush, tweens }) => {
        const previousItem = marker.firstElementChild.firstElementChild;
        buttons[0].lastElementChild.textContent = 'Profilübersicht';
        mutate(buttons[0].lastElementChild, undefined, 'characterData');
        flush();
        assert.notEqual(marker.firstElementChild.firstElementChild, previousItem);
        assert.equal(marker.firstElementChild.firstElementChild.textContent, 'Profilübersicht');
        assert.equal(tweens.length, 0);
    });
});

test('visual clones strip nested controls, directives, event handlers and duplicate IDs', () => {
    fixture(({ marker, buttons, mutate, flush }) => {
        buttons[0].lastElementChild.innerHTML = '<span id="private-label" x-data="secret" wire:click="unsafe" onclick="unsafe()" tabindex="0">Profil<button>Action</button><a href="/unsafe">Link</a></span>';
        const hidden = document.createElement('span');
        hidden.className = 'sr-only';
        hidden.textContent = 'Only native screen-reader hint';
        buttons[0].append(hidden);
        mutate(buttons[0], undefined, 'childList');
        flush();
        const ink = marker.firstElementChild;
        assert.equal(ink.querySelectorAll('button, a, [id], [x-data], [wire\\:click], [onclick], [tabindex], .sr-only').length, 0);
        assert.equal(ink.firstElementChild.textContent, 'Profil');
        assert.ok(buttons[0].querySelector('[x-data]'));
        assert.ok(buttons[0].querySelector('button'));
        assert.equal(buttons[0].lastElementChild.textContent, 'Only native screen-reader hint');
    });
});

test('hidden current restores the native fallback and visible recovery initializes instantly', () => {
    fixture(({ menu, marker, buttons, bounds, mutate, flush, tweens }) => {
        const previousBounds = bounds.get(buttons[0]);
        bounds.set(buttons[0], { ...previousBounds, width: 0, height: 0 });
        mutate(buttons[0], 'hidden');
        flush();
        assert.equal(menu.hasAttribute('data-profile-motion-ready'), false);
        assert.equal(marker.style.opacity, '0');
        bounds.set(buttons[0], previousBounds);
        mutate(buttons[0], 'hidden');
        flush();
        assert.equal(menu.hasAttribute('data-profile-motion-ready'), true);
        assert.equal(marker.style.opacity, '1');
        assert.equal(tweens.length, 0);
    });
});

test('horizontal scroll corrects menu coordinates immediately without replaying selection', () => {
    fixture(({ menu, marker, buttons, bounds, menuEvents, flush, select, tweens }) => {
        select(1);
        tweens[0].advance(0.25);
        menu.scrollLeft = 60;
        buttons.forEach(button => bounds.set(button, { ...bounds.get(button), left: bounds.get(button).left - 60 }));
        menuEvents.get('scroll')();
        flush();
        assert.equal(tweens[0].killed, true);
        assert.equal(tweens.length, 1);
        assert.equal(marker.style.transform, 'translate(118px, 3px) scale(1, 1)');
        assert.equal(marker.firstElementChild.children[1].style.left, '118px');
    });
});

test('responsive button resizing resets exact base dimensions and original ink alignment', () => {
    fixture(({ marker, buttons, bounds, resizeObservers, flush, tweens }) => {
        bounds.set(buttons[0], { left: 116, top: 208, width: 164, height: 48 });
        resizeObservers[0].callback();
        flush();
        assert.equal(marker.style.width, '164px');
        assert.equal(marker.style.height, '48px');
        assert.equal(marker.style.transform, 'translate(14px, 7px) scale(1, 1)');
        assert.equal(marker.firstElementChild.firstElementChild.style.left, '14px');
        assert.equal(tweens.length, 0);
    });
});

test('initial reduced motion follows confirmed selections instantly', () => {
    fixture(({ marker, select, tweens }) => {
        select(2);
        assert.equal(tweens.length, 0);
        assert.equal(marker.style.transform, 'translate(266px, 3px) scale(1, 1)');
        assert.equal(marker.firstElementChild.style.transform, 'scale(1, 1) translate(-266px, -3px)');
    }, { reduceMotion: true });
});

test('dynamic reduced motion cancels a running tween and restores exact confirmed geometry', () => {
    fixture(({ marker, select, tweens, media, mediaEvents, flush }) => {
        select(1);
        tweens[0].advance(0.3);
        media.matches = true;
        mediaEvents.get('change')();
        flush();
        assert.equal(tweens[0].killed, true);
        assert.equal(marker.style.transform, 'translate(118px, 3px) scale(1, 1)');
        media.matches = false;
        mediaEvents.get('change')();
        flush();
        select(2);
        assert.equal(tweens.length, 2);
    });
});

test('generated ink and ready commits do not cause observer loops; native morph restores readiness', () => {
    fixture(({ marker, menu, observers, frames, flush, tweens }) => {
        observers[0].callback([{ target: marker.firstElementChild, type: 'childList' }]);
        observers[0].callback([{ target: menu, type: 'attributes', attributeName: 'data-profile-motion-ready' }]);
        assert.equal(frames.size, 0);
        menu.removeAttribute('data-profile-motion-ready');
        observers[0].callback([{ target: menu, type: 'attributes', attributeName: 'data-profile-motion-ready' }]);
        assert.equal(frames.size, 1);
        flush();
        assert.equal(menu.hasAttribute('data-profile-motion-ready'), true);
        assert.equal(tweens.length, 0);
        assert.equal(observers[0].options.attributeFilter.includes('style'), false);
    });
});

test('rapid confirmed reversal starts at the actual visible frame and rejects stale callbacks', () => {
    fixture(({ marker, select, tweens }) => {
        select(1);
        const first = tweens[0];
        first.advance(0.5);
        const midpoint = { x: 64, y: 3, width: 128, height: 44 };
        select(2);
        assert.equal(first.killed, true);
        assert.deepEqual(tweens[1].from, midpoint);
        const visible = marker.style.transform;
        first.vars.onUpdate();
        first.vars.onComplete();
        assert.equal(marker.style.transform, visible);
        tweens[1].advance(1);
        assert.equal(marker.style.transform, 'translate(266px, 3px) scale(1, 1)');
    });
});

test('teardown removes owned listeners, observers, queued frames, ink and running tween only', () => {
    fixture(({ component, menu, marker, select, tweens, events, menuEvents, mediaEvents, observers, resizeObservers, frames, mutate }) => {
        select(1);
        mutate();
        assert.equal(frames.size, 1);
        component.destroy();
        component.destroy();
        assert.equal(tweens[0].killed, true);
        assert.equal(frames.size, 0);
        assert.equal(events.size, 0);
        assert.equal(menuEvents.size, 0);
        assert.equal(mediaEvents.size, 0);
        assert.ok(observers.every(observer => observer.disconnected));
        assert.ok(resizeObservers.every(observer => observer.disconnected));
        assert.equal(menu.hasAttribute('data-profile-motion-ready'), false);
        assert.equal(marker.children.length, 0);
        assert.equal(marker.style.transform, undefined);
        assert.equal(marker.style.width, undefined);
        assert.equal(menu.querySelector('[aria-selected="true"]').getAttribute('data-panel-tab'), 'Betrieb');
        observers[0].callback([{ target: menu, type: 'childList' }]);
        assert.equal(frames.size, 0);
    });
});

test('reinitializing one marker destroys its previous runtime before subscribing again', () => {
    fixture(({ component, marker, menu, observers, resizeObservers, flush, events }) => {
        component.init();
        assert.equal(observers[0].disconnected, true);
        assert.equal(resizeObservers[0].disconnected, true);
        flush();
        assert.equal(observers.length, 2);
        assert.equal(marker.children.length, 1);
        assert.equal(events.size, 1);
        assert.equal(menu.hasAttribute('data-profile-motion-ready'), true);
    });
});

test('unavailable GSAP preserves native fallback without observers or decorative ownership', () => {
    fixture(({ menu, marker, buttons, observers, resizeObservers, events, menuEvents, frames }) => {
        assert.equal(menu.hasAttribute('data-profile-motion-ready'), false);
        assert.equal(marker.children.length, 0);
        assert.equal(buttons[0].getAttribute('aria-selected'), 'true');
        assert.equal(observers.length, 0);
        assert.equal(resizeObservers.length, 0);
        assert.equal(events.size, 0);
        assert.equal(menuEvents.size, 0);
        assert.equal(frames.size, 0);
    }, { noGsap: true });
});

test('legacy media listeners and window resize fallback remain lifecycle scoped', () => {
    fixture(({ component, marker, buttons, bounds, events, mediaEvents, flush }) => {
        bounds.set(buttons[0], { ...bounds.get(buttons[0]), width: 128 });
        events.get('resize')();
        flush();
        assert.equal(marker.style.width, '128px');
        assert.equal(mediaEvents.size, 1);
        component.destroy();
        assert.equal(mediaEvents.size, 0);
        assert.equal(events.size, 0);
    }, { legacyMedia: true, noResizeObserver: true });
});
