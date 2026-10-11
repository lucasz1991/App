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
<section aria-label="Leistungsübersicht" data-service-overview data-service-detail-controller data-service-detail-panel-owner="{{ $this->getId() }}" x-data="rtServiceDetailPanel">
    <x-tables.table :columns="$serviceColumns" :items="$serviceItems" sort-action="sortServices" :sort-by="$serviceSortBy" :sort-dir="$serviceSortDir" :selected-items="[$selectedServiceId]" selection-action="openServiceDetails" detail-action="openServiceDetails" table-key="service-overview" data-table-pagination-key="service-overview" label="Leistungen im gewählten Zeitraum" :flush-top="true" :scroll-body="true" row-view="components.tables.rows.operations.service-overview" empty="Keine Leistungen für diese Filter im gewählten Zeitraum gefunden." />
    <x-operations.panel :id="'service-detail-panel-'.$this->getId()" label="Leistungsdetails" show-expression="panelVisible">
        <div class="rt-service-detail-host" data-service-detail-panel-owner="{{ $this->getId() }}" x-bind:aria-busy="loading">
            <div x-show="loading || error" x-cloak class="rt-service-detail-state">
                <div class="rt-ops-panel__accent" aria-hidden="true"></div>
                <x-operations.panel.header eyebrow="Leistung" title="Leistungsdetails" icon="fa-briefcase" close-label="Leistungsdetails schließen" />
                <div class="rt-ops-panel__body rt-modal-content">
                    <div x-show="loading" data-service-detail-loading class="rt-service-detail-loader" role="status" aria-live="polite">
                        <span class="rt-service-detail-loader__indicator" aria-hidden="true"><i class="far fa-spinner-third fa-spin"></i></span>
                        <strong>Leistungsdetails werden geladen …</strong>
                        <span>Besetzung, Schichten und Verlauf werden vorbereitet.</span>
                        <div class="rt-service-detail-skeleton" aria-hidden="true"><x-ui.loading.skeleton variant="list" :rows="3" /></div>
                    </div>
                    <div x-show="!loading && error" data-service-detail-error class="rt-service-detail-loader" role="alert">
                        <i class="far fa-circle-exclamation" aria-hidden="true"></i><p x-text="error"></p>
                        <x-ui.buttons.button-basic type="button" size="sm" x-on:click="retryServiceDetail()"><i class="far fa-arrow-rotate-right" aria-hidden="true"></i>Erneut versuchen</x-ui.buttons.button-basic>
                    </div>
                </div>
            </div>
            <div x-show="!loading && !error" x-cloak class="rt-service-detail-ready" data-service-detail-ready>
                @if($selectedServiceId && $orderGroups->has($selectedServiceId))
                    <livewire:admin.operations.orders :initial-order-id="$selectedServiceId" :customer-id="$customerFilter !== 'all' ? (int) $customerFilter : null" :consolidated="true" :detail-only="true" :embedded-detail="true" :key="'service-detail-'.$selectedServiceId.'-'.$serviceDetailVersion" />
                @endif
            </div>
        </div>
    </x-operations.panel>
</section>
