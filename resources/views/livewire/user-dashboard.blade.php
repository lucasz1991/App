{{--
    Mitarbeiter- und Gaeste-Dashboard.

    Gliederung:
      Kopf          -> der eigene Stand (Dateien, Ungelesene, Profil)
      Arbeitstag    -> nur fuer Mitarbeiter
      Neuigkeiten   -> letzte Nachrichten, daneben das Profil (nur unvollstaendig)
      Dateien       -> zuletzt bereitgestellt, mit Herkunft als Chips
      Verlauf       -> Nachrichteneingang der letzten 14 Tage

    Schnellzugriffe gibt es nicht mehr: die vier Kacheln waren eine Kopie der
    dauerhaft sichtbaren Seitennavigation. Das Balkendiagramm "Verfuegbare
    Dateien" ist ebenfalls entfallen — drei Herkunftszahlen sind kein Verlauf,
    sie stehen jetzt als Chips ueber der Dateiliste.
--}}
<div class="relative min-w-0" wire:loading.class="cursor-wait" data-user-dashboard>
    <x-ui.page :auto-intro="false" content-class="space-y-5 sm:space-y-6">
        <x-ui.welcome-intro
            :initially-open="\App\Support\PageViews::firstVisit(auth()->user(), 'intro:welcome')"
        />

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
                        <button
                            type="button"
                            x-on:click="$dispatch('rt-welcome:open')"
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-inset ring-white/15 outline-none transition hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white/75"
                            aria-label="{{ __('app.welcome_intro_reopen') }}"
                            title="{{ __('app.welcome_intro_reopen') }}"
                            data-welcome-intro-trigger
                        >
                            <i data-feather="info" class="h-5 w-5" aria-hidden="true"></i>
                        </button>
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

                <div class="mt-4 grid gap-3 sm:gap-4 md:grid-cols-2">
                    <x-ui.dashboard.focus-card
                        :href="$wagonListRoute"
                        icon="clipboard"
                        tone="brand"
                        :title="__('app.order_workspace')"
                        :description="__('app.help_topic_wagon_description')"
                        :badge="__('app.wagon_lists_available')"
                        :action-label="__('app.open_wagon_list')"
                    />

                    <x-ui.dashboard.focus-card
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

        {{-- Nachrichten und — nur solange etwas fehlt — der Profilstand.
             Ist das Profil vollstaendig, nimmt die Nachrichtenliste die
             ganze Breite ein, statt einen Erledigt-Hinweis stehen zu lassen. --}}
        <div class="grid min-w-0 gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up">
            <section class="min-w-0 rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 {{ $profileCompletion < 100 ? 'lg:col-span-7' : 'lg:col-span-12' }}" aria-labelledby="dashboard-news">
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
                            class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40"
                        >
                            {{ __('app.show_all') }}
                            <i data-feather="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                        </a>
                    </x-slot:actions>
                </x-ui.dashboard.section-heading>

                <div class="mt-5 grid gap-2 {{ $profileCompletion < 100 ? '' : 'md:grid-cols-3' }}">
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
                        <div class="flex min-h-32 flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/35 px-4 text-center md:col-span-3 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/25">
                            <i data-feather="check-circle" class="h-5 w-5 text-emerald-500" aria-hidden="true"></i>
                            <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">
                                {{ __('app.no_messages_yet') }}
                            </p>
                        </div>
                    @endforelse
                </div>
            </section>

            @if ($profileCompletion < 100)
                <aside class="min-w-0 rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-profile">
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
                                class="h-full origin-left rounded-full bg-rt-red"
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

                    <a
                        href="{{ route('profile.show') }}"
                        wire:navigate
                        class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-bold text-white shadow-rt-xs outline-none transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-red-dark hover:shadow-rt-sm focus-visible:ring-2 focus-visible:ring-rt-red focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface sm:w-auto"
                    >
                        {{ __('app.complete_profile') }}
                    </a>
                </aside>
            @endif
        </div>

        <section class="min-w-0 rounded-[1.5rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-files" data-anim="fade-up">
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
                        class="inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40"
                    >
                        {{ __('app.all_files') }}
                        <i data-feather="arrow-up-right" class="h-4 w-4" aria-hidden="true"></i>
                    </a>
                </x-slot:actions>
            </x-ui.dashboard.section-heading>

            {{-- Die Herkunft der Dateien als Chips statt als Balkendiagramm:
                 drei feste Kategorien sind kein Verlauf. --}}
            @if ($filesTotal > 0)
                <ul class="mt-4 flex flex-wrap gap-2" data-dashboard-file-sources aria-label="{{ __('app.available_files') }}">
                    @foreach (array_combine($fileSources['labels'], $fileSources['values']) as $sourceLabel => $sourceCount)
                        <li class="inline-flex items-center gap-2 rounded-full bg-rt-surface-muted/70 px-3 py-1.5 text-xs font-semibold text-rt-muted ring-1 ring-rt-border/60 dark:bg-rt-dark-surface-muted/45 dark:text-rt-dark-muted dark:ring-rt-dark-border/60">
                            {{ $sourceLabel }}
                            <span class="tabular-nums text-rt-text dark:text-white">{{ number_format($sourceCount, 0, ',', '.') }}</span>
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
                <div class="mt-5 flex min-h-36 w-full flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/45 px-4 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30" data-dashboard-files>
                    <i class="fad fa-folder-open text-2xl text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                    <span class="text-sm text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.no_files_available') }}
                    </span>
                </div>
            @endif
        </section>

        {{-- Das Bauteil bringt seine eigene Beschriftung mit (aria-labelledby
             auf den Kartentitel) — der Rahmen ist deshalb ein schlichtes div. --}}
        <div class="min-w-0" data-dashboard-real-series data-anim="fade-up">
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
                data-series-source="received-messages"
            />
        </div>
    </x-ui.page>
</div>
