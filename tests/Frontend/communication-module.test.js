import { activeVisibleChatId } from '../../resources/js/notification-presentation.js';
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';

const source = await readFile(new URL('../../resources/js/communication-module.js', import.meta.url), 'utf8');
const view = await readFile(new URL('../../resources/views/livewire/communication.blade.php', import.meta.url), 'utf8');
const styles = await readFile(new URL('../../resources/css/communication-module.css', import.meta.url), 'utf8');
const chatbotStyles = await readFile(new URL('../../resources/css/chatbot.css', import.meta.url), 'utf8');
const aiAssistStyles = await readFile(new URL('../../resources/css/ai-assist.css', import.meta.url), 'utf8');
const aiAssistSource = await readFile(new URL('../../resources/js/ai-assist.js', import.meta.url), 'utf8');
const chatbotSource = await readFile(new URL('../../resources/js/chatbot.js', import.meta.url), 'utf8');
const chatbotView = await readFile(new URL('../../resources/views/livewire/tools/chatbot.blade.php', import.meta.url), 'utf8');
const aiAssistView = await readFile(new URL('../../resources/views/livewire/operations/ai-assist.blade.php', import.meta.url), 'utf8');
const launcherComponent = await readFile(new URL('../../resources/views/components/ui/floating/launcher.blade.php', import.meta.url), 'utf8');
const windowComponent = await readFile(new URL('../../resources/views/components/ui/floating/window.blade.php', import.meta.url), 'utf8');

// Actual production module, isolated DOM and explicit test-only scheduling,
// geometry, style and WAAPI stubs. No browser, provider or device is opened.
function fixture({ width = 1440, height = 1000, reduced = false, animate = true } = {}) {
    const { document, window: dom } = parseHTML(`<html><body>
        <header data-rt-shell-topbar></header><main id="main-content"><div class="rt-app-page-frame"></div></main>
        <div data-communication-host data-app-badge-count="3"><button id="launcher" data-rt-floating-launcher></button><section id="panel">
        <header id="head"><div role="tablist">${['chats', 'calls', 'meetings'].map(tab => `<button role="tab" data-comm-tab="${tab}">${tab}</button>`).join('')}</div><div id="handle" aria-label="Fensterposition"></div></header>
        <div data-communication-chat="true" data-mobile-pane="list"><textarea id="draft"></textarea></div>
        <div id="persistent-call"></div></section></div>
    </body></html>`);
    const root = document.querySelector('[data-communication-host]'), panel = document.querySelector('#panel'), launcher = document.querySelector('#launcher');
    const nextTicks = [], timers = new Map(), rafs = new Map(), animations = [], calls = [], dispatches = [], captures = new Map();
    let component, active = document.body, rafId = 0, timerId = 0, now = 1000, visibility = 'visible';
    Object.defineProperty(document, 'activeElement', { configurable: true, get: () => active });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibility });
    dom.HTMLElement.prototype.focus = function () { active = this; };
    const listeners = new Map(), mediaListeners = new Set();
    const preference = { matches: reduced, addEventListener: (name, fn) => mediaListeners.add(fn), removeEventListener: (name, fn) => mediaListeners.delete(fn) };
    const stored = new Map();
    const window = {
        innerWidth: width, innerHeight: height, location: { href: 'https://railtime.example/arbeitsplatz/ansicht/orders' },
        localStorage: { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value) },
        addEventListener(name, fn) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); },
        removeEventListener: (name, fn) => listeners.get(name)?.delete(fn),
        dispatchEvent(event) { for (const fn of listeners.get(event.type) ?? []) fn(event); return !event.defaultPrevented; },
        matchMedia: () => preference,
        Livewire: { dispatchTo: (target, name, payload) => dispatches.push({ target, name, payload }) },
    };
    class Observer { observe() {} disconnect() { this.disconnected = true; } }
    const shape = (left, top, w, h) => ({ left, top, width: w, height: h, right: left + w, bottom: top + h });
    const rect = element => {
        if (element.testRect) return element.testRect;
        if (element === panel) {
            const running = animations.findLast(item => item.playState === 'running');
            if (running?.sampleRect) return running.sampleRect;
            const w = component?.mode === 'fullscreen' ? width - 224 : Math.min(640, width - 24);
            const h = component?.mode === 'fullscreen' ? height - 70 : Math.min(744, height - 120);
            const corner = component?.corner || 'bottom-right';
            return shape(parseFloat(panel.style.left) || (corner.endsWith('left') ? 24 : width - w - 24), parseFloat(panel.style.top) || (corner.startsWith('top') ? 94 : height - h - 24), w, h);
        }
        if (element === launcher) return component?.open ? shape(0, 0, 0, 0) : shape(width - 80, height - 80, 56, 56);
        if (element.matches('[data-rt-shell-topbar]')) return shape(0, 0, width, 70);
        if (element.matches('.rt-app-page-frame')) return shape(224, 70, width - 224, height - 70);
        return shape(0, 0, 0, 0);
    };
    dom.HTMLElement.prototype.getBoundingClientRect = function () { return rect(this); };
    panel.setPointerCapture = id => captures.set(id, panel);
    panel.hasPointerCapture = id => captures.get(id) === panel;
    panel.releasePointerCapture = id => captures.delete(id);
    if (animate) panel.animate = (frames, options) => {
        const item = {
            frames: Array.from(frames, frame => ({ ...frame })), options: { ...options }, playState: 'running', cancels: 0, onfinish: null, sampleRect: null,
            finish() { if (this.playState !== 'running') return; this.playState = 'finished'; this.onfinish?.(); },
            cancel() { this.cancels++; this.playState = 'idle'; },
        };
        animations.push(item); return item;
    };
    const context = vm.createContext({
        window, document, URL, CustomEvent: dom.CustomEvent, MutationObserver: Observer,
        performance: { now: () => now },
        requestAnimationFrame: fn => { const id = ++rafId; rafs.set(id, fn); return id; }, cancelAnimationFrame: id => rafs.delete(id),
        setTimeout: fn => { const id = ++timerId; timers.set(id, fn); return id; }, clearTimeout: id => timers.delete(id),
        getComputedStyle: element => ({ display: element.testDisplay || 'block', visibility: element.testVisibility || 'visible', opacity: element.testOpacity ?? '1', borderRadius: '20px' }),
    });
    vm.runInContext(source.replace(/export function /g, 'function ') + '\nglobalThis.testExports={communicationModule,communicationLink,cornerForPoint};', context);
    component = context.testExports.communicationModule();
    component.$refs = { panel, launcher }; component.$root = root;
    component.$nextTick = fn => nextTicks.push(fn); component.$watch = () => {};
    component.$wire = { openCall: uuid => calls.push({ action: 'open', uuid }), openHistory: uuid => calls.push({ action: 'history', uuid }) };
    component.init();
    const flush = () => {
        for (let guard = 0; nextTicks.length || rafs.size || timers.size; guard++) {
            assert.ok(guard < 50, 'Unbounded test scheduler');
            nextTicks.splice(0).forEach(fn => fn()); const batch = [...rafs.values()]; rafs.clear(); batch.forEach(fn => fn());
            const pending = [...timers.values()]; timers.clear(); pending.forEach(fn => fn());
        }
    };
    const event = (target, type, data = {}) => {
        const value = new dom.Event(type, { bubbles: true, cancelable: true }); Object.assign(value, data);
        target.dispatchEvent(value); return value;
    };
    const emit = (type, detail = {}) => window.dispatchEvent(new dom.CustomEvent(type, { detail }));
    const pointer = (type, target, data = {}) => event(target, type, { pointerType: 'touch', pointerId: 1, isPrimary: true, button: 0, clientX: 900, clientY: 250, ...data });
    flush();
    return {
        ...context.testExports, component, document, root, panel, launcher, window, stored, animations, captures, calls, dispatches, preference, flush, event, emit, pointer,
        tick: ms => { now += ms; }, finish: () => { animations.at(-1)?.finish(); flush(); },
        reduce: () => { preference.matches = true; for (const fn of mediaListeners) fn({ matches: true }); },
        visibility: value => { visibility = value; event(document, 'visibilitychange'); flush(); },
    };
}

test('communication links resolve only same-origin native entry routes and distinguish call history', () => {
    const f = fixture();
    for (const [href, expected] of [
        ['/chat?chat=17', { tab: 'chats', chatId: 17 }], ['/chat', { tab: 'chats', chatId: null }],
        ['/calls', { tab: 'calls', list: true }], ['/meetings', { tab: 'meetings' }], ['/calls/abc-123', { tab: 'calls', roomUuid: 'abc-123' }],
        ['/calls/abc/history', { tab: 'calls', historyRoomUuid: 'abc' }],
    ]) assert.equal(JSON.stringify(f.communicationLink(href, f.window.location.href)), JSON.stringify(expected));
    for (const href of ['https://foreign.example/chat', '/calls/abc/not-history', '/messages', 'javascript:alert(1)']) assert.equal(f.communicationLink(href, f.window.location.href), null);
    f.component.destroy();
});

test('panel centres select four available viewport corners without changing the two corner axes', () => {
    const f = fixture();
    for (const [x, y, expected] of [[200, 200, 'top-left'], [1200, 200, 'top-right'], [200, 800, 'bottom-left'], [1200, 800, 'bottom-right']]) assert.equal(f.cornerForPoint(x, y, 1440, 1000), expected);
    f.component.destroy();
});

test('communication and assistant surfaces close each other while the call DOM stays mounted', () => {
    const f = fixture(); let assistantClose = 0;
    f.window.addEventListener('railtime-assistant-close', () => assistantClose++);
    f.component.show(); f.flush(); f.finish();
    assert.equal(assistantClose, 1); assert.equal(f.component.open, true);
    const call = f.document.querySelector('#persistent-call');
    f.emit('communication:call-ready'); f.flush(); f.finish();
    f.emit('railtime-assistant-opened'); f.flush();
    assert.equal(f.component.open, false); assert.equal(f.component.activeCall, true);
    assert.notEqual(f.document.activeElement, f.launcher, 'the newly opened surface retains focus');
    assert.equal(f.document.querySelector('#persistent-call'), call);
    f.component.show(); f.flush(); f.finish();
    f.emit('railtime-sidebar-opened'); f.flush();
    assert.equal(f.component.open, false); assert.equal(f.component.activeCall, true);
    assert.notEqual(f.document.activeElement, f.launcher, 'the sidebar retains focus');
    assert.equal(f.document.querySelector('#persistent-call'), call);
    f.finish(); f.component.destroy();
});

test('surface-open event is shared by communication and AI Assist without call termination', () => {
    assert.match(source, /rt-floating-surface-opened[\s\S]*surface: 'communication'/);
    assert.match(aiAssistSource, /rt-floating-surface-opened[\s\S]*surface: 'assist'/);
    assert.match(view, /rt-floating-surface-opened\.window[\s\S]*surface === 'assist'/);
    assert.match(aiAssistView, /rt-floating-surface-opened\.window[\s\S]*surface === 'communication'/);
});

test('one persistent call DOM and session survive tabs resize close reopen and wire navigation', () => {
    const f = fixture(); const node = f.document.querySelector('#persistent-call');
    const session = { started: 100, muted: true, camera: false, disconnects: 0 }; node.session = session;
    f.document.querySelector('#draft').value = 'Preserved text'; f.emit('communication:call-ready'); f.flush(); f.finish();
    assert.equal(f.component.activeCall, true); assert.equal(f.component.showCall, true);
    for (const tab of ['chats', 'meetings', 'calls']) f.component.selectTab(tab);
    f.component.resize(); f.flush(); f.finish(); assert.equal(f.component.mode, 'fullscreen');
    f.component.close(); f.flush(); f.finish(); assert.equal(f.component.activeCall, true);
    f.component.show(); f.flush(); f.finish();
    f.component.resize(); f.flush(); f.finish();
    f.event(f.document, 'livewire:navigating'); assert.equal(f.component.mode, 'mini');
    f.event(f.document, 'livewire:navigated'); f.flush();
    assert.equal(f.document.querySelector('#persistent-call'), node); assert.equal(node.session, session);
    assert.equal(session.disconnects, 0); assert.equal(f.calls.length, 0);
    assert.equal(f.document.querySelector('#draft').value, 'Preserved text');
    f.emit('communication:call-released'); f.flush(); assert.equal(f.component.activeCall, false); f.component.destroy();
});

test('workspace accessibility restores existing inert and aria state after mini and navigation', () => {
    const f = fixture(); const main = f.document.querySelector('#main-content'); main.inert = false; main.setAttribute('aria-hidden', 'false');
    f.component.show('fullscreen'); f.flush(); f.finish(); assert.equal(main.inert, true); assert.equal(main.getAttribute('aria-hidden'), 'true');
    f.component.resize(); f.flush(); f.finish(); assert.equal(main.inert, false); assert.equal(main.getAttribute('aria-hidden'), 'false');
    f.component.resize(); f.flush(); f.finish(); f.event(f.document, 'livewire:navigating');
    const next = f.document.createElement('main'); next.id = 'main-content'; next.inert = false; main.replaceWith(next);
    f.event(f.document, 'livewire:navigated'); f.flush(); assert.equal(main.inert, false); assert.equal(next.inert, false);
    f.component.destroy();
});

test('chat read visibility is false when closed another tab or hidden and tracks the actual chat pane', () => {
    const f = fixture(); const chat = f.root.querySelector('[data-communication-chat]');
    chat.dataset.mobilePane = 'chat'; f.component.show(); f.flush();
    assert.equal(f.dispatches.at(-1).name, 'communication:visibility'); assert.equal(f.dispatches.at(-1).payload.visible, true);
    f.component.selectTab('meetings'); f.flush(); assert.equal(f.dispatches.at(-1).payload.visible, false);
    f.component.selectTab('chats'); f.flush(); f.visibility('hidden'); assert.equal(f.dispatches.at(-1).payload.visible, false);
    f.visibility('visible'); f.component.close(); f.flush(); assert.equal(f.dispatches.at(-1).payload.visible, false); f.component.destroy();
});

test('history intent uses native authorization entry while preserving an ongoing call', () => {
    const f = fixture(); f.emit('communication:call-ready'); f.flush(); f.finish();
    f.component.openIntent({ tab: 'calls', historyRoomUuid: 'ended-room' }); f.flush();
    assert.equal(f.calls.at(-1).action, 'history'); assert.equal(f.calls.at(-1).uuid, 'ended-room');
    f.emit('communication:history-ready'); f.flush();
    assert.equal(f.component.activeCall, true); assert.equal(f.component.showHistory, true); assert.equal(f.component.showCall, false);
    f.component.resumeCall(); f.flush(); assert.equal(f.component.showCall, true); f.component.destroy();
});

test('launcher clears the visible AI orb and ignores hidden zero or offscreen orbs', () => {
    const f = fixture(); f.component.measureLayout(); assert.equal(f.root.style.getPropertyValue('--comm-launcher-bottom'), '20px');
    const orb = f.document.createElement('button'); orb.className = 'rt-chatbot__pet-launcher';
    orb.testRect = { left: 1340, right: 1404, top: 900, bottom: 964, width: 64, height: 64 }; f.document.body.append(orb);
    f.component.measureLayout(); assert.equal(f.root.style.getPropertyValue('--comm-launcher-bottom'), '112px');
    for (const visibility of ['display', 'visibility', 'opacity', 'offscreen']) {
        orb.testDisplay = visibility === 'display' ? 'none' : 'block'; orb.testVisibility = visibility === 'visibility' ? 'hidden' : 'visible'; orb.testOpacity = visibility === 'opacity' ? '0' : '1';
        orb.testRect = { left: 1340, right: 1404, top: visibility === 'offscreen' ? 1100 : 900, bottom: visibility === 'offscreen' ? 1164 : 964, width: 64, height: 64 };
        f.component.measureLayout(); assert.equal(f.root.style.getPropertyValue('--comm-launcher-bottom'), '20px', visibility);
    }
    orb.remove(); f.component.measureLayout(); assert.equal(f.root.style.getPropertyValue('--comm-launcher-bottom'), '20px'); f.component.destroy();
});

test('mobile has only closed and fullscreen states and its launcher clears the bottom area menu', () => {
    const f = fixture({ width: 390, height: 844 });
    const menu = f.document.createElement('nav'); menu.setAttribute('data-area-menu', '');
    menu.testRect = { left: 0, top: 788, right: 390, bottom: 844, width: 390, height: 56 };
    f.document.body.append(menu); f.component.measureLayout();
    assert.equal(f.root.style.getPropertyValue('--comm-launcher-bottom'), '68px');
    f.component.show('mini'); f.flush();
    assert.equal(f.component.mode, 'fullscreen'); assert.equal(f.component.open, true);
    assert.equal(f.document.querySelector('#main-content').inert, true);
    f.finish(); f.component.resize(); f.flush();
    assert.equal(f.component.mode, 'fullscreen');
    f.event(f.document, 'livewire:navigating');
    assert.equal(f.component.mode, 'fullscreen');
    f.component.close(); f.flush(); f.finish();
    assert.match(view, /data-comm-resize x-show="wide"/);
    f.component.destroy();
});

test('native tabs retain arrow home end and cleanup removes global shell event handlers', () => {
    const f = fixture();
    for (const [start, key, end] of [['chats', 'ArrowRight', 'calls'], ['calls', 'End', 'meetings'], ['meetings', 'Home', 'chats']]) {
        const target = f.root.querySelector(`[data-comm-tab="${start}"]`); f.component.tabKey({ target, key, preventDefault() {} });
        assert.equal(f.component.tab, end); assert.equal(f.document.activeElement.dataset.commTab, end);
    }
    f.component.destroy(); f.emit('communication:call-ready'); assert.equal(f.component.activeCall, false);
});


test('hidden unified conversation never suppresses incoming notifications on desktop', () => {
    const f = fixture();
    const chat = f.document.querySelector('[data-communication-chat]'); chat.dataset.activeChatId = '17'; chat.dataset.mobilePane = 'chat';
    f.root.dataset.communicationOpen = 'false'; f.root.dataset.communicationTab = 'chats';
    assert.equal(activeVisibleChatId({ documentLike:f.document, windowLike:f.window }),0);
    f.root.dataset.communicationOpen = 'true';
    assert.equal(activeVisibleChatId({ documentLike:f.document, windowLike:f.window }),17);
    f.root.dataset.communicationTab='meetings';
    assert.equal(activeVisibleChatId({ documentLike:f.document, windowLike:f.window }),0);
    f.component.destroy();
});

test('cross-tab browser lock refuses a second media owner before mounting a call', async () => {
    const f=fixture(); const notices=[];
    f.window.addEventListener('swal:toast',e=>notices.push(e.detail));
    f.window.navigator={locks:{request:async (key,opts,callback)=>{assert.equal(opts.ifAvailable,true);return callback(null);}}};
    await f.component.requestCall('blocked-room');
    assert.equal(f.calls.length,0); assert.equal(notices.length,1); assert.equal(f.component.activeCall,false);
    f.component.destroy();
});

test('media ownership remains held through close and releases only on actual call release', async () => {
    const f=fixture(); let released=false;
    f.window.navigator={locks:{request:async (key,opts,callback)=>{await callback({name:key});released=true;}}};
    const held=f.component.requestCall('owned-room');
    await Promise.resolve(); f.emit('communication:call-ready'); f.flush(); f.finish();
    assert.equal(f.calls.length,1); f.component.close(); f.flush(); f.finish();
    assert.equal(released,false); assert.equal(f.component.activeCall,true);
    f.emit('communication:call-released'); await held;
    assert.equal(released,true); assert.equal(f.component.activeCall,false); f.component.destroy();
});

const appSource = await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
function nativeChatGestures() {
    const {document}=parseHTML('<div data-communication-chat="true"><button data-chat-swipe-id="17"><span id="row">Alex</span></button><button id="other">Action</button><input></div>');
    let component, now=1000, selection=''; const opened=[];
    const window={innerWidth:390,getSelection:()=>({toString:()=>selection})};
    const context=vm.createContext({Alpine:{data:(name,factory)=>{component=factory(false);}},window,document,localStorage:{getItem:()=>null},performance:{now:()=>now},readChatViewport:()=>({width:390,height:844,top:0})});
    const start=appSource.indexOf("Alpine.data('chatPaneNavigation'");
    const end=appSource.indexOf("Alpine.data('adminDashboardCharts'",start);
    vm.runInContext(appSource.slice(start,end),context);
    component.$root=document.querySelector('div');component.$wire={openChat:id=>opened.push(id)};
    const touch=(x,y,target=document.querySelector('#row'))=>({target,touches:[{clientX:x,clientY:y}],changedTouches:[{clientX:x,clientY:y}]});
    return {component,document,opened,touch,time:ms=>{now+=ms;},selection:text=>{selection=text;}};
}

test('native list touch-left selects the touched contact and suppresses its compatibility click',()=>{
    const f=nativeChatGestures();f.component.touchStart(f.touch(220,60));f.component.touchEnd(f.touch(100,62));
    assert.deepEqual(f.opened,[17]);let blocked=false;
    f.component.consumeSwipeClick({detail:1,pointerType:'touch',clientX:100,clientY:62,preventDefault:()=>{blocked=true;},stopImmediatePropagation:()=>{}});
    assert.equal(blocked,true);
});

test('native chat touch-right returns to list while vertical scroll and controls retain their actions',()=>{
    const f=nativeChatGestures();f.component.mobilePane='chat';
    f.component.touchStart(f.touch(100,60,f.document.querySelector('div')));f.component.touchEnd(f.touch(220,61));
    assert.equal(f.component.mobilePane,'list');
    f.component.touchStart(f.touch(220,60));f.component.touchMove(f.touch(216,100));f.component.touchEnd(f.touch(100,62));
    assert.deepEqual(f.opened,[]);
    f.component.touchStart(f.touch(220,60,f.document.querySelector('#other')));f.component.touchEnd(f.touch(100,62));
    assert.deepEqual(f.opened,[]);
});

test('native swipe rejects pinch long-press and selected message text',()=>{
    const f=nativeChatGestures();f.component.touchStart(f.touch(220,60));f.component.touchMove({touches:[{},{}]});f.component.touchEnd(f.touch(100,62));assert.deepEqual(f.opened,[]);
    f.component.touchStart(f.touch(220,60));f.time(700);f.component.touchEnd(f.touch(100,62));assert.deepEqual(f.opened,[]);
    f.selection('selected');f.component.touchStart(f.touch(220,60));f.component.touchEnd(f.touch(100,62));assert.deepEqual(f.opened,[]);
});
