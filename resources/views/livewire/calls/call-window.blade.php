{{-- Vollbild BEWUSST ueber Topbar und Sidebar (fixed + z-[200]): waehrend
     eines Anrufs ist die App-Navigation Ablenkung, kein Werkzeug. Verlassen
     fuehrt ohnehin ueber Auflegen zurueck in den Chat. --}}
<div
    class="fixed inset-0 z-[200] overflow-hidden bg-[#0b0e13]"
    x-data="callRoom({
        roomUuid: @js($room->uuid),
        tokenUrl: @js(route('calls.token', $room)),
        csrf: @js(csrf_token()),
        selfIdentity: @js(\App\Services\Calls\LiveKitService::identityFor(auth()->user())),
        canPublish: @js($me?->canPublish() ?? false),
        startWithVideo: @js((bool) data_get($room->settings, 'video', true)),
        labels: {
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
        },
    })"
    x-on:beforeunload.window="disconnect()"
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

            <button
                type="button"
                x-on:click="panelOpen = ! panelOpen"
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-sm text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                :class="panelOpen ? 'bg-white/10 text-white' : ''"
                title="{{ __('app.calls_participants') }}"
                aria-label="{{ __('app.calls_participants') }}"
            >
                <i class="far fa-users" aria-hidden="true"></i>
            </button>
        </div>

        {{-- Buehne: Video-Grid + Teilnehmer-Panel --}}
        <div class="relative flex min-h-0 flex-1">
            <div class="relative min-h-0 flex-1 p-2 sm:p-3">
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

                {{-- Verbindungsaufbau (nur solange KEIN Fehler vorliegt) --}}
                <div
                    x-show.important="! connected && ! failed"
                    class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 text-white/70"
                >
                    <span class="inline-flex h-12 w-12 animate-spin items-center justify-center rounded-full border-2 border-white/20 border-t-white/80"></span>
                    <p class="text-sm font-bold" x-text="statusLabel"></p>
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
            </div>

            {{-- Teilnehmerliste (Livewire-Wahrheit aus der DB, inkl. Moderation) --}}
            <aside
                x-cloak
                x-show="panelOpen"
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="translate-x-4 opacity-0"
                x-transition:enter-end="translate-x-0 opacity-100"
                class="absolute inset-y-2 right-2 z-20 w-72 overflow-y-auto rounded-[1.1rem] bg-rt-surface p-3 shadow-rt-lg dark:bg-rt-dark-surface sm:static sm:inset-auto sm:m-3 sm:ml-0 sm:w-80"
            >
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
            </aside>
        </div>

        {{-- Steuerleiste --}}
        <div class="flex shrink-0 items-center justify-center gap-2 px-4 pb-4 pt-2 sm:gap-3 sm:pb-5">
            <button
                type="button"
                x-on:click="toggleMic()"
                :disabled="! canPublish"
                class="inline-flex h-12 w-12 items-center justify-center rounded-full text-base transition-all disabled:cursor-not-allowed disabled:opacity-40"
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
                class="inline-flex h-12 w-12 items-center justify-center rounded-full text-base transition-all disabled:cursor-not-allowed disabled:opacity-40"
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
                class="hidden h-12 w-12 items-center justify-center rounded-full text-base transition-all disabled:cursor-not-allowed disabled:opacity-40 sm:inline-flex"
                :class="screenSharing ? 'bg-emerald-500 text-white ring-2 ring-emerald-300/60 hover:bg-emerald-600' : 'bg-slate-600 text-white hover:bg-slate-500'"
                :title="screenSharing ? @js(__('app.calls_screen_share_stop')) : @js(__('app.calls_screen_share'))"
                :aria-label="screenSharing ? @js(__('app.calls_screen_share_stop')) : @js(__('app.calls_screen_share'))"
            >
                <i class="far fa-desktop" aria-hidden="true"></i>
            </button>

            <button
                type="button"
                x-on:click="disconnect(); $wire.leaveCall()"
                class="inline-flex h-12 items-center justify-center gap-2 rounded-full bg-rt-red px-6 text-sm font-bold text-white shadow-rt-glow transition-colors hover:bg-rt-red-dark"
                title="{{ __('app.calls_leave') }}"
            >
                <i class="far fa-phone-slash" aria-hidden="true"></i>
                <span class="hidden sm:inline">{{ __('app.calls_leave') }}</span>
            </button>

            @if ($canModerate)
                <button
                    type="button"
                    x-on:click="disconnect(); $wire.endCall()"
                    class="inline-flex h-12 items-center justify-center gap-2 rounded-full bg-white/10 px-4 text-sm font-bold text-white/80 transition-colors hover:bg-white/20 hover:text-white"
                    title="{{ __('app.calls_ended') }}"
                >
                    <i class="far fa-power-off" aria-hidden="true"></i>
                    <span class="hidden lg:inline">{{ __('app.calls_ended') }}</span>
                </button>
            @endif
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
