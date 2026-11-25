<div class="rt-ops rt-personnel-management rt-time-review ops-stack">
@if(!$embedded && !$exports && $workTimeReady)<livewire:operations.work-time-capture-review />@endif
@if($showPayrollReferences)<livewire:operations.payroll-references />@endif
@if(!$historyOnly)
    <div class="rt-personnel-period" role="group" aria-label="Zeitraum der Zeitmeldungen">
        <div class="rt-personnel-period__intro"><span class="rt-personnel-eyebrow">Zeitraum</span><p>{{ $exports ? 'Arbeitszeiten exportieren' : 'Arbeitszeiten prüfen' }}</p></div>
        <div><x-ui.forms.label for="time-period-from" value="Beginn von" /><x-ui.forms.date-field id="time-period-from" wire:model.live="from" aria-label="Beginn von" /><x-ui.forms.input-error for="from" /></div>
        <div><x-ui.forms.label for="time-period-until" value="Beginn bis" /><x-ui.forms.date-field id="time-period-until" wire:model.live="until" aria-label="Beginn bis" /><x-ui.forms.input-error for="until" /></div>
    </div>
@endif
<x-tables.toolbar title="Filter" id="time-filters" :filter-count="$this->activeFilterCount()" reset-action="resetFilters">
<x-slot:search>@if(!$historyOnly)<x-ui.forms.search wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" />@endif</x-slot:search>
<x-slot:bulk>
    @if(!$historyOnly)
        @if($exports)<x-ui.buttons.button-basic mode="primary" wire:click="export" :disabled="empty($selected)" wire:loading.attr="disabled">Auswahl exportieren</x-ui.buttons.button-basic>
        @else<x-ui.buttons.button-basic mode="primary" wire:click="prepareBatch" :disabled="empty($selected)" wire:loading.attr="disabled">Auswahl prüfen</x-ui.buttons.button-basic>@endif
    @endif
</x-slot:bulk>
@if(!$exports)<x-tables.filter-field label="Zeitstatus" for="time-status-filter"><x-ui.forms.select id="time-status-filter" wire:model.live="filter" aria-label="Zeitstatus"><option value="submitted">Zur Prüfung</option><option value="returned">Zurückgegeben</option><option value="approved">Freigegeben</option><option value="all">Alle</option></x-ui.forms.select></x-tables.filter-field>@endif
@if($exports)<x-tables.filter-field label="CSV-Format" for="time-export-profile"><x-ui.forms.select id="time-export-profile" wire:model.live="exportProfile" aria-label="CSV-Format"><option value="v1">Schichtzeiten · CSV v1</option>@if($workTimeReady)<option value="v2">Alle Arbeitszeiten · CSV v2</option>@endif</x-ui.forms.select></x-tables.filter-field>@endif
</x-tables.toolbar>
<x-operations.feedback />
@if(!$historyOnly)
    <p class="ops-muted" aria-live="polite">
        @if($exports)<strong>{{ $exportProfile === 'v1' ? 'Schichtzeiten · CSV v1' : 'Alle Arbeitszeiten · CSV v2' }}</strong> · @endif
        {{ $period ? 'Zeitraum '.\Carbon\CarbonImmutable::parse($period[0])->format('d.m.Y').' – '.\Carbon\CarbonImmutable::parse($period[1])->format('d.m.Y') : 'Gesamter Zeitraum' }}
        @if($exports) · Exportiert werden die ausgewählten freigegebenen Meldungen.@endif
    </p>
@endif
@if(!$historyOnly)<div class="rt-personnel-results" aria-live="polite" aria-atomic="true"><span><strong>{{ $entries->total() }}</strong> {{ $entries->total() === 1 ? 'Zeitmeldung' : 'Zeitmeldungen' }}</span><span>{{ count($selected) }} ausgewählt</span></div><x-tables.table class="rt-personnel-table" label="Zeitmeldungen" :columns="[['label'=>'Tätigkeit','key'=>'plan_snapshot.title','width'=>'2fr'],['label'=>'Mitarbeiter','key'=>'user.name'],['label'=>'Plan netto','key'=>'planned_net'],['label'=>'Ist netto','key'=>'net_time'],['label'=>'Abweichung','key'=>'time_delta'],['label'=>'Status','key'=>'status']]" :items="$entries" :selected-items="$selected" detail-action="openDetails" row-view="components.tables.rows.shared.record-row" :actions-view="$exports ? 'components.tables.rows.time.export-selection-actions' : 'components.tables.rows.time.review-selection-actions'" empty="Keine Zeitmeldungen in dieser Ansicht. Prüfen Sie den Zeitraum und den Zeitstatus." />
<x-tables.pagination :paginator="$entries" label="Zeitmeldungsseiten" />@endif
<x-operations.modal wire:model="batchOpen" title="Auswahl prüfen" max-width="4xl">
    <div class="rt-personnel-management rt-personnel-detail ops-stack">
    <header class="rt-personnel-section-heading">
        <div><h3>{{ $batchEntries->count() }} {{ $batchEntries->count() === 1 ? 'Meldung ausgewählt' : 'Meldungen ausgewählt' }}</h3>
            <p>{{ $warningCount ? $warningCount.' mit Prüfhinweis – bitte einzeln prüfen.' : 'Alle ausgewählten Meldungen sind ohne Prüfhinweis.' }}</p>
        </div>
    </header>
    <ul class="divide-y divide-rt-border dark:divide-rt-dark-border">
        @foreach($batchEntries as $entry)
            @php
                $comparison = $comparisons[$entry->id];
            @endphp
            <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="batch-time-{{ $entry->id }}">
                <div class="min-w-0 flex-1">
                    <strong>{{ $entry->plan_snapshot['title'] ?? $entry->contextLabel() }}</strong>
                    <p class="ops-muted">{{ $entry->user?->name }} · {{ $entry->starts_at->format('d.m.Y') }}
                        @if($comparison['delta'] !== null) · Abweichung {{ $comparison['delta'] < 0 ? '−' : ($comparison['delta'] > 0 ? '+' : '') }}{{ \App\Support\Operations\OperationsDateTime::duration(abs($comparison['delta'])) }}@endif
                    </p>
                    @foreach($comparison['warnings'] as $warning)<p class="text-sm text-amber-800 dark:text-amber-300">{{ $warning }}</p>@endforeach
                </div>
                @if($comparison['warnings'])
                    <x-ui.buttons.button-basic type="button" wire:click="openBatchEntry({{ $entry->id }})" wire:loading.attr="disabled">Einzeln prüfen</x-ui.buttons.button-basic>
                @endif
            </li>
        @endforeach
    </ul>
    <x-operations.field label="Prüfvermerk / Korrekturgrund" model="batchNote" maxlength="1000" />
    <div class="ops-actions">
        @if($batchEntries->isNotEmpty() && ! $warningCount)<x-ui.buttons.button-basic mode="primary" wire:click="reviewBatch(true)" wire:loading.attr="disabled">Auswahl freigeben</x-ui.buttons.button-basic>@endif
        <x-ui.buttons.button-basic wire:click="reviewBatch(false)" wire:loading.attr="disabled">Zur Korrektur zurückgeben</x-ui.buttons.button-basic>
    </div>
    </div>
</x-operations.modal>
<x-operations.modal wire:model.live="detailOpen" title="Zeitmeldung" max-width="4xl">
<div class="rt-personnel-management rt-personnel-detail ops-stack">
@if($detailEntry)
@php
$entry = $detailEntry;
$warnings = $detailWarnings;
@endphp

                <header class="ops-toolbar"><div class="ops-actions">@if($exports)<x-ui.forms.checkbox wire:model.live="selected" value="{{ $entry->id }}" aria-label="Zeitmeldung auswählen" />@endif<div><p class="ops-kicker">{{ $entry->user?->name }} · Revision {{ $entry->revision }} · {{ $entry->contextLabel() }}</p><h2>{{ $entry->plan_snapshot['title'] ?? $entry->contextLabel() }}</h2></div></div><x-operations.status :value="$entry->status" /></header>
                <dl class="ops-meta">@if(!empty($entry->plan_snapshot['starts_at']) && !empty($entry->plan_snapshot['ends_at']))<div><dt>Plan · {{ $entry->timezone }}</dt><dd>{{ \Carbon\CarbonImmutable::parse($entry->plan_snapshot['starts_at'])->setTimezone($entry->timezone)->format('d.m. H:i') }} – {{ \Carbon\CarbonImmutable::parse($entry->plan_snapshot['ends_at'])->setTimezone($entry->timezone)->format('d.m. H:i') }}</dd></div>@endif<div><dt>Ist · {{ $entry->timezone }}</dt><dd>{{ $entry->starts_at->format('d.m. H:i') }} – {{ $entry->ends_at?->format('d.m. H:i') ?? 'Läuft' }}</dd></div><div><dt>Pause</dt><dd>{{ intdiv($entry->pause_seconds,60) }} min</dd></div><div><dt>Netto</dt><dd>{{ \App\Support\Operations\OperationsDateTime::duration($entry->netSeconds()) }}</dd></div>@if($workTimeReady)<div><dt>Kontogutschrift</dt><dd>{{ ($credited = $entry->creditedSeconds()) === null ? 'Nicht bewertet' : \App\Support\Operations\OperationsDateTime::duration($credited) }}</dd></div>@endif</dl>
                @if($entry->relationLoaded('activities') && $entry->activities->isNotEmpty())<x-tables.table :columns="[['label'=>'Abschnitt','key'=>'kind'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Ende','key'=>'ends_at'],['label'=>'Bewertung','key'=>'source']]" :items="$entry->activities" row-view="components.tables.rows.time.activity-row" />@endif
                @if($warnings)<div class="ops-actions">@foreach($warnings as $warning)<x-ui.badge color="amber" size="sm">{{ $warning }}</x-ui.badge>@endforeach</div>@endif
                @if($entry->note)<p>{{ $entry->note }}</p>@endif
                @if($entry->review_note)<p class="ops-muted">{{ $entry->review_note }}</p>@endif
                @if(!$exports && $entry->status === 'submitted' && $entry->user_id !== auth()->id())<x-operations.field label="Prüfvermerk" :model="'notes.'.$entry->id" maxlength="1000" /><div class="ops-actions"><x-ui.buttons.button-basic mode="primary" wire:click="decide({{ $entry->id }}, {{ $entry->revision }}, true)" wire:loading.attr="disabled">Freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="decide({{ $entry->id }}, {{ $entry->revision }}, false)" wire:loading.attr="disabled">Zur Korrektur</x-ui.buttons.button-basic></div>@endif
                <details><summary>Änderungsverlauf</summary>@foreach($detailRevisions as $revision)<div class="ops-row"><span>Revision {{ $revision->revision }} · {{ ['submitted'=>'Eingereicht','approved'=>'Freigegeben','returned'=>'Zurückgegeben','before_correction'=>'Vor Korrektur','corrected'=>'Korrigiert'][$revision->action] ?? 'Gespeichert' }}</span><span class="ops-muted">{{ $revision->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} · {{ \App\Support\Operations\OperationsDateTime::duration($revision->snapshot['net_seconds']) }}</span></div>@endforeach</details>

@endif
</div>
    @if($detailFromBatch)
        <x-slot:footer><x-ui.buttons.button-basic type="button" wire:click="closeDetails" wire:loading.attr="disabled">Zurück zur Auswahl</x-ui.buttons.button-basic></x-slot:footer>
    @endif
</x-operations.modal>
@if($exports)<section class="rt-personnel-history ops-stack"><header class="rt-personnel-section-heading"><h2>Bisherige Exporte</h2><p>Erstellte Dateien erneut herunterladen.</p></header>
<x-tables.table :columns="[['label'=>'Export','key'=>'id'],['label'=>'Erstellt','key'=>'created_at']]" :items="$history" detail-action="download" row-view="components.tables.rows.shared.record-row" :actions-view="$exportProfile === 'v1' ? 'components.tables.rows.time.payroll-download-actions' : null" :empty="$exportProfile === 'v2' ? 'Noch keine Arbeitszeit-Exporte (v2).' : 'Noch keine Schichtzeit-Exporte (v1).'" />
</section>@endif
</div>
