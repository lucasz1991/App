@props([
    'disabled' => false,
    'placeholder' => null,
])

@php
    $baseClasses = 'w-full rounded-lg border border-rt-border bg-rt-control py-1.5 pl-3 pr-10 text-sm text-rt-text shadow-[0_4px_14px_rgba(15,23,42,0.08)] transition hover:border-rt-accent/40 focus:border-rt-accent focus:ring focus:ring-rt-accent/30 disabled:cursor-not-allowed disabled:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-control dark:text-white dark:shadow-[0_4px_18px_rgba(0,0,0,0.28)] dark:hover:border-rt-dark-accent dark:disabled:bg-rt-dark-canvas';
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
