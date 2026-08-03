import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    chatMessageActions,
    chatMessageGesture,
    shouldCancelChatLongPress,
} from '../../resources/js/chat-message-actions.js';

function installInteractionEnvironment() {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const originalElement = globalThis.Element;
    const timers = [];
    let expanded = 'false';
    let triggerClicks = 0;
    let focused = 0;

    const trigger = {
        click() {
            triggerClicks += 1;
            expanded = 'true';
        },
        getAttribute(name) {
            if (name === 'aria-expanded') return expanded;
            if (name === 'aria-controls') return 'message-menu';
            return null;
        },
    };

    globalThis.Element = class Element {};
    globalThis.document = {
        querySelector: () => trigger,
        getElementById: () => ({
            querySelector: () => ({
                querySelector: () => ({ focus() { focused += 1; } }),
            }),
        }),
    };
    globalThis.window = {
        clearTimeout() {},
        navigator: {},
        requestAnimationFrame(callback) { callback(); },
        setTimeout(callback) { timers.push(callback); return timers.length; },
    };

    return {
        timers,
        triggerClicks: () => triggerClicks,
        focused: () => focused,
        closeTrigger: () => { expanded = 'false'; },
        restore() {
            globalThis.window = originalWindow;
            globalThis.document = originalDocument;
            globalThis.Element = originalElement;
        },
    };
}

test('ordinary click and long press route through the shared message dropdown', () => {
    const environment = installInteractionEnvironment();

    try {
        const actions = chatMessageActions({ messageId: 42, controllerId: 'chat-42', canReact: true });
        actions.$nextTick = (callback) => callback();

        actions.handleClick({
            preventDefault() {},
            stopPropagation() {},
            target: null,
        });
        assert.equal(actions.menuMode, 'actions');
        assert.equal(environment.triggerClicks(), 1);
        assert.equal(environment.focused(), 1);

        actions.menuMode = 'reactions';
        actions.showActionMenu();
        assert.equal(actions.menuMode, 'actions');
        assert.equal(environment.focused(), 2);

        environment.closeTrigger();
        actions.startLongPress({
            pointerType: 'touch',
            pointerId: 7,
            clientX: 60,
            clientY: 70,
            target: null,
        });
        environment.timers.shift()();
        assert.equal(actions.menuMode, 'reactions');
        assert.equal(actions.showMore, false);
        assert.equal(environment.triggerClicks(), 2);
    } finally {
        environment.restore();
    }
});

test('own-message long press opens useful message actions', () => {
    const environment = installInteractionEnvironment();

    try {
        const actions = chatMessageActions({ messageId: 7, controllerId: 'chat-7', canReact: false });
        actions.$nextTick = (callback) => callback();
        actions.startLongPress({ pointerType: 'touch', pointerId: 1, clientX: 20, clientY: 30, target: null });
        environment.timers.shift()();

        assert.equal(actions.menuMode, 'actions');
        assert.equal(environment.triggerClicks(), 1);
        assert.ok(actions.suppressClickUntil > Date.now());
    } finally {
        environment.restore();
    }
});

test('message long press uses the locked 500ms and 10px contract', () => {
    assert.equal(chatMessageGesture.longPressDelayMs, 500);
    assert.equal(chatMessageGesture.moveTolerancePx, 10);
    assert.equal(shouldCancelChatLongPress(10, 10, 16, 18), false);
    assert.equal(shouldCancelChatLongPress(10, 10, 21, 10), true);
});

test('chat and call transcripts use the same anchored dropdown components', async () => {
    const [transcript, callChat] = await Promise.all([
        readFile(new URL('../../resources/views/livewire/chat/partials/transcript.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/calls/call-chat.blade.php', import.meta.url), 'utf8'),
    ]);

    for (const source of [transcript, callChat]) {
        assert.match(source, /<x-chat\.message-dropdown/);
        assert.match(source, /<x-chat\.reaction-dropdown/);
        assert.match(source, /rt-chat-reactions--overlay/);
        assert.match(source, /x-on:contextmenu\.stop="openActionsAtPointer\(\$event\)"/);
        assert.doesNotMatch(source, /actionX|actionY|menuStyle|x-show="actionOpen"|x-show="reactionOpen"/);
        assert.doesNotMatch(source, /wire:click="(?:toggleReaction|react)\(/);
    }

    assert.match(callChat, /chatMessageActions\(/);
    assert.doesNotMatch(callChat, /\$allReactions\s*=/);
});

test('shared message dropdown shows one quick row and a genuinely collapsed extension', async () => {
    const source = await readFile(
        new URL('../../resources/views/components/chat/message-dropdown.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(source, /<x-ui\.dropdown\.anchor-dropdown/);
    assert.match(source, /array_filter\(/);
    assert.match(source, /data-chat-quick-reaction-row/);
    assert.match(source, /rt-chat-quick-reactions-track/);
    assert.match(source, /rt-chat-reaction-grid/);
    assert.match(source, /data-chat-expanded-reactions/);
    assert.match(source, /x-show="showMore" style="display:none;"/);
    assert.match(source, /data-rt-dropdown-keep-open/);
    assert.match(source, /wire:click="\{\{ \$reactMethod \}\}/);
    assert.doesNotMatch(source, /toggleReaction/);
});

test('reaction chips open an anchored change/remove menu without direct toggle', async () => {
    const source = await readFile(
        new URL('../../resources/views/components/chat/reaction-dropdown.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(source, /<x-ui\.dropdown\.anchor-dropdown/);
    assert.match(source, /chat_change_reaction/);
    assert.match(source, /chat_remove_reaction/);
    assert.match(source, /wire:click="\{\{ \$removeMethod \}\}/);
    assert.match(source, /wire:key="\{\{ \$resolvedId \}\}"/);
    assert.doesNotMatch(source, /toggleReaction/);
});

test('shared dropdown keeps in-panel expansion controls open', async () => {
    const source = await readFile(
        new URL('../../resources/views/components/ui/dropdown/anchor-dropdown.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(source, /data-rt-dropdown-keep-open/);
    assert.match(source, /data-rt-dropdown-caret/);
    assert.match(source, /x-teleport="body"/);
});

test('rendered reaction emojis stay transparent while the rail overlaps the bubble', async () => {
    const source = await readFile(
        new URL('../../resources/css/chat-redesign.css', import.meta.url),
        'utf8',
    );
    const chipRule = source.match(/\.rt-chat-reaction-chip\s*\{([\s\S]*?)\}/)?.[1] || '';
    const overlayRule = source.match(/\.rt-chat-reactions--overlay\s*\{([\s\S]*?)\}/)?.[1] || '';

    assert.match(chipRule, /border:\s*0/);
    assert.match(chipRule, /theme\('colors\.rt\.text'\)/);
    assert.match(chipRule, /background:\s*transparent/);
    assert.match(chipRule, /box-shadow:\s*none/);
    assert.match(overlayRule, /position:\s*absolute/);
    assert.match(overlayRule, /bottom:\s*0/);
    assert.match(overlayRule, /overflow-x:\s*auto/);
    assert.match(source, /\[data-chat-message-action-trigger\]\[aria-expanded='true'\]/);
});
