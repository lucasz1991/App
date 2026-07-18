<x-ui.page
    :title="__('app.welcome_name', ['name' => auth()->user()->name])"
    eyebrow="RT Rail Time GmbH"
    :description="now()->translatedFormat('l, d. F Y') . ' · ' . __('app.user_area_of', ['app' => config('app.name')])"
>
    <div class="grid gap-6 lg:grid-cols-3" data-anim="fade-up">
        {{-- Konto-Uebersicht --}}
        <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 lg:col-span-2 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.your_account') }}</h2>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.name') }}</dt>
                    <dd class="font-medium text-rt-text dark:text-rt-dark-text">{{ auth()->user()->name }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.email') }}</dt>
                    <dd class="font-medium text-rt-text dark:text-rt-dark-text">{{ auth()->user()->email }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.registered_since') }}</dt>
                    <dd class="font-medium text-rt-text dark:text-rt-dark-text">{{ auth()->user()->created_at?->format('d.m.Y') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.status') }}</dt>
                    <dd>
                        <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ __('app.active') }}</span>
                    </dd>
                </div>
            </dl>
        </div>

        {{-- Schnellzugriff --}}
        <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
            <h2 class="text-base font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.quick_access') }}</h2>
            <div class="mt-4 space-y-2">
                <a href="{{ route('profile.show') }}"
                   class="flex items-center gap-3 rounded-lg border border-rt-border px-4 py-3 text-sm font-medium text-rt-text transition-all duration-300 ease-rt-spring hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red active:scale-[0.98] dark:border-rt-dark-border dark:text-rt-dark-muted dark:hover:border-rt-red/40 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent">
                    <i data-feather="user" class="h-4 w-4"></i>
                    {{ __('app.edit_profile') }}
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="flex w-full items-center gap-3 rounded-lg border border-rt-border px-4 py-3 text-left text-sm font-medium text-rt-text transition-all duration-300 ease-rt-spring hover:border-red-200 hover:bg-red-50 hover:text-red-700 active:scale-[0.98] dark:border-rt-dark-border dark:text-rt-dark-muted dark:hover:border-red-800 dark:hover:bg-rt-dark-surface-muted dark:hover:text-red-400">
                        <i data-feather="log-out" class="h-4 w-4"></i>
                        {{ __('app.logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-ui.page>
