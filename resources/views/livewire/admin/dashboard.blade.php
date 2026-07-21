@section('title', __('app.dashboard'))

@php
    $activeRate = $totalUsers > 0 ? (int) round(($activeUsers / $totalUsers) * 100) : 0;
    $activeProgress = max(0, min(100, $activeRate));
    $chartConfig = array_merge($charts, [
        'labels' => [
            'total' => __('app.total'),
            'registrations' => __('app.registrations'),
            'accounts' => __('app.accounts'),
            'activity' => __('app.active_users'),
        ],
    ]);
    $previewToneClasses = [
        'red' => 'border-rose-200 bg-rose-50 text-rt-red dark:border-rose-700 dark:bg-rose-900 dark:text-rose-200',
        'amber' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-200',
        'blue' => 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-700 dark:bg-sky-900 dark:text-sky-200',
        'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900 dark:text-emerald-200',
    ];
@endphp

<x-ui.page>
    <div
        class="space-y-3 sm:space-y-4"
        x-data="adminDashboardCharts(@js($chartConfig))"
        data-admin-dashboard
    >
        {{-- Markanter Einstieg statt eines generischen Seitenkopfs. --}}
        <section class="rt-admin-hero relative overflow-hidden rounded-2xl px-4 py-4 text-rt-text shadow-rt-md sm:px-6 sm:py-5 lg:px-7 dark:text-white" data-dashboard-segment="hero">
            <svg class="pointer-events-none absolute -right-20 bottom-0 h-full w-[58%] opacity-70" viewBox="0 0 720 360" fill="none" aria-hidden="true">
                <path class="rt-admin-route-bed" d="M42 306C130 276 132 191 220 176C314 160 338 263 431 233C515 205 501 105 680 58" stroke-width="34" stroke-linecap="round" />
                <path class="rt-admin-route-line" d="M42 306C130 276 132 191 220 176C314 160 338 263 431 233C515 205 501 105 680 58" stroke="#e4002b" stroke-width="3" stroke-linecap="round" />
                <circle class="rt-admin-signal rt-admin-signal-neutral" cx="220" cy="176" r="8" />
                <circle class="rt-admin-signal" cx="431" cy="233" r="8" fill="#e4002b" style="animation-delay:.7s" />
                <circle class="rt-admin-route-end" cx="680" cy="58" r="5" />
            </svg>

            <div class="relative z-10 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(22rem,30rem)] lg:items-center" data-dashboard-items>
                <div class="max-w-3xl">
                    <div class="mb-3 flex flex-wrap items-center gap-2.5">
                        <span class="rt-admin-hero-badge inline-flex items-center gap-2 rounded-full border border-slate-300 bg-white px-2.5 py-1 text-[10px] font-semibold tracking-[0.13em] text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rt-red opacity-70"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-rt-red"></span>
                            </span>
                            {{ __('app.admin_control_center') }}
                        </span>
                        <span class="text-xs font-medium text-slate-500 dark:text-slate-300">{{ now()->translatedFormat('l, d. F Y') }}</span>
                    </div>

                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-red dark:text-rt-red-light">{{ __('app.administrator_team') }}</p>
                    <h1 class="mt-1.5 max-w-2xl text-2xl font-semibold leading-tight tracking-[-0.04em] text-rt-text sm:text-3xl lg:text-4xl dark:text-white">
                        {{ __('app.welcome_name', ['name' => auth()->user()->name]) }}
                    </h1>
                    <p class="rt-admin-hero-copy mt-2 max-w-2xl text-xs leading-5 text-slate-600 sm:text-sm sm:leading-6 dark:text-slate-300">
                        {{ __('app.admin_dashboard_description') }}
                    </p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @can('employees.view')
                            <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-rt-red px-3 py-2 text-xs font-semibold text-white shadow-[0_10px_24px_-12px_rgba(228,0,43,.8)] transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red-light active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-white/70">
                                <i data-feather="users" class="h-4 w-4"></i>
                                {{ __('app.manage_employees') }}
                            </a>
                        @endcan
                        @can('manage.messages')
                            <a href="{{ route('admin.messages') }}" wire:navigate data-dashboard-hero-secondary class="rt-ui-button rt-ui-button-secondary rt-admin-hero-secondary inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-rt-surface px-3 py-2 text-xs font-semibold text-slate-800 shadow-rt-xs transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:border-slate-400 hover:bg-slate-50 active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-rt-red/30 dark:border-slate-500 dark:bg-slate-800 dark:text-white dark:hover:border-slate-300 dark:hover:bg-slate-700">
                                <i data-feather="message-square" class="h-4 w-4"></i>
                                {{ __('app.messages') }}
                            </a>
                        @endcan
                    </div>
                </div>

                <aside class="rt-admin-live-card w-full rounded-xl border border-slate-300 bg-white p-3.5 shadow-[0_16px_36px_-28px_rgba(15,23,42,.32)] lg:justify-self-end dark:border-slate-600 dark:bg-[#111827] dark:shadow-[0_16px_36px_-24px_rgba(0,0,0,.85)]" aria-label="{{ __('app.live_operations') }}">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">{{ __('app.live_operations') }}</p>
                            <p class="mt-1 text-sm font-semibold text-rt-text dark:text-white">
                                @if ($canViewSystemData)
                                    {{ ($system['failedJobs'] ?? 0) > 0 ? __('app.jobs_failed', ['count' => $system['failedJobs']]) : __('app.system_ready') }}
                                @else
                                    {{ __('app.operations') }}
                                @endif
                            </p>
                        </div>
                        <span class="rt-admin-live-icon flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 bg-slate-100 text-rt-red dark:border-slate-600 dark:bg-slate-800 dark:text-rt-red-light">
                            <i data-feather="activity" class="h-4 w-4"></i>
                        </span>
                    </div>

                    <dl class="rt-admin-live-stats mt-3 grid grid-cols-3 divide-x divide-slate-200 dark:divide-slate-700">
                        <div class="pr-3">
                            <dt class="text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ __('app.online_now') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $operations['online'] }}">{{ $operations['online'] }}</dd>
                        </div>
                        <div class="px-3">
                            <dt class="text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ __('app.open_invitations') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $operations['openInvitations'] }}">{{ $operations['openInvitations'] }}</dd>
                        </div>
                        <div class="pl-3">
                            <dt class="text-[10px] leading-tight text-slate-500 dark:text-slate-400">{{ __('app.unread_messages_total') }}</dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $operations['unreadTotal'] }}">{{ $operations['unreadTotal'] }}</dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </section>

        {{-- Vier gleichwertige Kennzahlen in einer durchgehenden Zeile. --}}
        <section class="grid grid-cols-4 gap-1.5 sm:gap-2.5" aria-label="{{ __('app.dashboard') }}" data-dashboard-segment="kpis" data-dashboard-kpis data-dashboard-items>
            <article class="rt-admin-panel rt-admin-panel-accent group relative min-w-0 overflow-hidden rounded-xl p-2.5 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-md sm:p-3.5">
                <div class="flex items-center justify-between gap-1.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-rt-red dark:border-slate-700 dark:bg-slate-800"><i data-feather="users" class="h-3.5 w-3.5"></i></span>
                    <span class="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-1.5 py-1 text-[9px] font-semibold text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300 sm:text-[10px]">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                        {{ $activeRate }}%<span class="sr-only"> {{ __('app.active_rate') }}</span>
                    </span>
                </div>
                <p class="mt-2.5 truncate text-[10px] text-rt-muted dark:text-rt-dark-muted sm:text-xs">{{ __('app.total_users') }}</p>
                <p class="mt-1 text-xl font-semibold tracking-[-0.035em] tabular-nums text-rt-text dark:text-white sm:text-2xl" data-dashboard-count="{{ $totalUsers }}">{{ number_format($totalUsers, 0, ',', '.') }}</p>
                <div class="mt-2 h-1 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                    <div
                        class="h-full rounded-full bg-rt-red"
                        data-dashboard-progress="{{ $activeProgress }}"
                        style="transform: scaleX({{ $activeProgress / 100 }}); transform-origin: left center;"
                    ></div>
                </div>
            </article>

            <article class="rt-admin-panel min-w-0 rounded-xl p-2.5 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-md sm:p-3.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><i data-feather="user-check" class="h-3.5 w-3.5"></i></span>
                <p class="mt-2.5 truncate text-[10px] text-rt-muted dark:text-rt-dark-muted sm:text-xs">{{ __('app.active_users') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white sm:text-2xl" data-dashboard-count="{{ $activeUsers }}">{{ number_format($activeUsers, 0, ',', '.') }}</p>
            </article>

            <article class="rt-admin-panel min-w-0 rounded-xl p-2.5 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-md sm:p-3.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i data-feather="briefcase" class="h-3.5 w-3.5"></i></span>
                <p class="mt-2.5 truncate text-[10px] text-rt-muted dark:text-rt-dark-muted sm:text-xs">{{ __('app.employees') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white sm:text-2xl" data-dashboard-count="{{ $totalEmployees }}">{{ number_format($totalEmployees, 0, ',', '.') }}</p>
            </article>

            <article class="rt-admin-panel min-w-0 rounded-xl p-2.5 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:shadow-rt-md sm:p-3.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200"><i data-feather="layers" class="h-3.5 w-3.5"></i></span>
                <p class="mt-2.5 truncate text-[10px] text-rt-muted dark:text-rt-dark-muted sm:text-xs">{{ __('app.teams_rbac') }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white sm:text-2xl" data-dashboard-count="{{ $totalTeams }}">{{ number_format($totalTeams, 0, ',', '.') }}</p>
            </article>
        </section>

        {{-- Feine SVG-Diagramme mit Apache ECharts 6. --}}
        <section class="grid gap-3 lg:grid-cols-12" aria-label="{{ __('app.user_growth') }}" data-dashboard-segment="charts" data-dashboard-items>
            <article class="rt-admin-panel overflow-hidden rounded-2xl p-4 lg:col-span-8 xl:col-span-6">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.last_14_days') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.user_growth') }}</h2>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-x-4 gap-y-2 text-[11px] font-medium text-rt-muted dark:text-rt-dark-muted">
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 rounded-full bg-rt-text dark:bg-white"></span>{{ __('app.total') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="h-3 w-1.5 rounded-sm bg-rt-red"></span>{{ __('app.registrations') }}</span>
                        <strong class="rt-admin-chart-total rounded-md border border-slate-200 bg-slate-50 px-2 py-1 font-semibold text-rt-text dark:border-slate-700 dark:bg-slate-800 dark:text-white">+{{ array_sum($charts['userGrowth']['registrations']) }}</strong>
                    </div>
                </header>
                <div class="rt-admin-chart mt-2 h-[236px] sm:h-[252px]" x-ref="growthChart" aria-label="{{ __('app.user_growth') }}"></div>
            </article>

            <article class="rt-admin-panel rounded-2xl p-4 lg:col-span-4 xl:col-span-3">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.accounts') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.account_status') }}</h2>
                    </div>
                    <span class="text-xs font-semibold tabular-nums text-rt-muted dark:text-rt-dark-muted">{{ $activeRate }}%</span>
                </div>
                <div class="rt-admin-chart h-[132px]" x-ref="statusChart" aria-label="{{ __('app.account_status') }}"></div>
                <dl class="grid grid-cols-2 gap-2 border-t border-slate-200 pt-2.5 dark:border-slate-700">
                    <div>
                        <dt class="flex items-center gap-2 text-[10px] text-rt-muted dark:text-rt-dark-muted"><span class="h-2 w-2 rounded-full bg-rt-red"></span>{{ __('app.active_users') }}</dt>
                        <dd class="mt-1 text-sm font-semibold tabular-nums text-rt-text dark:text-white">{{ number_format($charts['status']['values'][0] ?? 0, 0, ',', '.') }}</dd>
                    </div>
                    <div>
                        <dt class="flex items-center gap-2 text-[10px] text-rt-muted dark:text-rt-dark-muted"><span class="h-2 w-2 rounded-full bg-slate-300 dark:bg-slate-600"></span>{{ __('app.inactive_users') }}</dt>
                        <dd class="mt-1 text-sm font-semibold tabular-nums text-rt-text dark:text-white">{{ number_format($charts['status']['values'][1] ?? 0, 0, ',', '.') }}</dd>
                    </div>
                </dl>
            </article>
            <article class="rt-admin-panel overflow-hidden rounded-2xl p-4 lg:col-span-12 xl:col-span-3">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.last_14_days') }}</p>
                        <h2 class="mt-1 text-base font-semibold text-rt-text dark:text-white">{{ __('app.activity_trend') }}</h2>
                    </div>
                    <i data-feather="bar-chart-2" class="h-5 w-5 text-rt-red-light"></i>
                </div>
                <div class="rt-admin-chart mt-2 h-[120px] sm:h-[150px] lg:h-[96px] xl:h-[150px]" x-ref="activityChart" aria-label="{{ __('app.activity_trend') }}"></div>
            </article>
        </section>

        @if (auth()->user()?->role === 'admin')
        <section class="rt-admin-panel rt-admin-operations overflow-hidden rounded-2xl" aria-labelledby="operational-preview-heading" data-dashboard-segment="operations">
            <header class="rt-admin-operations-header flex flex-wrap items-start justify-between gap-2.5 border-b border-slate-200 bg-slate-50 px-3.5 py-3 sm:gap-3 sm:px-5 sm:py-3.5 dark:border-slate-600 dark:bg-slate-900" data-dashboard-item>
                <div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.operations') }}</p>
                        <span class="rt-admin-demo-badge inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-amber-700 dark:border-amber-700 dark:bg-amber-900 dark:text-amber-200">
                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                            {{ __('app.demo_preview') }}
                        </span>
                    </div>
                    <h2 id="operational-preview-heading" class="mt-1 text-base font-semibold text-rt-text dark:text-white">{{ __('app.operational_control') }}</h2>
                    <p class="mt-0.5 hidden max-w-2xl text-xs leading-5 text-rt-muted sm:block dark:text-rt-dark-muted">{{ __('app.operational_preview_dashboard_hint') }}</p>
                </div>
                <span class="rt-admin-operations-status hidden items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-600 sm:inline-flex dark:border-slate-600 dark:bg-slate-950 dark:text-slate-200">
                    <i data-feather="database" class="h-3.5 w-3.5"></i>
                    {{ __('app.no_database_connection') }}
                </span>
            </header>

            <div class="rt-admin-operations-grid grid grid-cols-2 gap-px bg-slate-200 lg:grid-cols-4 dark:bg-slate-600" data-operational-preview data-dashboard-items>
                @foreach ($operationalPreviews as $previewModule)
                    <a
                        href="{{ route('admin.operations.preview', ['module' => $previewModule['slug']]) }}"
                        wire:navigate
                        class="rt-admin-operations-card group min-w-0 bg-white px-3 py-3 transition duration-200 hover:bg-slate-50 sm:px-4 sm:py-3.5 dark:bg-slate-800 dark:hover:bg-slate-700"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span class="rt-admin-preview-tone flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border sm:h-9 sm:w-9 {{ $previewToneClasses[$previewModule['tone']] ?? $previewToneClasses['red'] }}" data-preview-tone="{{ $previewModule['tone'] }}">
                                <i data-feather="{{ $previewModule['icon'] }}" class="h-4 w-4"></i>
                            </span>
                            <i data-feather="arrow-up-right" class="h-4 w-4 text-slate-400 transition duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-rt-red"></i>
                        </div>
                        <p class="mt-2 text-lg font-semibold tracking-[-0.035em] tabular-nums text-rt-text sm:mt-2.5 sm:text-xl dark:text-white">{{ $previewModule['metric'] }}</p>
                        <p class="mt-0.5 truncate text-[11px] text-rt-muted sm:text-xs dark:text-rt-dark-muted">{{ $previewModule['metric_label'] }}</p>
                        <div class="rt-admin-operations-card-divider mt-2 border-t border-slate-200 pt-2 sm:mt-2.5 sm:pt-2.5 dark:border-slate-600">
                            <p class="truncate text-xs font-semibold text-rt-text sm:text-sm dark:text-white">{{ $previewModule['title'] }}</p>
                            <p class="mt-0.5 hidden truncate text-[11px] text-rt-soft sm:block dark:text-rt-dark-soft">{{ $previewModule['badge'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
        @endif

        <section class="grid gap-3 lg:grid-cols-12" data-dashboard-segment="accounts" data-dashboard-items>
            {{-- Neueste Benutzer --}}
            <article class="rt-admin-panel rounded-2xl lg:col-span-8">
                <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-4 py-3.5 sm:px-5 dark:border-slate-700">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.accounts') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.recent_users') }}</h2>
                    </div>
                    @can('employees.view')
                        <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                            {{ __('app.show_all') }}
                            <i data-feather="arrow-up-right" class="h-4 w-4"></i>
                        </a>
                    @endcan
                </header>
                <div class="rt-admin-list-grid grid gap-px bg-slate-200 sm:grid-cols-2 dark:bg-slate-700" data-dashboard-items>
                    @forelse ($recentUsers as $user)
                        <a href="{{ route('admin.user-profile', $user->id) }}" wire:navigate class="rt-admin-user-row group flex min-w-0 items-center gap-3 bg-rt-surface px-4 py-3 transition duration-200 hover:bg-rt-surface-muted sm:px-5 dark:bg-rt-dark-surface dark:hover:bg-rt-dark-surface-muted">
                            <span class="rt-admin-user-avatar flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-sm font-semibold text-rt-text transition duration-200 group-hover:border-rt-red group-hover:bg-rt-red group-hover:text-white dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $user->name }}</span>
                                <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $user->email }}</span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block text-[10px] font-semibold {{ $user->status ? 'text-emerald-600 dark:text-emerald-400' : 'text-rt-soft dark:text-rt-dark-soft' }}">{{ $user->status ? __('app.active') : __('app.inactive') }}</span>
                                <span class="mt-1 block text-[10px] text-rt-soft dark:text-rt-dark-soft">{{ $user->created_at?->format('d.m.Y') }}</span>
                            </span>
                        </a>
                    @empty
                        <p class="bg-rt-surface px-6 py-8 text-sm text-rt-muted sm:col-span-2 dark:bg-rt-dark-surface dark:text-rt-dark-muted">{{ __('app.no_users_yet') }}</p>
                    @endforelse
                </div>
            </article>

            {{-- Schnellzugriffe als kompakte Befehlsflaeche. --}}
            <article class="rt-admin-panel rounded-2xl p-4 lg:col-span-4">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.admin_control_center') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.quick_access') }}</h2>
                <div class="mt-3 grid grid-cols-2 gap-2" data-dashboard-items>
                    @can('employees.view')
                        <a href="{{ route('admin.employees') }}" wire:navigate class="rt-admin-quick-link group rounded-xl border border-slate-200 bg-slate-50 p-3 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <i data-feather="users" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-2.5 block text-xs font-semibold leading-5">{{ __('app.manage_employees') }}</span>
                        </a>
                    @endcan
                    @can('files.manage')
                        <a href="{{ route('admin.files') }}" wire:navigate class="rt-admin-quick-link group rounded-xl border border-slate-200 bg-slate-50 p-3 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <i data-feather="folder" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-2.5 block text-xs font-semibold leading-5">{{ __('app.file_management') }}</span>
                        </a>
                    @endcan
                    @can('manage.messages')
                        <a href="{{ route('admin.mail-management') }}" wire:navigate class="rt-admin-quick-link group rounded-xl border border-slate-200 bg-slate-50 p-3 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <i data-feather="send" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-2.5 block text-xs font-semibold leading-5">{{ __('app.mail_management') }}</span>
                        </a>
                    @endcan
                    @can('settings.manage')
                        <a href="{{ route('admin.settings') }}" wire:navigate class="rt-admin-quick-link group rounded-xl border border-slate-200 bg-slate-50 p-3 transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:border-slate-700 dark:bg-slate-800 dark:text-white">
                            <i data-feather="sliders" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-2.5 block text-xs font-semibold leading-5">{{ __('app.settings') }}</span>
                        </a>
                    @endcan
                </div>
            </article>
        </section>

        <section class="grid gap-3 {{ $canViewSystemData && $system ? 'lg:grid-cols-12' : '' }}" data-dashboard-segment="system" data-dashboard-items>
            <article class="rt-admin-panel rounded-2xl p-4 {{ $canViewSystemData && $system ? 'lg:col-span-5' : '' }}">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.live_operations') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.recently_active') }}</h2>
                    </div>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"><i data-feather="radio" class="h-4 w-4"></i></span>
                </div>
                <div class="mt-3 space-y-2" data-dashboard-items>
                    @forelse ($recentActivity as $entry)
                        <div class="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-rt-surface-muted dark:hover:bg-rt-dark-surface-muted">
                            <span class="relative shrink-0">
                                <img src="{{ $entry['user']->profile_photo_url }}" alt="{{ $entry['user']->name }}" class="h-9 w-9 rounded-xl object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                                @if ($entry['lastSeen']->gte(now()->subMinutes(5)))
                                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-rt-surface dark:ring-rt-dark-surface"></span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-rt-text dark:text-white">{{ $entry['user']->name }}</span>
                            <time class="shrink-0 text-[11px] text-rt-soft dark:text-rt-dark-soft" datetime="{{ $entry['lastSeen']->toIso8601String() }}">{{ $entry['lastSeen']->diffForHumans() }}</time>
                        </div>
                    @empty
                        <p class="rounded-xl bg-rt-surface-muted px-4 py-6 text-sm text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ __('app.no_activity_yet') }}</p>
                    @endforelse
                </div>
            </article>

            {{-- Serverseitig nur fuer das Administratoren-Team bereitgestellt. --}}
            @if ($canViewSystemData && $system)
                <article class="rt-admin-panel overflow-hidden rounded-2xl lg:col-span-7" data-system-dashboard>
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3.5 sm:px-5 dark:border-slate-700">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.administrator_team') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.technical_system_data') }}</h2>
                            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.technical_system_data_description') }}</p>
                        </div>
                        <span class="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ __('app.system_ready') }}
                        </span>
                    </header>

                    <dl class="rt-admin-system-grid grid gap-px bg-slate-200 sm:grid-cols-2 lg:grid-cols-3 dark:bg-slate-700" data-dashboard-items>
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
                                <dd class="mt-1.5 truncate text-sm font-semibold text-rt-text dark:text-white" title="{{ $value }}">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </article>
            @endif
        </section>
    </div>
</x-ui.page>
