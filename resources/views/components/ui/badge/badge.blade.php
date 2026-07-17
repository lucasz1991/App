@props([
    'color' => 'gray',
    'size' => 'md',
])

@php
    $base = 'inline-flex items-center font-medium rounded-lg';
    $sizes = [
        'sm' => 'px-2 py-0.5 text-xs',
        'md' => 'px-3 py-1 text-sm',
        'lg' => 'px-4 py-1.5 text-base',
    ];

    $colors = [
        'gray'   => 'bg-rt-surface-muted text-rt-muted dark:bg-rt-dark-surface-muted dark:text-rt-dark-muted',
        'blue'   => 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
        'yellow' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        'green'  => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
        'red'    => 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
        'purple' => 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-200',
        'primary'=> 'bg-rt-accent-soft text-rt-accent dark:bg-rt-dark-accent-soft dark:text-rt-dark-accent',
    ];
@endphp

<span {{ $attributes->merge(['class' => "$base {$sizes[$size]} {$colors[$color]}"]) }}>
    {{ $slot }}
</span>
