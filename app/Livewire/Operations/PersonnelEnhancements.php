<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceApprovalPolicy;
use App\Models\AbsenceRequest;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeEmergencyContact;
use App\Models\PersonnelApplicant;
use App\Models\PersonnelDevelopmentReview;
use App\Models\PersonnelDocumentTemplate;
use App\Models\PersonnelSavedReport;
use App\Models\PersonnelSignatureRequest;
use App\Models\PersonnelSurvey;
use App\Models\PersonnelSurveyResponse;
use App\Models\PersonnelTask;
use App\Models\PersonnelWorkflowTemplate;
use App\Models\RegionalPersonnelCalendar;
use App\Models\SicknessEvidenceWorkflow;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\AbsenceApprovalChainService;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class PersonnelEnhancements extends Component
{
    use WithFileUploads;

    #[Locked]
    public bool $personal = false;

    public string $tab = 'workflows';

    public int $userId = 0;

    public bool $formOpen = false;

    #[Locked]
    public string $formKind = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public int $formUserId = 0;

    public array $form = [];

    public array $display = [];

    public $upload;

    public function mount(bool $personal = false, string $tab = 'workflows'): void
    {
        $this->personal = $personal;
        $this->tab = $tab;
        $this->access();
        $ids = $personal ? null : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.view');
        $this->userId = $personal ? auth()->id() : (int) User::where('role', 'staff')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->value('id');
    }

    private function access(): void
    {
        if ($this->personal) {
            OperationsAccess::own(auth()->user(), auth()->id());
            abort_unless(in_array($this->tab, ['workflows', 'documents', 'emergency', 'development', 'surveys', 'sickness'], true), 403);
            if ($this->userId) {
                abort_unless($this->userId === auth()->id(), 403);
            }
        } else {
            OperationsAccess::authorize(auth()->user(), 'employees.master-data.view');
            abort_unless(in_array($this->tab, ['workflows', 'reports', 'documents', 'emergency', 'approvals', 'calendars', 'recruiting', 'development', 'surveys', 'sickness'], true), 404);
            if ($this->tab === 'recruiting') {
                app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'employees.recruiting.manage');
            }
            if ($this->tab === 'development') {
                OperationsAccess::authorize(auth()->user(), 'employees.development.manage');
            }
            if ($this->tab === 'sickness') {
                OperationsAccess::authorize(auth()->user(), 'employees.master-data.edit');
                OperationsAccess::authorize(auth()->user(), 'operations.absences.review');
            }
            if ($this->userId) {
                app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.master-data.view');
                if ($this->tab === 'development') {
                    app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.development.manage');
                }
                if ($this->tab === 'sickness') {
                    app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.master-data.edit');
                    app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'operations.absences.review');
                }
            }
        }
    }

    public function showTab(string $tab): void
    {
        $this->tab = $tab;
        $this->reset('display', 'form', 'upload');
        $this->formOpen = false;
        $this->access();
    }

    public function updatedUserId(): void
    {
        $this->access();
        $this->reset('display', 'form', 'upload');
        $this->formOpen = false;
    }

    public function open(string $kind, ?int $id = null, int $revision = 0): void
    {
        $this->access();
        abort_unless(app(PersonnelEnhancementService::class)->ready(), 503);
        $allowed = ['workflow_template', 'workflow_run', 'report', 'document_template', 'signature_request', 'signature_result', 'emergency_edit', 'emergency_read', 'approval_policy', 'approval_retire', 'absence_review', 'calendar', 'calendar_apply', 'applicant', 'applicant_stage', 'applicant_convert', 'development', 'feedback', 'survey', 'survey_response', 'sickness', 'sickness_update'];
        abort_unless(in_array($kind, $allowed, true), 404);
        $personalKinds = ['signature_result', 'emergency_edit', 'feedback', 'survey_response', 'sickness_update'];
        abort_if($this->personal && ! in_array($kind, $personalKinds, true), 403);
        if (! $this->personal) {
            if (in_array($kind, ['workflow_template', 'document_template', 'survey'], true)) {
                app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'employees.master-data.edit');
            }
            if (in_array($kind, ['approval_policy', 'approval_retire', 'calendar'], true)) {
                app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'operations.rules.manage');
            }
            if (in_array($kind, ['applicant', 'applicant_stage', 'applicant_convert'], true)) {
                app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'employees.recruiting.manage');
            }
            if (in_array($kind, ['signature_request', 'workflow_run', 'calendar_apply', 'development', 'sickness', 'applicant_convert'], true)) {
                app(PersonnelEnhancementService::class)->access(auth()->user(), $this->userId, true, false);
            }
            if ($kind === 'development') {
                app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.development.manage');
            }
            if ($kind === 'sickness') {
                app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'operations.absences.review');
            }
            if ($kind === 'emergency_read') {
                app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.emergency.access');
            }
            if ($kind === 'report') {
                OperationsAccess::authorize(auth()->user(), 'operations.time.review');
            }
        }
        $this->formKind = $kind;
        $this->recordId = $id;
        $this->revision = $revision;
        $this->formUserId = $this->personal ? auth()->id() : $this->userId;
        $this->reset('upload', 'display');
        $today = now(config('operations.display_timezone'))->toDateString();
        $this->form = ['title' => '', 'note' => '', 'due_on' => now()->addDays(14)->toDateString(), 'anchor_on' => $today, 'type' => 'general', 'steps' => [['title' => '', 'offset_days' => 0, 'assigned_to' => auth()->id(), 'delegate_id' => null, 'escalate_to' => null, 'escalation_days' => 2, 'depends_on' => null]], 'stages' => [['reviewer_id' => auth()->id(), 'delegate_id' => null, 'hours' => 48]], 'kind' => 'vacation', 'minimum_days' => 1, 'maximum_days' => 366, 'team_id' => null, 'from' => now()->startOfMonth()->toDateString(), 'until' => now()->endOfMonth()->toDateString(), 'interval_days' => 0, 'user_ids' => $this->formUserId ? [$this->formUserId] : [], 'year' => now()->addYear()->year, 'dates_text' => '', 'region' => '', 'source' => '', 'unit' => 'days', 'retain_until' => now()->addMonths(6)->toDateString(), 'stage' => 'screening', 'interview_on' => null, 'responsible_id' => auth()->id(), 'threshold' => 5, 'score' => 3, 'action' => 'submitted', 'consent' => false];
        if ($kind === 'emergency_edit') {
            abort_unless($this->personal, 403);
            if ($contact = EmployeeEmergencyContact::where('user_id', auth()->id())->first()) {
                $this->form = app(PersonnelEnhancementService::class)->readEmergency(auth()->user(), '', auth()->user());
                $this->revision = $contact->revision;
            }
        }
        if ($id && in_array($kind, ['signature_result', 'applicant_stage', 'applicant_convert', 'feedback', 'survey_response', 'sickness_update', 'absence_review', 'workflow_run', 'calendar_apply', 'approval_retire'], true)) {
            $record = $this->authorizedRecord($kind, $id);
            abort_unless($record->revision === $revision, 422, 'Eintrag wurde geändert.');
            if ($kind === 'feedback') {
                $this->display = $record->payload;
            }
            if ($kind === 'sickness_update') {
                $this->display = $record->payload;
            }
            if ($kind === 'workflow_run' || $kind === 'calendar_apply') {
                $this->display = $record->payload;
            }
            if ($kind === 'calendar_apply') {
                $this->display += app(PersonnelEnhancementService::class)->calendarImpact($record, User::findOrFail($this->formUserId), auth()->user());
            }
            if ($kind === 'survey_response') {
                $this->display = ['question' => $record->payload['question']];
                $this->form['action'] = 'submit';
            }
            if ($kind === 'applicant_stage') {
                $this->display = $record->payload;
                $this->form['stage'] = ['received' => 'screening', 'screening' => 'interview', 'interview' => 'offer', 'offer' => 'rejected'][$record->status] ?? 'rejected';
            }
            if ($kind === 'absence_review') {
                $this->form['action'] = 'approve';
            }
        }
        $this->formOpen = true;
    }

    private function authorizedRecord(string $kind, int $id)
    {
        $class = match ($kind) {
            'signature_result' => PersonnelSignatureRequest::class,
            'applicant_stage', 'applicant_convert' => PersonnelApplicant::class,
            'feedback' => PersonnelDevelopmentReview::class,
            'survey_response' => PersonnelSurvey::class,
            'sickness_update' => SicknessEvidenceWorkflow::class,
            'absence_review' => AbsenceRequest::class,
            'workflow_run' => PersonnelWorkflowTemplate::class,
            'calendar_apply' => RegionalPersonnelCalendar::class,
            'approval_retire' => AbsenceApprovalPolicy::class,
            default => abort(404),
        };
        $record = $class::findOrFail($id);
        if ($record instanceof AbsenceApprovalPolicy) {
            app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'operations.rules.manage');
        } elseif ($record instanceof PersonnelApplicant) {
            app(PersonnelScopeService::class)->authorizeGlobal(auth()->user(), 'employees.recruiting.manage');
        } elseif ($record instanceof PersonnelSurvey) {
            OperationsAccess::own(auth()->user(), auth()->id());
            abort_unless(in_array(auth()->id(), $record->payload['user_ids'], true), 403);
        } elseif ($record instanceof PersonnelWorkflowTemplate || $record instanceof RegionalPersonnelCalendar) {
            abort_if($this->personal, 403);
            app(PersonnelEnhancementService::class)->access(auth()->user(), $this->formUserId, true, false);
        } else {
            app(PersonnelEnhancementService::class)->access(auth()->user(), (int) $record->user_id);
            abort_if($this->personal && (int) $record->user_id !== auth()->id(), 403);
            if ($record instanceof SicknessEvidenceWorkflow && ! $this->personal) {
                app(PersonnelScopeService::class)->authorize(auth()->user(), (int) $record->user_id, 'employees.master-data.edit');
                app(PersonnelScopeService::class)->authorize(auth()->user(), (int) $record->user_id, 'operations.absences.review');
            }
            if ($record instanceof AbsenceRequest && ! $this->personal) {
                app(PersonnelScopeService::class)->authorize(auth()->user(), (int) $record->user_id, 'operations.absences.review');
                abort_if((int) $record->user_id === auth()->id(), 403);
            }
            if ($record instanceof PersonnelDevelopmentReview && ! $this->personal) {
                app(PersonnelScopeService::class)->authorize(auth()->user(), (int) $record->user_id, 'employees.development.manage');
            }
        }

        return $record;
    }

    public function addStep(): void
    {
        $this->access();
        abort_unless(in_array($this->formKind, ['workflow_template', 'approval_policy'], true) && ! $this->personal, 403);
        $key = $this->formKind === 'approval_policy' ? 'stages' : 'steps';
        abort_unless(count($this->form[$key]) < ($key === 'stages' ? 6 : 30), 422);
        $this->form[$key][] = $key === 'stages' ? ['reviewer_id' => null, 'delegate_id' => null, 'hours' => 48] : ['title' => '', 'offset_days' => 0, 'assigned_to' => null, 'delegate_id' => null, 'escalate_to' => null, 'escalation_days' => 2, 'depends_on' => null];
    }

    public function removeLastStep(): void
    {
        $this->access();
        abort_unless(in_array($this->formKind, ['workflow_template', 'approval_policy'], true) && ! $this->personal, 403);
        $key = $this->formKind === 'approval_policy' ? 'stages' : 'steps';
        if (count($this->form[$key]) > 1) {
            array_pop($this->form[$key]);
        }
    }

    public function save(): void
    {
        $this->access();
        $service = app(PersonnelEnhancementService::class);
        $actor = auth()->user();
        $data = $this->form;
        $employee = $this->formUserId ? User::findOrFail($this->formUserId) : null;
        switch ($this->formKind) {
            case 'workflow_template': $service->createWorkflowTemplate($data, $actor);
                break;
            case 'workflow_run': $service->instantiateWorkflow($this->authorizedRecord('workflow_run', $this->recordId), $employee, $data['anchor_on'], $this->revision, $actor);
                break;
            case 'report': $service->saveReport($data, $actor);
                break;
            case 'document_template': $service->saveDocumentTemplate($data, $actor);
                break;
            case 'signature_request':
                $version = EmployeeDocumentVersion::whereHas('requirement', fn ($q) => $q->where('user_id', $employee?->id))->findOrFail($data['document_version_id'] ?? 0);
                $service->requestSignature($version, $data, $actor);
                break;
            case 'signature_result':
                $this->validate(['upload' => 'required|file|mimes:pdf|max:12288']);
                $service->submitSignatureResult($this->authorizedRecord('signature_result', $this->recordId), $this->revision, $this->upload, $actor);
                break;
            case 'emergency_edit': $service->saveEmergency($actor, $data, $this->revision, $actor);
                break;
            case 'emergency_read':
                $this->display = $service->readEmergency($employee, $data['note'] ?? '', $actor);

                return;
            case 'approval_policy': app(AbsenceApprovalChainService::class)->create($data, $actor);
                break;
            case 'approval_retire': app(AbsenceApprovalChainService::class)->retire($this->authorizedRecord('approval_retire', $this->recordId), $this->revision, $data['note'] ?? '', $actor);
                break;
            case 'absence_review':
                app(PersonnelWorkflowService::class)->absence($this->authorizedRecord('absence_review', $this->recordId), $this->revision, $data['action'], $data['note'] ?? '', $actor);
                break;
            case 'calendar':
                $data['dates'] = array_values(array_filter(preg_split('/[\s,;]+/', $data['dates_text'] ?? '')));
                $service->saveCalendar($data, $actor);
                break;
            case 'calendar_apply': $service->applyCalendar($this->authorizedRecord('calendar_apply', $this->recordId), $employee, $data, $this->revision, $actor);
                break;
            case 'applicant': $service->saveApplicant($data, $actor);
                break;
            case 'applicant_stage': $service->applicantStage($this->authorizedRecord('applicant_stage', $this->recordId), $this->revision, $data, $actor);
                break;
            case 'applicant_convert': $service->convertApplicant($this->authorizedRecord('applicant_convert', $this->recordId), $this->revision, $employee, (bool) ($data['consent'] ?? false), $actor);
                break;
            case 'development': $service->saveDevelopment($employee, $data, $actor);
                break;
            case 'feedback': $service->developmentFeedback($this->authorizedRecord('feedback', $this->recordId), $this->revision, $data['note'] ?? '', (bool) ($data['complete'] ?? false), $actor);
                break;
            case 'survey': $service->createSurvey($data, $actor);
                break;
            case 'survey_response': $service->surveyResponse($this->authorizedRecord('survey_response', $this->recordId), $this->revision, $data['action'], isset($data['score']) ? (int) $data['score'] : null, $actor);
                break;
            case 'sickness':
                $absence = AbsenceRequest::where('user_id', $employee?->id)->findOrFail($data['absence_request_id'] ?? 0);
                $service->openSicknessEvidence($absence, $data['due_on'], $actor);
                break;
            case 'sickness_update': $service->sicknessEvidence($this->authorizedRecord('sickness_update', $this->recordId), $this->revision, $data, $actor);
                break;
            default: abort(403);
        }
        $this->reset('form', 'display', 'upload');
        $this->formOpen = false;
        session()->flash('operations.saved', 'Gespeichert.');
    }

    public function activate(string $kind, int $id, int $revision): void
    {
        $this->access();
        abort_if($this->personal, 403);
        if ($kind === 'approval_policy') {
            app(AbsenceApprovalChainService::class)->activate(AbsenceApprovalPolicy::findOrFail($id), $revision, auth()->user());

            return;
        }
        $class = match ($kind) {
            'workflow_template' => PersonnelWorkflowTemplate::class, 'document_template' => PersonnelDocumentTemplate::class, 'calendar' => RegionalPersonnelCalendar::class, default => abort(404)
        };
        app(PersonnelEnhancementService::class)->activate($class::findOrFail($id), $revision, auth()->user());
    }

    public function runReport(int $id, int $revision): void
    {
        $this->access();
        abort_if($this->personal, 403);
        $this->display = app(PersonnelEnhancementService::class)->runReport(PersonnelSavedReport::findOrFail($id), $revision, auth()->user());
        $this->formKind = 'report_result';
        $this->formOpen = true;
    }

    public function downloadReport(int $id)
    {
        $this->access();
        abort_if($this->personal, 403);
        $results = app(PersonnelEnhancementService::class)->authorizedReportResult(PersonnelSavedReport::findOrFail($id), auth()->user());
        abort_unless($results !== [], 404);
        $result = end($results);

        return response()->streamDownload(function () use ($result): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Zeitraum_von', 'Zeitraum_bis', 'Mitarbeiter_ID', 'Name', 'Soll_Minuten', 'Ist_Minuten', 'Saldo_Minuten', 'Fehlende_Grundlagen', 'Offene_Abwesenheiten'], ';', '"', '');
            foreach ($result['rows'] as $row) {
                $name = preg_match('/^[=+\-@\t\r]/', $row['name']) ? "'".$row['name'] : $row['name'];
                fputcsv($stream, [$result['from'], $result['until'], $row['user_id'], $name, $row['target_minutes'], $row['actual_minutes'], $row['balance_minutes'], $row['missing_days'], $row['pending_absences']], ';', '"', '');
            }
            fclose($stream);
        }, 'Personalbericht.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function reportState(int $id, int $revision, bool $active): void
    {
        $this->access();
        abort_if($this->personal, 403);
        app(PersonnelEnhancementService::class)->reportState(PersonnelSavedReport::findOrFail($id), $revision, $active, auth()->user());
    }

    public function downloadDocumentTemplate(int $id)
    {
        $this->access();
        abort_if($this->personal, 403);
        $text = app(PersonnelEnhancementService::class)->renderDocument(PersonnelDocumentTemplate::findOrFail($id), User::findOrFail($this->userId), auth()->user());

        return response()->streamDownload(fn () => print ($text), 'Personalvorlage.txt', ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function reviewSignature(int $id, int $revision, bool $accept): void
    {
        $this->access();
        abort_if($this->personal, 403);
        app(PersonnelEnhancementService::class)->reviewSignatureResult(PersonnelSignatureRequest::findOrFail($id), $revision, $accept, auth()->user());
    }

    public function downloadSignature(int $id)
    {
        $this->access();

        return app(PersonnelEnhancementService::class)->signatureResult(PersonnelSignatureRequest::findOrFail($id), auth()->user());
    }

    public function downloadSignatureSource(int $id)
    {
        $this->access();

        return app(PersonnelEnhancementService::class)->signatureSource(PersonnelSignatureRequest::findOrFail($id), auth()->user());
    }

    public function completeTask(int $id, int $revision): void
    {
        $this->access();
        $task = PersonnelTask::findOrFail($id);
        abort_if($this->personal && (int) $task->user_id !== auth()->id(), 403);
        app(PersonnelProcessService::class)->completeTask($task, $revision, '', auth()->user());
    }

    public function surveyResult(int $id): void
    {
        $this->access();
        abort_if($this->personal, 403);
        $this->display = app(PersonnelEnhancementService::class)->surveyAggregate(PersonnelSurvey::findOrFail($id), auth()->user());
        $this->formKind = 'survey_result';
        $this->formOpen = true;
    }

    public function eraseApplicant(int $id, int $revision): void
    {
        $this->access();
        abort_if($this->personal, 403);
        app(PersonnelEnhancementService::class)->eraseApplicant(PersonnelApplicant::findOrFail($id), $revision, auth()->user());
    }

    private function related(string $class)
    {
        $query = $class::where('user_id', $this->userId);
        if (! $this->personal) {
            app(PersonnelScopeService::class)->applyRelatedQuery($query, auth()->user(), 'employees.master-data.view');
        }

        return $query->orderByDesc('id')->limit(60)->get();
    }

    private function recordRows(): Collection
    {
        $records = collect();
        $actor = auth()->user();
        $service = app(PersonnelEnhancementService::class);
        if ($this->tab === 'workflows') {
            if (! $this->personal && Gate::forUser($actor)->allows('employees.master-data.edit') && app(PersonnelScopeService::class)->visibleUserIds($actor, 'employees.master-data.edit') === null) {
                foreach (PersonnelWorkflowTemplate::latest()->limit(30)->get() as $record) {
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
            if (! $this->personal && Gate::forUser($actor)->allows('employees.master-data.edit') && app(PersonnelScopeService::class)->visibleUserIds($actor, 'employees.master-data.edit') === null) {
                foreach (PersonnelDocumentTemplate::latest()->limit(30)->get() as $record) {
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

        return $records;
    }

    private function row($record, string $kind, string $label, ?string $detail, ?string $status = null): object
    {
        $canComplete = $record instanceof PersonnelTask && ((int) $record->assigned_to === auth()->id() || auth()->user()->isAdmin() || app(PersonnelEnhancementService::class)->delegateCanComplete($record, auth()->user()));
        $canActivate = ! $this->personal && (int) $record->created_by !== auth()->id() && auth()->user()->can(in_array($kind, ['calendar', 'approval_policy'], true) ? 'operations.rules.manage' : 'employees.master-data.edit');

        return (object) ['id' => $kind.':'.$record->id, 'record_id' => $record->id, 'kind' => $kind, 'label' => $label, 'detail' => $detail, 'status' => $status ?? $record->status, 'revision' => $record->revision, 'personal_view' => $this->personal, 'context_user_id' => $this->userId, 'can_complete' => $canComplete, 'can_activate' => $canActivate];
    }

    public function render()
    {
        $this->access();
        $ready = app(PersonnelEnhancementService::class)->ready();
        $ids = $this->personal ? [auth()->id()] : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.view');
        $employees = User::where('role', 'staff')->where('status', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']);
        $employee = $this->userId ? User::findOrFail($this->userId) : null;
        $canEdit = ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit');
        $canGlobal = ! $this->personal && Gate::forUser(auth()->user())->allows('employees.master-data.edit') && app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.edit') === null;

        return view('livewire.operations.personnel-enhancements', ['ready' => $ready, 'records' => $ready ? $this->recordRows() : collect(), 'employees' => $employees, 'employee' => $employee, 'canEdit' => $canEdit, 'canGlobal' => $canGlobal, 'teams' => ! $this->personal && $this->formKind === 'approval_policy' ? Team::where('personal_team', false)->orderBy('name')->get(['id', 'name']) : collect(), 'assignees' => $this->personal ? collect() : User::where('status', true)->orderBy('name')->get(['id', 'name']), 'versions' => $ready && $this->formKind === 'signature_request' ? EmployeeDocumentVersion::whereHas('requirement', fn ($q) => $q->where('user_id', $this->formUserId))->with('requirement')->orderByDesc('id')->get() : collect(), 'sicknesses' => $ready && $this->formKind === 'sickness' ? AbsenceRequest::where('user_id', $this->formUserId)->where('kind', 'sick')->where('status', 'reported')->latest()->get() : collect()]);
    }
}
