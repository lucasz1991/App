@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null, 'help' => null])

{{-- Einheitlicher, kompakter Kopf aller Listen-/Inhaltsseiten. Titel und
     Aktionen bleiben in jeder Breite in EINER Zeile; falls der Platz knapp
     wird, kuerzt sich der Titel zugunsten der erreichbaren Aktionen. --}}
<header
    {{ $attributes->class('relative flex min-w-0 flex-nowrap items-center gap-2 overflow-hidden rounded-[1.05rem] bg-rt-surface/92 px-3 py-2.5 shadow-rt-xs ring-1 ring-rt-border/70 backdrop-blur-xl sm:gap-3 sm:px-4 sm:py-3 dark:bg-rt-dark-surface/92 dark:ring-rt-dark-border/70') }}
    data-anim="fade-up"
    data-page-header
>
    <span class="pointer-events-none absolute inset-y-2.5 left-0 w-0.5 rounded-r-full bg-rt-red" aria-hidden="true"></span>

    <div class="flex min-w-0 flex-1 items-center gap-2 ps-1">
        <h1 class="min-w-0 truncate text-lg font-semibold leading-tight tracking-[-0.025em] text-rt-text sm:text-xl dark:text-rt-dark-text" title="{{ $title }}">
            {{ $title }}
        </h1>
            @if (! is_null($count))
            <span class="inline-flex h-6 shrink-0 items-center justify-center rounded-md bg-rt-accent-soft px-2 text-[11px] font-bold leading-none tabular-nums text-rt-accent ring-1 ring-inset ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">{{ $count }}</span>
            @endif
    </div>

    @if (isset($actions) || $help)
        <div class="flex max-w-[72%] shrink-0 flex-nowrap items-center justify-end gap-1 rounded-[0.8rem] bg-rt-surface-muted/70 p-1 ring-1 ring-inset ring-rt-border/60 dark:bg-rt-dark-surface-muted/45 dark:ring-rt-dark-border/60 sm:max-w-[68%]" data-page-header-actions>
            @isset($actions)
                <div class="flex min-w-0 flex-nowrap items-center justify-end gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[0.65rem] bg-rt-control text-rt-muted shadow-rt-xs ring-1 ring-inset ring-rt-border/80 transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-accent-soft hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:ring-rt-dark-border/80 dark:hover:bg-rt-dark-accent-soft dark:hover:text-rt-dark-accent"
                aria-label="{{ app()->getLocale() === 'de' ? 'Informationen zu dieser Seite' : 'Information about this page' }}"
                title="{{ app()->getLocale() === 'de' ? 'Seitenhilfe' : 'Page help' }}"
                data-page-info-button
            >
                <i class="far fa-info-circle text-base" aria-hidden="true"></i>
            </button>
            @endif
        </div>
    @endif

    {{-- Kein eigener Dialog mehr an dieser Stelle: der Inhalt wird an das
         globale x-ui.info-modal geschickt (siehe Button oben). --}}
</header>
