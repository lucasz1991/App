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
const SPEECH_STATUS_RETRY_DELAYS_MS = [2_000, 5_000, 15_000, 30_000];
const ATTACHMENT_UPLOAD_LIMIT = 3;
const SEEN_PAGE_HELP_KEYS = new Set();

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
    speechChecking: 'Sprachdienst wird geprüft …',
    speechReady: 'Text und Sprache sind bereit.',
    speechPartiallyReady: 'Der Sprachdienst ist teilweise verfügbar.',
    speechOffline: 'Text ist bereit, Sprache momentan nicht.',
    speechDisabled: 'Sprachfunktionen sind nicht eingerichtet.',
    localSpeechProvider: 'Lokaler Sprachdienst',
    attachmentUploadFailed: 'Die Datei konnte nicht hochgeladen werden.',
    attachmentUploadCancelled: 'Der Datei-Upload wurde abgebrochen.',
    petGreeting: 'Hi, ich bin dein RailTime-Begleiter.',
    petHint: 'Was möchtest du heute in RailTime erledigen?',
    petVoiceHint: 'Du kannst deine Frage auch einfach einsprechen.',
    petReplyReady: 'Ich habe eine Antwort für dich.',
    petUnavailable: 'Ich mache gerade eine kurze Pause.',
    wagonHelp: 'Soll ich dich per Sprache Schritt für Schritt durch diese Wagenliste führen?',
    wagonVoiceStart: 'Per Sprache starten',
};

function clamp(value, min, max, fallback) {
    const parsed = Number(value);

    return Math.min(max, Math.max(min, Number.isFinite(parsed) ? parsed : fallback));
}

function safeStorage(storageName) {
    try {
        const storage = typeof window === 'undefined' ? null : (window[storageName] ?? null);
        if (!storage) return null;

        return {
            getItem(key) {
                try {
                    return storage.getItem(key);
                } catch (_) {
                    return null;
                }
            },
            setItem(key, value) {
                try {
                    storage.setItem(key, value);

                    return true;
                } catch (_) {
                    return false;
                }
            },
            removeItem(key) {
                try {
                    storage.removeItem(key);

                    return true;
                } catch (_) {
                    return false;
                }
            },
        };
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
        speechStatusEndpoint: String(config.speechStatusEndpoint ?? ''),
        sttConfigured: config.sttConfigured === undefined
            ? Boolean(config.speechAvailable !== false && config.sttEndpoint)
            : Boolean(config.sttConfigured),
        ttsConfigured: config.ttsConfigured === undefined
            ? Boolean(config.speechAvailable !== false && config.ttsEndpoint)
            : Boolean(config.ttsConfigured),
        sttReady: config.sttReady === undefined
            ? Boolean(!config.speechStatusEndpoint && config.speechAvailable !== false && config.sttEndpoint)
            : Boolean(config.sttReady),
        ttsReady: config.ttsReady === undefined
            ? Boolean(!config.speechStatusEndpoint && config.speechAvailable !== false && config.ttsEndpoint)
            : Boolean(config.ttsReady),
        speechStatusState: config.speechStatusEndpoint ? 'checking' : 'static',
        speechRoutingLabel: String(config.speechRoutingLabel ?? '').trim(),
        externalFallback: Boolean(config.externalFallback),
        speechFallbackActive: false,
        speechActiveProvider: '',
        speechSttProvider: '',
        speechTtsProvider: '',
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

        speechStatusAbortController: null,
        speechStatusRetryTimer: null,
        speechStatusRetryAttempt: 0,
        speechStatusGeneration: 0,
        speechFailureGeneration: 0,

        attachmentUploadActive: false,
        attachmentUploadProgress: 0,
        attachmentUploadError: '',
        attachmentCount: Math.max(0, Number(config.attachmentCount) || 0),
        composerHasText: false,
        attachmentSyncTimer: null,
        attachmentObserver: null,

        messagesPinned: true,
        messageObserver: null,
        scrollFrame: null,
        dockMediaQuery: null,
        petBubbleText: '',
        petBubbleVisible: false,
        petBubbleAnnounce: false,
        petBubbleOrigin: null,
        petBubbleActionKey: '',
        petBubbleActionLabel: '',
        wagonHelpVisible: false,
        wagonHelpText: '',
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
        _onlineHandler: null,

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
                this.ttsConfigured
                && this.ttsEndpoint
                && window.fetch
                && window.Audio
                && window.URL,
            );
            this.voiceSupported = this.recordedVoiceSupported();
            this.composerHasText = Boolean(this.composerDraft());

            this._dockChangeHandler = (event) => {
                this.isDesktopDocked = Boolean(event.matches);
                this.$nextTick(() => this.scrollMessages(false));
            };
            this._navigationHandler = () => {
                this.closeSettings(false);
                this.abortSpeechInput();
                this.stopSpeaking();
                this.cancelSpeechStatusRefresh(true);
                this.discardPendingAttachments();
            };
            this._visibilityHandler = () => {
                if (document.hidden) {
                    this.closeSettings(false);
                    this.abortSpeechInput();
                    this.cancelSpeechStatusRefresh(true);
                    this.clearPetBubbleTimers();
                    return;
                }

                void this.refreshSpeechStatus('visibility');
                if (!this.open && this.autoHelp) this.schedulePetBubble(false);
            };
            this._onlineHandler = () => {
                void this.refreshSpeechStatus('online');
            };

            if (this.dockMediaQuery?.addEventListener) {
                this.dockMediaQuery.addEventListener('change', this._dockChangeHandler);
            } else {
                this._windowResizeHandler = () => this.syncDockLayout();
                window.addEventListener('resize', this._windowResizeHandler);
            }
            document.addEventListener('livewire:navigating', this._navigationHandler);
            document.addEventListener('visibilitychange', this._visibilityHandler);
            window.addEventListener('online', this._onlineHandler);

            this.$watch('open', (value) => {
                const sessionStorage = safeStorage('sessionStorage');
                if (value) {
                    sessionStorage?.setItem('railtime-chatbot-open', '1');
                } else {
                    sessionStorage?.removeItem('railtime-chatbot-open');
                }

                if (!value) {
                    this.closeSettings(false);
                    this.abortSpeechInput();
                    this.stopSpeaking();
                    if (this.autoHelp) this.schedulePetBubble(false);
                    return;
                }

                this.hidePetBubble();
                void this.refreshSpeechStatus('open');
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
                this.observeAttachments();
                this.syncAttachmentCount();
                this.updateComposerState();
            });

            void this.refreshSpeechStatus('init');
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
            if (this._onlineHandler) {
                window.removeEventListener('online', this._onlineHandler);
                this._onlineHandler = null;
            }

            this.messageObserver?.disconnect();
            this.messageObserver = null;
            this.attachmentObserver?.disconnect();
            this.attachmentObserver = null;
            window.cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
            this.abortSpeechInput();
            this.stopSpeaking();
            this.cancelSpeechStatusRefresh(true);
            this.cancelWireAttachmentUpload();
            this.clearAttachmentUploadState();
            this.clearPetBubbleTimers();
            this.settingsOpen = false;
            releaseMicrophoneStream();
        },

        petState() {
            if (!this.assistantAvailable) return 'offline';
            if (this.recording || this.voiceUploading) return 'listening';
            // Mouth/speech motion must reflect audible playback, not the
            // provider request or browser buffering phase.
            if (this.ttsPlaying && this.speaking) return 'speaking';
            if (
                this.isLoading
                || this.ttsWorkerActive
                || this.ttsPreparing
                || this.ttsQueue.length > 0
            ) return 'thinking';

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

        pageHelpWasSeen(pageHelpKey) {
            const key = String(pageHelpKey ?? '').trim();
            if (!key) return false;

            if (safeStorage('sessionStorage')?.getItem(key) === '1') {
                SEEN_PAGE_HELP_KEYS.add(key);

                return true;
            }

            return SEEN_PAGE_HELP_KEYS.has(key);
        },

        rememberPageHelp(pageHelpKey) {
            const key = String(pageHelpKey ?? '').trim();
            if (!key) return;

            SEEN_PAGE_HELP_KEYS.add(key);
            safeStorage('sessionStorage')?.setItem(key, '1');
        },

        nextProactivePetHint() {
            const pageHelpKey = this.pageHelpStorageKey();

            if (
                this.pageHelpHint
                && pageHelpKey
                && !this.pageHelpWasSeen(pageHelpKey)
            ) {
                this.rememberPageHelp(pageHelpKey);

                return this.pageHelpHint;
            }

            return '';
        },

        showPetBubble(text, duration = PET_BUBBLE_VISIBLE_MS, announce = false, origin = null, action = null) {
            const message = String(text ?? '').trim();
            if (!message || this.open) return;

            window.clearTimeout(this.petBubbleTimer);
            this.petBubbleAnnounce = Boolean(announce);
            this.petBubbleOrigin = origin ?? (announce ? 'reply' : 'manual');
            this.petBubbleActionKey = String(action?.key ?? '');
            this.petBubbleActionLabel = String(action?.label ?? '');
            this.petBubbleText = message;
            this.petBubbleVisible = true;
            this.petBubbleTimer = window.setTimeout(() => {
                this.petBubbleVisible = false;
                this.petBubbleAnnounce = false;
                this.petBubbleOrigin = null;
                this.petBubbleActionKey = '';
                this.petBubbleActionLabel = '';
                this.petBubbleTimer = null;
            }, Math.max(1_500, Number(duration) || PET_BUBBLE_VISIBLE_MS));
        },

        hidePetBubble() {
            window.clearTimeout(this.petBubbleTimer);
            this.petBubbleTimer = null;
            this.petBubbleVisible = false;
            this.petBubbleAnnounce = false;
            this.petBubbleOrigin = null;
            this.petBubbleActionKey = '';
            this.petBubbleActionLabel = '';
        },

        runPetBubbleAction() {
            const actionKey = String(this.petBubbleActionKey ?? '');
            if (actionKey !== 'wagon_voice_start') return false;

            this.hidePetBubble();
            this.runWagonHelpAction();

            return true;
        },

        runWagonHelpAction() {
            this.wagonHelpVisible = false;
            this.setOpen(true, true);
            this.$nextTick(() => this.$wire?.quickAction?.('wagon_voice_start'));

            return true;
        },

        schedulePetBubble(initial = false) {
            window.clearTimeout(this.petBubbleCycleTimer);
            this.petBubbleCycleTimer = null;

            if (!this.autoHelp || this.open || document.hidden) return;
            const pageHelpKey = this.pageHelpStorageKey();
            if (
                !this.pageHelpHint
                || !pageHelpKey
                || this.pageHelpWasSeen(pageHelpKey)
            ) return;

            const delay = initial ? PET_BUBBLE_INITIAL_DELAY_MS : PET_BUBBLE_CYCLE_MS;
            this.petBubbleCycleTimer = window.setTimeout(() => {
                this.petBubbleCycleTimer = null;
                if (!this.autoHelp || this.open || document.hidden) return;

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

        manualTtsAvailable() {
            return Boolean(this.speechSupported && this.ttsReady);
        },

        manualVoiceAvailable() {
            return Boolean(this.voiceSupported && this.sttReady);
        },

        speechStatusTone() {
            if (this.speechStatusState === 'checking') return 'checking';
            if (!this.sttConfigured && !this.ttsConfigured) return 'disabled';
            if (this.sttReady && this.ttsReady) return this.speechFallbackActive ? 'fallback' : 'ready';
            if (this.sttReady || this.ttsReady) return 'partial';

            return 'offline';
        },

        speechStatusText() {
            const tone = this.speechStatusTone();
            if (tone === 'checking') return this.strings.speechChecking;
            if (tone === 'disabled') return this.strings.speechDisabled;
            if (tone === 'ready' || tone === 'fallback') return this.strings.speechReady;
            if (tone === 'partial') return this.strings.speechPartiallyReady;

            return this.strings.speechOffline;
        },

        speechProviderName() {
            const providers = [...new Set([
                this.speechSttProvider,
                this.speechTtsProvider,
                this.speechActiveProvider,
            ].map((entry) => String(entry ?? '').trim().toLowerCase()).filter(Boolean))];
            if (providers.length > 1) {
                return providers.map((provider) => (
                    provider === 'openrouter' ? 'OpenRouter' : (this.strings.localSpeechProvider || 'Lokaler Sprachdienst')
                )).join(' / ');
            }

            const provider = providers[0] ?? '';
            if (provider === 'openrouter' || provider === 'external') return 'OpenRouter';
            if (provider === 'local' || provider === 'shared' || provider === 'speech-service') {
                return this.strings.localSpeechProvider || 'Lokaler Sprachdienst';
            }

            return this.speechRoutingLabel;
        },

        cancelSpeechStatusRefresh(invalidate = false) {
            window.clearTimeout(this.speechStatusRetryTimer);
            this.speechStatusRetryTimer = null;
            this.speechStatusAbortController?.abort();
            this.speechStatusAbortController = null;
            if (invalidate) this.speechStatusGeneration += 1;
        },

        scheduleSpeechStatusRetry() {
            window.clearTimeout(this.speechStatusRetryTimer);
            this.speechStatusRetryTimer = null;
            if (!this.speechStatusEndpoint || document.hidden || !this.assistantAvailable) return;

            const attempt = Math.min(
                this.speechStatusRetryAttempt,
                SPEECH_STATUS_RETRY_DELAYS_MS.length - 1,
            );
            const delay = SPEECH_STATUS_RETRY_DELAYS_MS[attempt];
            this.speechStatusRetryAttempt += 1;
            this.speechStatusRetryTimer = window.setTimeout(() => {
                this.speechStatusRetryTimer = null;
                void this.refreshSpeechStatus('retry');
            }, delay);
        },

        applySpeechStatus(payload = {}) {
            const capabilities = payload?.capabilities ?? {};
            const stt = capabilities?.stt ?? {};
            const tts = capabilities?.tts ?? {};
            const sttAvailable = payload?.stt_available ?? stt?.available;
            const ttsAvailable = payload?.tts_available ?? tts?.available;
            const sttConfigured = payload?.stt_configured ?? stt?.configured;
            const ttsConfigured = payload?.tts_configured ?? tts?.configured;

            if (typeof sttConfigured === 'boolean') this.sttConfigured = sttConfigured;
            if (typeof ttsConfigured === 'boolean') this.ttsConfigured = ttsConfigured;
            if (typeof sttAvailable === 'boolean') this.sttReady = this.sttConfigured && sttAvailable;
            if (typeof ttsAvailable === 'boolean') this.ttsReady = this.ttsConfigured && ttsAvailable;

            const activeProviders = payload?.active_provider;
            this.speechSttProvider = String(
                payload?.stt_provider
                ?? (activeProviders && typeof activeProviders === 'object' ? activeProviders.stt : '')
                ?? '',
            ).trim();
            this.speechTtsProvider = String(
                payload?.tts_provider
                ?? (activeProviders && typeof activeProviders === 'object' ? activeProviders.tts : '')
                ?? '',
            ).trim();
            this.speechActiveProvider = this.speechSttProvider === this.speechTtsProvider
                ? this.speechSttProvider
                : '';
            const fallbackActive = payload?.fallback_active ?? payload?.fallback ?? payload?.using_fallback;
            this.speechFallbackActive = fallbackActive && typeof fallbackActive === 'object'
                ? Object.values(fallbackActive).some(Boolean)
                : Boolean(fallbackActive);
            const fallbackAvailable = payload?.fallback_available;
            if (fallbackAvailable !== undefined) {
                this.externalFallback = fallbackAvailable && typeof fallbackAvailable === 'object'
                    ? Object.values(fallbackAvailable).some(Boolean)
                    : Boolean(fallbackAvailable);
            }
            this.speechStatusState = String(payload?.state ?? '').trim()
                || (this.sttReady && this.ttsReady ? 'ready' : (this.sttReady || this.ttsReady ? 'partial' : 'offline'));
            this.speechSupported = Boolean(
                this.ttsConfigured
                && this.ttsEndpoint
                && window.fetch
                && window.Audio
                && window.URL,
            );
            this.voiceSupported = this.recordedVoiceSupported();
        },

        async speechResponseError(response) {
            const error = new Error(await this.responseError(response));
            error.status = Number(response?.status) || 0;

            return error;
        },

        async refreshSpeechStatus(reason = 'manual') {
            if (!this.speechStatusEndpoint || !window.fetch) {
                this.speechStatusState = this.sttReady || this.ttsReady ? 'static' : 'disabled';
                return null;
            }

            this.cancelSpeechStatusRefresh(false);
            const generation = ++this.speechStatusGeneration;
            const abortController = new AbortController();
            this.speechStatusAbortController = abortController;
            if (!this.sttReady && !this.ttsReady) this.speechStatusState = 'checking';

            try {
                const response = await fetch(this.speechStatusEndpoint, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    signal: abortController.signal,
                });
                if (!response.ok) throw await this.speechResponseError(response);

                const payload = await response.json();
                if (generation !== this.speechStatusGeneration) return null;

                this.applySpeechStatus(payload);
                this.speechStatusRetryAttempt = 0;

                return payload;
            } catch (error) {
                if (generation !== this.speechStatusGeneration || error?.name === 'AbortError') return null;

                this.sttReady = false;
                this.ttsReady = false;
                this.speechStatusState = error?.status === 401 || error?.status === 403
                    ? 'disabled'
                    : 'offline';
                if (error?.status !== 401 && error?.status !== 403) this.scheduleSpeechStatusRetry();

                return null;
            } finally {
                if (this.speechStatusAbortController === abortController) {
                    this.speechStatusAbortController = null;
                }
            }
        },

        applySpeechResponseHeaders(response, kind) {
            const provider = response?.headers?.get?.('X-Speech-Provider');
            const fallback = response?.headers?.get?.('X-Speech-Fallback');
            if (provider) {
                this.speechActiveProvider = String(provider);
                if (kind === 'stt') this.speechSttProvider = String(provider);
                if (kind === 'tts') this.speechTtsProvider = String(provider);
            }
            if (fallback !== null && fallback !== undefined) {
                this.speechFallbackActive = ['1', 'true', 'yes'].includes(String(fallback).toLowerCase());
            }
            if (kind === 'stt') this.sttReady = true;
            if (kind === 'tts') this.ttsReady = true;
            this.speechStatusState = this.sttReady && this.ttsReady ? 'ready' : 'partial';
        },

        handleSpeechRequestFailure(kind, error) {
            this.speechFailureGeneration += 1;
            this.cancelPendingAutoListen();

            const status = Number(error?.status) || 0;
            const transient = status === 0 || status >= 500;
            if (transient && kind === 'stt') this.sttReady = false;
            if (transient && kind === 'tts') this.ttsReady = false;
            if (transient) {
                this.speechStatusState = this.sttReady || this.ttsReady ? 'partial' : 'offline';
                this.scheduleSpeechStatusRetry();
            }
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
                this.cancelSpeechStatusRefresh(true);
                if (this.autoHelp) this.schedulePetBubble(false);
                if (wasOpen) this.$nextTick(() => this.$refs.launcher?.focus({ preventScroll: true }));
                return;
            }

            this.hidePetBubble();
            if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('railtime-wagon-context-request'));
            }
            void this.refreshSpeechStatus('open');
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

            this.resetAttachmentUi();
            if (!this.open) this.showPetBubble(this.strings.petReplyReady, PET_BUBBLE_VISIBLE_MS, true);
            if (this.autoRead && this.manualTtsAvailable()) {
                this.queueTtsSentence(text, key);
            }
            this.$nextTick(() => {
                this.updateComposerState();
                this.scrollMessages(false);
                if (detail.can_auto_listen !== false && detail.canAutoListen !== false) {
                    this.scheduleAutoListenAfterReply();
                } else {
                    this.cancelPendingAutoListen();
                }
            });
        },

        handleClientAction(rawDetail) {
            const detail = normalizedEventDetail(rawDetail);
            const action = normalizedEventDetail(detail.action ?? detail);
            const type = String(action.type ?? '');
            const actionToken = String(action.action_token ?? '');

            if (!/^[a-zA-Z0-9]{48}$/.test(actionToken)) return false;

            if (type === 'navigate') {
                const rawUrl = String(action.url ?? '').trim();
                if (!rawUrl.startsWith('/') || rawUrl.startsWith('//')) return false;

                let target;
                try {
                    target = new URL(rawUrl, window.location.href);
                } catch (_) {
                    return false;
                }

                if (target.origin !== window.location.origin) return false;

                this.closeSettings(false);
                this.abortSpeechInput();
                this.stopSpeaking();
                this.discardPendingAttachments();
                this.setOpen(false);

                const relativeTarget = `${target.pathname}${target.search}${target.hash}`;
                if (typeof window.Livewire?.navigate === 'function') {
                    window.Livewire.navigate(relativeTarget);
                } else {
                    window.location.assign(relativeTarget);
                }

                return true;
            }

            const allowedCommands = new Set([
                'start',
                'next',
                'previous',
                'select_wagon',
                'save',
                'set_field',
            ]);
            const command = String(action.command ?? '');
            const contextNonce = String(action.context_nonce ?? '');

            if (
                type !== 'wagon_list'
                || !allowedCommands.has(command)
                || !/^[a-zA-Z0-9_-]{16,96}$/.test(contextNonce)
            ) {
                return false;
            }

            window.dispatchEvent(new CustomEvent('railtime-wagon-assistant-command', {
                detail: {
                    ...action,
                    version: 1,
                    action_token: actionToken,
                    context_nonce: contextNonce,
                    command,
                },
            }));

            return true;
        },

        handleWagonHelp(rawDetail) {
            if (!this.autoHelp || document.hidden) return false;

            const detail = normalizedEventDetail(rawDetail);
            const text = String(
                detail.text
                ?? this.strings.wagonHelp,
            ).trim();

            if (!text) return false;

            if (!this.open) {
                this.showPetBubble(text, 9_000, true, 'wagon-list', {
                    key: 'wagon_voice_start',
                    label: this.strings.wagonVoiceStart,
                });
            } else {
                this.wagonHelpText = text;
                this.wagonHelpVisible = true;
                this.$nextTick(() => this.scrollMessages(true));
            }

            return true;
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
            const failureGeneration = this.speechFailureGeneration;
            const attempt = () => {
                this.autoListenTimer = null;
                if (generation !== this.autoListenGeneration || !this.autoListen) return;
                if (failureGeneration !== this.speechFailureGeneration) return;
                if (!this.open || document.hidden) return;

                this.voiceSupported = this.recordedVoiceSupported();
                if (!this.manualVoiceAvailable() || this.recording || this.voiceUploading) return;

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
            if (!this.manualTtsAvailable()) {
                this.audioError = this.strings.audioEndpointUnavailable;
                void this.refreshSpeechStatus('tts-unavailable');
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
            if (!cleanText || !this.manualTtsAvailable()) return;

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
                    if (error?.speechProviderFailure) this.handleSpeechRequestFailure('tts', error);
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
                    Accept: 'audio/wav, audio/mpeg, application/json',
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
                let response;
                try {
                    response = await fetch(this.ttsEndpoint, this.ttsFetchOptions(text, abortController));
                } catch (error) {
                    if (error?.name !== 'AbortError') error.speechProviderFailure = true;
                    throw error;
                }
                this.assertTtsGeneration(generation);

                if (!response.ok) {
                    const error = await this.speechResponseError(response);
                    error.speechProviderFailure = true;
                    throw error;
                }
                this.applySpeechResponseHeaders(response, 'tts');

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
                this.sttConfigured
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
            if (!this.voiceSupported || !this.sttReady) {
                this.audioError = this.sttEndpoint
                    ? (this.voiceSupported ? this.strings.speechEndpointUnavailable : this.strings.recordingUnsupported)
                    : this.strings.speechEndpointUnavailable;
                if (this.voiceSupported) void this.refreshSpeechStatus('stt-unavailable');
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

                let response;
                try {
                    response = await fetch(this.sttEndpoint, {
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
                } catch (error) {
                    if (error?.name !== 'AbortError') error.speechProviderFailure = true;
                    throw error;
                }

                if (!response.ok) {
                    const error = await this.speechResponseError(response);
                    error.speechProviderFailure = true;
                    throw error;
                }
                this.applySpeechResponseHeaders(response, 'stt');

                const payload = await response.json();
                const transcript = String(payload?.text ?? '').trim();
                if (!transcript) throw new Error(this.strings.speechNoText);

                const currentDraft = String(this.$refs.composer?.value ?? '').trim();
                const combinedDraft = [currentDraft, transcript].filter(Boolean).join(' ');
                await this.$wire.set('message', combinedDraft);
                this.composerHasText = Boolean(combinedDraft.trim());
                this.$nextTick(() => this.resizeComposer());
            } catch (error) {
                if (error?.name !== 'AbortError') {
                    if (error?.speechProviderFailure) this.handleSpeechRequestFailure('stt', error);
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

        updateComposerState() {
            this.composerHasText = Boolean(this.composerDraft());
        },

        canSubmit() {
            return Boolean(
                this.assistantAvailable
                && !this.isLoading
                && !this.attachmentUploadActive
                && (this.composerHasText || this.attachmentCount > 0),
            );
        },

        handleComposerEnter(event) {
            if (event?.isComposing || !this.canSubmit()) return false;

            this.$wire.sendMessage();

            return true;
        },

        handleAttachmentSelection(event) {
            const files = Array.from(event?.target?.files ?? []);
            this.attachmentUploadError = '';
            if (files.length > ATTACHMENT_UPLOAD_LIMIT) {
                this.attachmentUploadError = this.strings.attachmentTooMany
                    || `Es können maximal ${ATTACHMENT_UPLOAD_LIMIT} Dateien angehängt werden.`;
            }
        },

        beginAttachmentUpload() {
            window.clearTimeout(this.attachmentSyncTimer);
            this.attachmentSyncTimer = null;
            this.attachmentUploadActive = true;
            this.attachmentUploadProgress = 0;
            this.attachmentUploadError = '';
        },

        updateAttachmentUpload(rawDetail) {
            const detail = normalizedEventDetail(rawDetail);
            this.attachmentUploadProgress = clamp(detail?.progress, 0, 100, 0);
        },

        completeAttachmentUpload() {
            this.attachmentUploadActive = false;
            this.attachmentUploadProgress = 100;
            window.clearTimeout(this.attachmentSyncTimer);
            this.attachmentSyncTimer = window.setTimeout(() => {
                this.attachmentSyncTimer = null;
                this.syncAttachmentCount();
                this.attachmentUploadProgress = 0;
                if (this.$refs?.attachmentInput) this.$refs.attachmentInput.value = '';
            }, 0);
        },

        failAttachmentUpload(message = '') {
            this.attachmentUploadActive = false;
            this.attachmentUploadProgress = 0;
            this.attachmentUploadError = String(message || this.strings.attachmentUploadFailed);
            if (this.$refs?.attachmentInput) this.$refs.attachmentInput.value = '';
        },

        cancelAttachmentUpload() {
            this.failAttachmentUpload(this.strings.attachmentUploadCancelled);
        },

        syncAttachmentCount() {
            const count = this.$refs?.attachmentList
                ?.querySelectorAll?.('[data-chatbot-attachment-chip]')
                ?.length;
            if (Number.isFinite(count)) this.attachmentCount = Number(count);

            return this.attachmentCount;
        },

        observeAttachments() {
            this.attachmentObserver?.disconnect();
            this.attachmentObserver = null;

            if (!this.$refs?.attachmentList || typeof MutationObserver === 'undefined') return;

            this.attachmentObserver = new MutationObserver(() => this.syncAttachmentCount());
            this.attachmentObserver.observe(this.$refs.attachmentList, {
                childList: true,
                subtree: true,
            });
        },

        markAttachmentRemoval() {
            this.attachmentUploadError = '';
            this.attachmentCount = Math.max(0, this.attachmentCount - 1);
        },

        resetAttachmentUi() {
            this.attachmentCount = 0;
            this.clearAttachmentUploadState();
        },

        cancelWireAttachmentUpload() {
            if (!this.attachmentUploadActive) return;
            this.$wire?.cancelUpload?.('attachments');
        },

        discardPendingAttachments() {
            this.cancelWireAttachmentUpload();
            if (this.attachmentCount > 0) this.$wire?.discardAttachments?.();
            this.attachmentCount = 0;
            this.clearAttachmentUploadState();
        },

        clearAttachmentUploadState() {
            window.clearTimeout(this.attachmentSyncTimer);
            this.attachmentSyncTimer = null;
            this.attachmentUploadActive = false;
            this.attachmentUploadProgress = 0;
            this.attachmentUploadError = '';
            if (this.$refs?.attachmentInput) this.$refs.attachmentInput.value = '';
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
