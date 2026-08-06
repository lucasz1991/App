{{--
    EIN globales Infomodal fuer die ganze Anwendung — seit dem Umbau in der
    OPTIK DES EINHEITLICHEN MODALS (Standard-Panel, Kopf-/Inhalts-/Fusszeile,
    rt-motion-Choreografie von unten herein und nach oben hinaus) statt des
    frueheren Sonderlayouts mit eigenem Hero.

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
    x-trap.inert.noscroll="open"
    x-cloak
    class="rt-ui-modal rt-modal-shell fixed inset-0 z-[500] flex items-center justify-center overflow-hidden p-3 sm:p-6"
    x-on:click.self="close()"
    data-rt-info-modal
    data-rt-overlay-layer
    data-rt-overlay-base="500"
>
    {{-- Schleier: identisch zum einheitlichen Modal. --}}
    <div
        x-show.important="open"
        aria-hidden="true"
        class="rt-modal-backdrop fixed inset-0 -z-[1]"
        x-on:click="close()"
        x-transition:enter="rt-motion-backdrop-enter"
        x-transition:enter-start="rt-motion-faded"
        x-transition:enter-end="rt-motion-shown"
        x-transition:leave="rt-motion-backdrop-leave"
        x-transition:leave-start="rt-motion-shown"
        x-transition:leave-end="rt-motion-faded"
    >
        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-md"></div>
    </div>

    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="rt-info-modal-title"
        x-transition:enter="rt-motion-modal-enter"
        x-transition:enter-start="rt-motion-modal-enter-from"
        x-transition:enter-end="rt-motion-modal-enter-to"
        x-transition:leave="rt-motion-modal-leave"
        x-transition:leave-start="rt-motion-modal-leave-from"
        x-transition:leave-end="rt-motion-modal-leave-to"
        class="rt-ui-surface rt-ui-modal-panel rt-modal-frame rt-info-dialog relative flex max-h-[calc(100dvh-1.5rem)] min-h-0 w-full max-w-2xl flex-col overflow-hidden rounded-[1.4rem] bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-dark-text dark:ring-rt-dark-border/60 sm:max-h-[calc(100dvh-3rem)]"
        data-rt-info-dialog
    >
        <header class="rt-modal-header relative flex shrink-0 items-center gap-3.5 border-b border-rt-border/70 bg-rt-surface px-5 py-4 pr-16 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-6 sm:py-5 sm:pr-16">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-red ring-1 ring-rt-red/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                <i class="far fa-route" aria-hidden="true"></i>
            </span>
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.18em] text-rt-soft dark:text-rt-dark-soft">{{ __('app.about_this_page') }}</p>
                <h2 id="rt-info-modal-title" class="mt-0.5 text-balance text-lg font-semibold leading-6 tracking-[-0.02em]" x-text="title"></h2>
            </div>

            <button
                type="button"
                x-on:click="close()"
                class="absolute right-3 top-1/2 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-lg border border-rt-border/70 bg-rt-control text-rt-muted transition hover:border-rt-accent/35 hover:text-rt-accent focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/70 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/35 dark:hover:text-rt-dark-accent sm:right-4"
                aria-label="{{ __('app.close') }}"
                title="{{ __('app.close') }}"
            >
                <i class="far fa-times text-base" aria-hidden="true"></i>
            </button>
        </header>

        <div class="rt-info-body rt-modal-content min-h-0 overflow-y-auto overscroll-contain px-5 py-5 text-sm leading-6 sm:px-6 sm:py-6">
            <p class="rt-info-summary max-w-xl text-pretty text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" x-text="summary" x-show.important="summary"></p>

            <ul class="rt-info-points mt-5 grid gap-2.5 sm:grid-cols-2" x-show.important="points.length">
                <template x-for="(point, index) in points" :key="index">
                    <li class="rt-info-point group flex min-h-20 items-start gap-3 rounded-2xl bg-rt-surface-muted/70 px-3.5 py-3 text-sm leading-6 text-rt-text ring-1 ring-rt-border/50 dark:bg-rt-dark-surface-muted/60 dark:text-rt-dark-text dark:ring-rt-dark-border/50">
                        <span class="rt-info-point-index mt-0.5 inline-flex h-7 min-w-7 shrink-0 items-center justify-center rounded-lg bg-white text-[10px] font-bold tabular-nums text-rt-red shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-control dark:text-rt-dark-accent dark:ring-rt-dark-border" x-text="String(index + 1).padStart(2, '0')"></span>
                        <span class="rt-info-point-copy pt-0.5" x-text="point"></span>
                    </li>
                </template>
            </ul>
        </div>

        <footer class="rt-info-footer rt-modal-footer flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-rt-border/70 bg-rt-surface-muted/55 px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6">
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
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-5 py-2 text-sm font-semibold text-white shadow-rt-glow transition duration-200 hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30"
            >
                {{ __('app.lets_go') }}
                <i class="far fa-arrow-right text-xs" aria-hidden="true"></i>
            </button>
        </footer>
    </section>

    <div wire:ignore class="pointer-events-none fixed inset-0 z-[20]" data-rt-overlay-portal></div>
</div>
