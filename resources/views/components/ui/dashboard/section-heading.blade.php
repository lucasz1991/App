@props([
    'title',
    'description' => null,
    'id' => null,
    'icon' => null,
])

@php
    $headingId = $id ?: 'dashboard-section-'.\Illuminate\Support\Str::slug((string) $title);
@endphp

<header {{ $attributes->class('flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between') }}>
    <div class="flex min-w-0 items-start gap-3">
        @if (filled($icon))
            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent" aria-hidden="true">
                <i data-feather="{{ $icon }}" class="h-4 w-4"></i>
            </span>
        @endif

        <div class="min-w-0">
            <h2 id="{{ $headingId }}" class="text-lg font-bold tracking-[-0.02em] text-rt-text dark:text-white">
                {{ $title }}
            </h2>

            @if (filled($description))
                <p class="mt-1 max-w-2xl text-pretty text-sm leading-5 text-rt-muted dark:text-rt-dark-muted">
                    {{ $description }}
                </p>
            @endif
        </div>
    </div>

    @isset($actions)
        <div class="shrink-0">
            {{ $actions }}
        </div>
    @endisset
</header>
