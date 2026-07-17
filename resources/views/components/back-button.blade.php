@props([
    'href' => null,
    'label' => null,
])

@php
    $label = $label ?? __('app.back');
    $baseClasses = 'text-slate-700 bg-white hover:bg-slate-50 border-slate-300 dark:text-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 dark:border-slate-600 px-2 py-1 text-sm font-semibold transition-all duration-100 inline-flex items-center justify-center gap-2 text-center border rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900';
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $baseClasses]) }}>
        <i class="far fa-arrow-left"></i>
        <span>{{ trim((string) $slot) !== '' ? $slot : $label }}</span>
    </a>
@else
    <button type="button" onclick="window.history.back()" {{ $attributes->merge(['class' => $baseClasses]) }}>
        <i class="far fa-arrow-left"></i>
        <span>{{ trim((string) $slot) !== '' ? $slot : $label }}</span>
    </button>
@endif
