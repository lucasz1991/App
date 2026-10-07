@props(['module'])
@php
    $action = match ($module) {
        'inquiries' => ['operations.inquiry-inbox', 'Anfrage erfassen'],
        'orders' => ['admin.operations.orders', 'Neue Leistung'],
        'shift-management' => ['admin.operations.shift-management', 'Neue Schicht'],
        'customers' => ['admin.operations.customers', 'Neuer Kunde'],
        default => null,
    };
    $iconOnly = $module === 'shift-management';
@endphp
@if ($action)
    <x-ui.buttons.button-basic mode="primary" wire:click="$dispatchTo('{{ $action[0] }}', 'operations-create')" :aria-label="$action[1]" :title="$action[1]" :class="$iconOnly ? 'min-h-11 shrink-0 rt-shift-plan-create' : 'min-h-11 shrink-0'" data-operations-create>
        <i class="far fa-plus" aria-hidden="true"></i>
        @unless($iconOnly)<span class="rt-operations-create-label">{{ $action[1] }}</span>@endunless
    </x-ui.buttons.button-basic>
@endif
