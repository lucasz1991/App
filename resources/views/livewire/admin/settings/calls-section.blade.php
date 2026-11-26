{{-- Einstellungen > Superadmin > Anrufe. Die Wurzel wiederholt die Abstände des
     Akkordeon-Inhalts (space-y-3 sm:space-y-4), damit beide Karten wie bisher stehen. --}}
<div class="space-y-3 sm:space-y-4">
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-autosave-scope
        data-superadmin-call-settings
    >
        <x-ui.autosave-status event="call-settings-saved" target="saveCalls" dirty-target="calls" />

        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'ring_timeout' => ['label' => __('app.calls_setting_ring_timeout'), 'hint' => __('app.calls_setting_ring_timeout_hint'), 'unit' => __('app.unit_seconds')],
                    'max_participants' => ['label' => __('app.calls_setting_max_participants'), 'hint' => __('app.calls_setting_max_participants_hint'), 'unit' => ''],
                    'token_ttl' => ['label' => __('app.calls_setting_token_ttl'), 'hint' => __('app.calls_setting_token_ttl_hint'), 'unit' => __('app.unit_seconds')],
                    'empty_timeout' => ['label' => __('app.calls_setting_empty_timeout'), 'hint' => __('app.calls_setting_empty_timeout_hint'), 'unit' => __('app.unit_seconds')],
                    'http_connect_timeout' => ['label' => __('app.calls_setting_connect_timeout'), 'hint' => __('app.calls_setting_connect_timeout_hint'), 'unit' => __('app.unit_seconds')],
                    'http_timeout' => ['label' => __('app.calls_setting_http_timeout'), 'hint' => __('app.calls_setting_http_timeout_hint'), 'unit' => __('app.unit_seconds')],
                ] as $field => $meta)
                    @php [$configPath, $min, $max] = \App\Support\Calls\CallSettings::FIELDS[$field]; @endphp
                    <div class="min-w-0 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-4">
                        <x-ui.forms.label :for="'calls-'.$field" :value="$meta['label']" />
                        <x-ui.forms.number-input
                            :id="'calls-'.$field"
                            :min="$min"
                            :max="$max"
                            step="1"
                            :decimals="0"
                            separator="."
                            :unit="$meta['unit'] ?: null"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="calls.{{ $field }}"
                        />
                        <x-ui.forms.input-error :for="'calls.'.$field" class="mt-1" />
                        <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $meta['hint'] }}
                            <span class="whitespace-nowrap opacity-70">({{ $min }}–{{ $max }})</span>
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Infrastruktur: bewusst nur lesbar. --}}
    @php $infra = \App\Support\Calls\CallSettings::infrastructure(); @endphp
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60 sm:p-6"
    >
        <div class="flex min-w-0 items-start gap-3">
            <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted sm:flex">
                <i class="fad fa-lock fa-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                    {{ __('app.calls_infrastructure') }}
                </h2>
                <p class="mt-1 break-words text-sm text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.calls_infrastructure_hint') }}
                </p>
            </div>
        </div>

        <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
            @foreach ([
                'ws_url' => __('app.calls_infra_ws_url'),
                'url' => __('app.calls_infra_url'),
                'api_key' => __('app.calls_infra_api_key'),
                'api_secret' => __('app.calls_infra_api_secret'),
                'turn_mode' => __('app.calls_infra_turn_mode'),
            ] as $key => $label)
                <div class="flex flex-col gap-1 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <dt class="text-xs font-semibold text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt>
                    <dd class="min-w-0 truncate font-mono text-xs text-rt-text dark:text-rt-dark-text">
                        {{ $infra[$key] !== '' ? $infra[$key] : '—' }}
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
