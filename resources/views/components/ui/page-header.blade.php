@props(['title', 'description' => null, 'eyebrow' => null])

<header {{ $attributes->class('flex flex-wrap items-end justify-between gap-4') }}>
    <div>
        @if ($eyebrow)
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rt-accent dark:text-rt-dark-accent">{{ $eyebrow }}</p>
        @endif
        <h1 class="{{ $eyebrow ? 'mt-1' : '' }} text-2xl font-semibold tracking-tight text-rt-text dark:text-rt-dark-text">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-rt-muted dark:text-rt-dark-muted">{{ $description }}</p>
        @endif
    </div>
    @if (isset($actions))
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</header>
