@props([
    'href',
    'icon',
    'active' => false,
    'navigate' => true,
])

@php
    $classes = 'sidebar-nav-link flex px-6 py-3 text-sm font-medium text-slate-600 dark:text-slate-300 transition-all duration-150 ease-linear hover:text-rt-red dark:hover:text-rt-red';
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
