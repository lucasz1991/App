@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
])

@php
    // Grundstil für alle Inputs
    $baseClasses = 'w-full rounded-lg border border-rt-border bg-rt-control text-rt-text shadow-[0_4px_14px_rgba(15,23,42,0.08)] placeholder:text-rt-soft transition hover:border-rt-accent/40 focus:border-rt-accent focus:ring focus:ring-rt-accent/30 disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:opacity-70 read-only:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:placeholder:text-rt-dark-soft dark:shadow-[0_4px_18px_rgba(0,0,0,0.28)] dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas dark:read-only:bg-rt-dark-canvas';

    // Typ-spezifische Klassen (optional)
    $typeClasses = match($type) {
        'date', 'time', 'datetime-local' => 'px-2 py-1.5',
        'file'  => 'border text-sm',
        'number' => 'text-right',
        default => 'px-2 py-1.5',
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
