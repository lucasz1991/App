@props([
    'href',
    'icon',
    'active' => false,
    'navigate' => true,
])

@php
    $classes = 'sidebar-nav-link flex rounded-xl px-6 py-3 text-sm font-medium transition-all duration-150 ease-linear ' . ($active
        ? 'bg-rt-red/10 text-rt-red shadow-sm dark:bg-rt-red/15 dark:text-red-300'
        : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 hover:text-rt-red dark:hover:bg-slate-800/80 dark:hover:text-rt-red');
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
