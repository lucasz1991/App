import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { parseHTML } from 'linkedom';

const blade = await readFile(new URL('../../resources/views/components/ui/dropdown/anchor-dropdown.blade.php', import.meta.url), 'utf8');
const dataExpression = blade.match(/x-data="([\s\S]*?)"\n  x-cloak/)?.[1];
assert.ok(dataExpression, 'Global dropdown Alpine object must be readable for interaction checks.');
const detailBlade = await readFile(new URL('../../resources/views/livewire/operations/partials/timeline-event-detail.blade.php', import.meta.url), 'utf8');
const detailDataExpression = detailBlade.match(/x-data="([\s\S]*?)"\s+x-init=/)?.[1];
assert.ok(detailDataExpression, 'Timeline detail card must expose its actual Alpine tab state.');
const tabsBlade = await readFile(new URL('../../resources/views/components/operations/panel/tabs.blade.php', import.meta.url), 'utf8');

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
        cornerRadius: '16px',
        caretWidth: 18,
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
    Object.defineProperty(caret, 'offsetWidth', { get: () => geometry.caretWidth });
    f.window.getComputedStyle = element => element === caret
        ? { width: `${geometry.caretWidth}px` }
        : {
            borderTopLeftRadius: geometry.topLeftRadius ?? geometry.cornerRadius,
            borderTopRightRadius: geometry.topRightRadius ?? geometry.cornerRadius,
            borderBottomLeftRadius: geometry.bottomLeftRadius ?? geometry.cornerRadius,
            borderBottomRightRadius: geometry.bottomRightRadius ?? geometry.cornerRadius,
        };
    f.window.matchMedia = () => ({ matches: geometry.width < 768 });
    f.dropdown.open = true;
    return { ...f, geometry, caret, position: () => f.dropdown.syncAnchoredPanel(f.refs.panel) };
}

test('viewport-edge caret clears the full rounded corner on either side and placement', () => {
    for (const placement of ['top', 'bottom']) {
        for (const side of ['left', 'right']) {
            const f = geometryFixture({}, {
                width: 903,
                anchorLeft: side === 'left' ? 0 : 879,
                anchorWidth: 24,
                anchorTop: placement === 'top' ? 900 : 100,
            });
            f.dropdown.preferredPlacement = placement;
            f.dropdown.horizontalAlign = side;
            f.position();
            const caretX = Number.parseFloat(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'));
            const expectedInset = 16 + (18 / 2) + 2;
            assert.equal(f.dropdown.placement, placement);
            assert.equal(caretX, side === 'left' ? expectedInset : 384 - expectedInset);
            assert.equal(f.caret.hasAttribute('hidden'), false);
            assert.ok(f.refs.panel.getBoundingClientRect().left >= 12);
            assert.ok(f.refs.panel.getBoundingClientRect().right <= 891);
        }
    }
});

test('wide mobile panel keeps the safe inset even when its trigger lies beyond the card edge', () => {
    const f = geometryFixture({}, { width: 320, anchorLeft: 294, anchorWidth: 26 });
    f.position();
    assert.equal(f.refs.panel.style.left, '12px');
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), '269px');
    assert.equal(f.refs.panel.dataset.wideCentered, 'true');
    assert.equal(f.caret.hasAttribute('hidden'), false);
});

test('caret clearance follows asymmetric and elliptical corner radii and its real width', () => {
    const f = geometryFixture({}, {
        width: 903,
        anchorLeft: 879,
        anchorWidth: 24,
        topRightRadius: '24px 12px',
        bottomRightRadius: '8%',
        caretWidth: 24,
    });
    f.position();
    const clearance = Math.ceil(384 * 0.08 + 12 + 2);
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), `${384 - clearance}px`);
    Object.assign(f.geometry, { anchorTop: 900 });
    f.position();
    assert.equal(f.dropdown.placement, 'top');
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), `${384 - clearance}px`);
});

test('external anchors get the same corner guard without moving an ordinary centered caret', () => {
    const f = geometryFixture({}, { width: 903, anchorLeft: 879, anchorWidth: 24 });
    const externalAnchor = f.document.getElementById('outside');
    externalAnchor.getBoundingClientRect = f.refs.trigger.querySelector('button').getBoundingClientRect;
    externalAnchor.getClientRects = () => [externalAnchor.getBoundingClientRect()];
    f.dropdown.externalAnchor = externalAnchor;
    f.position();
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), '357px');
    Object.assign(f.geometry, { anchorLeft: 300, anchorWidth: 100 });
    f.position();
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), '50px');
});

test('very narrow panels hide a caret that cannot fit between corners and restore it after resize', () => {
    const f = geometryFixture({}, { panelWidth: 40, anchorWidth: 24 });
    f.position();
    assert.equal(f.caret.hasAttribute('hidden'), true);
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), '20px');
    Object.assign(f.geometry, { panelWidth: 160 });
    f.position();
    assert.equal(f.caret.hasAttribute('hidden'), false);
    assert.equal(f.refs.panel.style.getPropertyValue('--rt-dropdown-caret-x'), '27px');
});

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

function detailTabsFixture() {
    const state = new Function(`return (${detailDataExpression});`)();
    const expression = (attribute, key) => {
        const escapedAttribute = attribute.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const source = tabsBlade.match(new RegExp(`${escapedAttribute}="([^"]+)"`))?.[1];
        assert.ok(source, `Shared tab component must expose ${attribute}.`);
        return source.replaceAll('{{ $model }}', 'detailTab').replaceAll('@js((string) $key)', JSON.stringify(key));
    };
    return {
        state,
        activate(attribute, key) { new Function('state', `with (state) { ${expression(attribute, key)}; }`)(state); },
        value(attribute, key) { return new Function('state', `with (state) { return (${expression(attribute, key)}); }`)(state); },
        keyboard(attribute, focus, element) { new Function('$focus', '$el', expression(attribute, ''))(focus, element); },
    };
}

test('shared tab clicks select detail panels and keep the selected tab as the only tab stop', () => {
    const f = detailTabsFixture();
    assert.equal(f.state.detailTab, 'period');
    for (const key of ['assignment', 'period']) {
        f.activate('x-on:click', key);
        assert.equal(f.state.detailTab, key);
        for (const candidate of ['period', 'assignment']) {
            assert.equal(f.value('x-bind:aria-selected', candidate), candidate === key ? 'true' : 'false');
            assert.equal(f.value('x-bind:tabindex', candidate), candidate === key ? 0 : -1);
        }
    }
});

test('shared tab keyboard focus switches panels with arrow wrapping and Home or End', () => {
    const f = detailTabsFixture();
    const element = {};
    const keys = ['period', 'assignment'];
    let index = 0;
    const focusAt = nextIndex => {
        index = (nextIndex + keys.length) % keys.length;
        f.activate('x-on:focus', keys[index]);
    };
    const focus = {
        within(actual) { assert.equal(actual, element); return this; },
        wrap() { return this; },
        next() { focusAt(index + 1); },
        previous() { focusAt(index - 1); },
        first() { focusAt(0); },
        last() { focusAt(keys.length - 1); },
    };
    for (const [key, selected] of [
        ['arrow-right', 'assignment'], ['arrow-right', 'period'],
        ['arrow-left', 'assignment'], ['home', 'period'], ['end', 'assignment'],
    ]) {
        f.keyboard(`x-on:keydown.${key}.prevent`, focus, element);
        assert.equal(f.state.detailTab, selected);
    }
});

test('reopening detail cards resets to period without scroll references or wheel switching', () => {
    const f = detailTabsFixture();
    const initExpression = detailBlade.match(/x-init="([^"]+)"/)?.[1];
    assert.ok(initExpression);
    let openWatcher;
    const watch = (name, callback) => {
        assert.equal(name, 'open');
        openWatcher = callback;
    };
    new Function('state', '$watch', `with (state) { ${initExpression}; }`)(f.state, watch);
    f.activate('x-on:click', 'assignment');
    openWatcher(false);
    assert.equal(f.state.detailTab, 'assignment');
    openWatcher(true);
    assert.equal(f.state.detailTab, 'period');
    assert.doesNotMatch(detailBlade, /detailPages|goToDetailPage|@scroll|x-on:scroll|@wheel|x-on:wheel|snap-y|snap-start|overflow-y-auto/);
});

test('detail tab activation does not dismiss the parent dropdown', () => {
    const f = fixture({ openOnHover: false, fixedHeight: true });
    const nav = f.document.createElement('div');
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('data-rt-dropdown-keep-open', '');
    const button = f.document.createElement('button');
    button.setAttribute('role', 'tab');
    nav.appendChild(button);
    f.refs.panelScroll.appendChild(nav);
    f.dropdown.toggle();
    f.dropdown.handlePanelAction({ target: button });
    assert.equal(f.dropdown.open, true);
    f.dropdown.handleTrackedScroll({ target: nav });
    assert.equal(f.dropdown.open, true);
    assert.match(detailBlade, /<x-operations\.panel\.tabs[\s\S]*?model="detailTab"/);
    assert.match(detailBlade, /<x-operations\.panel\.tabs[\s\S]*?data-rt-dropdown-keep-open\s*\/>/);
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
