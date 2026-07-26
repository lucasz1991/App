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

        {{-- Ein gemeinsamer Alpine-Bereich ueber ALLE Gruppen: dadurch kann
             immer nur genau ein Thema offen sein, auch gruppenuebergreifend.
             Die Animation kommt von x-collapse (@alpinejs/collapse, in app.js
             registriert). Bewusst kein <details> mehr — das laesst sich weder
             exklusiv schalten noch animieren. --}}
        <section
            aria-labelledby="help-topics-heading"
            class="space-y-4"
            x-data="{
                openTopic: null,
                toggle(key) {
                    this.openTopic = this.openTopic === key ? null : key;
                },
            }"
        >
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">{{ __('app.help_topics') }}</p>
                    <h2 id="help-topics-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-white">
                        {{ app()->getLocale() === 'de' ? 'RailTime Schritt für Schritt' : 'RailTime step by step' }}
                    </h2>
                </div>
                <a href="{{ route('support') }}" wire:navigate class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-rt-red transition hover:text-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                    <i data-feather="life-buoy" class="h-4 w-4"></i>
                    {{ __('app.contact_support') }}
                </a>
            </div>

            @forelse ($topicGroups as $group => $groupTopics)
                <section class="overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                    <h3 class="border-b border-rt-border/60 bg-rt-surface-muted px-4 py-3 text-xs font-semibold uppercase tracking-[0.14em] text-rt-muted dark:border-rt-dark-border/60 dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted">{{ $group }}</h3>
                    @php $groupKey = \Illuminate\Support\Str::slug((string) $group); @endphp
                    <div class="divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
                        @foreach ($groupTopics as $topic)
                            @php $topicKey = $groupKey.'-'.$loop->index; @endphp
                            <div class="px-4 py-1" data-help-topic="{{ $topicKey }}">
                                <button
                                    type="button"
                                    x-on:click="toggle(@js($topicKey))"
                                    :aria-expanded="(openTopic === @js($topicKey)).toString()"
                                    aria-controls="help-panel-{{ $topicKey }}"
                                    class="flex min-h-14 w-full items-center gap-3 py-3 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-rt-accent/30"
                                >
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                                        <i data-feather="{{ $topic['icon'] }}" class="h-4 w-4"></i>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-rt-text dark:text-white">{{ $topic['title'] }}</span>
                                        <span class="mt-0.5 block text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">{{ $topic['summary'] }}</span>
                                    </span>
                                    <i
                                        data-feather="chevron-down"
                                        class="h-4 w-4 shrink-0 text-rt-soft transition-transform duration-200 dark:text-rt-dark-soft"
                                        :class="openTopic === @js($topicKey) && 'rotate-180'"
                                    ></i>
                                </button>

                                {{-- x-collapse animiert die Hoehe. Die Klassen hier
                                     enthalten bewusst KEINE Display-Utility
                                     (flex/block/grid): die traegt die
                                     Legacy-CSS mit !important, wodurch x-show
                                     nicht mehr ausblenden koennte. --}}
                                <div
                                    id="help-panel-{{ $topicKey }}"
                                    x-show="openTopic === @js($topicKey)"
                                    x-collapse
                                    x-cloak
                                    class="overflow-hidden pb-4 pl-12"
                                >
                                    <ul class="space-y-2">
                                        @foreach ($topic['points'] as $point)
                                            <li class="flex gap-2 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                                <i class="far fa-check-circle mt-1 shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                                                <span>{{ $point }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <a href="{{ $topic['href'] }}" wire:navigate class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-rt-red hover:text-rt-red-dark">
                                        {{ app()->getLocale() === 'de' ? 'Bereich öffnen' : 'Open area' }}
                                        <i class="far fa-arrow-right text-xs" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div>
                    <div class="rounded-2xl bg-rt-surface-muted px-5 py-8 text-center sm:col-span-2 xl:col-span-4 dark:bg-rt-dark-surface-muted">
                        <i data-feather="search" class="mx-auto h-6 w-6 text-rt-soft dark:text-rt-dark-soft"></i>
                        <p class="mt-3 text-sm font-semibold text-rt-text dark:text-white">{{ __('app.help_no_results') }}</p>
                        <button type="button" wire:click="$set('query', '')" class="mt-2 text-sm font-semibold text-rt-red hover:text-rt-red-dark">
                            {{ __('app.reset_search') }}
                        </button>
                    </div>
                </div>
            @endforelse
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
                            <h2 id="install-app-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-white">
                                {{ app()->getLocale() === 'de' ? 'Realtime auf dem Smartphone' : 'Realtime on your smartphone' }}
                            </h2>
                        </div>
                    </div>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ __('app.help_install_description') }}</p>
                </div>
                <a href="{{ route('profile.show', ['tab' => 'settings']) }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-4 py-2 text-sm font-semibold text-white shadow-rt-sm transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30">
                    <i data-feather="bell" class="h-4 w-4"></i>
                    {{ __('app.open_app_push') }}
                </a>
            </header>

            {{-- Installieren und testen direkt hier, nicht nur als Verweis ins
                 Profil. Nutzt die bestehende, getestete Push-Komponente. --}}
            <div class="border-b border-rt-border/60 p-4 dark:border-rt-dark-border/60 sm:p-6">
                <livewire:settings.push-settings :show-test="true" />
            </div>

            <div class="grid gap-px bg-rt-border/60 lg:grid-cols-3 dark:bg-rt-dark-border/60">
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

                {{-- Desktop: Windows und macOS. Auf Windows/Chrome/Edge greift der
                     Installationsdialog (Button oben), Safari auf dem Mac kennt
                     nur "Ablage > Zum Dock hinzufuegen". --}}
                <article class="bg-rt-surface p-4 dark:bg-rt-dark-surface sm:p-6">
                    <div class="flex items-center gap-2">
                        <i class="far fa-desktop text-xl text-rt-text dark:text-white" aria-hidden="true"></i>
                        <h3 class="text-base font-semibold text-rt-text dark:text-white">{{ __('app.help_install_desktop_title') }}</h3>
                    </div>

                    <p class="mt-3 text-xs font-semibold uppercase tracking-[0.12em] text-rt-soft dark:text-rt-dark-soft">
                        <i class="fab fa-windows mr-1" aria-hidden="true"></i>{{ __('app.help_install_windows_subtitle') }}
                    </p>
                    <ol class="mt-2 space-y-3">
                        @foreach ([
                            __('app.help_install_windows_step_1'),
                            __('app.help_install_windows_step_2'),
                            __('app.help_install_windows_step_3'),
                        ] as $step)
                            <li class="grid grid-cols-[1.75rem_minmax(0,1fr)] gap-3 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rt-surface-muted text-xs font-semibold text-rt-red dark:bg-rt-dark-surface-muted">{{ $loop->iteration }}</span>
                                <span>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>

                    <p class="mt-4 text-xs font-semibold uppercase tracking-[0.12em] text-rt-soft dark:text-rt-dark-soft">
                        <i class="fab fa-apple mr-1" aria-hidden="true"></i>{{ __('app.help_install_mac_subtitle') }}
                    </p>
                    <ol class="mt-2 space-y-3">
                        @foreach ([
                            __('app.help_install_mac_step_1'),
                            __('app.help_install_mac_step_2'),
                            __('app.help_install_mac_step_3'),
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

        <section aria-labelledby="help-faq-heading">
            <div class="mb-3">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-red">FAQ</p>
                <h2 id="help-faq-heading" class="mt-1 text-lg font-semibold tracking-tight text-rt-text dark:text-white">
                    {{ app()->getLocale() === 'de' ? 'Häufige Fragen' : 'Frequently asked questions' }}
                </h2>
            </div>
            <div class="divide-y divide-rt-border/60 overflow-hidden rounded-2xl bg-rt-surface shadow-rt-sm ring-1 ring-rt-border/60 dark:divide-rt-dark-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
                @foreach ($faqs as $faq)
                    <details class="group px-4 sm:px-5">
                        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 py-4 text-sm font-semibold text-rt-text focus:outline-none dark:text-white">
                            {{ $faq['question'] }}
                            <i data-feather="plus" class="h-4 w-4 shrink-0 text-rt-muted transition group-open:rotate-45 dark:text-rt-dark-muted"></i>
                        </summary>
                        <p class="pb-4 text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $faq['answer'] }}</p>
                    </details>
                @endforeach
            </div>
        </section>
    </div>
</x-ui.page>
