@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
])

@php
    // Grundstil für alle Inputs
    $baseClasses = 'w-full rounded-lg border-rt-border bg-rt-surface text-rt-text shadow-sm placeholder:text-rt-soft focus:border-rt-accent focus:ring focus:ring-rt-accent/30 dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-white dark:placeholder:text-rt-dark-soft';

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
