@props([
    'align' => 'right',
    'width' => '48',
    'contentClasses' => 'py-1 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-white',
    'dropdownClasses' => '',
    'offset' => 8,
])

{{-- Rueckwaertskompatibler Alias: Alle bisherigen <x-dropdown>-Aufrufe
     verwenden damit dieselbe anchor-basierte Dropdown-Implementierung. --}}
<x-ui.dropdown.anchor-dropdown
    :align="$align"
    :width="$width"
    :content-classes="$contentClasses"
    :dropdown-classes="$dropdownClasses"
    :offset="$offset"
    {{ $attributes }}
>
    <x-slot:trigger>{{ $trigger }}</x-slot:trigger>
    <x-slot:content>{{ $content }}</x-slot:content>
</x-ui.dropdown.anchor-dropdown>
