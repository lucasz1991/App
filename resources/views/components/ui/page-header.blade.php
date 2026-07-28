@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null, 'help' => null])

{{-- Einheitlicher, kompakter Kopf aller Listen-/Inhaltsseiten. Titel und
     Aktionen bleiben in jeder Breite in EINER Zeile; falls der Platz knapp
     wird, kuerzt sich der Titel zugunsten der erreichbaren Aktionen. --}}
<header
    {{ $attributes->class('relative flex min-w-0 flex-nowrap items-center gap-2 sm:gap-3') }}
    data-anim="fade-up"
    data-page-header
>
    <div class="flex min-w-0 flex-1 items-center gap-2">
        <h1 class="min-w-0 truncate text-lg font-semibold leading-tight tracking-[-0.025em] text-rt-text sm:text-xl dark:text-rt-dark-text" title="{{ $title }}">
            {{ $title }}
        </h1>
            @if (! is_null($count))
            <span class="inline-flex h-6 shrink-0 items-center justify-center rounded-md bg-rt-accent-soft px-2 text-[11px] font-bold leading-none tabular-nums text-rt-accent ring-1 ring-inset ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent dark:ring-rt-dark-accent/15">{{ $count }}</span>
            @endif
    </div>

    @if (isset($actions) || $help)
        <div class="flex max-w-[72%] shrink-0 flex-nowrap items-center justify-end gap-1 sm:max-w-[68%]" data-page-header-actions>
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
                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rt-control text-rt-muted shadow-rt-xs ring-1 ring-inset ring-rt-border/80 transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-accent-soft hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:bg-rt-dark-control dark:text-rt-dark-muted dark:ring-rt-dark-border/80 dark:hover:bg-rt-dark-accent-soft dark:hover:text-rt-dark-accent"
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
