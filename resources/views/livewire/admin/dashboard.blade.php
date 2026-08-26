@section('title', __('app.dashboard'))

@php
    $workforceActiveRate = max(0, min(100, (int) $workforce['activeRate']));
    $chartConfig = array_merge($charts, [
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
    $calendarModule = $operationalModulesBySlug->get('calendar');
    $customersModule = $operationalModulesBySlug->get('customers');
    $attentionCount = (int) ($ordersModule['alert_count'] ?? 0) + (int) ($shiftsModule['alert_count'] ?? 0);

    // Alle Zusammenfassungen entstehen aus dem vorhandenen Render-Payload.
    // Fuer die UI werden dadurch keine weiteren Datenbankabfragen ausgefuehrt.
    $activityValues = $charts['activity']['values'] ?? [];
    $activityPeak = $activityValues ? max($activityValues) : 0;
    $activityAverage = $activityValues ? round(array_sum($activityValues) / count($activityValues), 1) : 0.0;
    $registrationSum = array_sum($charts['userGrowth']['registrations'] ?? []);
    $growthTotals = $charts['userGrowth']['totals'] ?? [];
    $growthCurrent = $growthTotals ? (int) end($growthTotals) : 0;
    $snapshotTime = now()->translatedFormat('H:i');
@endphp

<x-ui.page :auto-intro="false">
    <x-ui.welcome-intro
        :initially-open="\App\Support\PageViews::firstVisit(auth()->user(), 'intro:welcome')"
    />

    <div
        class="mx-auto grid w-full min-w-0 max-w-[1680px] gap-4 lg:gap-5"
        x-data="adminDashboardCharts(@js($chartConfig))"
        data-admin-dashboard
    >
        <h1 class="sr-only">{{ __('app.admin_control_center') }}</h1>

        {{-- 1 · BETRIEBSSTEUERUNG
             Kein Ersatz-Hero: eine kompakte Steuerleiste und darunter alle
             fuenf produktiven Betriebsziele in einem priorisierten Bento. --}}
        <section
            aria-labelledby="operational-workspace-heading"
            data-dashboard-segment="operations"
            data-dashboard-area="operations"
        >
            <header class="rt-admin-command-strip flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-700" data-dashboard-item>
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red">{{ __('app.operational_control') }}</p>
                    <h2 id="operational-workspace-heading" class="mt-1 text-xl font-semibold tracking-[-0.03em] text-rt-text dark:text-white">
                        {{ __('app.operations_workspace_title') }}
                    </h2>
                </div>

                <div class="flex w-full min-w-0 flex-wrap items-center justify-between gap-2 sm:w-auto sm:justify-end">
                    <span
                        @class([
                            'inline-flex min-h-9 items-center gap-2 rounded-full px-3 py-1.5 text-[11px] font-semibold ring-1 ring-inset',
                            'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/70 dark:text-emerald-200 dark:ring-emerald-800' => $attentionCount === 0,
                            'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/70 dark:text-amber-200 dark:ring-amber-800' => $attentionCount > 0,
                        ])
                        data-dashboard-attention-count="{{ $attentionCount }}"
                    >
                        <span @class(['h-1.5 w-1.5 rounded-full', 'bg-emerald-500' => $attentionCount === 0, 'bg-amber-500' => $attentionCount > 0]) aria-hidden="true"></span>
                        {{ trans_choice('app.operational_attention', $attentionCount, ['count' => $attentionCount]) }}
                    </span>

                    <span class="inline-flex min-h-9 items-center gap-2 rounded-full bg-slate-100 px-3 py-1.5 text-[11px] font-semibold text-rt-muted ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-rt-dark-muted dark:ring-slate-700" data-dashboard-live-source aria-label="{{ __('app.dashboard_snapshot', ['time' => $snapshotTime]) }}">
                        <i data-feather="clock" class="h-3.5 w-3.5" aria-hidden="true"></i>
                        <span class="hidden sm:inline">{{ __('app.dashboard_snapshot', ['time' => $snapshotTime]) }}</span>
                    </span>

                    <button
                        type="button"
                        wire:click="$refresh"
                        wire:loading.attr="disabled"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-500 shadow-rt-xs transition hover:border-rt-red hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 disabled:cursor-wait disabled:opacity-60 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-rt-red-light dark:hover:text-rt-red-light"
                        aria-label="{{ __('app.refresh_dashboard') }}"
                        title="{{ __('app.refresh_dashboard') }}"
                        data-dashboard-action="refresh"
                    >
                        <span class="inline-flex" wire:loading.class="animate-spin" wire:target="$refresh" aria-hidden="true">
                            <i data-feather="refresh-cw" class="h-4 w-4"></i>
                        </span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-500 shadow-rt-xs transition hover:border-rt-red hover:text-rt-red focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-rt-red-light dark:hover:text-rt-red-light"
                        x-on:click="$dispatch('rt-welcome:open')"
                        aria-label="{{ __('app.welcome_intro_reopen') }}"
                        title="{{ __('app.welcome_intro_reopen') }}"
                        data-welcome-intro-trigger
                        data-dashboard-action="intro"
                    >
                        <i data-feather="info" class="h-4 w-4" aria-hidden="true"></i>
                    </button>
                </div>
            </header>

            <div class="rt-admin-module-bento mt-3 grid grid-cols-1 gap-3 lg:grid-cols-6 xl:grid-cols-12" data-operational-workspace data-dashboard-items>
                @if ($ordersModule)
                    <x-ui.dashboard.focus-card
                        class="rt-admin-module-tile rt-admin-module-tile-orders lg:col-span-3 xl:col-span-5 xl:row-span-2"
                        :href="route('admin.operations.preview', ['module' => 'orders'])"
                        :icon="$ordersModule['icon']"
                        :tone="$ordersModule['tone']"
                        variant="featured"
                        :metric="$ordersModule['metric']"
                        :metric-label="$ordersModule['metric_label']"
                        :title="$ordersModule['title']"
                        :description="$ordersModule['description']"
                        :badge="$ordersModule['badge']"
                        :action-label="__('app.open')"
                        data-dashboard-action="orders"
                        data-rt-glow
                    />
                @endif

                @if ($shiftsModule)
                    <x-ui.dashboard.focus-card
                        class="rt-admin-module-tile rt-admin-module-tile-shifts lg:col-span-3 xl:col-span-4"
                        :href="route('admin.operations.preview', ['module' => 'shift-management'])"
                        :icon="$shiftsModule['icon']"
                        :tone="$shiftsModule['tone']"
                        variant="compact"
                        :metric="$shiftsModule['metric']"
                        :metric-label="$shiftsModule['metric_label']"
                        :title="$shiftsModule['title']"
                        :badge="$shiftsModule['badge']"
                        :action-label="__('app.open')"
                        data-dashboard-action="shifts"
                        data-rt-glow
                    />
                @endif

                <x-ui.dashboard.focus-card
                    class="rt-admin-module-tile rt-admin-module-tile-wagons lg:col-span-2 xl:col-span-3"
                    :href="route('admin.operations.wagon-list')"
                    icon="list"
                    tone="brand"
                    variant="compact"
                    :title="__('app.wagon_list')"
                    :description="__('app.help_topic_wagon_description')"
                    :badge="__('app.wagon_lists_available')"
                    :action-label="__('app.open_wagon_list')"
                    data-dashboard-action="wagon-list"
                    data-rt-glow
                />

                @if ($calendarModule)
                    <x-ui.dashboard.focus-card
                        class="rt-admin-module-tile rt-admin-module-tile-calendar lg:col-span-2 xl:col-span-4"
                        :href="route('admin.operations.preview', ['module' => 'calendar'])"
                        :icon="$calendarModule['icon']"
                        :tone="$calendarModule['tone']"
                        variant="compact"
                        :metric="$calendarModule['metric']"
                        :metric-label="$calendarModule['metric_label']"
                        :title="$calendarModule['title']"
                        :badge="$calendarModule['badge']"
                        :action-label="__('app.open')"
                        data-dashboard-action="calendar"
                        data-rt-glow
                    />
                @endif

                @if ($customersModule)
                    <x-ui.dashboard.focus-card
                        class="rt-admin-module-tile rt-admin-module-tile-customers lg:col-span-2 xl:col-span-3"
                        :href="route('admin.operations.preview', ['module' => 'customers'])"
                        :icon="$customersModule['icon']"
                        :tone="$customersModule['tone']"
                        variant="compact"
                        :metric="$customersModule['metric']"
                        :metric-label="$customersModule['metric_label']"
                        :title="$customersModule['title']"
                        :badge="$customersModule['badge']"
                        :action-label="__('app.open')"
                        data-dashboard-action="customers"
                        data-rt-glow
                    />
                @endif
            </div>
        </section>

        {{-- 2 · BELEGSCHAFT & AKTIVITAET
             Kontostand und reale Nutzung stehen jetzt nebeneinander. Die vier
             Personalwerte sind flache Zellen statt vier weiterer Karten. --}}
        <section
            aria-labelledby="workforce-activity-heading"
            data-dashboard-segment="workforce-activity"
            data-dashboard-area="workforce-activity"
        >
            <header class="mb-3 flex items-end justify-between gap-3" data-dashboard-item>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red">{{ __('app.employees') }}</p>
                    <h2 id="workforce-activity-heading" class="mt-1 text-xl font-semibold tracking-[-0.03em] text-rt-text dark:text-white">{{ __('app.workforce_activity_title') }}</h2>
                </div>
            </header>

            <div class="rt-admin-split-grid grid gap-3 xl:grid-cols-12" data-dashboard-items>
                <article class="rt-admin-panel rt-admin-span-5 overflow-hidden rounded-[1.5rem] xl:col-span-5" data-dashboard-chart="workforce-status">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 sm:px-5 dark:border-slate-700">
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.employee_accounts') }}</h3>
                            <p class="mt-1 max-w-md text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ __('app.employee_accounts_description') }}</p>
                        </div>
                        @can('employees.view')
                            <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/30" data-dashboard-action="employees-manage">
                                {{ __('app.manage_employees') }}
                                <i data-feather="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                            </a>
                        @endcan
                    </header>

                    <div class="grid items-center gap-1 p-3 sm:grid-cols-[11rem_minmax(0,1fr)] sm:p-4 xl:grid-cols-1 2xl:grid-cols-[11rem_minmax(0,1fr)]">
                        <div class="rt-admin-chart h-[172px]" x-ref="statusChart" role="img" aria-label="{{ __('app.workforce_overview') }}" wire:ignore></div>

                        <dl class="grid grid-cols-2 overflow-hidden rounded-xl border border-slate-200 bg-slate-200 dark:border-slate-700 dark:bg-slate-700" data-dashboard-kpis data-dashboard-workforce-kpis>
                            <div class="min-w-0 bg-white p-3 dark:bg-slate-900">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.employees') }}</dt>
                                <dd class="mt-1 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-rt-text dark:text-white" data-dashboard-count="{{ $workforce['total'] }}">{{ number_format($workforce['total'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="min-w-0 bg-white p-3 dark:bg-slate-900">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-emerald-700 dark:text-emerald-300">{{ __('app.enabled_accounts') }}</dt>
                                <dd class="mt-1 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-rt-text dark:text-white" data-dashboard-count="{{ $workforce['active'] }}">{{ number_format($workforce['active'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="min-w-0 bg-white p-3 dark:bg-slate-900">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.disabled_accounts') }}</dt>
                                <dd class="mt-1 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-rt-text dark:text-white" data-dashboard-count="{{ $workforce['inactive'] }}">{{ number_format($workforce['inactive'], 0, ',', '.') }}</dd>
                            </div>
                            <div class="min-w-0 bg-white p-3 dark:bg-slate-900">
                                <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.teams_rbac') }}</dt>
                                <dd class="mt-1 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-rt-text dark:text-white" data-dashboard-count="{{ $totalTeams }}">{{ number_format($totalTeams, 0, ',', '.') }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="border-t border-slate-200 px-4 py-3 sm:px-5 dark:border-slate-700">
                        <div class="flex items-center justify-between gap-3 text-xs font-semibold">
                            <span class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.enabled_rate') }}</span>
                            <span class="tabular-nums text-rt-text dark:text-white">{{ $workforceActiveRate }}%</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div
                                class="rt-admin-kpi-meter h-full rounded-full bg-emerald-500"
                                data-dashboard-progress="{{ $workforceActiveRate }}"
                                style="transform: scaleX({{ $workforceActiveRate / 100 }}); transform-origin: left center;"
                            ></div>
                        </div>
                    </div>
                </article>

                <article class="rt-admin-panel rt-admin-span-7 overflow-hidden rounded-[1.5rem] xl:col-span-7" data-dashboard-chart="activity">
                    <header class="flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5">
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.last_14_days') }}</p>
                            <h3 class="mt-1 text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-white">{{ __('app.activity_trend') }}</h3>
                        </div>
                        <div class="flex shrink-0 items-stretch gap-2">
                            <span class="rt-admin-stat-chip">
                                <span class="rt-admin-stat-chip-label">{{ __('app.chart_average') }}</span>
                                <span class="rt-admin-stat-chip-value">Ø {{ number_format($activityAverage, 1, ',', '.') }}</span>
                            </span>
                            <span class="rt-admin-stat-chip rt-admin-stat-chip-brand">
                                <span class="rt-admin-stat-chip-label">{{ __('app.chart_peak') }}</span>
                                <span class="rt-admin-stat-chip-value">{{ number_format($activityPeak, 0, ',', '.') }}</span>
                            </span>
                        </div>
                    </header>

                    <div class="rt-admin-chart h-[220px] px-1 pb-2 sm:h-[240px] sm:px-2" x-ref="activityChart" role="img" aria-label="{{ __('app.activity_trend') }}" wire:ignore></div>
                    <p class="sr-only">{{ __('app.activity_chart_summary', ['average' => $activityAverage, 'peak' => $activityPeak]) }}</p>

                    <div class="border-t border-slate-200 px-4 py-3 sm:px-5 dark:border-slate-700">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-rt-text dark:text-white">{{ __('app.recently_active') }}</h4>
                            <i data-feather="radio" class="h-4 w-4 text-emerald-500" aria-hidden="true"></i>
                        </div>
                        <ul class="grid gap-1 sm:grid-cols-2" aria-label="{{ __('app.recently_active') }}">
                            @forelse ($recentActivity as $entry)
                                <li>
                                    <a href="{{ route('admin.user-profile', $entry['user']->id) }}" wire:navigate class="group grid min-h-11 grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 rounded-xl px-2 py-2 transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/30 dark:hover:bg-rt-dark-surface-muted" data-dashboard-action="activity-user-{{ $entry['user']->id }}">
                                        <span class="relative row-span-2 shrink-0">
                                            <img src="{{ $entry['user']->profile_photo_url }}" alt="" class="h-9 w-9 rounded-xl object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                                            @if ($entry['lastSeen']->gte(now()->subMinutes(5)))
                                                <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-slate-900" aria-hidden="true"></span>
                                                <span class="sr-only">{{ __('app.online') }}</span>
                                            @endif
                                        </span>
                                        <span class="min-w-0 truncate text-sm font-semibold text-rt-text dark:text-white">{{ $entry['user']->name }}</span>
                                        <time class="col-start-2 text-[11px] text-rt-soft dark:text-rt-dark-soft" datetime="{{ $entry['lastSeen']->toIso8601String() }}">{{ $entry['lastSeen']->diffForHumans() }}</time>
                                    </a>
                                </li>
                            @empty
                                <li class="sm:col-span-2">
                                    <p class="rounded-xl bg-rt-surface-muted px-4 py-6 text-sm text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ __('app.no_activity_yet') }}</p>
                                </li>
                            @endforelse
                        </ul>
                    </div>
                </article>
            </div>

            @can('devices.view')
                @if ($deviceSnapshot)
                    <x-ui.dashboard.device-management-widget
                        :stats="$deviceSnapshot"
                        :href="$deviceSnapshot['available'] ? route('admin.devices') : null"
                        data-dashboard-item
                    />
                @endif
            @endcan
        </section>

        {{-- 3 · KONTENENTWICKLUNG
             Zeitreihe und die dazugehoerigen neuen Konten bilden nun einen
             gemeinsamen Kontext statt zwei weit voneinander liegender Bloecke. --}}
        <section
            aria-labelledby="account-development-heading"
            data-dashboard-segment="accounts"
            data-dashboard-area="accounts"
        >
            <header class="mb-3" data-dashboard-item>
                <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red">{{ __('app.accounts') }}</p>
                <h2 id="account-development-heading" class="mt-1 text-xl font-semibold tracking-[-0.03em] text-rt-text dark:text-white">{{ __('app.account_development_title') }}</h2>
            </header>

            <div class="rt-admin-split-grid grid gap-3 xl:grid-cols-12" data-dashboard-items>
                <article class="rt-admin-panel rt-admin-span-7 overflow-hidden rounded-[1.5rem] xl:col-span-7" data-dashboard-chart="user-growth">
                    <header class="flex flex-wrap items-start justify-between gap-3 px-4 pt-4 sm:px-5">
                        <div class="min-w-0">
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.last_14_days') }}</p>
                            <h3 class="mt-1 text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-white">{{ __('app.user_growth') }}</h3>
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
                    <div class="rt-admin-chart h-[228px] px-1 sm:h-[248px] sm:px-2" x-ref="growthChart" role="img" aria-label="{{ __('app.user_growth') }}" wire:ignore></div>
                    <p class="sr-only">{{ __('app.growth_chart_summary', ['total' => $growthCurrent, 'registrations' => $registrationSum]) }}</p>
                    <footer class="rt-admin-chart-legend flex flex-wrap items-center gap-x-4 gap-y-2 px-4 pb-3.5 text-[11px] font-medium text-rt-muted sm:px-5 dark:text-rt-dark-muted">
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 rounded-full bg-rt-text dark:bg-white"></span>{{ __('app.total') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="h-3 w-1.5 rounded-sm bg-rt-red"></span>{{ __('app.registrations') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="rt-admin-legend-dash"></span>{{ __('app.chart_average') }}</span>
                    </footer>
                </article>

                <article class="rt-admin-panel rt-admin-span-5 overflow-hidden rounded-[1.5rem] xl:col-span-5">
                    <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3.5 sm:px-5 dark:border-slate-700">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.accounts') }}</p>
                            <h3 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.recent_users') }}</h3>
                        </div>
                        @can('employees.view')
                            <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/30" data-dashboard-action="employees-all">
                                {{ __('app.show_all') }}
                                <i data-feather="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                            </a>
                        @endcan
                    </header>

                    <ul class="divide-y divide-slate-200 dark:divide-slate-700" aria-label="{{ __('app.recent_users') }}">
                        @forelse ($recentUsers as $user)
                            <li>
                                <a href="{{ route('admin.user-profile', $user->id) }}" wire:navigate class="rt-admin-user-row group grid min-h-14 min-w-0 grid-cols-[2.25rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1 bg-white px-4 py-3 transition hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red sm:grid-cols-[2.25rem_minmax(0,1fr)_auto] sm:px-5 dark:bg-slate-900 dark:hover:bg-slate-800" data-dashboard-action="recent-user-{{ $user->id }}">
                                    <span class="rt-admin-user-avatar row-span-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-rt-text transition group-hover:border-rt-red group-hover:bg-rt-red group-hover:text-white sm:row-auto dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $user->name }}</span>
                                        <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $user->email }}</span>
                                    </span>
                                    <span class="col-start-2 flex min-w-0 items-center gap-2 sm:col-start-3 sm:block sm:text-right">
                                        <span class="block text-[10px] font-semibold {{ $user->status ? 'text-emerald-600 dark:text-emerald-400' : 'text-rt-soft dark:text-rt-dark-soft' }}">{{ $user->status ? __('app.active') : __('app.inactive') }}</span>
                                        <span class="block text-[10px] text-rt-soft sm:mt-1 dark:text-rt-dark-soft">{{ $user->created_at?->format('d.m.Y') }}</span>
                                    </span>
                                </a>
                            </li>
                        @empty
                            <li><p class="px-6 py-8 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_users_yet') }}</p></li>
                        @endforelse
                    </ul>
                </article>
            </div>
        </section>

        {{-- 4 · SYSTEM
             Geschuetzt, standardmaessig geschlossen und erst beim Oeffnen
             geladen. Die neun Werte sind in drei fachliche Gruppen gegliedert. --}}
        @if ($canViewSystemData)
            <section
                class="rt-admin-system-bar overflow-hidden rounded-[1.5rem] border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
                data-system-dashboard
                data-dashboard-segment="system"
                data-dashboard-area="system"
                x-data="{ open: false }"
            >
                <details x-bind:open="open" class="group">
                    <summary
                        class="flex min-h-16 cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-4 py-3.5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-rt-red sm:px-5 [&::-webkit-details-marker]:hidden"
                        x-on:click.prevent="open = !open; if (open) { $wire.loadSystemData() }"
                        x-bind:aria-expanded="open.toString()"
                        data-dashboard-action="system-toggle"
                    >
                        <span class="flex min-w-0 items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-950/70 dark:text-emerald-300 dark:ring-emerald-800" aria-hidden="true">
                                <i data-feather="shield" class="h-4 w-4"></i>
                            </span>
                            <span class="block min-w-0">
                                <span class="block text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.administrator_team') }}</span>
                                <h2 class="mt-0.5 text-base font-semibold text-rt-text dark:text-white">{{ __('app.technical_system_data') }}</h2>
                            </span>
                        </span>
                        <span class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-xl bg-slate-100 px-3 text-xs font-semibold text-rt-muted ring-1 ring-inset ring-slate-200 dark:bg-slate-800 dark:text-rt-dark-muted dark:ring-slate-700">
                            <span x-text="open ? @js(__('app.close')) : @js(__('app.show'))">{{ __('app.show') }}</span>
                            <svg class="h-4 w-4 transition-transform duration-200" x-bind:class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </span>
                    </summary>

                    <div x-show="open" x-collapse>
                        <div class="hidden min-h-28 items-center justify-center border-t border-slate-200 px-5 py-8 text-sm text-rt-muted dark:border-slate-700 dark:text-rt-dark-muted" wire:loading.flex wire:target="loadSystemData" data-dashboard-system-loading>
                            <span class="mr-2 h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent" aria-hidden="true"></span>
                            {{ __('app.loading_system_data') }}
                        </div>

                        @if ($systemLoaded && $system)
                            <div class="grid gap-px border-t border-slate-200 bg-slate-200 lg:grid-cols-3 dark:border-slate-700 dark:bg-slate-700" wire:loading.remove wire:target="loadSystemData">
                                @foreach ([
                                    [
                                        'title' => __('app.system_runtime'),
                                        'icon' => 'cpu',
                                        'items' => [
                                            [__('app.application'), $system['appVersion']],
                                            [__('app.environment'), $system['environment']],
                                            [__('app.php_version'), $system['php']],
                                        ],
                                    ],
                                    [
                                        'title' => __('app.system_data_jobs'),
                                        'icon' => 'database',
                                        'items' => [
                                            [__('app.database'), $system['database']],
                                            [__('app.queue'), $system['queue']],
                                            [__('app.last_activity'), $system['lastActivity']],
                                        ],
                                    ],
                                    [
                                        'title' => __('app.system_infrastructure'),
                                        'icon' => 'server',
                                        'items' => [
                                            [__('app.file_storage'), $system['storage']],
                                            [__('app.server_disk'), $system['disk']],
                                            [__('app.developer'), $system['developer']],
                                        ],
                                    ],
                                ] as $group)
                                    <article class="min-w-0 bg-white p-4 sm:p-5 dark:bg-slate-900">
                                        <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-white">
                                            <i data-feather="{{ $group['icon'] }}" class="h-4 w-4 text-rt-red" aria-hidden="true"></i>
                                            {{ $group['title'] }}
                                        </h3>
                                        <dl class="mt-3 space-y-3">
                                            @foreach ($group['items'] as [$label, $value])
                                                <div class="grid min-w-0 gap-0.5 sm:grid-cols-[7rem_minmax(0,1fr)] sm:gap-3">
                                                    <dt class="text-[10px] font-semibold uppercase tracking-[0.1em] text-rt-soft dark:text-rt-dark-soft">{{ $label }}</dt>
                                                    <dd class="break-words text-sm font-semibold text-rt-text sm:text-right dark:text-white" title="{{ $value }}">{{ $value }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>
            </section>
        @endif
    </div>
</x-ui.page>
