@props([
    'title',
    'description' => null,
    'eyebrow' => null,
    'count' => null,
    'help' => null,
    'backUrl' => null,
    'showBack' => true,
])

<header
    {{ $attributes->class('relative flex min-w-0 items-start gap-3 sm:gap-4') }}
    data-anim="fade-up"
    data-page-header
>
    @if($showBack)
        <x-ui.buttons.backbutton :href="$backUrl" class="mt-0.5" />
    @endif

    <div class="min-w-0 flex-1">
        @if($eyebrow)
            <p class="mb-1 text-[10px] font-bold uppercase tracking-[0.16em] text-rt-accent dark:text-rt-dark-accent">
                {{ $eyebrow }}
            </p>
        @endif

        <div class="flex min-w-0 items-center gap-2">
            <h1 class="min-w-0 truncate text-xl font-semibold leading-tight tracking-[-0.03em] text-rt-text sm:text-2xl dark:text-rt-dark-text" title="{{ $title }}">
                {{ $title }}
            </h1>
            @if (! is_null($count))
                <span class="inline-flex h-6 shrink-0 items-center justify-center rounded-lg bg-rt-accent-soft px-2 text-[11px] font-bold tabular-nums text-rt-accent ring-1 ring-inset ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent">
                    {{ $count }}
                </span>
            @endif
        </div>

        @if($description)
            <p class="mt-1 max-w-3xl text-sm leading-5 text-rt-muted dark:text-rt-dark-muted">
                {{ $description }}
            </p>
        @endif
    </div>

    @if (isset($actions) || $help)
        <div class="flex shrink-0 flex-nowrap items-center justify-end gap-1.5" data-page-header-actions>
            @isset($actions)
                <div class="flex min-w-0 flex-nowrap items-center justify-end gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    {{ $actions }}
                </div>
            @endisset
            @if ($help)
                <button
                    type="button"
                    x-data
                    x-on:click="$dispatch('rt-info:open', {{ \Illuminate\Support\Js::from([
                        'title' => $help['title'] ?? $title,
                        'summary' => $help['summary'] ?? null,
                        'points' => array_values($help['points'] ?? []),
                    ]) }})"
                    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-rt-border/80 bg-rt-surface text-rt-muted shadow-rt-xs transition duration-200 ease-rt-spring hover:-translate-y-px hover:bg-rt-accent-soft hover:text-rt-accent focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/80 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent-soft dark:hover:text-rt-dark-accent"
                    aria-label="{{ app()->getLocale() === 'de' ? 'Informationen zu dieser Seite' : 'Information about this page' }}"
                    title="{{ app()->getLocale() === 'de' ? 'Seitenhilfe' : 'Page help' }}"
                    data-page-info-button
                >
                    <i class="far fa-info-circle text-sm" aria-hidden="true"></i>
                </button>
            @endif
        </div>
    @endif
</header>
