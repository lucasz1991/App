@props([
    'href',
    'icon',
    'active' => false,
    'navigate' => true,
])

@php
    $classes = 'sidebar-nav-link relative flex rounded-lg px-6 py-3 text-sm font-medium transition-all duration-300 ease-rt-spring before:absolute before:left-0 before:top-1/2 before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-rt-accent before:transition-all before:duration-300 before:ease-rt-spring dark:before:bg-rt-dark-accent [&.active]:font-semibold [&.active]:text-rt-accent [&.active]:before:h-5 [&.active]:before:opacity-100 dark:[&.active]:text-rt-dark-accent ' . ($active
        ? 'bg-rt-accent-soft/70 text-rt-accent font-semibold shadow-rt-xs before:h-5 before:opacity-100 dark:bg-rt-dark-nav-active dark:text-rt-dark-accent'
        : 'text-rt-muted before:h-0 before:opacity-0 hover:bg-rt-nav-hover hover:text-rt-accent dark:text-white dark:hover:bg-rt-dark-surface-muted dark:hover:text-white');
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
