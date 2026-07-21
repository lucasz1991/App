<div class="relative" wire:loading.class="cursor-wait">
    <x-ui.page
        :title="__('app.welcome_name', ['name' => auth()->user()->name])"
        eyebrow="RT Rail Time GmbH"
        :description="now()->translatedFormat('l, d. F Y') . ' · ' . __('app.user_area_of', ['app' => config('app.name')])"
    >
        {{-- Kennzahlen (relevant fuer den Mitarbeiter, keine Admin-Statistiken) --}}
        <div class="grid gap-4 sm:grid-cols-3" data-anim-stagger>
            <x-ui.dashboard.stat-card tone="red" :label="__('app.next_shift')" :value="$nextShift ? $nextShift['date'] : '—'">
                <i data-feather="calendar" class="h-6 w-6"></i>
            </x-ui.dashboard.stat-card>

            <x-ui.dashboard.stat-card :label="__('app.available_files')" :value="number_format($filesTotal, 0, ',', '.')">
                <i data-feather="folder" class="h-6 w-6"></i>
            </x-ui.dashboard.stat-card>

            <x-ui.dashboard.stat-card tone="emerald" :label="__('app.unread_messages')" :value="number_format($unreadMessages, 0, ',', '.')">
                <i data-feather="mail" class="h-6 w-6"></i>
            </x-ui.dashboard.stat-card>
        </div>

        {{-- Dienstplan + Termine --}}
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
                    <h2 class="text-base font-semibold text-rt-text dark:text-rt-dark-text">{{ __('app.latest_messages') }}</h2>
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
