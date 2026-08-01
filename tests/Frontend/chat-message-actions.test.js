import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

import {
    chatMessageActions,
    chatMessageGesture,
    clampChatMenuPosition,
    shouldCancelChatLongPress,
} from '../../resources/js/chat-message-actions.js';

test('ordinary click and long press open separate menus', () => {
    const originalWindow = globalThis.window;
    const originalElement = globalThis.Element;
    const timers = [];
    globalThis.Element = class Element {};
    globalThis.window = {
        clearTimeout() {},
        navigator: {},
        requestAnimationFrame(callback) { callback(); },
        setTimeout(callback) { timers.push(callback); return timers.length; },
    };

    try {
        const actions = chatMessageActions({ canReact: true });
        actions.$nextTick = (callback) => callback();
        actions.positionMenu = () => {};
        actions.$refs = {
            messageBubble: {
                getBoundingClientRect: () => ({ left: 10, top: 20, width: 100, height: 60 }),
            },
        };

        actions.handleClick({
            clientX: 40,
            clientY: 50,
            detail: 1,
            target: null,
        });
        assert.equal(actions.actionOpen, true);
        assert.equal(actions.reactionOpen, false);

        actions.close();
        actions.startLongPress({
            pointerType: 'touch',
            pointerId: 7,
            clientX: 60,
            clientY: 70,
            target: null,
        });
        timers.shift()();
        assert.equal(actions.actionOpen, false);
        assert.equal(actions.reactionOpen, true);
        assert.equal(actions.showMore, false);
    } finally {
        globalThis.window = originalWindow;
        globalThis.Element = originalElement;
    }
});

test('own-message long press suppresses the click without opening reactions', () => {
    const originalWindow = globalThis.window;
    const originalElement = globalThis.Element;
    let scheduled = null;
    globalThis.Element = class Element {};
    globalThis.window = {
        clearTimeout() {},
        setTimeout(callback) { scheduled = callback; return 1; },
    };

    try {
        const actions = chatMessageActions({ canReact: false });
        actions.startLongPress({ pointerType: 'touch', pointerId: 1, clientX: 20, clientY: 30, target: null });
        assert.equal(typeof scheduled, 'function');
        scheduled();
        assert.equal(actions.reactionOpen, false);
        assert.ok(actions.suppressClickUntil > Date.now());
    } finally {
        globalThis.window = originalWindow;
        globalThis.Element = originalElement;
    }
});

test('message long press uses the locked 500ms and 10px contract', () => {
    assert.equal(chatMessageGesture.longPressDelayMs, 500);
    assert.equal(chatMessageGesture.moveTolerancePx, 10);
    assert.equal(shouldCancelChatLongPress(10, 10, 16, 18), false);
    assert.equal(shouldCancelChatLongPress(10, 10, 21, 10), true);
});

test('context menu positioning remains inside the visual viewport gap', () => {
    assert.equal(clampChatMenuPosition(-20, 12, 300), 12);
    assert.equal(clampChatMenuPosition(180, 12, 300), 180);
    assert.equal(clampChatMenuPosition(420, 12, 300), 300);
    assert.equal(clampChatMenuPosition(20, 50, 20), 50);
});

test('transcript separates click actions from long-press reactions with accessible targets', async () => {
    const source = await readFile(
        new URL('../../resources/views/livewire/chat/partials/transcript.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(source, /x-on:click="handleClick\(\$event\)"/);
    assert.match(source, /x-on:contextmenu\.stop="openActionsAtPointer\(\$event\)"/);
    assert.match(source, /x-on:pointercancel="cancelLongPress\(\)"/);
    assert.match(source, /handleKeyboard\(\$event\)/);
    assert.match(source, /x-show="actionOpen"/);
    assert.match(source, /x-show="reactionOpen"/);
    assert.match(source, /openReactionsFromActionMenu\(\)/);
    assert.match(source, /fa-chevron-down/);
    assert.doesNotMatch(source, /x-show="actionOpen"[\s\S]{0,800}chat_quick_reactions/);
    assert.match(source, /wire:click="beginReply/);
    assert.match(source, /wire:click="toggleReaction/);
    assert.match(source, /min-h-11 min-w-11/);
});

test('call chat uses the same split menus and renders history interactions passively', async () => {
    const source = await readFile(
        new URL('../../resources/views/livewire/calls/call-chat.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(source, /@unless \(\$readOnly\)[\s\S]*wire:poll\.5s="refreshMessages"/);
    assert.match(source, /handleMessageClick\(\$event/);
    assert.match(source, /this\.openReactions\(event, id, mine, true\)/);
    assert.match(source, /if \(!mine\) this\.openReactions\(event, id, mine, true\)/);
    assert.match(source, /x-show="actionOpen"/);
    assert.match(source, /x-show="reactionOpen"/);
    assert.match(source, /x-on:click\.outside="closeMenus\(\)"/);
    assert.match(source, /fa-chevron-down/);
    assert.match(source, /@if \(\$readOnly \|\| \$mine\)/);
    assert.match(source, /@if \(\$reactions->count\(\) > 1\)/);
});

test('rendered reaction emojis have no chip background or frame', async () => {
    const source = await readFile(
        new URL('../../resources/css/chat-redesign.css', import.meta.url),
        'utf8',
    );
    const chipRule = source.match(/\.rt-chat-reaction-chip\s*\{([\s\S]*?)\}/)?.[1] || '';

    assert.match(chipRule, /border:\s*0/);
    assert.match(chipRule, /background:\s*transparent/);
    assert.match(chipRule, /box-shadow:\s*none/);
});
