@props(['title', 'description' => null, 'eyebrow' => null, 'count' => null])

<header {{ $attributes->class('flex flex-wrap items-end justify-between gap-4') }} data-anim="fade-up">
    <div>
        @if ($eyebrow)
            <p class="inline-flex items-center rounded-full bg-rt-accent-soft/70 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">{{ $eyebrow }}</p>
        @endif
        <div class="{{ $eyebrow ? 'mt-2' : '' }} flex items-center gap-2">
            <h1 class="text-2xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $title }}</h1>
            @if (! is_null($count))
                <span class="inline-flex h-7 items-center justify-center rounded-full bg-rt-surface px-2.5 text-xs font-bold leading-none text-rt-red shadow-rt-xs ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:text-rt-red dark:ring-rt-dark-border/60">{{ $count }}</span>
            @endif
        </div>
        @if ($description)
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</header>
