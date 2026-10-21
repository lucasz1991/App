<section class="ops-stack min-w-0" aria-label="Angebote" x-data="{}">
    @if($headerInPage)
        <template x-teleport="[data-topbar-page-search]" wire:key="offers-topbar-search-{{ $this->getId() }}">
            <x-tables.search-field context="page-topbar" wire:model.live.debounce.300ms="search" :results-count="$offers->count()" placeholder="Angebot oder Kunde suchen" aria-label="Angebote suchen" />
        </template>
        <template x-teleport="[data-page-header-search]" wire:key="offers-header-controls-{{ $this->getId() }}">
            <div class="rt-offers-header-controls">
                <label class="sr-only" for="case-offer-status-{{ $this->getId() }}">Angebotsstatus</label>
                <x-ui.forms.select id="case-offer-status-{{ $this->getId() }}" wire:model.live="status" aria-label="Angebotsstatus">
                    <option value="all" data-icon-class="far fa-layer-group">Alle Stände</option>
                    <option value="draft" data-icon-class="far fa-pen-to-square">Entwürfe</option>
                    <option value="offered" data-icon-class="far fa-paper-plane">Angeboten</option>
                    <option value="accepted" data-icon-class="far fa-check">Zugesagt</option>
                </x-ui.forms.select>
            </div>
        </template>
    @else
    <x-tables.toolbar id="commercial-offer-index" title="Angebote" :search-in-header="true">
        <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Angebot oder Kunde suchen" /></x-slot:search>
        <x-tables.filter-field label="Status" for="case-offer-status"><x-ui.forms.select id="case-offer-status" wire:model.live="status"><option value="all">Alle Stände</option><option value="draft">Entwürfe</option><option value="offered">Angeboten</option><option value="accepted">Zugesagt</option></x-ui.forms.select></x-tables.filter-field>
    </x-tables.toolbar>
    @endif
    <x-tables.table :columns="[['label'=>'Vorgang / Kunde','key'=>'case'],['label'=>'Angebotsstand','key'=>'revision'],['label'=>'Betrag netto','key'=>'total'],['label'=>'Status','key'=>'status']]" :items="$offers" :flush-top="$headerInPage" detail-action="select" row-view="components.tables.rows.operations.case-offer" table-key="case-offers" empty="Keine Angebote für diese Auswahl." />
    {{ $offers->links() }}
    @if($selectedId)
        <livewire:operations.commercial-offers :subject-type="$selectedSubjectType" :subject-id="$selectedSubjectId" :initial-offer-id="$selectedId" :show-list="false" :key="'case-offer-detail-'.$selectedId.'-'.$detailGeneration" />
    @endif
</section>
