@props([
    'label' => null,
])

@if(filled($label))
    <li class="sidebar-nav__section block cursor-default px-5 py-3 text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400 group-data-[sidebar-size=sm]:hidden !h-10 !max-h-10" data-key="t-menu">
        {{ $label }}
    </li>
@endif

{{ $slot }}
