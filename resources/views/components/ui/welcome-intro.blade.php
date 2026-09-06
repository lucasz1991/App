{{--
    Rollenbezogenes RailTime-Video-Onboarding fuer den ersten Dashboard-Besuch.
    Der serverseitige Katalog liefert nur Module, die zur Rolle und zu den
    Berechtigungen des angemeldeten Kontos passen. Videos werden erst fuer das
    aktuell gewaehlte Modul geladen.

    x-show.important bleibt zwingend: Die Legacy-CSS setzt Display-Utilities
    mit !important und wuerde ein normales Alpine-x-show ueberstimmen.
--}}
@props([
    'initiallyOpen' => true,
])

@php
    $intro = app(\App\Support\WelcomeIntroCatalog::class)->forUser(auth()->user());
    $audience = $intro['audience'];

    $audienceIcons = [
        'admin' => 'fa-user-shield',
        'management' => 'fa-briefcase',
        'employee' => 'fa-id-badge',
        'guest' => 'fa-user',
    ];

    $pointIcons = [
        'fa-check-circle',
        'fa-route',
        'fa-shield-alt',
        'fa-info-circle',
    ];

    $slides = collect($intro['slides'])
        ->map(function (array $slide) use ($pointIcons): array {
            $slide['points'] = collect($slide['points'] ?? [])
                ->values()
                ->map(fn (string $point, int $index): array => [
                    'icon' => $pointIcons[$index] ?? 'fa-check',
                    'text' => $point,
                ])
                ->all();

            return $slide;
        })
        ->values()
        ->all();

    $introConfig = [
        'initiallyOpen' => (bool) $initiallyOpen,
        'slides' => $slides,
        'labels' => [
            'progress' => __('app.welcome_intro_progress'),
            'openStep' => __('app.welcome_intro_open_step'),
        ],
    ];
@endphp

<div
    x-data="welcomeIntro(@js($introConfig))"
    x-init="init()"
    x-on:rt-welcome:open.window="openIntro($event)"
    x-on:rt-navigation:prepare.window="closeIntro(false)"
    x-on:livewire:navigating.window="closeIntro(false)"
    x-on:keydown.escape.window="open && closeIntro()"
    data-rt-welcome-controller
    data-rt-welcome-audience="{{ $audience }}"
>
    <template x-teleport="body">
        <div
            x-show.important="open"
            x-cloak
            x-on:click.self="skip()"
            x-trap.inert.noscroll="open"
            class="rt-welcome-backdrop fixed inset-0 z-[520] flex items-center justify-center p-2.5 sm:p-5"
            data-rt-welcome-intro
            data-rt-welcome-initially-open="{{ $initiallyOpen ? 'true' : 'false' }}"
            data-rt-overlay-layer
            data-rt-overlay-base="520"
        >
            <section
                role="dialog"
                aria-modal="true"
                aria-labelledby="rt-welcome-title"
                aria-describedby="rt-welcome-description"
                x-on:keydown="handleKey($event)"
                class="rt-welcome-card w-full overflow-hidden rounded-[1.5rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-white/45 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/70 sm:rounded-[1.75rem]"
            >
                <header class="rt-welcome-hero relative overflow-hidden px-4 py-3.5 sm:px-6 sm:py-4">
                    <div class="rt-welcome-glow" aria-hidden="true"></div>

                    <div class="relative z-[2] flex min-w-0 items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="rt-welcome-logo-shell inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl ring-1 ring-white/20">
                                <img src="{{ asset('rt-brand/rt-logo.svg') }}" alt="" aria-hidden="true" class="rt-welcome-logo h-7 w-7">
                            </span>

                            <div class="min-w-0">
                                <p class="truncate text-[10px] font-bold uppercase tracking-[0.18em] text-white/65">
                                    {{ __('app.welcome_intro_original_recording') }}
                                </p>
                                <div class="mt-1 flex min-w-0 items-center gap-2 text-sm font-semibold text-white">
                                    <i class="far {{ $audienceIcons[$audience] ?? 'fa-user' }} shrink-0 text-rt-red-light" aria-hidden="true"></i>
                                    <span class="truncate">{{ $intro['label'] }}</span>
                                    <span class="hidden text-white/35 sm:inline" aria-hidden="true">·</span>
                                    <span class="hidden truncate text-xs font-medium text-white/65 sm:inline">
                                        {{ count($slides) }} {{ __('app.welcome_intro_topics') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <button
                            type="button"
                            x-on:click="skip()"
                            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white/75 ring-1 ring-white/15 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/80"
                            aria-label="{{ __('app.close') }}"
                        >
                            <i class="far fa-times" aria-hidden="true"></i>
                        </button>
                    </div>
                </header>

                <div class="rt-welcome-layout">
                    <nav class="rt-welcome-journey" aria-label="{{ __('app.welcome_intro_topics') }}" data-rt-welcome-module-nav>
                        <ol class="rt-welcome-module-list">
                            <template x-for="(slide, index) in slides" :key="`module-${slide.id}`">
                                <li class="min-w-0">
                                    <button
                                        type="button"
                                        x-on:click="goTo(index)"
                                        x-bind:aria-label="stepButtonLabel(index)"
                                        x-bind:aria-current="index === step ? 'step' : null"
                                        x-bind:data-state="index < step ? 'complete' : (index === step ? 'current' : 'upcoming')"
                                        x-bind:data-rt-welcome-module="slide.id"
                                        class="rt-welcome-journey-node group flex w-full items-center gap-2.5 rounded-xl text-left outline-none transition focus-visible:ring-2 focus-visible:ring-rt-red/40"
                                    >
                                        <span class="rt-welcome-module-icon inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                                            <i class="far" x-bind:class="slide.icon" aria-hidden="true"></i>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-xs font-semibold" x-text="slide.eyebrow"></span>
                                            <span class="mt-0.5 block text-[10px] tabular-nums opacity-60" x-text="slide.durationLabel"></span>
                                        </span>
                                        <i class="far fa-chevron-right shrink-0 text-[9px] opacity-35" aria-hidden="true"></i>
                                    </button>
                                </li>
                            </template>
                        </ol>
                    </nav>

                    <div class="rt-welcome-content flex min-h-0 flex-col">
                        <div class="rt-welcome-slide-viewport min-h-0 flex-1">
                            <template x-for="slide in [currentSlide]" :key="slide.id">
                                <article
                                    class="rt-welcome-slide"
                                    x-transition:enter="transition duration-300 ease-out"
                                    x-transition:enter-start="translate-x-3 opacity-0"
                                    x-transition:enter-end="translate-x-0 opacity-100"
                                >
                                    <section class="rt-welcome-media-column" aria-label="{{ __('app.welcome_intro_original_recording') }}">
                                        <div class="flex items-center justify-between gap-3">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted" x-text="slide.moduleLabel"></p>
                                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-rt-red/8 px-2.5 py-1 text-[10px] font-semibold text-rt-red dark:bg-rt-red/15 dark:text-rt-red-light">
                                                <i class="far fa-volume-up" aria-hidden="true"></i>
                                                <span x-text="slide.durationLabel"></span>
                                            </span>
                                        </div>

                                        <div class="rt-welcome-video-frame mt-3" data-rt-welcome-media-controls>
                                            <video
                                                x-ref="video"
                                                x-show.important="slide.videoAvailable && !videoFailed"
                                                x-bind:poster="slide.poster"
                                                x-bind:aria-label="slide.videoLabel"
                                                x-on:play="videoPlaying = true"
                                                x-on:pause="videoPlaying = false"
                                                x-on:ended="videoPlaying = false"
                                                x-on:error="videoFailed = true"
                                                controls
                                                controlslist="nodownload"
                                                disablepictureinpicture
                                                playsinline
                                                preload="metadata"
                                                class="h-full w-full"
                                                data-rt-welcome-video
                                            >
                                                <source x-bind:src="slide.video" type="video/mp4" x-on:error="videoFailed = true">
                                                <template x-for="track in slide.tracks" :key="`${slide.id}-${track.srclang}`">
                                                    <track
                                                        kind="captions"
                                                        x-bind:src="track.src"
                                                        x-bind:srclang="track.srclang"
                                                        x-bind:label="track.label"
                                                        x-bind:default="track.default"
                                                    >
                                                </template>
                                            </video>

                                            <div x-show.important="!slide.videoAvailable || videoFailed" class="rt-welcome-video-fallback" role="status">
                                                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-xl text-white ring-1 ring-white/15">
                                                    <i class="far fa-video-slash" aria-hidden="true"></i>
                                                </span>
                                                <h3 class="mt-3 text-base font-semibold text-white">{{ __('app.welcome_intro_video_unavailable_title') }}</h3>
                                                <p class="mt-1 max-w-md text-center text-xs leading-5 text-slate-300">{{ __('app.welcome_intro_video_unavailable_text') }}</p>
                                            </div>
                                        </div>

                                        <p class="mt-2.5 flex items-start gap-2 text-[10px] leading-4 text-rt-soft dark:text-rt-dark-soft">
                                            <i class="far fa-closed-captioning mt-0.5 shrink-0" aria-hidden="true"></i>
                                            <span>{{ __('app.welcome_intro_audio_language') }} — {{ __('app.welcome_intro_video_hint') }}</span>
                                        </p>
                                    </section>

                                    <section class="rt-welcome-detail-column">
                                        <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rt-red dark:text-rt-dark-accent" x-text="slide.eyebrow"></p>
                                        <h2
                                            id="rt-welcome-title"
                                            x-ref="heading"
                                            tabindex="-1"
                                            class="mt-1.5 text-balance text-2xl font-semibold leading-tight tracking-[-0.035em] text-rt-text outline-none dark:text-rt-dark-text sm:text-[1.7rem]"
                                            x-text="slide.title"
                                        ></h2>
                                        <p
                                            id="rt-welcome-description"
                                            class="mt-3 text-pretty text-sm leading-6 text-rt-muted dark:text-rt-dark-muted"
                                            x-text="slide.description"
                                        ></p>

                                        <div class="rt-welcome-detail-scroll mt-5">
                                            <section class="rt-welcome-explainer">
                                                <h3 class="rt-welcome-explainer-title">
                                                    <i class="far fa-cogs" aria-hidden="true"></i>
                                                    {{ __('app.welcome_intro_details_label') }}
                                                </h3>
                                                <p class="mt-2 text-sm leading-6 text-rt-text dark:text-rt-dark-text" x-text="slide.details"></p>
                                            </section>

                                            <ul class="rt-welcome-feature-list mt-3 grid gap-2 sm:grid-cols-2">
                                                <template x-for="(point, index) in slide.points" :key="`${slide.id}-${index}`">
                                                    <li class="rt-welcome-feature group flex items-start gap-2.5 rounded-xl p-3 text-xs leading-5">
                                                        <span class="rt-welcome-feature-icon inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg">
                                                            <i class="far" x-bind:class="point.icon" aria-hidden="true"></i>
                                                        </span>
                                                        <span class="text-rt-text dark:text-rt-dark-text" x-text="point.text"></span>
                                                    </li>
                                                </template>
                                            </ul>

                                            <div class="mt-3 grid gap-2.5 sm:grid-cols-2">
                                                <section class="rt-welcome-explainer rt-welcome-explainer--access">
                                                    <h3 class="rt-welcome-explainer-title">
                                                        <i class="far fa-user-lock" aria-hidden="true"></i>
                                                        {{ __('app.welcome_intro_access_label') }}
                                                    </h3>
                                                    <p class="mt-2 text-xs leading-5 text-rt-text dark:text-rt-dark-text" x-text="slide.access"></p>
                                                </section>
                                                <section class="rt-welcome-explainer rt-welcome-explainer--note">
                                                    <h3 class="rt-welcome-explainer-title">
                                                        <i class="far fa-info-circle" aria-hidden="true"></i>
                                                        {{ __('app.welcome_intro_boundaries_label') }}
                                                    </h3>
                                                    <p class="mt-2 text-xs leading-5 text-rt-text dark:text-rt-dark-text" x-text="slide.note"></p>
                                                </section>
                                            </div>
                                        </div>
                                    </section>
                                </article>
                            </template>
                        </div>

                        <footer class="rt-welcome-footer">
                            <div class="flex min-w-0 items-center gap-3">
                                <div
                                    class="rt-welcome-progress h-1.5 min-w-16 flex-1 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted"
                                    role="progressbar"
                                    aria-valuemin="0"
                                    aria-valuemax="100"
                                    x-bind:aria-valuenow="completion"
                                    x-bind:aria-valuetext="progressText"
                                >
                                    <span class="block h-full rounded-full bg-rt-red transition-[width] duration-300" x-bind:style="`width: ${completion}%`"></span>
                                </div>
                                <p
                                    class="shrink-0 text-[10px] font-bold uppercase tracking-[0.12em] text-rt-muted dark:text-rt-dark-muted"
                                    role="status"
                                    aria-live="polite"
                                    x-text="progressText"
                                ></p>
                            </div>

                            <div class="mt-3 flex items-center justify-between gap-2.5">
                                <button
                                    type="button"
                                    x-on:click="previous()"
                                    x-bind:disabled="isFirst"
                                    class="rt-welcome-back inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition hover:border-rt-red/30 hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 disabled:cursor-not-allowed disabled:opacity-35 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                                >
                                    <i class="far fa-arrow-left text-xs" aria-hidden="true"></i>
                                    <span class="hidden xs:inline">{{ __('app.previous') }}</span>
                                </button>

                                <p class="hidden text-center text-[10px] leading-4 text-rt-soft dark:text-rt-dark-soft lg:block">
                                    {{ __('app.welcome_intro_keyboard_hint') }}
                                </p>

                                <button
                                    type="button"
                                    x-on:click="next()"
                                    class="rt-skiper-highlight inline-flex min-h-11 min-w-[8.5rem] items-center justify-center gap-2 rounded-xl bg-rt-red px-5 py-2 text-sm font-semibold text-white shadow-rt-glow transition duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface"
                                >
                                    <span x-text="isLast ? @js(__('app.welcome_intro_finish')) : @js(__('app.next'))"></span>
                                    <i class="far" x-bind:class="isLast ? 'fa-check' : 'fa-arrow-right'" aria-hidden="true"></i>
                                </button>
                            </div>
                        </footer>
                    </div>
                </div>
            </section>

            <div wire:ignore class="pointer-events-none fixed inset-0 z-[20]" data-rt-overlay-portal></div>
        </div>
    </template>
</div>
