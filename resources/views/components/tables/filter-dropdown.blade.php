@props([
    'dropdownId',
    'label',
    'count' => 0,
    'resetAction' => null,
    'resetLabel' => null,
    'layerGroup' => null,
    'align' => 'right',
    'width' => '80',
    'panelClass' => '',
])

@php
    $count = max(0, (int) $count);
    // Nur einfache Livewire-Methodennamen zulassen, wie in x-tables.toolbar.
    $safeResetAction = is_string($resetAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $resetAction)
        ? $resetAction
        : null;
    $resolvedResetLabel = filled($resetLabel) ? (string) $resetLabel : __('app.reset_filters');
    $hasContext = isset($context) && trim((string) $context) !== '';
@endphp

{{--
    Kompakter Filter-Trigger fuer Listen, deren Filter in einem Dropdown im
    Seitenkopf liegen (Mitarbeiterverzeichnis, Auftraege, Leistungen).
    Trigger, Zaehler, Titel und Zuruecksetzen sind hier einheitlich; die
    Felder kommen als Slot. Zusatzattribute (Klassen, data-*) landen am Trigger.
--}}
<x-ui.dropdown.anchor-dropdown
    :width="$width"
    :align="$align"
    :dropdown-id="$dropdownId"
    content-role="dialog"
    :content-label="$label"
    :layer-group="$layerGroup"
    content-classes="rt-filter-dropdown__panel p-4 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text {{ $panelClass }}"
>
    <x-slot:trigger>
        <x-ui.buttons.button-basic
            mode="secondary"
            type="button"
            :aria-label="$label"
            :title="$label"
            {{ $attributes->class(['rt-filter-dropdown__trigger']) }}
            data-tables-filter-dropdown-trigger
        >
            <i class="far fa-sliders-h" aria-hidden="true"></i>
            @if($count > 0)<span class="rt-filter-dropdown__count" aria-label="{{ $count }} aktive Filter" data-tables-filter-count>{{ $count }}</span>@endif
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <div class="rt-filter-dropdown__head">
            <h2 class="text-sm font-semibold">{{ $label }}</h2>
            @if($safeResetAction && $count > 0)
                <x-ui.buttons.button-basic mode="link" type="button" wire:click="{{ $safeResetAction }}" wire:loading.attr="disabled" data-tables-filter-reset>{{ $resolvedResetLabel }}</x-ui.buttons.button-basic>
            @endif
        </div>
        @if($hasContext)
            {{ $context }}
        @endif
        {{ $slot }}
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
