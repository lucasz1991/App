{{-- Addressed by OperationsPages keys: page, view and (for cases/shifts) section. --}}
@props(['page', 'view' => '', 'section' => ''])
@php
    // The shift plan is reachable as page "shifts" and as cases/shifts/<section>.
    if ($page === 'cases' && $view === 'shifts') {
        [$page, $view] = ['shifts', $section ?: 'plan'];
    }
    $action = match (true) {
        $page === 'cases' && $view === 'inbox' => ['operations.inquiry-inbox', 'Anfrage erfassen'],
        $page === 'cases' && $view === 'orders' => ['operations.orders', 'Neue Leistung'],
        $page === 'shifts' && in_array($view, ['', 'plan'], true) => ['operations.shift-management', 'Neue Schicht'],
        $page === 'customers' && in_array($view, ['', 'master'], true) => ['operations.customers', 'Neuer Kunde'],
        default => null,
    };
    $iconOnly = $page === 'shifts';
@endphp
@if ($action)
    <x-ui.buttons.button-basic mode="primary" wire:click="$dispatchTo('{{ $action[0] }}', 'operations-create')" :aria-label="$action[1]" :title="$action[1]" :class="$iconOnly ? 'min-h-11 shrink-0 rt-shift-plan-create' : 'min-h-11 shrink-0'" data-operations-create>
        <i class="far fa-plus" aria-hidden="true"></i>
        @unless($iconOnly)<span class="rt-operations-create-label">{{ $action[1] }}</span>@endunless
    </x-ui.buttons.button-basic>
@endif
