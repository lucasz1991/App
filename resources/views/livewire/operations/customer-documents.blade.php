<div class="rt-ops ops-stack min-w-0" data-customer-documents>
    <x-tables.toolbar id="customer-documents-filters" :single-line="true" :filter-count="$activeFilterCount" title="Dokumente filtern" reset-action="resetFilters" search-for="customer-documents-search">
        <x-slot:search><x-tables.search-field id="customer-documents-search" :results-count="$items->total()" placeholder="Dokumente suchen" maxlength="100" wire:model.live.debounce.300ms="search" /></x-slot:search>
        <x-tables.filter-field label="Status" for="customer-documents-status" icon="far fa-filter">
            <x-ui.forms.select id="customer-documents-status" wire:model.live="status" aria-label="Dokumentenstatus">
                <option value="all">Alle Status</option><option value="quarantined">Prüfung offen</option><option value="published">Freigegeben</option><option value="withdrawn">Zurückgezogen</option><option value="reviewed">Eingang geprüft</option>
            </x-ui.forms.select>
        </x-tables.filter-field>
        <x-tables.filter-field label="Art" for="customer-documents-kind" icon="far fa-file-alt">
            <x-ui.forms.select id="customer-documents-kind" wire:model.live="kind" aria-label="Dokumentenart">
                <option value="all">Alle Dokumente</option><option value="document">Kundenunterlagen</option><option value="invoice">Rechnungsdokumente</option><option value="attachment">Eingangsanlagen</option>
            </x-ui.forms.select>
        </x-tables.filter-field>
    </x-tables.toolbar>
    <x-tables.table label="Kundendokumente" :items="$items" :columns="[
        ['label'=>'Dokument','key'=>'title','width'=>'2fr'],
        ['label'=>'Status','key'=>'status','width'=>'1fr'],
        ['label'=>'Stand','key'=>'revision','width'=>'0.5fr','hideOn'=>'md'],
        ['label'=>'Datei','key'=>'file','width'=>'1fr','hideOn'=>'md'],
        ['label'=>'Eingang','key'=>'date','width'=>'1fr','hideOn'=>'md'],
    ]" :flush-top="true" :table-key="'customer-documents-'.$customerId" row-view="components.tables.rows.customers.document-row" actions-view="components.tables.rows.customers.document-actions" empty="Keine Dokumente vorhanden." />
    <div class="py-3">{{ $items->links() }}</div>
</div>
