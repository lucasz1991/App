<section class="employee-operations" data-employee-operations data-operations-view="{{ $viewMode }}" aria-label="{{ $viewMode === 'overview' ? 'Betriebsübersicht' : 'Schichten des Mitarbeiters' }}">
    @if($viewMode !== 'overview')
    <header class="employee-operations__header">
        <div>
            <p class="employee-operations__eyebrow">Betrieb</p>
            <h2>Schichten &amp; Verlauf</h2>
            <p class="employee-operations__description">Zukünftige und vergangene Zuordnungen – mit Planungsstand und Rückmeldung.</p>
        </div>
        <span class="employee-operations__updated"><i class="far fa-clock" aria-hidden="true"></i> {{ $employeeOperations['updated_at'] }} · Europe/Berlin</span>
    </header>
    @endif

    @if($employeeOperations['state'] === 'unconfigured')
        <div class="employee-operations__state" role="status">
            <i class="far fa-calendar-exclamation" aria-hidden="true"></i>
            <h3>Schichtdaten noch nicht verfügbar</h3>
            <p>Die benötigte Schichtverwaltung ist nicht vollständig eingerichtet. Es werden keine unvollständigen Einsatzdaten angezeigt.</p>
        </div>
    @elseif($viewMode === 'overview')
        <dl class="employee-operations__summary" aria-label="Schichtzuordnungen im Überblick">
            <div><dt>Aktuell geplant</dt><dd>{{ $employeeOperations['summary']['ongoing'] }}</dd></div>
            <div><dt>Bevorstehend</dt><dd>{{ $employeeOperations['summary']['upcoming'] }}</dd></div>
            <div><dt>Vergangene Zuordnungen</dt><dd>{{ $employeeOperations['summary']['past'] }}</dd></div>
            <div><dt>Gesamter Verlauf</dt><dd>{{ $employeeOperations['summary']['total'] }}</dd></div>
        </dl>

        <div class="employee-operations__overview">
            <section class="employee-operations__schedule" aria-labelledby="employee-next-shift-{{ $userId }}">
                <header class="employee-operations__section-heading"><h3 id="employee-next-shift-{{ $userId }}">{{ count($employeeOperations['current']) ? 'Aktuell im Plan' : 'Nächster Einsatz' }}</h3><i class="far fa-calendar-days" aria-hidden="true"></i></header>
                @if(count($employeeOperations['current']))
                    @foreach($employeeOperations['current'] as $shift)
                        @include('livewire.profile.partials.employee-shift-preview', ['shift' => $shift, 'featured' => true])
                    @endforeach
                @elseif(count($employeeOperations['next']))
                    @include('livewire.profile.partials.employee-shift-preview', ['shift' => $employeeOperations['next'][0], 'featured' => true])
                @else
                    <div class="employee-operations__empty-duty"><i class="far fa-calendar-check" aria-hidden="true"></i><h4>Kein weiterer Einsatz eingeplant</h4><p>Für diesen Mitarbeiter liegen aktuell keine offenen oder bestätigten zukünftigen Zuordnungen vor.</p></div>
                @endif
                <p class="employee-operations__notice">Planungsdaten, keine Live-Anwesenheit. Angefragte Zuordnungen sind noch nicht bestätigt.</p>
                <div class="employee-operations__past-heading"><h4>Zuletzt im Verlauf</h4><span>Nach geplantem Endzeitpunkt</span></div>
                @forelse($employeeOperations['previous'] as $shift)
                    @include('livewire.profile.partials.employee-shift-preview', ['shift' => $shift, 'featured' => false])
                @empty
                    <p class="employee-operations__empty-context">Noch keine vergangenen Schichtzuordnungen.</p>
                @endforelse
            </section>

            <section class="employee-operations__schedule" aria-labelledby="employee-shift-context-{{ $userId }}">
                <header class="employee-operations__section-heading"><h3 id="employee-shift-context-{{ $userId }}">Weitere Einsätze</h3><span>{{ $employeeOperations['summary']['confirmed'] }} bestätigt · {{ $employeeOperations['summary']['requested'] }} angefragt</span></header>
                @php
                    $nextShifts = count($employeeOperations['current']) ? $employeeOperations['next'] : array_slice($employeeOperations['next'], 1);
                @endphp
                @forelse($nextShifts as $shift)
                    @include('livewire.profile.partials.employee-shift-preview', ['shift' => $shift, 'featured' => false])
                @empty
                    <p class="employee-operations__empty-context">Keine weiteren bevorstehenden Einsätze.</p>
                @endforelse
            </section>
        </div>
        <p class="employee-operations__footnote">Der Bereich „Schichten“ enthält den vollständigen verfügbaren Verlauf, auch abgelehnte und stornierte Zuordnungen. Ein vergangener Planzeitraum ist kein Nachweis geleisteter Arbeitszeit.</p>
    @else
        <div class="employee-operations__controls">
            <x-ui.buttons.multi-toggle :options="[
                ['value' => 'upcoming', 'label' => 'Aktuell & geplant', 'compactLabel' => 'Geplant', 'icon' => 'fa-calendar-days'],
                ['value' => 'past', 'label' => 'Vergangen', 'icon' => 'fa-clock-rotate-left'],
                ['value' => 'all', 'label' => 'Gesamter Verlauf', 'compactLabel' => 'Verlauf', 'icon' => 'fa-list'],
            ]" :value="$period" action="setPeriod" label="Schichtzeitraum" :show-labels="true" />
            <div class="employee-operations__search"><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Schicht, Ort oder Kunde suchen" aria-label="Schicht, Ort oder Kunde suchen" maxlength="120" /></div>
        </div>
        @error('search') <p class="employee-operations__validation" role="alert">{{ $message }}</p> @enderror
        @error('period') <p class="employee-operations__validation" role="alert">{{ $message }}</p> @enderror
        @error('employeeShiftsPage') <p class="employee-operations__validation" role="alert">{{ $message }}</p> @enderror

        @php
            $shiftRows = clone $employeeOperations['records'];
            $shiftRows->setCollection($shiftRows->getCollection()->map(fn ($row) => (object) $row));
            $shiftTableKey = 'employee-shifts-'.$userId.'-'.$period.'-'.substr(hash('sha256', $search), 0, 10);
        @endphp
        <div class="employee-operations__results" wire:key="{{ $shiftTableKey }}">
            <x-tables.table :columns="[
                ['key' => 'schedule', 'label' => 'Zeitraum', 'width' => 'minmax(160px, .9fr)'],
                ['key' => 'shift', 'label' => 'Einsatz', 'width' => 'minmax(0, 1.5fr)'],
                ['key' => 'status', 'label' => 'Plan & Rückmeldung', 'width' => 'minmax(160px, .9fr)', 'hideOn' => 'md'],
            ]" :items="$shiftRows" :table-key="$shiftTableKey" label="Schichten des Mitarbeiters"
                :flush-top="true" :mobile-header="true" detail-action="openShift"
                row-view="livewire.profile.partials.employee-shift-row" actions-view="livewire.profile.partials.employee-shift-action"
                empty="Keine Schichtzuordnungen für diese Auswahl gefunden." class="employee-operations__table"
                :data-table-pagination-key="$shiftTableKey" />
            <x-tables.pagination :paginator="$shiftRows" :table-key="$shiftTableKey" label="Seiten des Schichtverlaufs" class="employee-operations__pagination" />
        </div>
        <p class="employee-operations__footnote">„Aktuell & geplant“ zeigt angefragte und bestätigte Zuordnungen in offenen Schichten. Im gesamten Verlauf bleiben auch Absagen, Stornierungen und abgeschlossene Planungen sichtbar. Zeiten entsprechen der angegebenen Schichtzeitzone.</p>
    @endif
</section>
