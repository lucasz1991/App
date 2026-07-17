<div class="space-y-6 py-6">
    {{-- Kompakte Uebersicht statt Begruessungsband --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">Administration</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.dashboard') }}</h1>
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">Überblick über Benutzer, Teams und aktuelle Verwaltungsaufgaben.</p>
        </div>
        <div class="rounded-xl border border-rt-border bg-rt-surface px-4 py-3 text-right shadow-sm dark:border-rt-dark-border dark:bg-rt-dark-surface">
            <p class="text-xs text-rt-soft dark:text-rt-dark-soft">{{ now()->translatedFormat('l, d. F Y') }}</p>
            <p class="mt-0.5 text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ config('app.name') }}</p>
        </div>
    </div>

    {{-- Kennzahlen --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.dashboard.stat-card :label="__('app.total_users')" :value="number_format($totalUsers, 0, ',', '.')">
            <i data-feather="users" class="h-6 w-6"></i>
        </x-ui.dashboard.stat-card>

        <x-ui.dashboard.stat-card tone="emerald" :label="__('app.active_users')" :value="number_format($activeUsers, 0, ',', '.')">
            <i data-feather="user-check" class="h-6 w-6"></i>
        </x-ui.dashboard.stat-card>

        <x-ui.dashboard.stat-card tone="red" :label="__('app.employees')" :value="number_format($totalEmployees, 0, ',', '.')">
            <i data-feather="briefcase" class="h-6 w-6"></i>
        </x-ui.dashboard.stat-card>

        <x-ui.dashboard.stat-card tone="violet" :label="__('app.teams_rbac')" :value="number_format($totalTeams, 0, ',', '.')">
            <i data-feather="shield" class="h-6 w-6"></i>
        </x-ui.dashboard.stat-card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Neueste Benutzer --}}
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm lg:col-span-2 dark:border-slate-700 dark:bg-slate-800">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-slate-700">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.recent_users') }}</h2>
                @can('employees.view')
                    <a href="{{ route('admin.employees') }}" class="text-sm font-medium text-rt-red hover:text-rt-red-dark dark:text-rt-red dark:hover:text-rt-red-dark">
                        {{ __('app.show_all') }}
                    </a>
                @endcan
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-700">
                @forelse ($recentUsers as $user)
                    <div class="flex items-center justify-between gap-4 px-6 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $user->name }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            @if ($user->role === 'admin')
                                <span class="rounded-full bg-[#e4002b]/10 px-2.5 py-0.5 text-xs font-medium text-[#e4002b]">{{ __('app.role_admin') }}</span>
                            @elseif ($user->role === 'staff')
                                <span class="rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-medium text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">{{ __('app.role_staff') }}</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">{{ __('app.role_user') }}</span>
                            @endif
                            <span class="hidden h-2 w-2 rounded-full sm:block {{ $user->status ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600' }}"
                                  title="{{ $user->status ? __('app.active') : __('app.inactive') }}"></span>
                            <span class="hidden text-xs text-slate-400 md:block">{{ $user->created_at?->format('d.m.Y') }}</span>
                        </div>
                    </div>
                @empty
                    <p class="px-6 py-6 text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_users_yet') }}</p>
                @endforelse
            </div>
        </div>

        {{-- Schnellzugriff --}}
        <div class="space-y-4">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.quick_access') }}</h2>
                <div class="mt-4 space-y-2">
                    @can('employees.view')
                        <a href="{{ route('admin.employees') }}"
                           class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                            <i data-feather="users" class="h-4 w-4"></i>
                            {{ __('app.manage_employees') }}
                        </a>
                    @endcan
                    @can('roles.manage')
                        <a href="{{ route('admin.employees') }}"
                           class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                            <i data-feather="shield" class="h-4 w-4"></i>
                            {{ __('app.teams_permissions') }}
                        </a>
                    @endcan
                    <a href="{{ route('profile.show') }}"
                       class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700">
                        <i data-feather="user" class="h-4 w-4"></i>
                        {{ __('app.my_profile') }}
                    </a>
                </div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.system') }}</h2>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">{{ __('app.application') }}</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">{{ config('app.name') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">{{ __('app.environment') }}</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">{{ app()->environment() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500 dark:text-slate-400">Laravel</dt>
                        <dd class="font-medium text-slate-900 dark:text-white">{{ app()->version() }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
</div>
