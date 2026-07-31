{{-- OpenRouter: Zugang, Modellprofile und Laufzeitgrenzen.

     Der Umfang der erfassten Werte entspricht bewusst der AiUserFactory,
     damit eine spätere KI-Anbindung dieselbe Konfiguration vorfindet. In
     dieser Ausbaustufe werden die Werte ausschließlich erfasst. --}}

@php
    $openRouterModelFields = [
        'text_model' => ['label' => __('app.openrouter_text_model'), 'hint' => __('app.openrouter_text_model_hint'), 'icon' => 'fad fa-align-left'],
        'data_model' => ['label' => __('app.openrouter_data_model'), 'hint' => __('app.openrouter_data_model_hint'), 'icon' => 'fad fa-chart-bar'],
        'image_generation_model' => ['label' => __('app.openrouter_image_generation_model'), 'hint' => __('app.openrouter_image_generation_model_hint'), 'icon' => 'fad fa-image'],
        'image_understanding_model' => ['label' => __('app.openrouter_image_understanding_model'), 'hint' => __('app.openrouter_image_understanding_model_hint'), 'icon' => 'fad fa-eye'],
        'speech_to_text_model' => ['label' => __('app.openrouter_speech_to_text_model'), 'hint' => __('app.openrouter_speech_to_text_model_hint'), 'icon' => 'fad fa-wave-square'],
        'text_to_speech_model' => ['label' => __('app.openrouter_text_to_speech_model'), 'hint' => __('app.openrouter_text_to_speech_model_hint'), 'icon' => 'fad fa-volume-up'],
    ];

    $openRouterRuntimeFields = \App\Support\Ai\OpenRouterSettings::RUNTIME_FIELDS;
    $openRouterModelDefaults = \App\Support\Ai\OpenRouterSettings::MODEL_FIELDS;
@endphp

<section
    class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5"
    data-autosave-scope
>
    <x-ui.autosave-status
        event="openrouter-settings-saved"
        target="saveOpenRouter"
        dirty-target="openRouter"
    />

    <div class="space-y-4 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:space-y-5 sm:p-6" data-rt-glow>
        {{-- Status: ist eine nutzbare Verbindung hinterlegt? --}}
        @php $openRouterConfigured = filled($openRouter['api_url'] ?? '') && filled($openRouter['api_key'] ?? ''); @endphp
        <div class="flex flex-wrap items-center gap-2">
            <span
                @class([
                    'inline-flex items-center gap-2 rounded-lg px-2.5 py-1 text-xs font-semibold ring-1',
                    'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25' => $openRouterConfigured,
                    'bg-rt-surface-muted text-rt-muted ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60' => ! $openRouterConfigured,
                ])
            >
                <i class="{{ $openRouterConfigured ? 'fad fa-check-circle' : 'fad fa-circle' }}" aria-hidden="true"></i>
                {{ $openRouterConfigured ? __('app.openrouter_state_configured') : __('app.openrouter_state_incomplete') }}
            </span>
            <span class="inline-flex items-center gap-2 rounded-lg bg-rt-surface-muted px-2.5 py-1 font-mono text-xs text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                services · openrouter
            </span>
        </div>

        {{-- API-Verbindung --}}
        <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-5">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                <i class="fad fa-plug text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                {{ __('app.openrouter_connection') }}
            </h3>
            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.openrouter_connection_hint') }}
            </p>

            <div class="mt-4 grid min-w-0 gap-x-5 gap-y-4 lg:grid-cols-2">
                <div class="min-w-0">
                    <x-ui.forms.label for="openrouter-api-url" :value="__('app.openrouter_api_url')" />
                    <x-ui.forms.input
                        id="openrouter-api-url"
                        type="url"
                        inputmode="url"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://openrouter.ai/api/v1/chat/completions"
                        wire:model="openRouter.api_url"
                        class="mt-1.5"
                    />
                    <x-input-error for="openRouter.api_url" class="mt-1.5" />
                </div>

                <div class="min-w-0">
                    <x-ui.forms.label for="openrouter-api-key" :value="__('app.openrouter_api_key')" />
                    <x-ui.forms.input
                        id="openrouter-api-key"
                        type="password"
                        autocomplete="new-password"
                        spellcheck="false"
                        placeholder="sk-or-v1-…"
                        wire:model="openRouter.api_key"
                        class="mt-1.5"
                    />
                    <x-input-error for="openRouter.api_key" class="mt-1.5" />
                    <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.openrouter_api_key_hint') }}
                    </p>
                </div>

                <div class="min-w-0">
                    <x-ui.forms.label for="openrouter-referer-url" :value="__('app.openrouter_referer_url')" />
                    <x-ui.forms.input
                        id="openrouter-referer-url"
                        type="url"
                        inputmode="url"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="config('app.url')"
                        wire:model="openRouter.referer_url"
                        class="mt-1.5"
                    />
                    <x-input-error for="openRouter.referer_url" class="mt-1.5" />
                    <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.openrouter_referer_url_hint') }}
                    </p>
                </div>

                <div class="min-w-0">
                    <x-ui.forms.label for="openrouter-model-title" :value="__('app.openrouter_model_title')" />
                    <x-ui.forms.input
                        id="openrouter-model-title"
                        type="text"
                        autocomplete="off"
                        placeholder="RailTime"
                        wire:model="openRouter.model_title"
                        class="mt-1.5"
                    />
                    <x-input-error for="openRouter.model_title" class="mt-1.5" />
                    <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.openrouter_model_title_hint') }}
                    </p>
                </div>
            </div>
        </div>

        {{-- Modellprofile (AI Tools) --}}
        <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                    <i class="fad fa-microchip text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                    {{ __('app.openrouter_models') }}
                </h3>
                <p class="text-xs text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.openrouter_models_hint') }}
                </p>
            </div>

            <div class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($openRouterModelFields as $field => $meta)
                    <div class="flex min-w-0 flex-col rounded-xl bg-rt-surface p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-4">
                        <label
                            for="openrouter-{{ \Illuminate\Support\Str::slug($field) }}"
                            class="flex items-center gap-2 text-sm font-medium text-rt-text dark:text-rt-dark-text"
                        >
                            <i class="{{ $meta['icon'] }} text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                            {{ $meta['label'] }}
                        </label>
                        <x-ui.forms.input
                            :id="'openrouter-'.\Illuminate\Support\Str::slug($field)"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$openRouterModelDefaults[$field]"
                            wire:model="openRouter.{{ $field }}"
                            class="mt-1.5 font-mono text-sm"
                        />
                        <x-input-error :for="'openRouter.'.$field" class="mt-1.5" />
                        <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $meta['hint'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Laufzeit --}}
        <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-5">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                <i class="fad fa-tachometer-alt text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                {{ __('app.openrouter_runtime') }}
            </h3>
            <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.openrouter_runtime_hint') }}
            </p>

            <div class="mt-4 grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    'timeout' => ['label' => __('app.openrouter_timeout'), 'hint' => __('app.openrouter_timeout_hint'), 'unit' => __('app.unit_seconds'), 'decimals' => 0, 'step' => 1],
                    'temperature' => ['label' => __('app.openrouter_temperature'), 'hint' => __('app.openrouter_temperature_hint'), 'unit' => null, 'decimals' => 2, 'step' => 0.1],
                    'max_completion_tokens' => ['label' => __('app.openrouter_max_completion_tokens'), 'hint' => __('app.openrouter_max_completion_tokens_hint'), 'unit' => __('app.openrouter_unit_tokens'), 'decimals' => 0, 'step' => 100],
                ] as $field => $meta)
                    @php [, $min, $max] = $openRouterRuntimeFields[$field]; @endphp
                    <div class="min-w-0 rounded-xl bg-rt-surface p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-4">
                        <x-ui.forms.label :for="'openrouter-'.\Illuminate\Support\Str::slug($field)" :value="$meta['label']" />
                        {{-- separator '.': die Werte werden serverseitig mit
                             integer/numeric geprüft, ein Komma wäre dort ungültig. --}}
                        <x-ui.forms.number-input
                            :id="'openrouter-'.\Illuminate\Support\Str::slug($field)"
                            :min="$min"
                            :max="$max"
                            :step="$meta['step']"
                            :decimals="$meta['decimals']"
                            separator="."
                            :unit="$meta['unit']"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="openRouter.{{ $field }}"
                        />
                        <x-input-error :for="'openRouter.'.$field" class="mt-1" />
                        <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $meta['hint'] }}
                            <span class="whitespace-nowrap opacity-70">({{ $min }}–{{ $max }})</span>
                        </p>
                    </div>
                @endforeach

                <div class="flex min-w-0 flex-col justify-between gap-3 rounded-xl bg-rt-surface p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-4">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-rt-text dark:text-rt-dark-text">
                            {{ __('app.openrouter_stream_enabled') }}
                        </p>
                        <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.openrouter_stream_enabled_hint') }}
                        </p>
                    </div>
                    <div class="shrink-0">
                        <x-ui.forms.toggle-button model="openRouter.stream_enabled" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
