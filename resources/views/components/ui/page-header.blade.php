@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null, 'help' => null])

<header
    {{ $attributes->class('flex flex-wrap items-center justify-between gap-3') }}
    data-anim="fade-up"
>
    <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1">
        @if ($eyebrow)
            <span class="shrink-0 text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-muted dark:text-rt-dark-muted">{{ $eyebrow }}</span>
            <span class="hidden h-3 w-px bg-rt-border sm:block dark:bg-rt-dark-border" aria-hidden="true"></span>
        @endif
        <div class="flex min-w-0 items-center gap-2">
            <h1 class="truncate text-xl font-semibold tracking-tight text-rt-text sm:text-2xl dark:text-rt-dark-text">{{ $title }}</h1>
            @if (! is_null($count))
                <span class="inline-flex h-7 items-center justify-center rounded-full bg-rt-surface px-2.5 text-xs font-bold leading-none text-rt-red shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-red dark:ring-rt-dark-border/60">{{ $count }}</span>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
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
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs transition hover:border-rt-accent/40 hover:bg-rt-surface-muted hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/40 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
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
