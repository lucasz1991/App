<x-ui.page
    :title="__('app.settings')"
    :eyebrow="__('app.administration')"
    :description="__('app.settings_page_description')"
>
    <x-ui.accordion.tabs
        :tabs="[
            'general' => ['label' => __('app.general'), 'icon' => 'fad fa-sliders-h'],
            'company' => ['label' => __('app.company_data'), 'icon' => 'fad fa-building'],
            'users' => ['label' => __('app.users'), 'icon' => 'fad fa-users'],
            'system' => ['label' => __('app.system'), 'icon' => 'fad fa-server'],
        ]"
        default="general"
        persist-key="admin-settings.tabs"
        :persist-aliases="['sounds' => 'system', 'calls' => 'system']"
        :aria-label="__('app.settings')"
        content-class="mt-4 sm:mt-6"
    >
    {{-- Töne, Anrufe und System werden gemeinsam lazy geladen und innerhalb
         dieses Tabs als drei eigenständige Accordion-Sektionen gegliedert. --}}
    <x-ui.accordion.tab-panel for="system" :order="3" content-class="">
    <div
        x-data="{
            openSystemSection: 'sounds',
            toggleSystemSection(section) {
                this.openSystemSection = this.openSystemSection === section ? null : section;
            },
        }"
        class="flex flex-col gap-3"
        data-admin-system-accordion
        wire:ignore.self
    >
    {{-- Töne: systemweite Standards je Ereignis mit Sofort-Vorschau. --}}
    <x-admin.settings-accordion-section
        section="sounds"
        :label="__('app.sound_settings')"
        :description="__('app.sound_settings_hint')"
        icon="fad fa-volume"
        data-anim="fade-up"
    >
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        aria-labelledby="admin-system-sounds-trigger"
        data-sound-settings
        data-autosave-scope
    >
        <x-ui.autosave-status event="sound-settings-saved" target="saveSounds" dirty-target="sounds" />
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <x-ui.forms.sound-picker model="sounds" />
        </div>
    </section>
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="calls"
        :label="__('app.calls_settings')"
        :description="__('app.calls_settings_intro')"
        icon="fad fa-video"
        data-anim="fade-up"
        data-anim-delay="0.04"
    >
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-autosave-scope
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
                        <x-input-error :for="'calls.'.$field" class="mt-1" />
                        <p class="mt-1.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $meta['hint'] }}
                            <span class="whitespace-nowrap opacity-70">({{ $min }}–{{ $max }})</span>
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Infrastruktur: bewusst NUR lesbar. --}}
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
    </x-admin.settings-accordion-section>

    <x-admin.settings-accordion-section
        section="system"
        :label="__('app.system')"
        :description="__('app.settings_overview_system_text')"
        icon="fad fa-server"
        data-anim="fade-up"
        data-anim-delay="0.08"
    >
    {{-- Wartungsmodus-Warnbanner --}}
    @if ($maintenanceMode)
        <div
            class="flex items-start gap-3 rounded-xl border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-800 shadow-rt-sm ring-1 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/25"
        >
            <i class="fad fa-triangle-exclamation mt-0.5 fa-lg" aria-hidden="true"></i>
            <span>{{ __('app.maintenance_mode_active_banner') }}</span>
        </div>
    @endif

    {{-- System / Wartungsmodus --}}
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-autosave-scope
    >
        <x-ui.autosave-status
            event="system-settings-saved"
            target="saveSystem"
            dirty-target="maintenanceMode"
        />
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <div class="flex flex-col gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:p-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                        {{ __('app.maintenance_mode') }}
                    </p>
                    <p class="mt-0.5 text-xs text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.maintenance_mode_hint') }}
                    </p>
                </div>
                <div class="shrink-0 self-start sm:self-center">
                    <x-ui.forms.toggle-button model="maintenanceMode" data-autosave-instant="true" />
                </div>
            </div>
        </div>
    </section>
    </x-admin.settings-accordion-section>

    </div>
    </x-ui.accordion.tab-panel>

    {{-- Einladungen --}}
    <x-ui.accordion.tab-panel for="users" :order="2" content-class="space-y-6">
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-anim="fade-up"
        data-autosave-scope
    >
        <x-ui.autosave-status
            event="invitation-settings-saved"
            target="saveInvitations"
            dirty-target="invitationExpiryDays"
        />
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
        <div class="flex min-w-0 items-start gap-3 sm:gap-4">
            <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent sm:flex">
                <i class="fad fa-user-plus fa-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                    {{ __('app.invitations') }}
                </h2>
            </div>
        </div>

        <div class="mt-4 w-full sm:max-w-sm">
            <label for="invitation_expiry_days" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                {{ __('app.invitation_expiry_days') }}
            </label>
            <div class="mt-1.5">
                {{-- nullable=false: die Komponente ist an ein typisiertes
                     int-Property gebunden, ein leeres Feld wuerde beim
                     Speichern einen Typfehler ausloesen. --}}
                <x-ui.forms.number-input
                    id="invitation_expiry_days"
                    min="1"
                    max="365"
                    :nullable="false"
                    :unit="__('app.days')"
                    wire:model="invitationExpiryDays"
                />
            </div>
            @error('invitationExpiryDays')
                <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.invitation_expiry_hint') }}
            </p>
        </div>
        </div>
    </section>
    </x-ui.accordion.tab-panel>

    {{-- E-Mails --}}
    <x-ui.accordion.tab-panel for="general" :order="0" content-class="space-y-6">
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-anim="fade-up"
        data-autosave-scope
    >
        <x-ui.autosave-status event="mail-settings-saved" target="saveMails" dirty-target="adminEmail" />
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
        <div class="flex min-w-0 items-start gap-3 sm:gap-4">
            <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent sm:flex">
                <i class="fad fa-envelope fa-lg" aria-hidden="true"></i>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                    {{ __('app.settings_mails') }}
                </h2>
                <p class="mt-1 break-words text-sm text-rt-muted dark:text-rt-dark-muted">
                    {{ __('app.settings_overview_mails_text') }}
                </p>
            </div>
        </div>

        <div class="mt-4 w-full sm:max-w-md">
            <label for="admin_email" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                {{ __('app.admin_email_address') }}
            </label>
            <div class="mt-1.5">
                <x-ui.forms.input
                    type="email"
                    id="admin_email"
                    placeholder="admin@rail-time.de"
                    wire:model="adminEmail"
                />
            </div>
            @error('adminEmail')
                <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
            @enderror
            <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">
                {{ __('app.admin_email_hint') }}
            </p>
        </div>
        </div>
    </section>
    </x-ui.accordion.tab-panel>

    <x-ui.accordion.tab-panel for="company" :order="1" content-class="space-y-6">
        <section
            class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
            data-anim="fade-up"
            data-autosave-scope
        >
            <x-ui.autosave-status
                event="company-settings-saved"
                target="saveCompany"
                dirty-target="company.name,company.email,company.street,company.postal_code,company.city,company.country,company.phone,company.emergency_phone,company.website,company.managing_directors,company.register_court,company.commercial_register_number,company.vat_id,company.tax_number"
            />
            <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <div class="flex min-w-0 items-start gap-3 sm:gap-4">
                <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent sm:flex">
                    <i class="fad fa-building fa-lg" aria-hidden="true"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-lg">
                        {{ __('app.company_data') }}
                    </h2>
                    <p class="mt-1 max-w-3xl break-words text-sm text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.company_data_hint') }}
                    </p>
                </div>
            </div>

            <form wire:submit="saveCompany" class="mt-6">
                <div class="grid min-w-0 gap-x-5 gap-y-4 sm:grid-cols-2">
                            @foreach ([
                                ['name', 'company_name', 'text', 'organization'],
                                ['email', 'company_email', 'email', 'email'],
                                ['street', 'street', 'text', 'street-address'],
                                ['postal_code', 'postal_code', 'text', 'postal-code'],
                                ['city', 'city', 'text', 'address-level2'],
                                ['country', 'country', 'text', 'country-name'],
                                ['phone', 'company_phone', 'tel', 'tel'],
                                ['emergency_phone', 'emergency_phone', 'tel', 'tel'],
                                ['website', 'website', 'url', 'url'],
                                ['managing_directors', 'managing_directors', 'text', 'off'],
                                ['register_court', 'register_court', 'text', 'off'],
                                ['commercial_register_number', 'commercial_register_number', 'text', 'off'],
                                ['vat_id', 'vat_id', 'text', 'off'],
                                ['tax_number', 'tax_number', 'text', 'off'],
                            ] as [$field, $label, $type, $autocomplete])
                                <div class="min-w-0 {{ in_array($field, ['name', 'street', 'managing_directors'], true) ? 'sm:col-span-2' : '' }}">
                                    <label for="company_{{ $field }}" class="block text-sm font-medium text-rt-text dark:text-rt-dark-text">
                                        {{ __('app.'.$label) }}
                                    </label>
                                    <x-ui.forms.input
                                        id="company_{{ $field }}"
                                        type="{{ $type }}"
                                        autocomplete="{{ $autocomplete }}"
                                        wire:model="company.{{ $field }}"
                                        class="mt-1.5"
                                    />
                                    @error('company.'.$field)
                                        <p class="mt-1.5 text-sm text-rt-red">{{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                </div>
            </form>
            </div>
        </section>
    </x-ui.accordion.tab-panel>
    </x-ui.accordion.tabs>
</x-ui.page>
