<x-ui.page
    :title="__('app.settings')"
    :eyebrow="__('app.administration')"
    :description="__('app.settings_page_description')"
>
    @php
        // Der Übersicht-Tab spiegelt genau die Tabs, die danach folgen. Eine
        // gemeinsame Definition verhindert, dass Teaser und Tableiste
        // auseinanderlaufen, sobald ein Bereich dazukommt oder wegfällt.
        $assistantKnowledgeLabel = app()->getLocale() === 'de'
            ? 'Informationspool des Chatbot-Assistenten'
            : 'Chatbot assistant knowledge pool';
        $deviceManagementLabel = app()->getLocale() === 'de'
            ? 'Geräte-Setup'
            : 'Device setup';
        $settingsTabs = [
            'overview' => ['label' => __('app.settings_overview'), 'icon' => 'fad fa-th-large'],
            'company' => ['label' => __('app.company_data'), 'icon' => 'fad fa-building'],
            'users' => ['label' => __('app.users'), 'icon' => 'fad fa-users'],
            'system' => ['label' => __('app.system'), 'icon' => 'fad fa-server'],
        ];

        if ($isSuperAdmin) {
            $settingsTabs['device-management'] = ['label' => $deviceManagementLabel, 'icon' => 'fad fa-network-wired'];
            $settingsTabs['superadmin'] = ['label' => __('app.settings_superadmin'), 'icon' => 'fad fa-shield-alt'];
        }

        $settingsTeasers = [
            [
                'tab' => 'company',
                'section' => 'company',
                'label' => __('app.company_data'),
                'description' => __('app.settings_overview_company_text'),
                'icon' => 'fad fa-building',
                'items' => [__('app.company_name'), __('app.company_email'), __('app.vat_id')],
            ],
            [
                'tab' => 'users',
                'section' => 'invitations',
                'label' => __('app.users'),
                'description' => __('app.settings_overview_users_text'),
                'icon' => 'fad fa-users',
                'items' => array_values(array_filter([
                    __('app.invitations'),
                    auth()->user()?->can('roles.manage') ? __('app.teams_permissions') : null,
                ])),
            ],
            [
                'tab' => 'system',
                'section' => 'system',
                'label' => __('app.system'),
                'description' => __('app.settings_overview_system_text'),
                'icon' => 'fad fa-server',
                'items' => [
                    __('app.maintenance_mode'),
                    __('app.assistant_settings'),
                    __('app.settings_mails'),
                    __('app.sound_settings'),
                ],
            ],
        ];

        if ($isSuperAdmin) {
            $settingsTeasers[] = [
                'tab' => 'device-management',
                'section' => 'device-deployment',
                'label' => $deviceManagementLabel,
                'description' => app()->getLocale() === 'de'
                    ? 'Plesk-Adressen, Geräteprovider, Kontendomänen und sichere Verbindungstests einrichten.'
                    : 'Configure Plesk addresses, device providers, identity domains and safe connection tests.',
                'icon' => 'fad fa-network-wired',
                'items' => app()->getLocale() === 'de'
                    ? ['Subdomains oder Ports', 'Provider', 'Funktionstests']
                    : ['Subdomains or ports', 'Providers', 'Connection tests'],
            ];
            $settingsTeasers[] = [
                'tab' => 'superadmin',
                'section' => 'assistant-runtime',
                'label' => __('app.settings_superadmin'),
                'description' => __('app.settings_overview_superadmin_text'),
                'icon' => 'fad fa-shield-alt',
                'items' => [__('app.assistant_runtime_settings'), __('app.calls_settings'), $assistantKnowledgeLabel],
            ];
        }
    @endphp

    <x-ui.accordion.tabs
        :tabs="$settingsTabs"
        default="overview"
        persist-key="admin-settings.tabs"
        {{-- Migration der zuvor gespeicherten Tab-IDs: 'general' wurde zur
             Übersicht. Töne/E-Mails bleiben unter System; Anrufe wechseln für
             den Superadmin in seinen geschützten Bereich. --}}
        :persist-aliases="[
            'general' => 'overview',
            'sounds' => 'system',
            'calls' => $isSuperAdmin ? 'superadmin' : 'system',
            'mails' => 'system',
            'mail' => 'system',
        ]"
        :aria-label="__('app.settings')"
        content-class="mt-4 sm:mt-6"
    >
    {{-- Übersicht: Einstiegspunkt mit Teasern auf alle weiteren Bereiche. --}}
    <x-ui.accordion.tab-panel for="overview" :order="0" content-class="">
        <div class="flex flex-col gap-4 sm:gap-5">
            <section
                class="relative overflow-hidden rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 sm:p-6"
                data-anim="fade-up"
            >
                <span
                    class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-rt-accent-soft/50 blur-3xl dark:bg-rt-dark-accent-soft/35"
                    aria-hidden="true"
                ></span>

                <div class="relative flex min-w-0 items-start gap-3 sm:gap-4">
                    <span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent sm:flex">
                        <i class="fad fa-compass fa-lg" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text sm:text-xl">
                            {{ __('app.settings_overview') }}
                        </h2>
                        <p class="mt-1.5 max-w-3xl break-words text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.settings_overview_intro') }}
                        </p>
                    </div>
                </div>
            </section>

            <div class="grid min-w-0 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3">
                @foreach ($settingsTeasers as $index => $teaser)
                    <x-admin.settings-teaser
                        :tab="$teaser['tab']"
                        :section="$teaser['section']"
                        :label="$teaser['label']"
                        :description="$teaser['description']"
                        :icon="$teaser['icon']"
                        :items="$teaser['items']"
                        data-anim="fade-up"
                        :data-anim-delay="0.04 * ($index + 1)"
                    />
                @endforeach
            </div>
        </div>
    </x-ui.accordion.tab-panel>

    <x-ui.accordion.tab-panel for="company" :order="1" content-class="">
        <div
            x-data="{
                openAccordionSection: 'company',
                toggleAccordionSection(section) {
                    this.openAccordionSection = this.openAccordionSection === section ? null : section;
                },
            }"
            x-on:rt-settings-open-section.window="
                if ($event.detail.tab === 'company' && $event.detail.section) {
                    openAccordionSection = $event.detail.section;
                }
            "
            class="flex flex-col gap-3"
            data-admin-company-accordion
            wire:ignore.self
        >
        <x-ui.accordion.section
            section="company"
            :label="__('app.company_data')"
            :description="__('app.company_data_hint')"
            icon="fad fa-building"
            id-prefix="admin-company"
            data-anim="fade-up"
        >
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
        </x-ui.accordion.section>
        </div>
    </x-ui.accordion.tab-panel>

    {{-- Benutzer: Einladungen und Team-Rechte bleiben funktional getrennt. --}}
    <x-ui.accordion.tab-panel for="users" :order="2" content-class="">
    <div
        x-data="{
            openAccordionSection: 'invitations',
            toggleAccordionSection(section) {
                this.openAccordionSection = this.openAccordionSection === section ? null : section;
            },
        }"
        x-on:rt-settings-open-section.window="
            if ($event.detail.tab === 'users' && $event.detail.section) {
                openAccordionSection = $event.detail.section;
            }
        "
        class="flex flex-col gap-3"
        data-admin-users-accordion
        wire:ignore.self
    >
    <x-ui.accordion.section
        section="invitations"
        :label="__('app.invitations')"
        :description="__('app.invitation_expiry_hint')"
        icon="fad fa-user-plus"
        id-prefix="admin-users"
        data-anim="fade-up"
        data-admin-users-section="invitations"
    >
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
        <div class="w-full sm:max-w-sm">
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
    </x-ui.accordion.section>

    @can('roles.manage')
        <x-ui.accordion.section
            section="permissions"
            :label="__('app.teams_permissions')"
            :description="__('app.teams_permissions_hint')"
            icon="fad fa-user-shield"
            id-prefix="admin-users"
            data-anim="fade-up"
            data-anim-delay="0.04"
            data-admin-users-section="permissions"
        >
            <livewire:admin.employees.team-rbac-modal
                :embedded="true"
                :key="'settings-team-rbac-manager'"
            />
        </x-ui.accordion.section>
    @endcan
    </div>
    </x-ui.accordion.tab-panel>

    {{-- Allgemeine Systemwerte, Assist-Freigabe, E-Mails und Töne bleiben für
         Administratoren gemeinsam erreichbar. Betriebsnahe KI- und
         Anrufkonfiguration lebt ausschließlich im Superadmin-Tab. --}}
    <x-ui.accordion.tab-panel for="system" :order="3" content-class="">
    <div
        x-data="{
            openAccordionSection: 'system',
            toggleAccordionSection(section) {
                this.openAccordionSection = this.openAccordionSection === section ? null : section;
            },
        }"
        x-on:rt-settings-open-section.window="
            if ($event.detail.tab === 'system' && $event.detail.section) {
                openAccordionSection = $event.detail.section;
            }
        "
        class="flex flex-col gap-3"
        data-admin-system-accordion
        wire:ignore.self
    >
    {{-- 1. System: Wartungsmodus. --}}
    <x-admin.settings-accordion-section
        section="system"
        :label="__('app.system')"
        :description="__('app.settings_overview_system_short')"
        icon="fad fa-server"
        data-anim="fade-up"
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

    {{-- 2. Chatbot-Assistent: globale Freigabe vor Teamrechten. --}}
    <x-admin.settings-accordion-section
        section="assistant"
        :label="__('app.assistant_settings')"
        :description="__('app.assistant_settings_hint')"
        icon="fad fa-robot"
        data-anim="fade-up"
        data-anim-delay="0.04"
    >
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:p-1.5"
        data-autosave-scope
    >
        <x-ui.autosave-status
            event="assistant-settings-saved"
            target="saveAssistant"
            dirty-target="assistantEnabled"
        />
        <div class="space-y-4 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <div class="flex flex-col gap-3 rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:p-4">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                        {{ __('app.assistant_enabled') }}
                    </p>
                    <p class="mt-0.5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.assistant_enabled_hint') }}
                    </p>
                </div>
                <div class="shrink-0 self-start sm:self-center">
                    <x-ui.forms.toggle-button
                        id="assistant-enabled"
                        model="assistantEnabled"
                        data-autosave-instant="true"
                    />
                </div>
            </div>

        </div>
    </section>
    </x-admin.settings-accordion-section>

    {{-- 3. E-Mails: Empfängeradresse für Systemnachrichten. --}}
    <x-admin.settings-accordion-section
        section="mails"
        :label="__('app.settings_mails')"
        :description="__('app.settings_overview_mails_text')"
        icon="fad fa-envelope"
        data-anim="fade-up"
        data-anim-delay="0.08"
    >
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        data-autosave-scope
    >
        <x-ui.autosave-status event="mail-settings-saved" target="saveMails" dirty-target="adminEmail" />
        <div class="space-y-5 rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
        <div class="w-full sm:max-w-md">
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

        @php
            $outlookAddinManifest = app(\App\Support\OutlookAddin\OutlookAddinManifest::class);
            $outlookAddinConfiguration = app(\App\Support\OutlookAddin\OutlookAddinConfiguration::class);
            $outlookAddinConfigured = $outlookAddinManifest->ready();
            $outlookAddinDeployed = $outlookAddinConfiguration->deployed();
        @endphp
        <div
            class="rounded-xl bg-rt-surface-muted p-4 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
            data-outlook-addin-deployment
        >
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span
                            class="h-2.5 w-2.5 shrink-0 rounded-full {{ $outlookAddinDeployed ? 'bg-emerald-500' : ($outlookAddinConfigured ? 'bg-sky-500' : 'bg-amber-500') }}"
                            aria-hidden="true"
                        ></span>
                        <p class="text-sm font-semibold text-rt-text dark:text-rt-dark-text">
                            Outlook-Zentralbereitstellung
                        </p>
                    </div>
                    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        @if ($outlookAddinDeployed)
                            Als zentral bereitgestellt bestätigt. Signatur und Nachrichtenvorlage stammen aus der aktuellen Veröffentlichung.
                        @elseif ($outlookAddinConfigured)
                            Lokal bereit. Manifest zuerst im Microsoft 365 Admin Center einer Pilotgruppe zuweisen und prüfen; danach OUTLOOK_ADDIN_DEPLOYED aktivieren.
                        @else
                            Noch nicht aktiv. Tenant-ID und Client-ID werden einmalig in der Serverkonfiguration ergänzt.
                        @endif
                    </p>
                </div>

                <div class="grid shrink-0 gap-2 sm:grid-cols-2">
                    @if ($outlookAddinConfigured)
                        <a
                            href="{{ route('admin.outlook-addin.manifest') }}"
                            data-no-navigate
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-text ring-1 ring-inset ring-rt-border/80 transition hover:bg-rt-surface dark:text-rt-dark-text dark:ring-rt-dark-border/80 dark:hover:bg-rt-dark-surface"
                        >
                            <i class="far fa-file-code" aria-hidden="true"></i>
                            Manifest
                        </a>
                        <a
                            href="{{ route('admin.outlook-addin.package') }}"
                            data-no-navigate
                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-3 py-2 text-xs font-semibold text-white transition hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-red/20"
                        >
                            <i class="far fa-download" aria-hidden="true"></i>
                            Bereitstellungspaket
                        </a>
                    @else
                        <span class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold text-rt-muted ring-1 ring-inset ring-rt-border/60 opacity-70 dark:text-rt-dark-muted dark:ring-rt-dark-border/60 sm:col-span-2">
                            <i class="far fa-lock" aria-hidden="true"></i>
                            Serverkonfiguration ausstehend
                        </span>
                    @endif
                </div>
            </div>
        </div>
        </div>
    </section>
    </x-admin.settings-accordion-section>

    {{-- 4. Töne: systemweite Standards je Ereignis mit Sofort-Vorschau. --}}
    <x-admin.settings-accordion-section
        section="sounds"
        :label="__('app.sound_settings')"
        :description="__('app.sound_settings_hint')"
        icon="fad fa-volume"
        data-anim="fade-up"
        data-anim-delay="0.08"
    >
    <section
        class="relative min-w-0 overflow-hidden rounded-2xl bg-rt-surface-muted p-1 sm:p-1.5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60"
        aria-labelledby="admin-settings-sounds-trigger"
        data-sound-settings
        data-autosave-scope
    >
        <x-ui.autosave-status event="sound-settings-saved" target="saveSounds" dirty-target="sounds" />
        <div class="rounded-[calc(1rem-2px)] bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6" data-rt-glow>
            <x-ui.forms.sound-picker model="sounds" />
        </div>
    </section>
    </x-admin.settings-accordion-section>

    </div>
    </x-ui.accordion.tab-panel>

    @if ($isSuperAdmin)
        {{-- Geräte-Setup: Providerzugänge und Diagnosen bleiben ausschließlich
             bei Benutzer #1. Die Child-Komponente prüft diese Grenze in mount
             und in jeder einzelnen Aktion erneut auf dem Server. --}}
        <x-ui.accordion.tab-panel for="device-management" :order="4" content-class="">
            <livewire:admin.device-management-settings />
        </x-ui.accordion.tab-panel>

        {{-- Superadmin: Sprach-/Modellrouting, Anrufbetrieb und Wissenspool.
             Der Tab wird nur für Benutzer #1 gerendert; alle Aktionen prüfen
             diese Grenze zusätzlich auf dem Server. --}}
        <x-ui.accordion.tab-panel for="superadmin" :order="5" content-class="">
        <div
            x-data="{
                openAccordionSection: 'assistant-runtime',
                toggleAccordionSection(section) {
                    this.openAccordionSection = this.openAccordionSection === section ? null : section;
                },
            }"
            x-on:rt-settings-open-section.window="
                if ($event.detail.tab === 'superadmin' && $event.detail.section) {
                    openAccordionSection = $event.detail.section;
                }
            "
            class="flex flex-col gap-3"
            data-admin-superadmin-accordion
            wire:ignore.self
        >
            <div
                class="flex items-start gap-3 rounded-2xl border-l-4 border-rt-accent bg-rt-accent-soft/40 px-4 py-3 text-sm text-rt-text shadow-rt-sm ring-1 ring-rt-accent/15 dark:bg-rt-dark-accent-soft/25 dark:text-rt-dark-text dark:ring-rt-dark-accent/20"
                data-anim="fade-up"
            >
                <i class="fad fa-shield-alt mt-0.5 fa-lg text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                <span>{{ __('app.settings_superadmin_hint') }}</span>
            </div>

            <x-admin.settings-accordion-section
                section="assistant-runtime"
                :label="__('app.assistant_runtime_settings')"
                :description="__('app.assistant_runtime_settings_hint')"
                icon="fad fa-brain"
                data-anim="fade-up"
                data-anim-delay="0.04"
            >
                @include('livewire.admin.settings.partials.assistant-runtime')
            </x-admin.settings-accordion-section>

            {{-- Anruf-Betriebswerte und Infrastruktur sind ausschliesslich fuer den Superadmin. --}}
            <x-admin.settings-accordion-section
                section="calls"
                :label="__('app.calls_settings')"
                :description="__('app.calls_settings_intro')"
                icon="fad fa-video"
                data-anim="fade-up"
                data-anim-delay="0.08"
            >
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
            </x-admin.settings-accordion-section>

            <x-admin.settings-accordion-section
                section="assistant-knowledge"
                :label="$assistantKnowledgeLabel"
                :description="app()->getLocale() === 'de' ? 'Default-Prompt, verbindliche Regeln, Themen und gezielt abrufbares Wissen verwalten.' : 'Manage the default prompt, binding rules, topics and knowledge retrieved on demand.'"
                icon="fad fa-books"
                data-anim="fade-up"
                data-anim-delay="0.12"
            >
                <livewire:admin.assistant-knowledge-manager />
            </x-admin.settings-accordion-section>
        </div>
        </x-ui.accordion.tab-panel>
    @endif
    </x-ui.accordion.tabs>
</x-ui.page>
