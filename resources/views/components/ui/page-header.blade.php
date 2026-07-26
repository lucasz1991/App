@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null, 'help' => null])

<header
    x-data="{ pageHelpOpen: false }"
    x-on:keydown.escape.window="pageHelpOpen = false"
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
            <button
                type="button"
                x-on:click="pageHelpOpen = true"
                class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-rt-border bg-rt-control text-rt-muted shadow-rt-xs transition hover:border-rt-accent/40 hover:bg-rt-surface-muted hover:text-rt-accent focus:outline-none focus:ring-2 focus:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-muted dark:hover:border-rt-dark-accent/40 dark:hover:bg-rt-dark-surface-muted dark:hover:text-rt-dark-accent"
                aria-label="{{ app()->getLocale() === 'de' ? 'Informationen zu dieser Seite' : 'Information about this page' }}"
                title="{{ app()->getLocale() === 'de' ? 'Seitenhilfe' : 'Page help' }}"
            >
                <i class="far fa-info-circle text-base" aria-hidden="true"></i>
            </button>
        @endif
    </div>

    @if ($help)
        <div
            x-show="pageHelpOpen"
            x-cloak
            x-transition.opacity.duration.200ms
            class="fixed inset-0 z-[500] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="page-help-title"
            x-on:click.self="pageHelpOpen = false"
        >
            <section
                x-transition:enter="transition duration-250 ease-out"
                x-transition:enter-start="translate-y-3 scale-[0.98] opacity-0"
                x-transition:enter-end="translate-y-0 scale-100 opacity-100"
                class="w-full max-w-lg overflow-hidden rounded-2xl bg-rt-surface shadow-rt-lg ring-1 ring-rt-border dark:bg-rt-dark-surface dark:ring-rt-dark-border"
            >
                <header class="flex items-start justify-between gap-4 border-b border-rt-border/70 px-5 py-4 dark:border-rt-dark-border/70">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-rt-red">{{ app()->getLocale() === 'de' ? 'Über diese Seite' : 'About this page' }}</p>
                        <h2 id="page-help-title" class="mt-1 text-lg font-semibold text-rt-text dark:text-white">{{ $help['title'] }}</h2>
                    </div>
                    <button type="button" x-on:click="pageHelpOpen = false" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-rt-muted transition hover:bg-rt-surface-muted hover:text-rt-text dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted dark:hover:text-white" aria-label="{{ __('app.close') }}">
                        <i class="far fa-times" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="px-5 py-5">
                    <p class="text-sm leading-6 text-rt-muted dark:text-rt-dark-muted">{{ $help['summary'] }}</p>
                    <ul class="mt-4 space-y-3">
                        @foreach ($help['points'] as $point)
                            <li class="flex gap-3 text-sm leading-6 text-rt-text dark:text-rt-dark-text">
                                <i class="far fa-check-circle mt-1 shrink-0 text-rt-accent dark:text-rt-dark-accent" aria-hidden="true"></i>
                                <span>{{ $point }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('help') }}" wire:navigate class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-rt-red hover:text-rt-red-dark">
                        <i class="far fa-life-ring" aria-hidden="true"></i>
                        {{ app()->getLocale() === 'de' ? 'Alle Hilfethemen öffnen' : 'Open all help topics' }}
                    </a>
                </div>
            </section>
        </div>
    @endif
</header>
