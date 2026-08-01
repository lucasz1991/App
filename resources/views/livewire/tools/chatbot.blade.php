@php
    $assistantLabel = (string) ($assistantName ?? 'RailTime Assist');
    $assistantIsAvailable = (bool) ($assistantAvailable ?? false);
    $speechIsAvailable = (bool) ($speechAvailable ?? false);
    $historySource = $chatHistory ?? [];
    $history = is_array($historySource)
        ? $historySource
        : ($historySource instanceof \Traversable ? iterator_to_array($historySource, false) : []);
    $actionSource = $quickActions ?? [];
    $actions = is_array($actionSource)
        ? $actionSource
        : ($actionSource instanceof \Traversable ? iterator_to_array($actionSource, false) : []);
    $isGerman = app()->getLocale() === 'de';
    $resolvedPageRouteName = trim((string) ($pageRouteName ?? ''));
    $resolvedPageHelpHint = trim((string) ($pageHelpHint ?? ''));

    $ttsEndpoint = \Illuminate\Support\Facades\Route::has('assistant.audio-output.stream')
        ? route('assistant.audio-output.stream', [], false)
        : '';
    $sttEndpoint = \Illuminate\Support\Facades\Route::has('assistant.audio-input.transcribe')
        ? route('assistant.audio-input.transcribe', [], false)
        : '';

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
        'ttsEndpoint' => $ttsEndpoint,
        'sttEndpoint' => $sttEndpoint,
        'csrfToken' => csrf_token(),
        'pageRouteName' => $resolvedPageRouteName,
        'pageHelpHint' => $resolvedPageHelpHint,
        'autoReadDefault' => false,
        'autoListenDefault' => false,
        'autoHelpDefault' => true,
        'speechRate' => 1,
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
    x-on:railtime-assistant-cleared.window="stopSpeaking(); knownAssistantMessageKeys = []; $nextTick(() => scrollMessages(true))"
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
            x-bind:role="petBubbleAnnounce ? 'status' : null"
            x-bind:aria-live="petBubbleAnnounce ? 'polite' : 'off'"
            x-bind:aria-hidden="(!petBubbleAnnounce).toString()"
        >
            <span x-text="petBubbleText"></span>
        </div>

        <button
            type="button"
            class="rt-chatbot__pet-launcher"
            x-ref="launcher"
            x-on:mouseenter="showPetBubble(strings.petHint, 4_500)"
            x-on:focus="showPetBubble(strings.petHint, 4_500)"
            x-on:click="setOpen(true, true)"
            aria-controls="railtime-chatbot-panel"
            x-bind:aria-expanded="open.toString()"
            aria-label="{{ $isGerman ? $assistantLabel . ' öffnen' : 'Open ' . $assistantLabel }}"
        >
            <span class="rt-chatbot__pet-halo" aria-hidden="true"></span>
            <x-railtime-assistant-pet class="rt-chatbot__pet-figure" />
            <span
                class="rt-chatbot__pet-presence {{ $assistantIsAvailable ? '' : 'rt-chatbot__pet-presence--offline' }}"
                aria-hidden="true"
            ></span>
        </button>
    </div>

    <section
        id="railtime-chatbot-panel"
        class="rt-chatbot__panel"
        x-cloak
        x-show="open"
        x-transition.opacity.scale.95
        x-bind:role="isDesktopDocked ? 'complementary' : 'dialog'"
        x-bind:aria-modal="isDesktopDocked ? null : 'true'"
        aria-labelledby="railtime-chatbot-title"
        x-trap.inert.noscroll="open && !isDesktopDocked"
        x-on:keydown.escape.window="if (settingsOpen) closeSettings(true); else if (open) setOpen(false)"
    >
        <header class="rt-chatbot__header">
            <div class="rt-chatbot__identity">
                <span class="rt-chatbot__avatar" aria-hidden="true">
                    <x-railtime-assistant-pet class="rt-chatbot__avatar-pet" />
                </span>
                <div class="rt-chatbot__identity-copy">
                    <span class="rt-chatbot__eyebrow">
                        {{ $isGerman ? 'Dein RailTime-Begleiter' : 'Your RailTime companion' }}
                    </span>
                    <h2 id="railtime-chatbot-title" class="rt-chatbot__title">{{ $assistantLabel }}</h2>
                </div>
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
                            <span>{{ $isGerman
                                ? 'Bitte keine persönlichen Daten, Betriebsgeheimnisse oder Zugangsdaten eingeben.'
                                : 'Please do not enter personal data, business secrets, or credentials.' }}</span>
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

        <div class="rt-chatbot__control-deck">
            <p class="rt-chatbot__status">
                <span
                    class="rt-chatbot__status-dot {{ $assistantIsAvailable ? '' : 'rt-chatbot__status-dot--offline' }}"
                    aria-hidden="true"
                ></span>
                <span>
                    {{ $assistantIsAvailable
                        ? ($speechIsAvailable
                            ? ($isGerman ? 'Bereit für Text und Sprache' : 'Ready for text and voice')
                            : ($isGerman ? 'Bereit für deine Frage' : 'Ready for your question'))
                        : ($isGerman ? 'Momentan nicht verfügbar' : 'Currently unavailable') }}
                </span>
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
                        $displayTime = '';
                        if ($createdAt instanceof \DateTimeInterface) {
                            $displayTime = $createdAt->format('H:i');
                        } elseif (is_string($createdAt) && $createdAt !== '' && strtotime($createdAt) !== false) {
                            $displayTime = date('H:i', strtotime($createdAt));
                        }
                    @endphp

                    <div
                        class="rt-chatbot__message-row rt-chatbot__message-row--{{ $role }}"
                        wire:key="railtime-chatbot-message-{{ $wireMessageKey }}"
                    >
                        @if ($role === 'assistant')
                            <span class="rt-chatbot__message-pet" aria-hidden="true">
                                <x-railtime-assistant-pet />
                            </span>
                        @endif
                        <article class="rt-chatbot__message">
                            <p class="rt-chatbot__message-content">{{ $content }}</p>
                            <footer class="rt-chatbot__message-meta">
                                <span class="rt-chatbot__message-author">
                                    {{ $role === 'assistant' ? $assistantLabel : ($isGerman ? 'Du' : 'You') }}
                                </span>
                                <span class="rt-chatbot__message-meta-actions">
                                    @if ($role === 'assistant' && $speechIsAvailable)
                                        <button
                                            type="button"
                                            class="rt-chatbot__message-play"
                                            x-show="speechSupported"
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
                    </div>
                @empty
                    <div class="rt-chatbot__empty">
                        <span class="rt-chatbot__empty-pet" aria-hidden="true">
                            <x-railtime-assistant-pet />
                        </span>
                        <span class="rt-chatbot__empty-kicker">
                            {{ $speechIsAvailable
                                ? ($isGerman ? 'Text, Sprache und Audio' : 'Text, voice, and audio')
                                : ($isGerman ? 'Dein RailTime-Assistent' : 'Your RailTime assistant') }}
                        </span>
                        <strong>{{ $isGerman ? 'Womit fahren wir los?' : 'Where should we start?' }}</strong>
                        <p>{{ $isGerman
                            ? 'Ich helfe dir, dich in RailTime zurechtzufinden und die nächste sinnvolle Aktion zu finden.'
                            : 'I can help you navigate RailTime and find the next useful action.' }}</p>
                        <span class="rt-chatbot__empty-note">
                            {{ $speechIsAvailable
                                ? ($isGerman ? 'Schreiben oder Mikrofon antippen' : 'Type or tap the microphone')
                                : ($isGerman ? 'Schreib einfach deine Frage' : 'Just type your question') }}
                        </span>
                    </div>
                @endforelse

                <div
                    class="rt-chatbot__message-row"
                    wire:key="railtime-chatbot-loading"
                    wire:loading.flex
                    wire:target="sendMessage,quickAction"
                    style="display: none"
                >
                    <span class="rt-chatbot__message-pet rt-chatbot__message-pet--thinking" aria-hidden="true">
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

            @if (count($actions) > 0)
                <section class="rt-chatbot__quick-actions" aria-labelledby="railtime-chatbot-quick-actions">
                    <div class="rt-chatbot__quick-actions-heading">
                        <span id="railtime-chatbot-quick-actions">
                            {{ $isGerman ? 'Direkt loslegen' : 'Start right away' }}
                        </span>
                        <span aria-hidden="true">→</span>
                    </div>
                    <div class="rt-chatbot__quick-actions-track">
                        @foreach ($actions as $quickAction)
                            @php
                                $action = is_array($quickAction) ? $quickAction : (array) $quickAction;
                                $actionKey = (string) ($action['key'] ?? '');
                                $actionLabel = (string) ($action['label'] ?? $action['prompt'] ?? $actionKey);
                            @endphp
                            @continue($actionKey === '' || $actionLabel === '')
                            <button
                                type="button"
                                class="rt-chatbot__quick-action"
                                wire:key="railtime-chatbot-action-{{ sha1($actionKey) }}"
                                wire:click="quickAction({{ \Illuminate\Support\Js::from($actionKey) }})"
                                wire:loading.attr="disabled"
                                @disabled(! $assistantIsAvailable)
                            >
                                <span class="rt-chatbot__quick-action-mark" aria-hidden="true">
                                    <svg viewBox="0 0 20 20" fill="none">
                                        <path d="m7 5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                                <span>{{ $actionLabel }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif

            <template x-if="audioError">
                <div class="rt-chatbot__error" role="alert">
                    <span aria-hidden="true">!</span>
                    <span x-text="audioError"></span>
                </div>
            </template>

            <form class="rt-chatbot__composer" wire:submit.prevent="sendMessage">
                <div class="rt-chatbot__composer-heading">
                    <span>{{ $isGerman ? 'Deine Nachricht' : 'Your message' }}</span>
                    <span x-cloak x-show="!recording && !voiceUploading && voiceSupported">
                        {{ $isGerman ? 'Mikrofon verfügbar' : 'Microphone ready' }}
                    </span>
                    <span class="rt-chatbot__recording-label" x-cloak x-show="recording">
                        {{ $isGerman ? 'Aufnahme' : 'Recording' }} <span x-text="recordingLabel()"></span> / 0:45
                    </span>
                    <span x-cloak x-show="voiceUploading">
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
                    @if ($speechIsAvailable)
                        <button
                            type="button"
                            class="rt-chatbot__voice"
                            x-bind:class="{ 'rt-chatbot__voice--recording': recording }"
                            x-bind:aria-pressed="recording.toString()"
                            x-bind:disabled="!voiceSupported || voiceUploading || isLoading"
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
                        x-on:input="resizeComposer()"
                        x-on:keydown.enter.exact.prevent="if (!$event.isComposing && $el.value.trim() && !isLoading) $wire.sendMessage()"
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
                        x-bind:disabled="isLoading || !assistantAvailable"
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
                @endif

            </form>
        </div>
    </section>
</div>
