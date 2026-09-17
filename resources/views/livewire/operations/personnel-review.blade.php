<div class="space-y-4">
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
<x-tables.toolbar title="Filter" id="personnel-filters">
<x-slot:search><x-tables.search-field wire:model.live.debounce.300ms="search" placeholder="Mitarbeiter suchen" /></x-slot:search>
<x-slot:bulk>@if($module === 'qualifications')<x-ui.buttons.button-basic wire:click="$set('typesOpen', true)">Nachweisarten</x-ui.buttons.button-basic>@endif</x-slot:bulk>
<x-ui.forms.select wire:model.live="filter" aria-label="Prüfstatus"><option value="pending">In Prüfung</option><option value="approved">Freigegeben</option><option value="all">Alle</option></x-ui.forms.select>
</x-tables.toolbar>
<x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'user.name'],['label'=>'Art','key'=>$module === 'qualifications' ? 'qualification_label' : 'absence_label'],['label'=>'Beginn','key'=>$module === 'qualifications' ? 'valid_from' : 'starts_at'],['label'=>'Ende','key'=>$module === 'qualifications' ? 'valid_until' : 'ends_at'],['label'=>'Status','key'=>'status']]" :items="$records" detail-action="openDetails" row-view="components.tables.rows.operations.record" empty="Keine Einträge in dieser Ansicht." />
{{ $records->links() }}
<x-operations.modal wire:model="detailOpen" title="Prüfung">
@if($selectedRecord)
@php($record = $selectedRecord)
<header class="ops-toolbar"><div><p class="ops-kicker">{{ $record->user->name }} · Revision {{ $record->revision }}</p><h2>{{ $module === 'qualifications' ? $record->type->name : ['vacation'=>'Urlaub','unavailable'=>'Nicht verfügbar','other'=>'Abwesenheit'][$record->kind] }}</h2></div><x-operations.status :value="$record->status" /></header>
                    @if($module === 'qualifications')<div class="ops-toolbar"><p>Gültig {{ $record->valid_from->format('d.m.Y') }} – {{ $record->valid_until->format('d.m.Y') }}</p>@if($record->evidence_path)<a class="ops-link" href="{{ route('operations.evidence', $record->id) }}">Nachweis öffnen ↗</a>@else<span class="ops-muted">Datei fehlt</span>@endif</div>
                    @else<p>{{ $record->starts_at->format('d.m.Y H:i') }} – {{ $record->ends_at->format('d.m.Y H:i') }} <span class="ops-muted">{{ $record->timezone }}</span></p>@if($record->note)<p>{{ $record->note }}</p>@endif @endif
                    @if($record->review_note)<p class="ops-muted">{{ $record->review_note }}</p>@endif
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
