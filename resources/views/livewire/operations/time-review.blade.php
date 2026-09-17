<div class="space-y-4">
<x-tables.toolbar title="Filter" id="time-filters">
<x-slot:search><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" /></x-slot:search>
<x-slot:bulk>@if($exports)<x-ui.buttons.button-basic mode="primary" wire:click="export" wire:loading.attr="disabled">Auswahl exportieren</x-ui.buttons.button-basic>@endif</x-slot:bulk>
@if(!$exports)<x-ui.forms.select wire:model.live="filter" aria-label="Zeitstatus"><option value="submitted">Zur Prüfung</option><option value="returned">Zurückgegeben</option><option value="approved">Freigegeben</option><option value="all">Alle</option></x-ui.forms.select>@endif
</x-tables.toolbar>
<x-operations.feedback />
<x-tables.table :columns="[['label'=>'Dienst','key'=>'plan_snapshot.title','width'=>'2fr'],['label'=>'Mitarbeiter','key'=>'user.name'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Netto','key'=>'net_time'],['label'=>'Status','key'=>'status']]" :items="$entries" :selected-items="$selected" detail-action="openDetails" row-view="components.tables.rows.operations.record" :actions-view="$exports ? 'components.tables.rows.operations.export-selection' : null" empty="Keine Zeitmeldungen in dieser Ansicht." />
{{ $entries->links() }}
<x-operations.modal wire:model="detailOpen" title="Zeitmeldung" max-width="4xl">
@if($detailEntry)
@php
$entry = $detailEntry;
$warnings = app(\App\Services\Operations\WorkTimeService::class)->warnings($entry);
@endphp

                <header class="ops-toolbar"><div class="ops-actions">@if($exports)<x-ui.forms.checkbox wire:model="selected" value="{{ $entry->id }}" aria-label="Zeitmeldung auswählen" />@endif<div><p class="ops-kicker">{{ $entry->user->name }} · Revision {{ $entry->revision }}</p><h2>{{ $entry->plan_snapshot['title'] }}</h2></div></div><x-operations.status :value="$entry->status" /></header>
                <dl class="ops-meta"><div><dt>Plan</dt><dd>{{ \Carbon\CarbonImmutable::parse($entry->plan_snapshot['starts_at'])->format('d.m. H:i') }} – {{ \Carbon\CarbonImmutable::parse($entry->plan_snapshot['ends_at'])->format('d.m. H:i') }}</dd></div><div><dt>Ist · {{ $entry->timezone }}</dt><dd>{{ $entry->starts_at->format('d.m. H:i') }} – {{ $entry->ends_at?->format('d.m. H:i') ?? 'Läuft' }}</dd></div><div><dt>Pause</dt><dd>{{ intdiv($entry->pause_seconds,60) }} min</dd></div><div><dt>Netto</dt><dd>{{ \App\Support\Operations\OperationsDateTime::duration($entry->netSeconds()) }}</dd></div></dl>
                @if($warnings)<div class="ops-actions">@foreach($warnings as $warning)<span class="ops-badge" data-state="pending">{{ $warning }}</span>@endforeach</div>@endif
                @if($entry->note)<p>{{ $entry->note }}</p>@endif
                @if($entry->review_note)<p class="ops-muted">{{ $entry->review_note }}</p>@endif
                @if(!$exports && $entry->status === 'submitted' && $entry->user_id !== auth()->id())<x-operations.field label="Prüfvermerk" :model="'notes.'.$entry->id" maxlength="1000" /><div class="ops-actions"><x-ui.buttons.button-basic mode="primary" wire:click="decide({{ $entry->id }}, {{ $entry->revision }}, true)" wire:loading.attr="disabled">Freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="decide({{ $entry->id }}, {{ $entry->revision }}, false)" wire:loading.attr="disabled">Zur Korrektur</x-ui.buttons.button-basic></div>@endif
                <details><summary>Änderungsverlauf</summary>@foreach($entry->revisions()->orderByDesc('id')->get() as $revision)<div class="ops-row"><span>Revision {{ $revision->revision }} · {{ ['submitted'=>'Eingereicht','approved'=>'Freigegeben','returned'=>'Zurückgegeben','before_correction'=>'Vor Korrektur','corrected'=>'Korrigiert'][$revision->action] ?? 'Gespeichert' }}</span><span class="ops-muted">{{ $revision->created_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }} · {{ \App\Support\Operations\OperationsDateTime::duration($revision->snapshot['net_seconds']) }}</span></div>@endforeach</details>

@endif
</x-operations.modal>
@if($exports)<section class="space-y-3"><h2>Bisherige Exporte</h2>
<x-tables.table :columns="[['label'=>'Export','key'=>'id'],['label'=>'Erstellt','key'=>'created_at']]" :items="$history" detail-action="download" row-view="components.tables.rows.operations.record" empty="Noch keine Exporte." />
</section>@endif
</div>
