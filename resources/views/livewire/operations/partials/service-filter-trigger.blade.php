<x-tables.filter-dropdown
    dropdown-id="service-overview-filters-{{ $this->getId() }}"
    label="Leistungen filtern"
    layer-group="operations-shift-plan"
    :count="(int) ($orderFilter !== 'all') + (int) ($statusFilter !== 'all') + (int) ($attentionFilter !== 'all')"
    class="rt-service-filter-trigger"
    data-service-filter-trigger
>
    @if($contextCustomer)
        <x-slot:context><p class="mb-3 text-xs text-rt-muted dark:text-rt-dark-muted" data-shift-customer-context>Kunde: {{ $contextCustomer->company_name }}</p></x-slot:context>
    @endif
    <div class="rt-service-filter-fields" data-service-filter-fields>
        @include('livewire.operations.partials.shift-filter-fields')
    </div>
</x-tables.filter-dropdown>
