@props([
    'as' => 'button',
    'href' => null,
    'type' => 'button',
])

@php
    $baseClasses = 'rt-ui-dropdown-link inline-flex items-center w-full px-4 py-2 text-left text-sm leading-5 text-rt-text dark:text-rt-dark-text transition duration-150 ease-in-out focus:outline-none';
@endphp

@if($as === 'a')
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => $baseClasses . ' hover:bg-rt-surface-muted focus:bg-rt-surface-muted dark:hover:bg-rt-dark-nav-hover dark:focus:bg-rt-dark-nav-hover']) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->merge(['class' => $baseClasses . ' hover:bg-rt-surface-muted focus:bg-rt-surface-muted dark:hover:bg-rt-dark-nav-hover dark:focus:bg-rt-dark-nav-hover']) }}
    >
        {{ $slot }}
    </button>
@endif
