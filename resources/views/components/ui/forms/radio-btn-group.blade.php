@props([
    'size'   => 'md',     // sm|md
    'full'   => false,    // true => items grow equally
    'divide' => true,     // show vertical dividers between items
])

@php
    $pad = $size === 'sm' ? 'px-3 py-1.5' : 'px-4 py-2';
@endphp

<div
    {{ $attributes->class([
        // Outer frame carries border & rounded
        "inline-flex items-stretch overflow-hidden rounded-lg border border-rt-border bg-rt-surface shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface",
        // Optional dividers between buttons (purely visual; no double borders)
        "divide-x divide-rt-border/60 dark:divide-rt-dark-border/60" => $divide,
        // Make all children flex equally when desired
        "w-full" => $full,
    ]) }}
    role="group"
    data-rbg-group
>
    <style>
        [data-rbg-group] [data-rbg-item] { --rbg-pad-x: {{ $size === 'sm' ? '0.75rem' : '1rem' }}; --rbg-pad-y: {{ $size === 'sm' ? '0.375rem' : '0.5rem' }}; }
    </style>
    {{ $slot }}
</div>
