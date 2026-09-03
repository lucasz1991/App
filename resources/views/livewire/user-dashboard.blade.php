{{-- Ruhige persoenliche Startseite fuer Mitarbeiter und Gaeste. --}}
<div
    class="relative min-w-0"
    wire:loading.class="cursor-wait"
    data-user-dashboard
    data-dashboard-layout="minimal"
>
    <x-ui.page :auto-intro="false" content-class="mx-auto max-w-6xl space-y-10 sm:space-y-12">
        <x-ui.welcome-intro
            :initially-open="\App\Support\PageViews::firstVisit(auth()->user(), 'intro:welcome')"
        />

        <header
            class="border-b border-rt-border/80 pb-7 sm:pb-9 dark:border-rt-dark-border/80"
            aria-labelledby="dashboard-personal-title"
            data-dashboard-personal-header
            data-anim="fade-up"
        >
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between sm:gap-8">
                <div class="max-w-3xl">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">
                        {{ $showSchedule ? __('app.employee_dashboard') : __('app.guest_dashboard') }}
                    </p>
                    <h1 id="dashboard-personal-title" class="mt-2 text-3xl font-semibold leading-tight tracking-[-0.04em] text-rt-text sm:text-4xl dark:text-white">
                        {{ __('app.welcome_name', ['name' => auth()->user()->name]) }}
                    </h1>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-rt-muted sm:text-base sm:leading-7 dark:text-rt-dark-muted">
                        {{ $showSchedule ? __('app.employee_dashboard_description') : __('app.guest_dashboard_description') }}
                    </p>
                </div>

                <button
                    type="button"
                    x-on:click="$dispatch('rt-welcome:open')"
                    class="inline-flex min-h-11 w-full shrink-0 items-center justify-center rounded-md border border-rt-border bg-transparent px-4 py-2 text-sm font-semibold text-rt-text outline-none transition hover:bg-rt-surface-muted focus-visible:ring-2 focus-visible:ring-rt-red/70 motion-safe:active:scale-[0.98] motion-reduce:transition-none dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface-muted sm:w-auto"
                    aria-label="{{ __('app.welcome_intro_reopen') }}"
                    title="{{ __('app.welcome_intro_reopen') }}"
                    data-welcome-intro-trigger
                >
                    {{ __('app.welcome_intro_open_short') }}
                </button>
            </div>

            <p class="mt-5 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                <span class="font-semibold text-rt-text dark:text-white">{{ $dashboardTeamName }}</span>
                <span aria-hidden="true"> · </span>
                <time datetime="{{ now()->toDateString() }}">{{ now()->translatedFormat('l, d. F Y') }}</time>
            </p>

            <dl
                class="mt-6 grid divide-y divide-rt-border border-y border-rt-border sm:grid-cols-3 sm:divide-x sm:divide-y-0 dark:divide-rt-dark-border dark:border-rt-dark-border"
                data-dashboard-personal-summary
            >
                <div class="py-4 sm:px-5 sm:first:pl-0">
                    <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.available_files') }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white">{{ number_format($filesTotal, 0, ',', '.') }}</dd>
                </div>
                <div class="py-4 sm:px-5">
                    <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.unread_messages') }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums {{ $unreadMessages > 0 ? 'text-rt-red dark:text-rt-dark-accent' : 'text-rt-text dark:text-white' }}">{{ number_format($unreadMessages, 0, ',', '.') }}</dd>
                </div>
                <div class="py-4 sm:px-5 sm:last:pr-0">
                    <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.profile_status') }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-rt-text dark:text-white">{{ $profileCompletion }} %</dd>
                </div>
            </dl>
        </header>

        @if ($showSchedule)
            <section aria-labelledby="dashboard-workday-title" data-dashboard-work-focus data-anim="fade-up">
                <div class="max-w-2xl">
                    <h2 id="dashboard-workday-title" class="text-2xl font-semibold tracking-[-0.035em] text-rt-text dark:text-white">
                        {{ __('app.your_workday') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.employee_dashboard_workday_description') }}
                    </p>
                </div>

                <a
                    href="{{ $wagonListRoute }}"
                    wire:navigate
                    class="group mt-5 grid min-h-20 gap-3 border-y border-rt-border py-4 outline-none transition hover:border-rt-red/40 focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:py-5 dark:border-rt-dark-border"
                    data-dashboard-primary-action="wagon-list"
                >
                    <span class="min-w-0">
                        <span class="block text-base font-semibold text-rt-text dark:text-white">{{ __('app.order_workspace') }}</span>
                        <span class="mt-1 block text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('app.help_topic_wagon_description') }}</span>
                    </span>
                    <span class="text-sm font-semibold text-rt-red transition motion-safe:group-hover:translate-x-0.5 motion-reduce:transition-none dark:text-rt-dark-accent">
                        {{ __('app.open_wagon_list') }} <span aria-hidden="true">&rarr;</span>
                    </span>
                </a>
            </section>
        @endif

        <x-ui.dashboard.personal-device-widget
            :stats="$deviceWidget['stats']"
            :href="$deviceWidget['href']"
            data-dashboard-device-variant="compact"
            data-anim="fade-up"
        />

        <div
            @class([
                'grid min-w-0 gap-10',
                'lg:grid-cols-[minmax(0,1fr)_18rem] lg:gap-12' => $profileCompletion < 100,
            ])
            data-anim="fade-up"
        >
            <section class="min-w-0" aria-labelledby="dashboard-news" data-dashboard-message-list>
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="dashboard-news" class="text-2xl font-semibold tracking-[-0.035em] text-rt-text dark:text-white">
                            {{ __('app.news_and_information') }}
                        </h2>
                        <p class="mt-1.5 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.latest_personal_messages_description') }}
                        </p>
                    </div>
                    <a
                        href="{{ route('messages') }}"
                        wire:navigate
                        class="inline-flex min-h-11 w-full items-center text-sm font-semibold text-rt-red outline-none transition hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:text-rt-dark-accent sm:w-auto"
                    >
                        {{ __('app.show_all') }} <span class="ml-1" aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <ul class="mt-5 border-t border-rt-border dark:border-rt-dark-border">
                    @forelse ($latestMessages as $message)
                        <li wire:key="dash-msg-{{ $message->id }}">
                            <a
                                href="{{ route('messages', ['open' => $message->id]) }}"
                                wire:navigate
                                class="group grid min-h-16 grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 border-b border-rt-border py-4 outline-none transition hover:border-rt-red/35 focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:border-rt-dark-border"
                            >
                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ (int) $message->status === 1 ? 'bg-rt-red' : 'bg-slate-300 dark:bg-slate-600' }}" aria-hidden="true"></span>
                                <span class="min-w-0">
                                    <span class="sr-only">{{ (int) $message->status === 1 ? __('app.unread') : __('app.read') }}: </span>
                                    <span class="block truncate text-sm font-semibold text-rt-text dark:text-white">{{ $message->subject }}</span>
                                    <span class="mt-1 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                        {{ $message->sender?->name ?? config('app.name') }} · {{ $message->created_at?->diffForHumans() }}
                                    </span>
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="border-b border-rt-border py-6 text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted">
                            {{ __('app.no_messages_yet') }}
                        </li>
                    @endforelse
                </ul>
            </section>

            @if ($profileCompletion < 100)
                <aside class="border-t border-rt-border pt-6 lg:border-l lg:border-t-0 lg:pl-8 lg:pt-0 dark:border-rt-dark-border" aria-labelledby="dashboard-profile" data-dashboard-profile-reminder>
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.14em] text-rt-muted dark:text-rt-dark-muted">
                        {{ $profileCompletion }} %
                    </p>
                    <h2 id="dashboard-profile" class="mt-1.5 text-lg font-semibold tracking-[-0.025em] text-rt-text dark:text-white">
                        {{ __('app.profile_status') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.profile_status_description') }}
                    </p>

                    <ul class="mt-4 space-y-2 text-sm text-rt-text dark:text-rt-dark-text">
                        @foreach ($profileChecks as $key => $done)
                            @continue($done)
                            <li class="flex items-start gap-2">
                                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-rt-red" aria-hidden="true"></span>
                                <span>{{ __('app.' . $key) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a
                        href="{{ route('profile.show') }}"
                        wire:navigate
                        class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-rt-red outline-none transition hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:text-rt-dark-accent"
                    >
                        {{ __('app.complete_profile') }} <span class="ml-1" aria-hidden="true">&rarr;</span>
                    </a>
                </aside>
            @endif
        </div>

        <section class="min-w-0 border-t border-rt-border pt-8 dark:border-rt-dark-border" aria-labelledby="dashboard-files" data-anim="fade-up">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="dashboard-files" class="text-2xl font-semibold tracking-[-0.035em] text-rt-text dark:text-white">
                        {{ __('app.recent_files') }}
                    </h2>
                    <p class="mt-1.5 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.recent_files_description') }}
                    </p>
                </div>
                <a
                    href="{{ route('files') }}"
                    wire:navigate
                    class="inline-flex min-h-11 w-full items-center text-sm font-semibold text-rt-red outline-none transition hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:text-rt-dark-accent sm:w-auto"
                >
                    {{ __('app.all_files') }} <span class="ml-1" aria-hidden="true">&rarr;</span>
                </a>
            </div>

            @if ($recentFiles->isNotEmpty())
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4" data-dashboard-files>
                    @foreach ($recentFiles as $file)
                        <div class="min-w-0" wire:key="dash-file-{{ $file->id }}">
                            <x-ui.filepool.file-card :file="$file" :read-only="true" />
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-5 border-y border-rt-border py-6 text-sm text-rt-muted dark:border-rt-dark-border dark:text-rt-dark-muted" data-dashboard-files>
                    {{ __('app.no_files_available') }}
                </p>
            @endif
        </section>
    </x-ui.page>
</div>
