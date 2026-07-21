<x-ui.page
    :title="__('app.dashboard')"
    :eyebrow="__('app.administration')"
    description="Überblick über Benutzer, Teams, Betrieb und Systemzustand."
>
    <x-slot:actions>
        <div class="hidden rounded-xl bg-rt-surface px-4 py-3 text-right shadow-rt-sm ring-1 ring-rt-border/60 sm:block dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <p class="text-xs text-rt-soft dark:text-rt-dark-soft">{{ now()->translatedFormat('l, d. F Y') }}</p>
            <p class="mt-0.5 text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ config('app.name') }}</p>
        </div>
    </x-slot:actions>

    {{-- Kennzahlen --}}
    <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" data-anim-stagger>
        <x-ui.dashboard.stat-card :compact-mobile="true" :label="__('app.total_users')" :value="number_format($totalUsers, 0, ',', '.')">
            <i data-feather="users" class="h-4 w-4 sm:h-6 sm:w-6"></i>
        </x-ui.dashboard.stat-card>

        <x-ui.dashboard.stat-card :compact-mobile="true" tone="emerald" :label="__('app.active_users')" :value="number_format($activeUsers, 0, ',', '.')">
            <i data-feather="user-check" class="h-4 w-4 sm:h-6 sm:w-6"></i>
        </x-ui.dashboard.stat-card>

        <x-ui.dashboard.stat-card :compact-mobile="true" tone="red" :label="__('app.employees')" :value="number_format($totalEmployees, 0, ',', '.')">
            <i data-feather="briefcase" class="h-4 w-4 sm:h-6 sm:w-6"></i>
        </x-ui.dashboard.stat-card>

        <x-ui.dashboard.stat-card :compact-mobile="true" tone="violet" :label="__('app.teams_rbac')" :value="number_format($totalTeams, 0, ',', '.')">
            <i data-feather="shield" class="h-4 w-4 sm:h-6 sm:w-6"></i>
        </x-ui.dashboard.stat-card>
    </div>

    <div class="grid gap-4 sm:gap-6 lg:grid-cols-3" data-anim="fade-up" data-anim-delay="0.1">
        {{-- Neueste Benutzer --}}
        <div class="rounded-xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 lg:col-span-2 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <div class="flex items-center justify-between border-b border-rt-border/60 px-4 py-3 sm:px-6 sm:py-4 dark:border-rt-dark-border/60">
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.recent_users') }}</h2>
                @can('employees.view')
                    <a href="{{ route('admin.employees') }}" class="text-sm font-medium text-rt-red transition-all duration-300 ease-rt-spring hover:text-rt-red-dark dark:text-rt-red dark:hover:text-rt-red-dark">
                        {{ __('app.show_all') }}
                    </a>
                @endcan
            </div>
            <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                @forelse ($recentUsers as $user)
                    <div class="flex items-center justify-between gap-2 px-4 py-2.5 sm:gap-4 sm:px-6 sm:py-3">
                        <div class="flex min-w-0 items-center gap-2.5 sm:gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600 sm:h-9 sm:w-9 sm:text-sm dark:bg-slate-700 dark:text-slate-300">
                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $user->name }}</p>
                                <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $user->email }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5 sm:gap-3">
                            @if ($user->role === 'admin')
                                <span class="rounded-full bg-rt-red/10 px-2 py-0.5 text-[10px] font-medium text-rt-red sm:px-2.5 sm:text-xs">{{ __('app.role_admin') }}</span>
                            @elseif ($user->role === 'staff')
                                <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-medium text-sky-700 sm:px-2.5 sm:text-xs dark:bg-sky-500/10 dark:text-sky-300">{{ __('app.role_staff') }}</span>
                            @else
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 sm:px-2.5 sm:text-xs dark:bg-slate-700 dark:text-slate-300">{{ __('app.role_user') }}</span>
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
        <div class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.quick_access') }}</h2>
            <div class="mt-3 grid grid-cols-2 gap-2 sm:mt-4 lg:grid-cols-1">
                @can('employees.view')
                    <a href="{{ route('admin.employees') }}"
                       class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium leading-tight text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] sm:gap-3 sm:px-4 sm:py-3 sm:text-sm dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="users" class="h-4 w-4"></i>
                        {{ __('app.manage_employees') }}
                    </a>
                @endcan
                @can('files.manage')
                    <a href="{{ route('admin.files') }}"
                       class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium leading-tight text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] sm:gap-3 sm:px-4 sm:py-3 sm:text-sm dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="folder" class="h-4 w-4"></i>
                        {{ __('app.file_management') }}
                    </a>
                @endcan
                @can('manage.messages')
                    <a href="{{ route('admin.mail-management') }}"
                       class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium leading-tight text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] sm:gap-3 sm:px-4 sm:py-3 sm:text-sm dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="send" class="h-4 w-4"></i>
                        {{ __('app.mail_management') }}
                    </a>
                @endcan
                @can('roles.manage')
                    <a href="{{ route('admin.employees') }}"
                       class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium leading-tight text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] sm:gap-3 sm:px-4 sm:py-3 sm:text-sm dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="shield" class="h-4 w-4"></i>
                        {{ __('app.teams_permissions') }}
                    </a>
                @endcan
                <a href="{{ route('profile.show') }}"
                   class="flex min-h-11 items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs font-medium leading-tight text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-slate-300 hover:bg-slate-50 hover:shadow-rt-xs active:scale-[0.98] sm:gap-3 sm:px-4 sm:py-3 sm:text-sm dark:border-slate-700 dark:text-slate-300 dark:hover:border-slate-600 dark:hover:bg-slate-700">
                    <i data-feather="user" class="h-4 w-4"></i>
                    {{ __('app.my_profile') }}
                </a>
            </div>
        </div>
    </div>

    {{-- System & Betrieb — nur im Admin-/Verwaltungsbereich sichtbar --}}
    <div class="grid gap-4 sm:gap-6 lg:grid-cols-3" data-anim="fade-up" data-anim-delay="0.15">
        {{-- Systemstatus --}}
        <div class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.system_status') }}</h2>
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.application') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['appVersion'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.environment') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['environment'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.debug_mode') }}</dt>
                    <dd class="text-right font-medium">
                        @if ($system['debug'])
                            <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">{{ __('app.on') }}</span>
                        @else
                            <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('app.off') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.php_version') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['php'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Laravel</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['laravel'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.database') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['database'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.queue') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">
                        {{ $system['queue'] }}
                        @if ($system['failedJobs'] > 0)
                            <span class="ml-1 rounded-full bg-rt-red/10 px-2 py-0.5 text-xs font-semibold text-rt-red">{{ __('app.jobs_failed', ['count' => $system['failedJobs']]) }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.file_storage') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['storage'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.server_disk') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['disk'] }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.last_activity') }}</dt>
                    <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $system['lastActivityAt']?->diffForHumans() ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        {{-- Betrieb --}}
        <div class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.operations') }}</h2>
            <div class="mt-3 grid grid-cols-3 gap-2 sm:mt-4 sm:block sm:space-y-3">
                <div class="flex min-w-0 flex-col items-center justify-between gap-1 rounded-lg bg-rt-surface-muted/60 px-2 py-2.5 text-center ring-1 ring-rt-border/60 sm:flex-row sm:gap-3 sm:px-4 sm:py-3 sm:text-left dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                    <div class="flex min-w-0 flex-col items-center gap-1 sm:flex-row sm:gap-3">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60"></span>
                            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                        </span>
                        <span class="text-[10px] leading-tight text-slate-600 sm:text-sm dark:text-slate-300">{{ __('app.online_now') }}</span>
                    </div>
                    <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ $operations['online'] }}</span>
                </div>

                <a href="{{ route('admin.employees') }}" class="flex min-w-0 flex-col items-center justify-between gap-1 rounded-lg bg-rt-surface-muted/60 px-2 py-2.5 text-center ring-1 ring-rt-border/60 transition-all duration-300 ease-rt-spring hover:ring-rt-accent/40 sm:flex-row sm:gap-3 sm:px-4 sm:py-3 sm:text-left dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                    <div class="flex min-w-0 flex-col items-center gap-1 sm:flex-row sm:gap-3">
                        <i data-feather="user-plus" class="h-4 w-4 text-rt-muted dark:text-rt-dark-muted"></i>
                        <span class="text-[10px] leading-tight text-slate-600 sm:text-sm dark:text-slate-300">{{ __('app.open_invitations') }}</span>
                    </div>
                    <span class="text-lg font-semibold {{ $operations['openInvitations'] > 0 ? 'text-rt-red' : 'text-slate-900 dark:text-white' }}">{{ $operations['openInvitations'] }}</span>
                </a>

                <div class="flex min-w-0 flex-col items-center justify-between gap-1 rounded-lg bg-rt-surface-muted/60 px-2 py-2.5 text-center ring-1 ring-rt-border/60 sm:flex-row sm:gap-3 sm:px-4 sm:py-3 sm:text-left dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                    <div class="flex min-w-0 flex-col items-center gap-1 sm:flex-row sm:gap-3">
                        <i data-feather="mail" class="h-4 w-4 text-rt-muted dark:text-rt-dark-muted"></i>
                        <span class="text-[10px] leading-tight text-slate-600 sm:text-sm dark:text-slate-300">{{ __('app.unread_messages_total') }}</span>
                    </div>
                    <span class="text-lg font-semibold text-slate-900 dark:text-white">{{ number_format($operations['unreadTotal'], 0, ',', '.') }}</span>
                </div>
            </div>
        </div>

        {{-- Zuletzt aktive Benutzer --}}
        <div class="rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.recently_active') }}</h2>
            <div class="mt-4 space-y-3">
                @forelse ($recentActivity as $entry)
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="relative shrink-0">
                                <img src="{{ $entry['user']->profile_photo_url }}" alt="{{ $entry['user']->name }}" class="h-8 w-8 rounded-full object-cover ring-1 ring-rt-border/60 dark:ring-rt-dark-border/60">
                                @if ($entry['lastSeen']->gte(now()->subMinutes(5)))
                                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-rt-surface dark:ring-rt-dark-surface"></span>
                                @endif
                            </span>
                            <p class="truncate text-sm font-medium text-slate-900 dark:text-white">{{ $entry['user']->name }}</p>
                        </div>
                        <span class="shrink-0 text-xs text-slate-400">{{ $entry['lastSeen']->diffForHumans() }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.no_activity_yet') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-ui.page>
