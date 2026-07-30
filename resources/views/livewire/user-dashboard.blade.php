<div class="relative min-w-0" wire:loading.class="cursor-wait" data-user-dashboard>
    <x-ui.page :auto-intro="false" content-class="space-y-5 sm:space-y-6">
        @if (\App\Support\PageViews::firstVisit(auth()->user(), 'intro:welcome'))
            <x-ui.welcome-intro />
        @endif

        <x-ui.dashboard.role-hero
            :title="__('app.welcome_name', ['name' => auth()->user()->name])"
            :eyebrow="$showSchedule ? __('app.employee_dashboard') : __('app.guest_dashboard')"
            :description="$showSchedule ? __('app.employee_dashboard_description') : __('app.guest_dashboard_description')"
            :icon="$showSchedule ? 'briefcase' : 'compass'"
            heading-id="dashboard-role-title"
            data-anim="fade-up"
        >
            <x-slot:aside>
                <div class="flex h-full flex-col justify-between gap-5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-rt-red-light">
                                {{ __('app.team') }}
                            </p>
                            <p class="mt-1 truncate text-lg font-bold tracking-[-0.02em] text-white">
                                {{ $dashboardTeamName }}
                            </p>
                        </div>
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-inset ring-white/10" aria-hidden="true">
                            <i data-feather="{{ $showSchedule ? 'users' : 'navigation' }}" class="h-5 w-5"></i>
                        </span>
                    </div>

                    <div>
                        <p class="text-sm font-semibold text-white">
                            {{ now()->translatedFormat('l, d. F Y') }}
                        </p>
                        <p class="mt-2 text-pretty text-xs leading-5 text-slate-300">
                            {{ $showSchedule ? __('app.employee_dashboard_next_step') : __('app.guest_dashboard_next_step') }}
                        </p>
                    </div>
                </div>
            </x-slot:aside>

            <x-slot:metrics>
                <div class="grid gap-3 sm:grid-cols-3 sm:gap-4" data-anim-stagger>
                    <x-ui.dashboard.operational-stat
                        :label="__('app.available_files')"
                        :value="number_format($filesTotal, 0, ',', '.')"
                        icon="folder"
                        tone="brand"
                    />
                    <x-ui.dashboard.operational-stat
                        :label="__('app.unread_messages')"
                        :value="number_format($unreadMessages, 0, ',', '.')"
                        icon="mail"
                        :tone="$unreadMessages > 0 ? 'warning' : 'success'"
                    />
                    <x-ui.dashboard.operational-stat
                        :label="__('app.profile_status')"
                        :value="$profileCompletion . ' %'"
                        icon="user-check"
                        :tone="$profileCompletion === 100 ? 'success' : 'neutral'"
                    />
                </div>
            </x-slot:metrics>
        </x-ui.dashboard.role-hero>

        @if ($showSchedule)
            <section aria-labelledby="dashboard-workday-title" data-dashboard-work-focus data-anim="fade-up">
                <x-ui.dashboard.section-heading
                    id="dashboard-workday-title"
                    icon="briefcase"
                    :title="__('app.your_workday')"
                    :description="__('app.employee_dashboard_next_step')"
                />

                <div class="mt-4 grid gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-12">
                    <x-ui.dashboard.focus-card
                        class="xl:col-span-7"
                        :href="$wagonListRoute"
                        icon="clipboard"
                        tone="brand"
                        :title="__('app.order_workspace')"
                        :description="__('app.help_topic_wagon_description')"
                        :badge="__('app.wagon_lists_available')"
                        :action-label="__('app.open_wagon_list')"
                    />

                    <x-ui.dashboard.focus-card
                        class="xl:col-span-5"
                        icon="clock"
                        tone="neutral"
                        :title="__('app.shift_workspace')"
                        :description="__('app.preview_schedule_hint')"
                        :badge="__('app.planning_not_connected')"
                        preview
                    />
                </div>
            </section>
        @endif

        <section
            class="grid min-w-0 gap-3 sm:gap-4 xl:grid-cols-12"
            data-dashboard-real-series
            aria-label="{{ __('app.news_and_information') }}"
            data-anim="fade-up"
        >
            <x-ui.dashboard.trend-chart
                class="xl:col-span-7"
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
                data-series-source="received-messages"
            />

            <x-ui.dashboard.trend-chart
                class="xl:col-span-5"
                :title="__('app.available_files')"
                :description="__('app.recent_files_description')"
                :labels="$fileSources['labels']"
                :values="$fileSources['values']"
                type="bar"
                tone="neutral"
                icon="folder"
                :summary="$filesTotal"
                :summary-label="__('app.files')"
                :empty-label="__('app.no_files_available')"
                data-series-source="available-files"
            />
        </section>

        <div class="grid min-w-0 gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up">
            <section class="min-w-0 rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-7 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-news">
                <x-ui.dashboard.section-heading
                    :title="__('app.news_and_information')"
                    :description="__('app.latest_personal_messages_description')"
                    id="dashboard-news"
                    icon="inbox"
                >
                    <x-slot:actions>
                        <a
                            href="{{ route('messages') }}"
                            wire:navigate
                            class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40"
                        >
                            {{ __('app.show_all') }}
                        </a>
                    </x-slot:actions>
                </x-ui.dashboard.section-heading>

                <div class="mt-5 space-y-2">
                    @forelse ($latestMessages as $message)
                        <a
                            href="{{ route('messages') }}"
                            wire:navigate
                            class="group flex min-h-14 items-start gap-3 rounded-2xl bg-rt-surface-muted/45 px-3.5 py-3 outline-none ring-1 ring-inset ring-transparent transition duration-200 hover:bg-rt-surface-muted/80 hover:ring-rt-border/70 focus-visible:ring-2 focus-visible:ring-rt-accent dark:bg-rt-dark-surface-muted/25 dark:hover:bg-rt-dark-surface-muted/50"
                            wire:key="dash-msg-{{ $message->id }}"
                        >
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ (int) $message->status === 1 ? 'bg-rt-red ring-4 ring-rt-red/10' : 'bg-slate-300 dark:bg-slate-600' }}" aria-hidden="true"></span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold text-rt-text dark:text-rt-dark-text">
                                    {{ $message->subject }}
                                </span>
                                <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                    {{ $message->sender?->name ?? config('app.name') }} · {{ $message->created_at?->diffForHumans() }}
                                </span>
                            </span>
                        </a>
                    @empty
                        <div class="flex min-h-32 flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/35 px-4 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/25">
                            <i data-feather="check-circle" class="h-5 w-5 text-emerald-500" aria-hidden="true"></i>
                            <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.no_messages_yet') }}
                            </p>
                        </div>
                    @endforelse
                </div>
            </section>

            <aside class="min-w-0 rounded-[1.5rem] bg-rt-surface-muted/60 p-4 ring-1 ring-rt-border/70 sm:p-6 lg:col-span-5 dark:bg-rt-dark-surface-muted/35 dark:ring-rt-dark-border/70" aria-labelledby="dashboard-quick-access">
                <x-ui.dashboard.section-heading
                    :title="__('app.quick_access')"
                    :description="__('app.quick_access_description')"
                    id="dashboard-quick-access"
                    icon="zap"
                />

                <nav class="mt-5 grid gap-3 sm:grid-cols-2 sm:gap-4" aria-label="{{ __('app.quick_access') }}">
                    @if ($showSchedule)
                        <x-ui.dashboard.quick-action
                            :href="$wagonListRoute"
                            :title="__('app.wagon_list')"
                            :description="__('app.help_topic_wagon_description')"
                            icon="clipboard"
                        />
                    @else
                        <x-ui.dashboard.quick-action
                            :href="route('profile.show')"
                            :title="__('app.profile')"
                            :description="__('app.profile_status_description')"
                            icon="user"
                        />
                    @endif

                    <x-ui.dashboard.quick-action
                        :href="route('files')"
                        :title="__('app.download_center')"
                        :description="__('app.download_center_short_hint')"
                        icon="download-cloud"
                    />
                    <x-ui.dashboard.quick-action
                        :href="route('messages')"
                        :title="__('app.messages')"
                        :description="__('app.messages_short_hint')"
                        icon="mail"
                    />
                    <x-ui.dashboard.quick-action
                        :href="route('chat')"
                        :title="__('app.chat')"
                        :description="__('app.chat_short_hint')"
                        icon="message-circle"
                    />
                </nav>
            </aside>
        </div>

        <div class="grid min-w-0 gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up">
            <section class="min-w-0 rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-8 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-files">
                <x-ui.dashboard.section-heading
                    :title="__('app.recent_files')"
                    :description="__('app.recent_files_description')"
                    id="dashboard-files"
                    icon="folder"
                >
                    <x-slot:actions>
                        <a
                            href="{{ route('files') }}"
                            wire:navigate
                            class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40"
                        >
                            {{ __('app.all_files') }}
                        </a>
                    </x-slot:actions>
                </x-ui.dashboard.section-heading>

                @if ($recentFiles->isNotEmpty())
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6" data-dashboard-files>
                        @foreach ($recentFiles as $file)
                            <div class="min-w-0" wire:key="dash-file-{{ $file->id }}">
                                <x-ui.filepool.file-card :file="$file" :read-only="true" />
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-5 flex min-h-36 w-full flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/45 px-4 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30" data-dashboard-files>
                        <i class="fad fa-folder-open text-2xl text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                        <span class="text-sm text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.no_files_available') }}
                        </span>
                    </div>
                @endif
            </section>

            <aside class="min-w-0 rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-4 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-profile">
                <x-ui.dashboard.section-heading
                    :title="__('app.profile_status')"
                    :description="__('app.profile_status_description')"
                    id="dashboard-profile"
                    icon="user"
                />

                <div class="mt-5">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="text-rt-muted dark:text-rt-dark-muted">
                            {{ __('app.profile_completion') }}
                        </span>
                        <span class="text-lg font-bold tabular-nums text-rt-text dark:text-rt-dark-text">
                            {{ $profileCompletion }} %
                        </span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-rt-surface-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <div
                            class="h-full origin-left rounded-full {{ $profileCompletion === 100 ? 'bg-emerald-500' : 'bg-rt-red' }}"
                            style="width: {{ max($profileCompletion, 4) }}%"
                            role="progressbar"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="{{ $profileCompletion }}"
                        ></div>
                    </div>
                </div>

                <ul class="mt-5 space-y-2.5 text-sm">
                    @foreach ($profileChecks as $key => $done)
                        <li class="flex items-center gap-2.5">
                            <i class="far {{ $done ? 'fa-check-circle text-emerald-500' : 'fa-circle text-rt-soft dark:text-rt-dark-soft' }}" aria-hidden="true"></i>
                            <span class="{{ $done ? 'text-rt-muted dark:text-rt-dark-muted' : 'text-rt-text dark:text-rt-dark-text' }}">
                                {{ __('app.' . $key) }}
                            </span>
                        </li>
                    @endforeach
                </ul>

                @if ($profileCompletion < 100)
                    <a
                        href="{{ route('profile.show') }}"
                        wire:navigate
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-bold text-white shadow-rt-xs outline-none transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-red-dark hover:shadow-rt-sm focus-visible:ring-2 focus-visible:ring-rt-red focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface sm:w-auto"
                    >
                        {{ __('app.complete_profile') }}
                    </a>
                @else
                    <p class="mt-4 text-xs leading-5 text-rt-soft dark:text-rt-dark-soft">
                        {{ __('app.profile_complete_hint') }}
                    </p>
                @endif
            </aside>
        </div>
    </x-ui.page>
</div>
