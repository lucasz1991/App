@props([
    'href' => null,
    'label' => null,
])

@php
    $label = $label ?? __('app.back');
    $baseClasses = 'rt-ui-button rt-ui-button-secondary text-rt-text bg-rt-surface hover:bg-rt-surface-muted border-rt-border dark:text-rt-dark-text dark:bg-rt-dark-surface dark:hover:bg-rt-dark-surface-muted dark:border-rt-dark-border px-2 py-1 text-sm font-semibold transition-all duration-300 ease-rt-spring inline-flex items-center justify-center gap-2 text-center border rounded-lg shadow-rt-xs active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-rt-red/40 focus:ring-offset-2 dark:focus:ring-offset-slate-900';

    // Interne Links navigieren SPA-artig via wire:navigate (Livewire);
    // mit data-no-navigate kann ein Aufrufer das gezielt deaktivieren.
    $hrefString = (string) ($href ?? '');
    $shouldNavigate = $hrefString !== ''
        && ! $attributes->has('wire:navigate')
        && ! $attributes->has('target')
        && ! $attributes->has('download')
        && ! $attributes->has('data-no-navigate')
        && ! preg_match('~^(mailto:|tel:|#|javascript:|data:)~i', $hrefString)
        && (str_starts_with($hrefString, '/') || str_starts_with($hrefString, url('/')));
@endphp

@if($href)
    <a href="{{ $href }}" @if($shouldNavigate) wire:navigate @endif {{ $attributes->merge(['class' => $baseClasses]) }}>
        <i class="far fa-arrow-left"></i>
        <span>{{ trim((string) $slot) !== '' ? $slot : $label }}</span>
    </a>
@else
    <button type="button" onclick="window.history.back()" {{ $attributes->merge(['class' => $baseClasses]) }}>
        <i class="far fa-arrow-left"></i>
        <span>{{ trim((string) $slot) !== '' ? $slot : $label }}</span>
    </button>
@endif
