@props([
    'href',
    'icon',
    'active' => false,
    'navigate' => true,
])

@php
    $classes = 'sidebar-nav-link flex rounded-xl px-6 py-3 text-sm font-medium transition-all duration-150 ease-linear ' . ($active
        ? 'bg-rt-accent-soft text-rt-accent shadow-sm dark:bg-rt-dark-nav-active dark:text-white dark:shadow-black/20'
        : 'text-rt-muted dark:text-white hover:bg-rt-nav-hover hover:text-rt-accent dark:hover:bg-rt-dark-nav-hover dark:hover:text-white');
@endphp

<li>
    <a
        href="{{ $href }}"
        data-menu-active="{{ $active ? 'true' : 'false' }}"
        {{ $attributes->class($classes) }}
        @if($navigate) wire:navigate @endif
    >
        <span class="sidebar-nav-link__icon" aria-hidden="true">
            <i data-feather="{{ $icon }}" fill="#545a6d33"></i>
        </span>
        <span class="sidebar-nav-link__label">{{ $slot }}</span>
    </a>
</li>
