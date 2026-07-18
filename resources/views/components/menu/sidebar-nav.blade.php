@props([
    'label' => null,
])

@if(filled($label))
    <li class="sidebar-nav__section block cursor-default px-5 py-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-rt-soft dark:text-rt-dark-soft group-data-[sidebar-size=sm]:hidden !h-10 !max-h-10" data-key="t-menu">
        {{ $label }}
    </li>
@endif

{{ $slot }}
