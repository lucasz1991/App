@props(['ownerId'])
<div class="rt-service-detail-host" wire:ignore.self data-service-detail-panel-owner="{{ $ownerId }}" x-bind:aria-busy="loading">
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
    <div x-show="!loading && !error" x-cloak class="rt-service-detail-ready" data-service-detail-ready>{{ $slot }}</div>
</div>
