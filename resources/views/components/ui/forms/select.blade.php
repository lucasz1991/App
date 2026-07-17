@props([
    'disabled' => false,
    'placeholder' => null,
])

@php
    $baseClasses = 'w-full rounded-lg border-slate-300 bg-white py-1.5 pl-3 pr-10 text-sm shadow-sm focus:border-rt-red focus:ring focus:ring-rt-red/30 disabled:cursor-not-allowed disabled:bg-slate-100 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:disabled:bg-slate-900';
@endphp

<div class="relative">
    <select {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => $baseClasses]) !!}>
        @if($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif

        {{ $slot }}
    </select>

    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
        </svg>
    </span>
</div>
