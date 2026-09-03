@props([
    'title',
    'description' => null,
    'id' => null,
    'icon' => null,
    'variant' => 'default',
])

@php
    $headingId = $id ?: 'dashboard-section-'.\Illuminate\Support\Str::slug((string) $title);
    $isMinimal = $variant === 'minimal';
@endphp

<header
    {{ $attributes->class('flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:justify-between') }}
    data-dashboard-heading-variant="{{ $variant }}"
>
    <div class="flex min-w-0 items-start gap-3">
        @if (filled($icon))
            @if ($isMinimal)
                <span class="mt-1.5 h-5 w-1 shrink-0 rounded-sm bg-rt-red" aria-hidden="true"></span>
            @else
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent" aria-hidden="true">
                    <i data-feather="{{ $icon }}" class="h-4 w-4"></i>
                </span>
            @endif
        @endif

        <div class="min-w-0">
            <h2 id="{{ $headingId }}" @class([
                'text-lg tracking-[-0.02em] text-rt-text dark:text-white',
                'font-semibold' => $isMinimal,
                'font-bold' => ! $isMinimal,
            ])>
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
        <div @class(['shrink-0', 'w-full sm:w-auto' => $isMinimal])>
            {{ $actions }}
        </div>
    @endisset
</header>
