@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null, 'help' => null])

{{-- Einheitlicher, responsiver Kopf aller Listen-/Inhaltsseiten. Titel,
     Kontext und Beschreibung bleiben lesbar; Aktionen wechseln auf schmalen
     Screens kontrolliert in eine eigene Zeile. --}}
<header
    {{ $attributes->class('flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between') }}
    data-anim="fade-up"
>
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

    <div class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
        @isset($actions)
            {{ $actions }}
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
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl bg-rt-control text-rt-muted shadow-rt-xs ring-1 ring-inset ring-rt-border/80 transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-surface-muted hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:ring-rt-dark-border/80 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                aria-label="{{ app()->getLocale() === 'de' ? 'Informationen zu dieser Seite' : 'Information about this page' }}"
                title="{{ app()->getLocale() === 'de' ? 'Seitenhilfe' : 'Page help' }}"
            >
                <i class="far fa-info-circle text-base" aria-hidden="true"></i>
            </button>
        @endif
    </div>

    {{-- Kein eigener Dialog mehr an dieser Stelle: der Inhalt wird an das
         globale x-ui.info-modal geschickt (siehe Button oben). --}}
</header>
