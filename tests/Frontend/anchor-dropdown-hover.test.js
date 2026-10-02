import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { parseHTML } from 'linkedom';

const blade = await readFile(new URL('../../resources/views/components/ui/dropdown/anchor-dropdown.blade.php', import.meta.url), 'utf8');
const dataExpression = blade.match(/x-data="([\s\S]*?)"\n  x-cloak/)?.[1];
assert.ok(dataExpression, 'Global dropdown Alpine object must be readable for interaction checks.');

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
        dropdown, refs, document, dispatched,
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
