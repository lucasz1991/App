@section('title', __('app.help'))

<x-ui.page
    :title="__('app.help')"
    :eyebrow="__('app.help_center_eyebrow')"
    :description="__('app.help_center_description')"
>
    <div class="space-y-5" data-help-center>
        <section class="relative overflow-hidden rounded-2xl bg-slate-950 px-4 py-5 text-white shadow-rt-md sm:px-6 sm:py-7">
            <div class="pointer-events-none absolute -right-20 -top-24 h-64 w-64 rounded-full bg-rt-red/20 blur-3xl"></div>
            <div class="relative grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,.5fr)] lg:items-end">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-rose-300">{{ __('app.help_quick_help') }}</p>
                    <h2 class="mt-2 max-w-2xl text-2xl font-semibold tracking-[-0.035em] text-white sm:text-3xl">
                        {{ __('app.help_find_answer') }}
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">{{ __('app.help_find_answer_description') }}</p>
                </div>

                <label class="group relative block">
                    <span class="sr-only">{{ __('app.search_help') }}</span>
                    <i data-feather="search" class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 transition group-focus-within:text-rose-300"></i>
                    <input
                        type="search"
                        wire:model.live.debounce.250ms="query"
                        class="min-h-12 w-full rounded-xl border border-white/15 bg-white/10 py-3 pl-11 pr-4 text-sm text-white outline-none backdrop-blur placeholder:text-slate-400 focus:border-rose-300 focus:bg-white/15 focus:ring-2 focus:ring-rose-300/20"
                        placeholder="{{ __('app.search_help_placeholder') }}"
                    >
                </label>
            </div>
        </section>

        <section aria-labelledby="help-topics-heading">
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.help_topics') }}</p>
                    <h2 id="help-topics-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-white">{{ __('app.help_direct_links') }}</h2>
                </div>
                <a href="{{ route('support') }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                    <i data-feather="life-buoy" class="h-4 w-4"></i>
                    {{ __('app.contact_support') }}
                </a>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @forelse ($topics as $topic)
                    <a
                        href="{{ $topic['href'] }}"
                        wire:navigate
                        class="group flex min-h-36 flex-col rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 transition duration-300 ease-rt-spring hover:-translate-y-1 hover:shadow-rt-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
                    >
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent transition group-hover:bg-rt-red group-hover:text-white dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                            <i data-feather="{{ $topic['icon'] }}" class="h-5 w-5"></i>
                        </span>
                        <span class="mt-4 block text-sm font-semibold text-rt-text dark:text-white">{{ $topic['title'] }}</span>
                        <span class="mt-1 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $topic['description'] }}</span>
                    </a>
                @empty
                    <div class="rounded-2xl bg-rt-surface-muted px-5 py-8 text-center sm:col-span-2 xl:col-span-4 dark:bg-rt-dark-surface-muted">
                        <i data-feather="search" class="mx-auto h-6 w-6 text-rt-soft dark:text-rt-dark-soft"></i>
                        <p class="mt-3 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.help_no_results') }}</p>
                        <button type="button" wire:click="$set('query', '')" class="mt-2 text-sm font-semibold text-rt-red hover:text-rt-red-dark">
                            {{ __('app.reset_search') }}
                        </button>
                    </div>
                @endforelse
            </div>
        </section>

        <section
            class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60"
            aria-labelledby="install-app-heading"
            x-data="{
                secure: window.isSecureContext,
                serviceWorker: 'serviceWorker' in navigator,
                permission: 'Notification' in window ? Notification.permission : 'unsupported',
                standalone: window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true
            }"
        >
            <header class="grid gap-4 border-b border-rt-border/60 bg-rt-surface-muted p-4 dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted sm:p-6 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-rt-red text-white shadow-rt-glow">
                            <i data-feather="smartphone" class="h-5 w-5"></i>
                        </span>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.help_install_eyebrow') }}</p>
                            <h2 id="install-app-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-white">{{ __('app.help_install_title') }}</h2>
                        </div>
                    </div>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('app.help_install_description') }}</p>
                </div>
                <a href="{{ route('profile.show', ['tab' => 'app']) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                    <i data-feather="bell" class="h-4 w-4"></i>
                    {{ __('app.open_app_push') }}
                </a>
            </header>

            <div class="grid gap-px bg-rt-border/60 lg:grid-cols-2 dark:bg-rt-dark-border/60">
                <article class="bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                    <div class="flex items-center gap-2">
                        <i class="fab fa-apple text-xl text-rt-text dark:text-white" aria-hidden="true"></i>
                        <h3 class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.help_install_ios_title') }}</h3>
                    </div>
                    <ol class="mt-4 space-y-3">
                        @foreach ([
                            __('app.help_install_ios_step_1'),
                            __('app.help_install_ios_step_2'),
                            __('app.help_install_ios_step_3'),
                            __('app.help_install_ios_step_4'),
                        ] as $step)
                            <li class="grid grid-cols-[1.75rem_minmax(0,1fr)] gap-3 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rt-surface-muted text-xs font-semibold text-rt-red dark:bg-rt-dark-surface-muted">{{ $loop->iteration }}</span>
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </article>

                <article class="bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                    <div class="flex items-center gap-2">
                        <i class="fab fa-android text-xl text-rt-text dark:text-white" aria-hidden="true"></i>
                        <h3 class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.help_install_android_title') }}</h3>
                    </div>
                    <ol class="mt-4 space-y-3">
                        @foreach ([
                            __('app.help_install_android_step_1'),
                            __('app.help_install_android_step_2'),
                            __('app.help_install_android_step_3'),
                            __('app.help_install_android_step_4'),
                        ] as $step)
                            <li class="grid grid-cols-[1.75rem_minmax(0,1fr)] gap-3 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rt-surface-muted text-xs font-semibold text-rt-red dark:bg-rt-dark-surface-muted">{{ $loop->iteration }}</span>
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </article>
            </div>

            <div class="border-t border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex min-h-8 items-center gap-2 rounded-lg px-2.5 py-1 text-xs font-semibold" :class="secure ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300'">
                        <span class="h-1.5 w-1.5 rounded-full" :class="secure ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                        <span x-text="secure ? @js(__('app.help_https_ready')) : @js(__('app.help_https_missing'))"></span>
                    </span>
                    <span class="inline-flex min-h-8 items-center gap-2 rounded-lg px-2.5 py-1 text-xs font-semibold" :class="serviceWorker ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'">
                        <span class="h-1.5 w-1.5 rounded-full" :class="serviceWorker ? 'bg-emerald-500' : 'bg-slate-400'"></span>
                        {{ __('app.help_browser_support') }}
                    </span>
                    <span class="inline-flex min-h-8 items-center gap-2 rounded-lg bg-rt-surface-muted px-2.5 py-1 text-xs font-semibold text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">
                        {{ __('app.help_permission') }}:
                        <span x-text="permission"></span>
                    </span>
                    <span x-show="standalone" x-cloak class="inline-flex min-h-8 items-center gap-2 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        <i data-feather="check" class="h-3.5 w-3.5"></i>
                        {{ __('app.help_installed') }}
                    </span>
                </div>

                <dl class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.help_push_server') }}</dt>
                        <dd class="mt-1 text-sm font-semibold {{ $pushStatus['ready'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                            {{ $pushStatus['ready'] ? __('app.ready') : __('app.not_ready') }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.help_active_devices') }}</dt>
                        <dd class="mt-1 text-sm font-semibold tabular-nums text-rt-text dark:text-white">{{ $pushStatus['activeDevices'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.help_queue') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-rt-text dark:text-white">{{ $pushStatus['queue'] }}</dd>
                    </div>
                    <div class="rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted">
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.14em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.help_phone_url') }}</dt>
                        <dd class="mt-1 text-sm font-semibold {{ $pushStatus['phoneReadyUrl'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                            {{ $pushStatus['phoneReadyUrl'] ? __('app.ready') : __('app.help_https_domain_required') }}
                        </dd>
                    </div>
                </dl>

                @if (! $pushStatus['ready'])
                    <div
                        class="mt-3 rounded-xl border border-amber-200 bg-amber-50/80 p-3 text-amber-950 dark:border-amber-900/70 dark:bg-amber-950/35 dark:text-amber-100"
                        role="status"
                        data-testid="push-server-diagnostics"
                    >
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-900/70 dark:text-amber-200">
                                <i data-feather="alert-circle" class="h-4 w-4"></i>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">{{ __('app.help_push_not_ready_title') }}</p>
                                <ul class="mt-1.5 space-y-1 text-xs leading-5 text-amber-900/85 dark:text-amber-100/80">
                                    @foreach ($pushStatus['issues'] as $issue)
                                        <li class="flex gap-2">
                                            <span aria-hidden="true">•</span>
                                            <span>{{ __('app.help_push_issue_'.$issue) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($pushStatus['configurationCached'])
                                    <p class="mt-2 text-xs leading-5 text-amber-900/70 dark:text-amber-100/65">
                                        {{ __('app.help_push_config_cache_hint') }}
                                    </p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <p class="mt-3 text-xs leading-5 text-rt-soft dark:text-rt-dark-soft">
                    {{ __('app.help_push_queue_worker_hint', ['queue' => config('webpush.queue')]) }}
                </p>
            </div>
        </section>
    </div>
</x-ui.page>
