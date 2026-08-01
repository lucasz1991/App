import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test, { afterEach } from 'node:test';

import {
    cachedAssistantPhraseAudio,
    chooseAssistantPetReaction,
    clearAssistantPhraseAudioCache,
    railtimeChatbot,
    rememberAssistantPhraseAudio,
} from '../../resources/js/chatbot.js';

const sourceUrl = new URL('../../resources/js/chatbot.js', import.meta.url);
const originalGlobals = {
    Audio: globalThis.Audio,
    CustomEvent: globalThis.CustomEvent,
    document: globalThis.document,
    fetch: globalThis.fetch,
    FormData: globalThis.FormData,
    MediaRecorder: globalThis.MediaRecorder,
    navigator: globalThis.navigator,
    window: globalThis.window,
    createObjectURL: globalThis.URL.createObjectURL,
    revokeObjectURL: globalThis.URL.revokeObjectURL,
};

function replaceGlobal(name, value) {
    Object.defineProperty(globalThis, name, {
        configurable: true,
        writable: true,
        value,
    });
}

function replaceUrlMethod(name, value) {
    Object.defineProperty(globalThis.URL, name, {
        configurable: true,
        writable: true,
        value,
    });
}

function restoreGlobal(name, value) {
    if (value === undefined) {
        delete globalThis[name];
        return;
    }

    replaceGlobal(name, value);
}

function waitFor(predicate, timeoutMs = 500) {
    const startedAt = Date.now();

    return new Promise((resolve, reject) => {
        const poll = () => {
            if (predicate()) {
                resolve();
                return;
            }
            if (Date.now() - startedAt >= timeoutMs) {
                reject(new Error('Timed out while waiting for chatbot audio state.'));
                return;
            }
            setTimeout(poll, 0);
        };

        poll();
    });
}

afterEach(() => {
    clearAssistantPhraseAudioCache();
    restoreGlobal('Audio', originalGlobals.Audio);
    restoreGlobal('CustomEvent', originalGlobals.CustomEvent);
    restoreGlobal('document', originalGlobals.document);
    restoreGlobal('fetch', originalGlobals.fetch);
    restoreGlobal('FormData', originalGlobals.FormData);
    restoreGlobal('MediaRecorder', originalGlobals.MediaRecorder);
    restoreGlobal('navigator', originalGlobals.navigator);
    restoreGlobal('window', originalGlobals.window);
    replaceUrlMethod('createObjectURL', originalGlobals.createObjectURL);
    replaceUrlMethod('revokeObjectURL', originalGlobals.revokeObjectURL);
});

function memoryStorage(initial = {}) {
    const values = new Map(Object.entries(initial).map(([key, value]) => [key, String(value)]));

    return {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
        values,
    };
}

function throwingStorage() {
    const fail = () => {
        throw Object.assign(new Error('Storage access denied.'), { name: 'SecurityError' });
    };

    return {
        getItem: fail,
        setItem: fail,
        removeItem: fail,
    };
}

function installBrowserEnvironment({
    hidden = false,
    localValues = {},
    sessionValues = {},
    getUserMedia = async () => ({
        getAudioTracks: () => [{ readyState: 'live' }],
        getTracks: () => [{ stop() {} }],
    }),
} = {}) {
    const listeners = new Map();
    const windowListeners = new Map();
    const localStorage = memoryStorage(localValues);
    const sessionStorage = memoryStorage(sessionValues);

    class FakeMediaRecorder {}
    FakeMediaRecorder.isTypeSupported = () => true;

    const fakeWindow = {
        Audio: class {},
        FormData: class {},
        MediaRecorder: FakeMediaRecorder,
        URL: globalThis.URL,
        fetch: async () => {},
        innerWidth: 1440,
        localStorage,
        sessionStorage,
        matchMedia: () => ({
            matches: true,
            addEventListener() {},
            removeEventListener() {},
        }),
        addEventListener: (name, handler) => windowListeners.set(name, handler),
        removeEventListener: (name, handler) => {
            if (windowListeners.get(name) === handler) windowListeners.delete(name);
        },
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        cancelAnimationFrame: (timer) => clearTimeout(timer),
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
    };
    const fakeDocument = {
        hidden,
        addEventListener: (name, handler) => listeners.set(name, handler),
        removeEventListener: (name, handler) => {
            if (listeners.get(name) === handler) listeners.delete(name);
        },
        querySelectorAll: () => [],
    };

    replaceGlobal('window', fakeWindow);
    replaceGlobal('document', fakeDocument);
    replaceGlobal('fetch', fakeWindow.fetch);
    replaceGlobal('FormData', fakeWindow.FormData);
    replaceGlobal('MediaRecorder', FakeMediaRecorder);
    replaceGlobal('navigator', { mediaDevices: { getUserMedia } });

    return {
        fakeDocument,
        fakeWindow,
        listeners,
        localStorage,
        sessionStorage,
        windowListeners,
    };
}

function attachAlpineHarness(chatbot) {
    const watchers = new Map();
    chatbot.$refs = {};
    chatbot.$wire = { message: '', set: async () => {} };
    chatbot.$nextTick = (callback) => callback();
    chatbot.$watch = (name, callback) => watchers.set(name, callback);

    return watchers;
}

test('assistant message keys stay stable without depending on render position', () => {
    const chatbot = railtimeChatbot();

    assert.equal(chatbot.assistantMessageKey({ id: 42, content: 'Hallo' }), 'assistant:42');
    assert.equal(chatbot.assistantMessageKey({ key: 'reply-a' }), 'assistant:reply-a');

    const fallback = chatbot.assistantMessageKey({
        created_at: '2026-08-01T01:00:00+02:00',
        content: 'Eine stabile Antwort',
    });

    assert.equal(
        fallback,
        chatbot.assistantMessageKey({
            created_at: '2026-08-01T01:00:00+02:00',
            content: 'Eine stabile Antwort',
        }),
    );
    assert.notEqual(
        fallback,
        chatbot.assistantMessageKey({
            created_at: '2026-08-01T01:00:00+02:00',
            content: 'Eine andere Antwort',
        }),
    );
});

test('pet state follows assistant, loading, microphone, and playback state', () => {
    const chatbot = railtimeChatbot({ assistantAvailable: true });

    assert.equal(chatbot.petState(), 'idle');

    chatbot.isLoading = true;
    assert.equal(chatbot.petState(), 'thinking');

    chatbot.recording = true;
    assert.equal(chatbot.petState(), 'listening');

    chatbot.recording = false;
    chatbot.isLoading = false;
    chatbot.ttsWorkerActive = true;
    chatbot.ttsPreparing = true;
    assert.equal(chatbot.petState(), 'thinking');

    chatbot.ttsPreparing = false;
    chatbot.ttsPlaying = true;
    chatbot.speaking = true;
    assert.equal(chatbot.petState(), 'speaking');

    chatbot.assistantAvailable = false;
    assert.equal(chatbot.petState(), 'offline');
});

test('pet reactions are bounded and become the visible idle state', () => {
    assert.equal(chooseAssistantPetReaction(0), 'curious');
    assert.equal(chooseAssistantPetReaction(0.34), 'happy');
    assert.equal(chooseAssistantPetReaction(0.99), 'wave');

    installBrowserEnvironment();
    const chatbot = railtimeChatbot({ assistantAvailable: true });
    chatbot.petReaction = 'wave';
    assert.equal(chatbot.petState(), 'wave');

    chatbot.ttsPreparing = true;
    assert.equal(chatbot.petState(), 'thinking');
    chatbot.clearPetReactionTimers();
});

test('first pet click checks readiness and offers actions while the second opens the panel', async () => {
    installBrowserEnvironment();
    replaceGlobal('fetch', async () => ({
        ok: true,
        headers: { get: () => null },
        json: async () => ({
            state: 'ready',
            stt_configured: true,
            tts_configured: true,
            stt_available: true,
            tts_available: true,
            stt_provider: 'local',
            tts_provider: 'local',
        }),
    }));

    const chatbot = railtimeChatbot({
        assistantAvailable: true,
        speechStatusEndpoint: '/assistant/speech/status',
        sttEndpoint: '/assistant/audio-input/transcribe',
        ttsEndpoint: '/assistant/audio-output/stream',
        sttConfigured: true,
        ttsConfigured: true,
        pageRouteName: 'dashboard',
        pageHelpHints: [
            'Dashboard',
            'Hier siehst du deine nächsten Aufgaben.',
            'Die wichtigsten Aktionen liegen direkt im Seitenkopf.',
        ],
    });
    attachAlpineHarness(chatbot);

    await chatbot.handlePetClick();

    assert.equal(chatbot.open, false);
    assert.equal(chatbot.petPrimed, true);
    assert.equal(chatbot.petBubbleVisible, true);
    assert.notEqual(chatbot.petBubbleText, chatbot.strings.petStatusChecking);
    assert.ok(chatbot.petBubbleActions.some((action) => action.key === 'open_chat'));
    assert.ok(chatbot.petBubbleActions.some((action) => action.key === 'start_voice'));
    assert.ok(chatbot.petBubbleActions.some((action) => action.key === 'read_pet_phrase'));

    await chatbot.handlePetClick();

    assert.equal(chatbot.open, true);
    assert.equal(chatbot.petPrimed, false);
    chatbot.clearPetBubbleTimers();
    chatbot.clearPetReactionTimers();
});

test('read-aloud token state follows approximate media progress and resets outside playback', () => {
    const chatbot = railtimeChatbot();
    chatbot.ttsWorkerActive = true;
    chatbot.ttsActiveKey = 'assistant:progress';
    chatbot.ttsProgress = 0.45;

    assert.equal(chatbot.ttsTokenState('assistant:progress', 0, 4, 20), 'read');
    assert.equal(chatbot.ttsTokenState('assistant:progress', 8, 12, 20), 'current');
    assert.equal(chatbot.ttsTokenState('assistant:progress', 16, 20, 20), 'unread');
    assert.equal(chatbot.ttsTokenState('assistant:other', 0, 4, 20), 'idle');

    chatbot.stopSpeaking();
    assert.equal(chatbot.ttsTokenState('assistant:progress', 0, 4, 20), 'idle');
});

test('standard phrase audio cache is memory-only, bounded by TTL, and reusable', () => {
    const blob = new Blob(['cached phrase'], { type: 'audio/mpeg' });

    assert.equal(rememberAssistantPhraseAudio('phrase:a', blob, 1_000), true);
    assert.equal(cachedAssistantPhraseAudio('phrase:a', 1_001), blob);
    assert.equal(cachedAssistantPhraseAudio('phrase:a', 1_000 + (10 * 60 * 1_000) + 1), null);

    clearAssistantPhraseAudioCache();
    assert.equal(cachedAssistantPhraseAudio('phrase:a'), null);
});

test('assistant automation defaults and persisted device preferences are restored', () => {
    const defaults = railtimeChatbot();
    assert.equal(defaults.autoRead, false);
    assert.equal(defaults.autoListen, false);
    assert.equal(defaults.autoHelp, true);

    const { localStorage } = installBrowserEnvironment({
        localValues: {
            'railtime-chatbot-auto-read': '1',
            'railtime-chatbot-auto-listen': '1',
            'railtime-chatbot-auto-help': '0',
            'railtime-chatbot-speech-rate': '1.4',
        },
    });
    const chatbot = railtimeChatbot({
        autoReadDefault: false,
        autoListenDefault: false,
        autoHelpDefault: true,
        speechRate: 1,
    });
    const watchers = attachAlpineHarness(chatbot);

    chatbot.init();

    assert.equal(chatbot.settingsOpen, false);
    assert.equal(chatbot.autoRead, true);
    assert.equal(chatbot.autoListen, true);
    assert.equal(chatbot.autoHelp, false);
    assert.equal(chatbot.speechRate, 1.4);

    watchers.get('autoRead')(false);
    watchers.get('autoListen')(true);
    watchers.get('autoHelp')(true);
    watchers.get('speechRate')(1.2);

    assert.equal(localStorage.getItem('railtime-chatbot-auto-read'), '0');
    assert.equal(localStorage.getItem('railtime-chatbot-auto-listen'), '1');
    assert.equal(localStorage.getItem('railtime-chatbot-auto-help'), '1');
    assert.equal(localStorage.getItem('railtime-chatbot-speech-rate'), '1.2');

    chatbot.destroy();
});

test('blocked browser storage keeps defaults, watchers, and route help operational', () => {
    const { fakeWindow } = installBrowserEnvironment();
    fakeWindow.localStorage = throwingStorage();
    fakeWindow.sessionStorage = throwingStorage();

    const chatbot = railtimeChatbot({
        pageRouteName: 'storage-blocked-review-route',
        pageHelpHint: 'Dieser Hinweis bleibt trotz blockiertem Speicher einmalig.',
        autoReadDefault: false,
        autoListenDefault: false,
        autoHelpDefault: true,
        speechRate: 1,
    });
    const watchers = attachAlpineHarness(chatbot);

    assert.doesNotThrow(() => chatbot.init());
    assert.equal(chatbot.autoRead, false);
    assert.equal(chatbot.autoListen, false);
    assert.equal(chatbot.autoHelp, true);
    assert.equal(chatbot.speechRate, 1);

    assert.doesNotThrow(() => watchers.get('open')(true));
    assert.doesNotThrow(() => watchers.get('open')(false));
    assert.doesNotThrow(() => watchers.get('autoRead')(true));
    assert.doesNotThrow(() => watchers.get('autoListen')(true));
    assert.doesNotThrow(() => watchers.get('autoHelp')(false));
    assert.doesNotThrow(() => watchers.get('speechRate')(1.2));

    assert.equal(
        chatbot.nextProactivePetHint(),
        'Dieser Hinweis bleibt trotz blockiertem Speicher einmalig.',
    );
    assert.equal(chatbot.nextProactivePetHint(), '');

    chatbot.destroy();
});

test('page help memory fallback survives a component replacement when session storage is blocked', () => {
    const { fakeWindow } = installBrowserEnvironment();
    fakeWindow.sessionStorage = throwingStorage();
    const config = {
        pageRouteName: 'storage-blocked-wire-navigation-route',
        pageHelpHint: 'Nur einmal pro Browser-Lauf.',
    };

    const first = railtimeChatbot(config);
    attachAlpineHarness(first);
    assert.equal(first.nextProactivePetHint(), 'Nur einmal pro Browser-Lauf.');

    const replacement = railtimeChatbot(config);
    attachAlpineHarness(replacement);
    assert.equal(replacement.nextProactivePetHint(), '');
});

test('settings popover focuses its contents and restores focus to its trigger', () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot();
    attachAlpineHarness(chatbot);
    let panelFocused = false;
    let triggerFocused = false;
    chatbot.$refs.settingsPanel = {
        querySelector: () => ({ focus: () => { panelFocused = true; } }),
    };
    chatbot.$refs.settingsTrigger = { focus: () => { triggerFocused = true; } };

    chatbot.toggleSettings();

    assert.equal(chatbot.settingsOpen, true);
    assert.equal(panelFocused, true);

    chatbot.closeSettings(true);

    assert.equal(chatbot.settingsOpen, false);
    assert.equal(triggerFocused, true);
});

test('page help is proactive only once per route and manual bubbles remain available', () => {
    const { sessionStorage } = installBrowserEnvironment();
    const chatbot = railtimeChatbot({
        pageRouteName: 'dashboard',
        pageHelpHint: 'Hier findest du deine nÃ¤chsten Schichten.',
        autoHelpDefault: true,
    });
    attachAlpineHarness(chatbot);

    assert.equal(chatbot.nextProactivePetHint(), 'Hier findest du deine nÃ¤chsten Schichten.');
    assert.equal(sessionStorage.getItem('railtime-chatbot-page-help:dashboard'), '1');
    assert.equal(chatbot.nextProactivePetHint(), '');
    chatbot.schedulePetBubble(false);
    assert.equal(chatbot.petBubbleCycleTimer, null);

    chatbot.autoHelp = false;
    chatbot.schedulePetBubble(true);
    assert.equal(chatbot.petBubbleCycleTimer, null);

    chatbot.showPetBubble('Manuell angezeigter Hinweis');
    assert.equal(chatbot.petBubbleVisible, true);
    assert.equal(chatbot.petBubbleText, 'Manuell angezeigter Hinweis');
    chatbot.stopProactivePetBubbles();
    assert.equal(chatbot.petBubbleVisible, true);

    chatbot.showPetBubble(chatbot.strings.petReplyReady, 4_800, true);
    assert.equal(chatbot.petBubbleAnnounce, true);
    chatbot.stopProactivePetBubbles();
    assert.equal(chatbot.petBubbleVisible, true);

    chatbot.showPetBubble('Automatischer Seitenhinweis', 4_800, false, 'proactive');
    chatbot.stopProactivePetBubbles();
    assert.equal(chatbot.petBubbleVisible, false);
    chatbot.clearPetBubbleTimers();
});

test('local page help remains available while the remote assistant provider is offline', () => {
    const { fakeWindow } = installBrowserEnvironment();
    const timers = [];
    fakeWindow.setTimeout = (callback, delay) => {
        timers.push({ callback, delay });

        return timers.length;
    };
    const chatbot = railtimeChatbot({
        assistantAvailable: false,
        pageRouteName: 'offline-provider-local-help-route',
        pageHelpHint: 'Diese Seitenhilfe kommt direkt aus RailTime.',
        autoHelpDefault: true,
    });
    attachAlpineHarness(chatbot);

    chatbot.schedulePetBubble(true);

    assert.equal(timers[0]?.delay, 1_200);
    timers[0]?.callback();
    assert.equal(chatbot.petBubbleVisible, true);
    assert.equal(chatbot.petBubbleText, 'Diese Seitenhilfe kommt direkt aus RailTime.');
    assert.equal(chatbot.petBubbleOrigin, 'proactive');
    chatbot.clearPetBubbleTimers();
});

test('enabling automatic listening preflights microphone access and resets on denial', async () => {
    installBrowserEnvironment({
        getUserMedia: async () => {
            throw Object.assign(new Error('denied'), { name: 'NotAllowedError' });
        },
    });
    const chatbot = railtimeChatbot({
        speechAvailable: true,
        sttEndpoint: '/assistant/audio-input/transcribe',
    });
    attachAlpineHarness(chatbot);
    const input = { checked: true };

    const enabled = await chatbot.setAutoListen(true, input);

    assert.equal(enabled, false);
    assert.equal(chatbot.autoListen, false);
    assert.equal(input.checked, false);
    assert.match(chatbot.audioError, /Spracheingabe:/);
    assert.match(chatbot.audioError, /Mikrofon/);
});

test('successful automatic-listening preflight releases the microphone until recording starts', async () => {
    let stopped = 0;
    installBrowserEnvironment({
        getUserMedia: async () => {
            const track = { readyState: 'live', stop: () => { stopped += 1; } };

            return {
                getAudioTracks: () => [track],
                getTracks: () => [track],
            };
        },
    });
    const chatbot = railtimeChatbot({
        speechAvailable: true,
        sttEndpoint: '/assistant/audio-input/transcribe',
    });
    attachAlpineHarness(chatbot);

    const enabled = await chatbot.setAutoListen(true);

    assert.equal(enabled, true);
    assert.equal(chatbot.autoListen, true);
    assert.equal(chatbot.autoListenChecking, false);
    assert.equal(stopped, 1);
});

test('a recorded voice capture is visibly bounded to forty-five seconds', async () => {
    const { fakeWindow } = installBrowserEnvironment();
    const delays = [];
    fakeWindow.setTimeout = (callback, delay) => {
        delays.push(delay);

        return setTimeout(callback, delay);
    };

    class FakeMediaRecorder {
        static isTypeSupported() { return true; }

        constructor() {
            this.mimeType = 'audio/webm';
            this.state = 'inactive';
        }

        start() { this.state = 'recording'; }

        stop() { this.state = 'inactive'; }
    }

    fakeWindow.MediaRecorder = FakeMediaRecorder;
    replaceGlobal('MediaRecorder', FakeMediaRecorder);
    const chatbot = railtimeChatbot({
        speechAvailable: true,
        sttEndpoint: '/assistant/audio-input/transcribe',
    });
    attachAlpineHarness(chatbot);

    await chatbot.toggleVoice();

    assert.equal(chatbot.recording, true);
    assert.ok(delays.includes(45_000));
    chatbot.cancelVoiceCapture();
    assert.equal(chatbot.recording, false);
});

test('TTS reports speaking only after the audio element actually starts playing', async () => {
    const audioInstances = [];
    const revokedUrls = [];

    class FakeAudio {
        constructor(url) {
            this.url = url;
            this.paused = true;
            this.src = url;
            audioInstances.push(this);
        }

        play() {
            this.paused = false;
            return Promise.resolve();
        }

        pause() {
            this.paused = true;
        }
    }

    replaceGlobal('Audio', FakeAudio);
    replaceGlobal('document', { querySelectorAll: () => [] });
    replaceGlobal('fetch', async () => ({
        ok: true,
        headers: { get: () => null },
        blob: async () => new Blob(['audio'], { type: 'audio/mpeg' }),
    }));
    replaceUrlMethod('createObjectURL', () => 'blob:railtime-tts');
    replaceUrlMethod('revokeObjectURL', (url) => revokedUrls.push(url));

    const chatbot = railtimeChatbot({
        ttsEndpoint: '/assistant/audio-output/stream',
        csrfToken: 'csrf-test',
    });
    chatbot.speechSupported = true;

    chatbot.queueTtsSentence('Willkommen bei RailTime.', 'assistant:reply-1');

    assert.equal(chatbot.ttsPreparing, true);
    assert.equal(chatbot.ttsPlaying, false);
    assert.equal(chatbot.speaking, false);

    await waitFor(() => audioInstances.length === 1);
    const audio = audioInstances[0];

    assert.equal(chatbot.ttsPreparing, true);
    assert.equal(chatbot.speaking, false);

    audio.onplaying();
    assert.equal(chatbot.ttsPreparing, false);
    assert.equal(chatbot.ttsPlaying, true);
    assert.equal(chatbot.speaking, true);
    assert.equal(chatbot.speakingKey, 'assistant:reply-1');

    audio.onended();
    await waitFor(() => chatbot.ttsWorkerActive === false);

    assert.equal(chatbot.ttsPlaying, false);
    assert.equal(chatbot.speaking, false);
    assert.deepEqual(revokedUrls, ['blob:railtime-tts']);
});

test('repeated standard phrase playback requests TTS only once per component lifecycle', async () => {
    const audioInstances = [];
    let fetches = 0;
    let objectUrlIndex = 0;

    class FakeAudio {
        constructor(url) {
            this.url = url;
            this.paused = true;
            this.src = url;
            audioInstances.push(this);
        }

        play() {
            this.paused = false;

            return Promise.resolve();
        }

        pause() {
            this.paused = true;
        }
    }

    replaceGlobal('Audio', FakeAudio);
    replaceGlobal('document', { querySelectorAll: () => [] });
    replaceGlobal('fetch', async () => {
        fetches += 1;

        return {
            ok: true,
            headers: { get: () => null },
            blob: async () => new Blob(['same phrase audio'], { type: 'audio/mpeg' }),
        };
    });
    replaceUrlMethod('createObjectURL', () => 'blob:phrase-' + (++objectUrlIndex));
    replaceUrlMethod('revokeObjectURL', () => {});

    const chatbot = railtimeChatbot({
        ttsEndpoint: '/assistant/audio-output/stream',
        csrfToken: 'csrf-test',
    });
    chatbot.speechSupported = true;
    chatbot.ttsReady = true;
    const options = { cacheKey: 'pet-phrase:dashboard-help' };

    chatbot.speak('Soll ich dir auf dem Dashboard helfen?', 'pet:dashboard-help', options);
    await waitFor(() => audioInstances.length === 1);
    audioInstances[0].onplaying();
    audioInstances[0].onended();
    await waitFor(() => chatbot.ttsWorkerActive === false);

    chatbot.speak('Soll ich dir auf dem Dashboard helfen?', 'pet:dashboard-help', options);
    await waitFor(() => audioInstances.length === 2);

    assert.equal(fetches, 1);
    audioInstances[1].onplaying();
    audioInstances[1].onended();
    await waitFor(() => chatbot.ttsWorkerActive === false);
});

test('automatic listening waits for the complete TTS lifecycle and requires an empty draft', async () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot({
        speechAvailable: true,
        sttEndpoint: '/assistant/audio-input/transcribe',
    });
    attachAlpineHarness(chatbot);
    chatbot.open = true;
    chatbot.autoListen = true;
    chatbot.voiceSupported = true;
    chatbot.recordedVoiceSupported = () => true;
    chatbot.$refs.composer = { value: '' };
    let starts = 0;
    chatbot.toggleVoice = async () => { starts += 1; };

    chatbot.ttsWorkerActive = true;
    chatbot.ttsPreparing = true;
    chatbot.scheduleAutoListenAfterReply();

    await new Promise((resolve) => setTimeout(resolve, 25));
    assert.equal(starts, 0);

    chatbot.ttsWorkerActive = false;
    chatbot.ttsPreparing = false;
    chatbot.ttsPlaying = true;
    await new Promise((resolve) => setTimeout(resolve, 135));
    assert.equal(starts, 0);

    chatbot.ttsPlaying = false;
    await waitFor(() => starts === 1);

    chatbot.$refs.composer.value = 'Mein neuer Entwurf';
    chatbot.scheduleAutoListenAfterReply();
    await new Promise((resolve) => setTimeout(resolve, 25));
    assert.equal(starts, 1);
});

test('assistant reply queues TTS before automatic listening is scheduled', () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot();
    attachAlpineHarness(chatbot);
    chatbot.open = true;
    chatbot.autoRead = true;
    chatbot.autoListen = true;
    chatbot.speechSupported = true;
    chatbot.ttsReady = true;
    const order = [];
    chatbot.scrollMessages = () => {};
    chatbot.queueTtsSentence = () => {
        order.push('tts');
        chatbot.ttsPreparing = true;
    };
    chatbot.scheduleAutoListenAfterReply = () => order.push('listen');

    chatbot.handleAssistantReply({ key: 'new-answer', text: 'Eine neue Antwort' });

    assert.deepEqual(order, ['tts', 'listen']);
    assert.equal(chatbot.ttsPreparing, true);
});

test('assistant client navigation accepts only a same-origin relative route', () => {
    const { fakeWindow } = installBrowserEnvironment();
    const navigations = [];
    fakeWindow.location = {
        href: 'https://railtime.test/dashboard',
        origin: 'https://railtime.test',
        assign: (target) => navigations.push(`assign:${target}`),
    };
    fakeWindow.Livewire = {
        navigate: (target) => navigations.push(target),
    };

    const chatbot = railtimeChatbot();
    chatbot.closeSettings = () => {};
    chatbot.abortSpeechInput = () => {};
    chatbot.stopSpeaking = () => {};
    chatbot.discardPendingAttachments = () => {};
    chatbot.setOpen = () => {};

    const accepted = chatbot.handleClientAction({
        action: {
            type: 'navigate',
            action_token: 'A'.repeat(48),
            url: '/hilfe?bereich=app#start',
        },
    });

    assert.equal(accepted, true);
    assert.deepEqual(navigations, ['/hilfe?bereich=app#start']);
    assert.equal(chatbot.handleClientAction({
        action: {
            type: 'navigate',
            action_token: 'B'.repeat(48),
            url: 'https://attacker.invalid/steal',
        },
    }), false);
    assert.equal(chatbot.handleClientAction({
        action: {
            type: 'navigate',
            action_token: 'C'.repeat(48),
            url: '//attacker.invalid/steal',
        },
    }), false);
    assert.deepEqual(navigations, ['/hilfe?bereich=app#start']);
});

test('assistant client dispatches only allowlisted nonce-bound wagon commands', () => {
    const { fakeWindow } = installBrowserEnvironment();
    const dispatched = [];
    replaceGlobal('CustomEvent', class {
        constructor(type, options = {}) {
            this.type = type;
            this.detail = options.detail;
        }
    });
    fakeWindow.dispatchEvent = (event) => dispatched.push(event);

    const chatbot = railtimeChatbot();
    const accepted = chatbot.handleClientAction({
        action: {
            type: 'wagon_list',
            command: 'select_wagon',
            wagon_index: 2,
            context_nonce: 'wagon_context_nonce_123456',
            action_token: 'D'.repeat(48),
        },
    });

    assert.equal(accepted, true);
    assert.equal(dispatched.length, 1);
    assert.equal(dispatched[0].type, 'railtime-wagon-assistant-command');
    assert.equal(dispatched[0].detail.version, 1);
    assert.equal(dispatched[0].detail.command, 'select_wagon');
    assert.equal(dispatched[0].detail.wagon_index, 2);

    assert.equal(chatbot.handleClientAction({
        action: {
            type: 'wagon_list',
            command: 'delete_all',
            context_nonce: 'wagon_context_nonce_123456',
            action_token: 'E'.repeat(48),
        },
    }), false);
    assert.equal(chatbot.handleClientAction({
        action: {
            type: 'wagon_list',
            command: 'next',
            context_nonce: 'short',
            action_token: 'F'.repeat(48),
        },
    }), false);
    assert.equal(dispatched.length, 1);
});

test('wagon help bubble is shown only while automatic help is enabled and visible', () => {
    const { fakeDocument } = installBrowserEnvironment();
    const chatbot = railtimeChatbot();
    const bubbles = [];
    chatbot.open = false;
    chatbot.showPetBubble = (...args) => bubbles.push(args);

    chatbot.autoHelp = false;
    assert.equal(chatbot.handleWagonHelp({ text: 'Ich helfe bei der Wagenliste.' }), false);
    assert.equal(bubbles.length, 0);

    chatbot.autoHelp = true;
    assert.equal(chatbot.handleWagonHelp({ text: 'Ich helfe bei der Wagenliste.' }), true);
    assert.equal(bubbles.length, 1);
    assert.equal(bubbles[0][0], 'Ich helfe bei der Wagenliste.');
    assert.equal(bubbles[0][3], 'wagon-list');
    assert.equal(bubbles[0][4].key, 'wagon_voice_start');

    fakeDocument.hidden = true;
    assert.equal(chatbot.handleWagonHelp({ text: 'Versteckt' }), false);
    assert.equal(bubbles.length, 1);
});

test('assistant provider errors never schedule automatic listening', () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot({ assistantAvailable: true });
    chatbot.$nextTick = (callback) => callback();
    chatbot.scrollMessages = () => {};
    chatbot.updateComposerState = () => {};
    chatbot.resetAttachmentUi = () => {};
    let scheduled = 0;
    let cancelled = 0;
    chatbot.scheduleAutoListenAfterReply = () => { scheduled += 1; };
    chatbot.cancelPendingAutoListen = () => { cancelled += 1; };

    chatbot.handleAssistantReply({
        key: 'provider-error-answer',
        text: 'Der Assistent ist gerade nicht verfügbar.',
        can_auto_listen: false,
    });

    assert.equal(scheduled, 0);
    assert.equal(cancelled, 1);
});

test('speech transcription only fills the composer and never sends automatically', async () => {
    installBrowserEnvironment();
    replaceGlobal('FormData', class { append() {} });
    let filledMessage = null;
    let sent = 0;
    replaceGlobal('fetch', async () => ({
        ok: true,
        json: async () => ({ text: 'Bitte zeige meine Schichten' }),
    }));
    const chatbot = railtimeChatbot({ sttEndpoint: '/assistant/audio-input/transcribe' });
    attachAlpineHarness(chatbot);
    chatbot.$refs.composer = { value: '' };
    chatbot.$wire = {
        set: async (name, value) => {
            assert.equal(name, 'message');
            filledMessage = value;
        },
        sendMessage: () => { sent += 1; },
    };

    await chatbot.transcribeRecordedBlob(new Blob(['voice'], { type: 'audio/webm' }));

    assert.equal(filledMessage, 'Bitte zeige meine Schichten');
    assert.equal(sent, 0);
});

test('close, navigation, hidden tabs, and destroy cancel pending or active speech input', () => {
    const { fakeDocument, listeners } = installBrowserEnvironment({
        sessionValues: { 'railtime-chatbot-open': '1' },
        localValues: { 'railtime-chatbot-auto-help': '0' },
    });
    const chatbot = railtimeChatbot({
        speechAvailable: true,
        sttEndpoint: '/assistant/audio-input/transcribe',
    });
    attachAlpineHarness(chatbot);
    chatbot.init();
    chatbot.autoListen = true;
    chatbot.isLoading = true;
    chatbot.recordedVoiceSupported = () => true;
    chatbot.$refs.composer = { value: '' };

    const armSpeech = () => {
        chatbot.open = true;
        chatbot.recording = true;
        chatbot.voiceUploading = true;
        chatbot.sttAbortController = { abort() {} };
        chatbot.scheduleAutoListenAfterReply();
        assert.notEqual(chatbot.autoListenTimer, null);
    };

    armSpeech();
    chatbot.setOpen(false);
    assert.equal(chatbot.autoListenTimer, null);
    assert.equal(chatbot.recording, false);
    assert.equal(chatbot.voiceUploading, false);

    armSpeech();
    listeners.get('livewire:navigating')();
    assert.equal(chatbot.autoListenTimer, null);
    assert.equal(chatbot.recording, false);
    assert.equal(chatbot.voiceUploading, false);

    armSpeech();
    fakeDocument.hidden = true;
    listeners.get('visibilitychange')();
    assert.equal(chatbot.autoListenTimer, null);
    assert.equal(chatbot.recording, false);
    assert.equal(chatbot.voiceUploading, false);

    armSpeech();
    chatbot.destroy();
    assert.equal(chatbot.autoListenTimer, null);
    assert.equal(chatbot.recording, false);
    assert.equal(chatbot.voiceUploading, false);
});

test('stopping TTS invalidates work, aborts the request, and revokes object URLs', () => {
    const revokedUrls = [];
    let aborted = false;
    let cancelled = false;
    let paused = false;
    const chatbot = railtimeChatbot();

    replaceUrlMethod('revokeObjectURL', (url) => revokedUrls.push(url));

    chatbot.ttsCurrentGeneration = 7;
    chatbot.ttsQueue = [{ text: 'Alt', key: 'assistant:old', generation: 7 }];
    chatbot.ttsAbortController = { abort: () => { aborted = true; } };
    chatbot.ttsPlaybackCancel = () => { cancelled = true; };
    chatbot.ttsAudio = {
        pause: () => { paused = true; },
        src: 'blob:active',
    };
    chatbot.ttsObjectUrls = ['blob:active', 'blob:queued'];
    chatbot.ttsWorkerActive = true;
    chatbot.ttsPreparing = true;
    chatbot.ttsPlaying = true;
    chatbot.speaking = true;

    chatbot.stopSpeaking();

    assert.equal(chatbot.ttsCurrentGeneration, 8);
    assert.deepEqual(chatbot.ttsQueue, []);
    assert.equal(aborted, true);
    assert.equal(cancelled, true);
    assert.equal(paused, true);
    assert.deepEqual(revokedUrls, ['blob:active', 'blob:queued']);
    assert.equal(chatbot.ttsWorkerActive, false);
    assert.equal(chatbot.ttsPreparing, false);
    assert.equal(chatbot.ttsPlaying, false);
    assert.equal(chatbot.speaking, false);
});

test('speech configuration stays selectable while runtime readiness changes', async () => {
    installBrowserEnvironment();
    replaceGlobal('fetch', async () => ({
        ok: true,
        json: async () => ({
            state: 'degraded',
            stt_available: true,
            tts_available: true,
            stt_provider: 'local',
            tts_provider: 'openrouter',
            fallback_available: { stt: true, tts: true },
            fallback_active: { stt: false, tts: true },
        }),
    }));
    const chatbot = railtimeChatbot({
        speechStatusEndpoint: '/assistant/speech/status',
        sttEndpoint: '/assistant/audio-input/transcribe',
        ttsEndpoint: '/assistant/audio-output/stream',
        sttConfigured: true,
        ttsConfigured: true,
        externalFallback: true,
    });
    attachAlpineHarness(chatbot);

    chatbot.init();
    await waitFor(() => chatbot.sttReady && chatbot.ttsReady);

    assert.equal(chatbot.voiceSupported, true);
    assert.equal(chatbot.speechSupported, true);
    assert.equal(chatbot.manualVoiceAvailable(), true);
    assert.equal(chatbot.manualTtsAvailable(), true);
    assert.equal(chatbot.speechFallbackActive, true);
    assert.equal(chatbot.speechProviderName(), 'Lokaler Sprachdienst / OpenRouter');

    chatbot.sttReady = false;
    chatbot.ttsReady = false;

    assert.equal(chatbot.voiceSupported, true);
    assert.equal(chatbot.speechSupported, true);
    assert.equal(chatbot.manualVoiceAvailable(), false);
    assert.equal(chatbot.manualTtsAvailable(), false);

    chatbot.destroy();
});

test('status checks run on init, open, visibility, and online and are aborted on teardown', async () => {
    const {
        listeners,
        windowListeners,
    } = installBrowserEnvironment();
    let requests = 0;
    let aborted = 0;
    replaceGlobal('fetch', (_url, options) => {
        requests += 1;

        return new Promise((_resolve, reject) => {
            options.signal.addEventListener('abort', () => {
                aborted += 1;
                reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
            }, { once: true });
        });
    });
    const chatbot = railtimeChatbot({
        speechStatusEndpoint: '/assistant/speech/status',
        sttEndpoint: '/assistant/audio-input/transcribe',
        ttsEndpoint: '/assistant/audio-output/stream',
        sttConfigured: true,
        ttsConfigured: true,
    });
    attachAlpineHarness(chatbot);

    chatbot.init();
    await waitFor(() => requests === 1);
    chatbot.setOpen(true);
    await waitFor(() => requests === 2);
    listeners.get('visibilitychange')();
    await waitFor(() => requests === 3);
    windowListeners.get('online')();
    await waitFor(() => requests === 4);
    chatbot.destroy();

    await waitFor(() => aborted === 4);
    assert.equal(chatbot.speechStatusAbortController, null);
    assert.equal(chatbot.speechStatusRetryTimer, null);
    assert.equal(windowListeners.has('online'), false);
});

test('failed status checks retry with bounded backoff and stop on destroy', async () => {
    const { fakeWindow } = installBrowserEnvironment({
        localValues: { 'railtime-chatbot-auto-help': '0' },
    });
    const timers = [];
    fakeWindow.setTimeout = (callback, delay) => {
        const timer = { callback, delay, cleared: false };
        timers.push(timer);

        return timer;
    };
    fakeWindow.clearTimeout = (timer) => {
        if (timer) timer.cleared = true;
    };
    replaceGlobal('fetch', async () => {
        throw new TypeError('Failed to fetch');
    });
    const chatbot = railtimeChatbot({
        speechStatusEndpoint: '/assistant/speech/status',
        sttEndpoint: '/assistant/audio-input/transcribe',
        ttsEndpoint: '/assistant/audio-output/stream',
        sttConfigured: true,
        ttsConfigured: true,
        autoHelpDefault: false,
    });
    attachAlpineHarness(chatbot);

    chatbot.init();
    await waitFor(() => timers.some((timer) => timer.delay === 2_000));
    const firstRetry = timers.find((timer) => timer.delay === 2_000);
    firstRetry.callback();
    await waitFor(() => timers.some((timer) => timer.delay === 5_000));

    const secondRetry = timers.find((timer) => timer.delay === 5_000);
    chatbot.destroy();

    assert.equal(secondRetry.cleared, true);
    assert.equal(chatbot.speechStatusRetryTimer, null);
});

test('a provider failure cancels automatic listening without clearing its preference', async () => {
    installBrowserEnvironment();
    replaceGlobal('fetch', async () => ({
        ok: false,
        status: 503,
        headers: { get: () => null },
        text: async () => JSON.stringify({ message: 'Speech provider unavailable.' }),
    }));
    const chatbot = railtimeChatbot({
        sttEndpoint: '/assistant/audio-input/transcribe',
        ttsEndpoint: '/assistant/audio-output/stream',
        sttConfigured: true,
        ttsConfigured: true,
    });
    attachAlpineHarness(chatbot);
    chatbot.open = true;
    chatbot.autoRead = true;
    chatbot.autoListen = true;
    chatbot.speechSupported = true;
    chatbot.$refs.composer = { value: '' };
    let listeningStarts = 0;
    chatbot.toggleVoice = async () => { listeningStarts += 1; };

    chatbot.handleAssistantReply({ key: 'provider-error', text: 'Antwort mit Audiofehler' });
    await waitFor(() => chatbot.ttsWorkerActive === false);
    await new Promise((resolve) => setTimeout(resolve, 140));

    assert.equal(listeningStarts, 0);
    assert.equal(chatbot.autoListen, true);
    assert.equal(chatbot.ttsReady, false);
    assert.equal(chatbot.autoListenTimer, null);
});

test('uploaded attachments enable an attachment-only send and upload state is cleaned up', async () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot({ attachmentCount: 1, assistantAvailable: true });
    attachAlpineHarness(chatbot);
    let sent = 0;
    let cancelledUploads = 0;
    let discardedBatches = 0;
    chatbot.$wire.sendMessage = () => { sent += 1; };
    chatbot.$wire.cancelUpload = (name) => {
        assert.equal(name, 'attachments');
        cancelledUploads += 1;
    };
    chatbot.$wire.discardAttachments = (cleanupId) => {
        discardedBatches += 1;

        return Promise.resolve({ cleanup_id: cleanupId, remaining: 0 });
    };
    chatbot.$refs.composer = { value: '' };
    chatbot.$refs.attachmentInput = { value: 'selected' };
    chatbot.$refs.attachmentList = {
        querySelectorAll: () => [{}, {}],
    };

    assert.equal(chatbot.canSubmit(), true);
    assert.equal(chatbot.handleComposerEnter({ isComposing: false }), true);
    assert.equal(sent, 1);

    chatbot.beginAttachmentUpload();
    chatbot.updateAttachmentUpload({ progress: 47 });
    assert.equal(chatbot.canSubmit(), false);
    assert.equal(chatbot.attachmentUploadProgress, 47);

    chatbot.completeAttachmentUpload();
    await waitFor(() => chatbot.attachmentCount === 2);
    assert.equal(chatbot.attachmentUploadActive, false);
    assert.equal(chatbot.$refs.attachmentInput.value, '');

    chatbot.markAttachmentRemoval();
    assert.equal(chatbot.attachmentCount, 1);
    chatbot.attachmentUploadActive = true;
    await chatbot.discardPendingAttachments(true);
    assert.equal(cancelledUploads, 1);
    assert.equal(discardedBatches, 1);
    assert.equal(chatbot.attachmentCount, 0);
    chatbot.destroy();
    assert.equal(chatbot.attachmentSyncTimer, null);
    assert.equal(chatbot.attachmentUploadActive, false);
});

test('Livewire navigation waits for protected attachment cleanup before it resumes', async () => {
    const { fakeWindow, listeners } = installBrowserEnvironment();
    const navigations = [];
    let finishCleanup;
    let cleanupId = '';
    let prevented = 0;
    fakeWindow.location = {
        href: 'https://railtime.test/dashboard',
        origin: 'https://railtime.test',
        assign: (target) => navigations.push(target),
    };
    fakeWindow.Livewire = {
        navigate: (target) => {
            navigations.push(target);
            listeners.get('livewire:navigate')({
                detail: { url: target },
                preventDefault: () => { prevented += 1; },
            });
        },
    };

    const chatbot = railtimeChatbot({ attachmentCount: 1 });
    attachAlpineHarness(chatbot);
    chatbot.$wire.discardAttachments = (token) => {
        cleanupId = token;

        return new Promise((resolve) => {
            finishCleanup = resolve;
        });
    };
    chatbot.init();

    listeners.get('livewire:navigate')({
        detail: { url: 'https://railtime.test/wagon-lists?mode=create#wagon' },
        preventDefault: () => { prevented += 1; },
    });

    assert.equal(prevented, 1);
    await waitFor(() => chatbot.navigationCleanupInFlight === true);
    await waitFor(() => typeof finishCleanup === 'function');
    assert.deepEqual(navigations, []);

    finishCleanup({ cleanup_id: cleanupId, remaining: 0 });
    await waitFor(() => navigations.length === 1);

    assert.deepEqual(navigations, ['https://railtime.test/wagon-lists?mode=create#wagon']);
    assert.equal(chatbot.navigationCleanupInFlight, false);
    assert.equal(chatbot.attachmentCount, 0);
    chatbot.destroy();
    assert.equal(fakeWindow.RailTimeNavigationCoordinator.controllers.size, 0);
});

test('a real Livewire commit failure keeps the current page and restores the visible attachment count', async () => {
    const { fakeWindow, listeners } = installBrowserEnvironment();
    const navigations = [];
    fakeWindow.location = {
        href: 'https://railtime.test/dashboard',
        origin: 'https://railtime.test',
        assign: (target) => navigations.push(target),
    };
    fakeWindow.Livewire = {
        navigate: (target) => navigations.push(target),
    };

    const chatbot = railtimeChatbot({
        attachmentCount: 1,
        strings: { attachmentCleanupFailed: 'Cleanup fehlgeschlagen.' },
    });
    attachAlpineHarness(chatbot);
    chatbot.$refs.attachmentList = {
        querySelectorAll: () => [{}],
    };
    let commitHook = null;
    chatbot.$wire.$hook = (name, callback) => {
        assert.equal(name, 'commit');
        commitHook = callback;

        return () => { commitHook = null; };
    };
    chatbot.$wire.discardAttachments = (cleanupId) => {
        let failRequest = null;
        commitHook({
            commit: {
                calls: [{ method: 'discardAttachments', params: [cleanupId] }],
            },
            fail: (callback) => { failRequest = callback; },
        });
        queueMicrotask(() => failRequest());

        // Livewire 3 method promises remain pending on HTTP/network failure.
        return new Promise(() => {});
    };
    chatbot.init();

    listeners.get('livewire:navigate')({
        detail: { url: '/wagon-lists' },
        preventDefault() {},
    });
    await waitFor(() => chatbot.attachmentUploadError === 'Cleanup fehlgeschlagen.');

    assert.deepEqual(navigations, []);
    assert.equal(chatbot.attachmentCount, 1);
    assert.equal(chatbot.navigationCleanupInFlight, false);
    chatbot.destroy();
});

test('an inconclusive Livewire cleanup times out fail-closed instead of releasing navigation', async () => {
    const { fakeWindow, listeners } = installBrowserEnvironment();
    const navigations = [];
    fakeWindow.location = {
        href: 'https://railtime.test/dashboard',
        origin: 'https://railtime.test',
        assign: (target) => navigations.push(target),
    };
    fakeWindow.Livewire = {
        navigate: (target) => navigations.push(target),
    };

    const chatbot = railtimeChatbot({
        attachmentCount: 1,
        attachmentCleanupTimeoutMs: 50,
    });
    attachAlpineHarness(chatbot);
    chatbot.$wire.discardAttachments = () => new Promise(() => {});
    chatbot.init();

    listeners.get('livewire:navigate')({
        detail: { url: '/wagon-lists' },
        preventDefault() {},
    });
    await waitFor(() => chatbot.attachmentUploadError === chatbot.strings.attachmentCleanupFailed);

    assert.deepEqual(navigations, []);
    assert.equal(chatbot.attachmentsMayExistOnServer, true);
    assert.equal(chatbot.attachmentCount, 1);
    assert.equal(chatbot.navigationCleanupInFlight, false);
    chatbot.destroy();
});

test('a synchronous Livewire cleanup failure cannot release navigation', async () => {
    const { fakeWindow, listeners } = installBrowserEnvironment();
    const navigations = [];
    fakeWindow.location = {
        href: 'https://railtime.test/dashboard',
        origin: 'https://railtime.test',
        assign: (target) => navigations.push(target),
    };
    fakeWindow.Livewire = {
        navigate: (target) => navigations.push(target),
    };

    const chatbot = railtimeChatbot({ attachmentCount: 1 });
    attachAlpineHarness(chatbot);
    chatbot.$wire.discardAttachments = () => {
        throw new Error('Livewire unavailable.');
    };
    chatbot.init();

    listeners.get('livewire:navigate')({
        detail: { url: '/wagon-lists' },
        preventDefault() {},
    });
    await waitFor(() => chatbot.attachmentUploadError === chatbot.strings.attachmentCleanupFailed);

    assert.deepEqual(navigations, []);
    assert.equal(chatbot.attachmentsMayExistOnServer, true);
    assert.equal(chatbot.navigationCleanupInFlight, false);
    chatbot.destroy();
});

test('a cleanup acknowledgement with remaining raw files stays fail-closed', async () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot({ attachmentCount: 1 });
    attachAlpineHarness(chatbot);
    chatbot.$wire.discardAttachments = (cleanupId) => Promise.resolve({
        cleanup_id: cleanupId,
        remaining: 1,
    });

    await assert.rejects(
        chatbot.discardPendingAttachments(true),
        /left raw files/,
    );

    assert.equal(chatbot.attachmentsMayExistOnServer, true);
    assert.equal(chatbot.attachmentCount, 1);
    assert.equal(chatbot.attachmentUploadError, chatbot.strings.attachmentCleanupFailed);
});

test('a late Livewire finish commit forces a second acknowledged cleanup sweep', async () => {
    const { fakeWindow, listeners } = installBrowserEnvironment();
    const navigations = [];
    let commitHook = null;
    let finishCommit = null;
    let finishSecondSweep = null;
    let sweeps = 0;
    fakeWindow.location = {
        href: 'https://railtime.test/dashboard',
        origin: 'https://railtime.test',
        assign: (target) => navigations.push(target),
    };
    fakeWindow.Livewire = {
        navigate: (target) => {
            navigations.push(target);
            listeners.get('livewire:navigate')({
                detail: { url: target },
                preventDefault() {},
            });
        },
    };

    const chatbot = railtimeChatbot({ attachmentCount: 1 });
    attachAlpineHarness(chatbot);
    chatbot.$refs.attachmentList = {
        querySelectorAll: () => [{}],
    };
    chatbot.$wire.$hook = (name, callback) => {
        assert.equal(name, 'commit');
        commitHook = callback;

        return () => { commitHook = null; };
    };
    chatbot.$wire.discardAttachments = (cleanupId) => {
        sweeps += 1;
        if (sweeps === 1) {
            window.setTimeout(() => {
                commitHook({
                    commit: {
                        calls: [{ method: '_finishUpload', params: ['attachments', ['late.tmp']] }],
                    },
                    succeed: (callback) => { finishCommit = callback; },
                    fail() {},
                });
                window.setTimeout(() => {
                    chatbot.completeAttachmentUpload();
                    finishCommit();
                }, 20);
            }, 20);

            return Promise.resolve({ cleanup_id: cleanupId, remaining: 0 });
        }

        return new Promise((resolve) => {
            finishSecondSweep = () => resolve({ cleanup_id: cleanupId, remaining: 0 });
        });
    };
    chatbot.init();

    listeners.get('livewire:navigate')({
        detail: { url: '/wagon-lists' },
        preventDefault() {},
    });
    await waitFor(() => sweeps === 2, 1_500);

    assert.deepEqual(navigations, []);
    finishSecondSweep();
    await waitFor(() => navigations.length === 1, 1_500);

    assert.equal(sweeps, 2);
    assert.equal(chatbot.attachmentsMayExistOnServer, false);
    assert.equal(chatbot.attachmentCount, 0);
    chatbot.destroy();
});

test('composer actions are locked while attachment navigation cleanup is active', () => {
    installBrowserEnvironment();
    const chatbot = railtimeChatbot({ attachmentCount: 1, assistantAvailable: true });
    attachAlpineHarness(chatbot);
    let quickActions = 0;
    chatbot.$wire.quickAction = () => { quickActions += 1; };
    chatbot.composerHasText = true;
    chatbot.wagonHelpVisible = true;
    chatbot.navigationCleanupInFlight = true;
    const input = { files: [{}], value: 'selected' };

    assert.equal(chatbot.canSubmit(), false);
    assert.equal(chatbot.handleAttachmentSelection({ target: input }), false);
    assert.equal(input.value, '');
    assert.equal(chatbot.markAttachmentRemoval(), false);
    assert.equal(chatbot.runWagonHelpAction(), false);
    assert.equal(chatbot.runPetBubbleAction('wagon_voice_start'), false);
    assert.equal(chatbot.wagonHelpVisible, true);
    assert.equal(quickActions, 0);
    assert.equal(chatbot.attachmentCount, 1);
});

test('recorded STT and cleanup stay wired to the shared microphone holder', async () => {
    const source = await readFile(sourceUrl, 'utf8');

    assert.match(source, /acquireMicrophoneStream/);
    assert.match(source, /holdMicrophoneStream/);
    assert.match(source, /releaseMicrophoneStream/);
    assert.match(source, /VOICE_CAPTURE_LIMIT_MS\s*=\s*45_000/);
    assert.match(source, /new AbortController\(\)/);
    assert.match(source, /formData\.append\(\s*['"]audio['"]/);
    assert.match(source, /prefers-reduced-motion:\s*reduce/);
    assert.match(source, /audio\.onplaying\s*=\s*\(\)\s*=>/);
    assert.match(source, /audio\.ontimeupdate\s*=\s*updateProgress/);
    assert.match(source, /PET_BUBBLE_CYCLE_MS/);
    assert.match(source, /PET_BUBBLE_VISIBLE_MS\s*=\s*4_800/);
    assert.match(source, /async handlePetClick\(\)/);
    assert.match(source, /PHRASE_AUDIO_CACHE_MAX_ITEMS\s*=\s*8/);
    assert.match(source, /showPetBubble\(this\.strings\.petReplyReady, PET_BUBBLE_VISIBLE_MS, true\)/);
    assert.match(source, /clearPetBubbleTimers\(\)/);
    assert.match(source, /visibilitychange/);
    assert.match(source, /petBubbleAnnounce/);
    assert.match(source, /speechStatusEndpoint/);
    assert.match(source, /SPEECH_STATUS_RETRY_DELAYS_MS/);
    assert.match(source, /manualVoiceAvailable/);
    assert.match(source, /attachmentUploadActive/);
    assert.match(source, /handleComposerEnter/);
});
