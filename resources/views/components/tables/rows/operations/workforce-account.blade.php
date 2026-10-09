@php
    $model = $item instanceof \App\Models\EmployeeWorkModel;
    $policy = $item instanceof \App\Models\EmployeeVacationPolicy;
    $rule = $item instanceof \App\Models\EmployeeRuleAssignment;
    $task = $item instanceof \App\Models\PersonnelTask;
    $training = $item instanceof \App\Models\PersonnelTrainingParticipant;
    $absence = $item instanceof \App\Models\AbsenceRequest;
    $entry = $item instanceof \App\Models\WorkforceAccountEntry;
    $responsibility = $item instanceof \App\Models\PersonnelResponsibility;
    $planReview = $item instanceof \App\Models\PersonnelPlanReview;
    $rowEditable = !$this->personal && ($model || $policy || $entry || $task || $planReview) && app(\App\Services\Operations\PersonnelScopeService::class)->allows(auth()->user(), (int) $item->user_id, 'employees.master-data.edit');
    $rowTrainingEditable = !$this->personal && $training && app(\App\Services\Operations\PersonnelScopeService::class)->allows(auth()->user(), (int) $item->user_id, 'operations.qualifications.manage');
    $rowAbsenceReviewable = !$this->personal && $absence && (int) $item->user_id !== (int) auth()->id() && app(\App\Services\Operations\PersonnelScopeService::class)->allows(auth()->user(), (int) $item->user_id, 'operations.absences.review');
    $label = $model || $policy ? $item->name : ($rule ? $item->profile?->name : ($task ? $item->title : ($training ? $item->training?->title : ($absence ? (['vacation'=>'Urlaub','sick'=>'Krankmeldung','unavailable'=>'Nicht verfügbar','other'=>'Abwesenheit'][$item->kind] ?? 'Abwesenheit') : ($responsibility ? $item->responsible?->name : (['grant'=>'Anspruch','carry'=>'Übertrag','manual'=>'Gutschrift','correction'=>'Anspruchskorrektur','adjustment'=>'Zeitkorrektur'][$item->kind] ?? $item->kind))))));
    $status = ['draft'=>'Entwurf','active'=>'Aktiv','open'=>'Offen','done'=>'Erledigt','cancelled'=>'Storniert','confirmed'=>'Bestätigt','attended'=>'Teilgenommen','pending'=>'Ausstehend','approved'=>'Genehmigt','reported'=>'Gemeldet','withdrawn'=>'Zurückgenommen','rejected'=>'Abgelehnt'][$item->status ?? ''] ?? '';
    if ($planReview) {
        $label = $item->latest_snapshot['shift']['title'] ?? $item->shift?->title ?? 'Dienst';
        $status = ['review'=>'Prüfung erforderlich','conflict'=>'Konflikt','stale'=>'Plan geändert','resolved'=>'Geklärt'][$item->status] ?? $item->status;
    }
@endphp
@foreach($columnsMeta as $column)
    <div class="min-w-0 px-2 py-1.5 {{ $hideClass($column['hideOn']) }}">
        @if($column['key'] === 'label')<p class="font-medium">{{ $label }}</p>@if($entry)<p class="text-xs text-rt-muted">{{ $item->note }}</p>@endif
            @if($planReview)@foreach($item->latest_snapshot['issues'] ?? [] as $issue)<p class="text-xs text-rt-muted">{{ $issue['message'] }}</p>@endforeach @endif
        @elseif($column['key'] === 'value')
            @if($model || $policy || $rule || $responsibility){{ $item->starts_on }} – {{ $item->ends_on ?: 'offen' }}@if($model)<p class="text-xs text-rt-muted">{{ $item->weekly_target_minutes }} min / Woche</p>@endif
            @elseif($entry){{ $item->effective_on }} · {{ $item->unit === 'days' ? number_format($item->quantity / 100,2,',','.').' Tage' : $item->quantity.' min' }}@if($item->expires_on)<p class="text-xs text-rt-muted">Ablauf {{ $item->expires_on }}</p>@endif
            @elseif($task){{ $item->due_on ?: '—' }}<p class="text-xs text-rt-muted">{{ $item->assignee?->name }}</p>
            @elseif($training){{ $item->training?->starts_at?->format('d.m.Y H:i') }} – {{ $item->training?->ends_at?->format('H:i') }}
            @elseif($absence){{ $item->starts_at->format('d.m.Y H:i') }} – {{ $item->ends_at->format('d.m.Y H:i') }}@endif
            @if($planReview){{ $item->shift?->starts_at?->format('d.m.Y H:i') }}<p class="text-xs text-rt-muted">Plan R{{ $item->latest_snapshot['shift']['published_revision'] ?? $item->plan_revision }} · Prüfung R{{ $item->revision }}</p><p class="text-xs text-rt-muted">{{ ['work_model'=>'Arbeitsmodell','rule_assignment'=>'Regelzuordnung','global_rules'=>'Regelprofil'][$item->origin_type] ?? 'Konfiguration' }} #{{ $item->origin_id }} · R{{ $item->origin_revision }}</p>@endif
        @elseif($column['key'] === 'status'){{ $status ?: ($rule || $responsibility ? 'Zugeordnet' : 'Gebucht') }}
        @elseif($column['key'] === 'actions')
            <div class="ops-actions">
                @if($rowEditable && ($model || $policy))
                    @if($item->status === 'draft' && $item->created_by !== auth()->id())<x-ui.buttons.button-basic type="button" wire:click="activate('{{ $model ? 'model' : 'policy' }}',{{ $item->id }},{{ $item->revision }})">Freigeben</x-ui.buttons.button-basic>@elseif($item->status === 'active')<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('{{ $model ? 'end_model' : 'end_policy' }}',{{ $item->id }},{{ $item->revision }})">Beenden</x-ui.buttons.button-basic>@endif
                @endif
                @if($task && $item->status === 'open' && ($item->assigned_to === auth()->id() || auth()->user()->isAdmin()))<x-ui.buttons.button-basic type="button" wire:click="completeTask({{ $item->id }},{{ $item->revision }})">Erledigt</x-ui.buttons.button-basic>@endif
                @if($rowEditable && $task && $item->status === 'open')<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('task_cancel',{{ $item->id }},{{ $item->revision }})">Stornieren</x-ui.buttons.button-basic>@endif
                @if($rowTrainingEditable && $item->status === 'confirmed')<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('participation',{{ $item->id }},{{ $item->revision }})">Bearbeiten</x-ui.buttons.button-basic>@if($this->profileUserId === null)<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('training_cancel',{{ $item->personnel_training_id }},{{ $item->training->revision }})">Schulung stornieren</x-ui.buttons.button-basic>@endif @endif
                @if($rowAbsenceReviewable && $item->kind === 'sick' && $item->status === 'reported')<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('sickness_correction',{{ $item->id }},{{ $item->revision }})">Korrigieren</x-ui.buttons.button-basic>@endif
                @if($rowAbsenceReviewable && $item->kind === 'vacation' && $item->status === 'approved' && app(\App\Services\Operations\PersonnelScopeService::class)->allows(auth()->user(), (int) $item->user_id, 'employees.master-data.edit') && !\App\Models\VacationReservation::where('absence_request_id',$item->id)->exists())<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('adopt_absence',{{ $item->id }},{{ $item->revision }})">Konto zuordnen</x-ui.buttons.button-basic>@endif
                @if($rowEditable && $entry && $item->account === 'vacation' && $item->quantity > 0)<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('reduce_credit',{{ $item->id }},1)">Korrigieren</x-ui.buttons.button-basic>@endif
                @if($rowEditable && $planReview && $item->status !== 'resolved' && (int) auth()->id() !== (int) $item->user_id)<x-ui.buttons.button-basic type="button" wire:click="prepareRecord('plan_review',{{ $item->id }},{{ $item->revision }})">Neu prüfen</x-ui.buttons.button-basic>@endif
            </div>
        @endif
    </div>
@endforeach
