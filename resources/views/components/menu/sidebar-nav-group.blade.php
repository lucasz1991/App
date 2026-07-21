@props([
    'icon',
    'active' => false,
])

@php
    $classes = 'has-arrow sidebar-nav-link relative flex rounded-lg px-6 py-3 text-sm font-medium transition-all duration-300 ease-rt-spring before:absolute before:left-0 before:top-1/2 before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-rt-accent before:transition-all before:duration-300 before:ease-rt-spring dark:before:bg-rt-dark-accent ' . ($active
        ? 'bg-rt-accent-soft/70 text-rt-accent font-semibold shadow-rt-xs before:h-5 before:opacity-100 dark:bg-rt-dark-nav-active dark:text-rt-dark-accent'
        : 'text-rt-muted before:h-0 before:opacity-0 hover:bg-rt-nav-hover hover:text-rt-accent dark:text-white dark:hover:bg-rt-dark-surface-muted dark:hover:text-white');
@endphp

<li
    @class(['mm-active' => $active])
    data-mobile-expanded="{{ $active ? 'true' : 'false' }}"
>
    <a
        href="#"
        data-menu-active="{{ $active ? 'true' : 'false' }}"
        aria-expanded="{{ $active ? 'true' : 'false' }}"
        {{ $attributes->class($classes) }}
    >
        <span class="sidebar-nav-link__icon" aria-hidden="true">
            <i data-feather="{{ $icon }}" fill="#545a6d33"></i>
        </span>
        <span class="sidebar-nav-link__label">{{ $label }}</span>
    </a>

    <ul @class(['mm-collapse', 'mm-show' => $active])>
        {{ $slot }}
    </ul>
</li>
