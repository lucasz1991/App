import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { parseHTML } from 'linkedom';

const settle = () => new Promise(resolve => setTimeout(resolve, 25));
const appSource = await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8');
const blade = await readFile(new URL('../../resources/views/livewire/chat-box.blade.php', import.meta.url), 'utf8');
const nativeDataExpression = blade.match(/x-data="([^"]+)"/)[1];
const start = appSource.indexOf("Alpine.data('chatPaneNavigation'");
const end = appSource.indexOf("Alpine.data('adminDashboardCharts'", start);

async function fixture({ changingExpression = false, selected = false } = {}) {
    const { window, document } = parseHTML('<html><head></head><body></body></html>');
    delete window.Livewire;
    delete window.Alpine;
    window.livewireScriptConfig = {};
    window.innerWidth = 1770;
    window.requestAnimationFrame = callback => setTimeout(callback, 0);
    window.cancelAnimationFrame = clearTimeout;
    const context = vm.createContext({
        window, document,
        MutationObserver: window.MutationObserver,
        Element: window.Element, HTMLElement: window.HTMLElement,
        Node: window.Node, ShadowRoot: window.ShadowRoot,
        Event: window.Event, CustomEvent: window.CustomEvent,
        customElements: window.customElements,
        navigator: { userAgent: 'isolated-chat-morph-test' },
        location: { href: 'http://localhost/fixture' },
        URL, AbortController, DOMException,
        setTimeout, clearTimeout, setInterval, clearInterval, queueMicrotask,
        requestAnimationFrame: window.requestAnimationFrame,
        cancelAnimationFrame: clearTimeout,
        localStorage: { getItem: () => null, setItem() {} },
        performance,
        readChatViewport: () => ({ width: 1770, height: 1252, top: 0 }),
        rtChatViewportState: { stableHeight: 0, stableWidth: 0, keyboardOpen: false },
        console,
        fetch() { throw new Error('No network is permitted in the chat morph fixture.'); },
    });
    const vendor = (await readFile(new URL('../../vendor/livewire/livewire/dist/livewire.esm.js', import.meta.url), 'utf8'))
        .replace(/export\s*\{\s*export_Alpine as Alpine,\s*Livewire2 as Livewire\s*\};/, '')
        + '\nwindow.Alpine.plugin(import_morph.default); window.__testMorph = morph2;';
    vm.runInContext(vendor, context, { filename: 'installed-livewire.esm.js' });
    const Alpine = window.Alpine;
    const lifecycle = { initialized: 0, destroyed: 0 };
    const register = Alpine.data.bind(Alpine);
    Alpine.data = (name, factory) => register(name, (...args) => {
        const state = factory(...args);
        const initialize = state.init;
        const destroy = state.destroy;
        state.init = function () { lifecycle.initialized += 1; return initialize.call(this); };
        state.destroy = function () { lifecycle.destroyed += 1; return destroy.call(this); };
        return state;
    });
    context.Alpine = Alpine;
    vm.runInContext(appSource.slice(start, end), context, { filename: 'native-chat-pane-navigation.js' });

    const html = (hasSelection, chatId = 17) => {
        const expression = changingExpression
            ? `chatPaneNavigation(${hasSelection})`
            : nativeDataExpression.replace('@js((bool) $selectedChat)', String(hasSelection));
        return `<div wire:id="chat-fixture" x-data="${expression}"
            data-communication-chat="true" data-has-selected-chat="${hasSelection}"
            data-active-chat-id="${hasSelection ? chatId : 0}"
            data-mobile-pane="${hasSelection ? 'chat' : 'list'}" x-bind:data-mobile-pane="mobilePane"
            x-on:chat:pane-open.window="showChat()" x-on:chat:pane-list.window="showList()">
            <div id="list" x-bind:inert="mobilePane !== 'list'">
                <button id="chat-17" x-on:click="showChat()">Alex</button>
                <button id="chat-28" x-on:click="showChat()">Sam</button>
            </div>
            <div id="conversation" x-bind:inert="mobilePane !== 'chat'">
                <button id="back" x-on:click="showList()">Back</button>
                <span id="selected-chat">${hasSelection ? chatId : ''}</span>
            </div>
        </div>`;
    };
    document.body.innerHTML = html(selected);
    const root = document.body.firstElementChild;
    Alpine.start();
    await settle();
    const component = { id: 'chat-fixture', snapshotEncoded: '{}', effects: {} };
    return {
        root, document, lifecycle,
        click(id) { document.getElementById(id).dispatchEvent(new window.Event('click', { bubbles: true })); },
        async opened(chatId = 17) {
            await settle();
            window.dispatchEvent(new window.CustomEvent('chat:pane-open'));
            window.__testMorph(component, root, html(true, chatId));
            await settle();
        },
        async visibilityResponse(chatId = 17) {
            window.__testMorph(component, root, html(true, chatId));
            await settle();
        },
        cleanup() {
            Alpine.stopObservingMutations();
            Alpine.destroyTree(document.body);
        },
    };
}

test('the previous changing x-data expression destroys the native chat controller during selection morph', async () => {
    const sample = await fixture({ changingExpression: true });
    try {
        sample.click('chat-17');
        await sample.opened();
        assert.equal(sample.lifecycle.destroyed, 1);
        assert.equal(sample.lifecycle.initialized, 2);
    } finally {
        sample.cleanup();
    }
});

test('native chat list reopens the same and another chat after repeated back and Livewire morph cycles', async () => {
    const sample = await fixture();
    try {
        for (const chatId of [17, 17, 28, 17]) {
            sample.click(`chat-${chatId}`);
            await sample.opened(chatId);
            assert.equal(sample.root.dataset.mobilePane, 'chat', `conversation ${chatId} is visible`);
            assert.equal(sample.document.getElementById('conversation').hasAttribute('inert'), false);
            assert.equal(sample.document.getElementById('list').hasAttribute('inert'), true);
            assert.equal(sample.document.getElementById('selected-chat').textContent, String(chatId));
            assert.deepEqual(sample.lifecycle, { initialized: 1, destroyed: 0 });
            sample.click('back');
            await settle();
            await sample.visibilityResponse(chatId);
            assert.equal(sample.root.dataset.mobilePane, 'list');
            assert.equal(sample.document.getElementById('list').hasAttribute('inert'), false);
            assert.equal(sample.document.getElementById('conversation').hasAttribute('inert'), true);
        }
    } finally {
        sample.cleanup();
    }
});

test('native initial chat selection still starts in the conversation pane', async () => {
    const sample = await fixture({ selected: true });
    try {
        assert.equal(sample.root.dataset.mobilePane, 'chat');
        assert.equal(sample.document.getElementById('conversation').hasAttribute('inert'), false);
    } finally {
        sample.cleanup();
    }
});
