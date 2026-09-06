{{--
    Mitarbeiter- und Gaeste-Dashboard.

    Das vertraute Makrolayout bleibt bestehen: Rollen-Kopf, Arbeitstag,
    Nachrichten/Profil, Dateien und persoenlicher Verlauf. Die Segmente nutzen
    bewusst ruhige Varianten ohne schwere Schatten oder Admin-Inszenierung.
--}}
<div
    class="relative min-w-0"
    wire:loading.class="cursor-wait"
    data-user-dashboard
    data-dashboard-layout="segmented"
    data-dashboard-layout-contract="role-hero-workday-device-news-profile-files-trend"
>
    <x-ui.page :auto-intro="false" content-class="space-y-5 sm:space-y-6">
        <x-ui.welcome-intro
            :initially-open="\App\Support\PageViews::firstVisit(auth()->user(), \App\Support\WelcomeIntroCatalog::TRACKING_KEY)"
        />

        <x-ui.dashboard.role-hero
            :title="__('app.welcome_name', ['name' => auth()->user()->name])"
            :eyebrow="$showSchedule ? __('app.employee_dashboard') : __('app.guest_dashboard')"
            :description="$showSchedule ? __('app.employee_dashboard_description') : __('app.guest_dashboard_description')"
            :icon="$showSchedule ? 'briefcase' : 'compass'"
            heading-id="dashboard-role-title"
            variant="personal"
            data-dashboard-personal-header
            data-dashboard-segment-style="minimal"
            data-anim="fade-up"
        >
            <x-slot:aside>
                <div class="flex h-full flex-col justify-between gap-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">
                                {{ __('app.team') }}
                            </p>
                            <p class="mt-1 truncate text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-white">
                                {{ $dashboardTeamName }}
                            </p>
                        </div>

                        <button
                            type="button"
                            x-on:click="$dispatch('rt-welcome:open')"
                            class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-md border border-rt-border bg-transparent px-3 text-xs font-semibold text-rt-text outline-none transition hover:bg-rt-surface focus-visible:ring-2 focus-visible:ring-rt-red/70 motion-safe:active:scale-[0.98] motion-reduce:transition-none dark:border-rt-dark-border dark:text-white dark:hover:bg-rt-dark-surface"
                            aria-label="{{ __('app.welcome_intro_reopen') }}"
                            title="{{ __('app.welcome_intro_reopen') }}"
                            data-welcome-intro-trigger
                        >
                            {{ __('app.welcome_intro_open_short') }}
                        </button>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-rt-text dark:text-white">
                            {{ now()->translatedFormat('l, d. F Y') }}
                        </p>
                        <p class="mt-2 text-pretty text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                            {{ $showSchedule ? __('app.employee_dashboard_next_step') : __('app.guest_dashboard_next_step') }}
                        </p>
                    </div>
                </div>
            </x-slot:aside>

            <x-slot:metrics>
                <div class="grid gap-3 sm:grid-cols-3 sm:gap-4" data-dashboard-personal-summary data-anim-stagger>
                    <x-ui.dashboard.operational-stat
                        :label="__('app.available_files')"
                        :value="number_format($filesTotal, 0, ',', '.')"
                        icon="folder"
                        tone="brand"
                        variant="minimal"
                    />
                    <x-ui.dashboard.operational-stat
                        :label="__('app.unread_messages')"
                        :value="number_format($unreadMessages, 0, ',', '.')"
                        icon="mail"
                        :tone="$unreadMessages > 0 ? 'warning' : 'success'"
                        variant="minimal"
                    />
                    <x-ui.dashboard.operational-stat
                        :label="__('app.profile_status')"
                        :value="$profileCompletion . ' %'"
                        icon="user-check"
                        :tone="$profileCompletion === 100 ? 'success' : 'neutral'"
                        variant="minimal"
                    />
                </div>
            </x-slot:metrics>
        </x-ui.dashboard.role-hero>

        @if ($showSchedule)
            <section aria-labelledby="dashboard-workday-title" data-dashboard-work-focus data-dashboard-segment-style="minimal" data-anim="fade-up">
                <x-ui.dashboard.section-heading
                    id="dashboard-workday-title"
                    icon="briefcase"
                    :title="__('app.your_workday')"
                    :description="__('app.employee_dashboard_workday_description')"
                    variant="minimal"
                />

                <div class="mt-4 grid gap-3 sm:gap-4 md:grid-cols-2">
                    <x-ui.dashboard.focus-card
                        :href="$wagonListRoute"
                        icon="clipboard"
                        tone="brand"
                        :title="__('app.order_workspace')"
                        :description="__('app.help_topic_wagon_description')"
                        :badge="__('app.wagon_lists_available')"
                        :action-label="__('app.open_wagon_list')"
                        variant="minimal"
                        data-dashboard-primary-action="wagon-list"
                    />

                    <x-ui.dashboard.focus-card
                        icon="clock"
                        tone="neutral"
                        :title="__('app.shift_workspace')"
                        :description="__('app.preview_schedule_hint')"
                        :badge="__('app.planning_not_connected')"
                        variant="minimal"
                        preview
                    />
                </div>
            </section>
        @endif

        <x-ui.dashboard.personal-device-widget
            :stats="$deviceWidget['stats']"
            :href="$deviceWidget['href']"
            variant="panel"
            data-dashboard-segment-style="minimal"
            data-anim="fade-up"
        />

        <div class="grid min-w-0 gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up">
            <section
                class="min-w-0 rounded-xl border border-rt-border/80 bg-rt-surface p-4 sm:p-5 dark:border-rt-dark-border/80 dark:bg-rt-dark-surface {{ $profileCompletion < 100 ? 'lg:col-span-7' : 'lg:col-span-12' }}"
                aria-labelledby="dashboard-news"
                data-dashboard-message-list
                data-dashboard-segment-style="minimal"
            >
                <x-ui.dashboard.section-heading
                    :title="__('app.news_and_information')"
                    :description="__('app.latest_personal_messages_description')"
                    id="dashboard-news"
                    icon="inbox"
                    variant="minimal"
                >
                    <x-slot:actions>
                        <a
                            href="{{ route('messages') }}"
                            wire:navigate
                            class="inline-flex min-h-11 w-full items-center text-sm font-semibold text-rt-red outline-none transition hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:text-rt-dark-accent sm:w-auto"
                        >
                            {{ __('app.show_all') }} <span class="ml-1" aria-hidden="true">&rarr;</span>
                        </a>
                    </x-slot:actions>
                </x-ui.dashboard.section-heading>

                <ul class="mt-4 grid border-t border-rt-border/80 dark:border-rt-dark-border/80 {{ $profileCompletion < 100 ? '' : 'md:grid-cols-3 md:gap-x-4' }}">
                    @forelse ($latestMessages as $message)
                        <li wire:key="dash-msg-{{ $message->id }}">
                            <a
                                href="{{ route('messages', ['open' => $message->id]) }}"
                                wire:navigate
                                class="group grid min-h-16 grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 border-b border-rt-border/80 py-3 outline-none transition hover:border-rt-red/35 focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:border-rt-dark-border/80"
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
                        <li class="flex min-h-28 items-center border-b border-rt-border/80 py-5 text-sm text-rt-muted md:col-span-3 dark:border-rt-dark-border/80 dark:text-rt-dark-muted">
                            {{ __('app.no_messages_yet') }}
                        </li>
                    @endforelse
                </ul>
            </section>

            @if ($profileCompletion < 100)
                <aside
                    class="min-w-0 rounded-xl border border-rt-border/80 bg-rt-surface p-4 sm:p-5 lg:col-span-5 dark:border-rt-dark-border/80 dark:bg-rt-dark-surface"
                    aria-labelledby="dashboard-profile"
                    data-dashboard-profile-reminder
                    data-dashboard-segment-style="minimal"
                >
                    <x-ui.dashboard.section-heading
                        :title="__('app.profile_status')"
                        :description="__('app.profile_status_description')"
                        id="dashboard-profile"
                        icon="user"
                        variant="minimal"
                    />

                    <div class="mt-5">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.profile_completion') }}</span>
                            <span class="text-lg font-semibold tabular-nums text-rt-text dark:text-white">{{ $profileCompletion }} %</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                            <div
                                class="h-full origin-left rounded-full bg-rt-red"
                                style="width: {{ max($profileCompletion, 4) }}%"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-valuenow="{{ $profileCompletion }}"
                            ></div>
                        </div>
                    </div>

                    <ul class="mt-5 space-y-2 text-sm text-rt-text dark:text-rt-dark-text">
                        @foreach ($profileChecks as $key => $done)
                            @continue($done)
                            <li class="flex items-start gap-2">
                                <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-sm bg-rt-red" aria-hidden="true"></span>
                                <span>{{ __('app.' . $key) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a
                        href="{{ route('profile.show') }}"
                        wire:navigate
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-md bg-rt-text px-4 py-2 text-sm font-semibold text-white outline-none transition hover:bg-slate-700 focus-visible:ring-2 focus-visible:ring-rt-red/70 focus-visible:ring-offset-2 motion-safe:active:scale-[0.98] motion-reduce:transition-none dark:bg-slate-700 dark:hover:bg-slate-600 dark:focus-visible:ring-offset-rt-dark-surface sm:w-auto"
                    >
                        {{ __('app.complete_profile') }}
                    </a>
                </aside>
            @endif
        </div>

        <section
            class="min-w-0 rounded-xl border border-rt-border/80 bg-rt-surface p-4 sm:p-5 dark:border-rt-dark-border/80 dark:bg-rt-dark-surface"
            aria-labelledby="dashboard-files"
            data-dashboard-segment-style="minimal"
            data-anim="fade-up"
        >
            <x-ui.dashboard.section-heading
                :title="__('app.recent_files')"
                :description="__('app.recent_files_description')"
                id="dashboard-files"
                icon="folder"
                variant="minimal"
            >
                <x-slot:actions>
                    <a
                        href="{{ route('files') }}"
                        wire:navigate
                        class="inline-flex min-h-11 w-full items-center text-sm font-semibold text-rt-red outline-none transition hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/60 motion-reduce:transition-none dark:text-rt-dark-accent sm:w-auto"
                    >
                        {{ __('app.all_files') }} <span class="ml-1" aria-hidden="true">&rarr;</span>
                    </a>
                </x-slot:actions>
            </x-ui.dashboard.section-heading>

            @if ($filesTotal > 0)
                <ul class="mt-4 flex flex-wrap gap-2" data-dashboard-file-sources aria-label="{{ __('app.available_files') }}">
                    @foreach (array_combine($fileSources['labels'], $fileSources['values']) as $sourceLabel => $sourceCount)
                        <li class="inline-flex items-center gap-2 rounded-md border border-rt-border/70 bg-transparent px-2.5 py-1.5 text-xs font-medium text-rt-muted dark:border-rt-dark-border/70 dark:text-rt-dark-muted">
                            {{ $sourceLabel }}
                            <span class="font-semibold tabular-nums text-rt-text dark:text-white">{{ number_format($sourceCount, 0, ',', '.') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($recentFiles->isNotEmpty())
                <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6" data-dashboard-files>
                    @foreach ($recentFiles as $file)
                        <div class="min-w-0" wire:key="dash-file-{{ $file->id }}">
                            <x-ui.filepool.file-card :file="$file" :read-only="true" />
                        </div>
                    @endforeach
                </div>
            @else
                <div class="mt-5 flex min-h-28 w-full items-center border-y border-rt-border/80 py-5 text-sm text-rt-muted dark:border-rt-dark-border/80 dark:text-rt-dark-muted" data-dashboard-files>
                    {{ __('app.no_files_available') }}
                </div>
            @endif
        </section>

        <div class="min-w-0" data-dashboard-real-series data-dashboard-segment-style="minimal" data-anim="fade-up">
            <x-ui.dashboard.trend-chart
                :title="__('app.messages')"
                :description="__('app.last_14_days')"
                :labels="$messageActivity['labels']"
                :values="$messageActivity['values']"
                type="bar"
                tone="brand"
                icon="mail"
                :summary="$messageActivity['total']"
                :summary-label="__('app.last_14_days')"
                :empty-label="__('app.no_messages_yet')"
                variant="minimal"
                data-series-source="received-messages"
            />
        </div>
    </x-ui.page>
</div>
