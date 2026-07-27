{{--
    Willkommens-Intro beim ALLERERSTEN Besuch der Anwendung (Dashboard).
    Wird nur gerendert, wenn PageViews::firstVisit(...) fuer den Nutzer wahr
    war — das Vermerken uebernimmt die einbindende Seite.

    Gestaltung: RailTime-Flaechen und -Rot, hell wie dunkel, mit gestaffelten
    Eintritts-Animationen (Keyframes rt-welcome-* in app.css).

    x-show.important, weil die Legacy-CSS Display-Utilities mit !important
    markiert — ohne den Modifier liesse sich das Intro nicht schliessen.
--}}
<div
    x-data="{ open: true }"
    x-show.important="open"
    x-cloak
    x-on:keydown.escape.window="open = false"
    class="rt-welcome-backdrop fixed inset-0 z-[520] flex items-center justify-center p-4"
    data-rt-welcome-intro
>
    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="rt-welcome-title"
        x-trap.inert.noscroll="open"
        class="rt-welcome-card w-full max-w-4xl overflow-hidden rounded-[1.75rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-white/45 dark:bg-rt-dark-surface dark:text-white dark:ring-rt-dark-border/70"
    >
        <div class="rt-welcome-layout">
        {{-- Visuelle Startkarte nach Vengeance-Muster: eine ruhige, animierte
             Routenansicht statt eines dekorativen Standard-Verlaufs. --}}
        <div class="rt-welcome-hero relative overflow-hidden px-6 pb-7 pt-8 sm:px-8 sm:pb-8 sm:pt-9">
            <div class="rt-welcome-glow" aria-hidden="true"></div>
            <div class="relative z-[2] flex items-center justify-between gap-4">
                <span class="rt-welcome-logo-shell inline-flex h-14 w-14 items-center justify-center rounded-2xl ring-1 ring-white/20">
                    <img
                        src="{{ asset('rt-brand/rt-logo.svg') }}"
                        alt=""
                        aria-hidden="true"
                        class="rt-welcome-logo h-9 w-9"
                    >
                </span>
                <span class="rt-welcome-status inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[10px] font-bold uppercase tracking-[0.17em] text-white/75 ring-1 ring-white/15">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_0_4px_rgba(52,211,153,0.12)]" aria-hidden="true"></span>
                    {{ config('app.name') }}
                </span>
            </div>

            <div class="relative z-[2] mt-8">
                <p class="rt-welcome-item text-[11px] font-bold uppercase tracking-[0.22em] text-white/65" style="--rt-welcome-delay: 180ms">
                    {{ __('app.welcome_intro_eyebrow') }}
                </p>
                <h2 id="rt-welcome-title" class="rt-welcome-item mt-2 max-w-lg text-balance text-2xl font-semibold leading-tight tracking-tight text-white sm:text-3xl" style="--rt-welcome-delay: 300ms">
                    {{ __('app.welcome_name', ['name' => auth()->user()?->name ?? config('app.name')]) }}
                </h2>
            </div>

            <div class="rt-welcome-map rt-welcome-item relative z-[2] mt-8" style="--rt-welcome-delay: 410ms" aria-hidden="true">
                <div class="rt-welcome-map-grid"></div>
                <span class="rt-welcome-map-route"></span>
                <span class="rt-welcome-map-node rt-welcome-map-node--one"><i class="far fa-building"></i></span>
                <span class="rt-welcome-map-node rt-welcome-map-node--two"><i class="far fa-comments"></i></span>
                <span class="rt-welcome-map-node rt-welcome-map-node--three"><i class="far fa-user-check"></i></span>
                <span class="rt-welcome-map-pulse"></span>
            </div>
        </div>

        <div class="rt-welcome-content px-5 py-6 sm:px-7 sm:py-8">
            <p class="rt-welcome-item max-w-xl text-pretty text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" style="--rt-welcome-delay: 420ms">
                {{ __('app.welcome_intro_text') }}
            </p>

            <ul class="mt-5 grid gap-2.5">
                @foreach ([
                    ['icon' => 'far fa-compass', 'text' => __('app.welcome_intro_point_navigation')],
                    ['icon' => 'far fa-comments', 'text' => __('app.welcome_intro_point_communication')],
                    ['icon' => 'far fa-info-circle', 'text' => __('app.welcome_intro_point_help')],
                ] as $index => $point)
                    <li class="rt-welcome-item rt-welcome-feature group flex min-h-[4.5rem] items-center gap-3.5 rounded-2xl px-3.5 py-3 text-sm leading-6" style="--rt-welcome-delay: {{ 540 + ($index * 140) }}ms">
                        <span class="rt-welcome-feature-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">
                            <i class="{{ $point['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <span class="flex-1 text-rt-text dark:text-rt-dark-text">{{ $point['text'] }}</span>
                        <span class="text-[10px] font-bold tabular-nums text-rt-soft dark:text-rt-dark-soft">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="rt-welcome-item mt-7 flex flex-col-reverse gap-2 border-t border-rt-border/60 pt-5 dark:border-rt-dark-border/60 sm:flex-row sm:items-center sm:justify-end" style="--rt-welcome-delay: 980ms">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold text-rt-muted transition duration-200 hover:bg-rt-surface-muted hover:text-rt-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/30 dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                >
                    {{ __('app.skip_intro') }}
                </button>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="rt-skiper-highlight inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-6 py-2 text-sm font-semibold text-white shadow-rt-glow transition duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30"
                >
                    {{ __('app.lets_go') }}
                    <i class="far fa-arrow-right text-xs" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        </div>
    </section>
</div>
