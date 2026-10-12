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
