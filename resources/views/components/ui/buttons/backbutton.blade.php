@props([
    'href' => null,
    'fallback' => null,
    'label' => null,
])

@php
    $label = $label ?: (app()->getLocale() === 'de' ? 'Zurück' : 'Back');
    $fallback = $fallback ?: url('/');
    $classes = 'group inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-rt-border/80 bg-rt-surface text-rt-muted shadow-rt-xs transition duration-200 ease-rt-spring hover:-translate-x-0.5 hover:border-rt-accent/25 hover:bg-rt-accent-soft hover:text-rt-accent focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-rt-accent/15 dark:border-rt-dark-border/80 dark:bg-rt-dark-surface dark:text-rt-dark-muted dark:hover:bg-rt-dark-accent-soft dark:hover:text-rt-dark-accent';
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} aria-label="{{ $label }}" title="{{ $label }}" data-page-header-control>
        <i class="far fa-arrow-left text-sm transition-transform group-hover:-translate-x-0.5" aria-hidden="true"></i>
    </a>
@else
    <button
        type="button"
        {{ $attributes->class($classes) }}
        x-data
        x-on:click="window.history.length > 1 ? window.history.back() : window.location.assign(@js($fallback))"
        aria-label="{{ $label }}"
        title="{{ $label }}"
        data-page-header-control
    >
        <i class="far fa-arrow-left text-sm transition-transform group-hover:-translate-x-0.5" aria-hidden="true"></i>
    </button>
@endif
