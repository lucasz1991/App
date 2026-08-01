{{--
    EIN globales Infomodal fuer die ganze Anwendung.

    Jeder Info-Button schickt lediglich ein Fenster-Ereignis mit seinem Inhalt:

        x-on:click="$dispatch('rt-info:open', {
            title: '...', summary: '...', points: ['...'], intro: false,
        })"

    Dadurch existiert nur noch ein Dialog im DOM statt eines pro Button.
    intro=true (erster Seitenbesuch) zeigt zusaetzlich einen Los-geht's-Knopf.

    WICHTIG — x-show.important:
    Die Legacy-Datei public/build/css/tailwind.min.css definiert die
    Display-Utilities mit !important (.flex{display:flex!important}). Alpines
    x-show setzt ein INLINE display:none, und das verliert gegen ein
    !important aus dem Stylesheet. Ohne den .important-Modifier bleibt dieser
    Dialog dauerhaft sichtbar und laesst sich nicht schliessen. Nicht entfernen.

    EBENSO WICHTIG — keine doppelten Anfuehrungszeichen im x-data-Block:
    Das gesamte Objekt steht in einem HTML-Attribut mit doppelten
    Anfuehrungszeichen. Ein einziges " in einem Kommentar oder String beendet
    das Attribut mitten im Objekt; der Alpine-Scope zerbricht, 'open' faellt
    auf window.open (immer truthy) zurueck — und genau dann steht ein leeres,
    unschliessbares Modal auf jeder Seite. Abgesichert durch InfoModalTest.
--}}
<div
    x-data="{
        open: false,
        title: '',
        summary: '',
        points: [],
        intro: false,
        show(detail) {
            this.title = detail?.title ?? '';
            this.summary = detail?.summary ?? '';
            this.points = Array.isArray(detail?.points) ? detail.points : [];
            this.intro = Boolean(detail?.intro);
            this.open = true;
        },
        close() {
            this.open = false;
        },
    }"
    x-on:rt-info:open.window="show($event.detail)"
    x-on:rt-navigation:prepare.window="close()"
    x-on:keydown.escape.window="close()"
    x-show.important="open"
    x-cloak
    class="rt-info-backdrop fixed inset-0 z-[500] flex items-center justify-center bg-slate-950/60 p-3 backdrop-blur-md sm:p-6"
    x-on:click.self="close()"
    data-rt-info-modal
    data-rt-overlay-layer
    data-rt-overlay-base="500"
>
    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="rt-info-modal-title"
        x-trap.inert.noscroll="open"
        x-transition:enter="transition duration-300 ease-out"
        x-transition:enter-start="translate-y-3 scale-[0.985] opacity-0"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        x-transition:leave="transition duration-200 ease-in"
        x-transition:leave-start="translate-y-0 scale-100 opacity-100"
        x-transition:leave-end="translate-y-2 scale-[0.99] opacity-0"
        class="rt-info-dialog flex max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-[1.75rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/70 sm:max-h-[calc(100dvh-3rem)]"
        data-rt-info-dialog
    >
        <header class="rt-info-hero relative shrink-0 overflow-hidden px-5 pb-6 pt-5 sm:px-7 sm:pb-7 sm:pt-6">
            <div class="rt-info-orbit rt-info-orbit--one" aria-hidden="true"></div>
            <div class="rt-info-orbit rt-info-orbit--two" aria-hidden="true"></div>

            <div class="relative z-[2] flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3.5">
                    <span class="rt-info-icon flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl text-white ring-1 ring-white/20">
                        <i class="far fa-route text-lg" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.22em] text-white/65">{{ __('app.about_this_page') }}</p>
                        <h2 id="rt-info-modal-title" class="mt-1 text-balance text-xl font-semibold leading-tight tracking-tight text-white sm:text-2xl" x-text="title"></h2>
                    </div>
                </div>
                <button
                    type="button"
                    x-on:click="close()"
                    class="rt-info-close flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-white/75 transition duration-200 hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                    aria-label="{{ __('app.close') }}"
                >
                    <i class="far fa-times" aria-hidden="true"></i>
                </button>
            </div>

            <div class="rt-info-route-map relative z-[2] mt-6" aria-hidden="true">
                <span class="rt-info-route-line"></span>
                @foreach ([['far fa-compass', 'start'], ['far fa-lightbulb', 'middle'], ['far fa-check', 'finish']] as [$icon, $position])
                    <span class="rt-info-route-node rt-info-route-node--{{ $position }}">
                        <i class="{{ $icon }}"></i>
                    </span>
                @endforeach
            </div>
        </header>

        <div class="rt-info-body min-h-0 overflow-y-auto overscroll-contain px-5 py-5 sm:px-7 sm:py-6">
            <p class="rt-info-summary max-w-xl text-pretty text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" x-text="summary" x-show.important="summary"></p>

            <ul class="rt-info-points mt-5 grid gap-2.5 sm:grid-cols-2" x-show.important="points.length">
                <template x-for="(point, index) in points" :key="index">
                    <li class="rt-info-point group flex min-h-20 items-start gap-3 rounded-2xl bg-rt-surface-muted/70 px-3.5 py-3 text-sm leading-6 text-rt-text ring-1 ring-rt-border/50 dark:bg-rt-dark-surface-muted/60 dark:text-rt-dark-text dark:ring-rt-dark-border/50">
                        <span class="rt-info-point-index mt-0.5 inline-flex h-7 min-w-7 shrink-0 items-center justify-center rounded-lg bg-white text-[10px] font-bold tabular-nums text-rt-red shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-control dark:text-rt-dark-accent dark:ring-rt-dark-border" x-text="String(index + 1).padStart(2, '0')"></span>
                        <span class="rt-info-point-copy pt-0.5" x-text="point"></span>
                    </li>
                </template>
            </ul>

            <div class="rt-info-footer mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60">
                <a
                    href="{{ route('help') }}"
                    wire:navigate
                    class="rt-info-help-link group inline-flex min-h-10 items-center gap-2 rounded-xl px-2 text-sm font-semibold text-rt-red transition hover:bg-rt-accent-soft/60 hover:text-rt-red-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rt-red/35 dark:hover:bg-rt-dark-accent-soft/50"
                >
                    <i class="far fa-life-ring" aria-hidden="true"></i>
                    {{ __('app.open_all_help_topics') }}
                    <i class="far fa-external-link-alt text-[10px] opacity-55 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5" aria-hidden="true"></i>
                </a>

                {{-- Nur im Intro-Modus: deutlicher Abschluss-Knopf. --}}
                <button
                    type="button"
                    x-show.important="intro"
                    x-cloak
                    x-on:click="close()"
                    class="rt-skiper-highlight inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-5 py-2 text-sm font-semibold text-white shadow-rt-glow transition duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30"
                >
                    {{ __('app.lets_go') }}
                    <i class="far fa-arrow-right text-xs" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </section>
</div>
