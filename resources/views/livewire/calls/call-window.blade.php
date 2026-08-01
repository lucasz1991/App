{{-- Echtes Vollbild: Das Layout rendert hier OHNE Topbar/Sidebar
     (layouts.master mit chrome=false) - waehrend eines Anrufs ist die
     App-Navigation Ablenkung, kein Werkzeug. Verlassen fuehrt ueber
     Auflegen zurueck in den Chat. fixed+z-[200] bleibt als Absicherung
     gegen darunterliegende Seitenreste. --}}
<div
    class="fixed inset-0 z-[200] overflow-hidden bg-[#0b0e13]"
    data-rt-overlay-layer
    data-rt-overlay-base="200"
    x-data="callRoom({
        roomUuid: @js($room->uuid),
        tokenUrl: @js(route('calls.token', $room)),
        recordingAcknowledgementUrl: @js(route('calls.recording.acknowledge')),
        recordingPolicyVersion: @js(config('call_recording.policy_version')),
        csrf: @js(csrf_token()),
        selfIdentity: @js(\App\Services\Calls\LiveKitService::identityFor(auth()->user())),
        canPublish: @js($me?->canPublish() ?? false),
        startWithVideo: @js((bool) data_get($room->settings, 'video', true)),
        waiting: @js($this->waitingFor()),
        labels: {
            beingCalled: @js(__('app.calls_being_called')),
            ringsThere: @js(__('app.calls_rings_there')),
            connecting: @js(__('app.calls_connecting')),
            reconnecting: @js(__('app.calls_reconnecting')),
            ended: @js(__('app.calls_ended')),
            muted: @js(__('app.calls_you_were_muted')),
            connectionFailed: @js(__('app.calls_connection_failed')),
            microphoneBlocked: @js(__('app.calls_microphone_blocked')),
            cameraBlocked: @js(__('app.calls_camera_blocked')),
            microphoneMissing: @js(__('app.calls_microphone_missing')),
            cameraMissing: @js(__('app.calls_camera_missing')),
            microphoneBusy: @js(__('app.calls_microphone_busy')),
            cameraBusy: @js(__('app.calls_camera_busy')),
            recordingPreparing: @js(__('app.calls_recording_preparing')),
            recordingActive: @js(__('app.calls_recording_active')),
            recordingFailed: @js(__('app.calls_recording_failed')),
            recordingAcknowledgementRequired: @js(__('app.calls_recording_acknowledgement_required')),
            recordingAcknowledgementFailed: @js(__('app.calls_recording_acknowledgement_failed')),
        },
    })"
    x-on:beforeunload.window="disconnect()"
    x-on:rt-call-waiting.window="setWaiting($event.detail.waiting)"
>
    <div class="flex h-full min-h-0 flex-col overflow-hidden bg-rt-anthracite">

        {{-- Kopfzeile --}}
        <div class="flex shrink-0 items-center gap-3 px-4 py-3 sm:px-6">
            <span class="relative flex h-2.5 w-2.5">
                <span x-show.important="connected" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="connected ? 'bg-emerald-500' : 'bg-amber-400'"></span>
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-extrabold tracking-[-0.025em] text-white sm:text-[15px]">
                    {{ $room->name }}
                </p>
                <p class="text-[10px] font-bold uppercase tracking-[0.1em] text-white/50">
                    <span x-show="connected" x-cloak>{{ __('app.calls_in_progress') }} · <span x-text="participantCount"></span> {{ __('app.calls_participants') }}</span>
                    <span x-show="! connected" x-text="statusLabel"></span>
                </p>
            </div>

            <div
                x-cloak
                x-show="recordingEnabled"
                class="hidden min-h-10 items-center gap-2 rounded-xl bg-white/[0.07] px-3 text-[11px] font-bold text-white/80 ring-1 ring-white/10 sm:inline-flex"
                role="status"
                aria-live="polite"
            >
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                    :class="recordingStatus === 'active' ? 'animate-pulse bg-red-500' : (['failed', 'aborted', 'unavailable'].includes(recordingStatus) ? 'bg-rose-400' : 'bg-amber-400')"
                    aria-hidden="true"
                ></span>
                <span x-text="recordingStatusLabel"></span>
            </div>

            <x-ui.page-info-button
                :title="$room->name"
                route-name="calls.window"
                variant="inverse"
            />

            @if ($canModerate)
                <x-ui.dropdown.anchor-dropdown
                    align="right"
                    width="w-80"
                    :offset="8"
                    dropdown-id="call-invite-users"
                    layer-group="call-window"
                    wire:key="call-invite-users-{{ $room->id }}"
                >
                    <x-slot name="trigger">
                        <button
                            type="button"
                            aria-label="{{ __('app.calls_invite_people') }}"
                            title="{{ __('app.calls_invite_people') }}"
                            class="inline-flex h-10 items-center gap-2 rounded-xl bg-white/[0.07] px-3 text-xs font-bold text-white/80 ring-1 ring-white/10 transition hover:bg-white/[0.12] hover:text-white"
                        >
                            <i class="far fa-user-plus" aria-hidden="true"></i>
                            <span class="hidden sm:inline">{{ __('app.calls_invite_people') }}</span>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="w-80 max-w-[calc(100vw-1.5rem)] p-2">
                            <div class="px-2 pb-2 pt-1">
                                <p class="text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ __('app.calls_invite_people') }}</p>
                                <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.calls_invite_people_hint') }}</p>
                            </div>
                            <div class="scroll-container max-h-72 space-y-1 overflow-y-auto">
                                @forelse($inviteCandidates as $candidate)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 transition hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted">
                                        <input
                                            type="checkbox"
                                            value="{{ $candidate->id }}"
                                            wire:model="inviteeIds"
                                            class="h-4 w-4 rounded border-rt-border text-rt-red focus:ring-rt-red/30 dark:border-rt-dark-border dark:bg-rt-dark-control"
                                        >
                                        <x-chat.avatar :src="$candidate->profile_photo_url" :name="$candidate->name" size="sm" />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $candidate->name }}</span>
                                            <span class="block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $candidate->email }}</span>
                                        </span>
                                    </label>
                                @empty
                                    <p class="px-2.5 py-5 text-center text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.calls_no_invite_candidates') }}</p>
                                @endforelse
                            </div>
                            @if($inviteCandidates->isNotEmpty())
                                <div class="mt-2 border-t border-rt-border/60 pt-2 dark:border-rt-dark-border/60">
                                    <x-ui.buttons.button-basic wire:click="inviteUsers" class="w-full justify-center">
                                        <i class="far fa-paper-plane" aria-hidden="true"></i>
                                        {{ __('app.calls_invite_selected') }}
                                    </x-ui.buttons.button-basic>
                                </div>
                            @endif
                        </div>
                    </x-slot>
                </x-ui.dropdown.anchor-dropdown>
            @endif

            <button
                type="button"
                x-on:click="if (panelOpen && panelTab === 'chat') { panelOpen = false } else { panelTab = 'chat'; panelOpen = true }"
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-sm text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                :class="panelOpen && panelTab === 'chat' ? 'bg-white/10 text-white' : ''"
                title="{{ __('app.calls_chat') }}"
                aria-label="{{ __('app.calls_chat') }}"
            >
                <i class="far fa-message-dots" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                x-on:click="if (panelOpen && panelTab === 'participants') { panelOpen = false } else { panelTab = 'participants'; panelOpen = true }"
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-sm text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                :class="panelOpen && panelTab === 'participants' ? 'bg-white/10 text-white' : ''"
                title="{{ __('app.calls_participants') }}"
                aria-label="{{ __('app.calls_participants') }}"
            >
                <i class="far fa-users" aria-hidden="true"></i>
            </button>
        </div>

        {{-- Buehne: Video-Grid + Teilnehmer-Panel --}}
        <div class="relative flex min-h-0 flex-1">
            <div
                x-ref="selfPreviewStage"
                class="relative min-h-0 flex-1 overflow-hidden p-2 sm:p-3"
                data-rt-self-preview-stage
            >
                {{-- Die Bestätigung ist versioniert und wird aktiv per Klick
                     abgegeben. Bis dahin wird kein LiveKit-Token verbunden. --}}
                <div
                    x-cloak
                    x-show.important="recordingRequiresAcknowledgement"
                    class="absolute inset-0 z-50 flex items-center justify-center bg-[#0b0e13]/95 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="call-recording-notice-title"
                >
                    <div class="w-full max-w-lg rounded-3xl bg-rt-surface p-6 text-left shadow-2xl ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 sm:p-8">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-red-500/10 text-red-500">
                            <i class="far fa-record-vinyl text-lg" aria-hidden="true"></i>
                        </span>
                        <h2 id="call-recording-notice-title" class="mt-5 text-xl font-extrabold tracking-[-0.03em] text-rt-text dark:text-rt-dark-text">
                            {{ __('app.calls_recording_notice_title') }}
                        </h2>
                        <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.calls_recording_notice_body') }}
                        </p>
                        <p class="mt-3 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.calls_recording_notice_retention') }}
                            <span class="font-semibold" x-text="recordingPolicyVersion"></span>
                        </p>
                        <p
                            x-show="recordingAcknowledgementError"
                            x-text="recordingAcknowledgementError"
                            class="mt-3 rounded-xl bg-rose-500/10 px-3 py-2 text-xs font-semibold text-rose-600 dark:text-rose-400"
                            role="alert"
                        ></p>
                        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <button
                                type="button"
                                x-on:click="$wire.leaveCall()"
                                class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 text-sm font-bold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-text"
                            >
                                {{ __('app.calls_recording_decline') }}
                            </button>
                            <button
                                type="button"
                                x-on:click="acknowledgeRecording()"
                                :disabled="recordingAcknowledgementSubmitting"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-5 text-sm font-bold text-white transition hover:bg-rt-red-dark disabled:cursor-wait disabled:opacity-60"
                            >
                                <i class="far fa-check" aria-hidden="true"></i>
                                {{ __('app.calls_recording_accept') }}
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Autoplay-Sperre: ohne Nutzergeste bleibt der Anruf stumm. --}}
                <button
                    x-cloak
                    x-show.important="audioBlocked"
                    type="button"
                    x-on:click="unlockAudio()"
                    class="absolute inset-x-0 top-2 z-20 mx-auto flex w-max items-center gap-2 rounded-full bg-rt-accent px-4 py-2 text-sm font-bold text-white shadow-rt-lg"
                >
                    <i class="far fa-volume-up" aria-hidden="true"></i>
                    {{ __('app.calls_enable_audio') }}
                </button>

                {{-- Geraete-Aktivierung per Nutzergeste: Der automatische
                     Einschaltversuch nach dem Beitritt laeuft ausserhalb jeder
                     Geste — iOS Safari lehnt getUserMedia dort grundsaetzlich
                     ab. Dieser Klick ist die Geste. --}}
                <button
                    x-cloak
                    x-show.important="deviceSetupNeeded"
                    type="button"
                    x-on:click="retryDevices()"
                    class="absolute inset-x-0 z-20 mx-auto flex w-max items-center gap-2 rounded-full bg-emerald-500 px-4 py-2 text-sm font-bold text-white shadow-rt-lg transition-colors hover:bg-emerald-600"
                    :class="audioBlocked ? 'top-14' : 'top-2'"
                >
                    <i class="far fa-microphone" aria-hidden="true"></i>
                    {{ data_get($room->settings, 'video', true) ? __('app.calls_enable_devices') : __('app.calls_enable_microphone') }}
                </button>

                {{-- Anrufsignal: deckt Verbindungsaufbau UND das Warten auf
                     Annahme ab (nur solange KEIN Fehler vorliegt). Die
                     auslaufenden Wellen sind das Signal, die Zeile darunter
                     benennt den Zustand: "wird angerufen" solange nur die
                     Einladung raus ist, "klingelt" sobald ein Geraet der
                     Gegenseite das Laeuten bestaetigt hat. --}}
                <div
                    x-show.important="! failed && (! connected || outgoingRinging)"
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-6 px-6 text-center"
                >
                    <div class="rt-call-signal" :class="ringsThere ? 'rt-call-signal--live' : ''">
                        <span class="rt-call-signal__wave" aria-hidden="true"></span>
                        <span class="rt-call-signal__wave" aria-hidden="true"></span>
                        <span class="rt-call-signal__wave" aria-hidden="true"></span>

                        <span class="rt-call-signal__core">
                            <template x-if="waitingAvatar">
                                <img :src="waitingAvatar" alt="" class="h-full w-full rounded-full object-cover">
                            </template>
                            <template x-if="! waitingAvatar">
                                <i class="far fa-phone-volume rt-call-signal__icon" aria-hidden="true"></i>
                            </template>
                        </span>
                    </div>

                    <div class="min-w-0 max-w-sm">
                        <p
                            x-cloak
                            x-show.important="outgoingRinging"
                            class="truncate text-lg font-extrabold tracking-[-0.03em] text-white"
                            x-text="waitingNames"
                        ></p>
                        <p
                            class="mt-1.5 text-[11px] font-bold uppercase tracking-[0.16em] text-white/55"
                            x-text="outgoingRinging ? ringStatusLabel : statusLabel"
                        ></p>
                    </div>
                </div>

                {{-- Fehlerzustand: klar benannt, mit Ausweg — kein endloser
                     Ladekreis mit der Aufschrift "beendet" mehr. --}}
                <div
                    x-cloak
                    x-show.important="failed"
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-4 px-6 text-center"
                >
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-500/15 text-rose-400">
                        <i class="far fa-wifi-slash text-xl" aria-hidden="true"></i>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-white" x-text="statusLabel"></p>
                        <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-white/60">
                            {{ __('app.calls_connection_failed_hint') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            x-on:click="retry()"
                            class="inline-flex h-11 items-center gap-2 rounded-full bg-rt-red px-5 text-sm font-bold text-white transition-colors hover:bg-rt-red-dark"
                        >
                            <i class="far fa-rotate-right" aria-hidden="true"></i>
                            {{ __('app.calls_retry') }}
                        </button>
                        <button
                            type="button"
                            x-on:click="disconnect(); $wire.leaveCall()"
                            class="inline-flex h-11 items-center gap-2 rounded-full bg-white/10 px-5 text-sm font-bold text-white/80 transition-colors hover:bg-white/20"
                        >
                            {{ __('app.calls_back_to_chat') }}
                        </button>
                    </div>
                </div>

                {{-- Kacheln legt calls.js dynamisch an. wire:ignore ist
                     zwingend: ohne das loescht jedes Livewire-Re-Render
                     (z. B. $refresh bei call.answered) alle Video-Kacheln. --}}
                <div
                    wire:ignore
                    x-ref="grid"
                    class="rt-call-grid grid h-full min-h-0 content-center gap-2 sm:gap-3"
                    :style="gridStyle"
                ></div>

                {{-- Die eigene Kamera liegt als frei verschiebbare Vorschau
                     über der Bühne. Wird ihr Mittelpunkt herausgezogen, bleibt
                     an der Austrittskante ein zugänglicher Restore-Button. --}}
                <div
                    wire:ignore
                    x-ref="selfPreview"
                    id="rt-self-preview-{{ $room->uuid }}"
                    x-show.important="selfVideoVisible && ! selfPreviewMinimized"
                    x-cloak
                    x-on:pointerdown="startSelfPreviewDrag($event)"
                    x-bind:data-dragging="selfPreviewDragging.toString()"
                    :style="selfPreviewStyle"
                    class="rt-call-self-preview absolute left-0 top-0 z-30 touch-none cursor-grab overflow-hidden rounded-2xl bg-black/70 shadow-2xl ring-1 ring-white/20 focus-within:ring-2 focus-within:ring-white/70 active:cursor-grabbing"
                    role="group"
                    aria-label="{{ __('app.you') }}"
                    data-rt-self-preview
                >
                    <button
                        type="button"
                        x-ref="selfPreviewMinimize"
                        x-on:pointerdown.stop
                        x-on:click.stop="minimizeSelfPreview()"
                        class="absolute right-1 top-1 z-20 inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-xl bg-black/60 text-sm text-white/85 backdrop-blur transition-colors hover:bg-black/80 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                        title="{{ __('app.calls_minimize_self_video') }}"
                        aria-label="{{ __('app.calls_minimize_self_video') }}"
                        aria-controls="rt-self-preview-{{ $room->uuid }}"
                        data-rt-self-preview-minimize
                    >
                        <i class="far fa-compress" aria-hidden="true"></i>
                    </button>
                </div>

                <button
                    type="button"
                    x-ref="selfPreviewRestore"
                    x-show.important="selfVideoVisible && selfPreviewMinimized"
                    x-cloak
                    x-on:click="restoreSelfPreview()"
                    :style="selfPreviewRestoreStyle"
                    class="absolute left-0 top-0 z-30 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-rt-red text-sm text-white shadow-2xl ring-1 ring-white/30 transition-colors hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                    title="{{ __('app.calls_restore_self_video') }}"
                    aria-label="{{ __('app.calls_restore_self_video') }}"
                    aria-controls="rt-self-preview-{{ $room->uuid }}"
                    data-rt-self-preview-restore
                >
                    <i class="far fa-expand" aria-hidden="true"></i>
                </button>
            </div>

            {{-- Gemeinsames Seitenpanel: Teilnehmer oder persistenter Call-Chat. --}}
            <aside
                x-cloak
                x-show="panelOpen"
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="translate-x-4 opacity-0"
                x-transition:enter-end="translate-x-0 opacity-100"
                class="absolute inset-y-2 right-2 z-20 flex w-[min(24rem,calc(100vw-1rem))] min-h-0 flex-col overflow-hidden rounded-[1.1rem] bg-rt-surface shadow-rt-lg dark:bg-rt-dark-surface sm:static sm:inset-auto sm:m-3 sm:ml-0 sm:w-80"
            >
                <div class="grid shrink-0 grid-cols-2 gap-1 border-b border-rt-border/60 p-2 dark:border-rt-dark-border/60" role="tablist" aria-label="{{ __('app.calls_panel') }}">
                    <button
                        type="button"
                        x-on:click="panelTab = 'participants'"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-2 text-xs font-bold transition"
                        :class="panelTab === 'participants' ? 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent' : 'text-rt-muted hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted'"
                        role="tab"
                        :aria-selected="(panelTab === 'participants').toString()"
                    >
                        <i class="far fa-users" aria-hidden="true"></i>
                        {{ __('app.calls_participants') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="panelTab = 'chat'"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-2 text-xs font-bold transition"
                        :class="panelTab === 'chat' ? 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent' : 'text-rt-muted hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted'"
                        role="tab"
                        :aria-selected="(panelTab === 'chat').toString()"
                    >
                        <i class="far fa-message-dots" aria-hidden="true"></i>
                        {{ __('app.calls_chat') }}
                    </button>
                </div>

                <div x-show="panelTab === 'participants'" class="scroll-container min-h-0 flex-1 overflow-y-auto p-3" role="tabpanel">
                    <p class="px-1 pb-2 text-[10px] font-bold uppercase tracking-[0.1em] text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.calls_participants') }} ({{ $room->participants->count() }})
                    </p>

                    <ul class="space-y-1">
                    @foreach ($room->participants as $participant)
                        <li
                            class="flex items-center gap-2.5 rounded-xl p-2 transition-colors hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted"
                            wire:key="participant-{{ $participant->id }}"
                        >
                            <x-chat.avatar
                                :src="$participant->user?->profile_photo_url"
                                :name="$participant->user?->name ?? $participant->guest_name ?? '?'"
                                size="sm"
                            />

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13px] font-bold text-rt-text dark:text-rt-dark-text">
                                    {{ $participant->user?->name ?? $participant->guest_name }}
                                    @if ((int) $participant->user_id === auth()->id())
                                        <span class="text-rt-muted dark:text-rt-dark-muted">({{ __('app.you') }})</span>
                                    @endif
                                </span>
                                <span class="mt-0.5 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-1.5 py-0.5',
                                        'bg-rt-red/10 text-rt-red dark:text-rt-dark-accent' => $participant->role === 'host',
                                        'bg-amber-500/10 text-amber-600 dark:text-amber-400' => $participant->role === 'moderator',
                                        'bg-sky-500/10 text-sky-600 dark:text-sky-400' => $participant->role === 'speaker',
                                        'bg-slate-500/10 text-slate-500 dark:text-slate-400' => $participant->role === 'viewer',
                                    ])>
                                        {{ __('app.calls_role_'.$participant->role) }}
                                    </span>
                                    @if ($participant->isConnected())
                                        <span class="inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500" title="online"></span>
                                    @endif
                                </span>
                            </span>

                            @if ($canModerate && $participant->role !== 'host' && (int) $participant->user_id !== auth()->id())
                                <x-ui.dropdown.anchor-dropdown align="right" width="56" :offset="6" class="shrink-0">
                                    <x-slot name="trigger">
                                        <x-ui.dropdown.action-trigger
                                            :aria-label="__('app.calls_moderate')"
                                            class="h-8 w-8 rounded-lg px-0"
                                        />
                                    </x-slot>
                                    <x-slot name="content">
                                        <div class="space-y-0.5 p-1.5">
                                            <x-ui.dropdown.dropdown-link wire:click="muteParticipant({{ $participant->id }})">
                                                <span class="rt-chat-option-icon"><i class="far fa-microphone-slash" aria-hidden="true"></i></span>
                                                <span>{{ __('app.calls_mute') }}</span>
                                            </x-ui.dropdown.dropdown-link>
                                            <x-ui.dropdown.dropdown-link wire:click="toggleRole({{ $participant->id }})">
                                                <span class="rt-chat-option-icon"><i class="far {{ $participant->role === 'viewer' ? 'fa-microphone' : 'fa-headphones' }}" aria-hidden="true"></i></span>
                                                <span>{{ $participant->role === 'viewer' ? __('app.calls_role_speaker') : __('app.calls_role_viewer') }}</span>
                                            </x-ui.dropdown.dropdown-link>
                                            <div class="rt-chat-option-divider my-1" role="separator"></div>
                                            <x-ui.dropdown.dropdown-link wire:click="removeParticipant({{ $participant->id }})" data-rt-tone="danger">
                                                <span class="rt-chat-option-icon"><i class="far fa-user-slash" aria-hidden="true"></i></span>
                                                <span>{{ __('app.calls_remove_participant') }}</span>
                                            </x-ui.dropdown.dropdown-link>
                                        </div>
                                    </x-slot>
                                </x-ui.dropdown.anchor-dropdown>
                            @endif
                        </li>
                    @endforeach
                    </ul>
                </div>

                <div x-show="panelTab === 'chat'" class="min-h-0 flex-1" role="tabpanel">
                    @if ($room->callChat)
                        <livewire:calls.call-chat :room="$room" :key="'active-call-chat-'.$room->id" />
                    @endif
                </div>
            </aside>
        </div>

        {{-- Steuerleiste --}}
        <div class="shrink-0 px-3 pb-3 pt-2 sm:px-5 sm:pb-5">
        <div class="mx-auto flex w-max max-w-full items-center justify-center gap-1.5 rounded-2xl bg-white/[0.07] p-1.5 shadow-2xl ring-1 ring-white/10 backdrop-blur-xl sm:gap-2 sm:rounded-[1.35rem] sm:p-2">
            <button
                type="button"
                x-on:click="toggleMic()"
                :disabled="! canPublish"
                class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-base transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-40 sm:h-12 sm:w-12 sm:rounded-2xl"
                :class="micOn ? 'bg-slate-600 text-white hover:bg-slate-500' : 'bg-rose-600 text-white ring-2 ring-rose-400/60 hover:bg-rose-500'"
                :title="micOn ? @js(__('app.calls_mute')) : @js(__('app.calls_unmute'))"
                :aria-label="micOn ? @js(__('app.calls_mute')) : @js(__('app.calls_unmute'))"
            >
                <i class="far" :class="micOn ? 'fa-microphone' : 'fa-microphone-slash'" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                x-on:click="toggleCamera()"
                :disabled="! canPublish"
                class="inline-flex h-11 w-11 items-center justify-center rounded-xl text-base transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-40 sm:h-12 sm:w-12 sm:rounded-2xl"
                :class="cameraOn ? 'bg-slate-600 text-white hover:bg-slate-500' : 'bg-rose-600 text-white ring-2 ring-rose-400/60 hover:bg-rose-500'"
                :title="cameraOn ? @js(__('app.calls_camera_off')) : @js(__('app.calls_camera_on'))"
                :aria-label="cameraOn ? @js(__('app.calls_camera_off')) : @js(__('app.calls_camera_on'))"
            >
                <i class="far" :class="cameraOn ? 'fa-video' : 'fa-video-slash'" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                x-on:click="toggleScreenShare()"
                :disabled="! canPublish"
                class="hidden h-12 w-12 items-center justify-center rounded-2xl text-base transition-all active:scale-95 disabled:cursor-not-allowed disabled:opacity-40 sm:inline-flex"
                :class="screenSharing ? 'bg-emerald-500 text-white ring-2 ring-emerald-300/60 hover:bg-emerald-600' : 'bg-slate-600 text-white hover:bg-slate-500'"
                :title="screenSharing ? @js(__('app.calls_screen_share_stop')) : @js(__('app.calls_screen_share'))"
                :aria-label="screenSharing ? @js(__('app.calls_screen_share_stop')) : @js(__('app.calls_screen_share'))"
            >
                <i class="far fa-desktop" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                x-on:click="disconnect(); $wire.leaveCall()"
                class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 text-sm font-bold text-white shadow-rt-glow transition-all hover:bg-rt-red-dark active:scale-[0.97] sm:h-12 sm:rounded-2xl sm:px-6"
                title="{{ __('app.calls_leave') }}"
            >
                <i class="far fa-phone-slash" aria-hidden="true"></i>
                <span class="hidden sm:inline">{{ __('app.calls_leave') }}</span>
            </button>

            @if ($canModerate)
                <button
                    type="button"
                    x-on:click="disconnect(); $wire.endCall()"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white/10 px-3 text-sm font-bold text-white/80 transition-all hover:bg-white/20 hover:text-white active:scale-[0.97] sm:h-12 sm:rounded-2xl sm:px-4"
                    title="{{ __('app.calls_ended') }}"
                >
                    <i class="far fa-power-off" aria-hidden="true"></i>
                    <span class="hidden lg:inline">{{ __('app.calls_ended') }}</span>
                </button>
            @endif
        </div>
        </div>
    </div>

    {{-- Muss INNERHALB des einzigen Wurzelelements liegen: ausserhalb zaehlt
         Livewire es als zweites Wurzelelement und wirft (bei APP_DEBUG=true)
         MultipleRootElementsDetectedException. Die Position im Dokument bleibt
         vor @vite(app.js) im Layout, damit calls.js seinen alpine:init-Listener
         noch vor Livewire.start() registriert. --}}
    <div wire:ignore>
        @vite(['resources/js/calls.js'])
    </div>
</div>
