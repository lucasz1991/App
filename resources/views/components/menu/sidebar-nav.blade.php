@props([
    'label' => null,
    'collapsible' => false,
    'icon' => 'layers',
    'active' => false,
])

@if($collapsible && filled($label))
    <x-menu.sidebar-nav-group :icon="$icon" :active="$active" :submenu-id="'rt-sidebar-section-'.\Illuminate\Support\Str::slug($label)">
        <x-slot:label>{{ $label }}</x-slot:label>
        {{ $slot }}
    </x-menu.sidebar-nav-group>
@else
@if(filled($label))
    <li class="sidebar-nav__section block cursor-default px-5 py-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-soft dark:text-rt-dark-soft group-data-[sidebar-size=sm]:hidden !h-10 !max-h-10" data-key="t-menu" data-rt-sidebar-section>
        {{ $label }}
    </li>
@endif

{{ $slot }}
@endif
