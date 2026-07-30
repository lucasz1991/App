<div class="relative min-w-0" data-management-dashboard>
    <x-ui.page :auto-intro="false" content-class="space-y-5 sm:space-y-6">
        <x-ui.dashboard.role-hero
            :title="__('app.welcome_name', ['name' => auth()->user()->name])"
            :eyebrow="$dashboardTeamName"
            :description="__('app.management_dashboard_description')"
            icon="briefcase"
            data-anim="fade-up"
        >
            <x-slot:actions>
                @can('employees.view')
                    <a
                        href="{{ route('employees.index') }}"
                        wire:navigate
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-bold text-white shadow-rt-sm outline-none transition duration-200 ease-rt-spring hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:ring-2 focus-visible:ring-white/70"
                    >
                        <i data-feather="users" class="h-4 w-4" aria-hidden="true"></i>
                        {{ __('app.manage_employees') }}
                    </a>
                @endcan
                <a
                    href="{{ route('operations.wagon-list') }}"
                    wire:navigate
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-white/10 px-4 py-2 text-sm font-bold text-white ring-1 ring-inset ring-white/15 outline-none transition duration-200 hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white/70"
                >
                    <i data-feather="clipboard" class="h-4 w-4" aria-hidden="true"></i>
                    {{ __('app.wagon_list') }}
                </a>
            </x-slot:actions>

            <x-slot:aside>
                <div class="flex h-full flex-col">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-accent text-white shadow-rt-sm" aria-hidden="true">
                        <i data-feather="shield" class="h-5 w-5"></i>
                    </span>
                    <p class="mt-4 text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-slate-400">
                        {{ __('app.team') }}
                    </p>
                    <p class="mt-1 text-lg font-bold tracking-[-0.02em] text-white">
                        {{ $dashboardTeamName }}
                    </p>
                    <p class="mt-2 text-pretty text-xs leading-5 text-slate-300">
                        {{ $canViewSystemData ? __('app.welcome_message_team_administrators') : __('app.welcome_message_team_management') }}
                    </p>
                </div>
            </x-slot:aside>

            <x-slot:metrics>
                <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-anim-stagger>
                    <x-ui.dashboard.stat-card :compact-mobile="true" :label="__('app.total_users')" :value="number_format($totalUsers, 0, ',', '.')">
                        <i data-feather="users" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                    </x-ui.dashboard.stat-card>
                    <x-ui.dashboard.stat-card :compact-mobile="true" tone="success" :label="__('app.active_users')" :value="number_format($activeUsers, 0, ',', '.')">
                        <i data-feather="user-check" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                    </x-ui.dashboard.stat-card>
                    <x-ui.dashboard.stat-card :compact-mobile="true" tone="brand" :label="__('app.employees')" :value="number_format($totalEmployees, 0, ',', '.')">
                        <i data-feather="briefcase" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                    </x-ui.dashboard.stat-card>
                    <x-ui.dashboard.stat-card :compact-mobile="true" :label="__('app.teams_rbac')" :value="number_format($totalTeams, 0, ',', '.')">
                        <i data-feather="shield" class="h-4 w-4 sm:h-5 sm:w-5"></i>
                    </x-ui.dashboard.stat-card>
                </div>
            </x-slot:metrics>
        </x-ui.dashboard.role-hero>

        <section aria-labelledby="workforce-overview-title" data-anim="fade-up">
            <x-ui.dashboard.section-heading
                id="workforce-overview-title"
                icon="briefcase"
                :title="__('app.workforce_control')"
                :description="__('app.workforce_control_description')"
            />

            <div class="mt-4 grid gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-12" data-dashboard-workforce-focus>
                @can('employees.view')
                    <x-ui.dashboard.focus-card
                        class="xl:col-span-5"
                        :href="route('employees.index')"
                        icon="users"
                        tone="brand"
                        :metric="number_format($totalEmployees, 0, ',', '.')"
                        :metric-label="__('app.employees')"
                        :title="__('app.workforce_overview')"
                        :description="__('app.all_employees_hint')"
                        :badge="__('app.online_now') . ': ' . number_format($operations['online'], 0, ',', '.')"
                        :action-label="__('app.manage_employees')"
                    />
                @endcan

                <x-ui.dashboard.focus-card
                    class="xl:col-span-4"
                    :href="route('operations.wagon-list')"
                    icon="clipboard"
                    tone="warning"
                    :title="__('app.order_workspace')"
                    :description="__('app.help_topic_wagon_description')"
                    :badge="__('app.wagon_lists_available')"
                    :action-label="__('app.wagon_list')"
                />

                <x-ui.dashboard.focus-card
                    class="md:col-span-2 xl:col-span-3"
                    icon="clock"
                    tone="neutral"
                    :title="__('app.shift_workspace')"
                    :description="__('app.preview_schedule_hint')"
                    :badge="__('app.planning_not_connected')"
                    preview
                />
            </div>
        </section>

        <section
            class="min-w-0"
            aria-labelledby="management-trends-title"
            data-dashboard-real-series
            data-anim="fade-up"
        >
            <x-ui.dashboard.section-heading
                id="management-trends-title"
                icon="trending-up"
                :title="__('app.user_growth')"
                :description="__('app.last_14_days')"
            />

            <div class="mt-4 grid min-w-0 gap-3 sm:gap-4 md:grid-cols-2 xl:grid-cols-12">
                <x-ui.dashboard.trend-chart
                    class="md:col-span-2 xl:col-span-6"
                    :title="__('app.user_growth')"
                    :description="__('app.last_14_days')"
                    :labels="$charts['userGrowth']['labels']"
                    :values="$charts['userGrowth']['totals']"
                    type="line"
                    tone="brand"
                    icon="trending-up"
                    :summary="$totalUsers"
                    :summary-label="__('app.total_users')"
                    data-series-source="users-growth"
                />

                <x-ui.dashboard.trend-chart
                    class="xl:col-span-3"
                    :title="__('app.recently_active')"
                    :description="__('app.last_14_days')"
                    :labels="$charts['activity']['labels']"
                    :values="$charts['activity']['values']"
                    type="bar"
                    tone="success"
                    icon="activity"
                    :summary="collect($charts['activity']['values'])->last() ?: 0"
                    :summary-label="__('app.current')"
                    :empty-label="__('app.no_activity_yet')"
                    data-series-source="activity-log"
                />

                <x-ui.dashboard.trend-chart
                    class="xl:col-span-3"
                    :title="__('app.active_users') . ' / ' . __('app.inactive_users')"
                    :description="__('app.total_users')"
                    :labels="$charts['status']['labels']"
                    :values="$charts['status']['values']"
                    type="bar"
                    tone="neutral"
                    icon="users"
                    :summary="$totalUsers"
                    :summary-label="__('app.total_users')"
                    data-series-source="user-status"
                />
            </div>
        </section>

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4" aria-labelledby="operations-title" data-anim="fade-up">
            <x-ui.dashboard.section-heading
                class="mb-1 sm:col-span-3"
                id="operations-title"
                icon="activity"
                :title="__('app.operations')"
            />

            <x-ui.dashboard.operational-stat
                icon="radio"
                tone="success"
                :label="__('app.online_now')"
                :value="number_format($operations['online'], 0, ',', '.')"
            />
            <x-ui.dashboard.operational-stat
                icon="mail"
                tone="warning"
                :label="__('app.open_invitations')"
                :value="number_format($operations['openInvitations'], 0, ',', '.')"
            />
            <x-ui.dashboard.operational-stat
                icon="message-circle"
                tone="brand"
                :label="__('app.unread_messages_total')"
                :value="number_format($operations['unreadTotal'], 0, ',', '.')"
            />
        </section>

        <div class="grid gap-3 sm:gap-4 xl:grid-cols-12" data-anim="fade-up">
            <section class="rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 xl:col-span-7 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="recent-activity-title">
                <x-ui.dashboard.section-heading
                    id="recent-activity-title"
                    icon="clock"
                    :title="__('app.recently_active')"
                />

                <ul class="mt-5 space-y-2.5">
                    @forelse ($recentActivity as $entry)
                        <li class="grid grid-cols-[2.5rem_minmax(0,1fr)] items-center gap-x-3 gap-y-0.5 rounded-xl bg-rt-surface-muted/65 px-3 py-3 ring-1 ring-rt-border/60 sm:flex sm:gap-3 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60">
                            <img src="{{ $entry['user']->profile_photo_url }}" alt="" class="row-span-2 h-10 w-10 rounded-xl object-cover ring-1 ring-rt-border/70 sm:row-auto dark:ring-rt-dark-border/70">
                            <div class="min-w-0 sm:flex-1">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $entry['user']->name }}</p>
                                <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $entry['user']->email }}</p>
                            </div>
                            <time class="col-start-2 inline-flex items-center gap-1.5 text-[11px] text-rt-soft sm:col-auto sm:shrink-0 sm:text-xs dark:text-rt-dark-soft" datetime="{{ $entry['lastSeen']->toIso8601String() }}">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                                {{ $entry['lastSeen']->diffForHumans() }}
                            </time>
                        </li>
                    @empty
                        <li class="rounded-xl bg-rt-surface-muted/65 px-4 py-8 text-center ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60">
                            <i data-feather="user-x" class="mx-auto h-5 w-5 text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                            <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_activity_yet') }}</p>
                        </li>
                    @endforelse
                </ul>
            </section>

            <aside class="rounded-[1.5rem] bg-rt-surface-muted/60 p-4 ring-1 ring-rt-border/70 sm:p-6 xl:col-span-5 dark:bg-rt-dark-surface-muted/35 dark:ring-rt-dark-border/70" aria-labelledby="quick-access-title">
                <x-ui.dashboard.section-heading
                    id="quick-access-title"
                    icon="zap"
                    :title="__('app.quick_access')"
                />

                <nav class="mt-5 grid gap-3 sm:grid-cols-2 sm:gap-4" aria-label="{{ __('app.quick_access') }}">
                    @can('employees.view')
                        <x-ui.dashboard.quick-action
                            :href="route('employees.index')"
                            icon="users"
                            :title="__('app.employees')"
                            :description="__('app.all_employees_hint')"
                        />
                    @endcan

                    <x-ui.dashboard.quick-action
                        :href="route('operations.wagon-list')"
                        icon="truck"
                        :title="__('app.wagon_list')"
                        :description="__('app.help_topic_wagon_description')"
                    />

                    <x-ui.dashboard.quick-action
                        :href="route('messages')"
                        icon="message-circle"
                        :title="__('app.messages')"
                        :description="__('app.help_topic_messages_description')"
                    />

                    <x-ui.dashboard.quick-action
                        :href="route('files')"
                        icon="folder"
                        :title="__('app.download_center')"
                        :description="__('app.help_topic_files_description')"
                    />
                </nav>
            </aside>
        </div>

        @if ($canViewSystemData && $system)
            @php
                $systemFacts = [
                    ['label' => __('app.application'), 'value' => $system['appVersion']],
                    ['label' => __('app.environment'), 'value' => $system['environment']],
                    ['label' => __('app.php_version'), 'value' => $system['php']],
                    ['label' => __('app.developer'), 'value' => $system['developer']],
                    ['label' => __('app.database'), 'value' => $system['database']],
                    ['label' => __('app.queue'), 'value' => $system['queue']],
                    ['label' => __('app.file_storage'), 'value' => $system['storage']],
                    ['label' => __('app.server_disk'), 'value' => $system['disk']],
                    ['label' => __('app.last_activity'), 'value' => $system['lastActivityAt']?->diffForHumans() ?? __('app.unknown')],
                ];
            @endphp

            <section class="rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" data-system-dashboard aria-labelledby="system-dashboard-title" data-anim="fade-up">
                <div class="grid gap-3 sm:gap-4 lg:grid-cols-12">
                    <div class="lg:col-span-4">
                        <x-ui.dashboard.section-heading
                            id="system-dashboard-title"
                            icon="server"
                            :title="__('app.technical_system_data')"
                            :description="__('app.technical_system_data_description')"
                        />

                        <div class="mt-5 rounded-2xl bg-rt-text p-4 text-white dark:bg-rt-dark-surface-muted">
                            <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-rt-red-light">
                                {{ __('app.administrator_team') }}
                            </p>
                            <p class="mt-2 text-sm leading-6 text-slate-300">
                                {{ __('app.welcome_message_team_administrators') }}
                            </p>
                        </div>
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:col-span-8">
                        @foreach ($systemFacts as $fact)
                            <div class="rounded-xl bg-rt-surface-muted/65 px-3.5 py-3 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60">
                                <dt class="text-[0.6875rem] font-semibold uppercase tracking-[0.08em] text-rt-muted dark:text-rt-dark-muted">
                                    {{ $fact['label'] }}
                                </dt>
                                <dd class="mt-1 min-w-0 break-words text-sm font-semibold text-rt-text dark:text-white">
                                    {{ $fact['value'] }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>
        @endif
    </x-ui.page>
</div>
