@php
    $mode = (string) ($deployment['mode'] ?? 'subdomain');
    $commandsEnabled = (bool) ($runtime['production_commands_enabled'] ?? false);
    $providerIcons = [
        'openuem' => 'fad fa-laptop',
        'meshcentral' => 'fad fa-headset',
        'headwind' => 'fab fa-android',
        'nanomdm' => 'fab fa-apple',
        'identity' => 'fad fa-id-badge',
    ];
    $diagnosticStatusLabels = (array) __('device_management.diagnostic_status');
@endphp

<div
    x-data="{
        openAccordionSection: 'device-deployment',
        toggleAccordionSection(section) {
            this.openAccordionSection = this.openAccordionSection === section ? null : section;
        },
    }"
    x-on:rt-settings-open-section.window="
        if ($event.detail.tab === 'device-management' && $event.detail.section) {
            openAccordionSection = $event.detail.section;
        }
    "
    class="flex min-w-0 flex-col gap-3"
    data-device-management-settings
    data-admin-device-settings-accordion
    wire:ignore.self
>
    <div
        class="flex items-start gap-3 rounded-2xl border-l-4 border-rt-accent bg-rt-accent-soft/40 px-4 py-3 text-sm text-rt-text shadow-rt-sm ring-1 ring-rt-accent/15 dark:bg-rt-dark-accent-soft/25 dark:text-rt-dark-text dark:ring-rt-dark-accent/20"
        data-anim="fade-up"
    >
        <i class="fad fa-shield-check mt-0.5 fa-lg shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
        <div class="min-w-0">
            <p class="font-semibold">
                {{ __('device_management.superadmin_only.title') }}
            </p>
            <p class="mt-0.5 leading-5 text-rt-muted dark:text-rt-dark-muted">
                {{ __('device_management.superadmin_only.text') }}
            </p>
        </div>
    </div>

    <x-admin.settings-accordion-section
        section="device-microsoft"
        :label="__('device_management.entra.label')"
        :description="__('device_management.entra.description')"
        icon="fab fa-microsoft"
    >
        <livewire:admin.microsoft-device-settings />
        <livewire:admin.microsoft-employee-import />
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-deployment"
        :label="__('device_management.deployment.label')"
        :description="__('device_management.deployment.description')"
        icon="fad fa-server"
    >
        <section class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5">
            <div class="space-y-5 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                <div class="grid min-w-0 gap-4 lg:grid-cols-2">
                    <fieldset class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <legend class="px-1 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                            {{ __('device_management.deployment.mode_legend') }}
                        </legend>
                        <div class="mt-2 space-y-2">
                            @foreach ([
                                'subdomain' => [
                                    'label' => __('device_management.deployment.mode_subdomain_label'),
                                    'hint' => __('device_management.deployment.mode_subdomain_hint'),
                                ],
                                'port' => [
                                    'label' => __('device_management.deployment.mode_ports_label'),
                                    'hint' => __('device_management.deployment.mode_ports_hint'),
                                ],
                            ] as $value => $option)
                                <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl bg-rt-surface px-3 py-2.5 ring-1 ring-rt-border/60 transition hover:ring-rt-accent/40 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                                    <input
                                        type="radio"
                                        value="{{ $value }}"
                                        wire:model.live="deployment.mode"
                                        class="mt-1 h-4 w-4 border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface-muted"
                                    >
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $option['label'] }}</span>
                                        <span class="mt-0.5 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $option['hint'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-ui.forms.input-error for="deployment.mode" class="mt-2" />
                    </fieldset>

                    <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <x-ui.forms.label for="device-base-domain" :value="__('device_management.deployment.base_domain')" />
                        <x-ui.forms.input
                            id="device-base-domain"
                            type="text"
                            inputmode="url"
                            autocomplete="off"
                            autocapitalize="none"
                            spellcheck="false"
                            placeholder="rail-time.de"
                            wire:model="deployment.base_domain"
                            aria-describedby="device-base-domain-hint"
                            class="mt-1.5 font-mono"
                        />
                        <x-ui.forms.input-error for="deployment.base_domain" class="mt-1.5" />
                        <p id="device-base-domain-hint" class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('device_management.deployment.base_domain_hint') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-start gap-2.5 rounded-xl bg-amber-50/80 p-3.5 text-xs leading-5 text-amber-950 ring-1 ring-amber-200/80 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25">
                    <i class="fad fa-key mt-0.5 shrink-0" aria-hidden="true"></i>
                    <p>
                        {{ __('device_management.deployment.credentials_reset_warning') }}
                    </p>
                </div>

                <div class="grid min-w-0 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <div class="min-w-0">
                        <x-ui.forms.label for="device-enrollment-ttl" :value="__('device_management.deployment.enrollment_ttl')" />
                        <x-ui.forms.number-input
                            id="device-enrollment-ttl"
                            :min="15"
                            :max="10080"
                            step="15"
                            :decimals="0"
                            separator="."
                            :unit="__('device_management.units.minutes')"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="runtime.enrollment_ttl_minutes"
                        />
                        <x-ui.forms.input-error for="runtime.enrollment_ttl_minutes" class="mt-1" />
                    </div>
                    <div class="min-w-0">
                        <x-ui.forms.label for="device-max-sync-age" :value="__('device_management.deployment.max_sync_age')" />
                        <x-ui.forms.number-input
                            id="device-max-sync-age"
                            :min="1"
                            :max="720"
                            step="1"
                            :decimals="0"
                            separator="."
                            :unit="__('device_management.units.hours')"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="runtime.max_sync_age_hours"
                        />
                        <x-ui.forms.input-error for="runtime.max_sync_age_hours" class="mt-1" />
                    </div>
                    <div class="min-w-0">
                        <x-ui.forms.label for="device-artifact-limit" :value="__('device_management.deployment.artifact_limit')" />
                        <x-ui.forms.number-input
                            id="device-artifact-limit"
                            :min="1024"
                            :max="1048576"
                            step="1024"
                            :decimals="0"
                            separator="."
                            unit="KiB"
                            :nullable="false"
                            class="mt-1 w-full"
                            wire:model="runtime.artifact_max_kilobytes"
                        />
                        <x-ui.forms.input-error for="runtime.artifact_max_kilobytes" class="mt-1" />
                    </div>
                </div>

                <div class="flex flex-col gap-3 border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('device_management.deployment.save_hint') }}
                        </p>
                        <x-ui.forms.input-error for="deployment" class="mt-1.5" />
                    </div>
                    <x-ui.buttons.button-basic mode="primary"
                        type="button"
                        wire:click="saveDeployment"
                        wire:loading.attr="disabled"
                        wire:target="saveDeployment"
                        class="min-h-11 w-full shrink-0 sm:w-auto"
                    >
                        <i class="fad fa-save" aria-hidden="true"></i>
                        <span wire:loading.remove wire:target="saveDeployment">{{ __('device_management.deployment.save') }}</span>
                        <span wire:loading wire:target="saveDeployment">{{ __('app.loading') }}</span>
                    </x-ui.buttons.button-basic>
                </div>
            </div>
        </section>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-providers"
        :label="__('device_management.providers.label')"
        :description="__('device_management.providers.description')"
        icon="fad fa-network-wired"
    >
        <p class="mb-4 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" data-device-provider-setup-help>
            {{ __('device_management.providers.setup_help') }}
        </p>
        <div class="grid min-w-0 gap-4 xl:grid-cols-2">
            @foreach ($providers as $providerKey => $provider)
                @php
                    $result = (array) ($diagnostics[$providerKey] ?? []);
                    $hasResult = $result !== [];
                    $healthy = (bool) ($result['healthy'] ?? false);
                    $diagnosticStatus = (string) ($result['status'] ?? 'probe_failed');
                    $diagnosticStatusLabel = $diagnosticStatusLabels[$diagnosticStatus]
                        ?? __('device_management.diagnostic_status_unknown');
                @endphp
                <section
                    wire:key="device-provider-settings-{{ $providerKey }}"
                    class="min-w-0 overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70"
                    aria-labelledby="device-provider-{{ $providerKey }}-title"
                    data-device-provider-card="{{ $providerKey }}"
                >
                    <div class="flex min-w-0 flex-col gap-4 border-b border-rt-border/60 bg-rt-surface-muted/70 p-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted/55 sm:flex-row sm:items-start sm:justify-between sm:p-5">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                <i class="{{ $providerIcons[$providerKey] ?? 'fad fa-plug' }} fa-lg" aria-hidden="true"></i>
                            </span>
                            <div class="min-w-0">
                                <h3 id="device-provider-{{ $providerKey }}-title" class="break-words text-base font-semibold text-rt-text dark:text-rt-dark-text">
                                    {{ $provider['label'] ?? $providerKey }}
                                </h3>
                                <p class="mt-0.5 font-mono text-xs text-rt-muted dark:text-rt-dark-muted">{{ $providerKey }}</p>
                            </div>
                        </div>
                        <x-ui.forms.toggle-button
                            :id="'device-provider-'.$providerKey.'-enabled'"
                            :model="'providers.'.$providerKey.'.enabled'"
                            :label="__('device_management.providers.use')"
                            class="shrink-0"
                        />
                    </div>

                    <div class="space-y-5 p-4 sm:p-5">
                        @if ($providerKey === 'openuem')
                            @include('livewire.admin.partials.openuem-fork-settings')
                        @endif
                        <div class="rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <p class="text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">
                                {{ __('device_management.providers.effective_addresses') }}
                            </p>
                            <dl class="mt-2 space-y-2 text-xs">
                                <div>
                                    <dt class="font-semibold text-rt-muted dark:text-rt-dark-muted">{{ __('device_management.providers.internal_address') }}</dt>
                                    <dd class="mt-0.5 break-all font-mono text-rt-text dark:text-rt-dark-text">{{ $provider['effective_url'] ?: '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-rt-muted dark:text-rt-dark-muted">{{ __('device_management.providers.public_address') }}</dt>
                                    <dd class="mt-0.5 break-all font-mono text-rt-text dark:text-rt-dark-text">{{ $provider['public_url'] ?: '—' }}</dd>
                                </div>
                            </dl>
                        </div>

                        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-subdomain'" :value="__('device_management.providers.subdomain_prefix')" />
                                <x-ui.forms.input
                                    :id="'device-provider-'.$providerKey.'-subdomain'"
                                    type="text"
                                    autocomplete="off"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    wire:model="providers.{{ $providerKey }}.subdomain"
                                    class="mt-1.5 font-mono"
                                />
                                <x-ui.forms.input-error :for="'providers.'.$providerKey.'.subdomain'" class="mt-1.5" />
                            </div>
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-port'" :value="__('device_management.providers.private_port')" />
                                <x-ui.forms.number-input
                                    :id="'device-provider-'.$providerKey.'-port'"
                                    :min="1024"
                                    :max="65535"
                                    step="1"
                                    :decimals="0"
                                    separator="."
                                    :nullable="false"
                                    class="mt-1 w-full"
                                    wire:model="providers.{{ $providerKey }}.adapter_port"
                                />
                                <x-ui.forms.input-error :for="'providers.'.$providerKey.'.adapter_port'" class="mt-1" />
                            </div>
                        </div>

                        <p class="rounded-lg bg-amber-50/70 px-3 py-2 text-xs leading-5 text-amber-900 ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
                            @if ($providerKey === 'openuem' && ($provider['adapter'] ?? '') === 'native_fork_v1')
                                {{ __('device_management.providers.credentials_hint_native_fork') }}
                            @else
                            {{ __('device_management.providers.credentials_hint') }}
                            @endif
                        </p>

                        <div class="grid min-w-0 gap-4 sm:grid-cols-2">
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-token'" :value="__('device_management.providers.token')" />
                                <x-ui.forms.input
                                    :id="'device-provider-'.$providerKey.'-token'"
                                    type="password"
                                    autocomplete="new-password"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    :placeholder="($provider['token_configured'] ?? false) ? __('device_management.providers.secret_stored_placeholder') : __('device_management.providers.secret_min_placeholder')"
                                    wire:model="providers.{{ $providerKey }}.token"
                                    class="mt-1.5 font-mono"
                                />
                                <x-ui.forms.input-error :for="'providers.'.$providerKey.'.token'" class="mt-1.5" />
                                @if ($provider['token_configured'] ?? false)
                                    <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg px-2 text-xs font-medium text-rt-muted transition hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted">
                                        <input
                                            type="checkbox"
                                            wire:model="providers.{{ $providerKey }}.clear_token"
                                            class="h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface-muted"
                                        >
                                        <span>{{ __('device_management.providers.token_remove') }}</span>
                                    </label>
                                @endif
                            </div>
                            @if (! ($providerKey === 'openuem' && ($provider['adapter'] ?? '') === 'native_fork_v1'))
                            <div class="min-w-0">
                                <x-ui.forms.label :for="'device-provider-'.$providerKey.'-webhook'" :value="__('device_management.providers.webhook_secret')" />
                                <x-ui.forms.input
                                    :id="'device-provider-'.$providerKey.'-webhook'"
                                    type="password"
                                    autocomplete="new-password"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    :placeholder="($provider['webhook_secret_configured'] ?? false) ? __('device_management.providers.secret_stored_placeholder') : __('device_management.providers.secret_min_placeholder')"
                                    wire:model="providers.{{ $providerKey }}.webhook_secret"
                                    class="mt-1.5 font-mono"
                                />
                                <x-ui.forms.input-error :for="'providers.'.$providerKey.'.webhook_secret'" class="mt-1.5" />
                                @if ($provider['webhook_secret_configured'] ?? false)
                                    <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg px-2 text-xs font-medium text-rt-muted transition hover:bg-rt-surface-muted dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted">
                                        <input
                                            type="checkbox"
                                            wire:model="providers.{{ $providerKey }}.clear_webhook_secret"
                                            class="h-4 w-4 rounded border-rt-border text-rt-accent focus:ring-rt-accent dark:border-rt-dark-border dark:bg-rt-dark-surface-muted"
                                        >
                                        <span>{{ __('device_management.providers.webhook_secret_remove') }}</span>
                                    </label>
                                @endif
                            </div>
                            @endif
                        </div>

                        <details class="rounded-xl bg-rt-surface-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3.5 py-2.5 text-sm font-semibold text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/45 dark:text-rt-dark-text">
                                <span>{{ __('device_management.providers.advanced') }}</span>
                                <i class="fad fa-chevron-down text-xs text-rt-muted" aria-hidden="true"></i>
                            </summary>
                            <div class="grid min-w-0 gap-4 border-t border-rt-border/60 p-3.5 dark:border-rt-dark-border/60 sm:grid-cols-2">
                                <div class="min-w-0">
                                    <x-ui.forms.label :for="'device-provider-'.$providerKey.'-timeout'" :value="__('device_management.providers.timeout')" />
                                    <x-ui.forms.number-input
                                        :id="'device-provider-'.$providerKey.'-timeout'"
                                        :min="1"
                                        :max="30"
                                        step="1"
                                        :decimals="0"
                                        separator="."
                                        :unit="__('device_management.units.seconds')"
                                        :nullable="false"
                                        class="mt-1 w-full"
                                        wire:model="providers.{{ $providerKey }}.timeout"
                                    />
                                    <x-ui.forms.input-error :for="'providers.'.$providerKey.'.timeout'" class="mt-1" />
                                </div>
                                @if ($providerKey === 'meshcentral')
                                    <div class="min-w-0">
                                        <x-ui.forms.label :for="'device-provider-'.$providerKey.'-remote-url'" :value="__('device_management.providers.remote_url')" />
                                        <x-ui.forms.input
                                            :id="'device-provider-'.$providerKey.'-remote-url'"
                                            type="url"
                                            inputmode="url"
                                            autocomplete="off"
                                            autocapitalize="none"
                                            spellcheck="false"
                                            placeholder="https://remote.example.test/device/{provider_device_id}"
                                            wire:model="providers.{{ $providerKey }}.remote_url_template"
                                            class="mt-1.5 font-mono"
                                        />
                                        <x-ui.forms.input-error :for="'providers.'.$providerKey.'.remote_url_template'" class="mt-1.5" />
                                    </div>
                                @endif
                            </div>
                        </details>

                        @if ($providerKey === 'nanomdm')
                            @php $mobileCommandsEnabled = (bool) ($provider['mobile_commands_enabled'] ?? false); @endphp
                            <div class="flex flex-col gap-3 rounded-xl bg-amber-50/80 p-3.5 text-amber-950 ring-1 ring-amber-200/80 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold">{{ __('device_management.nanomdm.title') }}</p>
                                        <span class="rounded-full bg-white/65 px-2 py-0.5 text-[0.7rem] font-semibold ring-1 ring-current/10 dark:bg-black/10">
                                            {{ $mobileCommandsEnabled ? __('device_management.nanomdm.enabled') : __('device_management.nanomdm.blocked') }}
                                        </span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 opacity-85">
                                        {{ __('device_management.nanomdm.description') }}
                                    </p>
                                </div>
                                <x-ui.buttons.button-basic mode="secondary"
                                    type="button"
                                    wire:click="setNanoMdmMobileCommandsEnabled({{ $mobileCommandsEnabled ? 'false' : 'true' }})"
                                    wire:confirm="{{ $mobileCommandsEnabled
                                        ? __('device_management.nanomdm.confirm_block')
                                        : __('device_management.nanomdm.confirm_enable') }}"
                                    wire:loading.attr="disabled"
                                    wire:target="setNanoMdmMobileCommandsEnabled"
                                    class="min-h-11 w-full shrink-0 sm:w-auto"
                                >
                                    <i class="fad {{ $mobileCommandsEnabled ? 'fa-lock' : 'fa-unlock-alt' }}" aria-hidden="true"></i>
                                    <span wire:loading.remove wire:target="setNanoMdmMobileCommandsEnabled">
                                        {{ $mobileCommandsEnabled
                                            ? __('device_management.nanomdm.block')
                                            : __('device_management.nanomdm.enable') }}
                                    </span>
                                    <span wire:loading wire:target="setNanoMdmMobileCommandsEnabled">{{ __('app.loading') }}</span>
                                </x-ui.buttons.button-basic>
                            </div>
                            <x-ui.forms.input-error for="providers.nanomdm.mobile_commands_enabled" />
                        @endif

                        @if ($hasResult)
                            <div
                                class="rounded-xl p-3.5 ring-1 {{ $healthy ? 'bg-emerald-50 text-emerald-900 ring-emerald-200/80 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/25' : 'bg-rose-50 text-rose-900 ring-rose-200/80 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/25' }}"
                                role="status"
                                aria-live="polite"
                                aria-atomic="true"
                                data-device-provider-diagnostic="{{ $providerKey }}"
                            >
                                <div class="flex items-start gap-2.5">
                                    <i class="fad {{ $healthy ? 'fa-check-circle' : 'fa-exclamation-circle' }} mt-0.5" aria-hidden="true"></i>
                                    <div class="min-w-0 flex-1">
                                        <p class="break-words text-sm font-semibold">{{ $diagnosticStatusLabel }}</p>
                                        <p class="mt-1 text-xs opacity-80">
                                            {{ $result['checked_at'] ?? '—' }}
                                            @if (is_numeric($result['latency_ms'] ?? null))
                                                · {{ (int) $result['latency_ms'] }} ms
                                            @endif
                                        </p>
                                        <ul class="mt-2 flex flex-wrap gap-1.5 text-[0.7rem] font-semibold">
                                            @foreach ([
                                                'configured' => __('device_management.diagnostic_checks.configured'),
                                                'reachable' => __('device_management.diagnostic_checks.reachable'),
                                                'authenticated' => __('device_management.diagnostic_checks.authenticated'),
                                                'contract_valid' => __('device_management.diagnostic_checks.contract_valid'),
                                            ] as $check => $label)
                                                <li class="rounded-md bg-white/55 px-2 py-1 ring-1 ring-current/10 dark:bg-black/10">
                                                    <i class="fad {{ ($result[$check] ?? false) ? 'fa-check' : 'fa-times' }} me-1" aria-hidden="true"></i>{{ $label }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @else
                            <p class="text-sm leading-5 text-rt-muted dark:text-rt-dark-muted" data-device-provider-unchecked="{{ $providerKey }}">
                                {{ __('device_management.providers.not_tested') }}
                            </p>
                        @endif

                        <x-ui.forms.input-error :for="'providers.'.$providerKey" />
                        <div class="flex flex-col gap-2.5 border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60 sm:flex-row">
                            <x-ui.buttons.button-basic mode="primary"
                                type="button"
                                wire:click="saveProvider('{{ $providerKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="saveProvider('{{ $providerKey }}')"
                                class="min-h-11 w-full sm:flex-1"
                            >
                                <i class="fad fa-save" aria-hidden="true"></i>
                                <span wire:loading.remove wire:target="saveProvider('{{ $providerKey }}')">{{ __('device_management.providers.save') }}</span>
                                <span wire:loading wire:target="saveProvider('{{ $providerKey }}')">{{ __('app.loading') }}</span>
                            </x-ui.buttons.button-basic>
                            <x-ui.buttons.button-basic mode="secondary"
                                type="button"
                                wire:click="testProvider('{{ $providerKey }}')"
                                wire:loading.attr="disabled"
                                wire:target="testProvider('{{ $providerKey }}')"
                                class="min-h-11 w-full sm:flex-1"
                            >
                                <i class="fad fa-stethoscope" aria-hidden="true"></i>
                                <span wire:loading.remove wire:target="testProvider('{{ $providerKey }}')">{{ __('device_management.providers.test') }}</span>
                                <span wire:loading wire:target="testProvider('{{ $providerKey }}')">{{ __('app.loading') }}</span>
                            </x-ui.buttons.button-basic>
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-identities"
        :label="__('device_management.identity.label')"
        :description="__('device_management.identity.description')"
        icon="fad fa-id-card"
    >
        <section class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5">
            <div class="space-y-5 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                <div class="grid min-w-0 gap-4 lg:grid-cols-3">
                    @foreach ([
                        'microsoft_365' => ['label' => 'Microsoft 365 / Outlook', 'placeholder' => 'rail-time.de', 'icon' => 'fab fa-microsoft'],
                        'google_workspace' => ['label' => 'Google Workspace', 'placeholder' => 'rail-time.de', 'icon' => 'fab fa-google'],
                        'apple_managed' => ['label' => 'Apple Managed IDs', 'placeholder' => 'rail-time.de', 'icon' => 'fab fa-apple'],
                    ] as $field => $meta)
                        <div class="min-w-0 rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <label for="device-identity-{{ $field }}" class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                                <i class="{{ $meta['icon'] }} text-rt-muted dark:text-rt-dark-muted" aria-hidden="true"></i>
                                {{ $meta['label'] }}
                            </label>
                            <x-ui.forms.input
                                id="device-identity-{{ $field }}"
                                type="text"
                                inputmode="url"
                                autocomplete="off"
                                autocapitalize="none"
                                spellcheck="false"
                                :placeholder="$meta['placeholder']"
                                wire:model="identityDomains.{{ $field }}"
                                class="mt-1.5 font-mono"
                            />
                            <x-ui.forms.input-error :for="'identityDomains.'.$field" class="mt-1.5" />
                        </div>
                    @endforeach
                </div>
                <x-ui.forms.input-error for="identityDomains" />
                <div class="flex justify-end">
                    <x-ui.buttons.button-basic mode="primary"
                        type="button"
                        wire:click="saveIdentityDomains"
                        wire:loading.attr="disabled"
                        wire:target="saveIdentityDomains"
                        class="min-h-11 w-full sm:w-auto"
                    >
                        <i class="fad fa-save" aria-hidden="true"></i>
                        <span wire:loading.remove wire:target="saveIdentityDomains">{{ __('device_management.identity.save') }}</span>
                        <span wire:loading wire:target="saveIdentityDomains">{{ __('app.loading') }}</span>
                    </x-ui.buttons.button-basic>
                </div>
            </div>
        </section>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="device-safety"
        :label="__('device_management.production.label')"
        :description="__('device_management.production.description')"
        icon="fad fa-shield-alt"
    >
        <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70">
            <div class="flex flex-col gap-5 p-4 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700 ring-1 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25">
                        <i class="fad fa-power-off fa-lg" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">
                                {{ __('device_management.production.switch_title') }}
                            </h3>
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $commandsEnabled ? 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/25' : 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25' }}">
                                {{ $commandsEnabled ? __('device_management.production.enabled') : __('device_management.production.blocked') }}
                            </span>
                        </div>
                        <p class="mt-1 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('device_management.production.switch_description') }}
                        </p>
                    </div>
                </div>
                <x-ui.buttons.button-basic
                    type="button"
                    wire:click="setProductionCommandsEnabled({{ $commandsEnabled ? 'false' : 'true' }})"
                    wire:confirm="{{ $commandsEnabled
                        ? __('device_management.production.confirm_block')
                        : __('device_management.production.confirm_enable') }}"
                    wire:loading.attr="disabled"
                    wire:target="setProductionCommandsEnabled"
                    :mode="$commandsEnabled ? 'secondary' : 'danger'"
                    class="min-h-11 w-full shrink-0 lg:w-auto"
                >
                    <i class="fad {{ $commandsEnabled ? 'fa-lock' : 'fa-unlock-alt' }}" aria-hidden="true"></i>
                    <span wire:loading.remove wire:target="setProductionCommandsEnabled">
                        {{ $commandsEnabled
                            ? __('device_management.production.block')
                            : __('device_management.production.enable') }}
                    </span>
                    <span wire:loading wire:target="setProductionCommandsEnabled">{{ __('app.loading') }}</span>
                </x-ui.buttons.button-basic>
            </div>
            <x-ui.forms.input-error for="runtime.production_commands_enabled" class="mx-4 mb-4 sm:mx-6 sm:mb-6" />
        </section>
    </x-admin.settings-accordion-section>
</div>
