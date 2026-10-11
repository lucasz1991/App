@props(['options' => [], 'value' => null, 'action' => null, 'label' => 'Bereichsmenü', 'id' => null, 'navigate' => false, 'disabled' => false])

{{-- One native navigation instance: topbar on tablet/desktop, bottom dock on phones. --}}
<x-ui.buttons.multi-toggle
    :options="$options" :value="$value" :action="$action" :label="$label" :id="$id"
    :navigate="$navigate" :disabled="$disabled" :show-labels="true"
    {{ $attributes->class(['rt-area-menu'])->merge(['data-area-menu' => '']) }}
>
    <span class="rt-area-menu__indicator" aria-hidden="true" x-data="rtAreaMenu" wire:ignore></span>
</x-ui.buttons.multi-toggle>
