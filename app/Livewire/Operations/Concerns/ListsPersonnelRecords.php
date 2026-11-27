<?php

namespace App\Livewire\Operations\Concerns;

use App\Models\AbsenceApprovalPolicy;
use App\Models\AbsenceRequest;
use App\Models\EmployeeEmergencyContact;
use App\Models\PersonnelApplicant;
use App\Models\PersonnelDevelopmentReview;
use App\Models\PersonnelDocumentTemplate;
use App\Models\PersonnelSavedReport;
use App\Models\PersonnelSignatureRequest;
use App\Models\PersonnelSurvey;
use App\Models\PersonnelSurveyResponse;
use App\Models\PersonnelTask;
use App\Models\PersonnelWorkflowStep;
use App\Models\PersonnelWorkflowTemplate;
use App\Models\RegionalPersonnelCalendar;
use App\Models\SicknessEvidenceWorkflow;
use App\Services\Operations\AbsenceApprovalChainService;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelScopeService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * PersonnelEnhancements – Listenzeilen je Reiter (Prozesse, Berichte, Unterlagen,
 * Notfallkontakt, Freigaben, Kalender, Bewerbungen, Entwicklung, Rückmeldungen,
 * Nachweisstatus) samt Fokus auf einen einzelnen Vorgang.
 */
trait ListsPersonnelRecords
{
    private function related(string $class)
    {
        $query = $class::where('user_id', $this->userId);
        if (! $this->personal) {
            app(PersonnelScopeService::class)->applyRelatedQuery($query, auth()->user(), 'employees.master-data.view');
        }

        if ($this->focusedRecordId) {
            if ($this->focusedRecordType === 'workflow-run' && $class === PersonnelTask::class) {
                $query->whereIn('id', PersonnelWorkflowStep::where('run_id', $this->focusedRecordId)->select('task_id'));
            } else {
                $query->whereKey($this->focusedRecordId);
            }
        }

        return $query->orderByDesc('id')->limit(60)->get();
    }

    private function recordRows(): Collection
    {
        $records = collect();
        $actor = auth()->user();
        $service = app(PersonnelEnhancementService::class);
        if ($this->tab === 'workflows') {
            if (! $this->focusedRecordId && ! $this->personal && Gate::forUser($actor)->allows('employees.master-data.edit') && app(PersonnelScopeService::class)->visibleUserIds($actor, 'employees.master-data.edit') === null) {
                foreach (PersonnelWorkflowTemplate::when($this->profileUserId !== null, fn ($query) => $query->where('status', 'active'))->latest()->limit(30)->get() as $record) {
                    $records->push($this->row($record, 'workflow_template', $record->title, count($record->payload['steps']).' Schritte'));
                }
            }
            foreach ($this->related(PersonnelTask::class) as $task) {
                if ($this->personal && (int) $task->assigned_to !== auth()->id() && ! $service->delegateCanComplete($task, $actor)) {
                    continue;
                }
                $records->push($this->row($task, 'task', $task->title, $task->due_on ?: '—'));
            }
        } elseif ($this->tab === 'reports') {
            foreach (PersonnelSavedReport::where('created_by', $actor->id)->latest()->limit(60)->get() as $record) {
                // Re-check every private target before displaying even a saved report title.
                $service->authorizedReportResult($record, $actor);
                $records->push($this->row($record, 'report', $record->title, $record->payload['from'].' – '.$record->payload['until']));
            }
        } elseif ($this->tab === 'documents') {
            if (! $this->focusedRecordId && ! $this->personal && Gate::forUser($actor)->allows('employees.master-data.edit') && app(PersonnelScopeService::class)->visibleUserIds($actor, 'employees.master-data.edit') === null) {
                foreach (PersonnelDocumentTemplate::when($this->profileUserId !== null, fn ($query) => $query->where('status', 'active'))->latest()->limit(30)->get() as $record) {
                    $records->push($this->row($record, 'document_template', $record->title, 'Vorlage'));
                }
            }
            foreach ($this->related(PersonnelSignatureRequest::class) as $record) {
                $records->push($this->row($record, 'signature', $record->title, $record->due_on));
            }
        } elseif ($this->tab === 'emergency') {
            if ($contact = EmployeeEmergencyContact::where('user_id', $this->userId)->first()) {
                $records->push($this->row($contact, 'emergency', 'Notfallkontakt', $contact->confirmed_at->format('d.m.Y'), 'present'));
            }
        } elseif ($this->tab === 'approvals') {
            foreach (app(AbsenceApprovalChainService::class)->inbox($actor) as $item) {
                $records->push((object) ['id' => 'absence:'.$item['id'], 'record_id' => $item['id'], 'kind' => 'absence', 'label' => $item['title'], 'detail' => $item['due_on'], 'status' => $item['status'], 'revision' => $item['revision'], 'personal_view' => false, 'context_user_id' => $this->userId]);
            }
            if (Gate::forUser($actor)->allows('operations.rules.manage') && app(PersonnelScopeService::class)->visibleUserIds($actor, 'operations.rules.manage') === null) {
                foreach (AbsenceApprovalPolicy::latest()->limit(30)->get() as $record) {
                    $records->push($this->row($record, 'approval_policy', $record->title, count($record->payload['stages']).' Stufen'));
                }
            }
        } elseif ($this->tab === 'calendars') {
            OperationsAccess::authorize($actor, 'operations.rules.manage');
            foreach (RegionalPersonnelCalendar::latest()->limit(60)->get() as $record) {
                $p = $record->payload;
                $records->push($this->row($record, 'calendar', $record->title, $p['region'].' · '.$p['year'].' · '.count($p['dates']).' Daten'));
            }
        } elseif ($this->tab === 'recruiting') {
            foreach (PersonnelApplicant::latest()->limit(60)->get() as $record) {
                $records->push($this->row($record, 'applicant', $record->payload['name'] ?? 'Bereinigt', $record->title.' · '.$record->due_on));
            }
        } elseif ($this->tab === 'development') {
            foreach ($this->related(PersonnelDevelopmentReview::class) as $record) {
                $records->push($this->row($record, 'development', $record->title, $record->due_on));
            }
        } elseif ($this->tab === 'surveys') {
            foreach (PersonnelSurvey::latest()->limit(60)->get() as $record) {
                if ($this->personal) {
                    if (! in_array(auth()->id(), $record->payload['user_ids'], true)) {
                        continue;
                    }
                    $response = PersonnelSurveyResponse::where('survey_id', $record->id)->where('user_id', auth()->id())->first();
                    $records->push($this->row($record, 'survey', $record->title, $record->payload['until'], $response?->status ?? 'open'));
                } elseif ((int) $record->created_by === (int) $actor->id || $actor->isAdmin()) {
                    $records->push($this->row($record, 'survey', $record->title, $record->payload['until']));
                }
            }
        } else {
            foreach ($this->related(SicknessEvidenceWorkflow::class) as $record) {
                if (! $this->personal) {
                    $service->access($actor, (int) $record->user_id, true, false);
                    app(PersonnelScopeService::class)->authorize($actor, (int) $record->user_id, 'operations.absences.review');
                }
                $absence = AbsenceRequest::find($record->absence_request_id);
                $status = $record->status === 'covered' && $absence?->revision !== ($record->payload['absence_revision'] ?? null) ? 'needs_review' : $record->status;
                $records->push($this->row($record, 'sickness', 'Nachweisprüfung', $record->due_on, $status));
            }
        }

        return $this->focusedRecordId && $this->focusedRecordType !== 'workflow-run'
            ? $records->filter(fn ($record) => (int) $record->record_id === $this->focusedRecordId && $record->kind === $this->focusedRecordType)->values()
            : $records;
    }

    private function row($record, string $kind, string $label, ?string $detail, ?string $status = null): object
    {
        $canComplete = $record instanceof PersonnelTask && ((int) $record->assigned_to === auth()->id() || auth()->user()->isAdmin() || app(PersonnelEnhancementService::class)->delegateCanComplete($record, auth()->user()));
        $canActivate = $this->profileUserId === null && ! $this->personal && (int) $record->created_by !== auth()->id() && auth()->user()->can(in_array($kind, ['calendar', 'approval_policy'], true) ? 'operations.rules.manage' : 'employees.master-data.edit');

        return (object) ['id' => $kind.':'.$record->id, 'record_id' => $record->id, 'kind' => $kind, 'label' => $label, 'detail' => $detail, 'status' => $status ?? $record->status, 'revision' => $record->revision, 'personal_view' => $this->personal, 'context_user_id' => $this->userId, 'can_complete' => $canComplete, 'can_activate' => $canActivate];
    }
}
