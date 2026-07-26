{{--
    EIN globales Infomodal fuer die ganze Anwendung.

    Jeder Info-Button schickt lediglich ein Fenster-Ereignis mit seinem Inhalt:

        x-on:click="$dispatch('rt-info:open', {
            title: '...', summary: '...', points: ['...'],
        })"

    Dadurch existiert nur noch ein Dialog im DOM statt eines pro Button.

    WICHTIG — x-show.important:
    Die Legacy-Datei public/build/css/tailwind.min.css definiert die
    Display-Utilities mit !important (.flex{display:flex!important}). Alpines
    x-show setzt ein INLINE display:none, und das verliert gegen ein
    !important aus dem Stylesheet. Ohne den .important-Modifier bleibt dieser
    Dialog dauerhaft sichtbar und laesst sich nicht schliessen — genau dieser
    Fehler trat vorher bei den Infomodalen auf. Nicht entfernen.
--}}
<div
    x-data="{
        open: false,
        title: '',
        summary: '',
        points: [],
        show(detail) {
            this.title = detail?.title ?? '';
            this.summary = detail?.summary ?? '';
            this.points = Array.isArray(detail?.points) ? detail.points : [];
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
    class="fixed inset-0 z-[500] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
    x-on:click.self="close()"
    data-rt-info-modal
>
    <section
        role="dialog"
        aria-modal="true"
        aria-labelledby="rt-info-modal-title"
        x-trap.inert.noscroll="open"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-y-3 scale-[0.98] opacity-0"
        x-transition:enter-end="translate-y-0 scale-100 opacity-100"
        class="w-full max-w-lg overflow-hidden rounded-2xl bg-rt-surface text-rt-text shadow-rt-lg ring-1 ring-rt-border dark:bg-rt-dark-surface dark:text-white dark:ring-rt-dark-border"
    >
        <header class="flex items-start justify-between gap-4 border-b border-rt-border/70 px-5 py-4 dark:border-rt-dark-border/70">
            <div class="min-w-0">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-red">{{ __('app.about_this_page') }}</p>
                <h2 id="rt-info-modal-title" class="mt-1 text-lg font-semibold" x-text="title"></h2>
            </div>
            <button
                type="button"
                x-on:click="close()"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white"
                aria-label="{{ __('app.close') }}"
            >
                <i class="far fa-times" aria-hidden="true"></i>
            </button>
        </header>

        <div class="px-5 py-5">
            <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted" x-text="summary" x-show.important="summary"></p>

            <ul class="mt-4 space-y-3" x-show.important="points.length">
                <template x-for="(point, index) in points" :key="index">
                    <li class="flex gap-3 text-sm leading-6 text-rt-text dark:text-rt-dark-text">
                        <i class="far fa-check-circle mt-1 shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                        <span x-text="point"></span>
                    </li>
                </template>
            </ul>

            <a
                href="{{ route('help') }}"
                wire:navigate
                class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-rt-red hover:text-rt-red-dark"
            >
                <i class="far fa-life-ring" aria-hidden="true"></i>
                {{ __('app.open_all_help_topics') }}
            </a>
        </div>
    </section>
</div>
