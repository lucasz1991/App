@props([
    'href',
    'title',
    'description' => null,
    'icon' => 'arrow-up-right',
    'navigate' => true,
    'actionLabel' => null,
])

@php
    $resolvedActionLabel = $actionLabel ?: __('app.open');
@endphp

<a
    href="{{ $href }}"
    @if ($navigate) wire:navigate @endif
    {{ $attributes->class('group flex min-h-32 flex-col rounded-2xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/70 outline-none motion-safe:transition motion-safe:duration-200 motion-safe:ease-out motion-safe:hover:-translate-y-0.5 motion-safe:hover:shadow-rt-md focus-visible:ring-2 focus-visible:ring-rt-accent focus-visible:ring-offset-2 motion-reduce:transition-none dark:bg-rt-dark-surface dark:ring-rt-dark-border/70 dark:focus-visible:ring-offset-rt-dark-canvas') }}
    data-rt-glow
>
    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rt-accent-soft text-rt-accent ring-1 ring-rt-accent/10 dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent" aria-hidden="true">
        <i data-feather="{{ $icon }}" class="h-5 w-5"></i>
    </span>

    <span class="mt-4 block text-sm font-bold text-rt-text dark:text-white">
        {{ $title }}
    </span>

    @if (filled($description))
        <span class="mt-1 block text-pretty text-xs leading-5 text-rt-muted dark:text-rt-dark-muted">
            {{ $description }}
        </span>
    @endif

    <span class="mt-auto flex items-center justify-between gap-3 pt-4 text-xs font-semibold text-rt-accent dark:text-rt-dark-accent">
        <span>{{ $resolvedActionLabel }}</span>
        <i data-feather="arrow-up-right" class="h-4 w-4 motion-safe:transition-transform motion-safe:duration-200 motion-safe:group-hover:translate-x-0.5 motion-safe:group-hover:-translate-y-0.5 motion-reduce:transition-none" aria-hidden="true"></i>
    </span>
</a>
