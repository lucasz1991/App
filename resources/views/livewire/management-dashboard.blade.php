<div class="relative">
    <x-ui.page
        :title="__('app.welcome_name', ['name' => auth()->user()->name])"
        :eyebrow="$dashboardTeamName"
        :description="__('app.management_dashboard_description')"
    >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-anim-stagger>
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

        <section class="grid gap-4 sm:grid-cols-3" aria-labelledby="operations-title" data-anim="fade-up">
            <h2 id="operations-title" class="sr-only">{{ __('app.operations') }}</h2>
            <div class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.online_now') }}</p>
                <p class="mt-2 text-2xl font-bold text-rt-text dark:text-white">{{ number_format($operations['online'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.open_invitations') }}</p>
                <p class="mt-2 text-2xl font-bold text-rt-text dark:text-white">{{ number_format($operations['openInvitations'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.unread_messages_total') }}</p>
                <p class="mt-2 text-2xl font-bold text-rt-text dark:text-white">{{ number_format($operations['unreadTotal'], 0, ',', '.') }}</p>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-2" data-anim="fade-up">
            <section class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h2 class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.recently_active') }}</h2>
                <div class="mt-4 space-y-2.5">
                    @forelse ($recentActivity as $entry)
                        <div class="flex items-center gap-3 rounded-lg bg-rt-surface-muted/60 px-3 py-2.5 ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                            <img src="{{ $entry['user']->profile_photo_url }}" alt="" class="h-9 w-9 rounded-full object-cover">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-white">{{ $entry['user']->name }}</p>
                                <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $entry['user']->email }}</p>
                            </div>
                            <time class="shrink-0 text-xs text-rt-soft dark:text-rt-dark-soft" datetime="{{ $entry['lastSeen']->toIso8601String() }}">
                                {{ $entry['lastSeen']->diffForHumans() }}
                            </time>
                        </div>
                    @empty
                        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_activity_yet') }}</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h2 class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.system_status') }}</h2>
                <dl class="mt-4 divide-y divide-rt-border/60 text-sm dark:divide-rt-dark-border/60">
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.application') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['appVersion'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.environment') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['environment'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.php_version') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['php'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">Laravel</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['laravel'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.database') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['database'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.queue') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['queue'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.file_storage') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['storage'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.server_disk') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['disk'] }}</dd></div>
                    <div class="flex items-center justify-between gap-4 py-2.5"><dt class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.last_activity') }}</dt><dd class="text-right font-medium text-rt-text dark:text-white">{{ $system['lastActivityAt']?->diffForHumans() ?? '—' }}</dd></div>
                </dl>
            </section>
        </div>
    </x-ui.page>
</div>
