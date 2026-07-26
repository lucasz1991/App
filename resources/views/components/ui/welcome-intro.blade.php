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
        class="rt-welcome-card w-full max-w-xl overflow-hidden rounded-3xl bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-white dark:ring-rt-dark-border/70"
    >
        {{-- Kopf mit Markenverlauf und schwebendem Logo --}}
        <div class="rt-welcome-hero relative overflow-hidden px-6 pb-8 pt-10 text-center">
            <div class="rt-welcome-glow" aria-hidden="true"></div>
            <img
                src="{{ asset('rt-brand/rt-logo.svg') }}"
                alt=""
                aria-hidden="true"
                class="rt-welcome-logo mx-auto h-16 w-16"
            >
            <p class="rt-welcome-item mt-5 text-[11px] font-bold uppercase tracking-[0.22em] text-white/85" style="--rt-welcome-delay: 180ms">
                {{ __('app.welcome_intro_eyebrow') }}
            </p>
            <h2 id="rt-welcome-title" class="rt-welcome-item mt-2 text-2xl font-semibold tracking-tight text-white sm:text-3xl" style="--rt-welcome-delay: 300ms">
                {{ __('app.welcome_name', ['name' => auth()->user()?->name ?? config('app.name')]) }}
            </h2>
        </div>

        <div class="px-6 py-6">
            <p class="rt-welcome-item text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" style="--rt-welcome-delay: 420ms">
                {{ __('app.welcome_intro_text') }}
            </p>

            <ul class="mt-5 space-y-3">
                @foreach ([
                    ['icon' => 'far fa-compass', 'text' => __('app.welcome_intro_point_navigation')],
                    ['icon' => 'far fa-comments', 'text' => __('app.welcome_intro_point_communication')],
                    ['icon' => 'far fa-info-circle', 'text' => __('app.welcome_intro_point_help')],
                ] as $index => $point)
                    <li class="rt-welcome-item flex items-start gap-3 text-sm leading-6" style="--rt-welcome-delay: {{ 540 + ($index * 140) }}ms">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                            <i class="{{ $point['icon'] }}" aria-hidden="true"></i>
                        </span>
                        <span class="pt-1.5 text-rt-text dark:text-rt-dark-text">{{ $point['text'] }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="rt-welcome-item mt-7 flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end" style="--rt-welcome-delay: 980ms">
                <button
                    type="button"
                    x-on:click="open = false"
                    class="inline-flex min-h-11 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                >
                    {{ __('app.skip_intro') }}
                </button>
                <button
                    type="button"
                    x-on:click="open = false"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-6 py-2 text-sm font-semibold text-white shadow-rt-glow transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30"
                >
                    {{ __('app.lets_go') }}
                    <i class="far fa-arrow-right text-xs" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </section>
</div>
