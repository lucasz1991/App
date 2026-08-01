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
        'autoReadDefault' => false,
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
            role="status"
            aria-live="polite"
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
        x-on:keydown.escape.window="if (open) setOpen(false)"
    >
        <header class="rt-chatbot__header">
            <div class="rt-chatbot__identity">
                <span class="rt-chatbot__avatar" aria-hidden="true">RT</span>
                <div class="rt-chatbot__identity-copy">
                    <h2 id="railtime-chatbot-title" class="rt-chatbot__title">{{ $assistantLabel }}</h2>
                    <p class="rt-chatbot__status">
                        <span
                            class="rt-chatbot__status-dot {{ $assistantIsAvailable ? '' : 'rt-chatbot__status-dot--offline' }}"
                            aria-hidden="true"
                        ></span>
                        {{ $assistantIsAvailable
                            ? ($isGerman ? 'Bereit für deine Frage' : 'Ready for your question')
                            : ($isGerman ? 'Momentan nicht verfügbar' : 'Currently unavailable') }}
                    </p>
                </div>
            </div>

            <div class="rt-chatbot__header-actions">
                <button
                    type="button"
                    class="rt-chatbot__icon-button"
                    x-show="speechSupported"
                    x-bind:aria-pressed="autoRead.toString()"
                    x-bind:title="autoRead
                        ? @js($isGerman ? 'Automatisches Vorlesen ausschalten' : 'Disable automatic reading')
                        : @js($isGerman ? 'Antworten automatisch vorlesen' : 'Read responses automatically')"
                    x-on:click="autoRead = !autoRead"
                >
                    <span class="rt-chatbot__sr-only" x-text="autoRead
                        ? @js($isGerman ? 'Automatisches Vorlesen eingeschaltet' : 'Automatic reading enabled')
                        : @js($isGerman ? 'Automatisches Vorlesen ausgeschaltet' : 'Automatic reading disabled')"></span>
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M5 9.5v5h3.5l4.25 3.5V6L8.5 9.5H5Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                        <path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </button>

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
                        <article class="rt-chatbot__message">
                            <p class="rt-chatbot__message-content">{{ $content }}</p>
                            <footer class="rt-chatbot__message-meta">
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
                            </footer>
                        </article>
                    </div>
                @empty
                    <div class="rt-chatbot__empty">
                        <strong>{{ $isGerman ? 'Wobei kann ich helfen?' : 'How can I help?' }}</strong>
                        <p>{{ $isGerman
                            ? 'Frag nach deinen Nachrichten, Terminen oder Funktionen in RailTime.'
                            : 'Ask about your messages, appointments, or features in RailTime.' }}</p>
                    </div>
                @endforelse

                <div
                    class="rt-chatbot__message-row"
                    wire:key="railtime-chatbot-loading"
                    wire:loading.flex
                    wire:target="sendMessage,quickAction"
                    style="display: none"
                >
                    <div class="rt-chatbot__message" aria-label="{{ $isGerman ? 'Antwort wird erstellt' : 'Preparing response' }}">
                        <p class="rt-chatbot__message-content rt-chatbot__stream" wire:stream="assistant-response-stream"></p>
                        <span class="rt-chatbot__typing" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </span>
                    </div>
                </div>
            </div>

            @if (count($actions) > 0)
                <div class="rt-chatbot__quick-actions" aria-label="{{ $isGerman ? 'Schnellaktionen' : 'Quick actions' }}">
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
                        >{{ $actionLabel }}</button>
                    @endforeach
                </div>
            @endif

            <template x-if="audioError">
                <div class="rt-chatbot__error" role="alert">
                    <span aria-hidden="true">!</span>
                    <span x-text="audioError"></span>
                </div>
            </template>

            <form class="rt-chatbot__composer" wire:submit.prevent="sendMessage">
                <label class="rt-chatbot__sr-only" for="railtime-chatbot-message">
                    {{ $isGerman ? 'Nachricht an den Assistenten' : 'Message to the assistant' }}
                </label>
                <div class="rt-chatbot__composer-shell">
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
                        placeholder="{{ $isGerman ? 'Nachricht schreiben …' : 'Write a message …' }}"
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

                <div class="rt-chatbot__composer-meta" aria-live="polite">
                    <span x-show="!recording && !voiceUploading">
                        {{ $isGerman ? 'Enter sendet · Shift + Enter fügt eine Zeile ein' : 'Enter sends · Shift + Enter adds a line' }}
                    </span>
                    <span class="rt-chatbot__recording-label" x-cloak x-show="recording">
                        {{ $isGerman ? 'Aufnahme' : 'Recording' }} <span x-text="recordingLabel()"></span> / 0:45
                    </span>
                    <span x-cloak x-show="voiceUploading">
                        {{ $isGerman ? 'Sprache wird erkannt …' : 'Transcribing speech …' }}
                    </span>
                </div>
                <p class="rt-chatbot__privacy">
                    {{ $isGerman
                        ? 'Keine personenbezogenen Daten, Betriebsgeheimnisse oder Zugangsdaten eingeben. Die Unterhaltung wird zur Antwort über OpenRouter verarbeitet.'
                        : 'Do not enter personal data, business secrets, or credentials. The conversation is processed through OpenRouter to generate the answer.' }}
                </p>
            </form>
        </div>
    </section>
</div>
