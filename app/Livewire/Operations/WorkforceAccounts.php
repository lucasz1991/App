<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeRuleAssignment;
use App\Models\EmployeeVacationPolicy;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\PersonnelPlanReview;
use App\Models\PersonnelResponsibility;
use App\Models\PersonnelTask;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\QualificationType;
use App\Models\User;
use App\Models\WorkforceAccountEntry;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkforcePlanReviewService;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class WorkforceAccounts extends Component
{
    use WithPagination;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public bool $processOnly = false;

    public int $userId = 0;

    public string $tab = 'account';

    public string $from = '';

    public string $until = '';

    public bool $formOpen = false;

    #[Locked]
    public string $formKind = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public string $correctionUnit = '';

    public array $form = [];

    public function mount(bool $personal = false): void
    {
        $this->personal = $personal;
        $this->from = now(config('operations.display_timezone'))->startOfMonth()->toDateString();
        $this->until = now(config('operations.display_timezone'))->endOfMonth()->toDateString();
        $ids = $personal ? null : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.view');
        $this->userId = $personal ? auth()->id() : (int) User::where('role', 'staff')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->value('id');
        $this->access();
    }

    private function access(): void
    {
        if ($this->personal) {
            OperationsAccess::own(auth()->user(), $this->userId);
        } else {
            OperationsAccess::authorize(auth()->user(), 'employees.master-data.view');
            if ($this->userId) {
                app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, 'employees.master-data.view');
            }
        }
        abort_unless(in_array($this->tab, ['account', 'models', 'policies', 'rules', 'checks', 'absences', 'tasks', 'training', 'responsibilities'], true), 404);
        if ($this->personal) {
            abort_unless(in_array($this->tab, ['account', 'absences', 'tasks', 'training'], true), 403);
        }
        if (! $this->personal && in_array($this->tab, ['tasks', 'training'], true)) {
            OperationsAccess::authorize(auth()->user(), $this->tab === 'tasks' ? 'employees.master-data.view' : 'operations.qualifications.manage');
        }
        if ($this->tab === 'responsibilities') {
            abort_unless(! $this->personal && auth()->user()->isAdmin(), 403);
        }
    }

    private function employee(): User
    {
        $this->access();

        return User::where('role', 'staff')->findOrFail($this->userId);
    }

    public function showTab(string $tab): void
    {
        $this->access();
        abort_unless(in_array($tab, $this->personal ? ['account', 'absences', 'tasks', 'training'] : ($this->processOnly ? ['tasks', 'training'] : ['account', 'models', 'policies', 'rules', 'checks', 'absences', 'responsibilities']), true), 404);
        $this->tab = $tab;
        $this->access();
        $this->resetPage('workforceRecordsPage');
        $this->formOpen = false;
        $this->resetValidation();
    }

    public function updatedUserId(): void
    {
        $this->access();
        $this->employee();
        $this->resetPage('workforceRecordsPage');
        $this->formOpen = false;
        $this->resetValidation();
    }

    public function openForm(string $kind, ?int $id = null): void
    {
        $this->access();
        abort_unless(in_array($kind, $this->personal ? ['vacation', 'sick'] : ['model', 'policy', 'credit', 'adjustment', 'rule', 'task', 'training', 'enroll', 'participation', 'end_model', 'end_policy', 'reduce_credit', 'task_cancel', 'training_cancel', 'sickness_correction', 'responsibility', 'adopt_absence', 'plan_review'], true), 403);
        $this->employee();
        $this->formKind = $kind;
        $this->recordId = $id;
        $today = now(config('operations.display_timezone'))->toDateString();
        $this->form = match ($kind) {
            'model' => ['name' => '', 'starts_on' => $today, 'ends_on' => '', 'timezone' => config('operations.display_timezone'), 'weekly_target_minutes' => '', 'maximum_weekly_minutes' => '', 'daily_minutes' => array_fill_keys(range(1, 7), ''), 'window_start' => array_fill_keys(range(1, 7), ''), 'window_end' => array_fill_keys(range(1, 7), ''), 'window_start_2' => array_fill_keys(range(1, 7), ''), 'window_end_2' => array_fill_keys(range(1, 7), ''), 'valuation_percent' => array_fill_keys(['work', 'preparation', 'driving', 'shunting', 'travel', 'waiting', 'on_call', 'break', 'internal', 'training'], '')],
            'policy' => ['name' => '', 'starts_on' => $today, 'ends_on' => '', 'unit' => '', 'calendar_version' => '', 'holiday_region' => '', 'non_working_dates_text' => ''],
            'credit' => ['effective_on' => $today, 'expires_on' => '', 'entitlement_year' => (int) now(config('operations.display_timezone'))->year, 'amount' => '', 'kind' => 'grant', 'note' => ''],
            'adjustment' => ['effective_on' => $today, 'quantity' => '', 'note' => ''],
            'rule' => ['operations_rule_profile_id' => '', 'starts_on' => $today, 'ends_on' => ''],
            'task' => ['assigned_to' => '', 'type' => 'general', 'title' => '', 'due_on' => '', 'note' => ''],
            'training' => ['title' => '', 'qualification_type_id' => '', 'starts_at' => '', 'ends_at' => '', 'timezone' => config('operations.display_timezone'), 'capacity' => ''],
            'enroll' => ['personnel_training_id' => '', 'revision' => ''],
            'vacation' => ['starts_on' => $today, 'ends_on' => $today, 'vacation_fraction' => '1', 'starts_time' => '', 'ends_time' => '', 'note' => ''],
            'sick' => ['starts_at' => '', 'ends_at' => '', 'timezone' => config('operations.display_timezone')],
            'responsibility' => ['responsible_user_id' => '', 'starts_on' => $today, 'ends_on' => '', 'abilities' => []],
            default => ['note' => '', 'ends_on' => $today, 'quantity' => '', 'action' => 'attend'],
        };
        if (in_array($kind, ['credit', 'adjustment', 'reduce_credit'], true)) {
            $this->form['idempotency_key'] = (string) Str::uuid();
        }
        $this->correctionUnit = '';
        if ($kind === 'reduce_credit') {
            $this->correctionUnit = WorkforceAccountEntry::where('user_id', $this->userId)->where('account', 'vacation')->where('quantity', '>', 0)->findOrFail($id)->unit;
        }
        if ($kind === 'sickness_correction') {
            $absence = AbsenceRequest::where('user_id', $this->userId)->where('kind', 'sick')->findOrFail($id);
            $this->form = ['starts_at' => $absence->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $absence->ends_at->format('Y-m-d\TH:i'), 'timezone' => $absence->timezone, 'note' => '', 'revision' => $absence->revision];
        }
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function save(WorkforceAccountService $accounts, PersonnelProcessService $process, PersonnelWorkflowService $workflow): void
    {
        $employee = $this->employee();
        $actor = auth()->user();
        $data = array_map(fn ($value) => $value === '' ? null : $value, $this->form);
        if (! $this->personal) {
            if ($this->formKind === 'model') {
                $windows = [];
                foreach (range(1, 7) as $day) {
                    $start = $data['window_start'][$day] ?? '';
                    $end = $data['window_end'][$day] ?? '';
                    if ($start !== '' || $end !== '') {
                        $windows[$day] = [['start' => $start, 'end' => $end]];
                    }
                    $start2 = $data['window_start_2'][$day] ?? '';
                    $end2 = $data['window_end_2'][$day] ?? '';
                    if ($start2 !== '' || $end2 !== '') {
                        $windows[$day] ??= [];
                        $windows[$day][] = ['start' => $start2, 'end' => $end2];
                    }
                }
                $percent = array_filter($data['valuation_percent'] ?? [], fn ($value) => $value !== '' && $value !== null);
                Validator::make(['rates' => $percent], ['rates.*' => 'numeric|between:0,100|decimal:0,2'])->validate();
                $rates = array_map(fn ($rate) => (int) round((float) $rate * 100), $percent);
                unset($data['window_start'], $data['window_end'], $data['window_start_2'], $data['window_end_2'], $data['valuation_percent']);
                $accounts->createModel($employee, $data + ['work_windows' => $windows, 'valuation_rules' => $rates], $actor);
            } elseif ($this->formKind === 'policy') {
                $data['non_working_dates'] = array_values(array_filter(preg_split('/[\s,;]+/', $data['non_working_dates_text'] ?? '')));
                unset($data['non_working_dates_text']);
                $accounts->createPolicy($employee, $data, $actor);
            } elseif ($this->formKind === 'credit') {
                $accounts->creditVacation($employee, $data, $actor);
            } elseif ($this->formKind === 'adjustment') {
                $accounts->adjustTime($employee, $data, $actor);
            } elseif ($this->formKind === 'rule') {
                $accounts->assignRules($employee, $data, $actor);
            } elseif ($this->formKind === 'task') {
                $process->createTask($employee, $data, $actor);
            } elseif ($this->formKind === 'training') {
                $process->createTraining($data, $actor);
            } elseif ($this->formKind === 'enroll') {
                $this->validate(['form.personnel_training_id' => 'required|integer', 'form.revision' => 'required|integer']);
                $process->enroll(PersonnelTraining::findOrFail($data['personnel_training_id']), $employee, (int) $data['revision'], $actor);
            } elseif (in_array($this->formKind, ['end_model', 'end_policy'], true)) {
                $class = $this->formKind === 'end_model' ? EmployeeWorkModel::class : EmployeeVacationPolicy::class;
                $record = $class::where('user_id', $employee->id)->findOrFail($this->recordId);
                $accounts->endVersion($record, (int) ($data['revision'] ?? 0), $data['ends_on'] ?? '', $data['note'] ?? '', $actor);
            } elseif ($this->formKind === 'reduce_credit') {
                $record = WorkforceAccountEntry::where('user_id', $employee->id)->findOrFail($this->recordId);
                $accounts->reduceCreditAmount($record, $data['quantity'] ?? null, $data['note'] ?? '', $actor, $data['idempotency_key'] ?? null);
            } elseif ($this->formKind === 'participation') {
                $record = PersonnelTrainingParticipant::where('user_id', $employee->id)->findOrFail($this->recordId);
                $process->participation($record, (int) ($data['revision'] ?? 0), $data['action'] ?? '', $data['note'] ?? '', $actor);
            } elseif ($this->formKind === 'task_cancel') {
                $record = PersonnelTask::where('user_id', $employee->id)->findOrFail($this->recordId);
                $process->cancelTask($record, (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
            } elseif ($this->formKind === 'training_cancel') {
                $process->cancelTraining(PersonnelTraining::findOrFail($this->recordId), (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
            } elseif ($this->formKind === 'sickness_correction') {
                $record = AbsenceRequest::where('user_id', $employee->id)->findOrFail($this->recordId);
                $workflow->correctSickness($record, (int) ($data['revision'] ?? 0), $data, $actor);
            } elseif ($this->formKind === 'responsibility') {
                app(PersonnelScopeService::class)->assign($employee, $data, $actor);
            } elseif ($this->formKind === 'adopt_absence') {
                $record = AbsenceRequest::where('user_id', $employee->id)->findOrFail($this->recordId);
                $accounts->adoptApprovedAbsence($record, (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
            } elseif ($this->formKind === 'plan_review') {
                $record = PersonnelPlanReview::where('user_id', $employee->id)->findOrFail($this->recordId);
                app(WorkforcePlanReviewService::class)->reassess($record, (int) ($data['revision'] ?? 0), $data['note'] ?? '', $actor);
            } else {
                abort(403);
            }
        } elseif ($this->formKind === 'vacation') {
            $this->validate(['form.starts_on' => 'required|date_format:Y-m-d', 'form.ends_on' => 'required|date_format:Y-m-d|after_or_equal:form.starts_on']);
            $timezone = $accounts->effectiveModel($employee, $data['starts_on'])?->timezone ?? config('operations.display_timezone');
            $partial = $data['vacation_fraction'] !== '1' && $data['vacation_fraction'] !== 1;
            if ($partial) {
                $this->validate(['form.starts_time' => 'required|date_format:H:i', 'form.ends_time' => 'required|date_format:H:i', 'form.ends_on' => 'same:form.starts_on']);
            }
            $workflow->requestAbsence($actor, ['kind' => 'vacation', 'starts_at' => $data['starts_on'].'T'.($partial ? $data['starts_time'] : '00:00'), 'ends_at' => $partial ? $data['ends_on'].'T'.$data['ends_time'] : CarbonImmutable::parse($data['ends_on'])->addDay()->format('Y-m-d\T00:00'), 'timezone' => $timezone, 'vacation_fraction' => $data['vacation_fraction'] === 'window' ? '1' : $data['vacation_fraction'], 'note' => $data['note'] ?? '']);
        } elseif ($this->formKind === 'sick') {
            $workflow->reportSickness($actor, $data);
        } else {
            abort(403);
        }
        $this->formOpen = false;
        session()->flash('operations.saved', 'Gespeichert.');
    }

    public function selectTraining(): void
    {
        $this->access();
        $training = PersonnelTraining::findOrFail($this->form['personnel_training_id'] ?? 0);
        $this->form['revision'] = $training->revision;
    }

    public function prepareRecord(string $kind, int $id, int $revision): void
    {
        $this->openForm($kind, $id);
        $this->form['revision'] = $revision;
    }

    public function activate(string $kind, int $id, int $revision, WorkforceAccountService $service): void
    {
        $employee = $this->employee();
        abort_if($this->personal, 403);
        abort_unless(in_array($kind, ['model', 'policy'], true), 404);
        $class = $kind === 'model' ? EmployeeWorkModel::class : EmployeeVacationPolicy::class;
        $service->activate($class::where('user_id', $employee->id)->findOrFail($id), $revision, auth()->user());
    }

    public function completeTask(int $id, int $revision, PersonnelProcessService $service): void
    {
        $employee = $this->employee();
        $service->completeTask(PersonnelTask::where('user_id', $employee->id)->findOrFail($id), $revision, '', auth()->user());
    }

    public function render()
    {
        $this->access();
        $accounts = app(WorkforceAccountService::class);
        $ready = $accounts->ready() && app(PersonnelProcessService::class)->ready();
        if ($this->tab === 'checks') {
            $ready = $ready && app(WorkforcePlanReviewService::class)->ready();
        }
        $employee = $this->userId ? $this->employee() : null;
        $records = collect();
        if ($ready && $employee) {
            $query = match ($this->tab) {
                'models' => EmployeeWorkModel::where('user_id', $employee->id)->latest('starts_on'),
                'policies' => EmployeeVacationPolicy::where('user_id', $employee->id)->latest('starts_on'),
                'rules' => EmployeeRuleAssignment::where('user_id', $employee->id)->with('profile')->latest('starts_on'),
                'checks' => PersonnelPlanReview::where('user_id', $employee->id)->with(['shift', 'assignment'])->latest(),
                'tasks' => PersonnelTask::where('user_id', $employee->id)->when($this->personal, fn ($q) => $q->where('assigned_to', auth()->id()))->with('assignee')->latest(),
                'responsibilities' => PersonnelResponsibility::where('user_id', $employee->id)->with('responsible')->latest('starts_on'),
                'training' => PersonnelTrainingParticipant::where('user_id', $employee->id)->with('training')->latest(),
                'absences' => AbsenceRequest::where('user_id', $employee->id)->latest(),
                default => WorkforceAccountEntry::where('user_id', $employee->id)->latest('effective_on'),
            };
            $records = $query->paginate(15, ['*'], 'workforceRecordsPage');
        }

        $ids = $this->personal ? null : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'employees.master-data.view');

        $vacationDate = $this->form['starts_on'] ?? null;
        $validVacationDate = Validator::make(['date' => $vacationDate], ['date' => 'required|date_format:Y-m-d'])->passes();
        $vacationWindowAvailable = $ready && $employee && $validVacationDate && $accounts->effectivePolicy($employee, $vacationDate)?->unit === 'minutes';

        return view('livewire.operations.workforce-accounts', ['vacationWindowAvailable' => $vacationWindowAvailable, 'ready' => $ready, 'employee' => $employee, 'summary' => $ready && $employee && $this->tab === 'account' ? $accounts->summary($employee, $this->from, $this->until, auth()->user()) : null, 'records' => $records, 'employees' => $this->personal ? collect() : User::where('role', 'staff')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']), 'canConfigure' => ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit'), 'canProcesses' => ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit'), 'canTraining' => ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'operations.qualifications.manage'), 'trainings' => $ready ? PersonnelTraining::where('status', 'scheduled')->where('ends_at', '>', now()->utc())->orderBy('starts_at')->get() : collect(), 'rules' => $ready ? OperationsRuleProfile::orderByDesc('id')->get() : collect(), 'types' => $ready ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect(), 'assignees' => ! $this->personal ? User::where('status', true)->orderBy('name')->get(['id', 'name']) : collect()]);
    }
}
