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

        </section>
    </div>
</x-ui.page>
