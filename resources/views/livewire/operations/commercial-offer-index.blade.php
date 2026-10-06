<section class="ops-stack min-w-0" aria-label="Angebote">
    <x-tables.toolbar id="commercial-offer-index" title="Angebote" :search-in-header="true">
        <x-slot:search><x-tables.search-field context="page" wire:model.live.debounce.300ms="search" placeholder="Angebot oder Kunde suchen" /></x-slot:search>
        <x-tables.filter-field label="Status" for="case-offer-status"><x-ui.forms.select id="case-offer-status" wire:model.live="status"><option value="all">Alle Stände</option><option value="draft">Entwürfe</option><option value="offered">Angeboten</option><option value="accepted">Zugesagt</option></x-ui.forms.select></x-tables.filter-field>
    </x-tables.toolbar>
    <x-tables.table :columns="[['label'=>'Vorgang / Kunde','key'=>'case'],['label'=>'Angebotsstand','key'=>'revision'],['label'=>'Betrag netto','key'=>'total'],['label'=>'Status','key'=>'status']]" :items="$offers" detail-action="select" row-view="components.tables.rows.operations.case-offer" table-key="case-offers" empty="Keine Angebote für diese Auswahl." />
    {{ $offers->links() }}
    @if($selectedId)
        <livewire:operations.commercial-offers :subject-type="$selectedSubjectType" :subject-id="$selectedSubjectId" :initial-offer-id="$selectedId" :show-list="false" :key="'case-offer-detail-'.$selectedId.'-'.$detailGeneration" />
    @endif
</section>
