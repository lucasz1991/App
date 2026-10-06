import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { parseHTML } from 'linkedom';

const blade = await readFile(new URL('../../resources/views/components/ui/dropdown/anchor-dropdown.blade.php', import.meta.url), 'utf8');
const dataExpression = blade.match(/x-data="([\s\S]*?)"\n  x-cloak/)?.[1];
assert.ok(dataExpression, 'Global dropdown Alpine object must be readable for interaction checks.');
const detailBlade = await readFile(new URL('../../resources/views/livewire/operations/partials/timeline-event-detail.blade.php', import.meta.url), 'utf8');
const detailDataExpression = detailBlade.match(/x-data="([\s\S]*?)"\s+x-init=/)?.[1];
assert.ok(detailDataExpression, 'Timeline detail pager must expose its actual Alpine methods.');

// Exercise the actual inline component methods after replacing only the
// server-rendered scalar props. No duplicate implementation of hover logic.
function renderProps(source, options) {
    let output = '';
    let cursor = 0;
    while (true) {
        const start = source.indexOf('@js(', cursor);
        if (start < 0) return output + source.slice(cursor);
        output += source.slice(cursor, start);
        let end = start + 4;
        let depth = 1;
        let quote = null;
        for (; end < source.length; end++) {
            const char = source[end];
            if (quote) {
                if (char === quote && source[end - 1] !== '\\') quote = null;
                continue;
            }
            if (char === "'" || char === '"') quote = char;
            else if (char === '(') depth++;
            else if (char === ')' && --depth === 0) break;
        }
        const expression = source.slice(start + 4, end);
        let value;
        if (expression.includes('$openOnHover')) value = options.openOnHover;
        else if (expression.includes('$hoverOpenDelay')) value = 100;
        else if (expression.includes('$hoverCloseDelay')) value = 180;
        else if (expression.includes('$dropdownPanelId')) value = 'test-content';
        else if (expression.includes('$resolvedDropdownId')) value = 'test';
        else if (expression.includes('$resolvedLayerGroup')) value = '';
        else if (expression.includes('$anchorSelector')) value = null;
        else if (expression.includes('str_ends_with')) value = 'left';
        else if (expression.includes('str_starts_with')) value = 'bottom';
        else if (expression.includes('$anchorOffset')) value = 8;
        else if (expression.includes('$maxHeight')) value = options.maxHeight ?? 448;
        else if (expression.includes('$fixedHeight')) value = options.fixedHeight ?? false;
        else if (expression.includes('$contentRole')) value = 'dialog';
        else if (expression.includes('$headerOffset')) value = 0;
        else if (expression.includes('$matchesTriggerWidth') || expression.includes('$scrollOn')) value = false;
        else throw new Error(`Unexpected server scalar: ${expression}`);
        output += JSON.stringify(value);
        cursor = end + 1;
    }
}

function fixture(options = { openOnHover: true }) {
    const { document, Element, Node } = parseHTML('<html><body><div id="root"><div id="trigger"><button>Shift</button></div></div><div id="panel"><div id="content"><a href="/shift">Details</a></div></div><button id="outside">Outside</button></body></html>').window;
    let now = 0;
    let nextTimer = 0;
    const timers = new Map();
    const window = {
        setTimeout(callback, delay) {
            const id = ++nextTimer;
            timers.set(id, { callback, at: now + delay });
            return id;
        },
        clearTimeout(id) { timers.delete(id); },
    };
    const dropdown = new Function('window', 'document', 'Element', 'Node', 'CustomEvent', `return (${renderProps(dataExpression, options)});`)(window, document, Element, Node, class {});
    const refs = {
        trigger: document.getElementById('trigger'),
        panel: document.getElementById('panel'),
        panelScroll: document.getElementById('content'),
    };
    const dispatched = [];
    let activeElement = null;
    let focusCalls = 0;
    Object.defineProperty(document, 'activeElement', { get: () => activeElement });
    refs.trigger.querySelector('button').focus = () => { activeElement = refs.trigger.querySelector('button'); focusCalls++; };
    refs.panel.querySelector('a').focus = () => { activeElement = refs.panel.querySelector('a'); focusCalls++; };
    Object.assign(dropdown, {
        $refs: refs,
        $root: document.getElementById('root'),
        $nextTick: (callback) => callback(),
        $dispatch: (name) => dispatched.push(name),
        stopPositionTracking() {},
        clearExternalAnchorAccessibility() {},
    });

    return {
        dropdown, refs, document, window, dispatched,
        pending: () => timers.size,
        focusCalls: () => focusCalls,
        setFocus: (element) => { activeElement = element; },
        advance(milliseconds) {
            now += milliseconds;
            for (const [id, timer] of [...timers]) {
                if (timer.at <= now && timers.has(id)) {
                    timers.delete(id);
                    timer.callback();
                }
            }
        },
    };
}

// Linkedom deliberately has no layout engine. Supply only measured geometry;
// all placement, viewport clamping, and caret decisions use the real methods.
function geometryFixture(options = {}, measurements = {}) {
    const f = fixture({ openOnHover: false, ...options });
    const geometry = {
        width: 1200,
        height: 1000,
        panelWidth: 384,
        naturalHeight: 240,
        anchorLeft: 300,
        anchorTop: 100,
        anchorWidth: 100,
        anchorHeight: 28,
        ...measurements,
    };
    const rect = (left, top, width, height) => ({ left, top, width, height, right: left + width, bottom: top + height });
    const measuredWidth = () => Math.min(geometry.panelWidth, (f.window.visualViewport?.width ?? geometry.width) - 24);
    const measuredHeight = () => {
        const height = Number.parseFloat(f.refs.panelScroll.style.height);
        const maximum = Number.parseFloat(f.refs.panelScroll.style.maxHeight);
        return Math.min(Number.isFinite(height) ? height : geometry.naturalHeight, Number.isFinite(maximum) ? maximum : Infinity);
    };
    Object.defineProperties(f.document.documentElement, {
        clientWidth: { get: () => geometry.width },
        clientHeight: { get: () => geometry.height },
    });
    Object.defineProperties(f.refs.panel, {
        offsetWidth: { get: measuredWidth },
        offsetHeight: { get: measuredHeight },
    });
    Object.defineProperties(f.refs.panelScroll, {
        offsetHeight: { get: measuredHeight },
        clientHeight: { get: () => Math.max(0, measuredHeight() - 2) },
        scrollHeight: { get: () => geometry.naturalHeight - 2 },
    });
    const anchor = f.refs.trigger.querySelector('button');
    anchor.getBoundingClientRect = () => rect(geometry.anchorLeft, geometry.anchorTop, geometry.anchorWidth, geometry.anchorHeight);
    anchor.getClientRects = () => [anchor.getBoundingClientRect()];
    f.refs.panel.getBoundingClientRect = () => rect(Number.parseFloat(f.refs.panel.style.left) || 0, Number.parseFloat(f.refs.panel.style.top) || 0, measuredWidth(), measuredHeight());
    const caret = f.document.createElement('span');
    caret.setAttribute('data-rt-dropdown-caret', '');
    f.refs.panel.prepend(caret);
    f.window.matchMedia = () => ({ matches: geometry.width < 768 });
    f.dropdown.open = true;
    return { ...f, geometry, caret, position: () => f.dropdown.syncAnchoredPanel(f.refs.panel) };
}

test('natural-height dropdown retains its content height and anchor-side limit by default', () => {
    const f = geometryFixture();
    f.position();
    assert.equal(f.dropdown.fixedHeight, false);
    assert.equal(f.refs.panelScroll.style.height || '', '');
    assert.equal(f.refs.panelScroll.style.maxHeight, '448px');
    assert.equal(f.refs.panel.offsetHeight, 240);
    assert.equal(f.refs.panel.style.top, '136px');
    assert.equal(f.refs.panel.style.left, '300px');
    assert.equal(f.caret.hasAttribute('hidden'), false);

    Object.assign(f.geometry, { height: 568, naturalHeight: 800, anchorTop: 270 });
    f.position();
    assert.equal(f.dropdown.placement, 'bottom');
    assert.equal(f.refs.panelScroll.style.height || '', '');
    assert.equal(f.refs.panelScroll.style.maxHeight, '250px');
    assert.equal(f.refs.panel.offsetHeight, 250);
    assert.equal(f.refs.panel.style.top, '306px');
    assert.equal(f.caret.hasAttribute('hidden'), false);
});

test('fixed-height event card stays 560px and attached when either anchor side fits', () => {
    const f = geometryFixture({ fixedHeight: true, maxHeight: 560 });
    f.position();
    assert.equal(f.refs.panelScroll.style.height, '560px');
    assert.equal(f.refs.panelScroll.style.maxHeight, '560px');
    assert.equal(f.refs.panel.style.top, '136px');
    assert.equal(f.dropdown.placement, 'bottom');
    assert.equal(f.caret.hasAttribute('hidden'), false);

    Object.assign(f.geometry, { anchorTop: 900, naturalHeight: 1800 });
    f.position();
    assert.equal(f.dropdown.placement, 'top');
    assert.equal(f.refs.panel.style.top, '332px');
    assert.equal(f.refs.panel.offsetHeight, 560);
    assert.equal(f.caret.hasAttribute('hidden'), false);
});

test('short viewport keeps the full bounded card visible and hides its detached caret', () => {
    const f = geometryFixture({ fixedHeight: true, maxHeight: 560 }, { width: 360, height: 568, anchorLeft: 200, anchorTop: 270, naturalHeight: 1800 });
    f.position();
    assert.equal(f.refs.panelScroll.style.height, '544px');
    assert.equal(f.refs.panelScroll.style.maxHeight, '544px');
    assert.equal(f.refs.panel.style.top, '12px');
    assert.equal(f.refs.panel.style.left, '12px');
    assert.equal(f.refs.panel.getBoundingClientRect().bottom, 556);
    assert.equal(f.refs.panel.getBoundingClientRect().right, 348);
    assert.equal(f.caret.hasAttribute('hidden'), true);
    assert.equal(f.dropdown.open, true);

    // A second measurement must not drift due to an already constrained body.
    f.position();
    assert.equal(f.refs.panel.style.top, '12px');
    assert.equal(f.refs.panel.offsetHeight, 544);
});

test('fixed card follows visual viewport offsets and restores its caret after a resize', () => {
    const f = geometryFixture({ fixedHeight: true, maxHeight: 560 }, { anchorLeft: 180, anchorTop: 240 });
    f.window.visualViewport = { width: 420, height: 400, offsetLeft: 40, offsetTop: 80 };
    f.position();
    assert.equal(f.refs.panelScroll.style.height, '376px');
    assert.equal(f.refs.panel.style.top, '92px');
    assert.equal(f.refs.panel.style.left, '64px');
    assert.equal(f.refs.panel.getBoundingClientRect().bottom, 468);
    assert.equal(f.caret.hasAttribute('hidden'), true);

    Object.assign(f.window.visualViewport, { width: 900, height: 1000, offsetLeft: 20, offsetTop: 30 });
    f.position();
    assert.equal(f.refs.panelScroll.style.height, '560px');
    assert.equal(f.refs.panel.style.top, '276px');
    assert.equal(f.refs.panel.style.left, '180px');
    assert.equal(f.caret.hasAttribute('hidden'), false);
});

function detailPagerFixture(reducedMotion = false) {
    const calls = [];
    const innerCalls = [];
    const children = [0, 1].map(page => ({ scrollTo: options => innerCalls.push({ page, ...options }) }));
    const scroller = { children, clientHeight: 360, scrollTo: (options) => calls.push(options) };
    const refs = { detailPages: scroller };
    const window = { matchMedia: (query) => ({ matches: query === '(prefers-reduced-motion: reduce)' && reducedMotion }) };
    const pager = new Function('$refs', 'window', `return (${detailDataExpression});`)(refs, window);
    return { pager, scroller, refs, calls, innerCalls };
}

test('detail pager uses a whole current page height and bounds navigation to its two pages', () => {
    const f = detailPagerFixture();
    f.pager.goToDetailPage(1);
    assert.deepEqual(f.calls.at(-1), { top: 360, behavior: 'smooth' });
    assert.equal(f.pager.detailPage, 1);
    f.scroller.clientHeight = 244;
    f.pager.goToDetailPage(8);
    assert.deepEqual(f.calls.at(-1), { top: 244, behavior: 'smooth' });
    assert.equal(f.pager.detailPage, 1);
    f.pager.goToDetailPage(-1);
    assert.deepEqual(f.calls.at(-1), { top: 0, behavior: 'smooth' });
    assert.equal(f.pager.detailPage, 0);
    assert.deepEqual(f.innerCalls.at(-1), { page: 0, top: 0, behavior: 'instant' });
    delete f.refs.detailPages;
    assert.doesNotThrow(() => f.pager.goToDetailPage(1));
    assert.equal(f.calls.length, 3);
});

test('detail pager respects reduced motion and reopening resets to the first page immediately', () => {
    const reduced = detailPagerFixture(true);
    reduced.pager.goToDetailPage(1);
    assert.deepEqual(reduced.calls.at(-1), { top: 360, behavior: 'instant' });

    const f = detailPagerFixture();
    f.pager.goToDetailPage(1);
    const initExpression = detailBlade.match(/x-init="([^"]+)"/)?.[1];
    assert.ok(initExpression);
    let openWatcher;
    const watch = (name, callback) => {
        assert.equal(name, 'open');
        openWatcher = callback;
    };
    new Function('$watch', '$nextTick', 'goToDetailPage', initExpression)(watch, (callback) => callback(), f.pager.goToDetailPage.bind(f.pager));
    openWatcher(false);
    assert.equal(f.calls.length, 1);
    openWatcher(true);
    assert.deepEqual(f.calls.at(-1), { top: 0, behavior: 'instant' });
    assert.equal(f.pager.detailPage, 0);
});

test('native detail scrolling updates bounded page state without a server request', () => {
    const expression = detailBlade.match(/@scroll\.passive="([^"]+)"/)?.[1];
    assert.ok(expression);
    const scrollPage = new Function('detailPage', '$el', `${expression}; return detailPage;`);
    assert.equal(scrollPage(1, { scrollTop: 0, clientHeight: 360 }), 0);
    assert.equal(scrollPage(0, { scrollTop: 360, clientHeight: 360 }), 1);
    assert.equal(scrollPage(0, { scrollTop: 900, clientHeight: 360 }), 1);
    assert.equal(scrollPage(1, { scrollTop: -100, clientHeight: 360 }), 0);
    assert.equal(scrollPage(1, { scrollTop: 0, clientHeight: 0 }), 0);
});

test('detail pagination actions and nested scrolling do not dismiss the parent card', () => {
    const f = fixture({ openOnHover: false, fixedHeight: true });
    const nav = f.document.createElement('nav');
    nav.setAttribute('data-rt-dropdown-keep-open', '');
    const button = f.document.createElement('button');
    nav.appendChild(button);
    f.refs.panelScroll.appendChild(nav);
    f.dropdown.toggle();
    f.dropdown.handlePanelAction({ target: button });
    assert.equal(f.dropdown.open, true);
    f.dropdown.handleTrackedScroll({ target: nav });
    assert.equal(f.dropdown.open, true);
    assert.match(detailBlade, /<nav[^>]+data-rt-dropdown-keep-open/);
    assert.match(detailBlade, /@keydown\.page-down\.self\.prevent="goToDetailPage\(1\)"/);
    assert.match(detailBlade, /@keydown\.page-up\.self\.prevent="goToDetailPage\(0\)"/);
});

test('opt-in external trigger anchors the shared panel and restores keyboard focus', () => {
    const { dropdown, document, refs } = fixture({ openOnHover: false });
    const anchor = document.getElementById('outside');
    let focused = false;
    anchor.focus = () => { focused = true; };
    dropdown.layerId = 'test';
    dropdown.startPositionTracking = () => {};
    dropdown.openFromAnchor({ detail: { id: 'another-panel', anchor } });
    assert.equal(dropdown.open, false);
    dropdown.openFromAnchor({ detail: { id: 'test', anchor } });
    assert.equal(dropdown.open, true);
    assert.equal(dropdown.resolvePositionAnchor(), anchor);
    assert.equal(anchor.getAttribute('aria-expanded'), 'true');
    dropdown.close(true);
    assert.equal(focused, true);
    assert.equal(dropdown.open, false);
    assert.ok(refs.panel);
});

test('external timeline anchor follows mirrored footer scroll but closes when out of view', () => {
    const f = fixture({ openOnHover: false });
    const root = f.document.getElementById('root');
    root.setAttribute('data-rt-dropdown-scroll-root', '');
    const anchor = f.refs.trigger.querySelector('button');
    const footer = f.document.createElement('div');
    root.appendChild(footer);
    root.getBoundingClientRect = () => ({ left: 0, right: 700, top: 100, bottom: 600 });
    anchor.getBoundingClientRect = () => ({ left: 180, right: 400, top: 200, bottom: 248 });
    let positioned = 0;
    Object.assign(f.dropdown, { open: true, externalAnchor: anchor, schedulePosition: () => positioned++ });
    f.dropdown.handleTrackedScroll({ target: footer });
    assert.equal(f.dropdown.open, true);
    assert.equal(positioned, 1);
    anchor.getBoundingClientRect = () => ({ left: 720, right: 900, top: 200, bottom: 248 });
    f.dropdown.handleTrackedScroll({ target: footer });
    assert.equal(f.dropdown.open, false);
});

test('teleported panel close notifies only its identified timeline controller', async () => {
    const f = fixture();
    const events = [];
    f.dropdown.$dispatch = (name, detail) => events.push({ name, detail });
    f.dropdown.open = true;
    f.dropdown.close();
    assert.deepEqual(events, [{ name: 'dropdown-closed', detail: { id: f.dropdown.layerId } }]);
    const timeline = await readFile(new URL('../../resources/views/livewire/operations/staff-timeline.blade.php', import.meta.url), 'utf8');
    assert.match(timeline, /x-on:dropdown-closed\.window="if \(\$event\.detail\?\.id === layerId\) closePlanner\(\)"/);
});

const mouse = { pointerType: 'mouse' };

test('hover is opt-in and ordinary dropdown click toggles remain unchanged', () => {
    const f = fixture({ openOnHover: false });
    f.dropdown.enterHover(mouse, 'trigger');
    assert.equal(f.pending(), 0);
    assert.equal(f.dropdown.open, false);
    f.dropdown.toggle();
    assert.equal(f.dropdown.open, true);
    f.dropdown.toggle();
    assert.equal(f.dropdown.open, false);
    assert.match(blade, /'openOnHover'\s*=> false/);
});

test('short pointer passes do not open a preview; sustained hover opens after delay', () => {
    const f = fixture();
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(99);
    assert.equal(f.dropdown.open, false);
    f.dropdown.leaveHover(mouse, 'trigger');
    f.advance(200);
    assert.equal(f.dropdown.open, false);
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(100);
    assert.equal(f.dropdown.open, true);
    assert.equal(f.dropdown.pinned, false);
    assert.deepEqual(f.dispatched, ['dropdown-open']);
});

test('pointer crosses the anchor gap into teleported content without closing', () => {
    const f = fixture();
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(100);
    f.dropdown.leaveHover(mouse, 'trigger');
    f.advance(100);
    assert.equal(f.dropdown.open, true);
    f.dropdown.enterHover(mouse, 'panel');
    f.advance(1000);
    assert.equal(f.dropdown.open, true);
    f.dropdown.leaveHover(mouse, 'panel');
    f.advance(180);
    assert.equal(f.dropdown.open, false);
});

test('click pins a hover preview, second click closes, and no delayed reopen survives', () => {
    const f = fixture();
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(100);
    f.dropdown.toggle();
    assert.equal(f.dropdown.pinned, true);
    f.dropdown.leaveHover(mouse, 'trigger');
    f.advance(1000);
    assert.equal(f.dropdown.open, true);
    f.dropdown.toggle();
    f.advance(1000);
    assert.equal(f.dropdown.open, false);
    assert.equal(f.dropdown.pinned, false);
    assert.equal(f.pending(), 0);
});

test('keyboard can enter popup controls; Escape restores the trigger and clears pinning', () => {
    const f = fixture();
    f.dropdown.focusHoverPanel();
    assert.equal(f.dropdown.open, true);
    assert.equal(f.dropdown.pinned, true);
    assert.equal(f.focusCalls(), 1);
    assert.equal(f.document.activeElement, f.refs.panel.querySelector('a'));
    f.dropdown.close(true);
    assert.equal(f.dropdown.open, false);
    assert.equal(f.dropdown.pinned, false);
    assert.equal(f.document.activeElement, f.refs.trigger.querySelector('button'));
    assert.equal(f.focusCalls(), 2);
    assert.match(blade, /@keydown\.arrow-down\.prevent\.stop="focusHoverPanel\(\)"/);
});

test('hover-only content remains open while a popup control has focus', () => {
    const f = fixture();
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(100);
    f.dropdown.leaveHover(mouse, 'trigger');
    f.setFocus(f.refs.panel.querySelector('a'));
    f.dropdown.retainHoverFocus();
    f.advance(500);
    assert.equal(f.dropdown.open, true);
    f.setFocus(f.document.getElementById('outside'));
    f.dropdown.scheduleHoverClose();
    f.advance(180);
    assert.equal(f.dropdown.open, false);
});

test('touch does not create hover timers and its click still opens pinned content', () => {
    const f = fixture();
    f.dropdown.enterHover({ pointerType: 'touch' }, 'trigger');
    assert.equal(f.pending(), 0);
    assert.equal(f.dropdown.open, false);
    f.dropdown.toggle();
    assert.equal(f.dropdown.open, true);
    assert.equal(f.dropdown.pinned, true);
});

test('outside clicks and outside scroll close pinned panels; their own scroll stays usable', () => {
    const f = fixture();
    f.dropdown.toggle();
    f.dropdown.handleTrackedScroll({ target: f.refs.panelScroll });
    assert.equal(f.dropdown.open, true);
    f.dropdown.handleTrackedScroll({ target: f.document.body });
    assert.equal(f.dropdown.open, false);
    f.dropdown.toggle();
    f.dropdown.handleOutsideClick({ target: f.document.getElementById('outside') });
    assert.equal(f.dropdown.open, false);
    assert.equal(f.dropdown.pinned, false);
});

test('unmount cancels hover timers and never opens detached dropdowns', () => {
    const f = fixture();
    f.dropdown.enterHover(mouse, 'trigger');
    assert.equal(f.pending(), 1);
    f.dropdown.destroy();
    assert.equal(f.pending(), 0);
    f.advance(1000);
    assert.equal(f.dropdown.open, false);
});

test('external close resets stale pointer state before the next hover preview', () => {
    const f = fixture();
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(100);
    f.dropdown.enterHover(mouse, 'panel');
    f.dropdown.close();
    assert.equal(f.dropdown.pointerOverTrigger, false);
    assert.equal(f.dropdown.pointerOverPanel, false);
    f.dropdown.enterHover(mouse, 'trigger');
    f.advance(100);
    f.dropdown.leaveHover(mouse, 'trigger');
    f.advance(180);
    assert.equal(f.dropdown.open, false);
});
