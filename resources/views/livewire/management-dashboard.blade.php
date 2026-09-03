{{--
    Verwaltungs-Dashboard (Teams "Verwaltung" und "Administratoren").

    Gliederung wie im Admin-Dashboard, damit beide Ansichten sich gleich lesen:
      Kopf        -> was gerade laeuft
      Belegschaft -> Personal- und Kontenbestand, gebuendelt
      Arbeit      -> die erreichbaren Arbeitsbereiche
      Entwicklung -> Zeitreihen
      Personen    -> zuletzt aktiv
      System      -> zugeklappt, nur fuer das Team Administratoren

    Jede Zahl steht genau einmal. Schnellzugriffe gibt es hier nicht mehr —
    sie waren eine Kopie der dauerhaft sichtbaren Seitennavigation.
--}}
<div class="relative min-w-0" data-management-dashboard>
    <x-ui.page :auto-intro="false" content-class="space-y-5 sm:space-y-6">
        <x-ui.dashboard.role-hero
            :title="__('app.welcome_name', ['name' => auth()->user()->name])"
            :eyebrow="$dashboardTeamName"
            :description="__('app.management_dashboard_description')"
            icon="briefcase"
            data-anim="fade-up"
        >
            <x-slot:actions>
                <button
                    type="button"
                    x-on:click="$dispatch('rt-welcome:open')"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-inset ring-white/15 outline-none transition duration-200 hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white/75"
                    aria-label="{{ __('app.welcome_intro_reopen') }}"
                    title="{{ __('app.welcome_intro_reopen') }}"
                    data-welcome-intro-trigger
                >
                    <i data-feather="info" class="h-4 w-4" aria-hidden="true"></i>
                </button>
            </x-slot:actions>

            <x-slot:aside>
                <div class="flex h-full flex-col">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-accent text-white shadow-rt-sm" aria-hidden="true">
                        <i data-feather="shield" class="h-5 w-5"></i>
                    </span>
                    <p class="mt-4 text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-slate-400">
                        {{ __('app.team') }}
                    </p>
                    <p class="mt-1 text-lg font-bold tracking-[-0.02em] text-white">
                        {{ $dashboardTeamName }}
                    </p>
                    <p class="mt-2 text-pretty text-xs leading-5 text-slate-300">
                        {{ $canViewSystemData ? __('app.welcome_message_team_administrators') : __('app.welcome_message_team_management') }}
                    </p>
                </div>
            </x-slot:aside>

            {{-- Im Kopf stehen ausschliesslich die Werte von JETZT. Der
                 Bestand (Mitarbeiter, Konten, Teams) folgt im naechsten
                 Abschnitt und wird nicht doppelt gezeigt. --}}
            <x-slot:metrics>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4" data-anim-stagger>
                    <x-ui.dashboard.operational-stat
                        icon="radio"
                        tone="success"
                        :label="__('app.online_now')"
                        :value="number_format($operations['online'], 0, ',', '.')"
                    />
                    <x-ui.dashboard.operational-stat
                        icon="mail"
                        tone="warning"
                        :label="__('app.open_invitations')"
                        :value="number_format($operations['openInvitations'], 0, ',', '.')"
                    />
                    <x-ui.dashboard.operational-stat
                        icon="message-circle"
                        tone="brand"
                        :label="__('app.unread_messages_total')"
                        :value="number_format($operations['unreadTotal'], 0, ',', '.')"
                    />
                </div>
            </x-slot:metrics>
        </x-ui.dashboard.role-hero>

        {{-- BELEGSCHAFT — frueher vier Statuskacheln im Kopf, eine Fokuskarte
             und ein Balkendiagramm aktiv/inaktiv fuer dieselben drei Zahlen. --}}
        <section aria-labelledby="workforce-overview-title" data-dashboard-workforce-focus data-anim="fade-up">
            <x-ui.dashboard.section-heading
                id="workforce-overview-title"
                icon="briefcase"
                :title="__('app.workforce_overview')"
                :description="__('app.all_employees_hint')"
            >
                @can('employees.view')
                    <x-slot:actions>
                        <a
                            href="{{ route('employees.index') }}"
                            wire:navigate
                            class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40"
                        >
                            {{ __('app.manage_employees') }}
                            <i data-feather="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                        </a>
                    </x-slot:actions>
                @endcan
            </x-ui.dashboard.section-heading>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-anim-stagger>
                <x-ui.dashboard.stat-card :compact-mobile="true" tone="brand" :label="__('app.employees')" :value="number_format($totalEmployees, 0, ',', '.')">
                    <i data-feather="briefcase" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                </x-ui.dashboard.stat-card>
                <x-ui.dashboard.stat-card :compact-mobile="true" tone="success" :label="__('app.active_users')" :value="number_format($activeUsers, 0, ',', '.')">
                    <i data-feather="user-check" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                </x-ui.dashboard.stat-card>
                <x-ui.dashboard.stat-card :compact-mobile="true" :label="__('app.total_users')" :value="number_format($totalUsers, 0, ',', '.')">
                    <i data-feather="users" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                </x-ui.dashboard.stat-card>
                <x-ui.dashboard.stat-card :compact-mobile="true" :label="__('app.teams_rbac')" :value="number_format($totalTeams, 0, ',', '.')">
                    <i data-feather="shield" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                </x-ui.dashboard.stat-card>
            </div>

            @if ($deviceWidget['scope'] === 'fleet')
                @can('devices.view')
                    <x-ui.dashboard.device-management-widget
                        :stats="$deviceWidget['stats']"
                        :href="$deviceWidget['href']"
                        data-dashboard-device-scope="fleet"
                    />
                @endcan
            @else
                <x-ui.dashboard.personal-device-widget
                    :stats="$deviceWidget['stats']"
                    :href="$deviceWidget['href']"
                    class="mt-4"
                    data-dashboard-device-variant="compact"
                />
            @endif
        </section>

        {{-- ARBEITSBEREICHE — nur das, was diese Rolle auch oeffnen darf. --}}
        <section aria-labelledby="management-workspaces-title" data-anim="fade-up">
            <x-ui.dashboard.section-heading
                id="management-workspaces-title"
                icon="clipboard"
                :title="__('app.operational_control')"
            />

            <div class="mt-4 grid gap-3 sm:gap-4 md:grid-cols-2">
                <x-ui.dashboard.focus-card
                    :href="route('operations.wagon-list')"
                    icon="clipboard"
                    tone="brand"
                    :title="__('app.order_workspace')"
                    :description="__('app.help_topic_wagon_description')"
                    :badge="__('app.wagon_lists_available')"
                    :action-label="__('app.open_wagon_list')"
                />

                <x-ui.dashboard.focus-card
                    icon="clock"
                    tone="neutral"
                    :title="__('app.shift_workspace')"
                    :description="__('app.preview_schedule_hint')"
                    :badge="__('app.planning_not_connected')"
                    preview
                />
            </div>
        </section>

        {{-- ENTWICKLUNG — zwei Reihen nebeneinander. Das dritte Diagramm
             (aktiv/inaktiv) ist entfallen: beide Werte stehen oben als Zahl. --}}
        <section
            class="min-w-0"
            aria-labelledby="management-trends-title"
            data-dashboard-real-series
            data-anim="fade-up"
        >
            <x-ui.dashboard.section-heading
                id="management-trends-title"
                icon="trending-up"
                :title="__('app.user_growth')"
                :description="__('app.last_14_days')"
            />

            <div class="mt-4 grid min-w-0 gap-3 sm:gap-4 md:grid-cols-2">
                <x-ui.dashboard.trend-chart
                    :title="__('app.user_growth')"
                    :description="__('app.last_14_days')"
                    :labels="$charts['userGrowth']['labels']"
                    :values="$charts['userGrowth']['totals']"
                    type="line"
                    tone="brand"
                    icon="trending-up"
                    :summary="$totalUsers"
                    :summary-label="__('app.total_users')"
                    data-series-source="users-growth"
                />

                <x-ui.dashboard.trend-chart
                    :title="__('app.activity_trend')"
                    :description="__('app.last_14_days')"
                    :labels="$charts['activity']['labels']"
                    :values="$charts['activity']['values']"
                    type="bar"
                    tone="success"
                    icon="activity"
                    :summary="collect($charts['activity']['values'])->last() ?: 0"
                    :summary-label="__('app.current')"
                    :empty-label="__('app.no_activity_yet')"
                    data-series-source="activity-log"
                />
            </div>
        </section>

        <section class="rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="recent-activity-title" data-anim="fade-up">
            <x-ui.dashboard.section-heading
                id="recent-activity-title"
                icon="clock"
                :title="__('app.recently_active')"
            />

            <ul class="mt-5 grid gap-2.5 md:grid-cols-2">
                @forelse ($recentActivity as $entry)
                    <li class="grid grid-cols-[2.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0.5 rounded-xl bg-rt-surface-muted/65 px-3 py-3 ring-1 ring-rt-border/60 sm:flex sm:gap-3 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60">
                        <img src="{{ $entry['user']->profile_photo_url }}" alt="" class="row-span-2 h-10 w-10 rounded-xl object-cover ring-1 ring-rt-border/70 sm:row-auto dark:ring-rt-dark-border/70">
                        <div class="min-w-0 sm:flex-1">
                            <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $entry['user']->name }}</p>
                            <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $entry['user']->email }}</p>
                        </div>
                        <time class="col-start-2 inline-flex items-center gap-1.5 text-[11px] text-rt-soft sm:col-auto sm:shrink-0 sm:text-xs dark:text-rt-dark-soft" datetime="{{ $entry['lastSeen']->toIso8601String() }}">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                            {{ $entry['lastSeen']->diffForHumans() }}
                        </time>
                    </li>
                @empty
                    <li class="rounded-xl bg-rt-surface-muted/65 px-4 py-8 text-center ring-1 ring-rt-border/60 md:col-span-2 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60">
                        <i data-feather="user-x" class="mx-auto h-5 w-5 text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                        <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_activity_yet') }}</p>
                    </li>
                @endforelse
            </ul>
        </section>

        {{-- SYSTEM — neun Werte, die sich praktisch nie aendern: zugeklappt. --}}
        @if ($canViewSystemData && $system)
            @php
                $systemFacts = [
                    ['label' => __('app.application'), 'value' => $system['appVersion']],
                    ['label' => __('app.environment'), 'value' => $system['environment']],
                    ['label' => __('app.php_version'), 'value' => $system['php']],
                    ['label' => __('app.developer'), 'value' => $system['developer']],
                    ['label' => __('app.database'), 'value' => $system['database']],
                    ['label' => __('app.queue'), 'value' => $system['queue']],
                    ['label' => __('app.file_storage'), 'value' => $system['storage']],
                    ['label' => __('app.server_disk'), 'value' => $system['disk']],
                    ['label' => __('app.last_activity'), 'value' => $system['lastActivityAt']?->diffForHumans() ?? __('app.unknown')],
                ];
            @endphp

            <section class="overflow-hidden rounded-[1.5rem] bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" data-system-dashboard aria-labelledby="system-dashboard-title" data-anim="fade-up">
                <details class="group">
                    {{-- Bewusst ohne die Bauteil-Ueberschrift: <summary> darf
                         nur Text und hoechstens EIN Ueberschriftselement
                         enthalten, section-heading liefert ein <header>. --}}
                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 p-4 sm:p-6 [&::-webkit-details-marker]:hidden">
                        <span class="flex min-w-0 items-start gap-3">
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent" aria-hidden="true">
                                <i data-feather="server" class="h-4 w-4"></i>
                            </span>
                            <span class="min-w-0">
                                <h2 id="system-dashboard-title" class="text-lg font-bold tracking-[-0.02em] text-rt-text dark:text-white">
                                    {{ __('app.technical_system_data') }}
                                </h2>
                                <span class="mt-1 block max-w-2xl text-pretty text-sm leading-5 text-rt-muted dark:text-rt-dark-muted">
                                    {{ __('app.technical_system_data_description') }}
                                </span>
                            </span>
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-rt-surface-muted/70 px-3 py-2 text-xs font-bold text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/50 dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                            {{ __('app.show') }}
                            <i data-feather="chevron-down" class="h-4 w-4 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
                        </span>
                    </summary>

                    <dl class="grid gap-3 border-t border-rt-border/70 p-4 sm:grid-cols-2 sm:gap-4 sm:p-6 lg:grid-cols-3 dark:border-rt-dark-border/70">
                        @foreach ($systemFacts as $fact)
                            <div class="rounded-xl bg-rt-surface-muted/65 px-3.5 py-3 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                                    {{ $fact['label'] }}
                                </dt>
                                <dd class="mt-1 min-w-0 break-words text-sm font-semibold text-rt-text dark:text-white">
                                    {{ $fact['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            </section>
        @endif
    </x-ui.page>
</div>
