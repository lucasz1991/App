@php($ordersFilterCount = (int) ($statusFilter !== 'all') + (int) (trim($search) !== ''))
<x-ui.dropdown.anchor-dropdown width="80" align="right" dropdown-id="orders-filters-{{ $this->getId() }}"
    content-role="dialog" content-label="Aufträge filtern" layer-group="operations-orders"
    content-classes="rt-service-filter-panel p-4 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
    <x-slot:trigger>
        <x-ui.buttons.button-basic mode="secondary" type="button" class="rt-service-filter-trigger" aria-label="Aufträge filtern" title="Aufträge filtern" data-orders-filter-trigger>
            <i class="far fa-sliders-h" aria-hidden="true"></i>
            @if($ordersFilterCount > 0)<span class="rt-service-filter-count" aria-label="{{ $ordersFilterCount }} aktive Filter">{{ $ordersFilterCount }}</span>@endif
        </x-ui.buttons.button-basic>
    </x-slot:trigger>
    <x-slot:content>
        <h2 class="mb-3 text-sm font-semibold">Aufträge filtern</h2>
        <div class="rt-service-filter-fields" data-orders-filter-fields>
            <x-tables.filter-field label="Suche" for="orders-filter-search-{{ $this->getId() }}">
                <x-tables.search-field context="table" wire:model.live.debounce.300ms="search" placeholder="Leistungen suchen" aria-label="Leistungen suchen" id="orders-filter-search-{{ $this->getId() }}" />
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
            @if($ordersFilterCount > 0)<x-ui.buttons.button-basic type="button" size="sm" wire:click="resetFilters" wire:loading.attr="disabled"><i class="far fa-rotate-left" aria-hidden="true"></i>Filter zurücksetzen</x-ui.buttons.button-basic>@endif
        </div>
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
