@props([
    'items',
    'sortAction',
    'sortBy' => 'period',
    'sortDir' => 'asc',
    'selectedItems' => [],
    'selectionAction',
    'detailAction',
    'tableKey',
    'label' => 'Leistungen',
    'scrollBody' => true,
    'empty' => 'Keine Leistungen für diese Auswahl gefunden.',
])
@php
    // One presentation contract; query, pagination and business actions stay
    // with the original Orders/ShiftManagement Livewire owner.
    $columns = [
        ['label' => 'Leistung', 'key' => 'service', 'width' => 'minmax(0,1.5fr)', 'sortable' => true],
        ['label' => 'Kunde', 'key' => 'customer', 'width' => 'minmax(0,.9fr)', 'sortable' => true],
        ['label' => 'Zeitraum', 'key' => 'period', 'width' => 'minmax(0,1.1fr)', 'sortable' => true],
        ['label' => 'Ort', 'key' => 'location', 'width' => 'minmax(0,.9fr)'],
        ['label' => 'Schichten', 'key' => 'count', 'width' => 'minmax(0,.6fr)'],
        ['label' => 'Besetzung', 'key' => 'staffing', 'width' => 'minmax(0,1.15fr)'],
        ['label' => 'Status', 'key' => 'status', 'width' => 'minmax(0,.8fr)', 'sortable' => true],
    ];
@endphp
<x-tables.table :columns="$columns" :items="$items" :sort-action="$sortAction" :sort-by="$sortBy" :sort-dir="$sortDir"
    :selected-items="$selectedItems" :selection-action="$selectionAction" :detail-action="$detailAction" :table-key="$tableKey"
    :label="$label" :scroll-body="$scrollBody" :flush-top="true" :empty="$empty" :detail-on-click="true"
    row-view="components.tables.rows.operations.service-overview" class="rt-service-table" {{ $attributes }} />
