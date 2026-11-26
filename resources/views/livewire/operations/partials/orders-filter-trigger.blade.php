<x-tables.filter-dropdown
    dropdown-id="orders-filters-{{ $this->getId() }}"
    label="Aufträge filtern"
    layer-group="operations-orders"
    :count="(int) ($statusFilter !== 'all') + (int) (trim($search) !== '')"
    reset-action="resetFilters"
    class="rt-service-filter-trigger"
    data-orders-filter-trigger
>
    <div class="rt-service-filter-fields" data-orders-filter-fields>
        <x-tables.filter-field label="Suche" for="orders-filter-search-{{ $this->getId() }}">
            <x-ui.forms.search context="table" wire:model.live.debounce.300ms="search" placeholder="Leistungen suchen" aria-label="Leistungen suchen" id="orders-filter-search-{{ $this->getId() }}" />
        </x-tables.filter-field>
        <x-tables.filter-field label="Leistungsstatus" for="order-status-filter-{{ $this->getId() }}">
            <x-ui.forms.select id="order-status-filter-{{ $this->getId() }}" wire:model.live="statusFilter" aria-label="Leistungsstatus">
                <option value="all" data-icon-class="{{ $statusFilterIcons['all'] }}">Alle Leistungen</option>
                @foreach($statusOptions as $option)<option value="{{ $option['value'] }}" data-icon-class="{{ $statusFilterIcons[$option['value']] ?? 'far fa-circle text-rt-muted dark:text-rt-dark-muted' }}">{{ $option['label'] }}</option>@endforeach
            </x-ui.forms.select>
        </x-tables.filter-field>
        <x-tables.filter-field label="Anzeigeanzahl" for="orders-per-page-{{ $this->getId() }}">
            <x-ui.forms.select id="orders-per-page-{{ $this->getId() }}" wire:model.live="perPage" aria-label="Leistungen pro Seite">
                @foreach([25, 50, 100] as $size)<option value="{{ $size }}" data-icon-class="far fa-list">{{ $size }} pro Seite</option>@endforeach
            </x-ui.forms.select>
        </x-tables.filter-field>
    </div>
</x-tables.filter-dropdown>
