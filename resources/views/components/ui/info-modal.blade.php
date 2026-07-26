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
    x-on:keydown.escape.window="close()"
    x-show.important="open"
    x-cloak
    class="fixed inset-0 z-[500] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm"
    x-on:click.self="close()"
    data-rt-info-modal
>
    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="rt-info-modal-title"
        x-trap.inert.noscroll="open"
        x-transition:enter="transition duration-250 ease-out"
        x-transition:enter-start="translate-y-3 scale-[0.97] opacity-0"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        class="w-full max-w-lg overflow-hidden rounded-3xl bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border/70 dark:bg-rt-dark-surface dark:text-white dark:ring-rt-dark-border/70"
    >
        {{-- Markanter Kopf im RailTime-Stil (analog zum Willkommens-Intro) --}}
        <header class="relative overflow-hidden bg-[linear-gradient(135deg,#e4002b_0%,#a3001f_62%,#151b24_135%)] px-6 pb-5 pt-6">
            <div class="pointer-events-none absolute -right-10 -top-14 h-40 w-40 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>
            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white/15 text-white shadow-rt-sm ring-1 ring-white/25 backdrop-blur">
                        <i class="far fa-info text-lg" aria-hidden="true"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-white/75">{{ __('app.about_this_page') }}</p>
                        <h2 id="rt-info-modal-title" class="mt-0.5 truncate text-lg font-semibold text-white" x-text="title"></h2>
                    </div>
                </div>
                <button
                    type="button"
                    x-on:click="close()"
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-white/80 transition hover:bg-white/15 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/60"
                    aria-label="{{ __('app.close') }}"
                >
                    <i class="far fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <div class="px-6 py-5">
            <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" x-text="summary" x-show.important="summary"></p>

            <ul class="mt-4 space-y-2.5" x-show.important="points.length">
                <template x-for="(point, index) in points" :key="index">
                    <li class="flex items-start gap-3 rounded-xl bg-rt-surface-muted/70 px-3 py-2.5 text-sm leading-6 text-rt-text ring-1 ring-rt-border/50 dark:bg-rt-dark-surface-muted/60 dark:text-rt-dark-text dark:ring-rt-dark-border/50">
                        <i class="far fa-check-circle mt-1 shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                        <span x-text="point"></span>
                    </li>
                </template>
            </ul>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-rt-border/60 pt-4 dark:border-rt-dark-border/60">
                <a
                    href="{{ route('help') }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 text-sm font-semibold text-rt-red hover:text-rt-red-dark"
                >
                    <i class="far fa-life-ring" aria-hidden="true"></i>
                    {{ __('app.open_all_help_topics') }}
                </a>

                {{-- Nur im Intro-Modus: deutlicher Abschluss-Knopf. --}}
                <button
                    type="button"
                    x-show.important="intro"
                    x-cloak
                    x-on:click="close()"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-rt-red px-5 py-2 text-sm font-semibold text-white shadow-rt-glow transition hover:-translate-y-0.5 hover:bg-rt-red-dark focus:outline-none focus:ring-2 focus:ring-rt-red/30"
                >
                    {{ __('app.lets_go') }}
                    <i class="far fa-arrow-right text-xs" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </section>
</div>
