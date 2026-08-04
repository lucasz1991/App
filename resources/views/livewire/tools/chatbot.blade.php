@php
    $assistantLabel = (string) ($assistantName ?? 'RailTime Assist');
    $assistantIsAvailable = (bool) ($assistantAvailable ?? false);
    $speechIsAvailable = (bool) ($speechAvailable ?? false);
    $historySource = $chatHistory ?? [];
    $history = is_array($historySource)
        ? $historySource
        : ($historySource instanceof \Traversable ? iterator_to_array($historySource, false) : []);
    $isGerman = app()->getLocale() === 'de';
    $resolvedPageRouteName = trim((string) ($pageRouteName ?? ''));
    $resolvedPageHelpHint = trim((string) ($pageHelpHint ?? ''));
    $pageHelpHintsSource = $pageHelpHints ?? [];
    $resolvedPageHelpHints = collect(
        is_array($pageHelpHintsSource)
            ? $pageHelpHintsSource
            : ($pageHelpHintsSource instanceof \Traversable ? iterator_to_array($pageHelpHintsSource, false) : [])
    )
        ->filter(fn ($hint): bool => is_scalar($hint) && trim((string) $hint) !== '')
        ->map(fn ($hint): string => trim((string) $hint))
        ->unique()
        ->take(5)
        ->values()
        ->all();
    $pendingAttachmentSource = $attachments ?? [];
    $pendingAttachments = is_array($pendingAttachmentSource)
        ? $pendingAttachmentSource
        : ($pendingAttachmentSource instanceof \Traversable ? iterator_to_array($pendingAttachmentSource, false) : []);

    $ttsEndpoint = \Illuminate\Support\Facades\Route::has('assistant.audio-output.stream')
        ? route('assistant.audio-output.stream', [], false)
        : '';
    $sttEndpoint = \Illuminate\Support\Facades\Route::has('assistant.audio-input.transcribe')
        ? route('assistant.audio-input.transcribe', [], false)
        : '';
    $speechStatusEndpoint = \Illuminate\Support\Facades\Route::has('assistant.speech.status')
        ? route('assistant.speech.status', [], false)
        : '';
    $resolvedSttConfigured = (bool) ($sttConfigured ?? ($speechIsAvailable && $sttEndpoint !== ''));
    $resolvedTtsConfigured = (bool) ($ttsConfigured ?? ($speechIsAvailable && $ttsEndpoint !== ''));
    $canRenderSttControls = $sttEndpoint !== '';
    $canRenderTtsControls = $ttsEndpoint !== '';
    $resolvedSpeechRoutingLabel = trim((string) ($speechRoutingLabel
        ?? ($isGerman ? 'Lokaler Dienst mit externem Fallback' : 'Local service with external fallback')));
    $resolvedExternalFallback = (bool) ($externalFallback ?? false);

    $initialAssistantKeys = [];
    foreach ($history as $historyEntry) {
        $entry = is_array($historyEntry) ? $historyEntry : (array) $historyEntry;
        if (($entry['role'] ?? '') !== 'assistant') {
            continue;
        }

        $entryKey = (string) ($entry['key'] ?? '');
        if ($entryKey === '') {
            $createdAt = $entry['created_at'] ?? '';
            $createdAt = $createdAt instanceof \DateTimeInterface ? $createdAt->format(DATE_ATOM) : (string) $createdAt;
            $entryKey = 'fallback:' . sha1($createdAt . '|' . (string) ($entry['content'] ?? ''));
        }
        $initialAssistantKeys[] = 'assistant:' . $entryKey;
    }

    $chatbotConfig = [
        'assistantAvailable' => $assistantIsAvailable,
        'speechAvailable' => $speechIsAvailable,
        'speechStatusEndpoint' => $speechStatusEndpoint,
        'sttConfigured' => $resolvedSttConfigured,
        'ttsConfigured' => $resolvedTtsConfigured,
        'speechRoutingLabel' => $resolvedSpeechRoutingLabel,
        'externalFallback' => $resolvedExternalFallback,
        'ttsEndpoint' => $ttsEndpoint,
        'sttEndpoint' => $sttEndpoint,
        'csrfToken' => csrf_token(),
        'locale' => app()->getLocale(),
        'pageRouteName' => $resolvedPageRouteName,
        'pageHelpHint' => $resolvedPageHelpHint,
        'pageHelpHints' => $resolvedPageHelpHints,
        'autoReadDefault' => false,
        'autoListenDefault' => false,
        'autoHelpDefault' => true,
        'speechRate' => 1,
        'attachmentCount' => count($pendingAttachments),
        'initialAssistantKeys' => $initialAssistantKeys,
        'strings' => [
            'audioEndpointUnavailable' => $isGerman
                ? 'Die Audioausgabe ist momentan nicht erreichbar.'
                : 'Audio output is currently unavailable.',
            'audioPlaybackBlocked' => $isGerman
                ? 'Der Browser hat die Audiowiedergabe blockiert. Bitte erneut auf Vorlesen tippen.'
                : 'The browser blocked audio playback. Please tap read aloud again.',
            'audioPlaybackFailed' => $isGerman
                ? 'Audio konnte nicht abgespielt werden.'
                : 'Audio could not be played.',
            'audioStopped' => $isGerman ? 'Audioausgabe abgebrochen.' : 'Audio output stopped.',
            'microphoneBlocked' => $isGerman
                ? 'Das Mikrofon ist im Browser blockiert. Bitte über das Schloss-Symbol erlauben.'
                : 'The microphone is blocked in the browser. Please allow it in the site settings.',
            'microphoneFailed' => $isGerman
                ? 'Das Mikrofon konnte nicht verwendet werden.'
                : 'The microphone could not be used.',
            'recordingUnsupported' => $isGerman
                ? 'Sprachaufnahme wird von diesem Browser nicht unterstützt.'
                : 'Voice recording is not supported by this browser.',
            'speechEndpointUnavailable' => $isGerman
                ? 'Die Spracheingabe ist momentan nicht erreichbar.'
                : 'Speech input is currently unavailable.',
            'speechNoText' => $isGerman
                ? 'Es wurde kein gesprochener Text erkannt.'
                : 'No spoken text was detected.',
            'speechPrefix' => $isGerman ? 'Spracheingabe' : 'Speech input',
            'speechChecking' => $isGerman ? 'Sprachdienst wird geprüft …' : 'Checking speech service …',
            'speechReady' => $isGerman ? 'Text und Sprache sind bereit.' : 'Text and speech are ready.',
            'speechPartiallyReady' => $isGerman
                ? 'Der Sprachdienst ist teilweise verfügbar.'
                : 'The speech service is partially available.',
            'speechOffline' => $isGerman
                ? 'Text ist bereit, Sprache momentan nicht.'
                : 'Text is ready, but speech is currently unavailable.',
            'speechDisabled' => $isGerman
                ? 'Sprachfunktionen sind nicht eingerichtet.'
                : 'Speech features are not configured.',
            'localSpeechProvider' => $isGerman ? 'Lokaler Sprachdienst' : 'Local speech service',
            'attachmentUploadFailed' => $isGerman
                ? 'Die Datei konnte nicht hochgeladen werden.'
                : 'The file could not be uploaded.',
            'attachmentUploadCancelled' => $isGerman
                ? 'Der Datei-Upload wurde abgebrochen.'
                : 'The file upload was cancelled.',
            'attachmentCleanupFailed' => $isGerman
                ? 'Die Anhänge konnten vor dem Seitenwechsel nicht sicher entfernt werden.'
                : 'The attachments could not be safely removed before leaving this page.',
            'attachmentTooMany' => $isGerman
                ? 'Es können maximal drei Dateien angehängt werden.'
                : 'You can attach up to three files.',
            'petGreeting' => $isGerman
                ? 'Hi, ich bin dein RailTime-Begleiter.'
                : 'Hi, I am your RailTime companion.',
            'petHint' => $isGerman
                ? 'Was möchtest du heute in RailTime erledigen?'
                : 'What would you like to do in RailTime today?',
            'petVoiceHint' => $isGerman
                ? 'Du kannst deine Frage auch einfach einsprechen.'
                : 'You can also speak your question.',
            'petReplyReady' => $isGerman
                ? 'Ich habe eine Antwort für dich.'
                : 'I have an answer for you.',
            'petUnavailable' => $isGerman
                ? 'Ich mache gerade eine kurze Pause.'
                : 'I am taking a short break right now.',
            'petStatusChecking' => $isGerman
                ? 'Ich prüfe kurz, was hier bereit ist …'
                : 'I am quickly checking what is ready here …',
            'petReadyQuestion' => $isGerman
                ? 'Ich bin bereit. Wobei soll ich dir helfen?'
                : 'I am ready. What can I help you with?',
            'petTextOnlyQuestion' => $isGerman
                ? 'Schreiben ist bereit. Möchtest du mir deine Frage tippen?'
                : 'Text chat is ready. Would you like to type your question?',
            'petPageQuestion' => $isGerman
                ? 'Brauchst du Hilfe bei „:page“?'
                : 'Would you like help with “:page”?',
            'petNextStepQuestion' => $isGerman
                ? 'Soll ich dir auf „:page“ den nächsten Schritt zeigen?'
                : 'Should I show you the next step on “:page”?',
            'petHelpQuestion' => $isGerman
                ? 'Soll ich dich dabei unterstützen?'
                : 'Would you like me to help with that?',
            'petOpenChat' => $isGerman ? 'Chat öffnen' : 'Open chat',
            'petAskByVoice' => $isGerman ? 'Frage sprechen' : 'Ask by voice',
            'petReadAloud' => $isGerman ? 'Vorlesen' : 'Read aloud',
            'petCheckAgain' => $isGerman ? 'Erneut prüfen' : 'Check again',
            'wagonHelp' => $isGerman
                ? 'Soll ich dich per Sprache Schritt für Schritt durch diese Wagenliste führen?'
                : 'Would you like me to guide you through this wagon list step by step by voice?',
            'wagonVoiceStart' => $isGerman ? 'Per Sprache starten' : 'Start by voice',
        ],
    ];
@endphp

<div
    class="rt-chatbot"
    data-railtime-chatbot-root
    x-data="railtimeChatbot({
        ...@js($chatbotConfig),
        isLoading: $wire.entangle('isLoading')
    })"
    x-on:railtime-assistant-reply.window="handleAssistantReply($event.detail)"
    x-on:railtime-assistant-cleared.window="stopSpeaking(); clearPhraseAudioCache(); resetAttachmentUi(); knownAssistantMessageKeys = []; $nextTick(() => { updateComposerState(); scrollMessages(true) })"
    x-on:railtime-assistant-client-action.window="handleClientAction($event.detail)"
    x-on:railtime-wagon-context-updated.window="if (!$event.detail?.editor_open) wagonHelpVisible = false; $wire.updateWagonAssistantContext($event.detail)"
    x-on:railtime-wagon-assistant-result.window="$wire.recordAssistantActionResult($event.detail)"
    x-on:railtime-wagon-assistant-help.window="handleWagonHelp($event.detail)"
>
    <button
        type="button"
        class="rt-chatbot__backdrop"
        aria-label="{{ $isGerman ? 'Assistent schließen' : 'Close assistant' }}"
        x-cloak
        x-show="open && !isDesktopDocked"
        x-transition.opacity
        x-on:click="setOpen(false)"
    ></button>

    <span
        class="rt-chatbot__pet-controller"
        x-data="railtimeAssistantCloud()"
        x-bind:data-pet-open="open.toString()"
        x-bind:data-state="petState()"
        aria-hidden="true"
    ></span>

    <div
        class="rt-chatbot__pet-stage"
        x-cloak
        x-show="!open"
        x-transition
        x-bind:data-state="petState()"
    >
        <div
            class="rt-chatbot__pet-bubble"
            x-cloak
            x-show="petBubbleVisible"
            x-transition:enter="rt-chatbot__pet-bubble-enter"
            x-transition:enter-start="rt-chatbot__pet-bubble-enter-start"
            x-transition:enter-end="rt-chatbot__pet-bubble-enter-end"
            x-transition:leave="rt-chatbot__pet-bubble-leave"
            x-transition:leave-start="rt-chatbot__pet-bubble-enter-end"
            x-transition:leave-end="rt-chatbot__pet-bubble-enter-start"
            x-bind:role="petBubbleActions.length ? 'group' : (petBubbleAnnounce ? 'status' : null)"
            x-bind:aria-live="petBubbleAnnounce ? 'polite' : 'off'"
            x-bind:aria-hidden="(!petBubbleAnnounce && petBubbleActions.length === 0).toString()"
            aria-label="{{ $isGerman ? 'Aktionen des Assistenten' : 'Assistant actions' }}"
        >
            <span x-text="petBubbleText"></span>
            <div
                class="rt-chatbot__pet-bubble-actions"
                x-cloak
                x-show="petBubbleActions.length"
            >
                <template x-for="action in petBubbleActions" x-bind:key="action.key">
                    <button
                        type="button"
                        class="rt-chatbot__pet-bubble-action"
                        x-bind:data-primary="action.primary.toString()"
                        x-bind:disabled="navigationCleanupInFlight && ['start_voice', 'wagon_voice_start'].includes(action.key)"
                        x-on:click.stop="runPetBubbleAction(action)"
                        x-text="action.label"
                    ></button>
                </template>
            </div>
        </div>

        <button
            type="button"
            class="rt-chatbot__pet-launcher"
            x-ref="launcher"
            x-on:mouseenter="showPetBubble(strings.petHint, 4_500)"
            x-on:focus="showPetBubble(strings.petHint, 4_500)"
            x-on:click="handlePetClick()"
            aria-controls="railtime-chatbot-panel"
            x-bind:aria-expanded="open.toString()"
            aria-label="{{ $isGerman ? $assistantLabel . ' öffnen' : 'Open ' . $assistantLabel }}"
        >
            <span class="rt-chatbot__pet-halo" aria-hidden="true"></span>
            <span
                class="rt-chatbot__pet-renderer rt-chatbot__pet-renderer--launcher"
                data-assistant-cloud-slot="launcher"
                wire:ignore
                aria-hidden="true"
            >
                <span class="rt-assistant-cloud__fallback" aria-hidden="true"></span>
            </span>
            <span
                class="rt-chatbot__pet-presence {{ $assistantIsAvailable ? '' : 'rt-chatbot__pet-presence--offline' }}"
                aria-hidden="true"
            ></span>
        </button>
    </div>

    <section
        id="railtime-chatbot-panel"
        class="rt-chatbot__panel"
        data-empty-chat="{{ count($history) === 0 ? 'true' : 'false' }}"
        x-bind:data-settings-open="settingsOpen.toString()"
        x-cloak
        x-show="open"
        x-transition:enter="rt-chatbot__panel-enter"
        x-transition:enter-start="rt-chatbot__panel-enter-start"
        x-transition:enter-end="rt-chatbot__panel-enter-end"
        x-transition:leave="rt-chatbot__panel-leave"
        x-transition:leave-start="rt-chatbot__panel-enter-end"
        x-transition:leave-end="rt-chatbot__panel-enter-start"
        x-bind:role="isDesktopDocked ? 'complementary' : 'dialog'"
        x-bind:aria-modal="isDesktopDocked ? null : 'true'"
        aria-labelledby="railtime-chatbot-title"
        x-trap.inert.noscroll="open && !isDesktopDocked"
        x-on:keydown.escape.window="if (settingsOpen) closeSettings(true); else if (open) setOpen(false)"
    >
        <header class="rt-chatbot__header">
            <div class="rt-chatbot__identity">
                <span
                    class="rt-chatbot__avatar"
                    data-assistant-cloud-slot="header"
                    wire:ignore
                    aria-hidden="true"
                >
                    <span class="rt-assistant-cloud__fallback" aria-hidden="true"></span>
                </span>
                <h2 id="railtime-chatbot-title" class="rt-chatbot__title">{{ $assistantLabel }}</h2>
            </div>

            <div class="rt-chatbot__header-actions">
                <div
                    class="rt-chatbot__settings"
                    x-on:click.outside="if (settingsOpen) closeSettings(false)"
                >
                    <button
                        type="button"
                        class="rt-chatbot__icon-button rt-chatbot__settings-trigger"
                        x-ref="settingsTrigger"
                        x-bind:aria-expanded="settingsOpen.toString()"
                        x-on:click="toggleSettings()"
                        aria-controls="railtime-chatbot-settings"
                        aria-haspopup="dialog"
                        title="{{ $isGerman ? 'Assistent einstellen' : 'Assistant settings' }}"
                    >
                        <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Assistent einstellen' : 'Assistant settings' }}</span>
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M4 7h10M18 7h2M4 17h2M10 17h10M14 4v6M7 14v6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                            <circle cx="14" cy="7" r="2" stroke="currentColor" stroke-width="1.8" />
                            <circle cx="7" cy="17" r="2" stroke="currentColor" stroke-width="1.8" />
                        </svg>
                    </button>

                    <div
                        id="railtime-chatbot-settings"
                        class="rt-chatbot__settings-popover"
                        x-ref="settingsPanel"
                        x-cloak
                        x-show="settingsOpen"
                        x-transition:enter="rt-chatbot__settings-enter"
                        x-transition:enter-start="rt-chatbot__settings-enter-start"
                        x-transition:enter-end="rt-chatbot__settings-enter-end"
                        x-transition:leave="rt-chatbot__settings-leave"
                        x-transition:leave-start="rt-chatbot__settings-enter-end"
                        x-transition:leave-end="rt-chatbot__settings-enter-start"
                        x-trap="settingsOpen"
                        x-on:keydown.escape.stop.prevent="closeSettings(true)"
                        role="dialog"
                        aria-labelledby="railtime-chatbot-settings-title"
                        tabindex="-1"
                    >
                        <div class="rt-chatbot__settings-heading">
                            <div>
                                <span class="rt-chatbot__settings-kicker">{{ $isGerman ? 'Persönlich auf diesem Gerät' : 'Personal on this device' }}</span>
                                <h3 id="railtime-chatbot-settings-title">{{ $isGerman ? 'Assistenz-Einstellungen' : 'Assistant settings' }}</h3>
                            </div>
                            <button
                                type="button"
                                class="rt-chatbot__settings-close"
                                x-on:click="closeSettings(true)"
                                title="{{ $isGerman ? 'Einstellungen schließen' : 'Close settings' }}"
                            >
                                <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Einstellungen schließen' : 'Close settings' }}</span>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="m7 7 10 10M17 7 7 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>

                        <div
                            class="rt-chatbot__speech-card"
                            x-bind:data-tone="speechStatusTone()"
                            role="status"
                            aria-live="polite"
                        >
                            <div class="rt-chatbot__speech-card-heading">
                                <span class="rt-chatbot__speech-card-dot" aria-hidden="true"></span>
                                <span x-text="speechStatusText()"></span>
                            </div>
                            <p>
                                <span>{{ $resolvedSpeechRoutingLabel }}</span>
                                <span x-cloak x-show="speechProviderName()" x-text="speechProviderName()"></span>
                            </p>
                            <div class="rt-chatbot__speech-capabilities" aria-label="{{ $isGerman ? 'Verfügbare Sprachfunktionen' : 'Available speech features' }}">
                                <span x-bind:data-ready="sttReady.toString()">
                                    {{ $isGerman ? 'Diktieren' : 'Dictation' }}
                                </span>
                                <span x-bind:data-ready="ttsReady.toString()">
                                    {{ $isGerman ? 'Vorlesen' : 'Read aloud' }}
                                </span>
                                <span x-cloak x-show="speechFallbackActive" data-fallback="true">
                                    {{ $isGerman ? 'Fallback aktiv' : 'Fallback active' }}
                                </span>
                            </div>
                        </div>

                        <div class="rt-chatbot__settings-list">
                            <label class="rt-chatbot__setting-row">
                                <span class="rt-chatbot__setting-copy">
                                    <strong>{{ $isGerman ? 'Automatische Sprachausgabe' : 'Automatic voice output' }}</strong>
                                    <small>{{ $isGerman ? 'Neue Antworten direkt vorlesen.' : 'Read new responses aloud.' }}</small>
                                </span>
                                <input
                                    type="checkbox"
                                    class="rt-chatbot__setting-input"
                                    x-model="autoRead"
                                    x-bind:disabled="!speechSupported"
                                >
                                <span class="rt-chatbot__setting-switch" aria-hidden="true"></span>
                            </label>

                            <label class="rt-chatbot__setting-row">
                                <span class="rt-chatbot__setting-copy">
                                    <strong>{{ $isGerman ? 'Automatisches Hören' : 'Automatic listening' }}</strong>
                                    <small>{{ $isGerman ? 'Nach einer Antwort das Mikrofon vorbereiten.' : 'Prepare the microphone after a response.' }}</small>
                                </span>
                                <input
                                    type="checkbox"
                                    class="rt-chatbot__setting-input"
                                    x-bind:checked="autoListen"
                                    x-bind:disabled="!voiceSupported"
                                    x-on:change="setAutoListen($event.target.checked, $event.target)"
                                >
                                <span class="rt-chatbot__setting-switch" aria-hidden="true"></span>
                            </label>

                            <label class="rt-chatbot__setting-row">
                                <span class="rt-chatbot__setting-copy">
                                    <strong>{{ $isGerman ? 'Automatisches Helfen' : 'Automatic help' }}</strong>
                                    <small>{{ $isGerman ? 'Einmal pro Seite einen passenden Hinweis zeigen.' : 'Show one useful hint per page.' }}</small>
                                </span>
                                <input
                                    type="checkbox"
                                    class="rt-chatbot__setting-input"
                                    x-model="autoHelp"
                                >
                                <span class="rt-chatbot__setting-switch" aria-hidden="true"></span>
                            </label>

                            <label class="rt-chatbot__setting-select">
                                <span>
                                    <strong>{{ $isGerman ? 'Sprechtempo' : 'Reading speed' }}</strong>
                                    <small>{{ $isGerman ? 'Tempo der Audioausgabe' : 'Voice output speed' }}</small>
                                </span>
                                <select
                                    x-model.number="speechRate"
                                    x-bind:disabled="!speechSupported"
                                    aria-label="{{ $isGerman ? 'Sprechtempo auswählen' : 'Choose reading speed' }}"
                                >
                                    <option value="0.8">{{ $isGerman ? '0,8×' : '0.8×' }}</option>
                                    <option value="1">1×</option>
                                    <option value="1.2">{{ $isGerman ? '1,2×' : '1.2×' }}</option>
                                    <option value="1.4">{{ $isGerman ? '1,4×' : '1.4×' }}</option>
                                </select>
                            </label>
                        </div>

                        <p class="rt-chatbot__settings-privacy">
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M6.5 8V6a3.5 3.5 0 0 1 7 0v2M5 8h10v8H5V8Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span>
                                <span>{{ $isGerman
                                    ? 'Bitte keine persönlichen Daten, Betriebsgeheimnisse oder Zugangsdaten eingeben.'
                                    : 'Please do not enter personal data, business secrets, or credentials.' }}</span>
                                <span x-cloak x-show="externalFallback">
                                    {{ $isGerman
                                        ? ' Bei aktiviertem Fallback können Sprache, Vorlesetext und angehängte Dateien an OpenRouter übertragen werden.'
                                        : ' When fallback is enabled, speech, read-aloud text, and attached files may be sent to OpenRouter.' }}
                                </span>
                            </span>
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    class="rt-chatbot__icon-button"
                    x-cloak
                    x-show="ttsActive()"
                    x-on:click="stopSpeaking()"
                    title="{{ $isGerman ? 'Vorlesen stoppen' : 'Stop reading' }}"
                >
                    <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Vorlesen stoppen' : 'Stop reading' }}</span>
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <rect x="7" y="7" width="10" height="10" rx="1.5" fill="currentColor"/>
                    </svg>
                </button>

                <button
                    type="button"
                    class="rt-chatbot__icon-button"
                    wire:click="clearChat"
                    wire:loading.attr="disabled"
                    x-bind:disabled="navigationCleanupInFlight"
                    title="{{ $isGerman ? 'Unterhaltung leeren' : 'Clear conversation' }}"
                >
                    <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Unterhaltung leeren' : 'Clear conversation' }}</span>
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M8.5 6.5h7M9 6.5l.6-2h4.8l.6 2M7 8.5l.75 10h8.5l.75-10M10 10.5v5.5M14 10.5v5.5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>

                <button
                    type="button"
                    class="rt-chatbot__icon-button"
                    x-on:click="setOpen(false)"
                    title="{{ $isGerman ? 'Assistent schließen' : 'Close assistant' }}"
                >
                    <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Assistent schließen' : 'Close assistant' }}</span>
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m7 7 10 10M17 7 7 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
        </header>

        <div
            class="rt-chatbot__service-alert"
            x-cloak
            x-show="!assistantAvailable || ['partial', 'offline', 'disabled'].includes(speechStatusTone())"
            role="status"
            aria-live="polite"
        >
            <p>
                <span
                    class="rt-chatbot__status-dot {{ $assistantIsAvailable ? '' : 'rt-chatbot__status-dot--offline' }}"
                    x-bind:data-tone="assistantAvailable ? speechStatusTone() : 'offline'"
                    aria-hidden="true"
                ></span>
                <span x-text="assistantAvailable
                    ? speechStatusText()
                    : @js($isGerman ? 'Momentan nicht verfügbar' : 'Currently unavailable')"></span>
            </p>
        </div>

        <div class="rt-chatbot__body">
            <div
                class="rt-chatbot__messages"
                x-ref="messages"
                x-on:scroll.passive="handleMessagesScroll()"
                aria-live="polite"
                aria-relevant="additions text"
            >
                @forelse ($history as $historyEntry)
                    @php
                        $entry = is_array($historyEntry) ? $historyEntry : (array) $historyEntry;
                        $role = ($entry['role'] ?? '') === 'user' ? 'user' : 'assistant';
                        $content = (string) ($entry['content'] ?? '');
                        $createdAt = $entry['created_at'] ?? '';
                        $createdAtKey = $createdAt instanceof \DateTimeInterface ? $createdAt->format(DATE_ATOM) : (string) $createdAt;
                        $entryKey = (string) ($entry['key'] ?? '');
                        $entryKey = $entryKey !== '' ? $entryKey : 'fallback:' . sha1($createdAtKey . '|' . $content);
                        $messageKey = $role . ':' . $entryKey;
                        $wireMessageKey = sha1($messageKey);
                        $entryAttachmentSource = $entry['attachments'] ?? [];
                        $messageAttachments = is_array($entryAttachmentSource)
                            ? $entryAttachmentSource
                            : ($entryAttachmentSource instanceof \Traversable ? iterator_to_array($entryAttachmentSource, false) : []);
                        $entryActionSource = $entry['actions'] ?? [];
                        $messageActions = is_array($entryActionSource)
                            ? $entryActionSource
                            : ($entryActionSource instanceof \Traversable ? iterator_to_array($entryActionSource, false) : []);
                        $displayTime = '';
                        if ($createdAt instanceof \DateTimeInterface) {
                            $displayTime = $createdAt->format('H:i');
                        } elseif (is_string($createdAt) && $createdAt !== '' && strtotime($createdAt) !== false) {
                            $displayTime = date('H:i', strtotime($createdAt));
                        }
                        $messageCharacterLength = max(1, mb_strlen($content));
                        $speechTokens = [];
                        if ($role === 'assistant' && $canRenderTtsControls) {
                            $tokenParts = preg_split('/(\s+)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
                            $tokenOffset = 0;
                            foreach ($tokenParts as $tokenPart) {
                                $tokenLength = mb_strlen($tokenPart);
                                $speechTokens[] = [
                                    'text' => $tokenPart,
                                    'start' => $tokenOffset,
                                    'end' => $tokenOffset + $tokenLength,
                                ];
                                $tokenOffset += $tokenLength;
                            }
                        }
                    @endphp

                    <div
                        class="rt-chatbot__message-row rt-chatbot__message-row--{{ $role }}"
                        wire:key="railtime-chatbot-message-{{ $wireMessageKey }}"
                    >
                        @if ($role === 'assistant')
                            <span
                                class="rt-chatbot__message-pet"
                                x-bind:data-state="ttsActiveKey === @js($messageKey)
                                    ? (speaking ? 'speaking' : 'thinking')
                                    : 'idle'"
                                aria-hidden="true"
                            >
                                <x-railtime-assistant-pet />
                            </span>
                        @endif
                        <div class="rt-chatbot__message-stack">
                            <article class="rt-chatbot__message">
                            @if ($role === 'assistant' && $canRenderTtsControls)
                                <p
                                    class="rt-chatbot__message-content rt-chatbot__message-content--readable"
                                    x-bind:data-reading="(ttsActiveKey === @js($messageKey) && ttsActive()).toString()"
                                >@foreach ($speechTokens as $speechToken)<span class="rt-chatbot__speech-token" x-bind:data-read-state="ttsTokenState(@js($messageKey), {{ $speechToken['start'] }}, {{ $speechToken['end'] }}, {{ $messageCharacterLength }})">{{ $speechToken['text'] }}</span>@endforeach</p>
                            @else
                                <p class="rt-chatbot__message-content">{{ $content }}</p>
                            @endif
                            @if (count($messageAttachments) > 0)
                                <ul
                                    class="rt-chatbot__message-attachments"
                                    aria-label="{{ $isGerman ? 'Anhänge dieser Nachricht' : 'Attachments in this message' }}"
                                >
                                    @foreach ($messageAttachments as $messageAttachment)
                                        @php
                                            $attachmentMeta = is_array($messageAttachment) ? $messageAttachment : (array) $messageAttachment;
                                            $attachmentName = trim((string) ($attachmentMeta['name'] ?? ''));
                                            $attachmentSize = max(0, (int) ($attachmentMeta['size'] ?? 0));
                                            $attachmentSizeLabel = $attachmentSize >= 1048576
                                                ? number_format($attachmentSize / 1048576, 1, $isGerman ? ',' : '.', '') . ' MB'
                                                : number_format(max(1, $attachmentSize) / 1024, 0, $isGerman ? ',' : '.', '') . ' KB';
                                        @endphp
                                        @continue($attachmentName === '')
                                        <li wire:key="railtime-chatbot-message-attachment-{{ sha1($messageKey . '|' . $attachmentName . '|' . $loop->index) }}">
                                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                <path d="M6.5 2.75h5L15 6.25v10.5H6.5V2.75Z" stroke="currentColor" stroke-width="1.35" stroke-linejoin="round" />
                                                <path d="M11.5 2.75v3.5H15" stroke="currentColor" stroke-width="1.35" stroke-linejoin="round" />
                                            </svg>
                                            <span title="{{ $attachmentName }}">{{ $attachmentName }}</span>
                                            <small>{{ $attachmentSizeLabel }}</small>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <footer class="rt-chatbot__message-meta">
                                <span class="rt-chatbot__message-author">
                                    {{ $role === 'assistant' ? $assistantLabel : ($isGerman ? 'Du' : 'You') }}
                                </span>
                                <span class="rt-chatbot__message-meta-actions">
                                    @if ($role === 'assistant' && $canRenderTtsControls)
                                        <button
                                            type="button"
                                            class="rt-chatbot__message-play"
                                            x-show="speechSupported"
                                            x-bind:disabled="!manualTtsAvailable()"
                                            x-bind:aria-pressed="(speaking && speakingKey === @js($messageKey)).toString()"
                                            x-on:click="speaking && speakingKey === @js($messageKey)
                                                ? stopSpeaking()
                                                : speak(@js($content), @js($messageKey))"
                                            title="{{ $isGerman ? 'Nachricht vorlesen' : 'Read message aloud' }}"
                                        >
                                            <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Nachricht vorlesen' : 'Read message aloud' }}</span>
                                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                                <path d="M7 9.5v5h3.25l4 3.25V6.25l-4 3.25H7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                                                <path d="M17 9.25a4 4 0 0 1 0 5.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                            </svg>
                                        </button>
                                    @endif
                                    @if ($displayTime !== '')
                                        <time datetime="{{ $createdAtKey }}">{{ $displayTime }}</time>
                                    @endif
                                </span>
                            </footer>
                            </article>

                            @if ($role === 'assistant' && $loop->last && count($messageActions) > 0)
                                <div
                                    class="rt-chatbot__message-actions"
                                    role="group"
                                    aria-label="{{ $isGerman ? 'Passende Optionen' : 'Suggested options' }}"
                                >
                                    @foreach ($messageActions as $quickAction)
                                        @php
                                            $action = is_array($quickAction) ? $quickAction : (array) $quickAction;
                                            $actionKind = (string) ($action['kind'] ?? '');
                                            $actionKey = (string) ($action['key'] ?? '');
                                            $actionToken = (string) ($action['token'] ?? '');
                                            $actionLabel = (string) ($action['label'] ?? $action['prompt'] ?? $actionKey);
                                        @endphp
                                        @continue($actionLabel === '' || ! in_array($actionKind, ['prompt', 'pending_tool'], true))
                                        @continue($actionKind === 'prompt' && $actionKey === '')
                                        @continue($actionKind === 'pending_tool' && ! preg_match('/\A[a-zA-Z0-9]{48}\z/', $actionToken))
                                        <button
                                            type="button"
                                            class="rt-chatbot__message-action"
                                            wire:key="railtime-chatbot-action-{{ sha1($messageKey . '|' . $actionKind . '|' . $actionKey . '|' . $actionToken) }}"
                                            @if ($actionKind === 'pending_tool')
                                                wire:click="confirmAssistantAction({{ \Illuminate\Support\Js::from($actionToken) }})"
                                            @else
                                                wire:click="quickAction({{ \Illuminate\Support\Js::from($actionKey) }})"
                                            @endif
                                            wire:loading.attr="disabled"
                                            x-bind:disabled="navigationCleanupInFlight || isLoading || !assistantAvailable"
                                            @disabled(! $assistantIsAvailable)
                                        >
                                            <span>{{ $actionLabel }}</span>
                                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                                <path d="m7 5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="rt-chatbot__empty">
                        <span class="rt-chatbot__empty-pet" aria-hidden="true">
                            <x-railtime-assistant-pet />
                        </span>
                        <strong>{{ $isGerman ? 'Womit fahren wir los?' : 'Where should we start?' }}</strong>
                    </div>
                @endforelse

                <div
                    class="rt-chatbot__message-row rt-chatbot__message-row--assistant"
                    x-cloak
                    x-show="wagonHelpVisible"
                    role="status"
                    aria-live="polite"
                >
                    <span class="rt-chatbot__message-pet" aria-hidden="true">
                        <x-railtime-assistant-pet />
                    </span>
                    <div class="rt-chatbot__message-stack">
                        <article class="rt-chatbot__message">
                            <p class="rt-chatbot__message-content" x-text="wagonHelpText"></p>
                        </article>
                        <div class="rt-chatbot__message-actions" role="group" aria-label="{{ $isGerman ? 'Wagenlisten-Hilfe' : 'Wagon-list help' }}">
                            <button
                                type="button"
                                class="rt-chatbot__message-action"
                                x-on:click="runWagonHelpAction()"
                                x-bind:disabled="navigationCleanupInFlight || isLoading || !assistantAvailable"
                            >
                                <span x-text="strings.wagonVoiceStart"></span>
                                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="m7 5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    class="rt-chatbot__message-row"
                    wire:key="railtime-chatbot-loading"
                    wire:loading.flex
                    wire:target="sendMessage,quickAction"
                    style="display: none"
                >
                    <span class="rt-chatbot__message-pet rt-chatbot__message-pet--thinking" data-state="thinking" aria-hidden="true">
                        <x-railtime-assistant-pet />
                    </span>
                    <div class="rt-chatbot__message" aria-label="{{ $isGerman ? 'Antwort wird erstellt' : 'Preparing response' }}">
                        <p class="rt-chatbot__message-content rt-chatbot__stream" wire:stream="assistant-response-stream"></p>
                        <span class="rt-chatbot__typing" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </span>
                    </div>
                </div>
            </div>

            <template x-if="audioError">
                <div class="rt-chatbot__error" role="alert">
                    <span aria-hidden="true">!</span>
                    <span x-text="audioError"></span>
                </div>
            </template>

            <template x-if="attachmentUploadError">
                <div class="rt-chatbot__error" role="alert">
                    <span aria-hidden="true">!</span>
                    <span x-text="attachmentUploadError"></span>
                </div>
            </template>

            <form class="rt-chatbot__composer" wire:submit.prevent="sendMessage">
                <div
                    class="rt-chatbot__attachments"
                    x-ref="attachmentList"
                    aria-live="polite"
                    aria-label="{{ $isGerman ? 'Ausgewählte Anhänge' : 'Selected attachments' }}"
                >
                    @foreach ($pendingAttachments as $attachmentIndex => $pendingAttachment)
                        @php
                            $pendingAttachmentName = method_exists($pendingAttachment, 'getClientOriginalName')
                                ? trim((string) $pendingAttachment->getClientOriginalName())
                                : '';
                            $pendingAttachmentSize = method_exists($pendingAttachment, 'getSize')
                                ? max(0, (int) $pendingAttachment->getSize())
                                : 0;
                            $pendingAttachmentSizeLabel = $pendingAttachmentSize >= 1048576
                                ? number_format($pendingAttachmentSize / 1048576, 1, $isGerman ? ',' : '.', '') . ' MB'
                                : number_format(max(1024, $pendingAttachmentSize) / 1024, 0, $isGerman ? ',' : '.', '') . ' KB';
                        @endphp
                        @continue($pendingAttachmentName === '')
                        <span
                            class="rt-chatbot__attachment-chip"
                            data-chatbot-attachment-chip
                            wire:key="railtime-chatbot-pending-attachment-{{ sha1($pendingAttachmentName . '|' . $pendingAttachmentSize . '|' . $attachmentIndex) }}"
                        >
                            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="M7.5 10.75 11.9 6.3a2.2 2.2 0 1 1 3.1 3.12l-6.1 6.12a3.45 3.45 0 0 1-4.88-4.88l6.3-6.32" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <span class="rt-chatbot__attachment-chip-copy">
                                <strong title="{{ $pendingAttachmentName }}">{{ $pendingAttachmentName }}</strong>
                                <small>{{ $pendingAttachmentSizeLabel }}</small>
                            </span>
                            <button
                                type="button"
                                wire:click="removeAttachment({{ (int) $attachmentIndex }})"
                                wire:loading.attr="disabled"
                                wire:target="removeAttachment({{ (int) $attachmentIndex }})"
                                x-bind:disabled="navigationCleanupInFlight"
                                x-on:click="markAttachmentRemoval()"
                                aria-label="{{ $isGerman ? 'Anhang entfernen: ' : 'Remove attachment: ' }}{{ $pendingAttachmentName }}"
                            >
                                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                    <path d="m6 6 8 8M14 6l-8 8" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" />
                                </svg>
                            </button>
                        </span>
                    @endforeach
                </div>

                <div
                    class="rt-chatbot__upload-progress"
                    x-cloak
                    x-show="attachmentUploadActive"
                    role="progressbar"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    x-bind:aria-valuenow="Math.round(attachmentUploadProgress)"
                    aria-label="{{ $isGerman ? 'Dateien werden hochgeladen' : 'Uploading files' }}"
                >
                    <span style="--rt-chatbot-upload-progress: 0%" x-bind:style="`--rt-chatbot-upload-progress: ${attachmentUploadProgress}%`"></span>
                    <small x-text="`${Math.round(attachmentUploadProgress)} %`"></small>
                </div>

                <div
                    class="rt-chatbot__composer-state"
                    x-cloak
                    x-show="recording || voiceUploading"
                    role="status"
                    aria-live="polite"
                    aria-atomic="true"
                >
                    <span class="rt-chatbot__recording-label" x-show="recording">
                        {{ $isGerman ? 'Aufnahme' : 'Recording' }} <span x-text="recordingLabel()"></span> / 0:45
                    </span>
                    <span x-show="voiceUploading">
                        {{ $isGerman ? 'Sprache wird erkannt …' : 'Transcribing speech …' }}
                    </span>
                </div>
                <label class="rt-chatbot__sr-only" for="railtime-chatbot-message">
                    {{ $isGerman ? 'Nachricht an den Assistenten' : 'Message to the assistant' }}
                </label>
                <div
                    class="rt-chatbot__composer-shell"
                    x-bind:class="{ 'rt-chatbot__composer-shell--recording': recording }"
                >
                    <input
                        id="railtime-chatbot-attachments"
                        class="rt-chatbot__sr-only"
                        type="file"
                        multiple
                        accept=".txt,.md,.csv,.json,.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx,.pptx,text/plain,text/markdown,text/csv,application/json,application/pdf,image/jpeg,image/png,image/webp,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.presentationml.presentation"
                        tabindex="-1"
                        x-ref="attachmentInput"
                        wire:model="attachments"
                        x-on:change="handleAttachmentSelection($event)"
                        x-on:livewire-upload-start="beginAttachmentUpload()"
                        x-on:livewire-upload-progress="updateAttachmentUpload($event.detail)"
                        x-on:livewire-upload-finish="completeAttachmentUpload()"
                        x-on:livewire-upload-error="failAttachmentUpload()"
                        x-on:livewire-upload-cancel="cancelAttachmentUpload()"
                        x-bind:disabled="isLoading || attachmentUploadActive || navigationCleanupInFlight || attachmentCount >= 3 || !assistantAvailable"
                    >
                    <button
                        type="button"
                        class="rt-chatbot__attach"
                        x-on:click="$refs.attachmentInput?.click()"
                        x-bind:disabled="isLoading || attachmentUploadActive || navigationCleanupInFlight || attachmentCount >= 3 || !assistantAvailable"
                        title="{{ $isGerman ? 'Dateien anhängen' : 'Attach files' }}"
                    >
                        <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Dateien anhängen' : 'Attach files' }}</span>
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="m8.25 12.75 6.15-6.16a3.25 3.25 0 0 1 4.6 4.6l-8.12 8.12a5 5 0 0 1-7.07-7.07l8.4-8.4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    @if ($canRenderSttControls)
                        <button
                            type="button"
                            class="rt-chatbot__voice"
                            x-bind:class="{ 'rt-chatbot__voice--recording': recording }"
                            x-bind:aria-pressed="recording.toString()"
                            x-bind:disabled="!manualVoiceAvailable() || voiceUploading || isLoading || navigationCleanupInFlight"
                            wire:loading.attr="disabled"
                            wire:target="sendMessage,quickAction"
                            x-on:click="toggleVoice()"
                            title="{{ $isGerman ? 'Spracheingabe' : 'Speech input' }}"
                        >
                            <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Spracheingabe starten oder stoppen' : 'Start or stop speech input' }}</span>
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <rect x="9" y="4" width="6" height="10" rx="3" stroke="currentColor" stroke-width="1.8"/>
                                <path d="M6.5 11.5a5.5 5.5 0 0 0 11 0M12 17v3M9 20h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                        </button>
                    @endif

                    <textarea
                        id="railtime-chatbot-message"
                        class="rt-chatbot__input"
                        rows="1"
                        maxlength="4000"
                        x-ref="composer"
                        wire:model="message"
                        x-on:input="resizeComposer(); updateComposerState()"
                        x-on:keydown.enter.exact.prevent="handleComposerEnter($event)"
                        x-bind:disabled="isLoading || navigationCleanupInFlight || !assistantAvailable"
                        wire:loading.attr="disabled"
                        wire:target="sendMessage,quickAction"
                        placeholder="{{ $isGerman ? 'Frag mich etwas zu RailTime …' : 'Ask me anything about RailTime …' }}"
                        autocomplete="off"
                        @disabled(! $assistantIsAvailable)
                    ></textarea>

                    <button
                        type="submit"
                        class="rt-chatbot__send"
                        wire:loading.attr="disabled"
                        x-bind:disabled="!canSubmit()"
                        title="{{ $isGerman ? 'Nachricht senden' : 'Send message' }}"
                    >
                        <span class="rt-chatbot__sr-only">{{ $isGerman ? 'Nachricht senden' : 'Send message' }}</span>
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="m4 5 16 7-16 7 2.2-6.1L14 12 6.2 11.1 4 5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>

                @if (isset($errors))
                    @error('message')
                        <p class="rt-chatbot__validation" role="alert">{{ $message }}</p>
                    @enderror
                    @error('attachments')
                        <p class="rt-chatbot__validation" role="alert">{{ $message }}</p>
                    @enderror
                    @error('attachments.*')
                        <p class="rt-chatbot__validation" role="alert">{{ $message }}</p>
                    @enderror
                @endif

            </form>
        </div>
    </section>
</div>
