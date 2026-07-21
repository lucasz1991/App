@props([
    'disabled' => false,
    'readonly' => false,
    'rows' => 4,
])

@php
    // Gemeinsamer mehrzeiliger Text-Control: mobil mindestens 16 px und mit
    // demselben dualen Dark-Mode-Vertrag wie Input und Select.
    $baseClasses = 'rt-ui-control min-h-28 w-full resize-y rounded-xl border border-rt-border bg-rt-control px-3.5 py-2.5 text-base leading-6 text-rt-text shadow-rt-xs outline-none placeholder:text-rt-soft transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft disabled:opacity-70 read-only:bg-rt-surface-muted sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-rt-dark-text dark:placeholder:text-rt-dark-soft dark:hover:border-rt-dark-accent dark:focus:ring-rt-dark-accent/20 dark:disabled:bg-rt-dark-canvas dark:read-only:bg-rt-dark-canvas';
@endphp

<textarea
    rows="{{ $rows }}"
    {{ $disabled ? 'disabled' : '' }}
    {{ $readonly ? 'readonly' : '' }}
    {{ $attributes->merge(['class' => $baseClasses]) }}
>{{ $slot }}</textarea>
