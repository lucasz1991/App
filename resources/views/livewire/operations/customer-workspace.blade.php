<div class="rt-ops ops-stack customer-workspace" data-customer-workspace @if(!$customerId) data-customer-directory-scroll @endif>
    <x-ui.page title="Kunden" :auto-intro="false" :show-back="false" :title-in-topbar="true">
        <x-slot:actions>
            @if(!$customerId)
                <div class="customer-directory__toolbar" wire:key="customer-directory-toolbar">
                    <div class="customer-directory__search-controls" aria-label="Kunden suchen und auswählen">
                        @if($selectedListCustomerId)
                            <x-ui.dropdown.anchor-dropdown dropdown-id="customer-list-selection" width="64" layer-group="topbar-controls">
                                <x-slot:trigger><x-ui.buttons.button-basic type="button" mode="secondary" aria-label="Aktionen für ausgewählten Kunden" title="Ausgewählter Kunde"><i class="far fa-layer-group" aria-hidden="true"></i></x-ui.buttons.button-basic></x-slot:trigger>
                                <x-slot:content>
                                    <x-dropdown-link wire:click.prevent="selectCustomer({{ $selectedListCustomerId }})"><i class="far fa-id-card" aria-hidden="true"></i>Kundenprofil öffnen</x-dropdown-link>
                                    <x-dropdown-link wire:click.prevent="toggleCustomerSelection({{ $selectedListCustomerId }})"><i class="far fa-align-slash" aria-hidden="true"></i>Auswahl aufheben</x-dropdown-link>
                                </x-slot:content>
                            </x-ui.dropdown.anchor-dropdown>
                        @endif
                        <x-tables.search-field context="page-topbar" id="customer-list-search" :results-count="$customers->total()" placeholder="Kunden suchen" maxlength="100" wire:model.live.debounce.300ms="search" />
                    </div>
                    <span class="customer-directory__total" aria-live="polite">{{ number_format($customers->total(), 0, ',', '.') }} {{ $customers->total()===1?'Kunde':'Kunden' }}</span>
                    <div class="customer-directory__actions">
                        @if($canCreate)
                            <x-ui.buttons.button-basic type="button" mode="primary" class="customer-directory__create" wire:click="createCustomer" aria-label="Kunde anlegen" title="Kunde anlegen"><i class="far fa-plus" aria-hidden="true"></i></x-ui.buttons.button-basic>
                        @endif
                        @include('livewire.operations.partials.customer-directory-filters')
                    </div>
                </div>
            @endif
        </x-slot:actions>
    @if(!$customerId)
        <div class="customer-directory__results">
            <x-tables.table
                class="rt-customer-table"
                label="Kundenliste"
                table-key="customer-list"
                :flush-top="true"
                :scroll-body="true"
                :mobile-header="true"
                :columns="$canCreate ? [
                    ['label'=>'Kunde','key'=>'company_name','width'=>'34%','sortable'=>true],
                    ['label'=>'Ansprechpartner / Kontakt','key'=>'contact','width'=>'32%','hideOn'=>'md'],
                    ['label'=>'Ort','key'=>'city','width'=>'20%','sortable'=>true,'hideOn'=>'md'],
                    ['label'=>'Status','key'=>'is_active','width'=>'14%','sortable'=>true],
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
            <div class="customer-directory__pagination"><x-tables.pagination :paginator="$customers" table-key="customer-list" /></div>
        </div>
    @else
        @include('livewire.operations.partials.customer-profile')
    @endif
    </x-ui.page>
    @if($canCreate && ($startCreating || $editingCustomerId))
        <livewire:admin.operations.customers :customer-id="$editingCustomerId" :embedded="true" :modal-only="true" :workspace-revision="$contextRevision" :start-creating="$startCreating" :start-editing="(bool)$editingCustomerId" :key="'customer-form-'.($editingCustomerId ?? 'new').'-'.$contextRevision" />
    @endif
</div>
