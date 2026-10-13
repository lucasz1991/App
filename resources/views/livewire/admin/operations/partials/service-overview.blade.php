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
    // Keep the native page owner when adapting grouped services to table rows.
    // A plain collection would opt out of the shared scroll-pagination controller.
    $serviceItems = (clone $serviceGroups)->setCollection($serviceRows);
@endphp
<section aria-label="Leistungsübersicht" data-service-overview data-service-detail-controller data-service-detail-panel-owner="{{ $this->getId() }}" x-data="rtServiceDetailPanel">
    <x-operations.service-table :items="$serviceItems" sort-action="sortServices" :sort-by="$serviceSortBy" :sort-dir="$serviceSortDir" :selected-items="[$selectedServiceId]" :bulk-selected-items="$selectedServiceRows" bulk-selection-action="toggleServiceSelection" selection-action="openServiceDetails" detail-action="openServiceDetails" table-key="service-overview" data-table-pagination-key="service-overview" label="Leistungen im gewählten Zeitraum" empty="Keine Leistungen für diese Filter im gewählten Zeitraum gefunden." />
    <x-operations.panel :id="'service-detail-panel-'.$this->getId()" label="Leistungsdetails" show-expression="panelVisible">
        <x-operations.service-detail-shell :owner-id="$this->getId()">
                @if($selectedServiceId && $orderGroups->has($selectedServiceId))
                    <livewire:admin.operations.orders :initial-order-id="$selectedServiceId" :customer-id="$customerFilter !== 'all' ? (int) $customerFilter : null" :consolidated="true" :detail-only="true" :embedded-detail="true" :key="'service-detail-'.$selectedServiceId.'-'.$serviceDetailVersion" />
                @endif
        </x-operations.service-detail-shell>
    </x-operations.panel>
</section>
