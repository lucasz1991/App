import {
    acquireMicrophoneStream,
    holdMicrophoneStream,
    releaseMicrophoneStream,
} from './microphone-stream.js';

// Keep the assistant presentation isolated from the already dense app/chat
// styles. Vite still discovers and bundles this lazy CSS import, while Node's
// native test runner can import the behavior module without a CSS loader.
if (typeof document !== 'undefined') {
    void import('../css/chatbot.css');
}

const CHATBOT_DESKTOP_QUERY = '(min-width: 1140px)';
const VOICE_CAPTURE_LIMIT_MS = 45_000;
const MAX_TTS_TEXT_LENGTH = 4_000;
const MAX_KNOWN_MESSAGE_KEYS = 500;
const PET_BUBBLE_INITIAL_DELAY_MS = 1_200;
const PET_BUBBLE_VISIBLE_MS = 4_800;
const PET_BUBBLE_CYCLE_MS = 38_000;

const DEFAULT_STRINGS = {
    audioEndpointUnavailable: 'Die Audioausgabe ist momentan nicht erreichbar.',
    audioPlaybackBlocked: 'Der Browser hat die Audiowiedergabe blockiert. Bitte erneut auf Vorlesen tippen.',
    audioPlaybackFailed: 'Audio konnte nicht abgespielt werden.',
    audioStopped: 'Audioausgabe abgebrochen.',
    microphoneBlocked: 'Das Mikrofon ist im Browser blockiert. Bitte über das Schloss-Symbol erlauben.',
    microphoneFailed: 'Das Mikrofon konnte nicht verwendet werden.',
    recordingUnsupported: 'Sprachaufnahme wird von diesem Browser nicht unterstützt.',
    speechEndpointUnavailable: 'Die Spracheingabe ist momentan nicht erreichbar.',
    speechNoText: 'Es wurde kein gesprochener Text erkannt.',
    speechPrefix: 'Spracheingabe',
    petGreeting: 'Hi, ich bin dein RailTime-Begleiter.',
    petHint: 'Was möchtest du heute in RailTime erledigen?',
    petVoiceHint: 'Du kannst deine Frage auch einfach einsprechen.',
    petReplyReady: 'Ich habe eine Antwort für dich.',
    petUnavailable: 'Ich mache gerade eine kurze Pause.',
};

function clamp(value, min, max, fallback) {
    const parsed = Number(value);

    return Math.min(max, Math.max(min, Number.isFinite(parsed) ? parsed : fallback));
}

function safeStorage(storageName) {
    try {
        return typeof window === 'undefined' ? null : (window[storageName] ?? null);
    } catch (_) {
        return null;
    }
}

function normalizedEventDetail(detail) {
    return Array.isArray(detail) ? (detail[0] ?? {}) : (detail ?? {});
}

/**
 * Alpine behavior for the global RailTime assistant.
 *
 * Registered in app.js as:
 * Alpine.data('railtimeChatbot', railtimeChatbot)
 */
export function railtimeChatbot(config = {}) {
    const strings = { ...DEFAULT_STRINGS, ...(config.strings ?? {}) };

    return {
        open: false,
        isDesktopDocked: false,
        assistantAvailable: config.assistantAvailable !== false,
        speechAvailable: config.speechAvailable !== false,
        isLoading: config.isLoading ?? false,
        ttsEndpoint: String(config.ttsEndpoint ?? ''),
        sttEndpoint: String(config.sttEndpoint ?? ''),
        csrfToken: String(config.csrfToken ?? ''),
        pageRouteName: String(config.pageRouteName ?? ''),
        pageHelpHint: String(config.pageHelpHint ?? '').trim(),
        strings,

        settingsOpen: false,
        autoRead: Boolean(config.autoReadDefault),
        autoListen: Boolean(config.autoListenDefault),
        autoHelp: config.autoHelpDefault !== false,
        speechRate: clamp(config.speechRate, 0.5, 2, 1),
        speechSupported: false,
        voiceSupported: false,
        audioError: '',

        knownAssistantMessageKeys: Array.isArray(config.initialAssistantKeys)
            ? config.initialAssistantKeys.map(String).slice(-MAX_KNOWN_MESSAGE_KEYS)
            : [],

        ttsQueue: [],
        ttsWorkerActive: false,
        ttsPreparing: false,
        ttsPlaying: false,
        ttsActiveKey: null,
        ttsActiveText: null,
        ttsAbortController: null,
        ttsAudio: null,
        ttsPlaybackCancel: null,
        ttsObjectUrls: [],
        ttsCurrentGeneration: 0,
        speaking: false,
        speakingKey: null,

        mediaRecorder: null,
        mediaStream: null,
        voiceChunks: [],
        voiceCaptureGeneration: 0,
        voiceCaptureTimer: null,
        voiceDurationTimer: null,
        recording: false,
        recordingSeconds: 0,
        voiceUploading: false,
        sttAbortController: null,

        messagesPinned: true,
        messageObserver: null,
        scrollFrame: null,
        dockMediaQuery: null,
        petBubbleText: '',
        petBubbleVisible: false,
        petBubbleAnnounce: false,
        petBubbleOrigin: null,
        petHintIndex: 0,
        petBubbleTimer: null,
        petBubbleCycleTimer: null,
        autoListenTimer: null,
        autoListenGeneration: 0,
        autoListenChecking: false,
        _dockChangeHandler: null,
        _windowResizeHandler: null,
        _navigationHandler: null,
        _visibilityHandler: null,

        init() {
            this.dockMediaQuery = window.matchMedia?.(CHATBOT_DESKTOP_QUERY) ?? null;
            this.isDesktopDocked = this.dockMediaQuery
                ? this.dockMediaQuery.matches
                : window.innerWidth >= 1140;

            this.autoRead = this.readBool('railtime-chatbot-auto-read', this.autoRead);
            this.autoListen = this.readBool('railtime-chatbot-auto-listen', this.autoListen);
            this.autoHelp = this.readBool('railtime-chatbot-auto-help', this.autoHelp);
            this.speechRate = this.readNumber('railtime-chatbot-speech-rate', this.speechRate);
            this.open = this.isDesktopDocked
                && safeStorage('sessionStorage')?.getItem('railtime-chatbot-open') === '1';

            this.speechSupported = Boolean(
                this.speechAvailable
                && this.ttsEndpoint
                && window.fetch
                && window.Audio
                && window.URL,
            );
            this.voiceSupported = this.recordedVoiceSupported();

            this._dockChangeHandler = (event) => {
                this.isDesktopDocked = Boolean(event.matches);
                this.$nextTick(() => this.scrollMessages(false));
            };
            this._navigationHandler = () => {
                this.closeSettings(false);
                this.abortSpeechInput();
                this.stopSpeaking();
            };
            this._visibilityHandler = () => {
                if (document.hidden) {
                    this.closeSettings(false);
                    this.abortSpeechInput();
                    this.clearPetBubbleTimers();
                    return;
                }

                if (!this.open && this.autoHelp) this.schedulePetBubble(false);
            };

            if (this.dockMediaQuery?.addEventListener) {
                this.dockMediaQuery.addEventListener('change', this._dockChangeHandler);
            } else {
                this._windowResizeHandler = () => this.syncDockLayout();
                window.addEventListener('resize', this._windowResizeHandler);
            }
            document.addEventListener('livewire:navigating', this._navigationHandler);
            document.addEventListener('visibilitychange', this._visibilityHandler);

            this.$watch('open', (value) => {
                safeStorage('sessionStorage')?.setItem('railtime-chatbot-open', value ? '1' : '0');

                if (!value) {
                    this.closeSettings(false);
                    this.abortSpeechInput();
                    this.stopSpeaking();
                    if (this.autoHelp) this.schedulePetBubble(false);
                    return;
                }

                this.hidePetBubble();
                this.$nextTick(() => {
                    this.observeMessages();
                    this.scrollMessages(true);
                });
            });
            this.$watch('autoRead', (value) => {
                safeStorage('localStorage')?.setItem('railtime-chatbot-auto-read', value ? '1' : '0');
                if (!value) this.stopSpeaking();
            });
            this.$watch('autoListen', (value) => {
                safeStorage('localStorage')?.setItem('railtime-chatbot-auto-listen', value ? '1' : '0');
                if (!value) this.abortSpeechInput();
            });
            this.$watch('autoHelp', (value) => {
                safeStorage('localStorage')?.setItem('railtime-chatbot-auto-help', value ? '1' : '0');
                if (!value) {
                    this.stopProactivePetBubbles();
                    return;
                }

                if (!this.open && !document.hidden) this.schedulePetBubble(true);
            });
            this.$watch('speechRate', (value) => {
                const normalized = this.clampSpeechRate(value);
                if (normalized !== value) {
                    this.speechRate = normalized;
                    return;
                }
                safeStorage('localStorage')?.setItem('railtime-chatbot-speech-rate', String(normalized));
            });

            this.$nextTick(() => {
                this.observeMessages();
                this.scrollMessages(false, true);
            });

            if (this.autoHelp) this.schedulePetBubble(true);
        },

        destroy() {
            if (this.dockMediaQuery?.removeEventListener && this._dockChangeHandler) {
                this.dockMediaQuery.removeEventListener('change', this._dockChangeHandler);
            }
            if (this._windowResizeHandler) {
                window.removeEventListener('resize', this._windowResizeHandler);
                this._windowResizeHandler = null;
            }
            if (this._navigationHandler) {
                document.removeEventListener('livewire:navigating', this._navigationHandler);
            }
            if (this._visibilityHandler) {
                document.removeEventListener('visibilitychange', this._visibilityHandler);
            }

            this.messageObserver?.disconnect();
            this.messageObserver = null;
            window.cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
            this.abortSpeechInput();
            this.stopSpeaking();
            this.clearPetBubbleTimers();
            this.settingsOpen = false;
            releaseMicrophoneStream();
        },

        petState() {
            if (!this.assistantAvailable) return 'offline';
            if (this.recording || this.voiceUploading) return 'listening';
            if (this.ttsActive()) return 'speaking';
            if (this.isLoading) return 'thinking';

            return 'idle';
        },

        petHints() {
            const hints = [this.strings.petGreeting, this.strings.petHint];

            if (this.voiceSupported) hints.push(this.strings.petVoiceHint);

            return hints.map((hint) => String(hint ?? '').trim()).filter(Boolean);
        },

        pageHelpStorageKey() {
            const route = String(this.pageRouteName ?? '').trim();

            return route ? `railtime-chatbot-page-help:${route}` : '';
        },

        nextProactivePetHint() {
            const pageHelpKey = this.pageHelpStorageKey();
            const sessionStorage = safeStorage('sessionStorage');

            if (
                this.pageHelpHint
                && pageHelpKey
                && sessionStorage?.getItem(pageHelpKey) !== '1'
            ) {
                sessionStorage?.setItem(pageHelpKey, '1');

                return this.pageHelpHint;
            }

            return '';
        },

        showPetBubble(text, duration = PET_BUBBLE_VISIBLE_MS, announce = false, origin = null) {
            const message = String(text ?? '').trim();
            if (!message || this.open) return;

            window.clearTimeout(this.petBubbleTimer);
            this.petBubbleAnnounce = Boolean(announce);
            this.petBubbleOrigin = origin ?? (announce ? 'reply' : 'manual');
            this.petBubbleText = message;
            this.petBubbleVisible = true;
            this.petBubbleTimer = window.setTimeout(() => {
                this.petBubbleVisible = false;
                this.petBubbleAnnounce = false;
                this.petBubbleOrigin = null;
                this.petBubbleTimer = null;
            }, Math.max(1_500, Number(duration) || PET_BUBBLE_VISIBLE_MS));
        },

        hidePetBubble() {
            window.clearTimeout(this.petBubbleTimer);
            this.petBubbleTimer = null;
            this.petBubbleVisible = false;
            this.petBubbleAnnounce = false;
            this.petBubbleOrigin = null;
        },

        schedulePetBubble(initial = false) {
            window.clearTimeout(this.petBubbleCycleTimer);
            this.petBubbleCycleTimer = null;

            if (!this.autoHelp || this.open || document.hidden) return;
            const pageHelpKey = this.pageHelpStorageKey();
            if (
                !this.pageHelpHint
                || !pageHelpKey
                || safeStorage('sessionStorage')?.getItem(pageHelpKey) === '1'
            ) return;

            const delay = initial ? PET_BUBBLE_INITIAL_DELAY_MS : PET_BUBBLE_CYCLE_MS;
            this.petBubbleCycleTimer = window.setTimeout(() => {
                this.petBubbleCycleTimer = null;
                if (!this.autoHelp || this.open || document.hidden) return;
                if (!this.assistantAvailable) return;

                this.showPetBubble(this.nextProactivePetHint(), PET_BUBBLE_VISIBLE_MS, false, 'proactive');
            }, delay);
        },

        stopProactivePetBubbles() {
            window.clearTimeout(this.petBubbleCycleTimer);
            this.petBubbleCycleTimer = null;
            if (this.petBubbleOrigin === 'proactive') this.hidePetBubble();
        },

        clearPetBubbleTimers() {
            window.clearTimeout(this.petBubbleTimer);
            window.clearTimeout(this.petBubbleCycleTimer);
            this.petBubbleTimer = null;
            this.petBubbleCycleTimer = null;
            this.petBubbleVisible = false;
            this.petBubbleAnnounce = false;
            this.petBubbleOrigin = null;
        },

        readBool(key, fallback) {
            const stored = safeStorage('localStorage')?.getItem(key);

            return stored === null || stored === undefined ? Boolean(fallback) : stored === '1';
        },

        readNumber(key, fallback) {
            const stored = safeStorage('localStorage')?.getItem(key);

            return this.clampSpeechRate(stored === null || stored === undefined ? fallback : stored);
        },

        clampSpeechRate(value) {
            return clamp(value, 0.5, 2, 1);
        },

        toggleSettings() {
            if (this.settingsOpen) {
                this.closeSettings(true);
                return;
            }

            this.settingsOpen = true;
            this.$nextTick(() => {
                const panel = this.$refs?.settingsPanel;
                const focusTarget = panel?.querySelector?.(
                    '[autofocus], button:not([disabled]), select:not([disabled]), input:not([disabled])',
                );
                focusTarget?.focus?.({ preventScroll: true });
            });
        },

        closeSettings(restoreFocus = false) {
            if (!this.settingsOpen) return;

            this.settingsOpen = false;
            if (!restoreFocus) return;

            this.$nextTick(() => {
                const trigger = this.$refs?.settingsTrigger ?? this.$refs?.settingsButton;
                trigger?.focus?.({ preventScroll: true });
            });
        },

        syncDockLayout() {
            this.isDesktopDocked = this.dockMediaQuery
                ? this.dockMediaQuery.matches
                : window.innerWidth >= 1140;
        },

        setOpen(value, focusComposer = false) {
            const wasOpen = this.open;
            this.open = Boolean(value);
            if (!this.open) {
                this.closeSettings(false);
                this.abortSpeechInput();
                if (this.autoHelp) this.schedulePetBubble(false);
                if (wasOpen) this.$nextTick(() => this.$refs.launcher?.focus({ preventScroll: true }));
                return;
            }

            this.hidePetBubble();
            this.$nextTick(() => {
                this.scrollMessages(true);
                if (focusComposer) {
                    this.$refs.composer?.focus({ preventScroll: true });
                }
            });
        },

        toggleOpen() {
            this.setOpen(!this.open, !this.open);
        },

        prefersReducedMotion() {
            return Boolean(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches);
        },

        scrollBehavior(animate = true) {
            return animate && !this.prefersReducedMotion() ? 'smooth' : 'auto';
        },

        observeMessages() {
            this.messageObserver?.disconnect();
            this.messageObserver = null;

            if (!this.$refs.messages || typeof MutationObserver === 'undefined') return;

            this.messageObserver = new MutationObserver(() => this.scrollMessages(false));
            this.messageObserver.observe(this.$refs.messages, {
                childList: true,
                subtree: true,
                characterData: true,
            });
        },

        handleMessagesScroll() {
            const messages = this.$refs.messages;
            if (!messages) return;

            const bottomGap = messages.scrollHeight - messages.scrollTop - messages.clientHeight;
            this.messagesPinned = bottomGap <= 72;
        },

        scrollMessages(force = false, immediate = false) {
            if (!force && !this.messagesPinned) return;

            window.cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = window.requestAnimationFrame(() => {
                const messages = this.$refs.messages;
                if (!messages) return;

                messages.scrollTo({
                    top: messages.scrollHeight,
                    behavior: this.scrollBehavior(!immediate),
                });
                this.messagesPinned = true;
            });
        },

        hashMessage(value) {
            let hash = 2166136261;
            const input = String(value ?? '');

            for (let index = 0; index < input.length; index += 1) {
                hash ^= input.charCodeAt(index);
                hash = Math.imul(hash, 16777619);
            }

            return (hash >>> 0).toString(36);
        },

        assistantMessageKey(item) {
            if (!item || typeof item !== 'object') return 'assistant:missing';

            const persisted = item.id ?? item.key ?? item.uuid;
            if (persisted !== null && persisted !== undefined && String(persisted) !== '') {
                return `assistant:${String(persisted)}`;
            }

            const time = item.time ?? item.created_at ?? item.createdAt ?? '';
            const content = item.content ?? item.text ?? '';

            return `assistant:fallback:${this.hashMessage(`${time}|${content}`)}`;
        },

        rememberAssistantKey(key) {
            const normalized = String(key ?? '');
            if (!normalized || this.knownAssistantMessageKeys.includes(normalized)) return false;

            this.knownAssistantMessageKeys = [
                ...this.knownAssistantMessageKeys,
                normalized,
            ].slice(-MAX_KNOWN_MESSAGE_KEYS);

            return true;
        },

        handleAssistantReply(rawDetail) {
            const detail = normalizedEventDetail(rawDetail);
            const text = String(detail.text ?? detail.content ?? '').trim();
            if (!text) return;

            const key = detail.key
                ? `assistant:${String(detail.key)}`
                : this.assistantMessageKey(detail);

            if (!this.rememberAssistantKey(key)) return;

            if (!this.open) this.showPetBubble(this.strings.petReplyReady, PET_BUBBLE_VISIBLE_MS, true);
            if (this.autoRead && this.speechSupported) {
                this.queueTtsSentence(text, key);
            }
            this.$nextTick(() => {
                this.scrollMessages(false);
                this.scheduleAutoListenAfterReply();
            });
        },

        composerDraft() {
            const value = this.$refs?.composer?.value ?? this.$wire?.message ?? '';

            return String(value ?? '').trim();
        },

        cancelPendingAutoListen() {
            this.autoListenGeneration += 1;
            window.clearTimeout(this.autoListenTimer);
            this.autoListenTimer = null;
            this.autoListenChecking = false;
        },

        scheduleAutoListenAfterReply() {
            this.cancelPendingAutoListen();
            if (!this.autoListen) return;

            const generation = this.autoListenGeneration;
            const attempt = () => {
                this.autoListenTimer = null;
                if (generation !== this.autoListenGeneration || !this.autoListen) return;
                if (!this.open || document.hidden) return;

                this.voiceSupported = this.recordedVoiceSupported();
                if (!this.voiceSupported || this.recording || this.voiceUploading) return;

                if (this.isLoading || this.ttsActive()) {
                    this.autoListenTimer = window.setTimeout(attempt, 120);
                    return;
                }
                if (this.composerDraft()) return;

                void this.toggleVoice();
            };

            this.autoListenTimer = window.setTimeout(attempt, 0);
        },

        async setAutoListen(value, input = null) {
            const enabled = Boolean(value);
            if (!enabled) {
                this.cancelPendingAutoListen();
                this.autoListen = false;
                this.abortSpeechInput();
                if (input) input.checked = false;
                return false;
            }
            if (this.autoListen || this.autoListenChecking) {
                if (input) input.checked = this.autoListen;
                return this.autoListen;
            }

            this.voiceSupported = this.recordedVoiceSupported();
            if (!this.voiceSupported) {
                this.autoListen = false;
                this.audioError = this.sttEndpoint
                    ? this.strings.recordingUnsupported
                    : this.strings.speechEndpointUnavailable;
                if (input) input.checked = false;
                return false;
            }

            this.cancelPendingAutoListen();
            const generation = this.autoListenGeneration;
            this.autoListenChecking = true;
            this.audioError = '';

            try {
                await acquireMicrophoneStream();
                if (generation !== this.autoListenGeneration) {
                    releaseMicrophoneStream();
                    return false;
                }

                // The toggle only verifies consent/capability. Do not keep an
                // invisible live track open before a visible recording starts.
                releaseMicrophoneStream();
                this.autoListen = true;

                return true;
            } catch (error) {
                if (generation !== this.autoListenGeneration || error?.name === 'AbortError') return false;

                releaseMicrophoneStream();
                this.autoListen = false;
                const blocked = error?.name === 'NotAllowedError' || error?.name === 'SecurityError';
                this.audioError = `${this.strings.speechPrefix}: ${blocked ? this.strings.microphoneBlocked : this.strings.microphoneFailed}`;

                return false;
            } finally {
                if (generation === this.autoListenGeneration) this.autoListenChecking = false;
                if (input) input.checked = this.autoListen;
            }
        },

        ttsActive() {
            return this.ttsWorkerActive
                || this.ttsPreparing
                || this.ttsPlaying
                || this.ttsQueue.length > 0;
        },

        speak(text, key = null) {
            if (!this.speechSupported) {
                this.audioError = this.strings.audioEndpointUnavailable;
                return;
            }

            const cleanText = String(text ?? '').trim().slice(0, MAX_TTS_TEXT_LENGTH);
            if (!cleanText) return;

            if (this.ttsActive() && this.ttsActiveKey === key && this.ttsActiveText === cleanText) return;

            this.stopSpeaking();
            this.audioError = '';
            this.queueTtsSentence(cleanText, key);
        },

        queueTtsSentence(text, key = null) {
            const cleanText = String(text ?? '').trim().slice(0, MAX_TTS_TEXT_LENGTH);
            if (!cleanText || !this.speechSupported) return;

            const duplicateIsActive = this.ttsActiveKey === key && this.ttsActiveText === cleanText;
            const duplicateIsQueued = this.ttsQueue.some((item) => (
                item.generation === this.ttsCurrentGeneration
                && item.key === key
                && item.text === cleanText
            ));

            if (duplicateIsActive || duplicateIsQueued) return;

            this.ttsQueue.push({
                text: cleanText,
                key,
                generation: this.ttsCurrentGeneration,
            });
            void this.playNextTts();
        },

        async playNextTts() {
            if (this.ttsWorkerActive || !this.ttsQueue.length) return;

            const item = this.ttsQueue.shift();
            if (!item || item.generation !== this.ttsCurrentGeneration) {
                void this.playNextTts();
                return;
            }

            this.ttsWorkerActive = true;
            this.ttsPreparing = true;
            this.ttsPlaying = false;
            this.speaking = false;
            this.speakingKey = null;
            this.ttsActiveKey = item.key;
            this.ttsActiveText = item.text;

            try {
                await this.playTtsViaBlob(item.text, item.key, item.generation);
            } catch (error) {
                if (item.generation === this.ttsCurrentGeneration && error?.name !== 'AbortError') {
                    this.audioError = this.audioErrorMessage(error);
                }
            } finally {
                if (item.generation !== this.ttsCurrentGeneration) return;

                this.ttsWorkerActive = false;
                this.ttsPreparing = false;
                this.ttsPlaying = false;
                this.speaking = false;
                this.speakingKey = null;

                if (this.ttsQueue.length) {
                    void this.playNextTts();
                } else {
                    this.ttsActiveKey = null;
                    this.ttsActiveText = null;
                }
            }
        },

        ttsFetchOptions(text, abortController) {
            return {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'audio/wav, application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: abortController.signal,
                body: JSON.stringify({
                    text,
                    speed: this.clampSpeechRate(this.speechRate),
                }),
            };
        },

        ttsAbortError() {
            const error = new Error(this.strings.audioStopped);
            error.name = 'AbortError';

            return error;
        },

        assertTtsGeneration(generation) {
            if (generation !== this.ttsCurrentGeneration) throw this.ttsAbortError();
        },

        async playTtsViaBlob(text, key, generation) {
            if (!this.ttsEndpoint) throw new Error(this.strings.audioEndpointUnavailable);

            const abortController = new AbortController();
            this.ttsAbortController = abortController;
            let objectUrl = null;

            try {
                const response = await fetch(this.ttsEndpoint, this.ttsFetchOptions(text, abortController));
                this.assertTtsGeneration(generation);

                if (!response.ok) throw new Error(await this.responseError(response));

                const blob = await response.blob();
                this.assertTtsGeneration(generation);
                objectUrl = URL.createObjectURL(blob);
                this.ttsObjectUrls.push(objectUrl);

                await this.playAudioUrl(objectUrl, key, generation);
            } finally {
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                    this.ttsObjectUrls = this.ttsObjectUrls.filter((entry) => entry !== objectUrl);
                }
                if (this.ttsAbortController === abortController) this.ttsAbortController = null;
            }
        },

        playAudioUrl(url, key, generation) {
            return new Promise((resolve, reject) => {
                const audio = new Audio(url);
                this.ttsAudio = audio;
                audio.preload = 'auto';
                let settled = false;

                const resetPlaybackState = () => {
                    if (generation !== this.ttsCurrentGeneration) return;
                    this.ttsPreparing = false;
                    this.ttsPlaying = false;
                    this.speaking = false;
                    this.speakingKey = null;
                };
                const cleanup = () => {
                    audio.onplaying = null;
                    audio.onwaiting = null;
                    audio.onpause = null;
                    audio.onended = null;
                    audio.onerror = null;
                    if (this.ttsAudio === audio) this.ttsAudio = null;
                    if (this.ttsPlaybackCancel === cancelPlayback) this.ttsPlaybackCancel = null;
                };
                const settle = (callback, value) => {
                    if (settled) return;
                    settled = true;
                    cleanup();
                    callback(value);
                };
                const cancelPlayback = () => settle(reject, this.ttsAbortError());

                this.ttsPlaybackCancel = cancelPlayback;
                audio.onplaying = () => {
                    if (generation !== this.ttsCurrentGeneration) {
                        cancelPlayback();
                        return;
                    }

                    this.ttsPreparing = false;
                    this.ttsPlaying = true;
                    this.speaking = true;
                    this.speakingKey = key;
                };
                audio.onwaiting = () => {
                    if (generation !== this.ttsCurrentGeneration) return;
                    this.ttsPreparing = true;
                    this.ttsPlaying = false;
                    this.speaking = false;
                    this.speakingKey = null;
                };
                audio.onpause = () => {
                    resetPlaybackState();
                    cancelPlayback();
                };
                audio.onended = () => {
                    resetPlaybackState();
                    settle(resolve);
                };
                audio.onerror = () => {
                    resetPlaybackState();
                    settle(reject, new Error(this.strings.audioPlaybackFailed));
                };

                document.querySelectorAll?.('audio').forEach((element) => {
                    if (!element.paused) element.pause();
                });
                audio.play().catch((error) => {
                    resetPlaybackState();
                    settle(reject, error);
                });
            });
        },

        async responseError(response) {
            const raw = await response.text();
            const headerConnectionId = response.headers?.get?.('X-AI-Connection-ID');
            const headerRequestId = response.headers?.get?.('X-Request-ID');

            try {
                const payload = JSON.parse(raw);
                const message = payload?.detail || payload?.message || `HTTP ${response.status}`;
                const connectionId = payload?.connection_id
                    || payload?.request_id
                    || headerConnectionId
                    || headerRequestId;

                return connectionId ? `${message} (Referenz: ${connectionId})` : message;
            } catch (_) {
                const message = raw || `HTTP ${response.status}`;

                const connectionId = headerConnectionId || headerRequestId;

                return connectionId ? `${message} (Referenz: ${connectionId})` : message;
            }
        },

        audioErrorMessage(error) {
            const message = String(error?.message || error || this.strings.audioPlaybackFailed);

            if (message.includes('Failed to fetch')) return this.strings.audioEndpointUnavailable;
            if (error?.name === 'NotAllowedError') return this.strings.audioPlaybackBlocked;

            return message.length > 420 ? `${message.slice(0, 420)}…` : message;
        },

        stopSpeaking() {
            this.ttsCurrentGeneration += 1;
            this.ttsQueue = [];
            this.ttsAbortController?.abort();
            this.ttsAbortController = null;

            const audio = this.ttsAudio;
            const cancelPlayback = this.ttsPlaybackCancel;
            this.ttsPlaybackCancel = null;
            cancelPlayback?.();
            if (audio) {
                audio.pause();
                audio.src = '';
            }
            this.ttsAudio = null;

            this.ttsObjectUrls.forEach((url) => URL.revokeObjectURL(url));
            this.ttsObjectUrls = [];
            this.ttsWorkerActive = false;
            this.ttsPreparing = false;
            this.ttsPlaying = false;
            this.ttsActiveKey = null;
            this.ttsActiveText = null;
            this.speaking = false;
            this.speakingKey = null;
        },

        recordedVoiceSupported() {
            return Boolean(
                this.speechAvailable
                && this.sttEndpoint
                && window.fetch
                && window.FormData
                && window.MediaRecorder
                && navigator.mediaDevices
                && typeof navigator.mediaDevices.getUserMedia === 'function',
            );
        },

        supportedRecordedMimeType() {
            const candidates = [
                'audio/webm;codecs=opus',
                'audio/webm',
                'audio/ogg;codecs=opus',
                'audio/ogg',
                'audio/mp4',
                'audio/wav',
            ];

            return candidates.find((type) => MediaRecorder.isTypeSupported(type)) || '';
        },

        recordedAudioExtension(mimeType) {
            const normalized = String(mimeType ?? '').toLowerCase();
            if (normalized.includes('ogg')) return 'ogg';
            if (normalized.includes('wav')) return 'wav';
            if (normalized.includes('mp4')) return 'm4a';

            return 'webm';
        },

        async toggleVoice() {
            if (this.recording) {
                this.stopListening();
                return;
            }

            if (this.voiceUploading || this.isLoading || this.ttsActive()) return;

            this.voiceSupported = this.recordedVoiceSupported();
            if (!this.voiceSupported) {
                this.audioError = this.sttEndpoint
                    ? this.strings.recordingUnsupported
                    : this.strings.speechEndpointUnavailable;
                return;
            }

            this.audioError = '';
            this.voiceChunks = [];
            const captureGeneration = ++this.voiceCaptureGeneration;

            try {
                const stream = await acquireMicrophoneStream();
                if (captureGeneration !== this.voiceCaptureGeneration) {
                    releaseMicrophoneStream();
                    return;
                }

                const mimeType = this.supportedRecordedMimeType();
                const recorder = mimeType
                    ? new MediaRecorder(stream, { mimeType })
                    : new MediaRecorder(stream);

                this.mediaStream = stream;
                this.mediaRecorder = recorder;
                recorder.ondataavailable = (event) => {
                    if (captureGeneration !== this.voiceCaptureGeneration) return;
                    if (event?.data?.size > 0) this.voiceChunks.push(event.data);
                };
                recorder.onstop = () => {
                    if (captureGeneration !== this.voiceCaptureGeneration || this.mediaRecorder !== recorder) return;
                    void this.finishRecordedCapture(captureGeneration, recorder);
                };
                recorder.onerror = (event) => {
                    if (captureGeneration !== this.voiceCaptureGeneration) return;
                    this.clearVoiceCaptureState();
                    this.mediaRecorder = null;
                    this.mediaStream = null;
                    releaseMicrophoneStream();
                    this.audioError = `${this.strings.speechPrefix}: ${event?.error?.message || this.strings.microphoneFailed}`;
                };

                recorder.start(250);
                this.recording = true;
                this.recordingSeconds = 0;
                this.voiceDurationTimer = window.setInterval(() => {
                    this.recordingSeconds = Math.min(45, this.recordingSeconds + 1);
                }, 1_000);
                this.voiceCaptureTimer = window.setTimeout(() => this.stopListening(), VOICE_CAPTURE_LIMIT_MS);
            } catch (error) {
                if (captureGeneration !== this.voiceCaptureGeneration) return;

                this.clearVoiceCaptureState();
                this.mediaRecorder = null;
                this.mediaStream = null;
                releaseMicrophoneStream();
                const blocked = error?.name === 'NotAllowedError' || error?.name === 'SecurityError';
                this.audioError = `${this.strings.speechPrefix}: ${blocked ? this.strings.microphoneBlocked : this.strings.microphoneFailed}`;
            }
        },

        stopListening() {
            const recorder = this.mediaRecorder;
            this.clearVoiceCaptureState();

            if (recorder && recorder.state !== 'inactive') {
                try {
                    recorder.stop();
                } catch (error) {
                    this.mediaRecorder = null;
                    this.mediaStream = null;
                    releaseMicrophoneStream();
                    this.audioError = `${this.strings.speechPrefix}: ${error?.message || this.strings.microphoneFailed}`;
                }
                return;
            }

            this.mediaRecorder = null;
            this.mediaStream = null;
            holdMicrophoneStream();
        },

        async finishRecordedCapture(captureGeneration, recorder) {
            const chunks = this.voiceChunks;
            const mimeType = recorder?.mimeType || chunks[0]?.type || 'audio/webm';

            this.mediaRecorder = null;
            this.mediaStream = null;
            this.voiceChunks = [];
            this.clearVoiceCaptureState();
            holdMicrophoneStream();

            if (captureGeneration !== this.voiceCaptureGeneration || !chunks.length) return;

            await this.transcribeRecordedBlob(new Blob(chunks, { type: mimeType }));
        },

        async transcribeRecordedBlob(blob) {
            if (!this.sttEndpoint) {
                this.audioError = this.strings.speechEndpointUnavailable;
                return;
            }

            this.sttAbortController?.abort();
            const abortController = new AbortController();
            this.sttAbortController = abortController;
            this.voiceUploading = true;
            this.audioError = '';

            try {
                const formData = new FormData();
                formData.append(
                    'audio',
                    blob,
                    `railtime-assistant-audio.${this.recordedAudioExtension(blob.type)}`,
                );

                const response = await fetch(this.sttEndpoint, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: abortController.signal,
                    body: formData,
                });

                if (!response.ok) throw new Error(await this.responseError(response));

                const payload = await response.json();
                const transcript = String(payload?.text ?? '').trim();
                if (!transcript) throw new Error(this.strings.speechNoText);

                const currentDraft = String(this.$refs.composer?.value ?? '').trim();
                const combinedDraft = [currentDraft, transcript].filter(Boolean).join(' ');
                await this.$wire.set('message', combinedDraft);
                this.$nextTick(() => this.resizeComposer());
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    this.audioError = `${this.strings.speechPrefix}: ${this.audioErrorMessage(error)}`;
                }
            } finally {
                if (this.sttAbortController === abortController) {
                    this.sttAbortController = null;
                    this.voiceUploading = false;
                }
            }
        },

        clearVoiceCaptureState() {
            window.clearTimeout(this.voiceCaptureTimer);
            window.clearInterval(this.voiceDurationTimer);
            this.voiceCaptureTimer = null;
            this.voiceDurationTimer = null;
            this.recording = false;
        },

        cancelVoiceCapture() {
            this.voiceCaptureGeneration += 1;
            this.clearVoiceCaptureState();

            const recorder = this.mediaRecorder;
            this.mediaRecorder = null;
            this.mediaStream = null;
            this.voiceChunks = [];

            if (recorder) {
                recorder.ondataavailable = null;
                recorder.onstop = null;
                recorder.onerror = null;
                if (recorder.state !== 'inactive') {
                    try {
                        recorder.stop();
                    } catch (_) {
                        // The shared stream is invalidated below regardless.
                    }
                }
            }

            releaseMicrophoneStream();
        },

        abortSpeechInput() {
            this.cancelPendingAutoListen();
            this.cancelVoiceCapture();
            this.sttAbortController?.abort();
            this.sttAbortController = null;
            this.voiceUploading = false;
        },

        resizeComposer() {
            const composer = this.$refs.composer;
            if (!composer) return;

            composer.style.height = 'auto';
            composer.style.height = `${Math.min(composer.scrollHeight, 144)}px`;
        },

        recordingLabel() {
            const seconds = Math.max(0, Math.min(45, Number(this.recordingSeconds) || 0));

            return `0:${String(seconds).padStart(2, '0')}`;
        },
    };
}
