@props([
    'disabled' => false,
    'placeholder' => null,
])

@php
    $baseClasses = 'rt-ui-control min-h-11 w-full appearance-none rounded-xl border border-rt-border bg-rt-control py-2.5 pl-3.5 pr-10 text-base leading-6 text-rt-text shadow-rt-xs outline-none transition-all duration-200 ease-rt-spring hover:border-rt-accent/50 focus:border-rt-accent focus:ring-4 focus:ring-rt-accent/15 disabled:cursor-not-allowed disabled:bg-rt-surface-muted disabled:text-rt-soft sm:text-sm sm:leading-5 dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:hover:border-rt-dark-accent dark:focus:ring-rt-dark-accent/20 dark:disabled:bg-rt-dark-canvas';
@endphp

<div class="relative">
    <select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => $baseClasses]) !!}>
        @if($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        {{ $slot }}
    </select>

    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-rt-soft dark:text-rt-dark-soft">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
    </span>
</div>
