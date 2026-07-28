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
            :label="$resolvedLabel"
            :aria-label="$resolvedLabel"
            orientation="vertical"
            responsive-label
            class="!h-8 !min-h-8 !w-8 !min-w-8 !px-0 sm:!w-auto sm:!px-2.5"
        />
    </x-slot:trigger>

    <x-slot:content>
        <div class="space-y-0.5 p-1" data-page-actions-menu>
            {{ $slot }}
        </div>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
