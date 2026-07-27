@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null, 'help' => null])

{{-- Einheitlicher, responsiver Kopf aller Listen-/Inhaltsseiten. Titel,
     Kontext und Beschreibung bleiben lesbar; Aktionen wechseln auf schmalen
     Screens kontrolliert in eine eigene Zeile. --}}
<header
    {{ $attributes->class('relative flex flex-col gap-4 overflow-hidden rounded-[1.4rem] bg-rt-surface/95 p-4 shadow-rt-sm ring-1 ring-rt-border/70 backdrop-blur-xl sm:flex-row sm:items-center sm:justify-between sm:p-5 dark:bg-rt-dark-surface/95 dark:ring-rt-dark-border/70') }}
    data-anim="fade-up"
    data-page-header
>
    <span class="pointer-events-none absolute inset-y-4 left-0 w-1 rounded-r-full bg-rt-red" aria-hidden="true"></span>

    <div class="min-w-0">
        @if (filled($eyebrow))
            <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-rt-accent dark:text-rt-dark-accent">
                {{ $eyebrow }}
            </p>
        @endif

        <div class="flex min-w-0 flex-wrap items-center gap-2.5">
            <h1 class="text-balance text-2xl font-semibold leading-tight tracking-[-0.035em] text-rt-text sm:text-3xl dark:text-rt-dark-text">{{ $title }}</h1>
            @if (! is_null($count))
                <span class="inline-flex h-7 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft px-2.5 text-xs font-bold leading-none tabular-nums text-rt-accent ring-1 ring-inset ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">{{ $count }}</span>
            @endif
        </div>

        @if (filled($description))
            <p class="mt-2 max-w-3xl text-pretty text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">
                {{ $description }}
            </p>
        @endif
    </div>

    <div class="flex w-full min-w-0 items-start gap-2 rounded-xl bg-rt-surface-muted/70 p-1.5 ring-1 ring-inset ring-rt-border/60 sm:w-auto sm:max-w-[58%] sm:justify-end dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60" data-page-header-actions>
        @isset($actions)
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2 sm:flex-none sm:justify-end">
                {{ $actions }}
            </div>
        @endisset
        @if ($help)
            {{-- Oeffnet das EINE globale Infomodal (x-ui.info-modal, einmal je
                 Layout) mit dem Inhalt dieser Seite. Bewusst kein eigener
                 Dialog pro Button. --}}
            <button
                type="button"
                x-data
                x-on:click="$dispatch('rt-info:open', {{ \Illuminate\Support\Js::from([
                    'title' => $help['title'] ?? $title,
                    'summary' => $help['summary'] ?? null,
                    'points' => array_values($help['points'] ?? []),
                ]) }})"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-[0.7rem] bg-rt-control text-rt-muted shadow-rt-xs ring-1 ring-inset ring-rt-border/80 transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-accent-soft hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:ring-rt-dark-border/80 dark:hover:bg-rt-dark-accent-soft dark:hover:text-rt-dark-accent"
                aria-label="{{ app()->getLocale() === 'de' ? 'Informationen zu dieser Seite' : 'Information about this page' }}"
                title="{{ app()->getLocale() === 'de' ? 'Seitenhilfe' : 'Page help' }}"
                data-page-info-button
            >
                <i class="far fa-info-circle text-base" aria-hidden="true"></i>
            </button>
        @endif
    </div>

    {{-- Kein eigener Dialog mehr an dieser Stelle: der Inhalt wird an das
         globale x-ui.info-modal geschickt (siehe Button oben). --}}
</header>
