{{-- KI-Eingang als Seite: Kopf, Filterleiste und Tabelle der Eingänge. --}}
<div class="ops-toolbar"><div><h2 class="font-semibold">AI-Annahme</h2><p class="ops-muted">Eingänge, Rückfragen und Planungsvorschläge gemeinsam prüfen.</p></div>@if($ready)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="openCapture"><i class="far fa-plus" aria-hidden="true"></i>Eingang erfassen</x-ui.buttons.button-basic>@endif</div>
<x-operations.feedback />
@if(!$ready)
    <p class="ops-muted">Die AI-Annahme ist noch nicht eingerichtet. Der Superadmin richtet die Datenbankerweiterung und das Dispositionspostfach ein.</p>
@else
    @if(!$enabled)<p class="ops-muted" role="status">Die automatische Verarbeitung ist deaktiviert. Eingänge bleiben zur späteren Auswertung erhalten.</p>@endif
    <x-tables.toolbar title="AI-Eingänge filtern" id="ai-intake-filters" :search-in-header="true" :filter-count="$this->activeFilterCount()" reset-action="resetFilters">
        <x-slot:search><x-ui.forms.search context="page" wire:model.live.debounce.300ms="search" placeholder="AI-Eingang suchen" /></x-slot:search>
        <x-tables.filter-field label="Bearbeitung" for="ai-intake-status"><x-ui.forms.select id="ai-intake-status" wire:model.live="statusFilter"><option value="all">Alle Zustände</option>@foreach($labels as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
    </x-tables.toolbar>
    <x-tables.table :columns="[['label'=>'Eingang','key'=>'title','width'=>'minmax(0,1.7fr)'],['label'=>'Kunde','key'=>'customer','width'=>'minmax(0,1fr)'],['label'=>'Bearbeitung','key'=>'status','width'=>'minmax(0,.8fr)'],['label'=>'Erfasst','key'=>'created','width'=>'minmax(0,.8fr)']]" :items="$intakes" table-key="ai-intake" detail-action="select" row-view="components.tables.rows.inquiries.ai-intake-row" empty="Noch keine AI-Eingänge für diese Auswahl." :flush-top="true" />
    <x-tables.pagination :paginator="$intakes" label="Eingangsseiten" />
@endif
