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
            expanded = expanded === 'true' ? 'false' : 'true';
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
        expanded: () => expanded,
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

        actions.handleClick({
            preventDefault() {},
            stopPropagation() {},
            target: null,
        });
        assert.equal(environment.triggerClicks(), 2);
        assert.equal(environment.expanded(), 'false');

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
        assert.equal(environment.triggerClicks(), 3);
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

test('the focused bubble itself toggles message actions from the keyboard', () => {
    const environment = installInteractionEnvironment();

    try {
        const actions = chatMessageActions({ messageId: 9, controllerId: 'chat-9', canReact: true });
        const bubble = {};
        let prevented = 0;
        actions.$nextTick = (callback) => callback();

        actions.handleKeyboard({
            target: bubble,
            currentTarget: bubble,
            key: 'Enter',
            shiftKey: false,
            preventDefault() { prevented += 1; },
        });
        assert.equal(environment.expanded(), 'true');

        actions.handleKeyboard({
            target: bubble,
            currentTarget: bubble,
            key: ' ',
            shiftKey: false,
            preventDefault() { prevented += 1; },
        });
        assert.equal(environment.expanded(), 'false');
        assert.equal(environment.triggerClicks(), 2);
        assert.equal(prevented, 2);
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
    assert.match(transcript, /trigger-variant="bubble"/);
    assert.doesNotMatch(transcript, /rt-chat-message-actions|trigger-variant="caret"/);
});

test('shared message dropdown shows one quick row and a genuinely collapsed extension', async () => {
    const source = await readFile(
        new URL('../../resources/views/components/chat/message-dropdown.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(source, /<x-ui\.dropdown\.anchor-dropdown/);
    assert.match(source, /'triggerVariant'\s*=>\s*'bubble'/);
    assert.match(source, /:anchor-selector="\$bubbleTrigger \? '\[data-rt-chat-message\]' : null"/);
    assert.match(source, /'sr-only'\s*=>\s*\$bubbleTrigger/);
    assert.doesNotMatch(source, /rt-chat-message-caret/);
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
    assert.match(source, /resolvePositionAnchor\(\)/);
    assert.match(source, /clearExternalAnchorAccessibility\(\)/);
    assert.match(source, /positionObserver\.observe\(positionAnchor\)/);
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
    assert.match(overlayRule, /bottom:\s*-1\.35rem/);
    assert.match(overlayRule, /overflow-x:\s*auto/);
    assert.match(source, /\[data-chat-message-action-trigger\]\[aria-expanded='true'\]/);
    assert.doesNotMatch(source, /\.rt-chat-message-(?:actions|caret)/);
});

test('premium chat hierarchy keeps real presence, group identity, and external message metadata', async () => {
    const [chatBox, list, header, transcript] = await Promise.all([
        readFile(new URL('../../app/Livewire/ChatBox.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/chat/partials/chat-list.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/chat/partials/conversation-header.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/chat/partials/transcript.blade.php', import.meta.url), 'utf8'),
    ]);

    assert.match(chatBox, /withMax\('activities as last_activity_at', 'created_at'\)/);
    assert.match(list, /\$previewPersonIsOnline\s*=\s*\$previewPerson\?->isOnline\(\)/);
    assert.match(list, /:signal="\$previewPersonIsOnline"/);
    assert.match(header, /\$headerIsOnline\s*=\s*\$headerPerson\?->isOnline\(\)/);
    assert.match(header, /data-chat-presence="\{\{ \$headerIsOnline \? 'online' : 'offline' \}\}"/);
    assert.doesNotMatch(header, /rt-chat-live-status/);

    assert.match(transcript, /@if \(\$selectedChat->isGroup\(\)\)[\s\S]*?<x-chat\.avatar/);
    assert.match(transcript, /rt-chat-message-bubble-wrap/);
    assert.match(transcript, /rt-chat-message-meta--\{\{ \$own \? 'own' : 'other' \}\}/);
    assert.doesNotMatch(transcript, /metaInline|rt-chat-message-meta-inline/);
});

test('typing feedback has one live region, preserves scroll position, and renders decorative dots', async () => {
    const [header, transcript] = await Promise.all([
        readFile(new URL('../../resources/views/livewire/chat/partials/conversation-header.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/chat/partials/transcript.blade.php', import.meta.url), 'utf8'),
    ]);

    assert.match(transcript, /data-chat-typing-indicator/);
    assert.match(transcript, /x-effect="if \(typingLabel && stickToBottom\)/);
    assert.match(transcript, /aria-hidden="true"/);
    assert.doesNotMatch(transcript, /role="status"/);
    assert.doesNotMatch(transcript, /aria-live="polite"/);
    assert.match(header, /aria-live="polite"/);
    assert.match(transcript, /rt-chat-typing-bubble[\s\S]*?<span><\/span>[\s\S]*?<span><\/span>[\s\S]*?<span><\/span>/);
});

test('composer preserves every input path in the detached premium control layout', async () => {
    const composer = await readFile(
        new URL('../../resources/views/livewire/chat/partials/composer.blade.php', import.meta.url),
        'utf8',
    );

    assert.match(composer, /data-chat-composer-attachment/);
    assert.match(composer, /x-ref="attachmentInput"/);
    assert.match(composer, /x-ref="attachmentInput"[\s\S]*?tabindex="-1"/);
    assert.match(composer, /@click="\$refs\.attachmentInput\.click\(\)"/);
    assert.match(composer, /aria-label="\{\{ __\('app\.add_attachment'\) \}\}"/);
    assert.match(composer, /<x-chat\.live-location-share/);
    assert.match(composer, /@click="startRecording\(\)"/);
    assert.match(composer, /data-chat-input-capsule/);
    assert.match(composer, /data-chat-send-button/);
    assert.match(composer, /x-show\.important="draft\.trim\(\)\.length > 0/);
    assert.match(composer, /wire:loading\.attr="disabled"/);
    assert.match(composer, /rounded-full/);
    assert.match(composer, /__\('app\.send_message'\)/);
});

test('reference motion and geometry stay compact, branded, and reduced-motion safe', async () => {
    const [styles, app] = await Promise.all([
        readFile(new URL('../../resources/css/chat-redesign.css', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8'),
    ]);

    const lineRule = styles.match(/\.rt-chat-message-line\s*\{([\s\S]*?)\}/)?.[1] || '';
    const ownRule = styles.match(/\.rt-chat-message--own,[\s\S]*?\{([\s\S]*?)\}/)?.[1] || '';
    const transcriptRule = styles.match(/\.rt-chat-transcript,[\s\S]*?\{([\s\S]*?)\}/)?.[1] || '';

    assert.match(lineRule, /max-width:\s*78%/);
    assert.match(styles, /\.rt-chat-message-line--group\s*\{[\s\S]*?align-items:\s*flex-start/);
    assert.match(ownRule, /linear-gradient\(135deg, var\(--chat-bubble-own-start\), var\(--chat-bubble-own-end\)\)/);
    assert.match(transcriptRule, /background-image:\s*none/);
    assert.match(styles, /@keyframes rt-chat-typing-dot/);
    assert.match(styles, /prefers-reduced-motion:[\s\S]*?\.rt-chat-typing-bubble > span/);
    assert.match(styles, /--chat-online-text:\s*#15803d/);
    assert.match(styles, /--chat-bubble-own-start:\s*#d5274f/);
    assert.match(styles, /\.rt-chat-header-action\s*\{[\s\S]*?border-radius:\s*9999px/);
    assert.match(styles, /\.rt-chat-options-trigger\s*\{[\s\S]*?border-radius:\s*9999px !important/);
    assert.match(styles, /\.rt-chat-options-trigger\s*\{[\s\S]*?width:\s*2\.75rem !important[\s\S]*?height:\s*2\.75rem !important/);
    assert.match(app, /\{ autoAlpha: 0, y: 6, scale: 0\.98 \}/);
    assert.match(app, /duration:\s*0\.18/);
    assert.match(app, /stagger:\s*0\.025/);
});

test('premium stacked lists are shared by the topbar, chat sidebar, and creation pickers', async () => {
    const [component, inbox, list, picker, publicInfo, styles, headerInbox, chatBox] = await Promise.all([
        readFile(new URL('../../resources/views/components/chat/stacked-list-item.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/tools/header-inbox.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/chat/partials/chat-list.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/livewire/chat/partials/member-picker.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/views/components/user/public-info.blade.php', import.meta.url), 'utf8'),
        readFile(new URL('../../resources/css/chat-redesign.css', import.meta.url), 'utf8'),
        readFile(new URL('../../app/Livewire/Tools/HeaderInbox.php', import.meta.url), 'utf8'),
        readFile(new URL('../../app/Livewire/ChatBox.php', import.meta.url), 'utf8'),
    ]);

    assert.match(component, /data-chat-stacked-item/);
    assert.match(component, /rt-chat-stacked-item__avatar/);
    assert.match(component, /rt-chat-stacked-item__context/);
    assert.match(component, /rt-chat-stacked-item__meta/);
    assert.match(component, /rt-chat-stacked-item__rail/);
    assert.match(component, /fa-chevron-right/);

    assert.equal((inbox.match(/<x-chat\.stacked-list-item/g) || []).length, 2);
    assert.match(inbox, /data-rt-inbox-premium-list/);
    assert.match(inbox, /rt-chat-stacked-list--inbox/);
    assert.match(list, /<x-chat\.stacked-list-item/);
    assert.match(list, /__\('app\.group_chat'\)/);
    assert.match(list, /__\('app\.direct_chat'\)/);

    assert.match(picker, /rt-chat-stacked-surface/);
    assert.match(picker, /rt-chat-member-list/);
    assert.match(picker, /:show-context="true"/);
    assert.match(picker, /:show-presence="true"/);
    assert.match(picker, /<\/x-user\.person-anchor-preview>[\s\S]*?<x-ui\.forms\.checkbox/);
    assert.match(publicInfo, /rt-user-public-info__context/);

    assert.match(headerInbox, /withMax\('activities as last_activity_at', 'created_at'\)/);
    assert.ok((chatBox.match(/withMax\('activities as last_activity_at', 'created_at'\)/g) || []).length >= 3);
    assert.match(styles, /\.rt-chat-stacked-item__avatar/);
    assert.match(styles, /\.rt-chat-stacked-item\.is-active \.rt-chat-stacked-item__rail/);
    assert.match(styles, /\.rt-chat-member-row\.is-selected::before/);
    assert.match(styles, /cubic-bezier\(0\.16, 1, 0\.3, 1\)/);
    assert.match(styles, /prefers-reduced-motion:[\s\S]*?\.rt-chat-stacked-item/);
});
