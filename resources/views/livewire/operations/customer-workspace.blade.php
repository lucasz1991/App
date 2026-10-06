<div class="rt-ops ops-stack" data-customer-workspace>
    @if(!$customerId)
        <x-operations.surface>
            <x-slot:actions>
                <span class="mr-auto text-sm text-rt-muted dark:text-rt-dark-muted" aria-live="polite">{{ number_format($customers->total(), 0, ',', '.') }} {{ $customers->total()===1?'Kunde':'Kunden' }}</span>
                @if($canCreate)
                    <x-ui.buttons.button-basic type="button" mode="primary" wire:click="createCustomer"><i class="far fa-plus" aria-hidden="true"></i>Kunde anlegen</x-ui.buttons.button-basic>
                @endif
            </x-slot:actions>
            <x-tables.toolbar id="customer-list-filters" :single-line="true" :filter-count="$activeFilterCount" title="Kunden filtern" reset-action="resetFilters" search-for="customer-list-search">
                <x-slot:search>
                    <x-tables.search-field id="customer-list-search" :results-count="$customers->total()" placeholder="Kunden suchen" maxlength="100" wire:model.live.debounce.300ms="search" />
                </x-slot:search>
                @if($canCreate)
                    <x-tables.filter-field label="Status" icon="far fa-signal-alt-3" for="customer-status-filter">
                        <x-ui.forms.select id="customer-status-filter" wire:model.live="activeFilter" aria-label="Kundenstatus" class="w-full">
                            <option value="all">Alle Kunden</option>
                            <option value="active">Aktiv</option>
                            <option value="inactive">Inaktiv</option>
                        </x-ui.forms.select>
                    </x-tables.filter-field>
                @endif
                <x-tables.filter-field label="Anzeige" icon="far fa-list-ol" for="customer-page-size">
                    <x-ui.forms.select id="customer-page-size" wire:model.live="perPage" aria-label="Kunden pro Seite" class="w-full">
                        @foreach([15,30,50,100] as $size)<option value="{{ $size }}">{{ $size }} pro Seite</option>@endforeach
                    </x-ui.forms.select>
                </x-tables.filter-field>
                <x-slot:chips>
                    @if(trim($search)!=='')<x-tables.filter-chip label="Suche" :value="$search" wire:click="$set('search', '')" />@endif
                    @if($canCreate && $activeFilter!=='all')<x-tables.filter-chip label="Status" :value="$activeFilter==='active'?'Aktiv':'Inaktiv'" wire:click="$set('activeFilter', 'all')" />@endif
                </x-slot:chips>
            </x-tables.toolbar>
            <x-tables.table
                label="Kundenliste"
                table-key="customer-list"
                :flush-top="true"
                :columns="$canCreate ? [
                    ['label'=>'Kunde','key'=>'company_name','width'=>'36%','sortable'=>true],
                    ['label'=>'Kontakt','key'=>'contact','width'=>'28%','hideOn'=>'md'],
                    ['label'=>'Ort','key'=>'city','width'=>'20%','sortable'=>true,'hideOn'=>'md'],
                    ['label'=>'Status','key'=>'is_active','width'=>'16%','sortable'=>true],
                ] : [['label'=>'Kunde','key'=>'company_name','width'=>'1fr','sortable'=>true]]"
                :items="$customers"
                :selected-items="$selectedListCustomerId ? [$selectedListCustomerId] : []"
                selection-action="toggleCustomerSelection"
                detail-action="selectCustomer"
                row-view="components.tables.rows.customers.customer-row"
                actions-view="components.tables.rows.customers.customer-actions"
                :sort-by="$sortBy"
                :sort-dir="$sortDir"
                sort-action="sort"
                empty="Keine Kunden gefunden."
            />
            <div class="py-4">{{ $customers->links() }}</div>
        </x-operations.surface>
    @else
        @include('livewire.operations.partials.customer-profile')
    @endif
    @if($canCreate && ($startCreating || $editingCustomerId))
        <livewire:admin.operations.customers :customer-id="$editingCustomerId" :embedded="true" :modal-only="true" :workspace-revision="$contextRevision" :start-creating="$startCreating" :start-editing="(bool)$editingCustomerId" :key="'customer-form-'.($editingCustomerId ?? 'new').'-'.$contextRevision" />
    @endif
</div>
