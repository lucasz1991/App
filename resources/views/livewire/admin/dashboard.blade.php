@section('title', __('app.dashboard'))

@php
    $activeRate = $totalUsers > 0 ? (int) round(($activeUsers / $totalUsers) * 100) : 0;
    $chartConfig = array_merge($charts, [
        'labels' => [
            'total' => __('app.total'),
            'registrations' => __('app.registrations'),
            'accounts' => __('app.accounts'),
            'activity' => __('app.active_users'),
        ],
    ]);
@endphp

<x-ui.page>
    <div
        class="space-y-5 sm:space-y-6"
        x-data="adminDashboardCharts(@js($chartConfig))"
        data-admin-dashboard
    >
        {{-- Markanter Einstieg statt eines generischen Seitenkopfs. --}}
        <section class="rt-admin-hero relative overflow-hidden rounded-[1.75rem] px-5 py-6 text-white shadow-rt-lg sm:px-8 sm:py-9 lg:min-h-[25rem] lg:px-10 lg:py-10" data-anim="fade-up">
            <svg class="pointer-events-none absolute -right-24 bottom-0 h-[85%] w-[70%] opacity-70" viewBox="0 0 720 360" fill="none" aria-hidden="true">
                <path d="M42 306C130 276 132 191 220 176C314 160 338 263 431 233C515 205 501 105 680 58" stroke="rgba(255,255,255,.10)" stroke-width="34" stroke-linecap="round" />
                <path class="rt-admin-route-line" d="M42 306C130 276 132 191 220 176C314 160 338 263 431 233C515 205 501 105 680 58" stroke="#e4002b" stroke-width="3" stroke-linecap="round" />
                <circle class="rt-admin-signal" cx="220" cy="176" r="8" fill="#ffffff" />
                <circle class="rt-admin-signal" cx="431" cy="233" r="8" fill="#e4002b" style="animation-delay:.7s" />
                <circle cx="680" cy="58" r="5" fill="#ffffff" opacity=".75" />
            </svg>

            <div class="relative z-10 grid gap-8 lg:grid-cols-[minmax(0,1.25fr)_minmax(20rem,.75fr)] lg:items-end">
                <div class="max-w-3xl lg:self-center">
                    <div class="mb-5 flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.06] px-3 py-1.5 text-[11px] font-semibold tracking-[0.14em] text-white/75 backdrop-blur-md">
                            <span class="relative flex h-2 w-2">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-rt-red opacity-70"></span>
                                <span class="relative inline-flex h-2 w-2 rounded-full bg-rt-red"></span>
                            </span>
                            {{ __('app.admin_control_center') }}
                        </span>
                        <span class="text-xs font-medium text-white/50">{{ now()->translatedFormat('l, d. F Y') }}</span>
                    </div>

                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-rt-red-light">{{ __('app.administrator_team') }}</p>
                    <h1 class="mt-3 max-w-2xl text-3xl font-semibold leading-[1.04] tracking-[-0.045em] text-white sm:text-5xl lg:text-[3.65rem]">
                        {{ __('app.welcome_name', ['name' => auth()->user()->name]) }}
                    </h1>
                    <p class="mt-5 max-w-2xl text-sm leading-6 text-slate-300 sm:text-base sm:leading-7">
                        {{ __('app.admin_dashboard_description') }}
                    </p>

                    <div class="mt-7 flex flex-wrap gap-2.5">
                        @can('employees.view')
                            <a href="{{ route('admin.employees') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-rt-red px-4 py-2.5 text-sm font-semibold text-white shadow-[0_12px_30px_-12px_rgba(228,0,43,.8)] transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red-light active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-white/70">
                                <i data-feather="users" class="h-4 w-4"></i>
                                {{ __('app.manage_employees') }}
                            </a>
                        @endcan
                        @can('manage.messages')
                            <a href="{{ route('admin.messages') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/[0.07] px-4 py-2.5 text-sm font-semibold text-white backdrop-blur-md transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-white/30 hover:bg-white/[0.12] active:translate-y-0 focus:outline-none focus:ring-2 focus:ring-white/60">
                                <i data-feather="message-square" class="h-4 w-4"></i>
                                {{ __('app.messages') }}
                            </a>
                        @endcan
                    </div>
                </div>

                <aside class="rounded-2xl border border-white/10 bg-black/25 p-4 shadow-[inset_0_1px_0_rgba(255,255,255,.08)] backdrop-blur-xl sm:p-5" aria-label="{{ __('app.live_operations') }}">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-white/45">{{ __('app.live_operations') }}</p>
                            <p class="mt-1 text-sm font-semibold text-white">
                                {{ ($system['failedJobs'] ?? 0) > 0 ? __('app.jobs_failed', ['count' => $system['failedJobs']]) : __('app.system_ready') }}
                            </p>
                        </div>
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-white/[0.08] text-rt-red-light ring-1 ring-inset ring-white/10">
                            <i data-feather="activity" class="h-5 w-5"></i>
                        </span>
                    </div>

                    <dl class="mt-5 grid grid-cols-3 divide-x divide-white/10">
                        <div class="pr-3">
                            <dt class="text-[10px] leading-tight text-white/45">{{ __('app.online_now') }}</dt>
                            <dd class="mt-2 text-2xl font-semibold tabular-nums text-white" data-dashboard-count="{{ $operations['online'] }}">{{ $operations['online'] }}</dd>
                        </div>
                        <div class="px-3">
                            <dt class="text-[10px] leading-tight text-white/45">{{ __('app.open_invitations') }}</dt>
                            <dd class="mt-2 text-2xl font-semibold tabular-nums text-white" data-dashboard-count="{{ $operations['openInvitations'] }}" data-dashboard-delay=".08">{{ $operations['openInvitations'] }}</dd>
                        </div>
                        <div class="pl-3">
                            <dt class="text-[10px] leading-tight text-white/45">{{ __('app.unread_messages_total') }}</dt>
                            <dd class="mt-2 text-2xl font-semibold tabular-nums text-white" data-dashboard-count="{{ $operations['unreadTotal'] }}" data-dashboard-delay=".16">{{ $operations['unreadTotal'] }}</dd>
                        </div>
                    </dl>
                </aside>
            </div>
        </section>

        {{-- Asymmetrische Kennzahlenleiste. --}}
        <section class="grid grid-cols-2 gap-3 xl:grid-cols-5" aria-label="{{ __('app.dashboard') }}" data-anim-stagger>
            <article class="group relative col-span-2 overflow-hidden rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-1 hover:shadow-rt-md dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="absolute -right-8 -top-12 h-32 w-32 rounded-full bg-rt-red/10 blur-2xl transition duration-500 group-hover:bg-rt-red/20"></div>
                <div class="relative flex items-end justify-between gap-5">
                    <div>
                        <p class="text-xs font-medium text-rt-muted dark:text-rt-dark-muted">{{ __('app.total_users') }}</p>
                        <p class="mt-2 text-4xl font-semibold tracking-[-0.045em] tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $totalUsers }}">{{ number_format($totalUsers, 0, ',', '.') }}</p>
                    </div>
                    <div class="text-right">
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $activeRate }}% {{ __('app.active_rate') }}
                        </span>
                    </div>
                </div>
                <div class="mt-5 h-1.5 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                    <div class="h-full rounded-full bg-rt-red transition-[width] duration-1000 ease-rt-spring" style="width: {{ max(3, $activeRate) }}%"></div>
                </div>
            </article>

            <article class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-1 hover:shadow-rt-md sm:p-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><i data-feather="user-check" class="h-4 w-4"></i></span>
                <p class="mt-4 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.active_users') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $activeUsers }}" data-dashboard-delay=".08">{{ number_format($activeUsers, 0, ',', '.') }}</p>
            </article>

            <article class="rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-1 hover:shadow-rt-md sm:p-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent"><i data-feather="briefcase" class="h-4 w-4"></i></span>
                <p class="mt-4 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.employees') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $totalEmployees }}" data-dashboard-delay=".16">{{ number_format($totalEmployees, 0, ',', '.') }}</p>
            </article>

            <article class="col-span-2 rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-1 hover:shadow-rt-md sm:col-span-1 sm:p-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-200"><i data-feather="layers" class="h-4 w-4"></i></span>
                <p class="mt-4 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.teams_rbac') }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-rt-text dark:text-white" data-dashboard-count="{{ $totalTeams }}" data-dashboard-delay=".24">{{ number_format($totalTeams, 0, ',', '.') }}</p>
            </article>
        </section>

        {{-- Zwei echte Diagrammtypen plus kompakte Aktivitaetsgrafik. --}}
        <section class="grid gap-4 xl:grid-cols-12" aria-label="{{ __('app.user_growth') }}" data-anim="fade-up">
            <article class="overflow-hidden rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 xl:col-span-8 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.last_14_days') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.user_growth') }}</h2>
                    </div>
                    <span class="rounded-lg bg-rt-surface-muted px-2.5 py-1 text-xs font-medium text-rt-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                        +{{ array_sum($charts['userGrowth']['registrations']) }} {{ __('app.registrations') }}
                    </span>
                </header>
                <div class="rt-admin-chart mt-3 min-h-[308px]" x-ref="growthChart" aria-label="{{ __('app.user_growth') }}"></div>
            </article>

            <div class="grid gap-4 xl:col-span-4">
                <article class="rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <h2 class="text-lg font-semibold text-rt-text dark:text-white">{{ __('app.account_status') }}</h2>
                    <div class="rt-admin-chart min-h-[220px]" x-ref="statusChart" aria-label="{{ __('app.account_status') }}"></div>
                </article>
                <article class="overflow-hidden rounded-2xl bg-rt-anthracite p-5 text-white shadow-rt-md sm:p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-white/45">{{ __('app.last_14_days') }}</p>
                            <h2 class="mt-1 text-base font-semibold text-white">{{ __('app.activity_trend') }}</h2>
                        </div>
                        <i data-feather="bar-chart-2" class="h-5 w-5 text-rt-red-light"></i>
                    </div>
                    <div class="rt-admin-chart mt-2 min-h-[112px]" x-ref="activityChart" aria-label="{{ __('app.activity_trend') }}"></div>
                </article>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-12" data-anim="fade-up" data-anim-delay=".05">
            {{-- Neueste Benutzer --}}
            <article class="rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 xl:col-span-8 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <header class="flex items-center justify-between gap-4 border-b border-rt-border/60 px-5 py-4 sm:px-6 dark:border-rt-dark-border/60">
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
                <div class="grid gap-px bg-rt-border/60 sm:grid-cols-2 dark:bg-rt-dark-border/60">
                    @forelse ($recentUsers as $user)
                        <a href="{{ route('admin.user-profile', $user->id) }}" wire:navigate class="group flex min-w-0 items-center gap-3 bg-rt-surface px-5 py-4 transition duration-300 hover:bg-rt-surface-muted sm:px-6 dark:bg-rt-dark-surface dark:hover:bg-rt-dark-surface-muted">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-sm font-semibold text-rt-text ring-1 ring-inset ring-rt-border/60 transition duration-300 group-hover:bg-rt-red group-hover:text-white dark:bg-rt-dark-surface-muted dark:text-white dark:ring-rt-dark-border/60">
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
            <article class="rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 xl:col-span-4 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.admin_control_center') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.quick_access') }}</h2>
                <div class="mt-5 grid grid-cols-2 gap-2.5">
                    @can('employees.view')
                        <a href="{{ route('admin.employees') }}" wire:navigate class="group rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-inset ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <i data-feather="users" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-4 block text-xs font-semibold leading-5">{{ __('app.manage_employees') }}</span>
                        </a>
                    @endcan
                    @can('files.manage')
                        <a href="{{ route('admin.files') }}" wire:navigate class="group rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-inset ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <i data-feather="folder" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-4 block text-xs font-semibold leading-5">{{ __('app.file_management') }}</span>
                        </a>
                    @endcan
                    @can('manage.messages')
                        <a href="{{ route('admin.mail-management') }}" wire:navigate class="group rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-inset ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <i data-feather="send" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-4 block text-xs font-semibold leading-5">{{ __('app.mail_management') }}</span>
                        </a>
                    @endcan
                    @can('settings.manage')
                        <a href="{{ route('admin.settings') }}" wire:navigate class="group rounded-xl bg-rt-surface-muted p-3.5 ring-1 ring-inset ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red hover:text-white hover:shadow-rt-glow dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                            <i data-feather="sliders" class="h-5 w-5 text-rt-red transition group-hover:text-white"></i>
                            <span class="mt-4 block text-xs font-semibold leading-5">{{ __('app.settings') }}</span>
                        </a>
                    @endcan
                </div>
            </article>
        </section>

        <section class="grid gap-4 {{ $canViewSystemData && $system ? 'xl:grid-cols-12' : '' }}" data-anim="fade-up" data-anim-delay=".08">
            <article class="rounded-2xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 {{ $canViewSystemData && $system ? 'xl:col-span-5' : '' }} dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.live_operations') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.recently_active') }}</h2>
                    </div>
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300"><i data-feather="radio" class="h-4 w-4"></i></span>
                </div>
                <div class="mt-5 space-y-2.5">
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
                <article class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 xl:col-span-7 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-system-dashboard>
                    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-rt-border/60 px-5 py-5 sm:px-6 dark:border-rt-dark-border/60">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.administrator_team') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ __('app.technical_system_data') }}</h2>
                            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.technical_system_data_description') }}</p>
                        </div>
                        <span class="inline-flex items-center gap-2 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ __('app.system_ready') }}
                        </span>
                    </header>

                    <dl class="grid gap-px bg-rt-border/60 sm:grid-cols-2 lg:grid-cols-3 dark:bg-rt-dark-border/60">
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
                            <div class="min-w-0 bg-rt-surface px-5 py-4 dark:bg-rt-dark-surface">
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
