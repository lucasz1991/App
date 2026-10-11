@php
    $serviceRows = $serviceGroups->getCollection()->map(function ($group) use ($displayTimezone) {
        $order = $group['items']->first()->order;
        return (object) [
            'id' => $order?->id ?? 0,
            'order' => $order,
            'count' => $group['items']->count(),
            'shifts' => $group['items'],
            'timezone' => $displayTimezone,
            'summary' => $group['summary'],
            'staffingUsers' => $group['staffingUsers'],
            'staffingSeats' => $group['staffingSeats'],
            'from' => $group['items']->min('starts_at')?->copy()->setTimezone($displayTimezone),
            'until' => $group['items']->max('ends_at')?->copy()->setTimezone($displayTimezone),
        ];
    })->values();
    $serviceColumns = [
        ['label' => 'Leistung', 'key' => 'service', 'width' => 'minmax(0,1.5fr)', 'sortable' => true],
        ['label' => 'Kunde', 'key' => 'customer', 'width' => 'minmax(0,.9fr)', 'sortable' => true],
        ['label' => 'Zeitraum', 'key' => 'period', 'width' => 'minmax(0,1.1fr)', 'sortable' => true],
        ['label' => 'Ort', 'key' => 'location', 'width' => 'minmax(0,.9fr)'],
        ['label' => 'Schichten', 'key' => 'count', 'width' => 'minmax(0,.6fr)'],
        ['label' => 'Besetzung', 'key' => 'staffing', 'width' => 'minmax(0,1.15fr)'],
        ['label' => 'Status', 'key' => 'status', 'width' => 'minmax(0,.8fr)', 'sortable' => true],
    ];
@endphp
<section aria-label="Leistungsübersicht" data-service-overview>
    <x-tables.table :columns="$serviceColumns" :items="$serviceRows" sort-action="sortServices" :sort-by="$serviceSortBy" :sort-dir="$serviceSortDir" :selected-items="[$selectedServiceId]" selection-action="openServiceDetails" detail-action="openServiceDetails" table-key="service-overview" label="Leistungen im gewählten Zeitraum" :flush-top="true" row-view="components.tables.rows.operations.service-overview" empty="Keine Leistungen für diese Filter im gewählten Zeitraum gefunden." />
    @if($selectedServiceId && $orderGroups->has($selectedServiceId))
        <livewire:admin.operations.orders :initial-order-id="$selectedServiceId" :customer-id="$customerFilter !== 'all' ? (int) $customerFilter : null" :consolidated="true" :detail-only="true" :key="'service-detail-'.$selectedServiceId.'-'.$serviceDetailVersion" />
    @endif
</section>
