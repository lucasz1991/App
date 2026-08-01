{{--
    Rollenbezogenes RailTime-Onboarding fuer den allerersten Dashboard-Besuch.
    Admin, Verwaltung, Mitarbeiter und Gaeste erhalten denselben stabilen
    Dialogvertrag, aber eigene Inhalte und Prioritaeten.

    x-show.important bleibt zwingend: Die Legacy-CSS setzt Display-Utilities
    mit !important und wuerde ein normales Alpine-x-show ueberstimmen.
--}}
@props([
    'initiallyOpen' => true,
])

@php
    $rawAudience = auth()->user()?->dashboardAudience() ?? 'guest';
    $audience = match ($rawAudience) {
        'admin' => 'admin',
        'administration', 'management' => 'management',
        'employee' => 'employee',
        default => 'guest',
    };

    $content = trans('app.welcome_intro_content.'.$audience);
    $content = is_array($content) ? $content : trans('app.welcome_intro_content.guest');

    $visuals = [
        'admin' => [
            'audienceIcon' => 'fa-user-shield',
            'slideIcons' => ['fa-route', 'fa-users-cog', 'fa-clipboard-list', 'fa-shield-check'],
        ],
        'management' => [
            'audienceIcon' => 'fa-briefcase',
            'slideIcons' => ['fa-chart-network', 'fa-users', 'fa-calendar-alt', 'fa-comments'],
        ],
        'employee' => [
            'audienceIcon' => 'fa-id-badge',
            'slideIcons' => ['fa-compass', 'fa-clock', 'fa-clipboard-check', 'fa-folder-open'],
        ],
        'guest' => [
            'audienceIcon' => 'fa-sparkles',
            'slideIcons' => ['fa-map-signs', 'fa-folder-open', 'fa-comments', 'fa-life-ring'],
        ],
    ];
    $pointIcons = [
        ['fa-compass', 'fa-search', 'fa-info-circle'],
        ['fa-users', 'fa-user-plus', 'fa-shield-alt'],
        ['fa-clipboard-list', 'fa-clock', 'fa-list-check'],
        ['fa-comments', 'fa-question-circle', 'fa-check-circle'],
    ];
    $visual = $visuals[$audience];

    $slides = collect(array_values($content['slides'] ?? []))
        ->map(function (array $slide, int $index) use ($visual, $pointIcons): array {
            return [
                'id' => (string) ($slide['id'] ?? 'step-'.($index + 1)),
                'eyebrow' => (string) ($slide['eyebrow'] ?? ''),
                'title' => (string) ($slide['title'] ?? ''),
                'description' => (string) ($slide['description'] ?? ''),
                'icon' => $visual['slideIcons'][$index] ?? 'fa-compass',
                'points' => collect($slide['points'] ?? [])
                    ->values()
                    ->map(fn (string $point, int $pointIndex): array => [
                        'icon' => $pointIcons[$index][$pointIndex] ?? 'fa-check',
                        'text' => $point,
                    ])
                    ->all(),
            ];
        })
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
                class="rt-welcome-card w-full max-w-5xl overflow-hidden rounded-[1.5rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-white/45 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/70 sm:rounded-[1.75rem]"
            >
                <div class="rt-welcome-layout">
                    <div class="rt-welcome-hero relative overflow-hidden px-5 pb-6 pt-5 sm:px-7 sm:pb-7 sm:pt-7 lg:px-8 lg:pb-8">
                        <div class="rt-welcome-glow" aria-hidden="true"></div>

                        <div class="relative z-[2] flex items-center justify-between gap-3">
                            <span class="rt-welcome-logo-shell inline-flex h-12 w-12 items-center justify-center rounded-2xl ring-1 ring-white/20 sm:h-14 sm:w-14">
                                <img
                                    src="{{ asset('rt-brand/rt-logo.svg') }}"
                                    alt=""
                                    aria-hidden="true"
                                    class="rt-welcome-logo h-8 w-8 sm:h-9 sm:w-9"
                                >
                            </span>

                            <span class="rt-welcome-status inline-flex min-w-0 items-center gap-2 rounded-xl px-3 py-2 text-[10px] font-bold uppercase tracking-[0.13em] text-white ring-1 ring-white/15">
                                <i class="far {{ $visual['audienceIcon'] }} shrink-0 text-rt-red-light" aria-hidden="true"></i>
                                <span class="truncate">{{ $content['label'] ?? config('app.name') }}</span>
                            </span>
                        </div>

                        <div class="relative z-[2] mt-6 sm:mt-8">
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-white/70" x-text="currentSlide.eyebrow"></p>
                            <h2
                                id="rt-welcome-title"
                                x-ref="heading"
                                tabindex="-1"
                                class="mt-2 max-w-xl text-balance text-2xl font-semibold leading-tight tracking-[-0.035em] text-white outline-none sm:text-3xl lg:text-[2.15rem]"
                                x-text="currentSlide.title"
                            ></h2>
                            <p
                                id="rt-welcome-description"
                                class="mt-3 max-w-xl text-pretty text-sm leading-6 text-slate-200 sm:text-[0.9375rem]"
                                x-text="currentSlide.description"
                            ></p>
                        </div>

                        <div class="rt-welcome-journey relative z-[2] mt-6 sm:mt-8" aria-label="{{ __('app.welcome_intro_progress_label') }}">
                            <span class="rt-welcome-journey-track" aria-hidden="true">
                                <span class="rt-welcome-journey-fill" x-bind:style="`width: ${completion}%`"></span>
                            </span>
                            <ol class="relative grid grid-cols-4 gap-2">
                                <template x-for="(slide, index) in slides" :key="`hero-${slide.id}`">
                                    <li class="flex justify-center">
                                        <button
                                            type="button"
                                            x-on:click="goTo(index)"
                                            x-bind:aria-label="stepButtonLabel(index)"
                                            x-bind:aria-current="index === step ? 'step' : null"
                                            x-bind:data-state="index < step ? 'complete' : (index === step ? 'current' : 'upcoming')"
                                            class="rt-welcome-journey-node inline-flex h-10 w-10 items-center justify-center rounded-xl text-xs outline-none ring-1 transition focus-visible:ring-2 focus-visible:ring-white/80"
                                        >
                                            <i class="far" x-bind:class="slide.icon" aria-hidden="true"></i>
                                        </button>
                                    </li>
                                </template>
                            </ol>
                        </div>
                    </div>

                    <div class="rt-welcome-content flex min-h-0 flex-col px-5 py-5 sm:px-7 sm:py-7 lg:px-8">
                        <div class="flex items-center justify-between gap-4">
                            <p
                                class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted"
                                role="status"
                                aria-live="polite"
                                x-text="progressText"
                            ></p>
                            <button
                                type="button"
                                x-show.important="!isLast"
                                x-on:click="skip()"
                                class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl px-3 text-xs font-semibold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                            >
                                {{ __('app.skip_intro') }}
                            </button>
                        </div>

                        <div class="rt-welcome-slide-viewport mt-4 min-h-0 flex-1">
                            <template x-for="slide in [currentSlide]" :key="slide.id">
                                <article
                                    class="rt-welcome-slide"
                                    x-transition:enter="transition duration-300 ease-out"
                                    x-transition:enter-start="translate-x-3 opacity-0"
                                    x-transition:enter-end="translate-x-0 opacity-100"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="rt-welcome-slide-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                                            <i class="far" x-bind:class="slide.icon" aria-hidden="true"></i>
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-rt-red dark:text-rt-dark-accent">
                                                {{ __('app.welcome_intro_at_a_glance') }}
                                            </p>
                                            <h3 class="mt-1 text-lg font-semibold tracking-[-0.02em] text-rt-text dark:text-rt-dark-text" x-text="slide.title"></h3>
                                        </div>
                                    </div>

                                    <ul class="mt-4 grid gap-2.5">
                                        <template x-for="(point, index) in slide.points" :key="`${slide.id}-${index}`">
                                            <li class="rt-welcome-feature group flex min-h-[4.35rem] items-center gap-3.5 rounded-2xl px-3.5 py-3 text-sm leading-5">
                                                <span class="rt-welcome-feature-icon flex h-9 w-9 shrink-0 items-center justify-center rounded-xl">
                                                    <i class="far" x-bind:class="point.icon" aria-hidden="true"></i>
                                                </span>
                                                <span class="flex-1 text-pretty text-rt-text dark:text-rt-dark-text" x-text="point.text"></span>
                                                <span class="text-[10px] font-bold tabular-nums text-rt-soft dark:text-rt-dark-soft" x-text="String(index + 1).padStart(2, '0')"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </article>
                            </template>
                        </div>

                        <div class="rt-welcome-footer mt-5 border-t border-rt-border/70 pt-4 dark:border-rt-dark-border/70">
                            <div
                                class="rt-welcome-progress h-1.5 overflow-hidden rounded-full bg-rt-surface-muted dark:bg-rt-dark-surface-muted"
                                role="progressbar"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                x-bind:aria-valuenow="completion"
                                x-bind:aria-valuetext="progressText"
                            >
                                <span class="block h-full rounded-full bg-rt-red transition-[width] duration-300" x-bind:style="`width: ${completion}%`"></span>
                            </div>

                            <div class="mt-4 flex items-center justify-between gap-2.5">
                                <button
                                    type="button"
                                    x-on:click="previous()"
                                    x-bind:disabled="isFirst"
                                    class="rt-welcome-back inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-rt-border bg-rt-surface px-3.5 py-2 text-sm font-semibold text-rt-text shadow-rt-xs transition hover:border-rt-red/30 hover:bg-rt-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 disabled:cursor-not-allowed disabled:opacity-35 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                                >
                                    <i class="far fa-arrow-left text-xs" aria-hidden="true"></i>
                                    <span class="hidden xs:inline">{{ __('app.previous') }}</span>
                                </button>

                                <button
                                    type="button"
                                    x-on:click="next()"
                                    class="rt-skiper-highlight inline-flex min-h-11 min-w-[8.5rem] items-center justify-center gap-2 rounded-xl bg-rt-red px-5 py-2 text-sm font-semibold text-white shadow-rt-glow transition duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-rt-dark-surface"
                                >
                                    <span x-text="isLast ? @js(__('app.welcome_intro_finish')) : @js(__('app.next'))"></span>
                                    <i class="far" x-bind:class="isLast ? 'fa-check' : 'fa-arrow-right'" aria-hidden="true"></i>
                                </button>
                            </div>

                            <p class="mt-3 text-center text-[10px] leading-4 text-rt-soft dark:text-rt-dark-soft">
                                {{ __('app.welcome_intro_keyboard_hint') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <div wire:ignore class="pointer-events-none fixed inset-0 z-[20]" data-rt-overlay-portal></div>
        </div>
    </template>
</div>
