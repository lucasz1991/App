@props([
    'as' => 'button',
    'href' => null,
    'type' => 'button',
])

@php
    $baseClasses = 'rt-ui-dropdown-link inline-flex min-h-10 w-full items-center gap-2.5 rounded-[0.7rem] px-3 py-2 text-left text-sm leading-5 text-rt-text dark:text-rt-dark-text transition duration-200 ease-rt-spring focus:outline-none';
@endphp

@if($as === 'a')
    <a
        href="{{ $href }}"
        data-rt-dropdown-item
        {{ $attributes->merge(['class' => $baseClasses . ' hover:bg-rt-surface-muted focus:bg-rt-surface-muted dark:hover:bg-rt-dark-nav-hover dark:focus:bg-rt-dark-nav-hover']) }}
    >
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        data-rt-dropdown-item
        {{ $attributes->merge(['class' => $baseClasses . ' hover:bg-rt-surface-muted focus:bg-rt-surface-muted dark:hover:bg-rt-dark-nav-hover dark:focus:bg-rt-dark-nav-hover']) }}
    >
        {{ $slot }}
    </button>
@endif
