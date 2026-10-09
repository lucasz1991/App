import assert from 'node:assert/strict';
import test, { afterEach } from 'node:test';
import { railtimeChatbot } from '../../resources/js/chatbot.js';

const originalWindow = globalThis.window;
const originalDocument = globalThis.document;
const fixtures = [];
const flush = () => new Promise(resolve => setImmediate(resolve));

afterEach(() => {
    for (const { bot } of fixtures.splice(0)) bot.destroy();
    if (originalWindow === undefined) delete globalThis.window;
    else globalThis.window = originalWindow;
    if (originalDocument === undefined) delete globalThis.document;
    else globalThis.document = originalDocument;
});

function fixture(config = {}) {
    const handlers = new Map();
    const values = new Map();
    const storage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) };
    globalThis.window = {
        innerWidth: 1280, localStorage: storage, sessionStorage: storage,
        location: { href: 'https://railtime.example.test/operations', origin: 'https://railtime.example.test' },
        matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {} }),
        setTimeout, clearTimeout, setInterval, clearInterval,
        requestAnimationFrame: callback => setTimeout(callback, 0), cancelAnimationFrame: clearTimeout,
        addEventListener() {}, removeEventListener() {},
    };
    globalThis.document = {
        hidden: false, documentElement: { classList: { contains: () => false }, removeAttribute() {} },
        querySelectorAll: () => [],
        addEventListener: (name, callback) => handlers.set(name, callback),
        removeEventListener: (name, callback) => { if (handlers.get(name) === callback) handlers.delete(name); },
    };
    const bot = railtimeChatbot({ operationsAvailable: true, assistantAvailable: false, speechAvailable: false, autoHelpDefault: false, ...config });
    const calls = [];
    Object.assign(bot, {
        $wire: {
            message: '', $id: 'native-assistant',
            loadOperationsAssist: async () => { calls.push(['load']); },
            runOperationsAction: async key => { calls.push(['action', key]); },
            submitOperationsIntake: async () => { calls.push(['intake']); },
            sendMessage: async () => { calls.push(['chat']); },
        },
        $refs: { composer: { value: 'Eine Anfrage', style: {}, scrollHeight: 30, focus() {} }, launcher: { focus() {} } },
        $watch() {}, $nextTick: callback => callback(),
        refreshSpeechStatus: async () => {}, observeMessages() {}, observeAttachments() {}, scrollMessages() {},
        syncPageBuilderAssistState() {}, hidePetBubble() {}, abortSpeechInput() {}, closeSettings() {},
    });
    bot.updateComposerState();
    const result = { bot, calls, handlers };
    fixtures.push(result);
    return result;
}

test('opening sole assistant loads operational panel once and never submits an intake', async () => {
    const { bot, calls } = fixture();
    let resolve;
    bot.$wire.loadOperationsAssist = () => { calls.push(['load']); return new Promise(done => { resolve = done; }); };
    bot.operationsTab = 'intake';
    bot.setOpen(true);
    bot.setOpen(true);
    assert.deepEqual(calls, [['load']]);
    assert.equal(bot.operationsLoading, true);
    resolve();
    await flush();
    bot.setOpen(false);
    bot.setOpen(true);
    await flush();
    assert.deepEqual(calls, [['load']]);
    assert.equal(bot.operationsLoaded, false, 'locked server property must not be written by the client');
    assert.equal(bot.operationsPanelLoaded, true);
    assert.equal(bot.operationsLoading, false);
    assert.equal(bot.$refs.composer.value, 'Eine Anfrage');
});

test('first operational load failure is shown and a new opening retries successfully', async () => {
    const { bot } = fixture();
    let calls = 0;
    bot.$wire.loadOperationsAssist = async () => { if (++calls === 1) throw new Error('private failure'); };
    bot.setOpen(true);
    await flush();
    assert.match(bot.operationsError, /erneut öffnen/);
    assert.doesNotMatch(bot.operationsError, /private failure/);
    bot.setOpen(false);
    bot.setOpen(true);
    await flush();
    assert.equal(calls, 2);
    assert.equal(bot.operationsError, '');
    assert.equal(bot.operationsPanelLoaded, true);
});

test('only explicit intake form or Enter submits intake and shared admission blocks double submit', async () => {
    const { bot, calls } = fixture();
    let resolve;
    bot.$wire.submitOperationsIntake = (...args) => {
        assert.deepEqual(args, [], 'backend consumes the actual native composer and attachments');
        calls.push(['intake']);
        return new Promise(done => { resolve = done; });
    };
    bot.operationsTab = 'intake';
    assert.equal(bot.canSubmit(), true, 'intake remains usable while general AI provider is unavailable');
    assert.equal(bot.handleComposerEnter({ isComposing: false }), true);
    assert.equal(bot.operationsBusy, true);
    assert.equal(await bot.submitComposer({ preventDefault() {} }), false);
    assert.deepEqual(calls, [['intake']]);
    resolve();
    await flush();
    assert.equal(bot.operationsBusy, false);
    bot.operationsTab = 'activity';
    assert.equal(await bot.submitComposer(), false);
    bot.operationsTab = 'chat';
    assert.equal(await bot.submitComposer(), true);
    assert.deepEqual(calls, [['intake'], ['chat']]);
});

test('operational actions switch to native chat without clearing an unsent draft', async () => {
    const { bot, calls } = fixture();
    bot.operationsTab = 'actions';
    assert.equal(await bot.handleOperationsAction('period'), true);
    assert.equal(bot.operationsTab, 'chat');
    assert.equal(bot.$refs.composer.value, 'Eine Anfrage');
    assert.deepEqual(calls, [['action', 'period']]);
    bot.operationsAvailable = false;
    assert.equal(await bot.handleOperationsAction('period'), false);
    assert.deepEqual(calls, [['action', 'period']]);
});

test('failed HTTP commit shows a safe error and releases native busy admission', async () => {
    const { bot } = fixture();
    let hook;
    let cleaned = 0;
    bot.$wire.$hook = (name, callback) => { assert.equal(name, 'commit'); hook = callback; return () => { cleaned++; }; };
    bot.$wire.runOperationsAction = () => new Promise(() => {});
    const pending = bot.handleOperationsAction('period');
    hook({ commit: { calls: [{ method: 'loadOperationsAssist' }] }, fail() { assert.fail('unrelated loader must not reject an action'); } });
    hook({ commit: { calls: [{ method: 'runOperationsAction' }] }, fail: callback => callback() });
    assert.equal(await pending, false);
    assert.equal(bot.operationsBusy, false);
    assert.match(bot.operationsError, /erneut versuchen/);
    assert.equal(cleaned, 1);
    assert.equal(bot.$refs.composer.value, 'Eine Anfrage');
});

test('local operational replies and local DOM history never enter external TTS or auto listening', () => {
    const { bot } = fixture({ autoReadDefault: true });
    bot.manualTtsAvailable = () => true;
    bot.scheduleAutoListenAfterReply = () => assert.fail('local output must not start the microphone');
    assert.equal(bot.handleOperationsReply({ key: 'local-uuid', localOnly: true }), true);
    bot.handleAssistantReply({ key: 'local-uuid', text: 'Private native personnel data' });
    bot.queueTtsSentence('Private native personnel data', 'assistant:local-uuid');
    assert.deepEqual(bot.ttsQueue, []);
    bot.$refs.messages = { querySelectorAll: () => [{ dataset: { assistantMessageKey: 'assistant:history-uuid' } }] };
    bot.speak('Native historical data', 'assistant:history-uuid');
    bot.queueTtsSentence('Native historical data', 'assistant:history-uuid');
    assert.deepEqual(bot.ttsQueue, []);
    assert.equal(bot.handleOperationsReply({ key: 'ordinary', localOnly: false }), false);
});

test('CtrlJ listener belongs to sole central owner and navigation destruction cancels late UI completion', async () => {
    const { bot, handlers, calls } = fixture();
    bot.init();
    assert.equal(typeof handlers.get('keydown'), 'function');
    let prevented = 0;
    const shortcut = { key: 'j', ctrlKey: true, preventDefault() { prevented++; } };
    handlers.get('keydown')(shortcut);
    await flush();
    assert.equal(bot.open, true);
    assert.equal(prevented, 1);
    assert.deepEqual(calls, [['load']]);
    assert.equal(bot.handleOperationsShortcut({ ...shortcut, isComposing: true }), false);
    assert.equal(bot.handleOperationsShortcut({ ...shortcut, repeat: true }), false);
    assert.equal(bot.handleOperationsShortcut({ ...shortcut, defaultPrevented: true }), false);
    let reject;
    bot.$wire.runOperationsAction = () => new Promise((resolve, fail) => { reject = fail; });
    const pending = bot.handleOperationsAction('period');
    bot.destroy();
    reject(new Error('late transport rejection'));
    assert.equal(await pending, false);
    assert.equal(bot.operationsError, '');
    assert.equal(handlers.has('keydown'), false);
    assert.equal(await bot.submitComposer(), false);
});

test('general chatbot keeps native submission when operational feature is unavailable', async () => {
    const { bot, calls } = fixture({ operationsAvailable: false, assistantAvailable: true });
    assert.equal(bot.canSubmit(), true);
    assert.equal(await bot.submitComposer(), true);
    assert.deepEqual(calls, [['chat']]);
    assert.equal(bot.handleOperationsShortcut({ key: 'j', ctrlKey: true, preventDefault() { assert.fail('no operational shortcut'); } }), false);
});

test('locked loaded state is read-only and avoids redundant loader requests', async () => {
    const { bot, calls } = fixture({ operationsLoaded: true });
    Object.defineProperty(bot, 'operationsLoaded', { get: () => true, set() { assert.fail('client must never update locked loaded state'); } });
    bot.setOpen(true);
    await flush();
    assert.deepEqual(calls, []);
    assert.equal(await bot.handleOperationsAction('day'), true);
    assert.deepEqual(calls, [['action', 'day']]);
});

test('IME confirmation, provider processing and attachment cleanup cannot submit another request', async () => {
    const { bot, calls } = fixture();
    bot.operationsTab = 'intake';
    assert.equal(bot.handleComposerEnter({ isComposing: true }), false);
    for (const gate of ['isLoading', 'attachmentUploadActive', 'navigationCleanupInFlight']) {
        bot[gate] = true;
        assert.equal(await bot.submitComposer(), false);
        bot[gate] = false;
    }
    bot.operationsAvailable = false;
    assert.equal(await bot.submitComposer(), false);
    assert.deepEqual(calls, []);
    assert.equal(bot.$refs.composer.value, 'Eine Anfrage');
});
