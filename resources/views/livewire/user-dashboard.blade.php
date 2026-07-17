<div class="space-y-6">
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
                    {{ now()->translatedFormat('l, d. F Y') }} &middot; {{ __('app.user_area_of', ['app' => config('app.name')]) }}
                </p>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Konto-Uebersicht --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2 dark:border-slate-700 dark:bg-slate-800">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.your_account') }}</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.name') }}</dt>
                    <dd class="font-medium text-slate-900 dark:text-white">{{ auth()->user()->name }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.email') }}</dt>
                    <dd class="font-medium text-slate-900 dark:text-white">{{ auth()->user()->email }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.registered_since') }}</dt>
                    <dd class="font-medium text-slate-900 dark:text-white">{{ auth()->user()->created_at?->format('d.m.Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('app.status') }}</dt>
                    <dd>
                        <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('app.active') }}</span>
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Schnellzugriff --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('app.quick_access') }}</h2>
            <div class="mt-4 space-y-2">
                <a href="{{ route('profile.show') }}"
                   class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                    <i data-feather="user" class="h-4 w-4"></i>
                    {{ __('app.edit_profile') }}
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:border-red-200 hover:bg-red-50 hover:text-red-700 dark:border-slate-700 dark:text-slate-300 dark:hover:border-red-800 dark:hover:bg-slate-700 dark:hover:text-red-400">
                        <i data-feather="log-out" class="h-4 w-4"></i>
                        {{ __('app.logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
