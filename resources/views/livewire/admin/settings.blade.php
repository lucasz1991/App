<x-ui.page
    :title="__('app.settings')"
    :eyebrow="__('app.administration')"
    :description="__('app.settings_page_description')"
>
    @php
        // Der Übersicht-Tab spiegelt genau die Tabs, die danach folgen. Eine
        // gemeinsame Definition verhindert, dass Teaser und Tableiste
        // auseinanderlaufen, sobald ein Bereich dazukommt oder wegfällt.
        $assistantKnowledgeLabel = __('settings.assistant_knowledge_label');
        $deviceManagementLabel = __('settings.device_management_label');
        $settingsTabs = [
            'overview' => ['label' => __('app.settings_overview'), 'icon' => 'fad fa-th-large'],
            'company' => ['label' => __('app.company_data'), 'icon' => 'fad fa-building'],
            'users' => ['label' => __('app.users'), 'icon' => 'fad fa-users'],
            'system' => ['label' => __('app.system'), 'icon' => 'fad fa-server'],
            'email' => ['label' => 'E-Mail', 'icon' => 'fad fa-envelope'],
        ];

        if ($isSuperAdmin) {
            $settingsTabs['device-management'] = ['label' => $deviceManagementLabel, 'icon' => 'fad fa-network-wired'];
            $settingsTabs['ai-disposition'] = ['label' => 'AI-Disposition', 'icon' => 'fad fa-inbox'];
            $settingsTabs['dropbox'] = ['label' => 'Dropbox', 'icon' => 'fab fa-dropbox'];
            $settingsTabs['tomtom'] = ['label' => 'TomTom / Routenplanung', 'icon' => 'fad fa-route'];
            $settingsTabs['employee-locations'] = ['label' => 'Mitarbeiter-Standorte', 'icon' => 'fad fa-map-marker-alt'];
            $settingsTabs['superadmin'] = ['label' => __('app.settings_superadmin'), 'icon' => 'fad fa-shield-alt'];
        }

        $settingsTabs['activities'] = ['label' => 'Tätigkeitenkatalog', 'icon' => 'far fa-clipboard-list'];
        $settingsTeasers = [
            ['tab' => 'activities', 'section' => '', 'label' => 'Tätigkeitenkatalog', 'description' => 'Tätigkeiten, Qualifikationen und Dienstnachweise verwalten.', 'icon' => 'far fa-clipboard-list', 'items' => ['Kürzel', 'Anforderungen', 'Belege']],
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
                'tab' => 'ai-disposition', 'section' => 'configuration', 'label' => 'AI-Disposition',
                'description' => 'Dispositionspostfach, automatische Anfrage-Annahme und AI-Planungshilfe einrichten.',
                'icon' => 'fad fa-inbox', 'items' => ['Postfach', 'Anfrage-Annahme', 'AI-Helper'],
            ];
            $settingsTeasers[] = [
                'tab' => 'device-management',
                'section' => 'device-deployment',
                'label' => $deviceManagementLabel,
                'description' => __('settings.device_management_description'),
                'icon' => 'fad fa-network-wired',
                'items' => __('settings.device_management_items'),
            ];
            $settingsTeasers[] = [
                'tab' => 'dropbox',
                'section' => '',
                'label' => 'Dropbox',
                'description' => __('settings.dropbox_description'),
                'icon' => 'fab fa-dropbox',
                'items' => __('settings.dropbox_items'),
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
        if ($isSuperAdmin) {
            array_splice($settingsTeasers, count($settingsTeasers) - 1, 0, [
                ['tab' => 'tomtom', 'section' => '', 'label' => 'TomTom / Routenplanung', 'description' => 'Fahrzeiten, Fahrtreserve und Verbrauchsgrenzen einrichten.', 'icon' => 'fad fa-route', 'items' => ['Verbindung', 'Fahrzeiten', 'Verbrauch']],
                ['tab' => 'employee-locations', 'section' => '', 'label' => 'Mitarbeiter-Standorte', 'description' => 'Tägliche Standortfreigabe und Geschäftshandy-Dienst einrichten.', 'icon' => 'fad fa-map-marker-alt', 'items' => ['Tagesfreigabe', 'Geschäftshandy', 'Aufbewahrung']],
            ]);
        }
    @endphp

    <x-ui.accordion.tabs
        :tabs="$settingsTabs"
        :default="$initialTab ?: 'overview'"
        :force-default="$initialTab !== ''"
        persist-key="admin-settings.tabs"
        {{-- Migration der zuvor gespeicherten Tab-IDs: 'general' wurde zur
             Übersicht. Töne/E-Mails bleiben unter System; Anrufe wechseln für
             den Superadmin in seinen geschützten Bereich. --}}
        :persist-aliases="[
            'general' => 'overview',
            'sounds' => 'system',
            'calls' => $isSuperAdmin ? 'superadmin' : 'system',
            'mails' => 'email',
            'mail' => 'email',
        ]"
        :aria-label="__('app.settings')"
        content-class="mt-4 sm:mt-6"
    >
    {{-- Übersicht: Einstiegspunkt mit Teasern auf alle weiteren Bereiche. --}}
    <x-ui.accordion.tab-panel for="overview" :order="0" content-class="">
        <div class="flex flex-col gap-4 sm:gap-5">
            @if ($isSuperAdmin)
                <livewire:admin.system-health />

                <nav class="rt-health-shortcuts" aria-label="Einstellungsbereiche">
                    @foreach ($settingsTeasers as $teaser)
                        <button type="button" x-on:click="selectTab(@js($teaser['tab']), true); $dispatch('rt-settings-open-section', { tab: @js($teaser['tab']), section: @js($teaser['section']) });">
                            <span>{{ $teaser['label'] }}<small>{{ implode(' · ', $teaser['items']) }}</small></span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg>
                        </button>
                    @endforeach
                </nav>
            @else
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
            @endif
        </div>
    </x-ui.accordion.tab-panel>

    <x-ui.accordion.tab-panel for="activities" :order="count($settingsTabs) - 1" content-class=""><livewire:admin.duty-activity-catalog /></x-ui.accordion.tab-panel>

    <x-ui.accordion.tab-panel for="company" :order="1" content-class="">
        <div
            x-data="{
                openAccordionSection: @js($initialTab === 'company' && $initialSection !== '' ? $initialSection : 'company'),
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
        <livewire:admin.settings.company-section />
        </x-ui.accordion.section>
        </div>
    </x-ui.accordion.tab-panel>

    {{-- Benutzer: Einladungen und Team-Rechte bleiben funktional getrennt. --}}
    <x-ui.accordion.tab-panel for="users" :order="2" content-class="">
    <div
        x-data="{
            openAccordionSection: @js($initialTab === 'users' && $initialSection !== '' ? $initialSection : 'invitations'),
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
    <livewire:admin.settings.invitations-section />
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
            <livewire:employees.team-rbac-modal
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
            openAccordionSection: @js($initialTab === 'system' && $initialSection !== '' ? $initialSection : 'system'),
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
    <livewire:admin.settings.system-section />
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
    <livewire:admin.settings.assistant-section />
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
    <livewire:admin.settings.sounds-section />
    </x-admin.settings-accordion-section>

    </div>
    </x-ui.accordion.tab-panel>

    @if ($isSuperAdmin)
        {{-- Geräte-Setup: Providerzugänge und Diagnosen bleiben ausschließlich
             bei Benutzer #1. Die Child-Komponente prüft diese Grenze in mount
             und in jeder einzelnen Aktion erneut auf dem Server. --}}
        <x-ui.accordion.tab-panel for="device-management" :order="5" content-class="">
            <livewire:admin.device-management-settings />
            @if(auth()->user()?->isSuperAdmin())<livewire:admin.workplace-support-settings />@endif
        </x-ui.accordion.tab-panel>

        <x-ui.accordion.tab-panel for="dropbox" :order="7" content-class="">
            <livewire:admin.dropbox-settings />
        </x-ui.accordion.tab-panel>

        <x-ui.accordion.tab-panel for="tomtom" :order="8" content-class=""><livewire:admin.tom-tom-settings /></x-ui.accordion.tab-panel>
        <x-ui.accordion.tab-panel for="employee-locations" :order="9" content-class=""><livewire:admin.employee-location-settings /></x-ui.accordion.tab-panel>

        {{-- Superadmin: Sprach-/Modellrouting, Anrufbetrieb und Wissenspool.
             Der Tab wird nur für Benutzer #1 gerendert; alle Aktionen prüfen
             diese Grenze zusätzlich auf dem Server. --}}
        <x-ui.accordion.tab-panel for="superadmin" :order="10" content-class="">
        <div
            x-data="{
                openAccordionSection: @js($initialTab === 'superadmin' && $initialSection !== '' ? $initialSection : 'assistant-runtime'),
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

            @if(auth()->user()?->isSuperAdmin())
                <x-admin.settings-accordion-section section="local-excel" label="Lokaler Excel-Import" description="Dateien vom Gerät einmalig in die App übernehmen" icon="fas fa-file-excel">
                    <livewire:admin.local-excel-import />
                </x-admin.settings-accordion-section>
            @endif

            <x-admin.settings-accordion-section
                section="assistant-runtime"
                :label="__('app.assistant_runtime_settings')"
                :description="__('app.assistant_runtime_settings_hint')"
                icon="fad fa-brain"
                data-anim="fade-up"
                data-anim-delay="0.04"
            >
                <livewire:admin.settings.assistant-runtime-section />
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
                <livewire:admin.settings.calls-section />
            </x-admin.settings-accordion-section>

            <x-admin.settings-accordion-section
                section="assistant-knowledge"
                :label="$assistantKnowledgeLabel"
                :description="__('settings.assistant_knowledge_description')"
                icon="fad fa-books"
                data-anim="fade-up"
                data-anim-delay="0.12"
            >
                <livewire:admin.assistant-knowledge-manager />
            </x-admin.settings-accordion-section>
        </div>
        </x-ui.accordion.tab-panel>
    @endif
    <x-ui.accordion.tab-panel for="email" :order="4" content-class="">
        <div class="flex flex-col gap-3" x-data="{ openAccordionSection: @js($initialTab === 'email' && $initialSection !== '' ? $initialSection : 'mails'), toggleAccordionSection(section) { this.openAccordionSection = this.openAccordionSection === section ? null : section } }">
    {{-- 3. E-Mails: Empfängeradresse für Systemnachrichten. --}}
    <x-admin.settings-accordion-section
        section="mails"
        :label="__('app.settings_mails')"
        :description="__('app.settings_overview_mails_text')"
        icon="fad fa-envelope"
        data-anim="fade-up"
        data-anim-delay="0.08"
    >
    <livewire:admin.settings.mails-section />
    </x-admin.settings-accordion-section>


            <x-admin.settings-accordion-section section="templates" label="Mailvorlagen & Editor" description="" icon="fad fa-file-signature">
                <x-ui.buttons.button-basic :href="route('admin.mail-documents.editor')" data-no-navigate>Mailvorlagen & Editor öffnen</x-ui.buttons.button-basic>
            </x-admin.settings-accordion-section>
        </div>
    </x-ui.accordion.tab-panel>
    @if($isSuperAdmin)
        <x-ui.accordion.tab-panel for="ai-disposition" :order="6" content-class="">
            <livewire:admin.ai-disposition-configuration />
        </x-ui.accordion.tab-panel>
    @endif
    </x-ui.accordion.tabs>
</x-ui.page>
