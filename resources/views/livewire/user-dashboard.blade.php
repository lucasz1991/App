<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page
        :title="__('app.welcome_name', ['name' => auth()->user()->name])"
        :eyebrow="$dashboardTeamName"
        :description="now()->translatedFormat('l, d. F Y') . ' · ' . __('app.personal_dashboard_description')"
    >
        {{-- Ausschliesslich persoenliche Kennzahlen, niemals Systemstatistiken. --}}
        <div class="grid grid-cols-2 gap-2 sm:gap-4 {{ $showSchedule ? 'xl:grid-cols-4' : 'sm:grid-cols-2' }}" data-anim-stagger>
            @if ($showSchedule)
                <x-ui.dashboard.stat-card :compact-mobile="true" tone="red" :label="__('app.next_shift')" :value="$nextShift ? $nextShift['date'] : '—'">
                    <i data-feather="calendar" class="h-4 w-4 sm:h-6 sm:w-6"></i>
                </x-ui.dashboard.stat-card>

                <x-ui.dashboard.stat-card :compact-mobile="true" tone="sky" :label="__('app.next_order')" :value="$nextOrder['number']">
                    <i data-feather="clipboard" class="h-4 w-4 sm:h-6 sm:w-6"></i>
                </x-ui.dashboard.stat-card>

                <x-ui.dashboard.stat-card :compact-mobile="true" tone="violet" :label="__('app.assignment')" :value="$nextOrder['train']">
                    <i data-feather="map-pin" class="h-4 w-4 sm:h-6 sm:w-6"></i>
                </x-ui.dashboard.stat-card>
            @endif

            @unless ($showSchedule)
                <x-ui.dashboard.stat-card :compact-mobile="true" :label="__('app.available_files')" :value="number_format($filesTotal, 0, ',', '.')">
                    <i data-feather="folder" class="h-4 w-4 sm:h-6 sm:w-6"></i>
                </x-ui.dashboard.stat-card>
            @endunless

            <x-ui.dashboard.stat-card :compact-mobile="true" tone="emerald" :label="__('app.unread_messages')" :value="number_format($unreadMessages, 0, ',', '.')">
                <i data-feather="mail" class="h-4 w-4 sm:h-6 sm:w-6"></i>
            </x-ui.dashboard.stat-card>
        </div>

        @if ($showSchedule)
        <section class="overflow-hidden rounded-2xl bg-slate-950 text-white shadow-rt-md ring-1 ring-slate-900" data-anim="fade-up" aria-labelledby="next-order-title">
            <div class="grid gap-0 lg:grid-cols-[minmax(0,1.45fr)_minmax(18rem,.55fr)]">
                <div class="relative overflow-hidden p-5 sm:p-7">
                    <div class="pointer-events-none absolute -right-24 -top-24 h-64 w-64 rounded-full bg-sky-500/15 blur-3xl"></div>
                    <div class="relative">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-md bg-white/10 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.14em] text-sky-200">{{ __('app.demo_preview') }}</span>
                            <span class="rounded-md bg-emerald-400/10 px-2 py-1 text-[10px] font-semibold text-emerald-200">{{ $nextOrder['status'] }}</span>
                        </div>
                        <p class="mt-5 text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{{ __('app.next_order') }} · {{ $nextOrder['number'] }}</p>
                        <h2 id="next-order-title" class="mt-1 text-2xl font-semibold tracking-tight text-white sm:text-3xl">{{ $nextOrder['train'] }}</h2>
                        <p class="mt-2 text-base font-medium text-slate-200">{{ $nextOrder['route'] }}</p>

                        <dl class="mt-6 grid gap-4 text-sm sm:grid-cols-3">
                            <div><dt class="text-xs text-slate-400">{{ __('app.time') }}</dt><dd class="mt-1 font-semibold">{{ $nextOrder['date'] }} · {{ $nextOrder['time'] }}</dd></div>
                            <div><dt class="text-xs text-slate-400">{{ __('app.assignment') }}</dt><dd class="mt-1 font-semibold">{{ $nextOrder['assignment'] }}</dd></div>
                            <div><dt class="text-xs text-slate-400">{{ __('app.meeting_point') }}</dt><dd class="mt-1 font-semibold">{{ $nextOrder['meetingPoint'] }}</dd></div>
                        </dl>

                        <a href="{{ $wagonListRoute }}" wire:navigate class="mt-6 inline-flex min-h-11 items-center gap-2 rounded-lg bg-sky-500 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-sky-400 active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-sky-300/60">
                            <i class="far fa-edit" aria-hidden="true"></i>
                            {{ __('app.open_wagon_list') }}
                        </a>
                    </div>
                </div>

                <aside class="border-t border-white/10 bg-white/[0.04] p-5 sm:p-7 lg:border-l lg:border-t-0" aria-label="{{ __('app.work_checklist') }}">
                    <h3 class="text-sm font-semibold text-white">{{ __('app.work_checklist') }}</h3>
                    <div class="mt-4 space-y-3">
                        @foreach ($workChecklist as $item)
                            <div class="flex items-center gap-3 text-sm">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg {{ $item['done'] ? 'bg-emerald-400/15 text-emerald-300' : 'bg-white/10 text-slate-400' }}">
                                    <i class="far {{ $item['done'] ? 'fa-check' : 'fa-circle' }} text-xs" aria-hidden="true"></i>
                                </span>
                                <span class="{{ $item['done'] ? 'text-slate-400 line-through' : 'font-medium text-slate-100' }}">{{ $item['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </aside>
            </div>
        </section>

        {{-- Dienstplan + Termine gibt es nur fuer das Team Mitarbeiter. --}}
        <div class="grid gap-6 lg:grid-cols-3" data-anim="fade-up">
            {{-- Naechste Schichten --}}
            <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 lg:col-span-2 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="mb-4">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">{{ __('app.duty_roster') }}</p>
                    <h2 class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.next_shifts') }}</h2>
                </div>

                <div class="space-y-2.5">
                    @foreach ($shifts as $shift)
                        <div class="flex items-center gap-4 rounded-xl bg-rt-surface-muted/60 p-3 ring-1 ring-rt-border/60 transition-all duration-300 ease-rt-spring hover:ring-rt-accent/40 dark:bg-rt-dark-surface-muted/40 dark:ring-rt-dark-border/60">
                            <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-rt-surface text-center shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                                <span class="text-[10px] font-medium uppercase text-rt-muted dark:text-rt-dark-muted">{{ $shift['day'] }}</span>
                                <span class="text-xs font-bold text-rt-text dark:text-rt-dark-text">{{ $shift['date'] }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $shift['title'] }}</p>
                                <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $shift['route'] }}</p>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-sm font-semibold {{ $shift['time'] === 'frei' ? 'text-rt-soft dark:text-rt-dark-soft' : 'text-rt-text dark:text-rt-dark-text' }}">{{ $shift['time'] }}</p>
                                @if ($shift['role'])
                                    <span class="mt-1 hidden sm:inline-block"><x-ui.badge :color="$shift['tone']">{{ $shift['role'] }}</x-ui.badge></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-xs text-rt-soft dark:text-rt-dark-soft">{{ __('app.schedule_hint') }}</p>
            </div>

            {{-- Naechste Termine --}}
            <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h2 class="text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.upcoming_appointments') }}</h2>
                <div class="mt-4 space-y-3">
                    @foreach ($plans as $plan)
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-12 shrink-0 flex-col items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                                <span class="text-xs font-bold">{{ $plan['date'] }}</span>
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-rt-text dark:text-rt-dark-text">{{ $plan['title'] }}</p>
                                <p class="truncate text-xs text-rt-muted dark:text-rt-dark-muted">{{ $plan['meta'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Möglichkeiten & Infos: Schnellzugriff, Nachrichten, Profil-Status --}}
        <div class="grid gap-6 md:grid-cols-3" data-anim="fade-up" data-anim-delay="0.05">
            {{-- Schnellzugriff --}}
            <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.quick_access') }}</h2>
                <div class="mt-4 space-y-2">
                    <a href="{{ route('files') }}" wire:navigate
                       class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="download-cloud" class="h-4 w-4"></i>
                        {{ __('app.download_center') }}
                    </a>
                    <a href="{{ route('messages') }}" wire:navigate
                       class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="mail" class="h-4 w-4"></i>
                        {{ __('app.messages') }}
                    </a>
                    <a href="{{ route('chat') }}" wire:navigate
                       class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="message-circle" class="h-4 w-4"></i>
                        {{ __('app.chat') }}
                    </a>
                    <a href="{{ route('email-templates.index') }}" wire:navigate
                       class="flex items-center gap-3 rounded-lg border border-slate-200 px-4 py-3 text-sm font-medium text-slate-700 transition-all duration-300 ease-rt-spring hover:-translate-y-0.5 hover:border-rt-red/40 hover:bg-rt-red/5 hover:text-rt-red hover:shadow-rt-xs active:scale-[0.98] dark:border-slate-700 dark:text-slate-300 dark:hover:border-rt-red/40 dark:hover:bg-slate-700 dark:hover:text-rt-red">
                        <i data-feather="file-text" class="h-4 w-4"></i>
                        {{ __('app.email_templates') }}
                    </a>
                </div>
            </div>

            {{-- Neueste Nachrichten --}}
            <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.news_and_information') }}</h2>
                    <a href="{{ route('messages') }}" wire:navigate class="text-sm font-medium text-rt-red transition-all duration-300 ease-rt-spring hover:text-rt-red-dark">
                        {{ __('app.show_all') }}
                    </a>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($latestMessages as $message)
                        <a href="{{ route('messages') }}" wire:navigate class="flex items-start gap-3 rounded-lg px-2 py-1.5 transition-all duration-300 ease-rt-spring hover:bg-rt-surface-muted/60 dark:hover:bg-rt-dark-surface-muted/40" wire:key="dash-msg-{{ $message->id }}">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full {{ (int) $message->status === 1 ? 'bg-rt-red' : 'bg-slate-300 dark:bg-slate-600' }}"></span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $message->subject }}</span>
                                <span class="block truncate text-xs text-rt-muted dark:text-rt-dark-muted">
                                    {{ $message->sender?->name ?? config('app.name') }} · {{ $message->created_at?->diffForHumans() }}
                                </span>
                            </span>
                        </a>
                    @empty
                        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_messages_yet') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- Profil-Status --}}
            <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.profile_status') }}</h2>
                <div class="mt-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-rt-muted dark:text-rt-dark-muted">{{ __('app.profile_completion') }}</span>
                        <span class="font-semibold text-rt-text dark:text-rt-dark-text">{{ $profileCompletion }} %</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-rt-surface-muted ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted dark:ring-rt-dark-border/60">
                        <div class="h-full rounded-full {{ $profileCompletion === 100 ? 'bg-emerald-500' : 'bg-rt-red' }}" style="width: {{ max($profileCompletion, 4) }}%"></div>
                    </div>
                </div>
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($profileChecks as $key => $done)
                        <li class="flex items-center gap-2">
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
                       class="mt-4 inline-flex items-center gap-2 rounded-lg bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-xs transition-all duration-300 ease-rt-spring hover:bg-rt-red-dark">
                        {{ __('app.complete_profile') }}
                    </a>
                @else
                    <p class="mt-4 text-xs text-rt-soft dark:text-rt-dark-soft">{{ __('app.profile_complete_hint') }}</p>
                @endif
            </div>
        </div>

        {{-- Aktuelle Dateien --}}
        <div class="rounded-xl bg-rt-surface p-6 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-anim="fade-up" data-anim-delay="0.05">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">{{ __('app.downloads') }}</p>
                    <h2 class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ __('app.recent_files') }}</h2>
                </div>
                <a href="{{ route('files') }}" wire:navigate class="text-sm font-medium text-rt-red transition-all duration-300 ease-rt-spring hover:text-rt-red-dark">
                    {{ __('app.all_files') }}
                </a>
            </div>

            @if ($recentFiles->isNotEmpty())
                <div class="flex flex-wrap gap-4">
                    @foreach ($recentFiles as $file)
                        <div class="w-32" wire:key="dash-file-{{ $file->id }}">
                            <x-ui.filepool.file-card :file="$file" :read-only="true" />
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex w-full flex-col items-center gap-2 rounded-xl border border-dashed border-rt-border bg-rt-surface-muted/60 py-10 text-center dark:border-rt-dark-border dark:bg-rt-dark-surface-muted/40">
                    <i class="fad fa-folder-open text-2xl text-rt-soft dark:text-rt-dark-soft"></i>
                    <span class="text-sm text-rt-muted dark:text-rt-dark-muted">{{ __('app.no_files_available') }}</span>
                </div>
            @endif
        </div>
    </x-ui.page>
</div>
