import {
    acquireMicrophoneStream,
    holdMicrophoneStream,
    releaseMicrophoneStream,
} from './microphone-stream.js';
import { ensureRailTimeNavigationCoordinator } from './navigation-coordinator.js';
import {
    acquirePageBuilderAssistantActionDispatcher,
    ensurePageBuilderAssistantBridge,
} from './lmz-editor-assistant.js';

ensurePageBuilderAssistantBridge();
const dispatchPageBuilderAssistantAction = acquirePageBuilderAssistantActionDispatcher();

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
const PET_PRIMER_VISIBLE_MS = 14_000;
const PET_REACTION_MIN_DELAY_MS = 7_000;
const PET_REACTION_DELAY_RANGE_MS = 9_000;
const SPEECH_STATUS_RETRY_DELAYS_MS = [2_000, 5_000, 15_000, 30_000];
const ATTACHMENT_UPLOAD_LIMIT = 3;
const ATTACHMENT_CLEANUP_TIMEOUT_MS = 12_000;
const PHRASE_AUDIO_CACHE_TTL_MS = 10 * 60 * 1_000;
const PHRASE_AUDIO_CACHE_MAX_ITEMS = 8;
const PHRASE_AUDIO_CACHE_MAX_BYTES = 16 * 1024 * 1024;
const SEEN_PAGE_HELP_KEYS = new Set();
const PET_REACTIONS = Object.freeze(['curious', 'happy', 'wave']);
const PHRASE_AUDIO_CACHE = new Map();
let phraseAudioCacheBytes = 0;

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
    attachmentCleanupFailed: 'Die Anhänge konnten vor dem Seitenwechsel nicht sicher entfernt werden.',
    petGreeting: 'Hi, ich bin dein RailTime-Begleiter.',
    petHint: 'Was möchtest du heute in RailTime erledigen?',
    petVoiceHint: 'Du kannst deine Frage auch einfach einsprechen.',
    petReplyReady: 'Ich habe eine Antwort für dich.',
    petUnavailable: 'Ich mache gerade eine kurze Pause.',
    petStatusChecking: 'Ich prüfe kurz, was hier bereit ist …',
    petReadyQuestion: 'Ich bin bereit. Wobei soll ich dir helfen?',
    petTextOnlyQuestion: 'Schreiben ist bereit. Möchtest du mir deine Frage tippen?',
    petPageQuestion: 'Brauchst du Hilfe bei „:page“?',
    petNextStepQuestion: 'Soll ich dir auf „:page“ den nächsten Schritt zeigen?',
    petHelpQuestion: 'Soll ich dich dabei unterstützen?',
    petOpenChat: 'Chat öffnen',
    petAskByVoice: 'Frage sprechen',
    petReadAloud: 'Vorlesen',
    petCheckAgain: 'Erneut prüfen',
    wagonHelp: 'Soll ich dich per Sprache Schritt für Schritt durch diese Wagenliste führen?',
    wagonVoiceStart: 'Per Sprache starten',
};

function deletePhraseAudioCacheEntry(key) {
    const existing = PHRASE_AUDIO_CACHE.get(key);
    if (!existing) return;

    phraseAudioCacheBytes = Math.max(0, phraseAudioCacheBytes - existing.size);
    PHRASE_AUDIO_CACHE.delete(key);
}

function prunePhraseAudioCache(now = Date.now()) {
    PHRASE_AUDIO_CACHE.forEach((entry, key) => {
        if (entry.expiresAt <= now) deletePhraseAudioCacheEntry(key);
    });

    while (
        PHRASE_AUDIO_CACHE.size > PHRASE_AUDIO_CACHE_MAX_ITEMS
        || phraseAudioCacheBytes > PHRASE_AUDIO_CACHE_MAX_BYTES
    ) {
        const oldestKey = PHRASE_AUDIO_CACHE.keys().next().value;
        if (oldestKey === undefined) break;
        deletePhraseAudioCacheEntry(oldestKey);
    }
}

export function clearAssistantPhraseAudioCache() {
    PHRASE_AUDIO_CACHE.clear();
    phraseAudioCacheBytes = 0;
}

export function cachedAssistantPhraseAudio(key, now = Date.now()) {
    const normalized = String(key ?? '').trim();
    if (!normalized) return null;

    prunePhraseAudioCache(now);
    const entry = PHRASE_AUDIO_CACHE.get(normalized);
    if (!entry) return null;

    PHRASE_AUDIO_CACHE.delete(normalized);
    PHRASE_AUDIO_CACHE.set(normalized, entry);

    return entry.blob;
}

export function rememberAssistantPhraseAudio(key, blob, now = Date.now()) {
    const normalized = String(key ?? '').trim();
    const size = Number(blob?.size) || 0;
    if (!normalized || !blob || size <= 0 || size > PHRASE_AUDIO_CACHE_MAX_BYTES) return false;

    deletePhraseAudioCacheEntry(normalized);
    PHRASE_AUDIO_CACHE.set(normalized, {
        blob,
        size,
        expiresAt: now + PHRASE_AUDIO_CACHE_TTL_MS,
    });
    phraseAudioCacheBytes += size;
    prunePhraseAudioCache(now);

    return PHRASE_AUDIO_CACHE.has(normalized);
}

export function chooseAssistantPetReaction(randomValue = Math.random()) {
    const normalized = Math.min(0.999999, Math.max(0, Number(randomValue) || 0));

    return PET_REACTIONS[Math.floor(normalized * PET_REACTIONS.length)];
}

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
        pageBuilderActionClaimEndpoint: String(config.pageBuilderActionClaimEndpoint ?? ''),
        pageBuilderActionClaimTokens: [],
        csrfToken: String(config.csrfToken ?? ''),
        locale: String(
            config.locale
            ?? (typeof document !== 'undefined' ? document.documentElement?.lang : '')
            ?? 'de',
        ).trim().toLowerCase() || 'de',
        pageRouteName: String(config.pageRouteName ?? ''),
        pageHelpHint: String(config.pageHelpHint ?? '').trim(),
        pageHelpHints: Array.isArray(config.pageHelpHints)
            ? config.pageHelpHints.map((entry) => String(entry ?? '').trim()).filter(Boolean).slice(0, 5)
            : [],
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
        ttsProgress: 0,
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
        attachmentsMayExistOnServer: Math.max(0, Number(config.attachmentCount) || 0) > 0,
        attachmentMutationVersion: 0,
        attachmentCleanupPromise: null,
        attachmentCleanupResolve: null,
        attachmentCleanupReject: null,
        attachmentCleanupToken: '',
        attachmentCleanupVersion: 0,
        attachmentCleanupTimer: null,
        attachmentCleanupTimedOutToken: '',
        attachmentCleanupTimedOutVersion: 0,
        attachmentFlushPromise: null,
        attachmentFinishCommitsInFlight: 0,
        attachmentCommitBarrierTimeoutMs: Math.max(
            500,
            Number(config.attachmentCommitBarrierTimeoutMs) || ATTACHMENT_CLEANUP_TIMEOUT_MS,
        ),
        attachmentCleanupTimeoutMs: Math.max(
            50,
            Number(config.attachmentCleanupTimeoutMs) || ATTACHMENT_CLEANUP_TIMEOUT_MS,
        ),
        navigationCleanupInFlight: false,
        navigationCoordinator: null,
        navigationController: null,
        navigationUnregister: null,
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
        petBubbleActions: [],
        petBubblePhraseKey: '',
        petPrimed: false,
        petStatusChecking: false,
        petReaction: '',
        wagonHelpVisible: false,
        wagonHelpText: '',
        petHintIndex: -1,
        petBubbleTimer: null,
        petBubbleCycleTimer: null,
        petReactionTimer: null,
        petReactionClearTimer: null,
        autoListenTimer: null,
        autoListenGeneration: 0,
        autoListenChecking: false,
        _dockChangeHandler: null,
        _windowResizeHandler: null,
        _navigationHandler: null,
        _visibilityHandler: null,
        _onlineHandler: null,
        _attachmentCleanupAckHandler: null,
        _attachmentCleanupEventUnsubscribe: null,
        _attachmentCommitUnhook: null,

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
                this.resetAttachmentUi();
                this.clearPetReactionTimers();
                clearAssistantPhraseAudioCache();
            };
            this._visibilityHandler = () => {
                if (document.hidden) {
                    this.closeSettings(false);
                    this.abortSpeechInput();
                    this.cancelSpeechStatusRefresh(true);
                    this.clearPetBubbleTimers();
                    this.clearPetReactionTimers();
                    return;
                }

                void this.refreshSpeechStatus('visibility');
                if (!this.open && this.autoHelp) this.schedulePetBubble(false);
                this.scheduleRandomPetReaction();
            };
            this._onlineHandler = () => {
                void this.refreshSpeechStatus('online');
            };

            this.navigationCoordinator = ensureRailTimeNavigationCoordinator(window, document);
            this.navigationController = {
                hasPendingWork: () => this.hasPendingAttachmentWork(),
                flush: () => this.flushPendingAttachments(),
                onFlushError: () => this.handleAttachmentFlushError(),
            };
            this.navigationUnregister = this.navigationCoordinator?.register(this.navigationController) ?? null;
            this._attachmentCleanupAckHandler = (event) => {
                this.handleAttachmentCleanupAck(event);
            };
            this._attachmentCleanupEventUnsubscribe = this.$wire?.$on?.(
                'railtime-assistant-attachments-discarded',
                this._attachmentCleanupAckHandler,
            ) ?? null;
            this._attachmentCommitUnhook = this.$wire?.$hook?.(
                'commit',
                (payload) => this.handleAttachmentCommit(payload),
            ) ?? null;
            window.addEventListener(
                'railtime-assistant-attachments-discarded',
                this._attachmentCleanupAckHandler,
            );

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
                    this.petPrimed = false;
                    if (this.autoHelp) this.schedulePetBubble(false);
                    this.scheduleRandomPetReaction();
                    return;
                }

                this.petPrimed = false;
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
                clearAssistantPhraseAudioCache();
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
            this.scheduleRandomPetReaction();
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
            if (this._attachmentCleanupAckHandler) {
                window.removeEventListener(
                    'railtime-assistant-attachments-discarded',
                    this._attachmentCleanupAckHandler,
                );
                this._attachmentCleanupAckHandler = null;
            }
            if (typeof this._attachmentCleanupEventUnsubscribe === 'function') {
                this._attachmentCleanupEventUnsubscribe();
            }
            this._attachmentCleanupEventUnsubscribe = null;
            if (typeof this._attachmentCommitUnhook === 'function') this._attachmentCommitUnhook();
            this._attachmentCommitUnhook = null;
            if (typeof this.navigationUnregister === 'function') this.navigationUnregister();
            this.navigationUnregister = null;
            this.navigationController = null;
            this.navigationCoordinator = null;

            this.messageObserver?.disconnect();
            this.messageObserver = null;
            this.attachmentObserver?.disconnect();
            this.attachmentObserver = null;
            window.cancelAnimationFrame(this.scrollFrame);
            this.scrollFrame = null;
            this.abortSpeechInput();
            this.stopSpeaking();
            this.cancelSpeechStatusRefresh(true);
            this.failPendingAttachmentCleanup(
                new Error('Assistant attachment cleanup was interrupted.'),
                '',
                false,
            );
            this.cancelWireAttachmentUpload();
            this.clearAttachmentUploadState();
            this.clearPetBubbleTimers();
            this.clearPetReactionTimers();
            clearAssistantPhraseAudioCache();
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
                || this.petStatusChecking
                || this.ttsWorkerActive
                || this.ttsPreparing
                || this.ttsQueue.length > 0
            ) return 'thinking';
            if (PET_REACTIONS.includes(this.petReaction)) return this.petReaction;

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

        hasPageHelp() {
            return Boolean(this.pageHelpHint || this.pageHelpHints.length);
        },

        formatPetPageString(template, page) {
            return String(template ?? '').replace(':page', String(page ?? '').trim());
        },

        pagePetQuestionCandidates() {
            const supplied = this.pageHelpHints
                .map((entry) => String(entry ?? '').trim())
                .filter(Boolean);
            const pageTitle = supplied[0] ?? '';
            const details = supplied.length > 1
                ? supplied.slice(1)
                : [this.pageHelpHint].filter(Boolean);
            const candidates = [];

            if (pageTitle) {
                candidates.push(this.formatPetPageString(this.strings.petPageQuestion, pageTitle));
                candidates.push(this.formatPetPageString(this.strings.petNextStepQuestion, pageTitle));
            }

            details.slice(0, 3).forEach((detail) => {
                const concise = String(detail).trim().slice(0, 220);
                if (concise) candidates.push(`${concise} ${this.strings.petHelpQuestion}`.trim());
            });

            if (!candidates.length && this.pageHelpHint) candidates.push(this.pageHelpHint);

            return [...new Set(candidates.map((entry) => entry.trim()).filter(Boolean))];
        },

        nextPagePetQuestion() {
            const candidates = this.pagePetQuestionCandidates();
            if (!candidates.length) return '';

            let index = Math.floor(Math.random() * candidates.length);
            if (candidates.length > 1 && index === this.petHintIndex) {
                index = (index + 1) % candidates.length;
            }
            this.petHintIndex = index;

            return candidates[index];
        },

        petStatusQuestion() {
            if (!this.assistantAvailable) return this.strings.petUnavailable;

            const contextual = this.nextPagePetQuestion();
            const tone = this.speechStatusTone();
            if (['offline', 'disabled'].includes(tone)) {
                return contextual || this.strings.petTextOnlyQuestion;
            }

            return contextual || this.strings.petReadyQuestion;
        },

        nextProactivePetHint() {
            const pageHelpKey = this.pageHelpStorageKey();

            if (
                this.hasPageHelp()
                && pageHelpKey
                && !this.pageHelpWasSeen(pageHelpKey)
            ) {
                this.rememberPageHelp(pageHelpKey);

                return this.pageHelpHints.length
                    ? (this.nextPagePetQuestion() || this.pageHelpHint)
                    : this.pageHelpHint;
            }

            return '';
        },

        normalizePetBubbleActions(actions = null) {
            const source = Array.isArray(actions) ? actions : (actions ? [actions] : []);

            return source
                .map((action) => ({
                    key: String(action?.key ?? '').trim(),
                    label: String(action?.label ?? '').trim(),
                    primary: Boolean(action?.primary),
                }))
                .filter((action) => action.key && action.label)
                .slice(0, 3);
        },

        showPetBubble(
            text,
            duration = PET_BUBBLE_VISIBLE_MS,
            announce = false,
            origin = null,
            actions = null,
            phraseKey = '',
        ) {
            const message = String(text ?? '').trim();
            if (!message || this.open) return;

            window.clearTimeout(this.petBubbleTimer);
            const normalizedActions = this.normalizePetBubbleActions(actions);
            this.petBubbleAnnounce = Boolean(announce);
            this.petBubbleOrigin = origin ?? (announce ? 'reply' : 'manual');
            this.petBubbleActions = normalizedActions;
            this.petBubbleActionKey = normalizedActions[0]?.key ?? '';
            this.petBubbleActionLabel = normalizedActions[0]?.label ?? '';
            this.petBubblePhraseKey = String(phraseKey ?? '').trim();
            this.petBubbleText = message;
            this.petBubbleVisible = true;
            this.petBubbleTimer = window.setTimeout(() => {
                const resetPrimer = this.petBubbleOrigin === 'pet-status';
                this.petBubbleVisible = false;
                this.petBubbleAnnounce = false;
                this.petBubbleOrigin = null;
                this.petBubbleActionKey = '';
                this.petBubbleActionLabel = '';
                this.petBubbleActions = [];
                this.petBubblePhraseKey = '';
                if (resetPrimer) this.petPrimed = false;
                this.petBubbleTimer = null;
            }, Math.max(1_500, Number(duration) || PET_BUBBLE_VISIBLE_MS));
        },

        hidePetBubble() {
            const resetPrimer = this.petBubbleOrigin === 'pet-status';
            window.clearTimeout(this.petBubbleTimer);
            this.petBubbleTimer = null;
            this.petBubbleVisible = false;
            this.petBubbleAnnounce = false;
            this.petBubbleOrigin = null;
            this.petBubbleActionKey = '';
            this.petBubbleActionLabel = '';
            this.petBubbleActions = [];
            this.petBubblePhraseKey = '';
            if (resetPrimer) this.petPrimed = false;
        },

        petStatusActions() {
            const actions = [];
            const wagonRoute = ['operations.wagon-list', 'admin.operations.wagon-list']
                .includes(this.pageRouteName);

            if (wagonRoute && this.manualVoiceAvailable()) {
                actions.push({
                    key: 'wagon_voice_start',
                    label: this.strings.wagonVoiceStart,
                    primary: true,
                });
            }
            actions.push({
                key: 'open_chat',
                label: this.strings.petOpenChat,
                primary: !wagonRoute,
            });
            if (!wagonRoute && this.manualVoiceAvailable()) {
                actions.push({
                    key: 'start_voice',
                    label: this.strings.petAskByVoice,
                });
            }
            if (this.manualTtsAvailable()) {
                actions.push({
                    key: 'read_pet_phrase',
                    label: this.strings.petReadAloud,
                });
            } else if (['offline', 'disabled'].includes(this.speechStatusTone())) {
                actions.push({
                    key: 'check_status',
                    label: this.strings.petCheckAgain,
                });
            }

            return actions.slice(0, 3);
        },

        async handlePetClick() {
            if (this.petPrimed) {
                this.triggerPetReaction('happy', 900);
                this.setOpen(true, true);

                return true;
            }

            this.petPrimed = true;
            this.petStatusChecking = true;
            this.triggerPetReaction('curious', 1_050);
            this.showPetBubble(
                this.strings.petStatusChecking,
                PET_PRIMER_VISIBLE_MS,
                true,
                'pet-status',
            );

            await this.refreshSpeechStatus('pet-click');
            this.petStatusChecking = false;
            if (!this.petPrimed || this.open || document.hidden) return false;

            const question = this.petStatusQuestion();
            const phraseKey = `${this.pageRouteName || 'page'}:${this.hashMessage(question)}`;
            this.showPetBubble(
                question,
                PET_PRIMER_VISIBLE_MS,
                true,
                'pet-status',
                this.petStatusActions(),
                phraseKey,
            );
            if (this.autoRead && this.manualTtsAvailable()) this.speakPetBubble();

            return true;
        },

        runPetBubbleAction(action = null) {
            const actionKey = String(action?.key ?? action ?? this.petBubbleActionKey ?? '');
            if (
                this.navigationCleanupInFlight
                && ['start_voice', 'wagon_voice_start'].includes(actionKey)
            ) return false;

            if (actionKey === 'read_pet_phrase') {
                this.triggerPetReaction('wave', 900);
                this.speakPetBubble();

                return true;
            }
            if (actionKey === 'check_status') {
                this.petPrimed = false;
                void this.handlePetClick();

                return true;
            }
            if (actionKey === 'open_chat') {
                this.triggerPetReaction('happy', 900);
                this.setOpen(true, true);

                return true;
            }
            if (actionKey === 'start_voice') {
                this.triggerPetReaction('happy', 900);
                this.setOpen(true, true);
                this.$nextTick(() => void this.toggleVoice());

                return true;
            }
            if (actionKey !== 'wagon_voice_start') return false;

            this.hidePetBubble();
            this.runWagonHelpAction();

            return true;
        },

        runWagonHelpAction() {
            if (this.navigationCleanupInFlight || this.isLoading || !this.assistantAvailable) {
                return false;
            }

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
                !this.hasPageHelp()
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
            this.petBubbleActionKey = '';
            this.petBubbleActionLabel = '';
            this.petBubbleActions = [];
            this.petBubblePhraseKey = '';
            this.petPrimed = false;
            this.petStatusChecking = false;
        },

        triggerPetReaction(reaction, duration = 900) {
            if (
                !PET_REACTIONS.includes(reaction)
                || !this.assistantAvailable
                || document.hidden
                || this.prefersReducedMotion()
            ) return false;

            window.clearTimeout(this.petReactionClearTimer);
            this.petReaction = reaction;
            this.petReactionClearTimer = window.setTimeout(() => {
                this.petReaction = '';
                this.petReactionClearTimer = null;
            }, Math.max(500, Number(duration) || 900));

            return true;
        },

        scheduleRandomPetReaction() {
            window.clearTimeout(this.petReactionTimer);
            this.petReactionTimer = null;
            if (
                !this.assistantAvailable
                || document.hidden
                || this.prefersReducedMotion()
            ) return;

            const delay = PET_REACTION_MIN_DELAY_MS
                + Math.floor(Math.random() * PET_REACTION_DELAY_RANGE_MS);
            this.petReactionTimer = window.setTimeout(() => {
                this.petReactionTimer = null;
                const busy = this.recording
                    || this.voiceUploading
                    || this.ttsActive()
                    || this.isLoading
                    || this.petStatusChecking;
                if (!busy) this.triggerPetReaction(chooseAssistantPetReaction(), 1_050);
                this.scheduleRandomPetReaction();
            }, delay);
        },

        clearPetReactionTimers() {
            window.clearTimeout(this.petReactionTimer);
            window.clearTimeout(this.petReactionClearTimer);
            this.petReactionTimer = null;
            this.petReactionClearTimer = null;
            this.petReaction = '';
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
            const previousTtsProvider = this.ttsProviderSignature();
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
            if (
                previousTtsProvider
                && previousTtsProvider !== this.ttsProviderSignature()
            ) clearAssistantPhraseAudioCache();
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
            const previousTtsProvider = this.ttsProviderSignature();
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
            if (
                kind === 'tts'
                && previousTtsProvider
                && previousTtsProvider !== this.ttsProviderSignature()
            ) clearAssistantPhraseAudioCache();
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
                this.petPrimed = false;
                this.closeSettings(false);
                this.abortSpeechInput();
                this.cancelSpeechStatusRefresh(true);
                if (this.autoHelp) this.schedulePetBubble(false);
                if (wasOpen) this.$nextTick(() => this.$refs.launcher?.focus({ preventScroll: true }));
                return;
            }

            this.petPrimed = false;
            this.hidePetBubble();
            if (typeof window.dispatchEvent === 'function' && typeof CustomEvent === 'function') {
                window.dispatchEvent(new CustomEvent('railtime-wagon-context-request'));
                window.dispatchEvent(new CustomEvent('railtime-pagebuilder-context-request'));
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
            this.triggerPetReaction('happy', 1_050);
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

        async claimPageBuilderAction(actionToken) {
            const endpoint = this.pageBuilderActionClaimEndpoint.trim();
            if (
                !endpoint.startsWith('/')
                || endpoint.startsWith('//')
                || this.pageBuilderActionClaimTokens.includes(actionToken)
            ) return false;

            let target;
            try {
                target = new URL(endpoint, window.location.href);
            } catch (_) {
                return false;
            }
            if (target.origin !== window.location.origin) return false;

            this.pageBuilderActionClaimTokens = [
                ...this.pageBuilderActionClaimTokens,
                actionToken,
            ].slice(-50);

            try {
                const response = await fetch(`${target.pathname}${target.search}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action_token: actionToken }),
                });
                if (!response?.ok) return false;

                const payload = normalizedEventDetail(await response.json());
                const action = normalizedEventDetail(payload.action);
                if (
                    action.type !== 'pagebuilder'
                    || String(action.action_token ?? '') !== actionToken
                ) return false;

                return dispatchPageBuilderAssistantAction?.(action) === true;
            } catch (_) {
                return false;
            }
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
                this.setOpen(false);

                const relativeTarget = `${target.pathname}${target.search}${target.hash}`;
                if (typeof window.Livewire?.navigate === 'function') {
                    window.Livewire.navigate(relativeTarget);
                } else if (this.navigationCoordinator && this.hasPendingAttachmentWork()) {
                    void this.navigationCoordinator.navigate(relativeTarget, false).catch(() => {});
                } else {
                    window.location.assign(relativeTarget);
                }

                return true;
            }

            if (type === 'pagebuilder_grant') {
                if (
                    !this.pageBuilderActionClaimEndpoint
                    || this.pageBuilderActionClaimTokens.includes(actionToken)
                ) return false;
                void this.claimPageBuilderAction(actionToken);

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

        ttsProviderSignature() {
            return [
                this.speechTtsProvider,
                this.speechActiveProvider,
                this.speechRoutingLabel,
            ].map((entry) => String(entry ?? '').trim().toLowerCase()).filter(Boolean).join('|');
        },

        clearPhraseAudioCache() {
            clearAssistantPhraseAudioCache();
        },

        phraseAudioCacheKey(text, phraseKey = '') {
            const source = [
                'v1',
                this.locale,
                this.clampSpeechRate(this.speechRate),
                this.ttsProviderSignature() || 'configured',
                String(phraseKey ?? '').trim(),
                String(text ?? '').trim(),
            ].join('|');

            return `pet-phrase:${this.hashMessage(source)}`;
        },

        speakPetBubble() {
            const text = String(this.petBubbleText ?? '').trim();
            if (!text) return false;

            const phraseKey = this.petBubblePhraseKey || `manual:${this.hashMessage(text)}`;
            this.speak(text, `pet:${phraseKey}`, {
                cacheKey: this.phraseAudioCacheKey(text, phraseKey),
            });

            return true;
        },

        ttsTokenState(key, start, end, total) {
            if (String(this.ttsActiveKey ?? '') !== String(key ?? '') || !this.ttsActive()) {
                return 'idle';
            }

            const length = Math.max(1, Number(total) || 1);
            const progress = Math.min(1, Math.max(0, Number(this.ttsProgress) || 0));
            const startRatio = Math.max(0, Number(start) || 0) / length;
            const endRatio = Math.max(startRatio, Number(end) || 0) / length;
            if (progress >= endRatio - 0.0001) return 'read';
            if (progress > startRatio) return 'current';

            return 'unread';
        },

        speak(text, key = null, options = {}) {
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
            this.queueTtsSentence(cleanText, key, options);
        },

        queueTtsSentence(text, key = null, options = {}) {
            const cleanText = String(text ?? '').trim().slice(0, MAX_TTS_TEXT_LENGTH);
            if (!cleanText || !this.manualTtsAvailable()) return;
            const cacheKey = String(options?.cacheKey ?? '').trim();

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
                cacheKey,
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
            this.ttsProgress = 0;

            try {
                await this.playTtsViaBlob(item.text, item.key, item.generation, item.cacheKey);
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
                this.ttsProgress = 0;

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

        async playTtsViaBlob(text, key, generation, cacheKey = '') {
            if (!this.ttsEndpoint) throw new Error(this.strings.audioEndpointUnavailable);

            const abortController = new AbortController();
            this.ttsAbortController = abortController;
            let objectUrl = null;

            try {
                let blob = cachedAssistantPhraseAudio(cacheKey);
                if (!blob) {
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
                        if ([401, 403].includes(error.status)) clearAssistantPhraseAudioCache();
                        throw error;
                    }
                    this.applySpeechResponseHeaders(response, 'tts');

                    blob = await response.blob();
                    this.assertTtsGeneration(generation);
                    if (cacheKey) rememberAssistantPhraseAudio(cacheKey, blob);
                }

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
                    this.ttsProgress = 0;
                };
                const cleanup = () => {
                    audio.onplaying = null;
                    audio.onwaiting = null;
                    audio.onpause = null;
                    audio.onended = null;
                    audio.onerror = null;
                    audio.ontimeupdate = null;
                    audio.ondurationchange = null;
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
                const updateProgress = () => {
                    if (generation !== this.ttsCurrentGeneration) return;

                    const duration = Number(audio.duration);
                    const currentTime = Number(audio.currentTime);
                    if (!Number.isFinite(duration) || duration <= 0 || !Number.isFinite(currentTime)) return;

                    this.ttsProgress = Math.min(1, Math.max(0, currentTime / duration));
                };
                audio.ontimeupdate = updateProgress;
                audio.ondurationchange = updateProgress;
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
                    if (generation === this.ttsCurrentGeneration) this.ttsProgress = 1;
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
            this.ttsProgress = 0;
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
                && !this.navigationCleanupInFlight
                && (this.composerHasText || this.attachmentCount > 0),
            );
        },

        handleComposerEnter(event) {
            if (event?.isComposing || !this.canSubmit()) return false;

            this.$wire.sendMessage();

            return true;
        },

        handleAttachmentSelection(event) {
            if (this.navigationCleanupInFlight) {
                if (event?.target) event.target.value = '';

                return false;
            }

            const files = Array.from(event?.target?.files ?? []);
            this.attachmentUploadError = '';
            if (files.length > 0) this.markAttachmentMutation();
            if (files.length > ATTACHMENT_UPLOAD_LIMIT) {
                this.attachmentUploadError = this.strings.attachmentTooMany
                    || `Es können maximal ${ATTACHMENT_UPLOAD_LIMIT} Dateien angehängt werden.`;
            }

            return true;
        },

        beginAttachmentUpload() {
            if (this.navigationCleanupInFlight) {
                this.$wire?.cancelUpload?.('attachments');

                return false;
            }

            window.clearTimeout(this.attachmentSyncTimer);
            this.attachmentSyncTimer = null;
            this.markAttachmentMutation();
            this.attachmentUploadActive = true;
            this.attachmentUploadProgress = 0;
            this.attachmentUploadError = '';

            return true;
        },

        updateAttachmentUpload(rawDetail) {
            const detail = normalizedEventDetail(rawDetail);
            this.attachmentUploadProgress = clamp(detail?.progress, 0, 100, 0);
        },

        completeAttachmentUpload() {
            this.markAttachmentMutation();
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
            if (this.attachmentCount > 0) this.attachmentsMayExistOnServer = true;

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
            if (this.navigationCleanupInFlight) return false;

            this.markAttachmentMutation();
            this.attachmentUploadError = '';
            this.attachmentCount = Math.max(0, this.attachmentCount - 1);

            return true;
        },

        resetAttachmentUi() {
            this.attachmentsMayExistOnServer = false;
            this.attachmentCount = 0;
            this.clearAttachmentUploadState();
        },

        markAttachmentMutation() {
            this.attachmentMutationVersion += 1;
            this.attachmentsMayExistOnServer = true;
            this.attachmentCleanupTimedOutToken = '';
            this.attachmentCleanupTimedOutVersion = 0;
        },

        hasPendingAttachmentWork() {
            return Boolean(
                this.attachmentUploadActive
                || this.attachmentsMayExistOnServer
                || this.attachmentCount > 0
                || this.attachmentCleanupPromise
                || this.attachmentFlushPromise,
            );
        },

        cancelWireAttachmentUpload() {
            if (!this.attachmentUploadActive) return true;
            if (typeof this.$wire?.cancelUpload !== 'function') return false;

            try {
                this.$wire.cancelUpload('attachments');
                this.attachmentUploadActive = false;

                return true;
            } catch (_) {
                return false;
            }
        },

        attachmentCleanupId() {
            const uuid = window.crypto?.randomUUID?.();
            if (uuid) return uuid;

            return `cleanup_${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}_${Math.random().toString(36).slice(2)}`;
        },

        attachmentCleanupCallMatches(commit, cleanupId) {
            return Boolean(commit?.calls?.some?.((call) => (
                call?.method === 'discardAttachments'
                && String(call?.params?.[0] ?? '') === String(cleanupId)
            )));
        },

        attachmentFinishCallMatches(commit) {
            return Boolean(commit?.calls?.some?.((call) => (
                call?.method === '_finishUpload'
                && String(call?.params?.[0] ?? '') === 'attachments'
            )));
        },

        handleAttachmentCommit({ commit, succeed, fail } = {}) {
            if (this.attachmentFinishCallMatches(commit)) {
                this.attachmentFinishCommitsInFlight += 1;
                this.markAttachmentMutation();
                let settled = false;
                const settle = () => {
                    if (settled) return;
                    settled = true;
                    this.attachmentFinishCommitsInFlight = Math.max(
                        0,
                        this.attachmentFinishCommitsInFlight - 1,
                    );
                    this.markAttachmentMutation();
                };
                succeed?.(settle);
                fail?.(settle);
            }

            const cleanupId = this.attachmentCleanupToken;
            if (!cleanupId || !this.attachmentCleanupCallMatches(commit, cleanupId)) return;

            fail?.(() => this.failPendingAttachmentCleanup(
                new Error('Livewire attachment cleanup request failed.'),
                cleanupId,
            ));
        },

        async waitForAttachmentCommitBarrier() {
            const startedAt = Date.now();
            let observedVersion = this.attachmentMutationVersion;
            let quietSince = startedAt;

            while (Date.now() - startedAt < this.attachmentCommitBarrierTimeoutMs) {
                await new Promise((resolve) => window.setTimeout(resolve, 20));
                const now = Date.now();
                if (observedVersion !== this.attachmentMutationVersion) {
                    observedVersion = this.attachmentMutationVersion;
                    quietSince = now;
                }

                if (this.attachmentUploadActive || this.attachmentFinishCommitsInFlight > 0) {
                    quietSince = now;

                    continue;
                }

                // Livewire buffers a newly queued component commit for five
                // milliseconds. A wider quiet window covers that queue and
                // prevents an already-started _finishUpload from arriving
                // after the final server-confirmed sweep.
                if (now - quietSince >= 75) return true;
            }

            throw new Error('Attachment upload commits did not settle in time.');
        },

        releaseAttachmentCleanupRequest(cleanupId = '') {
            if (cleanupId && cleanupId !== this.attachmentCleanupToken) return null;

            window.clearTimeout(this.attachmentCleanupTimer);
            this.attachmentCleanupTimer = null;

            const request = {
                token: this.attachmentCleanupToken,
                version: this.attachmentCleanupVersion,
                resolve: this.attachmentCleanupResolve,
                reject: this.attachmentCleanupReject,
            };
            this.attachmentCleanupPromise = null;
            this.attachmentCleanupResolve = null;
            this.attachmentCleanupReject = null;
            this.attachmentCleanupToken = '';
            this.attachmentCleanupVersion = 0;

            return request;
        },

        handleAttachmentCleanupAck(rawDetail) {
            const eventDetail = rawDetail?.detail ?? rawDetail;
            const detail = normalizedEventDetail(eventDetail);
            const cleanupId = String(detail?.cleanup_id ?? detail?.cleanupId ?? '').trim();
            if (!cleanupId) return false;

            if (cleanupId === this.attachmentCleanupToken) {
                const request = this.releaseAttachmentCleanupRequest(cleanupId);
                request?.resolve?.({
                    cleanupId,
                    version: request.version,
                    remaining: Number(detail?.remaining ?? 0),
                });

                return true;
            }

            if (cleanupId !== this.attachmentCleanupTimedOutToken) return false;

            const unchanged = this.attachmentMutationVersion === this.attachmentCleanupTimedOutVersion;
            this.attachmentCleanupTimedOutToken = '';
            this.attachmentCleanupTimedOutVersion = 0;
            if (unchanged && Number(detail?.remaining ?? 0) === 0) {
                this.attachmentsMayExistOnServer = false;
                this.attachmentCount = 0;
                this.clearAttachmentUploadState();
            }

            return true;
        },

        failPendingAttachmentCleanup(error, cleanupId = '', rememberLateAck = true) {
            if (!this.attachmentCleanupPromise) return false;
            if (cleanupId && cleanupId !== this.attachmentCleanupToken) return false;

            const request = this.releaseAttachmentCleanupRequest(cleanupId);
            if (!request) return false;
            if (rememberLateAck) {
                this.attachmentCleanupTimedOutToken = request.token;
                this.attachmentCleanupTimedOutVersion = request.version;
            }
            request.reject?.(error instanceof Error ? error : new Error(String(error || 'Attachment cleanup failed.')));

            return true;
        },

        requestAttachmentCleanupSweep(version) {
            if (this.attachmentCleanupPromise) return this.attachmentCleanupPromise;
            if (typeof this.$wire?.discardAttachments !== 'function') {
                return Promise.reject(new Error('Attachment cleanup is unavailable.'));
            }

            const cleanupId = this.attachmentCleanupId();
            this.attachmentCleanupToken = cleanupId;
            this.attachmentCleanupVersion = version;
            this.attachmentCleanupPromise = new Promise((resolve, reject) => {
                this.attachmentCleanupResolve = resolve;
                this.attachmentCleanupReject = reject;
            });
            const cleanupPromise = this.attachmentCleanupPromise;

            this.attachmentCleanupTimer = window.setTimeout(() => {
                this.failPendingAttachmentCleanup(
                    new Error('Livewire attachment cleanup confirmation timed out.'),
                    cleanupId,
                );
            }, this.attachmentCleanupTimeoutMs);

            try {
                const callResult = this.$wire.discardAttachments(cleanupId);
                if (callResult && typeof callResult.then === 'function') {
                    callResult.then(
                        (result) => this.handleAttachmentCleanupAck(result),
                        (error) => this.failPendingAttachmentCleanup(error, cleanupId),
                    );
                }
            } catch (error) {
                this.failPendingAttachmentCleanup(error, cleanupId, false);
            }

            return cleanupPromise;
        },

        async performAttachmentFlush() {
            if (!this.hasPendingAttachmentWork()) return true;

            this.navigationCleanupInFlight = true;
            this.attachmentUploadError = '';

            try {
                for (let sweep = 0; sweep < 3; sweep += 1) {
                    if (!this.cancelWireAttachmentUpload()) {
                        throw new Error('Active attachment upload could not be cancelled.');
                    }

                    await this.waitForAttachmentCommitBarrier();
                    const version = this.attachmentMutationVersion;
                    const acknowledgement = await this.requestAttachmentCleanupSweep(version);
                    if (Number(acknowledgement?.remaining ?? 0) !== 0) {
                        throw new Error('Attachment cleanup left raw files on the server.');
                    }
                    await this.waitForAttachmentCommitBarrier();

                    if (version === this.attachmentMutationVersion && !this.attachmentUploadActive) {
                        this.attachmentsMayExistOnServer = false;
                        this.attachmentCount = 0;
                        this.clearAttachmentUploadState();

                        return true;
                    }
                }

                throw new Error('Attachment state changed during cleanup.');
            } catch (error) {
                this.attachmentsMayExistOnServer = true;
                this.syncAttachmentCount();
                this.attachmentUploadError = this.strings.attachmentCleanupFailed;

                throw error;
            } finally {
                this.navigationCleanupInFlight = false;
            }
        },

        flushPendingAttachments() {
            if (this.attachmentFlushPromise) return this.attachmentFlushPromise;

            this.attachmentFlushPromise = this.performAttachmentFlush()
                .finally(() => {
                    this.attachmentFlushPromise = null;
                });

            return this.attachmentFlushPromise;
        },

        handleAttachmentFlushError() {
            this.attachmentsMayExistOnServer = true;
            this.syncAttachmentCount();
            this.attachmentUploadError = this.strings.attachmentCleanupFailed;
            this.setOpen(true);
        },

        discardPendingAttachments(waitForServer = false) {
            const cleanup = this.flushPendingAttachments();
            if (!waitForServer) cleanup.catch(() => {});

            return cleanup;
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
