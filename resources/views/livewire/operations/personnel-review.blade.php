<div class="min-w-0 space-y-4">
<x-operations.feedback />
@if($module === 'rules')
<div class="flex justify-end"><x-ui.buttons.button-basic mode="primary" wire:click="createRules">Neues Regelprofil</x-ui.buttons.button-basic></div>
<section class="ops-panel ops-stack"><p class="ops-kicker">Aktives Regelprofil</p>
                @if($activeRules)<h2>{{ $activeRules->name }}</h2><dl class="ops-meta">@foreach(['minimum_rest_minutes'=>'Mindestruhezeit','maximum_shift_minutes'=>'Maximale Schichtdauer','break_after_minutes'=>'Pause ab','minimum_break_minutes'=>'Mindestpause'] as $field=>$label)<div><dt>{{ $label }}</dt><dd>{{ $activeRules->$field }} min</dd></div>@endforeach</dl><p class="ops-muted">Freigegeben am {{ $activeRules->approved_at->setTimezone(config('operations.display_timezone'))->format('d.m.Y H:i') }}</p>
                @else<p>Kein Regelprofil freigegeben.</p>@endif
            </section>
<x-operations.modal wire:model="formOpen" title="Regelprofil"><form wire:submit="saveRules" class="ops-panel ops-form"><h2 class="ops-full">Neues Regelprofil</h2><x-operations.field label="Bezeichnung" model="rules.name" required :wide="true" />
                @foreach(['minimum_rest_minutes'=>'Mindestruhezeit (min)','maximum_shift_minutes'=>'Maximale Schichtdauer (min)','break_after_minutes'=>'Pause nach (min)','minimum_break_minutes'=>'Mindestpause (min)'] as $field=>$label)<x-operations.field :label="$label" :model="'rules.'.$field" type="number" min="0" required />@endforeach
                <div class="ops-full"><x-ui.forms.checkbox wire:model="rules.confirmed" required label="Betrieblich geprüfte Regeln freigeben" /></div>
                <div class="ops-full"><x-ui.buttons.button-basic mode="primary" type="submit" wire:loading.attr="disabled">Regelprofil aktivieren</x-ui.buttons.button-basic></div>
            </form></x-operations.modal>
@else
@if($module === 'absences')
<header class="ops-toolbar"><h2>Urlaub & Abwesenheiten</h2><x-ui.buttons.multi-toggle id="absence-view" label="Abwesenheitsansicht" :value="$absenceView" action="setAbsenceView" :options="[['value'=>'list','label'=>'Liste','icon'=>'fa-table-list'],['value'=>'calendar','label'=>'Kalender','icon'=>'fa-calendar-days']]" /></header>
<div class="grid gap-3 sm:grid-cols-2"><div><x-ui.forms.label for="absence-period-from" value="Von" /><x-ui.forms.date-field id="absence-period-from" wire:model.live="from" aria-label="Abwesenheiten von" /></div><div><x-ui.forms.label for="absence-period-until" value="Bis" /><x-ui.forms.date-field id="absence-period-until" wire:model.live="until" aria-label="Abwesenheiten bis" /></div></div>
@endif
<x-tables.toolbar title="Filter" id="personnel-filters">
<x-slot:search><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" /></x-slot:search>
<x-slot:bulk>@if($module === 'qualifications')<x-ui.buttons.button-basic wire:click="$set('typesOpen', true)">Nachweisarten</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic wire:click="exportAbsences" wire:loading.attr="disabled">CSV exportieren</x-ui.buttons.button-basic>@endif</x-slot:bulk>
@if($module === 'absences')<x-tables.filter-field label="Art" for="absence-kind-filter"><x-ui.forms.select id="absence-kind-filter" wire:model.live="absenceKind" aria-label="Abwesenheitsart"><option value="all">Alle Arten</option><option value="vacation">Urlaub</option><option value="unavailable">Nicht verfügbar</option><option value="other">Abwesenheit</option></x-ui.forms.select></x-tables.filter-field>@endif
<x-tables.filter-field label="Prüfstatus" for="personnel-status-filter"><x-ui.forms.select id="personnel-status-filter" wire:model.live="filter" aria-label="Prüfstatus"><option value="pending">In Prüfung</option><option value="approved">Freigegeben</option><option value="all">Alle</option></x-ui.forms.select></x-tables.filter-field>
@if($module === 'qualifications')<x-tables.filter-field label="Gültigkeit" for="personnel-validity"><x-ui.forms.select id="personnel-validity" wire:model.live="validity" aria-label="Gültigkeit"><option value="all">Alle Fristen</option><option value="expired">Abgelaufen</option><option value="30">Ablauf in 30 Tagen</option><option value="90">Ablauf in 90 Tagen</option><option value="future">Künftig gültig</option></x-ui.forms.select></x-tables.filter-field>@endif
</x-tables.toolbar>
@if($module === 'absences' && $absenceView === 'calendar' && $from && $until && \Carbon\CarbonImmutable::parse($from)->diffInDays(\Carbon\CarbonImmutable::parse($until)) < 94)
<livewire:operations.staff-timeline :from="$from" :until="$until" :absences-only="true" :absence-kind="$absenceKind" :absence-status="$filter" :search="$search" :key="'absences-'.$from.'-'.$until.'-'.$absenceKind.'-'.$filter.'-'.$search" />
@else
@if($module === 'absences' && $absenceView === 'calendar')<p class="ops-muted">Kalender: maximal 93 Tage auswählen.</p>@endif
<x-tables.table :columns="array_merge([['label'=>'Mitarbeiter','key'=>'user.name'],['label'=>'Art','key'=>$module === 'qualifications' ? 'qualification_label' : 'absence_label'],['label'=>'Beginn','key'=>$module === 'qualifications' ? 'valid_from' : 'starts_at'],['label'=>'Ende','key'=>$module === 'qualifications' ? 'valid_until' : 'ends_at'],['label'=>'Status','key'=>'status']], $module === 'qualifications' ? [['label'=>'Gültigkeit','key'=>'validity']] : [])" :items="$records" detail-action="openDetails" row-view="components.tables.rows.operations.record" empty="Keine Einträge in dieser Ansicht." />
{{ $records->links() }}
@endif
<x-operations.modal wire:model="detailOpen" title="Prüfung">
@if($selectedRecord)
@php($record = $selectedRecord)
<header class="ops-toolbar"><div><p class="ops-kicker">{{ $record->user->name }} · Revision {{ $record->revision }}</p><h2>{{ $module === 'qualifications' ? $record->type->name : ['vacation'=>'Urlaub','unavailable'=>'Nicht verfügbar','other'=>'Abwesenheit'][$record->kind] }}</h2></div><x-operations.status :value="$record->status" /></header>
                    @if($module === 'qualifications')<div class="ops-toolbar"><p>Gültig {{ $record->valid_from->format('d.m.Y') }} – {{ $record->valid_until->format('d.m.Y') }}</p>@if($record->evidence_path)<a class="ops-link" href="{{ route('operations.evidence', $record->id) }}">Nachweis öffnen ↗</a>@else<span class="ops-muted">Datei fehlt</span>@endif</div>
                    @else<p>{{ $record->starts_at->format('d.m.Y H:i') }} – {{ $record->ends_at->format('d.m.Y H:i') }} <span class="ops-muted">{{ $record->timezone }}</span></p>@if($record->note)<p>{{ $record->note }}</p>@endif @endif
                    @if($record->review_note)<p class="ops-muted">{{ $record->review_note }}</p>@endif
                    @if($module === 'absences' && $absenceConflicts->isNotEmpty())<h3 class="text-sm font-semibold">Umzuplanende Dienste</h3><x-tables.table :columns="[['label'=>'Dienst','key'=>'title'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Ende','key'=>'ends_at']]" :items="$absenceConflicts" row-view="components.tables.rows.operations.affected-shift" />@endif
                    @if($module === 'absences' && $record->status === 'approved' && $record->starts_at->isFuture() && $record->user_id !== auth()->id())
                        <x-operations.field label="Stornierungsgrund" :model="'notes.'.$record->id" maxlength="1000" />
                        <x-ui.buttons.button-basic wire:click="decide({{ $record->id }}, {{ $record->revision }}, 'cancel')" wire:confirm="Genehmigte Abwesenheit stornieren?" wire:loading.attr="disabled">Abwesenheit stornieren</x-ui.buttons.button-basic>
                    @endif
                    @if($module === 'qualifications')
                        <h3 class="text-sm font-semibold">Betroffene zukünftige Dienste</h3>
                        <x-tables.table :columns="[['label'=>'Dienst','key'=>'title'],['label'=>'Beginn','key'=>'starts_at'],['label'=>'Ende','key'=>'ends_at']]" :items="$affectedShifts" row-view="components.tables.rows.operations.affected-shift" empty="Keine zukünftigen Dienste ohne gültigen Nachweis." />
                    @endif
                    @if(($record->status === 'pending' || ($module === 'qualifications' && $record->status === 'approved')) && $record->user_id !== auth()->id())
                        <x-operations.field label="Prüfvermerk" :model="'notes.'.$record->id" maxlength="1000" />
                        <div class="ops-actions">
                            @if($record->status === 'pending')<x-ui.buttons.button-basic mode="primary" wire:click="decide({{ $record->id }}, {{ $record->revision }}, 'approve')" wire:loading.attr="disabled">Freigeben</x-ui.buttons.button-basic><x-ui.buttons.button-basic wire:click="decide({{ $record->id }}, {{ $record->revision }}, 'reject')" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic>
                            @else<x-ui.buttons.button-basic wire:click="decide({{ $record->id }}, {{ $record->revision }}, 'revoke')" wire:confirm="Nachweis widerrufen?" wire:loading.attr="disabled">Widerrufen</x-ui.buttons.button-basic>@endif
                        </div>
                    @endif

@endif
</x-operations.modal>
@if($module === 'qualifications')
<x-operations.modal wire:model="typesOpen" title="Nachweisarten">
<x-tables.table :columns="[['label'=>'Bezeichnung','key'=>'name'],['label'=>'Status','key'=>'is_active']]" :items="$types" row-view="components.tables.rows.operations.record" />
<form wire:submit="addType" class="ops-form"><x-operations.field label="Neue Nachweisart" model="typeName" required maxlength="180" /><div class="self-end"><x-ui.buttons.button-basic type="submit">Hinzufügen</x-ui.buttons.button-basic></div></form>
</x-operations.modal>
@endif
@endif
</div>
