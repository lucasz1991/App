@props([
    'items',
    'sortAction',
    'sortBy' => 'period',
    'sortDir' => 'asc',
    'selectedItems' => [],
    'bulkSelectedItems' => [],
    'bulkSelectionAction' => null,
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
    $hasSelection = is_string($bulkSelectionAction) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $bulkSelectionAction);
    $columns = [
        ['label' => 'Leistung', 'key' => 'service', 'width' => 'minmax(0,1.5fr)', 'sortable' => true],
        ['label' => 'Kunde', 'key' => 'customer', 'width' => 'minmax(0,.9fr)', 'sortable' => true],
        ['label' => 'Zeitraum', 'key' => 'period', 'width' => 'minmax(0,1.1fr)', 'sortable' => true],
        ['label' => 'Ort', 'key' => 'location', 'width' => 'minmax(0,.9fr)'],
        ['label' => 'Schichten', 'key' => 'count', 'width' => 'minmax(0,.6fr)'],
        ['label' => 'Besetzung', 'key' => 'staffing', 'width' => 'minmax(0,1.15fr)'],
        ['label' => 'Status', 'key' => 'status', 'width' => 'minmax(0,.8fr)', 'sortable' => true],
    ];
    if ($hasSelection) {
        array_unshift($columns, ['label' => 'Auswahl', 'key' => 'selection', 'width' => '44px']);
        // Only the synthetic presentation row gets checkbox metadata, never an Order/Shift.
        $selectionRows = collect($items->items())->map(function ($item) use ($bulkSelectedItems, $bulkSelectionAction) {
            $row = clone $item;
            $row->bulkSelected = in_array((int) $row->id, $bulkSelectedItems, true);
            $row->bulkSelectionAction = $bulkSelectionAction;
            return $row;
        });
        $items = (clone $items)->setCollection($selectionRows);
        $selectedItems = array_values(array_unique([...$selectedItems, ...$bulkSelectedItems]));
        $pageSelectionIds = $selectionRows->pluck('id')->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->all();
        $pageSelectedCount = count(array_intersect($pageSelectionIds, $bulkSelectedItems));
        $pageSelectionState = $pageSelectedCount === 0 ? 'false' : ($pageSelectedCount === count($pageSelectionIds) ? 'true' : 'mixed');
    }
@endphp
<x-tables.table :columns="$columns" :items="$items" :sort-action="$sortAction" :sort-by="$sortBy" :sort-dir="$sortDir"
    :selected-items="$selectedItems" :selection-action="$selectionAction" :detail-action="$detailAction" :table-key="$tableKey"
    :label="$label" :scroll-body="$scrollBody" :flush-top="true" :empty="$empty" :detail-on-click="true"
    row-view="components.tables.rows.operations.service-overview" class="rt-service-table {{ $hasSelection ? 'rt-service-table--selectable' : '' }}" {{ $attributes }}>
    @if($hasSelection)
        <x-slot:selectionHeader>
            <x-tables.selection-toggle action="toggleSelectAllServices"
                label="Leistungen dieser Seite auswählen oder Auswahl aufheben"
                :state="$pageSelectionState" :disabled="$pageSelectionIds === []" />
        </x-slot:selectionHeader>
    @endif
</x-tables.table>
