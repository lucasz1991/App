<div class="space-y-6 py-6">
    {{-- Begruessungs-Band im RailTime-Look --}}
    <div class="relative overflow-hidden rounded-2xl bg-[#080b10] px-6 py-8 text-white shadow-lg sm:px-8">
        <div class="pointer-events-none absolute -right-16 -top-16 h-64 w-64 rounded-full bg-[#e4002b]/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 right-24 h-48 w-48 rounded-full bg-white/5 blur-2xl"></div>

        <div class="relative flex flex-wrap items-center gap-6">
            <img src="{{ asset('rt-brand/rt-logo.svg') }}" alt="" class="h-16 w-16 drop-shadow-lg">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.2em] text-[#e4002b]">RT Rail Time GmbH</p>
                <h1 class="mt-1 text-2xl font-semibold sm:text-3xl">
                    {{ __('app.welcome_name', ['name' => auth()->user()->name]) }}
                </h1>
                <p class="mt-1 text-sm text-slate-300">
                    {{ now()->translatedFormat('l, d. F Y') }} &middot; {{ __('app.admin_area_of', ['app' => config('app.name')]) }}
                </p>
            </div>
        </div>
    </div>

    {{-- Kennzahlen --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600 dark:bg-sky-500/10">
                <i data-feather="users" class="h-6 w-6"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.total_users') }}</p>
                <p class="text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($totalUsers, 0, ',', '.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10">
                <i data-feather="user-check" class="h-6 w-6"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.active_users') }}</p>
                <p class="text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($activeUsers, 0, ',', '.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-[#e4002b]/10 text-[#e4002b]">
                <i data-feather="briefcase" class="h-6 w-6"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.employees') }}</p>
                <p class="text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($totalEmployees, 0, ',', '.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10">
                <i data-feather="shield" class="h-6 w-6"></i>
            </div>
            <div>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('app.teams_rbac') }}</p>
                <p class="text-2xl font-semibold text-slate-900 dark:text-white">{{ number_format($totalTeams, 0, ',', '.') }}</p>
            </div>
        </div>
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
