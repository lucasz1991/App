@props([
    'icon',
    'active' => false,
    'depth' => 0,
    'submenuId' => null,
])

@php
    $submenuId ??= 'rt-sidebar-group-'.\Illuminate\Support\Str::slug(strip_tags($label));
    $classes = 'has-arrow sidebar-nav-link relative flex rounded-lg px-6 py-3 text-sm font-medium transition-all duration-300 ease-rt-spring before:absolute before:left-0 before:top-1/2 before:w-[3px] before:-translate-y-1/2 before:rounded-full before:bg-rt-accent before:transition-all before:duration-300 before:ease-rt-spring dark:before:bg-rt-dark-accent ' . ($active
        ? 'bg-rt-accent-soft/70 text-rt-accent font-semibold shadow-rt-xs before:h-5 before:opacity-100 dark:bg-rt-dark-nav-active dark:text-rt-dark-accent'
        : 'text-rt-muted before:h-0 before:opacity-0 hover:bg-rt-nav-hover hover:text-rt-accent dark:text-white dark:hover:bg-rt-dark-surface-muted dark:hover:text-white');
@endphp

<li @class(['mm-active' => $active])>
    <a
        href="#"
        data-menu-active="{{ $active ? 'true' : 'false' }}"
        data-rt-sidebar-group
        role="button"
        aria-controls="{{ $submenuId }}"
        aria-expanded="{{ $active ? 'true' : 'false' }}"
        @keydown.space.prevent="$el.click()"
        @if($depth > 0) style="padding-left: {{ 24 + (8 * $depth) }}px !important" @endif
        {{ $attributes->class($classes) }}
    >
        <span class="sidebar-nav-link__icon" aria-hidden="true">
            <i data-feather="{{ $icon }}" fill="#545a6d33"></i>
        </span>
        <span class="sidebar-nav-link__label">{{ $label }}</span>
        <span class="sidebar-nav-link__chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="m9 18 6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </a>

    <ul id="{{ $submenuId }}" @class(['mm-collapse', 'mm-show' => $active])>
        {{ $slot }}
    </ul>
</li>
