@props([
    'type' => 'text',
    'disabled' => false,
    'readonly' => false,
    'autofocus' => false,
])

@php
    // Grundstil für alle Inputs
    $baseClasses = 'w-full rounded-lg border-slate-300 shadow-sm focus:border-rt-red focus:ring focus:ring-rt-red/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200';

    // Typ-spezifische Klassen (optional)
    $typeClasses = match($type) {
        'date', 'time', 'datetime-local' => 'px-2 py-1.5',
        'file'  => 'text-sm text-slate-700 border border-slate-300 bg-white dark:text-slate-200 dark:border-slate-600 dark:bg-slate-800',
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
