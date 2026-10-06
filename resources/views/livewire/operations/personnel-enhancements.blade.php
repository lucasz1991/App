<div class="rt-ops ops-stack">
    <x-operations.feedback />
    @if(!$ready)
        <p class="text-sm text-rt-muted">Personalbereich nicht verfügbar.</p>
    @else
        @if(!$embedded)<header class="ops-toolbar">
            @if(!$personal)<div><x-ui.forms.label for="personnel-enhancement-person" value="Mitarbeiter"/><x-ui.forms.select id="personnel-enhancement-person" wire:model.live="userId"><option value="0">Auswählen</option>@foreach($employees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach</x-ui.forms.select></div>@endif
            @php
                $options = [
                    ['value'=>'workflows','label'=>'Prozesse','icon'=>'fa-list-check'],
                    ['value'=>'documents','label'=>'Unterlagen','icon'=>'fa-file-signature'],
                    ['value'=>'emergency','label'=>'Notfallkontakt','icon'=>'fa-phone'],
                    ['value'=>'surveys','label'=>'Rückmeldung','icon'=>'fa-comment-dots'],
                ];
                if($personal || auth()->user()->can('employees.development.manage')) $options[] = ['value'=>'development','label'=>'Entwicklung','icon'=>'fa-seedling'];
                if($personal || (auth()->user()->can('employees.master-data.edit') && auth()->user()->can('operations.absences.review'))) $options[] = ['value'=>'sickness','label'=>'Nachweisstatus','icon'=>'fa-notes-medical'];
                if(!$personal) {
                    $options[] = ['value'=>'reports','label'=>'Berichte','icon'=>'fa-chart-line'];
                    if(auth()->user()->can('operations.absences.review')) $options[] = ['value'=>'approvals','label'=>'Freigaben','icon'=>'fa-check-double'];
                    if(auth()->user()->can('operations.rules.manage')) $options[] = ['value'=>'calendars','label'=>'Regionalkalender','icon'=>'fa-calendar-days'];
                    if(auth()->user()->can('employees.recruiting.manage')) $options[] = ['value'=>'recruiting','label'=>'Bewerbungen','icon'=>'fa-user-plus'];
                }
            @endphp
            <x-ui.buttons.multi-toggle id="personnel-enhancement-view" label="Personalbereich" :value="$tab" action="showTab" :options="$options" />
        </header>@endif
        <div class="ops-actions">
            @if($tab === 'workflows' && $canGlobal)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('workflow_template')">Prozessvorlage</x-ui.buttons.button-basic>@endif
            @if($tab === 'reports' && !$personal)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('report')">Bericht speichern</x-ui.buttons.button-basic>@endif
            @if($tab === 'documents' && $canGlobal)<x-ui.buttons.button-basic type="button" wire:click="open('document_template')">Dokumentvorlage</x-ui.buttons.button-basic>@endif
            @if($tab === 'documents' && $canEdit)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('signature_request')">Unterzeichnung anfragen</x-ui.buttons.button-basic>@endif
            @if($tab === 'emergency' && $personal)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('emergency_edit')">Kontakt pflegen</x-ui.buttons.button-basic>@endif
            @if($tab === 'approvals' && !$personal && auth()->user()->can('operations.rules.manage'))<x-ui.buttons.button-basic type="button" wire:click="open('approval_policy')">Freigabekette</x-ui.buttons.button-basic>@endif
            @if($tab === 'calendars' && !$personal)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('calendar')">Kalenderstand</x-ui.buttons.button-basic>@endif
            @if($tab === 'recruiting' && !$personal)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('applicant')">Bewerbung erfassen</x-ui.buttons.button-basic>@endif
            @if($tab === 'development' && $canEdit)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('development')">Entwicklungsziel</x-ui.buttons.button-basic>@endif
            @if($tab === 'surveys' && $canGlobal)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('survey')">Umfrage</x-ui.buttons.button-basic>@endif
            @if($tab === 'sickness' && $canEdit)<x-ui.buttons.button-basic type="button" mode="primary" wire:click="open('sickness')">Nachweisprüfung</x-ui.buttons.button-basic>@endif
        </div>
        <x-tables.table :columns="[['label'=>'Eintrag','key'=>'label'],['label'=>'Frist / Stand','key'=>'detail'],['label'=>'Status','key'=>'status'],['label'=>'Aktionen','key'=>'actions']]" :items="$records" row-view="components.tables.rows.operations.personnel-enhancement" empty="Keine Einträge." />
    @endif
    <x-operations.modal wire:model="formOpen" title="Personal" max-width="4xl">
        @if($formOpen)
        @if($formKind === 'report_result')
            <p class="text-sm text-rt-muted">{{ $display['from'] ?? '' }} – {{ $display['until'] ?? '' }}</p>
            <x-tables.table :columns="[['label'=>'Mitarbeiter','key'=>'name'],['label'=>'Soll (min)','key'=>'target_minutes'],['label'=>'Ist (min)','key'=>'actual_minutes'],['label'=>'Saldo (min)','key'=>'balance_minutes']]" :items="collect($display['rows'] ?? [])->map(fn($row)=>(object)($row+['id'=>$row['user_id']]))" row-view="components.tables.rows.operations.personnel-report" empty="Keine Ergebnisse." />
            @if(isset($display['change_minutes']))<p class="text-sm tabular-nums">Änderung: {{ $display['change_minutes'] }} min</p>@endif
        @elseif($formKind === 'survey_result')
            <div class="ops-form"><div><p class="ops-muted">Antworten</p><p class="text-xl font-semibold tabular-nums">{{ $display['count'] ?? 0 }}</p></div><div><p class="ops-muted">Mittelwert</p><p class="text-xl font-semibold tabular-nums">{{ $display['average'] ?? '—' }} / 5</p></div></div>
        @else
            <form wire:submit="save" class="ops-form">
                @if(in_array($formKind,['workflow_template','report','document_template','signature_request','approval_policy','calendar','development','survey']))<x-operations.field label="Bezeichnung" model="form.title" required :wide="true"/>@endif
                @include('livewire.operations.partials.personnel-workflow-form')
                @include('livewire.operations.partials.personnel-document-form')
                @include('livewire.operations.partials.personnel-approval-form')
                @include('livewire.operations.partials.personnel-development-form')
                @include('livewire.operations.partials.personnel-survey-form')
                <div class="ops-full ops-actions">@if($formKind !== 'emergency_read' || $display === [])<x-ui.buttons.button-basic type="submit" mode="primary" wire:loading.attr="disabled">{{ $formKind === 'emergency_read' ? 'Notfallzugriff protokollieren' : 'Speichern' }}</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic type="button" wire:click="$set('formOpen',false)">Schließen</x-ui.buttons.button-basic></div>
            </form>
        @endif
        @endif
    </x-operations.modal>
</div>
