<?php

namespace App\Livewire\Operations;

use App\Livewire\Operations\Concerns\AuthorizesPersonnelProfileScope;
use App\Livewire\Operations\Concerns\ListsPersonnelRecords;
use App\Livewire\Operations\Concerns\ManagesPersonnelReports;
use App\Livewire\Operations\Concerns\ManagesPersonnelSignatures;
use App\Models\AbsenceApprovalPolicy;
use App\Models\AbsenceRequest;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeEmergencyContact;
use App\Models\PersonnelApplicant;
use App\Models\PersonnelDevelopmentReview;
use App\Models\PersonnelDocumentTemplate;
use App\Models\PersonnelSignatureRequest;
use App\Models\PersonnelSurvey;
use App\Models\PersonnelTask;
use App\Models\PersonnelWorkflowRun;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Personalbereich eines Mitarbeiters: Prozesse, Unterlagen, Notfallkontakt, Freigaben,
 * Regionalkalender, Bewerbungen, Entwicklung, Rückmeldungen und Nachweisstatus.
 *
 * Die Reiter teilen Mitarbeiterauswahl, Formulardialog und Profilbindung und bleiben
 * deshalb eine Komponente. Fachlich getrennt liegen in Concerns:
 *   - ListsPersonnelRecords           (Listenzeilen je Reiter)
 *   - ManagesPersonnelReports         (Berichte ausführen, laden, aktivieren)
 *   - ManagesPersonnelSignatures      (Dokumentvorlagen und Unterzeichnungen)
 *   - AuthorizesPersonnelProfileScope (Bindung an das Mitarbeiterprofil)
 */
class PersonnelEnhancements extends Component
{
    use AuthorizesPersonnelProfileScope, ListsPersonnelRecords, ManagesPersonnelReports, ManagesPersonnelSignatures, WithFileUploads;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public ?int $profileUserId = null;

    #[Locked]
    public ?string $profileTab = null;

    #[Locked]
    public ?int $focusedRecordId = null;

    #[Locked]
    public string $focusedRecordType = '';

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

    public function mount(bool $personal = false, string $tab = 'workflows', bool $embedded = false, ?int $initialUserId = null, ?int $initialRecordId = null, string $initialRecordType = '', ?int $profileUserId = null): void
    {
        $this->personal = $personal;
        $this->embedded = $embedded;
        $this->tab = $tab;
        $this->profileUserId = $profileUserId;
        $this->profileTab = $profileUserId !== null ? $tab : null;
        abort_if($profileUserId !== null && $initialUserId !== null && $profileUserId !== $initialUserId, 403);
        if ($profileUserId !== null) {
            $this->userId = $profileUserId;
        }
        $this->access();
        $ids = $personal ? null : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.view');
        $this->userId = $profileUserId ?? ($personal ? auth()->id() : ($initialUserId ?? (int) User::where('role', 'staff')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->value('id')));
        $this->access();
        if ($initialRecordId) {
            $this->focusedRecordId = $initialRecordId;
            $this->focusedRecordType = $initialRecordType ?: match ($tab) {
                'workflows' => 'task', 'documents' => 'signature', 'sickness' => 'sickness', 'approvals' => 'absence', 'recruiting' => 'applicant', 'development' => 'development', 'surveys' => 'survey', 'reports' => 'report', 'calendars' => 'calendar', default => abort(404),
            };
            if ($this->focusedRecordType === 'workflow-run') {
                abort_unless($tab === 'workflows', 404);
                $run = PersonnelWorkflowRun::where('user_id', $this->userId)->findOrFail($initialRecordId);
                app(PersonnelEnhancementService::class)->access(auth()->user(), (int) $run->user_id);
            } else {
                abort_unless($this->recordRows()->contains(fn ($row) => (int) $row->record_id === $initialRecordId && $row->kind === $this->focusedRecordType), 404);
            }
        }
    }

    private function access(): void
    {
        if ($this->profileUserId !== null) {
            abort_unless($this->embedded && ! $this->personal && $this->profileUserId > 0 && $this->userId === $this->profileUserId && $this->tab === $this->profileTab && in_array($this->tab, ['workflows', 'documents', 'emergency', 'development', 'sickness'], true), 403);
        }
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
                if ($this->profileUserId !== null) {
                    abort_unless(User::where('role', 'staff')->whereKey($this->profileUserId)->exists(), 404);
                    if ($this->tab === 'emergency') {
                        app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.emergency.access');
                    }
                }
            }
        }
    }

    public function showTab(string $tab): void
    {
        abort_if($this->profileUserId !== null && $tab !== $this->profileTab, 403);
        $this->tab = $tab;
        $this->reset('focusedRecordId', 'focusedRecordType');
        $this->reset('display', 'form', 'upload');
        $this->formOpen = false;
        $this->access();
    }

    public function updatedUserId(): void
    {
        $this->access();
        $this->reset('focusedRecordId', 'focusedRecordType');
        $this->reset('display', 'form', 'upload');
        $this->formOpen = false;
    }

    public function open(string $kind, ?int $id = null, int $revision = 0): void
    {
        $this->access();
        $this->authorizeProfileAction($kind);
        abort_unless(app(PersonnelEnhancementService::class)->ready(), 503);
        $allowed = ['workflow_template', 'workflow_run', 'report', 'document_template', 'signature_request', 'signature_result', 'emergency_edit', 'emergency_read', 'approval_policy', 'approval_retire', 'absence_review', 'calendar', 'calendar_apply', 'applicant', 'applicant_stage', 'applicant_convert', 'development', 'feedback', 'survey', 'survey_response', 'sickness', 'sickness_update', 'sickness_report'];
        abort_unless(in_array($kind, $allowed, true), 404);
        $personalKinds = ['signature_result', 'emergency_edit', 'feedback', 'survey_response', 'sickness_update', 'sickness_report'];
        abort_if($this->personal && ! in_array($kind, $personalKinds, true), 403);
        if ($kind === 'sickness_report') {
            abort_unless($this->personal && $this->tab === 'sickness' && $id === null, 403);
        }
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
        if ($kind === 'sickness_report') {
            $this->form = ['starts_at' => '', 'ends_at' => '', 'timezone' => config('operations.display_timezone', 'Europe/Berlin')];
        }
        $this->resetValidation();
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
            $this->authorizeProfileRecord($record);
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
        $this->authorizeProfileAction($this->formKind);
        abort_if($this->profileUserId !== null && $this->formUserId !== $this->profileUserId, 403);
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
            case 'sickness_report':
                abort_unless($this->personal && $this->tab === 'sickness' && $this->formUserId === $actor->id, 403);
                try {
                    app(PersonnelWorkflowService::class)->reportSickness($actor, $data);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [array_key_exists($key, $data) ? 'form.'.$key : $key => $messages])->all());
                }
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
        $this->resetValidation();
        $this->formOpen = false;
        session()->flash('operations.saved', 'Gespeichert.');
    }

    public function activate(string $kind, int $id, int $revision): void
    {
        $this->access();
        abort_if($this->profileUserId !== null, 403);
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

    public function completeTask(int $id, int $revision): void
    {
        $this->access();
        $this->authorizeProfileAction('task_complete');
        $task = PersonnelTask::findOrFail($id);
        $this->authorizeProfileRecord($task);
        abort_if($this->personal && (int) $task->user_id !== auth()->id(), 403);
        app(PersonnelProcessService::class)->completeTask($task, $revision, '', auth()->user());
    }

    public function surveyResult(int $id): void
    {
        $this->access();
        abort_if($this->profileUserId !== null, 403);
        abort_if($this->personal, 403);
        $this->display = app(PersonnelEnhancementService::class)->surveyAggregate(PersonnelSurvey::findOrFail($id), auth()->user());
        $this->formKind = 'survey_result';
        $this->formOpen = true;
    }

    public function eraseApplicant(int $id, int $revision): void
    {
        $this->access();
        abort_if($this->profileUserId !== null, 403);
        abort_if($this->personal, 403);
        app(PersonnelEnhancementService::class)->eraseApplicant(PersonnelApplicant::findOrFail($id), $revision, auth()->user());
    }

    public function render()
    {
        $this->access();
        $ready = app(PersonnelEnhancementService::class)->ready();
        $ids = $this->personal ? [auth()->id()] : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.view');
        $employees = $this->embedded ? collect() : User::where('role', 'staff')->where('status', true)->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']);
        $employee = $this->userId ? User::findOrFail($this->userId) : null;
        $canEdit = ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit');
        $canGlobal = $this->profileUserId === null && ! $this->personal && Gate::forUser(auth()->user())->allows('employees.master-data.edit') && app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.edit') === null;

        return view('livewire.operations.personnel-enhancements', ['ownSicknesses' => $ready && $this->personal && $this->tab === 'sickness' ? AbsenceRequest::where('user_id', auth()->id())->where('kind', 'sick')->latest('starts_at')->limit(30)->get() : collect(), 'ready' => $ready, 'records' => $ready ? $this->recordRows() : collect(), 'employees' => $employees, 'employee' => $employee, 'canEdit' => $canEdit, 'canGlobal' => $canGlobal, 'teams' => ! $this->personal && $this->formOpen && $this->formKind === 'approval_policy' ? Team::where('personal_team', false)->orderBy('name')->get(['id', 'name']) : collect(), 'assignees' => $this->personal || ! $this->formOpen ? collect() : User::where('status', true)->orderBy('name')->get(['id', 'name']), 'versions' => $ready && $this->formOpen && $this->formKind === 'signature_request' ? EmployeeDocumentVersion::whereHas('requirement', fn ($q) => $q->where('user_id', $this->formUserId))->with('requirement')->orderByDesc('id')->get() : collect(), 'sicknesses' => $ready && $this->formOpen && $this->formKind === 'sickness' ? AbsenceRequest::where('user_id', $this->formUserId)->where('kind', 'sick')->where('status', 'reported')->latest()->get() : collect()]);
    }
}
