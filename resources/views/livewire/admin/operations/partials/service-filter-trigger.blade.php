@php($serviceFilterCount = (int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all'))
<x-ui.dropdown.anchor-dropdown width="80" align="right" dropdown-id="service-overview-filters-{{ $this->getId() }}"
    content-role="dialog" content-label="Leistungen filtern" layer-group="operations-shift-plan"
    content-classes="rt-service-filter-panel p-4 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
    <x-slot:trigger>
        <x-ui.buttons.button-basic mode="secondary" type="button" class="rt-service-filter-trigger" aria-label="Leistungen filtern" title="Leistungen filtern" data-service-filter-trigger>
            <i class="far fa-sliders-h" aria-hidden="true"></i>
            @if($serviceFilterCount > 0)<span class="rt-service-filter-count" aria-label="{{ $serviceFilterCount }} aktive Filter">{{ $serviceFilterCount }}</span>@endif
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <h2 class="mb-3 text-sm font-semibold">Leistungen filtern</h2>
        @if($contextCustomer)<p class="mb-3 text-xs text-rt-muted dark:text-rt-dark-muted" data-shift-customer-context>Kunde: {{ $contextCustomer->company_name }}</p>@endif
        <div class="rt-service-filter-fields" data-service-filter-fields>
            @include('livewire.admin.operations.partials.shift-filter-fields')
        </div>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
