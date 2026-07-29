<div class="relative min-w-0" wire:loading.class="cursor-wait" data-user-dashboard>
    <x-ui.page :auto-intro="false">
        {{-- Allererster Besuch der Anwendung: festliches Willkommens-Intro
             (ersetzt hier die automatische Seiteninfo). --}}
        @if (\App\Support\PageViews::firstVisit(auth()->user(), 'intro:welcome'))
            <x-ui.welcome-intro />
        @endif

        <div class="space-y-5 sm:space-y-6" data-dashboard-stack>
        {{-- Gemeinsamer, klar markierter Einstieg fuer Mitarbeiter und Gaeste. --}}
        <x-ui.dashboard.role-hero
            :title="__('app.welcome_name', ['name' => auth()->user()->name])"
            :eyebrow="$showSchedule ? __('app.employee_dashboard') : __('app.guest_dashboard')"
            :description="$showSchedule ? __('app.employee_dashboard_description') : __('app.guest_dashboard_description')"
            :icon="$showSchedule ? 'briefcase' : 'compass'"
            heading-id="dashboard-role-title"
            data-anim="fade-up"
        >
            <x-slot:aside>
                <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-rt-red-light">
                    {{ $showSchedule ? __('app.your_workday') : __('app.welcome_aboard') }}
                </p>
                <p class="mt-2 text-lg font-bold tracking-tight text-white">
                    {{ now()->translatedFormat('l, d. F Y') }}
                </p>
                <p class="mt-2 text-sm leading-6 text-slate-300">
                    {{ $showSchedule ? __('app.employee_dashboard_next_step') : __('app.guest_dashboard_next_step') }}
                </p>
            </x-slot:aside>

            <x-slot:metrics>
                {{-- Nur echte persoenliche Kennzahlen; Vorschau-Inhalte folgen separat. --}}
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
        <section class="relative overflow-hidden rounded-[1.75rem] bg-rt-surface shadow-rt-md ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" data-anim="fade-up" aria-labelledby="next-order-title">
            <span class="absolute inset-y-0 left-0 w-1 bg-rt-red" aria-hidden="true"></span>
            <div class="grid gap-0 lg:grid-cols-[minmax(0,1.5fr)_minmax(17rem,.5fr)]">
                <div class="relative overflow-hidden p-4 sm:p-7">
                    <div class="pointer-events-none absolute -right-24 -top-24 h-64 w-64 rounded-full bg-rt-red/10 blur-3xl"></div>
                    <div class="relative">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-lg bg-rt-red/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.14em] text-rt-red ring-1 ring-inset ring-rt-red/15">{{ __('app.demo_preview') }}</span>
                            <span class="rounded-lg bg-emerald-500/10 px-2.5 py-1 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-500/15 dark:text-emerald-300">{{ $nextOrder['status'] }}</span>
                        </div>
                        <p class="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">{{ __('app.next_order') }} · {{ $nextOrder['number'] }}</p>
                        <h2 id="next-order-title" class="mt-1 text-3xl font-bold tracking-[-0.04em] text-rt-text sm:text-4xl dark:text-white">{{ $nextOrder['train'] }}</h2>
                        <p class="mt-2 text-base font-semibold text-rt-muted dark:text-rt-dark-muted">{{ $nextOrder['route'] }}</p>

                        <dl class="mt-5 grid gap-3 text-sm sm:mt-6 sm:grid-cols-3 sm:gap-4">
                            <div class="rounded-2xl bg-rt-surface-muted/70 p-3.5 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted/50 dark:ring-rt-dark-border/60">
                                <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.time') }}</dt>
                                <dd class="mt-1 font-bold text-rt-text dark:text-white">{{ $nextOrder['date'] }} · {{ $nextOrder['time'] }}</dd>
                            </div>
                            <div class="rounded-2xl bg-rt-surface-muted/70 p-3.5 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted/50 dark:ring-rt-dark-border/60">
                                <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.assignment') }}</dt>
                                <dd class="mt-1 font-bold text-rt-text dark:text-white">{{ $nextOrder['assignment'] }}</dd>
                            </div>
                            <div class="rounded-2xl bg-rt-surface-muted/70 p-3.5 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted/50 dark:ring-rt-dark-border/60">
                                <dt class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ __('app.meeting_point') }}</dt>
                                <dd class="mt-1 font-bold text-rt-text dark:text-white">{{ $nextOrder['meetingPoint'] }}</dd>
                            </div>
                        </dl>

                        <a href="{{ $wagonListRoute }}" wire:navigate class="mt-6 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-5 py-2.5 text-sm font-bold text-white shadow-rt-sm outline-none transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-red-dark hover:shadow-rt-md active:translate-y-0 active:scale-[0.98] focus-visible:ring-2 focus-visible:ring-rt-red focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface sm:w-auto">
                            <i class="far fa-edit" aria-hidden="true"></i>
                            {{ __('app.open_wagon_list') }}
                        </a>
                    </div>
                </div>

                <aside class="border-t border-rt-border/70 bg-rt-surface-muted/60 p-4 sm:p-7 lg:border-l lg:border-t-0 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35" aria-label="{{ __('app.work_checklist') }}">
                    <h3 class="text-sm font-bold text-rt-text dark:text-white">{{ __('app.work_checklist') }}</h3>
                    <p class="mt-1 text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
                        {{ __('app.preview_workflow_hint') }}
                    </p>
                    <div class="mt-4 space-y-3">
                        @foreach ($workChecklist as $item)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl ring-1 ring-inset {{ $item['done'] ? 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/15 dark:text-emerald-300' : 'bg-rt-surface text-rt-soft ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-soft dark:ring-rt-dark-border/70' }}">
                                    <i class="far {{ $item['done'] ? 'fa-check' : 'fa-circle' }} text-xs" aria-hidden="true"></i>
                                </span>
                                <span class="{{ $item['done'] ? 'text-rt-soft line-through dark:text-rt-dark-soft' : 'font-semibold text-rt-text dark:text-rt-dark-text' }}">{{ $item['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </aside>
            </div>
        </section>

        {{-- Dienstplan + Termine gibt es nur fuer das Team Mitarbeiter und sind klar als Vorschau markiert. --}}
        <div class="grid gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up">
            {{-- Naechste Schichten --}}
            <section class="min-w-0 rounded-[1.75rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-8 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-next-shifts">
                <x-ui.dashboard.section-heading
                    :title="__('app.next_shifts')"
                    :description="__('app.demo_preview') . ' · ' . __('app.preview_schedule_hint')"
                    id="dashboard-next-shifts"
                    icon="calendar"
                />

                <div class="mt-5 space-y-2.5">
                    @foreach ($shifts as $shift)
                        <div class="grid grid-cols-[3rem_minmax(0,1fr)] items-center gap-x-3 gap-y-1 rounded-2xl bg-rt-surface-muted/60 p-3 ring-1 ring-rt-border/60 transition duration-200 ease-rt-spring hover:ring-rt-accent/35 sm:flex sm:gap-4 dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                            <div class="row-span-2 flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-rt-surface text-center shadow-rt-xs ring-1 ring-rt-border/60 sm:row-auto dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                                <span class="text-[10px] font-medium uppercase text-rt-muted dark:text-rt-dark-muted">{{ $shift['day'] }}</span>
                                <span class="text-xs font-bold text-rt-text dark:text-rt-dark-text">{{ $shift['date'] }}</span>
                            </div>
                            <div class="min-w-0 sm:flex-1">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $shift['title'] }}</p>
                                <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift['route'] }}</p>
                            </div>
                            <div class="col-start-2 min-w-0 text-left sm:col-auto sm:shrink-0 sm:text-right">
                                <p class="text-sm font-semibold {{ $shift['time'] === 'frei' ? 'text-rt-soft dark:text-rt-dark-soft' : 'text-rt-text dark:text-rt-dark-text' }}">{{ $shift['time'] }}</p>
                                @if ($shift['role'])
                                    <span class="mt-1 hidden sm:inline-block"><x-ui.badge :color="$shift['tone']">{{ $shift['role'] }}</x-ui.badge></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-xs text-rt-soft dark:text-rt-dark-soft">{{ __('app.schedule_hint') }}</p>
            </section>

            {{-- Naechste Termine --}}
            <section class="min-w-0 rounded-[1.75rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-4 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-appointments">
                <x-ui.dashboard.section-heading
                    :title="__('app.upcoming_appointments')"
                    :description="__('app.demo_preview') . ' · ' . __('app.preview_appointments_hint')"
                    id="dashboard-appointments"
                    icon="clock"
                />
                <div class="mt-5 space-y-3">
                    @foreach ($plans as $plan)
                        <div class="flex items-center gap-3 rounded-2xl bg-rt-surface-muted/60 p-3 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                            <div class="flex h-11 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                                <span class="text-xs font-bold">{{ $plan['date'] }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $plan['title'] }}</p>
                                <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $plan['meta'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
        @endif

        {{-- Möglichkeiten & Infos: Schnellzugriff, Nachrichten, Profil-Status --}}
        <div class="grid gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up" data-anim-delay="0.05">
            {{-- Schnellzugriff --}}
            <section class="min-w-0 rounded-[1.75rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-5 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-quick-access">
                <x-ui.dashboard.section-heading
                    :title="__('app.quick_access')"
                    :description="__('app.quick_access_description')"
                    id="dashboard-quick-access"
                    icon="zap"
                />
                <div class="mt-5 grid gap-3 sm:grid-cols-2 sm:gap-4">
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
                    <x-ui.dashboard.quick-action
                        :href="route('email-templates.index')"
                        :title="__('app.email_templates')"
                        :description="__('app.email_templates_short_hint')"
                        icon="file-text"
                    />
                </div>
            </section>

            {{-- Neueste Nachrichten --}}
            <section class="min-w-0 rounded-[1.75rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-7 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-news">
                <x-ui.dashboard.section-heading
                    :title="__('app.news_and_information')"
                    :description="__('app.latest_personal_messages_description')"
                    id="dashboard-news"
                    icon="inbox"
                >
                    <x-slot:actions>
                        <a href="{{ route('messages') }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40">
                        {{ __('app.show_all') }}
                        </a>
                    </x-slot:actions>
                </x-ui.dashboard.section-heading>

                <div class="mt-5 space-y-2">
                    @forelse ($latestMessages as $message)
                        <a href="{{ route('messages') }}" wire:navigate class="group flex min-h-14 items-start gap-3 rounded-2xl bg-rt-surface-muted/45 px-3.5 py-3 outline-none ring-1 ring-inset ring-transparent transition duration-200 hover:bg-rt-surface-muted/80 hover:ring-rt-border/70 focus-visible:ring-2 focus-visible:ring-rt-accent dark:bg-rt-dark-surface-muted/25 dark:hover:bg-rt-dark-surface-muted/50" wire:key="dash-msg-{{ $message->id }}">
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ (int) $message->status === 1 ? 'bg-rt-red ring-4 ring-rt-red/10' : 'bg-slate-300 dark:bg-slate-600' }}"></span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-bold text-rt-text dark:text-rt-dark-text">{{ $message->subject }}</span>
                                <span class="mt-0.5 block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                    {{ $message->sender?->name ?? config('app.name') }} · {{ $message->created_at?->diffForHumans() }}
                                </span>
                            </span>
                        </a>
                    @empty
                        <div class="flex min-h-32 flex-col items-center justify-center rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/35 px-4 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/25">
                            <i data-feather="check-circle" class="h-5 w-5 text-emerald-500" aria-hidden="true"></i>
                            <p class="mt-2 text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_messages_yet') }}</p>
                        </div>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="grid gap-3 sm:gap-4 lg:grid-cols-12" data-anim="fade-up" data-anim-delay="0.08">
            {{-- Profil-Status --}}
            <section class="min-w-0 rounded-[1.75rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-4 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-profile">
                <x-ui.dashboard.section-heading
                    :title="__('app.profile_status')"
                    :description="__('app.profile_status_description')"
                    id="dashboard-profile"
                    icon="user"
                />
                <div class="mt-5">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.profile_completion') }}</span>
                        <span class="text-lg font-bold tabular-nums text-rt-text dark:text-rt-dark-text">{{ $profileCompletion }} %</span>
                    </div>
                    <div class="mt-2 h-2.5 overflow-hidden rounded-full bg-rt-surface-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <div class="h-full rounded-full {{ $profileCompletion === 100 ? 'bg-emerald-500' : 'bg-rt-red' }}" style="width: {{ max($profileCompletion, 4) }}%"></div>
                    </div>
                </div>
                <ul class="mt-5 space-y-2.5 text-sm">
                    @foreach ($profileChecks as $key => $done)
                        <li class="flex items-center gap-2.5">
                            @if ($done)
                                <i class="far fa-check-circle text-emerald-500" aria-hidden="true"></i>
                                <span class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.' . $key) }}</span>
                            @else
                                <i class="far fa-circle text-rt-soft dark:text-rt-dark-soft" aria-hidden="true"></i>
                                <span class="text-rt-text dark:text-rt-dark-text">{{ __('app.' . $key) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($profileCompletion < 100)
                    <a href="{{ route('profile.show') }}"
                       wire:navigate
                       class="mt-5 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-bold text-white shadow-rt-xs outline-none transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-red-dark hover:shadow-rt-sm focus-visible:ring-2 focus-visible:ring-rt-red focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface sm:w-auto">
                        {{ __('app.complete_profile') }}
                    </a>
                @else
                    <p class="mt-4 text-xs text-rt-soft dark:text-rt-dark-soft">{{ __('app.profile_complete_hint') }}</p>
                @endif
            </section>

        {{-- Aktuelle Dateien --}}
        <section class="min-w-0 rounded-[1.75rem] bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 sm:p-6 lg:col-span-8 dark:bg-rt-dark-surface dark:ring-rt-dark-border/70" aria-labelledby="dashboard-files">
            <x-ui.dashboard.section-heading
                :title="__('app.recent_files')"
                :description="__('app.recent_files_description')"
                id="dashboard-files"
                icon="folder"
            >
                <x-slot:actions>
                    <a href="{{ route('files') }}" wire:navigate class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-bold text-rt-red outline-none transition duration-200 hover:bg-rt-red/5 hover:text-rt-red-dark focus-visible:ring-2 focus-visible:ring-rt-red/40">
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
                <div class="mt-5 flex w-full flex-col items-center gap-2 rounded-2xl border border-dashed border-rt-border bg-rt-surface-muted/45 py-7 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/30" data-dashboard-files>
                    <i class="fad fa-folder-open text-2xl text-rt-soft dark:text-rt-dark-soft"></i>
                    <span class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_files_available') }}</span>
                </div>
            @endif
        </section>
        </div>
        </div>
    </x-ui.page>
</div>
