{{-- Superadmin-only routing between the private speech server and external models. --}}
@php
    $speechModes = [
        'local_with_external_fallback' => __('app.assistant_speech_mode_local_fallback'),
        'local_only' => __('app.assistant_speech_mode_local_only'),
        'external_only' => __('app.assistant_speech_mode_external_only'),
    ];
    $localSpeech = (array) data_get($assistantSpeechStatus, 'providers.local', []);
    $externalSpeech = (array) data_get($assistantSpeechStatus, 'providers.external', []);
    $speechStateLabel = static function (array $provider): string {
        if (($provider['state'] ?? '') === 'not_checked') {
            return __('Noch nicht geprüft');
        }
        $stt = (bool) ($provider['stt_available'] ?? false);
        $tts = (bool) ($provider['tts_available'] ?? false);

        return $stt && $tts
            ? __('app.assistant_speech_ready')
            : (($stt || $tts)
                ? __('app.assistant_speech_partial')
                : __('app.assistant_speech_unavailable'));
    };
@endphp

<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5"
    data-superadmin-assistant-runtime
>
    <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
        <div class="rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                        {{ __('app.assistant_speech_routing') }}
                    </p>
                    <p class="mt-0.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.assistant_speech_routing_hint') }}
                    </p>
                </div>

                <div class="w-full min-w-0 lg:w-80">
                    <x-ui.forms.select
                        id="assistant-speech-routing"
                        wire:model.live="assistantSpeechRouting"
                        :aria-label="__('app.assistant_speech_routing')"
                    >
                        @foreach ($speechModes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.forms.select>
                    <x-input-error for="assistantSpeechRouting" class="mt-1.5" />
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ([
                    ['label' => __('app.assistant_speech_local_status'), 'provider' => $localSpeech, 'icon' => 'fad fa-server', 'external' => false],
                    ['label' => __('app.assistant_speech_external_status'), 'provider' => $externalSpeech, 'icon' => 'fad fa-cloud', 'external' => true],
                ] as $speechProvider)
                    @php
                        $providerReady = (bool) ($speechProvider['provider']['stt_available'] ?? false)
                            || (bool) ($speechProvider['provider']['tts_available'] ?? false);
                        $providerStateLabel = $speechProvider['external']
                            ? ($providerReady ? __('app.assistant_speech_configured') : __('app.assistant_speech_not_configured'))
                            : $speechStateLabel($speechProvider['provider']);
                        $providerUnverified = ! $speechProvider['external'] && ($speechProvider['provider']['state'] ?? '') === 'not_checked';
                    @endphp
                    <div class="rounded-xl bg-rt-surface p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                        <div class="flex items-center justify-between gap-3">
                            <span class="flex min-w-0 items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                <i class="{{ $speechProvider['icon'] }} shrink-0 text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                                <span class="truncate">{{ $speechProvider['label'] }}</span>
                            </span>
                            <span @class([
                                'shrink-0 rounded-full px-2.5 py-1 text-[0.7rem] font-semibold ring-1',
                                'bg-emerald-50 text-emerald-700 ring-emerald-200/80 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25' => $providerReady,
                                'bg-rt-surface-muted text-rt-muted ring-rt-border/70 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/70' => ! $providerReady,
                            ])>{{ $providerStateLabel }}</span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                            @if ($providerUnverified)
                                <span>{{ __('Status über „Aktualisieren“ prüfen. Beim Öffnen der Einstellungen wurde keine Verbindung aufgebaut.') }}</span>
                            @else
                            <span class="rounded-md bg-rt-surface-muted px-2 py-1 dark:bg-rt-dark-surface-muted">
                                {{ __('app.assistant_speech_stt') }}: {{ ($speechProvider['provider']['stt_available'] ?? false) ? ($speechProvider['external'] ? __('app.assistant_speech_configured') : __('app.assistant_speech_ready')) : ($speechProvider['external'] ? __('app.assistant_speech_not_configured') : __('app.assistant_speech_unavailable')) }}
                            </span>
                            <span class="rounded-md bg-rt-surface-muted px-2 py-1 dark:bg-rt-dark-surface-muted">
                                {{ __('app.assistant_speech_tts') }}: {{ ($speechProvider['provider']['tts_available'] ?? false) ? ($speechProvider['external'] ? __('app.assistant_speech_configured') : __('app.assistant_speech_ready')) : ($speechProvider['external'] ? __('app.assistant_speech_not_configured') : __('app.assistant_speech_unavailable')) }}
                            </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-col gap-3 rounded-xl bg-amber-50/80 p-3.5 text-amber-900 ring-1 ring-amber-200/80 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs leading-5">
                    {{ __('app.assistant_speech_privacy_fallback') }}
                </p>
                <button
                    type="button"
                    class="rt-ui-button rt-ui-button-secondary shrink-0"
                    wire:click="refreshAssistantSpeechStatus"
                    wire:loading.attr="disabled"
                    wire:target="refreshAssistantSpeechStatus"
                >
                    <i class="fad fa-sync" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="refreshAssistantSpeechStatus">{{ __('app.refresh') }}</span>
                    <span wire:loading wire:target="refreshAssistantSpeechStatus">{{ __('app.loading') }}</span>
                </button>
            </div>
        </div>
    </div>
</section>

@include('livewire.admin.settings.partials.openrouter')
