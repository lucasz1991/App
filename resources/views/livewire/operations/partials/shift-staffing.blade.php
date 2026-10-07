<div class="rt-staffing" data-shift-staffing x-data="{ filtersOpen: false }">
    <section class="rt-staffing-section" aria-label="Aktuelle Besetzung">
        <div class="rt-staffing-heading">
            <div><h3>Besetzung & Rückmeldungen</h3><p>{{ $selectedReservedCount }} von {{ $selectedShift->required_staff }} Plätzen reserviert · {{ max(0, $selectedShift->required_staff - $selectedReservedCount) }} offen</p></div>
            <span class="rt-staffing-count">{{ $feedback->count() }}</span>
        </div>
        @if($feedback->isNotEmpty())
            <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name','width'=>'minmax(0,1.2fr)'],['label'=>'Rückmeldung & Zustellung','key'=>'response','width'=>'minmax(0,1.3fr)'],['label'=>'','key'=>'action','width'=>'44px']]" :items="$feedback" row-view="components.tables.rows.operations.staffing-feedback" label="Aktuelle Besetzung und Rückmeldungen" class="rt-staffing-table" />
        @else
            <p class="rt-staffing-note">Noch niemand zugewiesen. Wählen Sie unten passende Mitarbeitende aus.</p>
        @endif
        @if($detailOpen && \App\Support\Operations\WorkforcePlanningSchema::ready())
            <details class="rt-staffing-more"><summary>Planungsvarianten</summary><livewire:operations.plan-variants :shift-ids="[$selectedShift->id]" :key="'variants-'.$selectedShift->id" /></details>
        @endif
    </section>

    @if($regionalEmployee)
        @include('livewire.operations.partials.staffing-regions')
    @endif

    @if($selectedCandidate)
        @include('livewire.operations.partials.staffing-assignment-review')
    @endif
    @error('assignment')<p class="rt-staffing-alert" role="alert">{{ $message }}</p>@enderror

    @if($shiftClosed)
        <p class="rt-staffing-note">Diese Schicht ist abgeschlossen oder storniert. Neue Zuweisungen sind nicht möglich.</p>
    @else
        <section class="rt-staffing-section" aria-label="Mitarbeitende finden" data-staffing-candidates>
            <div class="rt-staffing-heading">
                <div><h3>Mitarbeitende finden</h3><p>Beste Planungseignung zuerst · alle Treffer werden vorab sortiert</p></div>
                @if($candidatesReady)<span class="rt-staffing-count">{{ $candidateTotal }}</span>@endif
            </div>
            @if(!$candidatesReady)
                <div x-data x-intersect.once="$wire.loadStaffingCandidates()" class="rt-staffing-loading" role="status">
                    <i class="far fa-spinner-third fa-spin" aria-hidden="true"></i><span>Eignung wird geprüft …</span>
                    <button type="button" wire:click="loadStaffingCandidates" wire:loading.attr="disabled">Liste laden</button>
                </div>
            @else
                <div class="rt-staffing-search">
                    <x-tables.search-field wire:model.live.debounce.350ms="candidateSearch" placeholder="Name suchen" aria-label="Mitarbeitende nach Namen suchen" />
                    <x-ui.buttons.button-basic type="button" x-on:click="filtersOpen = !filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="staffing-filters-{{ $selectedShift->id }}"><i class="far fa-sliders-h" aria-hidden="true"></i> Filter @if($candidateQualification || $candidatePool || $candidateSuitability !== 'all' || $candidateRegion !== 'all')<span class="rt-staffing-filter-dot" aria-label="Filter aktiv"></span>@endif</x-ui.buttons.button-basic>
                </div>
                <div class="rt-staffing-filters" x-show="filtersOpen" x-cloak id="staffing-filters-{{ $selectedShift->id }}">
                    <x-tables.filter-field label="Eignung" for="staffing-suitability"><x-ui.forms.select id="staffing-suitability" wire:model.live="candidateSuitability"><option value="all">Alle Eignungen</option><option value="eligible">Geeignet</option><option value="review">Zeitkonflikt prüfen</option><option value="blocked">Nicht zuweisbar</option></x-ui.forms.select></x-tables.filter-field>
                    <x-tables.filter-field label="Qualifikation" for="staffing-qualification"><x-ui.forms.select id="staffing-qualification" wire:model.live="candidateQualification"><option value="">Alle Qualifikationen</option>@foreach($candidateFilterOptions['qualifications'] as $qualification)<option value="{{ $qualification['id'] }}">{{ $qualification['name'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
                    <x-tables.filter-field label="Personalpool" for="staffing-pool"><x-ui.forms.select id="staffing-pool" wire:model.live="candidatePool"><option value="">Alle Personalpools</option>@foreach($candidateFilterOptions['pools'] as $pool)<option value="{{ $pool['id'] }}">{{ $pool['name'] }}</option>@endforeach</x-ui.forms.select></x-tables.filter-field>
                    <x-tables.filter-field label="Einsatzgebiet" for="staffing-region"><x-ui.forms.select id="staffing-region" wire:model.live="candidateRegion"><option value="all">Alle Gebiete</option><option value="preferred">Wunschgebiet</option><option value="border">Grenzgebiet</option><option value="outside">Außerhalb Wunschgebiet</option><option value="no_go">No-Go-Gebiet</option><option value="unknown">Ort nicht prüfbar</option><option value="neutral">Ohne Regionalwunsch</option></x-ui.forms.select></x-tables.filter-field>
                    <button type="button" class="rt-staffing-text-button" wire:click="resetCandidateFilters">Filter zurücksetzen</button>
                </div>
                <details class="rt-staffing-more"><summary>Wie wird die Eignung ermittelt?</summary><p>Der Wert ist eine Planungshilfe, keine Wahrscheinlichkeit oder Rechtsfreigabe: 85 Basispunkte, Wunschgebiet +15, Grenzgebiet +5, außerhalb −10. Fehlende Regionalwünsche bleiben neutral. Zeitkonflikte senken den Wert und erfordern eine gesonderte Prüfung; feste Ausschlussgründe stehen immer zuletzt. Pflichtqualifikationen müssen während der gesamten Schicht gültig sein.</p></details>
                <div wire:loading.flex wire:target="candidateSearch,candidateQualification,candidatePool,candidateSuitability,candidateRegion,resetCandidateFilters" class="rt-staffing-loading" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i> Treffer werden neu geprüft …</div>
                <div wire:loading.class="opacity-50 pointer-events-none" wire:target="candidateSearch,candidateQualification,candidatePool,candidateSuitability,candidateRegion,resetCandidateFilters">
                    <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name','width'=>'minmax(0,1.25fr)'],['label'=>'Eignung ↓','key'=>'eligibility','width'=>'minmax(0,1.2fr)'],['label'=>'','key'=>'action','width'=>'110px']]" :items="$candidates" row-view="components.tables.rows.operations.staffing-candidate" empty="Keine Treffer. Suche oder Filter anpassen." label="Mitarbeitende nach Planungseignung absteigend" class="rt-staffing-table" />
                </div>
                @if($candidates->count() < $candidateTotal)
                    <div class="rt-staffing-loading" wire:key="staffing-more-{{ $selectedShift->id }}-{{ $candidateLimit }}-{{ md5($candidateSearch.$candidateQualification.$candidatePool.$candidateSuitability.$candidateRegion) }}" x-data x-intersect.once="$wire.loadMoreCandidates()">
                        <button type="button" wire:click="loadMoreCandidates" wire:loading.attr="disabled" wire:target="loadMoreCandidates"><span wire:loading.remove wire:target="loadMoreCandidates">Weitere Mitarbeitende laden</span><span wire:loading wire:target="loadMoreCandidates" role="status"><i class="far fa-spinner-third fa-spin" aria-hidden="true"></i> Wird geladen …</span></button>
                    </div>
                @endif
                <p class="rt-staffing-note" aria-live="polite">{{ $candidates->count() }} von {{ $candidateTotal }} Mitarbeitenden · Bereits reservierte Personen stehen oben.</p>
            @endif
        </section>
    @endif
</div>
