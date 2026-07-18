@props([
    'href' => null,
    'label' => null,
])

@php
    $label = $label ?? __('app.back');
    $baseClasses = 'text-rt-text bg-rt-surface hover:bg-rt-surface-muted border-rt-border dark:text-rt-dark-text dark:bg-rt-dark-surface dark:hover:bg-rt-dark-surface-muted dark:border-rt-dark-border px-2 py-1 text-sm font-semibold transition-all duration-300 ease-rt-spring inline-flex items-center justify-center gap-2 text-center border rounded-lg shadow-rt-xs active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900';
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
