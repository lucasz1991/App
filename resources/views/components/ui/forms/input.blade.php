@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
])

@php
    // Mobil immer mindestens 16 px Schriftgroesse: verhindert den automatischen
    // Browser-Zoom beim Fokussieren auf iOS und bleibt am Desktop kompakt.
    $baseClasses = 'min-h-11 w-full rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-70 read-only:bg-rt-surface-muted sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:focus:ring-rt-dark-accent/20 dark:disabled:bg-rt-dark-canvas dark:read-only:bg-rt-dark-canvas';

    // Typ-spezifische Klassen (optional)
    $typeClasses = match($type) {
        'file' => 'cursor-pointer file:mr-3 file:rounded-lg file:border-0 file:bg-rt-accent-soft file:px-3 file:py-2 file:text-sm file:font-semibold file:text-rt-accent hover:file:bg-rt-accent hover:file:text-white dark:file:bg-rt-dark-surface-muted dark:file:text-white',
        'number' => 'text-right tabular-nums',
        default => '',
    };

    $allClasses = $baseClasses . ' ' . $typeClasses;
@endphp

<input
    type="{{ $type }}"
    {{ $disabled ? 'disabled' : '' }}
    {{ $readonly ? 'readonly' : '' }}
    {{ $autofocus ? 'autofocus' : '' }}
    {!! $attributes->merge(['class' => $allClasses]) !!}
>
