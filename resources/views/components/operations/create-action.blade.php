@props(['module'])
@php
    $action = match ($module) {
        'inquiries' => ['operations.inquiry-inbox', 'Anfrage erfassen'],
        'orders' => ['admin.operations.orders', 'Neue Leistung'],
        'shift-management' => ['admin.operations.shift-management', 'Neue Schicht'],
        'customers' => ['admin.operations.customers', 'Neuer Kunde'],
        default => null,
    };
@endphp
@if ($action)
    <x-ui.buttons.button-basic mode="primary" wire:click="$dispatchTo('{{ $action[0] }}', 'operations-create')" :aria-label="$action[1]" :title="$action[1]" class="min-h-11 shrink-0" data-operations-create>
        <i class="far fa-plus" aria-hidden="true"></i>
        <span class="hidden sm:inline">{{ $action[1] }}</span>
    </x-ui.buttons.button-basic>
@endif
