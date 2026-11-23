@php($personal = (bool) data_get($item, 'personal_view', false))
@php($userId = (int) data_get($item, 'context_user_id', 0))
@foreach($columnsMeta as $column)
<div class="min-w-0 px-2 py-2 {{ $hideClass($column['hideOn']) }}">
@switch($column['key'])
@case('label')<p class="font-semibold text-sm">{{ $item->label }}</p>@break
@case('detail')<span class="text-sm text-rt-muted tabular-nums">{{ $item->detail ?: '—' }}</span>@break
@case('status')<span class="text-sm">{{ ['draft'=>'Entwurf','paused'=>'Pausiert','retired'=>'Beendet','active'=>'Freigegeben','open'=>'Offen','overdue'=>'Überfällig','done'=>'Erledigt','pending_external'=>'Unterzeichnung offen','result_submitted'=>'Ergebnis eingereicht','verified_result'=>'Ergebnis geprüft','rejected'=>'Abgelehnt','present'=>'Hinterlegt','received'=>'Eingang','screening'=>'Sichtung','interview'=>'Gespräch','offer'=>'Angebot','converted'=>'Übernommen','withdrawn'=>'Zurückgezogen','erased'=>'Bereinigt','completed'=>'Abgeschlossen','submitted'=>'Eingereicht','declined'=>'Nicht teilgenommen','covered'=>'Abgedeckt','uncovered'=>'Abdeckung fehlt','follow_up'=>'Rückfrage','needs_review'=>'Erneut prüfen'][$item->status] ?? $item->status }}</span>@break
@case('actions')<div class="ops-actions">
    @if(in_array($item->kind,['workflow_template','document_template','calendar','approval_policy']))
        @if($item->status === 'draft' && data_get($item,'can_activate',false))<x-ui.buttons.button-basic type="button" wire:click="activate('{{ $item->kind }}',{{ $item->record_id }},{{ $item->revision }})" wire:loading.attr="disabled">Freigeben</x-ui.buttons.button-basic>@endif
        @if($item->kind === 'approval_policy' && $item->status === 'active')<x-ui.buttons.button-basic type="button" wire:click="open('approval_retire',{{ $item->record_id }},{{ $item->revision }})">Stand beenden</x-ui.buttons.button-basic>@endif
        @if($item->status === 'active' && $userId)
            @if($item->kind === 'workflow_template')<x-ui.buttons.button-basic type="button" wire:click="open('workflow_run',{{ $item->record_id }},{{ $item->revision }})">Starten</x-ui.buttons.button-basic>@endif
            @if($item->kind === 'document_template')<x-ui.buttons.button-basic type="button" wire:click="downloadDocumentTemplate({{ $item->record_id }})">Vorlage</x-ui.buttons.button-basic>@endif
            @if($item->kind === 'calendar')<x-ui.buttons.button-basic type="button" wire:click="open('calendar_apply',{{ $item->record_id }},{{ $item->revision }})">Richtlinie vorbereiten</x-ui.buttons.button-basic>@endif
        @endif
    @elseif($item->kind === 'task' && $item->status === 'open' && data_get($item,'can_complete',false))<x-ui.buttons.button-basic type="button" wire:click="completeTask({{ $item->record_id }},{{ $item->revision }})" wire:loading.attr="disabled">Erledigen</x-ui.buttons.button-basic>
    @elseif($item->kind === 'report')@if($item->status === 'active')<x-ui.buttons.button-basic type="button" wire:click="runReport({{ $item->record_id }},{{ $item->revision }})" wire:loading.attr="disabled">Auswerten</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="reportState({{ $item->record_id }},{{ $item->revision }},false)">Pausieren</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic type="button" wire:click="reportState({{ $item->record_id }},{{ $item->revision }},true)">Fortsetzen</x-ui.buttons.button-basic>@endif<x-ui.buttons.button-basic type="button" wire:click="downloadReport({{ $item->record_id }})">CSV</x-ui.buttons.button-basic>
    @elseif($item->kind === 'signature')
        <x-ui.buttons.button-basic type="button" wire:click="downloadSignatureSource({{ $item->record_id }})">Dokumentstand</x-ui.buttons.button-basic>
        @if($personal && $item->status === 'pending_external')<x-ui.buttons.button-basic type="button" wire:click="open('signature_result',{{ $item->record_id }},{{ $item->revision }})">Ergebnis einreichen</x-ui.buttons.button-basic>@endif
        @if(in_array($item->status,['result_submitted','verified_result','rejected']))<x-ui.buttons.button-basic type="button" wire:click="downloadSignature({{ $item->record_id }})">Ergebnis</x-ui.buttons.button-basic>@endif
        @if(!$personal && $item->status === 'result_submitted')<x-ui.buttons.button-basic type="button" wire:click="reviewSignature({{ $item->record_id }},{{ $item->revision }},true)" wire:loading.attr="disabled">Prüfen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" wire:click="reviewSignature({{ $item->record_id }},{{ $item->revision }},false)" wire:loading.attr="disabled">Ablehnen</x-ui.buttons.button-basic>@endif
    @elseif($item->kind === 'emergency')
        @if($personal)<x-ui.buttons.button-basic type="button" wire:click="open('emergency_edit')">Pflegen</x-ui.buttons.button-basic>@elseif(auth()->user()->can('employees.emergency.access'))<x-ui.buttons.button-basic type="button" wire:click="open('emergency_read')">Notfallzugriff</x-ui.buttons.button-basic>@endif
    @elseif($item->kind === 'absence')<x-ui.buttons.button-basic type="button" wire:click="open('absence_review',{{ $item->record_id }},{{ $item->revision }})">Stufe prüfen</x-ui.buttons.button-basic>
    @elseif($item->kind === 'applicant')
        @if(!in_array($item->status,['converted','rejected','withdrawn','erased']))<x-ui.buttons.button-basic type="button" wire:click="open('applicant_stage',{{ $item->record_id }},{{ $item->revision }})">Weiterbearbeiten</x-ui.buttons.button-basic>@endif
        @if($item->status === 'offer' && $userId)<x-ui.buttons.button-basic type="button" wire:click="open('applicant_convert',{{ $item->record_id }},{{ $item->revision }})">Mitarbeiter zuordnen</x-ui.buttons.button-basic>@endif
        @if(in_array($item->status,['rejected','withdrawn']))<x-ui.buttons.button-basic type="button" wire:click="eraseApplicant({{ $item->record_id }},{{ $item->revision }})">Fristgebunden bereinigen</x-ui.buttons.button-basic>@endif
    @elseif($item->kind === 'development' && $item->status === 'open')<x-ui.buttons.button-basic type="button" wire:click="open('feedback',{{ $item->record_id }},{{ $item->revision }})">Gespräch</x-ui.buttons.button-basic>
    @elseif($item->kind === 'survey')
        @if($personal)<x-ui.buttons.button-basic type="button" wire:click="open('survey_response',{{ $item->record_id }},{{ $item->revision }})">Rückmeldung</x-ui.buttons.button-basic>@else<x-ui.buttons.button-basic type="button" wire:click="surveyResult({{ $item->record_id }})">Aggregat</x-ui.buttons.button-basic>@endif
    @elseif($item->kind === 'sickness')<x-ui.buttons.button-basic type="button" wire:click="open('sickness_update',{{ $item->record_id }},{{ $item->revision }})">Nachweisstatus</x-ui.buttons.button-basic>
    @endif
</div>@break
@endswitch
</div>
@endforeach
