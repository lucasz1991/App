@props(['order' => null, 'previewId' => null])
@php
    $location = $order?->location_name ?: 'Einsatzort offen';
    $locationPreview = $order instanceof \App\Models\Order
        ? \App\Support\Operations\TimelineLocationPreview::fromOrder($order)
        : ['state' => 'unknown', 'label' => 'Standort nicht verortet'];
@endphp
<x-operations.service-cell-preview :label="'Ort: '.$location" :preview-id="$previewId" kind="location">
    <x-slot:trigger>
        <span class="rt-table-value">{{ $location }}</span>
    </x-slot:trigger>
    <x-slot:content>
        <section class="rt-service-cell-preview__body rt-service-location-preview" aria-label="Ort der Leistung">
            <h3 class="rt-service-cell-preview__title">{{ $location }}</h3>
            <template x-if="open">
                <div class="rt-service-location-preview__map">
                    <x-operations.timeline-mini-map :preview="$locationPreview" :location="$order?->location_name" />
                </div>
            </template>
        </section>
    </x-slot:content>
</x-operations.service-cell-preview>
