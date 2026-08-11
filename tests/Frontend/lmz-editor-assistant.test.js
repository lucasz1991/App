import assert from 'node:assert/strict';
import test, { after, afterEach } from 'node:test';

class TestCustomEvent extends Event {
    constructor(type, options = {}) {
        super(type);
        this.detail = options.detail;
    }
}

const originalWindow = globalThis.window;
const originalDocument = globalThis.document;
const originalCustomEvent = globalThis.CustomEvent;
const testWindow = new EventTarget();
const rootClasses = new Set();

Object.defineProperty(globalThis, 'window', { configurable: true, writable: true, value: testWindow });
Object.defineProperty(globalThis, 'document', {
    configurable: true,
    writable: true,
    value: {
        documentElement: {
            classList: {
                toggle(name, enabled) {
                    if (enabled) rootClasses.add(name);
                    else rootClasses.delete(name);
                },
            },
        },
    },
});
Object.defineProperty(globalThis, 'CustomEvent', { configurable: true, writable: true, value: TestCustomEvent });

const {
    acquirePageBuilderAssistantActionDispatcher,
    normalizePageBuilderAssistantContext,
    publishPageBuilderAssistantContext,
    registerPageBuilderAssistantAdapter,
    schedulePageBuilderAssistantContextPublish,
    unregisterPageBuilderAssistantAdapter,
} = await import('../../resources/js/lmz-editor-assistant.js');
const dispatchPageBuilderAssistantAction = acquirePageBuilderAssistantActionDispatcher();

afterEach(() => {
    unregisterPageBuilderAssistantAdapter();
    rootClasses.clear();
});

after(() => {
    if (originalWindow === undefined) delete globalThis.window;
    else globalThis.window = originalWindow;
    if (originalDocument === undefined) delete globalThis.document;
    else globalThis.document = originalDocument;
    if (originalCustomEvent === undefined) delete globalThis.CustomEvent;
    else globalThis.CustomEvent = originalCustomEvent;
});

function context(overrides = {}) {
    const base = {
        version: 1,
        route_name: 'admin.marketing.creatives.editor',
        mode: 'marketing',
        resource_id: '10000000-0000-4000-8000-000000000001',
        format_or_kind: 'story',
        workspace_nonce: 'workspace_nonce_1234567890',
        fullscreen_open: true,
        editor_ready: true,
        read_only: false,
        persisted_content_hash: 'a'.repeat(64),
        persisted_version: 3,
        client_revision: 18,
        unsaved: true,
        selection: {
            selection_nonce: 'selection_nonce_1234567890',
            fingerprint: 'b'.repeat(64),
            tag: 'p',
            block_id: 'rt-marketing-headline',
            text: 'Wagenmeister gesucht',
            styles: { 'font-size': '64px' },
            protected: false,
            motion_allowed: true,
            gif: false,
        },
        capabilities: [
            'open_fullscreen', 'open_panel', 'focus_selection', 'edit_text', 'set_style',
            'replace_image', 'add_block', 'undo', 'redo', 'preview', 'save',
            'animation', 'gif_preview',
        ],
        available_block_ids: ['rt-marketing-headline', 'rt-marketing-hero'],
        validation: { state: 'valid', issues: [] },
    };

    return { ...base, ...overrides };
}

function action(command, overrides = {}) {
    const active = context();
    return {
        type: 'pagebuilder',
        action_token: 'T'.repeat(48),
        route_name: active.route_name,
        mode: active.mode,
        command,
        resource_id: active.resource_id,
        format_or_kind: active.format_or_kind,
        workspace_nonce: active.workspace_nonce,
        persisted_content_hash: active.persisted_content_hash,
        persisted_version: active.persisted_version,
        client_revision: active.client_revision,
        ...overrides,
    };
}

function selectedAction(command, overrides = {}) {
    const active = context();
    return action(command, {
        selection_nonce: active.selection.selection_nonce,
        selection_fingerprint: active.selection.fingerprint,
        ...overrides,
    });
}

function nextResult() {
    return new Promise((resolve) => {
        testWindow.addEventListener('railtime-pagebuilder-assistant-result', (event) => resolve(event.detail), { once: true });
    });
}

test('normalizes a bounded value-free context and protects mail structure', () => {
    const safe = normalizePageBuilderAssistantContext({
        ...context(),
        html: '<script>alert(1)</script>',
        css: 'body{}',
        selection: {
            ...context().selection,
            styles: {
                'font-size': '64px',
                position: 'fixed',
                color: 'url(https://evil.test/a.png)',
            },
        },
        capabilities: [...context().capabilities, 'publish', 'export'],
        available_block_ids: [...context().available_block_ids, 'evil-block'],
    });

    assert.equal(safe.client_revision, 18);
    assert.equal(safe.persisted_version, 3);
    assert.deepEqual(safe.selection.styles, { 'font-size': '64px' });
    assert.equal(Object.hasOwn(safe, 'html'), false);
    assert.equal(Object.hasOwn(safe, 'css'), false);
    assert.equal(safe.capabilities.includes('publish'), false);
    assert.equal(safe.available_block_ids.includes('evil-block'), false);

    const mail = normalizePageBuilderAssistantContext({
        ...context(),
        route_name: 'admin.mail-documents.editor',
        mode: 'mail',
        format_or_kind: 'signature',
        selection: {
            ...context().selection,
            block_id: 'rt-mail-signature',
            text: '{{VORNAME_NACHNAME}}',
            motion_allowed: true,
            gif: true,
        },
        capabilities: [...context().capabilities, 'replace_image'],
        available_block_ids: ['rt-mail-section', 'rt-mail-image'],
    });

    assert.equal(mail.selection.protected, true);
    assert.equal(mail.selection.motion_allowed, false);
    assert.equal(mail.selection.gif, true);
    assert.equal(mail.capabilities.includes('replace_image'), false);
    assert.deepEqual(mail.available_block_ids, ['rt-mail-section']);
});

test('rechecks the local client revision immediately before a confirmed command', async () => {
    let active = context({ client_revision: 19 });
    let saveCalls = 0;
    registerPageBuilderAssistantAdapter({
        getContext: () => active,
        save: () => { saveCalls += 1; return true; },
    });

    const receipt = nextResult();
    assert.equal(dispatchPageBuilderAssistantAction(action('save')), true);
    assert.deepEqual(await receipt, { action_token: 'T'.repeat(48), status: 'stale_context' });
    assert.equal(saveCalls, 0);

    active = context();
    const appliedReceipt = nextResult();
    assert.equal(dispatchPageBuilderAssistantAction(action('save', { action_token: 'U'.repeat(48) })), true);
    assert.deepEqual(await appliedReceipt, { action_token: 'U'.repeat(48), status: 'applied' });
    assert.equal(saveCalls, 1);
});

test('coalesces rapid editor updates while an explicit context request remains immediate', async () => {
    let reads = 0;
    registerPageBuilderAssistantAdapter({
        getContext() {
            reads += 1;
            return context({ client_revision: reads });
        },
    });
    await new Promise((resolve) => setTimeout(resolve, 0));
    const afterRegister = reads;

    schedulePageBuilderAssistantContextPublish(15);
    schedulePageBuilderAssistantContextPublish(15);
    schedulePageBuilderAssistantContextPublish(15);
    await new Promise((resolve) => setTimeout(resolve, 35));
    assert.equal(reads, afterRegister + 1);

    await publishPageBuilderAssistantContext();
    assert.equal(reads, afterRegister + 2);
});

test('rechecks selection fingerprint and passes only normalized method arguments', async () => {
    let active = context();
    const calls = [];
    registerPageBuilderAssistantAdapter({
        getContext: () => active,
        setStyle(property, value) {
            calls.push([property, value]);
            active = { ...active, client_revision: active.client_revision + 1 };
            return true;
        },
    });

    const receipt = nextResult();
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('set_style', {
        property: 'font-size',
        value: '72px',
        css: 'position:fixed',
        url: 'https://evil.test',
    })), true);
    assert.deepEqual(await receipt, { action_token: 'T'.repeat(48), status: 'applied' });
    assert.deepEqual(calls, [['font-size', '72px']]);

    active = {
        ...context(),
        selection: { ...context().selection, fingerprint: 'c'.repeat(64) },
    };
    const staleReceipt = nextResult();
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('set_style', {
        action_token: 'U'.repeat(48),
        property: 'font-size', value: '70px',
    })), true);
    assert.equal((await staleReceipt).status, 'stale_context');
    assert.equal(calls.length, 1);
});

test('rejects raw HTML, unsafe CSS, mail image replacement and invented GIF frame controls', () => {
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('edit_text', {
        text: '<b>nicht erlaubt</b>',
    })), false);
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('edit_text', {
        text: '&#123;&#123;EMPFAENGER_E_MAIL&#125;&#125;',
    })), false);
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('set_style', {
        property: 'background-color', value: 'url(https://evil.test/a.png)',
    })), false);
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('set_animation', {
        field: 'framerate', value: 60,
    })), false);

    const mail = context({
        route_name: 'admin.mail-documents.editor',
        mode: 'mail',
        format_or_kind: 'template',
    });
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('replace_image', {
        route_name: mail.route_name,
        mode: mail.mode,
        format_or_kind: mail.format_or_kind,
        file_id: 4,
    })), false);
});

test('GIF restart is limited to a fresh GIF selection and fullscreen state raises the one chatbot layer', async () => {
    let active = context({
        selection: { ...context().selection, gif: false },
    });
    let restarts = 0;
    const adapter = {
        getContext: () => active,
        restartGifPreview() { restarts += 1; return true; },
    };
    registerPageBuilderAssistantAdapter(adapter);
    assert.equal(rootClasses.has('rt-pagebuilder-fullscreen-open'), false);

    const staleReceipt = nextResult();
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('restart_gif')), true);
    assert.equal((await staleReceipt).status, 'stale_context');
    assert.equal(restarts, 0);

    active = context({ selection: { ...context().selection, gif: true } });
    const appliedReceipt = nextResult();
    assert.equal(dispatchPageBuilderAssistantAction(selectedAction('restart_gif', { action_token: 'U'.repeat(48) })), true);
    assert.equal((await appliedReceipt).status, 'applied');
    assert.equal(restarts, 1);

    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(rootClasses.has('rt-pagebuilder-fullscreen-open'), true);
});

test('consumes each confirmed pagebuilder action token exactly once and exposes no ambient command listener', async () => {
    let saves = 0;
    registerPageBuilderAssistantAdapter({
        getContext: () => context(),
        save: () => { saves += 1; return true; },
    });
    const confirmed = action('save', { action_token: 'R'.repeat(48) });
    const receipt = nextResult();

    assert.equal(dispatchPageBuilderAssistantAction(confirmed), true);
    assert.deepEqual(await receipt, { action_token: 'R'.repeat(48), status: 'applied' });
    assert.equal(dispatchPageBuilderAssistantAction(confirmed), false);
    testWindow.dispatchEvent(new TestCustomEvent('railtime-pagebuilder-assistant-command', { detail: confirmed }));
    await new Promise((resolve) => setTimeout(resolve, 0));
    assert.equal(saves, 1);
});
