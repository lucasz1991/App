@props([
    'width' => '64',
    'label' => null,
])

@php
    $resolvedLabel = $label ?: __('app.options');
@endphp

<x-ui.dropdown.anchor-dropdown
    {{ $attributes->class('rt-page-actions-dropdown') }}
    align="right"
    :width="$width"
    :offset="8"
>
    <x-slot:trigger>
        <x-ui.dropdown.action-trigger
            :aria-label="$resolvedLabel"
            orientation="vertical"
            class="!h-10 !min-h-10 !w-10 !min-w-10 !px-0"
            data-page-header-control
        />
    </x-slot:trigger>

    <x-slot:content>
        <div class="space-y-0.5 p-1" data-page-actions-menu>
            {{ $slot }}
        </div>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
