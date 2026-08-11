@props([
    'id' => null,
    'maxWidth' => null,
    // Schaltet die Inhaltsschleuse ab. Nur fuer Dialoge sinnvoll, deren
    // Inhalt selbst schon eine eigene Ladedarstellung mitbringt.
    'instant' => false,
])

@php
    $modalId = $id ?? md5($attributes->wire('model'));
    $titleId = $modalId.'-title';
    $contentId = $modalId.'-content';
    $gated = ! filter_var($instant, FILTER_VALIDATE_BOOLEAN);
@endphp

<x-modal
    :id="$modalId"
    :maxWidth="$maxWidth"
    role="dialog"
    aria-labelledby="{{ $titleId }}"
    aria-describedby="{{ $contentId }}"
    {{ $attributes->except(['role', 'aria-labelledby', 'aria-describedby']) }}
>
    <header class="rt-modal-header relative grid shrink-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-4 border-b border-rt-border/70 bg-rt-surface px-5 py-4 dark:border-rt-dark-border/70 dark:bg-rt-dark-surface sm:px-6">
        <h2 id="{{ $titleId }}" class="min-w-0 text-lg font-semibold leading-6 tracking-[-0.02em] text-rt-text dark:text-rt-dark-text">
            {{ $title }}
        </h2>

        <div
            class="flex shrink-0 items-center gap-1"
            data-dialog-header-toolbar
        >
            @isset($headerActions)
                <div
                    class="flex items-center gap-1"
                    role="toolbar"
                    aria-label="{{ __('app.actions') }}"
                    data-dialog-header-actions
                >
                    {{ $headerActions }}
                </div>

                <span
                    class="mx-0.5 h-5 w-px shrink-0 bg-rt-border/80 dark:bg-rt-dark-border/80"
                    aria-hidden="true"
                    data-dialog-header-separator
                ></span>
            @endisset

            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="rt-ui-button rt-ui-button-secondary inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-rt-border/70 bg-rt-control text-rt-muted shadow-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/35 hover:text-rt-accent active:scale-[0.97] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/70 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/35 dark:hover:text-rt-dark-accent"
                aria-label="{{ __('app.close') }}"
                title="{{ __('app.close') }}"
                data-dialog-close
            >
                <i class="far fa-times text-base" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    {{--
        INHALTSSCHLEUSE — der Grund, warum die Einfahrt sauber laeuft.

        Die Einfahrt bewegt das Panel per transform. Aendert sich waehrend
        dieser Bewegung die Hoehe des Panels — weil Livewire-Inhalt nachkommt,
        ein Bild fertig laedt oder eine Schrift tauscht — springt der Dialog
        mitten in der Fahrt (gemessen: 317 -> 703 px waehrend der Einfahrt).

        Deshalb: solange der Inhalt nicht steht, zeigt der Rumpf ein Skelett
        mit fester Reservehoehe. Erst wenn alles bereit ist, wird der echte
        Inhalt eingeblendet.

        WICHTIG — kein kuenstlicher Aufenthalt: Steht der Inhalt beim Oeffnen
        bereits (der Normalfall), schaltet rtModalBody noch VOR dem ersten
        Einzelbild auf "ready". Dann erscheint gar kein Skelett und es geht
        keine Zeit verloren.

        display:contents im Bereitzustand: Der Huellcontainer verschwindet
        dadurch aus dem Boxenbaum. Abstands- und :first-child-Regeln der
        Dialoginhalte greifen weiter, als gaebe es ihn nicht.
    --}}
    <div
        id="{{ $contentId }}"
        class="rt-modal-content min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-5 py-5 text-sm leading-6 text-rt-text [overflow-wrap:anywhere] [scrollbar-gutter:stable] dark:text-rt-dark-text sm:px-6 sm:py-6 [&_iframe]:max-w-full [&_img]:max-w-full [&_video]:max-w-full"
        @if ($gated)
            {{-- Grundzustand ist BEREIT, nicht ladend. Auf "loading" schaltet
                 ausschliesslich resources/js/modal-body.js, und nur nachdem
                 es positiv festgestellt hat, dass der Inhalt noch nicht steht.

                 Der Grund ist ein echter Fehler: Livewire schreibt beim
                 Neurendern das serverseitige Markup zurueck. Stand hier
                 "loading", blieb das Skelett nach jeder Aktion IM offenen
                 Dialog fuer immer stehen — etwa beim Teamwechsel unter
                 "Teams und Rechte". Mit "ready" als Grundzustand fuehrt jeder
                 Fehler hoechstens zu einer einmal ruckelnden Einfahrt. --}}
            x-data="rtModalBody"
            data-rt-modal-body
            data-rt-state="ready"
        @endif
    >
        @if ($gated)
            <div class="rt-modal-body-loader" data-rt-modal-loader>
                <x-ui.loading.skeleton variant="list" :rows="3" />
            </div>

            <div data-rt-modal-body-content>
                {{ $content }}
            </div>
        @else
            {{ $content }}
        @endif
    </div>

    @isset($footer)
        <footer class="rt-modal-footer flex shrink-0 flex-row flex-wrap items-center justify-end gap-2 border-t border-rt-border/70 bg-rt-surface-muted/55 px-5 py-4 text-end dark:border-rt-dark-border/70 dark:bg-rt-dark-surface-muted/35 sm:px-6">
            {{ $footer }}
        </footer>
    @endisset
</x-modal>
