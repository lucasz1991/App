@section('title', __('app.dashboard'))

@php
    // Jede Zahl steht genau EINMAL auf dieser Seite. Die Aufteilung:
    //   Kopf        -> was gerade laeuft (Online, Ungelesen, Einladungen)
    //   Einsatz     -> die vier Arbeitsbereiche mit ihrer Leitzahl
    //   Belegschaft -> alles zum Personalbestand, gebuendelt in einer Karte
    //   Entwicklung -> Zeitreihen
    //   Personen    -> die beiden Personenlisten nebeneinander
    //   System      -> zugeklappt, weil sich diese Werte praktisch nie aendern
    $workforceActiveRate = max(0, min(100, (int) $workforce['activeRate']));
    $chartConfig = array_merge($charts, [
        // Der Ring zeigt bewusst nur produktive Mitarbeiterkonten. Die
        // globale Kontenentwicklung bleibt davon getrennt.
        'status' => $workforce['status'],
        'labels' => [
            'total' => __('app.total'),
            'registrations' => __('app.registrations'),
            'accounts' => __('app.employees'),
            'activity' => __('app.active_users'),
            'average' => __('app.chart_average'),
            'peak' => __('app.chart_peak'),
        ],
    ]);
    $operationalModulesBySlug = collect($operationalModules)->keyBy('slug');
    $ordersModule = $operationalModulesBySlug->get('orders');
    $shiftsModule = $operationalModulesBySlug->get('shift-management');
    $secondaryModules = $operationalModulesBySlug->only(['calendar', 'customers']);

    // Lesehilfen fuer die Diagrammkoepfe. Beide Reihen liegen bereits im
    // Render-Payload — hier wird ausschliesslich zusammengefasst, es kommt
    // keine zusaetzliche Abfrage hinzu.
    $activityValues = $charts['activity']['values'] ?? [];
    $activityPeak = $activityValues ? max($activityValues) : 0;
    $activityAverage = $activityValues ? (int) round(array_sum($activityValues) / count($activityValues)) : 0;
    $registrationSum = array_sum($charts['userGrowth']['registrations'] ?? []);
    $growthTotals = $charts['userGrowth']['totals'] ?? [];
    $growthCurrent = $growthTotals ? (int) end($growthTotals) : 0;
    $failedJobs = (int) ($system['failedJobs'] ?? 0);
@endphp

<x-ui.page :auto-intro="false">
    {{-- Beim allerersten Besuch startet das Intro automatisch. Danach bleibt
         derselbe Dialog unsichtbar im DOM und kann ueber den Info-Knopf im
         Kopf jederzeit erneut geoeffnet werden. --}}
    <x-ui.welcome-intro
        :initially-open="\App\Support\PageViews::firstVisit(auth()->user(), 'intro:welcome')"
    />

    <div
        class="grid min-w-0 gap-5 sm:gap-6"
        x-data="adminDashboardCharts(@js($chartConfig))"
        data-admin-dashboard
    >
        {{-- 1 · KOPF ---------------------------------------------------------
             Titel und Tageslage. Bewusst ohne Aktionsknoepfe: jede Aktion
             steht weiter unten in ihrem fachlichen Zusammenhang und zusaetzlich
             dauerhaft in der Seitennavigation. --}}
        <section class="rt-admin-hero relative overflow-hidden rounded-[1.4rem] px-4 py-4 text-rt-text shadow-rt-md sm:px-6 sm:py-5 lg:px-7 dark:text-white" data-dashboard-segment="hero">
            <svg class="pointer-events-none absolute -right-20 bottom-0 h-full w-[58%] opacity-70" viewBox="0 0 720 360" fill="none" aria-hidden="true" data-rt-route>
                <path class="rt-admin-route-bed" data-rt-route-bed d="M42 306C130 276 132 191 220 176C314 160 338 263 431 233C515 205 501 105 680 58" stroke-width="34" stroke-linecap="round" />
                <path class="rt-admin-route-line" data-rt-route-line d="M42 306C130 276 132 191 220 176C314 160 338 263 431 233C515 205 501 105 680 58" stroke="#e4002b" stroke-width="3" stroke-linecap="round" />
                <circle class="rt-admin-signal rt-admin-signal-neutral" cx="220" cy="176" r="8" />
                <circle class="rt-admin-signal" cx="431" cy="233" r="8" fill="#e4002b" style="animation-delay:.7s" />
                <circle class="rt-admin-route-end" cx="680" cy="58" r="5" />
                <circle class="rt-admin-route-train" data-rt-route-train cx="0" cy="0" r="6" fill="#e4002b" opacity="0" />
            </svg>

            <div class="relative z-10 grid gap-3 sm:gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,30rem)] lg:items-center" data-dashboard-items>
                <div class="max-w-3xl">
                    <div class="mb-3 flex flex-wrap items-center gap-2.5">
                        <span class="rt-admin-hero-badge inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-2.5 py-1 text-[10px] font-semibold tracking-[0.08em] text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rt-red opacity-70"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-rt-red"></span>
                            </span>
                            {{ now()->translatedFormat('l, d. F Y') }}
                        </span>
                    </div>

                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red dark:text-rt-red-light">{{ __('app.administrator_team') }}</p>
                    <h1 class="mt-1.5 max-w-2xl text-2xl font-semibold leading-tight tracking-[-0.04em] text-rt-text sm:text-3xl lg:text-4xl dark:text-white">
                        {{ __('app.admin_control_center') }}
                    </h1>
                    <p class="rt-admin-hero-copy mt-2 max-w-2xl text-xs leading-5 text-slate-600 sm:text-sm sm:leading-6 dark:text-slate-300">
                        {{ __('app.admin_dashboard_description') }}
                    </p>
                </div>

                <aside class="rt-admin-live-card w-full rounded-xl border border-slate-300 bg-white p-3.5 shadow-[0_16px_36px_-28px_rgba(15,23,42,.32)] lg:justify-self-end dark:border-slate-600 dark:bg-rt-dark-surface dark:shadow-[0_16px_36px_-24px_rgba(0,0,0,.85)]" aria-label="{{ __('app.live_operations') }}" data-rt-glow>
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">{{ __('app.live_operations') }}</p>
                            {{-- Ohne Systemrechte gibt es hier nichts Belastbares
                                 zu melden — dann bleibt die Zeile weg, statt ein
                                 nichtssagendes Wort anzuzeigen. --}}
                            @if ($canViewSystemData)
                                <p class="mt-1 truncate text-sm font-semibold {{ $failedJobs > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-rt-text dark:text-white' }}">
                                    {{ $failedJobs > 0 ? __('app.jobs_failed', ['count' => $failedJobs]) : __('app.system_ready') }}
                                </p>
                            @endif
                        </div>
                        <span class="flex shrink-0 items-center gap-1.5">
                            <button
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 bg-white text-slate-500 shadow-rt-xs transition duration-200 hover:-translate-y-0.5 hover:border-rt-red hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-rt-red-light dark:hover:text-rt-red-light"
                                x-on:click="$dispatch('rt-welcome:open')"
                                aria-label="{{ __('app.welcome_intro_reopen') }}"
                                title="{{ __('app.welcome_intro_reopen') }}"
                                data-welcome-intro-trigger
                            >
                                <i data-feather="info" class="h-4 w-4"></i>
                            </button>
                            <span class="rt-admin-live-icon flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 bg-slate-100 text-rt-red dark:border-slate-600 dark:bg-slate-800 dark:text-rt-red-light">
                                <i data-feather="activity" class="h-4 w-4"></i>
                            </span>
                        </span>
                    </div>

                    <dl class="rt-admin-live-stats mt-3 grid grid-cols-3 divide-x divide-slate-200 dark:divide-slate-700">
                        <div class="min-w-0 pr-1.5 sm:pr-3">
                            <dt class="text-pretty text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ __('app.online_now') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $operations['online'] }}">{{ $operations['online'] }}</dd>
                        </div>
                        <div class="min-w-0 px-1.5 sm:px-3">
                            <dt class="text-pretty text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ __('app.open_invitations') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $operations['openInvitations'] }}">{{ $operations['openInvitations'] }}</dd>
                        </div>
                        <div class="min-w-0 pl-1.5 sm:pl-3">
                            <dt class="text-pretty text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ __('app.unread_messages_total') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $operations['unreadTotal'] }}">{{ $operations['unreadTotal'] }}</dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </section>

        {{-- 2 · EINSATZ ------------------------------------------------------
             Alle operativen Einstiege an einem Ort — genau die fuenf, die auch
             in der Seitennavigation unter "Betrieb" stehen. Aufträge, Schichten
             und Wagenlisten als Karten, Kalender und Kunden als Randlinks. --}}
        <section
            class="rt-admin-operations-stage relative overflow-hidden rounded-[1.4rem]"
            aria-labelledby="operational-workspace-heading"
            data-dashboard-segment="operations"
        >
            <div class="pointer-events-none absolute inset-y-0 right-0 hidden w-2/5 opacity-40 sm:block" aria-hidden="true">
                <svg class="h-full w-full" viewBox="0 0 420 170" fill="none" preserveAspectRatio="none">
                    <path d="M8 150C98 142 111 62 205 73C292 83 310 20 412 16" stroke="currentColor" class="text-slate-200 dark:text-slate-700" stroke-width="18" stroke-linecap="round"/>
                    <path d="M8 150C98 142 111 62 205 73C292 83 310 20 412 16" stroke="#e4002b" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>

            <header class="relative z-10 flex flex-wrap items-start justify-between gap-3 px-4 pb-3 pt-4 sm:px-5 sm:pb-4 sm:pt-5" data-dashboard-item>
                <div class="max-w-2xl">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red">{{ __('app.operational_control') }}</p>
                    <h2 id="operational-workspace-heading" class="mt-1.5 text-xl font-semibold tracking-[-0.025em] text-rt-text dark:text-white">{{ __('app.operations_workspace_title') }}</h2>
                    <p class="mt-1 max-w-2xl text-xs leading-5 text-rt-muted sm:text-sm dark:text-rt-dark-muted">{{ __('app.operations_workspace_description') }}</p>
                </div>
                <span class="relative z-10 flex flex-wrap items-center justify-end gap-2" data-dashboard-data-legend>
                    <span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50/95 px-3 py-2 text-[11px] font-semibold text-emerald-800 shadow-rt-xs backdrop-blur dark:border-emerald-700/70 dark:bg-emerald-950/80 dark:text-emerald-200" data-dashboard-live-source>
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                        {{ __('app.live_operations') }}
                    </span>
                </span>
            </header>

            <div class="rt-admin-operations-grid relative z-10 grid gap-3 px-3 pb-3 sm:gap-4 sm:px-4 sm:pb-4 md:grid-cols-2 xl:grid-cols-12" data-operational-workspace data-dashboard-items>
                @if ($ordersModule)
                    <x-ui.dashboard.focus-card
                        class="rt-admin-operations-card xl:col-span-4"
                        :href="route('admin.operations.preview', ['module' => 'orders'])"
                        icon="clipboard"
                        tone="warning"
                        :metric="$ordersModule['metric']"
                        :metric-label="$ordersModule['metric_label']"
                        :title="$ordersModule['title']"
                        :description="$ordersModule['description']"
                        :badge="$ordersModule['badge']"
                        :action-label="__('app.open')"
                        data-rt-glow
                    />
                @endif

                @if ($shiftsModule)
                    <x-ui.dashboard.focus-card
                        class="rt-admin-operations-card xl:col-span-4"
                        :href="route('admin.operations.preview', ['module' => 'shift-management'])"
                        icon="clock"
                        tone="success"
                        :metric="$shiftsModule['metric']"
                        :metric-label="$shiftsModule['metric_label']"
                        :title="$shiftsModule['title']"
                        :description="$shiftsModule['description']"
                        :badge="$shiftsModule['badge']"
                        :action-label="__('app.open')"
                        data-rt-glow
                    />
                @endif

                {{-- Die Wagenliste stand bisher nur als Knopf im Kopf und noch
                     einmal in den Schnellzugriffen. Sie gehoert fachlich hierher
                     — damit deckt sich der Block mit dem Menuepunkt "Betrieb". --}}
                <x-ui.dashboard.focus-card
                    class="rt-admin-operations-card md:col-span-2 xl:col-span-4"
                    :href="route('admin.operations.wagon-list')"
                    icon="list"
                    tone="brand"
                    :title="__('app.wagon_list')"
                    :description="__('app.help_topic_wagon_description')"
                    :badge="__('app.wagon_lists_available')"
                    :action-label="__('app.open_wagon_list')"
                    data-rt-glow
                />
            </div>

            @if ($secondaryModules->isNotEmpty())
                <nav class="relative z-10 flex flex-wrap gap-2 border-t border-slate-200/80 px-4 py-3 dark:border-slate-700/80" aria-label="{{ __('app.preview_modules') }}">
                    @foreach ($secondaryModules as $module)
                        <a
                            href="{{ route('admin.operations.preview', ['module' => $module['slug']]) }}"
                            wire:navigate
                            data-dashboard-operations-link
                            class="inline-flex min-h-10 flex-1 items-center justify-between gap-3 rounded-xl bg-white/75 px-3 py-2 text-xs font-semibold text-rt-muted ring-1 ring-inset ring-slate-200 transition hover:bg-white hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red sm:flex-none dark:bg-slate-900/70 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-900 dark:hover:text-rt-red-light"
                        >
                            <span class="inline-flex items-center gap-2">
                                <i data-feather="{{ $module['icon'] }}" class="h-3.5 w-3.5" aria-hidden="true"></i>
                                {{ $module['title'] }}
                            </span>
                            <span class="inline-flex items-center gap-2">
                                <span class="tabular-nums">{{ $module['metric'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </nav>
            @endif
        </section>

        {{-- 3 · BELEGSCHAFT --------------------------------------------------
             Ersetzt fuenf frueher verstreute Darstellungen derselben Zahlen
             (Fokuskarte, drei KPI-Kacheln und die Ringkarte) durch eine Karte:
             Ring links, die vier Werte rechts, ein Link nach unten rechts. --}}
        <section
            class="rt-admin-panel overflow-hidden rounded-2xl"
            aria-labelledby="workforce-heading"
            data-dashboard-segment="workforce"
            data-dashboard-chart="workforce-status"
            data-rt-glow
        >
            <header class="rt-admin-chart-head flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5" data-dashboard-item>
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.employees') }}</p>
                    <h2 id="workforce-heading" class="mt-1 text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-white">{{ __('app.workforce_overview') }}</h2>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <span class="rt-admin-stat-chip">
                        <span class="rt-admin-stat-chip-label">{{ __('app.active_rate') }}</span>
                        <span class="rt-admin-stat-chip-value">{{ $workforceActiveRate }}%</span>
                    </span>
                    @can('employees.view')
                        <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                            {{ __('app.manage_employees') }}
                            <i data-feather="arrow-up-right" class="h-4 w-4"></i>
                        </a>
                    @endcan
                </div>
            </header>

            <div class="grid items-center gap-2 px-2 pb-3 sm:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] sm:px-3">
                <div class="rt-admin-chart h-[188px] sm:h-[212px]" x-ref="statusChart" aria-label="{{ __('app.workforce_overview') }}"></div>

                <dl class="grid grid-cols-2 gap-2.5 px-2 pb-1 sm:gap-3 sm:pb-0" data-dashboard-kpis data-dashboard-workforce-kpis data-dashboard-items>
                    <div class="rt-admin-kpi rt-admin-panel rt-admin-panel-accent min-w-0 overflow-hidden rounded-[1.15rem] p-3">
                        <span class="rt-admin-kpi-icon flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-rt-red dark:border-slate-700 dark:bg-slate-800"><i data-feather="users" class="h-3.5 w-3.5"></i></span>
                        <dt class="mt-2.5 text-pretty text-[10px] leading-4 text-rt-muted sm:text-xs dark:text-rt-dark-muted">{{ __('app.employees') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tracking-[-0.035em] tabular-nums text-rt-text sm:text-2xl dark:text-white" data-dashboard-count="{{ $workforce['total'] }}">{{ number_format($workforce['total'], 0, ',', '.') }}</dd>
                    </div>

                    <div class="rt-admin-kpi rt-admin-panel min-w-0 overflow-hidden rounded-[1.15rem] p-3">
                        <span class="rt-admin-kpi-icon flex h-8 w-8 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><i data-feather="user-check" class="h-3.5 w-3.5"></i></span>
                        <dt class="mt-2.5 text-pretty text-[10px] leading-4 text-rt-muted sm:text-xs dark:text-rt-dark-muted">{{ __('app.active_users') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text sm:text-2xl dark:text-white" data-dashboard-count="{{ $workforce['active'] }}">{{ number_format($workforce['active'], 0, ',', '.') }}</dd>
                        <div class="rt-admin-kpi-track mt-2 h-1 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                            <div
                                class="rt-admin-kpi-meter h-full rounded-full bg-emerald-500"
                                data-dashboard-progress="{{ $workforceActiveRate }}"
                                style="transform: scaleX({{ $workforceActiveRate / 100 }}); transform-origin: left center;"
                            ></div>
                        </div>
                    </div>

                    <div class="rt-admin-kpi rt-admin-panel min-w-0 overflow-hidden rounded-[1.15rem] p-3">
                        <span class="rt-admin-kpi-icon flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"><i data-feather="user-minus" class="h-3.5 w-3.5"></i></span>
                        <dt class="mt-2.5 text-pretty text-[10px] leading-4 text-rt-muted sm:text-xs dark:text-rt-dark-muted">{{ __('app.inactive_users') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text sm:text-2xl dark:text-white" data-dashboard-count="{{ $workforce['inactive'] }}">{{ number_format($workforce['inactive'], 0, ',', '.') }}</dd>
                    </div>

                    <div class="rt-admin-kpi rt-admin-panel min-w-0 overflow-hidden rounded-[1.15rem] p-3">
                        <span class="rt-admin-kpi-icon flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i data-feather="shield" class="h-3.5 w-3.5"></i></span>
                        <dt class="mt-2.5 text-pretty text-[10px] leading-4 text-rt-muted sm:text-xs dark:text-rt-dark-muted">{{ __('app.teams_rbac') }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text sm:text-2xl dark:text-white" data-dashboard-count="{{ $totalTeams }}">{{ number_format($totalTeams, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        {{-- 4 · ENTWICKLUNG --------------------------------------------------
             Zwei Zeitreihen nebeneinander statt untereinander. Der Ring ist
             hier bewusst nicht mehr dabei — er gehoert zur Belegschaft. --}}
        <section class="grid gap-3 sm:gap-4 md:grid-cols-12" aria-label="{{ __('app.user_growth') }}" data-dashboard-segment="charts" data-dashboard-items>
            <article class="rt-admin-panel rt-admin-chart-card overflow-hidden rounded-2xl md:col-span-12 xl:col-span-6" data-dashboard-chart="user-growth" data-rt-glow>
                <header class="rt-admin-chart-head flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.last_14_days') }}</p>
                        <h2 class="mt-1 text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-white">{{ __('app.user_growth') }}</h2>
                    </div>
                    <div class="flex shrink-0 items-stretch gap-2">
                        <span class="rt-admin-stat-chip">
                            <span class="rt-admin-stat-chip-label">{{ __('app.total') }}</span>
                            <span class="rt-admin-stat-chip-value" data-dashboard-count="{{ $growthCurrent }}">{{ number_format($growthCurrent, 0, ',', '.') }}</span>
                        </span>
                        <span class="rt-admin-stat-chip rt-admin-stat-chip-brand">
                            <span class="rt-admin-stat-chip-label">{{ __('app.registrations') }}</span>
                            <span class="rt-admin-stat-chip-value">+{{ number_format($registrationSum, 0, ',', '.') }}</span>
                        </span>
                    </div>
                </header>
                <div class="rt-admin-chart mt-1 h-[236px] px-1 sm:h-[252px] sm:px-2" x-ref="growthChart" aria-label="{{ __('app.user_growth') }}"></div>
                <footer class="rt-admin-chart-legend flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pb-3.5 text-[11px] font-medium text-rt-muted sm:px-5 dark:text-rt-dark-muted">
                    <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 rounded-full bg-rt-text dark:bg-white"></span>{{ __('app.total') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="h-3 w-1.5 rounded-sm bg-rt-red"></span>{{ __('app.registrations') }}</span>
                    <span class="inline-flex items-center gap-2"><span class="rt-admin-legend-dash"></span>{{ __('app.chart_average') }}</span>
                </footer>
            </article>

            <article class="rt-admin-panel rt-admin-chart-card overflow-hidden rounded-2xl md:col-span-12 xl:col-span-6" data-dashboard-chart="activity" data-rt-glow>
                <header class="rt-admin-chart-head flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5">
                    <div class="min-w-0">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.last_14_days') }}</p>
                        <h2 class="mt-1 text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-white">{{ __('app.activity_trend') }}</h2>
                    </div>
                    <div class="flex shrink-0 items-stretch gap-2">
                        <span class="rt-admin-stat-chip">
                            <span class="rt-admin-stat-chip-label">{{ __('app.chart_average') }}</span>
                            <span class="rt-admin-stat-chip-value">Ø {{ number_format($activityAverage, 0, ',', '.') }}</span>
                        </span>
                        <span class="rt-admin-stat-chip rt-admin-stat-chip-brand">
                            <span class="rt-admin-stat-chip-label">{{ __('app.chart_peak') }}</span>
                            <span class="rt-admin-stat-chip-value">{{ number_format($activityPeak, 0, ',', '.') }}</span>
                        </span>
                    </div>
                </header>
                <div class="rt-admin-chart mt-1 h-[236px] px-1 pb-2 sm:h-[252px] sm:px-2" x-ref="activityChart" aria-label="{{ __('app.activity_trend') }}"></div>
            </article>
        </section>

        {{-- 5 · PERSONEN -----------------------------------------------------
             Beide Personenlisten standen bisher in getrennten Abschnitten mit
             einem Diagrammblock dazwischen. Sie zeigen dieselbe Art von Objekt
             und gehoeren nebeneinander. --}}
        <section class="grid gap-3 sm:gap-4 md:grid-cols-12" aria-label="{{ __('app.accounts') }}" data-dashboard-segment="people" data-dashboard-items>
            <article class="rt-admin-panel overflow-hidden rounded-2xl md:col-span-7">
                <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3.5 sm:px-5 dark:border-slate-700">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.accounts') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.recent_users') }}</h2>
                    </div>
                    @can('employees.view')
                        <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                            {{ __('app.show_all') }}
                            <i data-feather="arrow-up-right" class="h-4 w-4"></i>
                        </a>
                    @endcan
                </header>
                <div class="rt-admin-list-grid grid gap-px bg-slate-200 dark:bg-slate-700">
                    @forelse ($recentUsers as $user)
                        <a href="{{ route('admin.user-profile', $user->id) }}" wire:navigate class="rt-admin-user-row group grid min-w-0 grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1 bg-rt-surface px-4 py-3 transition duration-200 hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red sm:flex sm:px-5 dark:bg-rt-dark-surface dark:hover:bg-rt-dark-surface-muted">
                            <span class="rt-admin-user-avatar row-span-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-rt-text transition duration-200 group-hover:border-rt-red group-hover:bg-rt-red group-hover:text-white sm:row-auto dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $user->name }}</span>
                                <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $user->email }}</span>
                            </span>
                            <span class="col-start-2 flex min-w-0 items-center gap-2 text-left sm:col-auto sm:block sm:shrink-0 sm:text-right">
                                <span class="block text-[10px] font-semibold {{ $user->status ? 'text-emerald-600 dark:text-emerald-400' : 'text-rt-soft dark:text-rt-dark-soft' }}">{{ $user->status ? __('app.active') : __('app.inactive') }}</span>
                                <span class="block text-[10px] text-rt-soft sm:mt-1 dark:text-rt-dark-soft">{{ $user->created_at?->format('d.m.Y') }}</span>
                            </span>
                        </a>
                    @empty
                        <p class="bg-rt-surface px-6 py-8 text-sm text-rt-muted dark:bg-rt-dark-surface dark:text-rt-dark-muted">{{ __('app.no_users_yet') }}</p>
                    @endforelse
                </div>
            </article>

            <article class="rt-admin-panel rounded-2xl p-4 md:col-span-5">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.live_operations') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.recently_active') }}</h2>
                    </div>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><i data-feather="radio" class="h-4 w-4"></i></span>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($recentActivity as $entry)
                        <div class="grid grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0.5 rounded-xl px-2 py-2 transition hover:bg-rt-surface-muted sm:flex dark:hover:bg-rt-dark-surface-muted">
                            <span class="relative row-span-2 shrink-0 sm:row-auto">
                                <img src="{{ $entry['user']->profile_photo_url }}" alt="{{ $entry['user']->name }}" class="h-9 w-9 rounded-xl object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                                @if ($entry['lastSeen']->gte(now()->subMinutes(5)))
                                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-rt-surface dark:ring-rt-dark-surface"></span>
                                @endif
                            </span>
                            <span class="min-w-0 truncate text-sm font-semibold text-rt-text sm:flex-1 dark:text-white">{{ $entry['user']->name }}</span>
                            <time class="col-start-2 text-[11px] text-rt-soft sm:col-auto sm:shrink-0 dark:text-rt-dark-soft" datetime="{{ $entry['lastSeen']->toIso8601String() }}">{{ $entry['lastSeen']->diffForHumans() }}</time>
                        </div>
                    @empty
                        <p class="rounded-xl bg-rt-surface-muted px-4 py-6 text-sm text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ __('app.no_activity_yet') }}</p>
                    @endforelse
                </div>
            </article>
        </section>

        {{-- 6 · SYSTEM -------------------------------------------------------
             Neun Werte, die sich praktisch nie aendern. Sie bleiben erreichbar,
             belegen aber keine halbe Bildschirmhoehe mehr. Serverseitig nur
             fuer das Administratoren-Team bereitgestellt. --}}
        @if ($canViewSystemData && $system)
            <section class="rt-admin-panel overflow-hidden rounded-2xl" data-system-dashboard data-dashboard-segment="system">
                <details class="group">
                    {{-- <summary> darf nur Text und hoechstens EIN
                         Ueberschriftselement enthalten — deshalb <span> statt
                         <div>/<p> um die Begleittexte. --}}
                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3.5 sm:px-5 [&::-webkit-details-marker]:hidden">
                        <span class="block min-w-0">
                            <span class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.administrator_team') }}</span>
                            <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.technical_system_data') }}</h2>
                            <span class="mt-1 block text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.technical_system_data_description') }}</span>
                        </span>
                        <span class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-semibold text-rt-muted dark:border-slate-700 dark:bg-slate-800 dark:text-rt-dark-muted">
                            {{ __('app.show') }}
                            <i data-feather="chevron-down" class="h-4 w-4 transition-transform duration-200 group-open:rotate-180" aria-hidden="true"></i>
                        </span>
                    </summary>


                    <dl class="rt-admin-system-grid grid gap-px border-t border-slate-200 bg-slate-200 sm:grid-cols-2 lg:grid-cols-3 dark:border-slate-700 dark:bg-slate-700">
                        @foreach ([
                            [__('app.application'), $system['appVersion']],
                            [__('app.environment'), $system['environment']],
                            [__('app.php_version'), $system['php']],
                            [__('app.developer'), $system['developer']],
                            [__('app.database'), $system['database']],
                            [__('app.queue'), $system['queue']],
                            [__('app.file_storage'), $system['storage']],
                            [__('app.server_disk'), $system['disk']],
                            [__('app.last_activity'), $system['lastActivityAt']?->diffForHumans() ?? '—'],
                        ] as [$label, $value])
                            <div class="rt-admin-system-cell min-w-0 bg-rt-surface px-4 py-3 dark:bg-rt-dark-surface">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.12em] text-rt-soft dark:text-rt-dark-soft">{{ $label }}</dt>
                                <dd class="mt-1.5 break-words text-sm font-semibold text-rt-text dark:text-white" title="{{ $value }}">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </details>
            </section>
        @endif
    </div>
</x-ui.page>
