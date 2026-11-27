<?php

namespace App\Livewire\Operations;

use App\Livewire\Operations\Concerns\SavesPersonalAbsenceForms;
use App\Livewire\Operations\Concerns\SavesPersonnelProcessForms;
use App\Livewire\Operations\Concerns\SavesWorkforceAccountForms;
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
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Arbeitsmodelle, Konten und Personalprozesse eines Mitarbeiters in einer gemeinsamen
 * Ansicht (ein Mitarbeiter, ein Reiter, ein Formulardialog). Das Speichern liegt je
 * Fachbereich in einem Concern:
 *   - SavesWorkforceAccountForms  (Konten, Arbeitsmodelle, Richtlinien, Regeln, Prüfungen, Zuständigkeiten)
 *   - SavesPersonnelProcessForms  (Aufgaben, Schulungen)
 *   - SavesPersonalAbsenceForms   (Urlaubsantrag, Krankmeldung der persönlichen Ansicht)
 */
class WorkforceAccounts extends Component
{
    use SavesPersonalAbsenceForms, SavesPersonnelProcessForms, SavesWorkforceAccountForms, WithPagination;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public string $accountKind = 'all';

    #[Locked]
    public ?int $profileUserId = null;

    #[Locked]
    public ?string $profileTab = null;

    public int $userId = 0;

    public string $tab = 'account';

    public string $from = '';

    public string $until = '';

    #[Locked]
    public array $accountPeriod = [];

    public bool $formOpen = false;

    #[Locked]
    public string $formKind = '';

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $focusedRecordId = null;

    #[Locked]
    public string $correctionUnit = '';

    public array $form = [];

    public function mount(bool $personal = false, ?string $tab = null, bool $embedded = false, string $accountKind = 'all', ?int $initialUserId = null, ?int $initialRecordId = null, ?int $profileUserId = null): void
    {
        $this->personal = $personal;
        $this->embedded = $embedded;
        $this->accountKind = $accountKind;
        abort_unless(in_array($accountKind, ['all', 'vacation', 'time'], true), 422);
        if ($tab !== null) {
            $this->tab = $tab;
        }
        $this->profileUserId = $profileUserId;
        $this->profileTab = $profileUserId !== null ? $this->tab : null;
        abort_if($profileUserId !== null && $initialUserId !== null && $profileUserId !== $initialUserId, 403);
        $this->from = now(config('operations.display_timezone'))->startOfMonth()->toDateString();
        $this->until = now(config('operations.display_timezone'))->endOfMonth()->toDateString();
        $this->accountPeriod = ['from' => $this->from, 'until' => $this->until];
        $ids = $personal ? null : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), $this->ability());
        $this->userId = $profileUserId ?? ($personal ? auth()->id() : ($initialUserId ?? (int) User::where('role', 'staff')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->value('id')));
        $this->access();
        if ($initialRecordId && $this->tab === 'tasks') {
            PersonnelTask::where('user_id', $this->userId)->when($this->personal, fn ($query) => $query->where('assigned_to', auth()->id()))->findOrFail($initialRecordId);
            $this->focusedRecordId = $initialRecordId;
        }
        if ($initialRecordId && $this->tab === 'checks') {
            $record = PersonnelPlanReview::where('user_id', $this->userId)->findOrFail($initialRecordId);
            $this->prepareRecord('plan_review', $record->id, $record->revision);
        }
    }

    public function applyAccountPeriod(): void
    {
        $this->access();
        abort_unless($this->tab === 'account', 404);
        $period = $this->validate([
            'from' => 'required|date_format:Y-m-d',
            'until' => 'required|date_format:Y-m-d|after_or_equal:from',
        ], [
            'from.required' => 'Bitte einen Beginn auswählen.',
            'until.required' => 'Bitte ein Ende auswählen.',
            'until.after_or_equal' => 'Das Ende muss am oder nach dem Beginn liegen.',
        ]);

        if (CarbonImmutable::parse($period['from'], 'UTC')->diffInDays(CarbonImmutable::parse($period['until'], 'UTC')) > 366) {
            throw ValidationException::withMessages(['until' => 'Zeitraum darf höchstens 367 Tage umfassen.']);
        }

        $this->accountPeriod = $period;
        $this->resetPage('workforceRecordsPage');
    }

    private function ability(): string
    {
        return $this->tab === 'training' ? 'operations.qualifications.manage' : 'employees.master-data.view';
    }

    private function access(): void
    {
        if ($this->profileUserId !== null) {
            abort_unless($this->embedded && ! $this->personal && $this->profileUserId > 0 && $this->userId === $this->profileUserId && $this->tab === $this->profileTab, 403);
        }
        if ($this->personal) {
            OperationsAccess::own(auth()->user(), $this->userId);
        } else {
            OperationsAccess::authorize(auth()->user(), $this->ability());
            if ($this->userId) {
                app(PersonnelScopeService::class)->authorize(auth()->user(), $this->userId, $this->ability());
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
        abort_if($this->profileUserId !== null && $tab !== $this->profileTab, 403);
        abort_unless(in_array($tab, $this->personal ? ['account', 'absences', 'tasks', 'training'] : ['account', 'models', 'policies', 'rules', 'checks', 'absences', 'responsibilities', 'training'], true), 404);
        $this->tab = $tab;
        $this->focusedRecordId = null;
        $this->access();
        $this->resetPage('workforceRecordsPage');
        $this->formOpen = false;
        $this->resetValidation();
    }

    public function updatedUserId(): void
    {
        $this->focusedRecordId = null;
        $this->access();
        $this->employee();
        $this->resetPage('workforceRecordsPage');
        $this->formOpen = false;
        $this->resetValidation();
    }

    public function openForm(string $kind, ?int $id = null): void
    {
        $this->access();
        $this->authorizeProfileForm($kind);
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
        $this->authorizeProfileForm($this->formKind);
        $actor = auth()->user();
        $data = array_map(fn ($value) => $value === '' ? null : $value, $this->form);
        if (! $this->personal) {
            abort_unless($this->saveAccountForm($employee, $data, $actor, $accounts, $workflow) || $this->saveProcessForm($employee, $data, $actor, $process), 403);
        } else {
            abort_unless($this->savePersonalAbsenceForm($employee, $data, $actor, $accounts, $workflow), 403);
        }
        $this->resetValidation();
        $this->formOpen = false;
        session()->flash('operations.saved', 'Gespeichert.');
    }

    public function prepareRecord(string $kind, int $id, int $revision): void
    {
        $this->openForm($kind, $id);
        $this->form['revision'] = $revision;
    }

    private function authorizeProfileForm(string $kind): void
    {
        if ($this->profileUserId === null) {
            return;
        }

        $allowed = match ($this->profileTab) {
            'account' => ['credit', 'adjustment', 'reduce_credit'],
            'models' => ['model', 'end_model'],
            'policies' => ['policy', 'end_policy'],
            'rules' => ['rule'],
            'checks' => ['plan_review'],
            'absences' => ['sickness_correction', 'adopt_absence'],
            'tasks' => ['task', 'task_cancel'],
            'training' => ['enroll', 'participation'],
            'responsibilities' => ['responsibility'],
            default => [],
        };
        abort_unless(in_array($kind, $allowed, true), 403);
    }

    public function render()
    {
        $this->access();
        $accounts = app(WorkforceAccountService::class);
        $ready = in_array($this->tab, ['tasks', 'training'], true) ? app(PersonnelProcessService::class)->ready() : $accounts->ready();
        if ($this->tab === 'checks') {
            $ready = $ready && app(WorkforcePlanReviewService::class)->ready();
        }
        $employee = $this->userId ? $this->employee() : null;
        $records = collect();
        $actionRecord = null;
        if ($ready && $employee) {
            $query = match ($this->tab) {
                'models' => EmployeeWorkModel::where('user_id', $employee->id)->latest('starts_on'),
                'policies' => EmployeeVacationPolicy::where('user_id', $employee->id)->latest('starts_on'),
                'rules' => EmployeeRuleAssignment::where('user_id', $employee->id)->with('profile')->latest('starts_on'),
                'checks' => PersonnelPlanReview::where('user_id', $employee->id)->with(['shift', 'assignment'])->latest(),
                'tasks' => PersonnelTask::where('user_id', $employee->id)->when($this->personal, fn ($q) => $q->where('assigned_to', auth()->id()))->when($this->focusedRecordId, fn ($q) => $q->whereKey($this->focusedRecordId))->with('assignee')->latest(),
                'responsibilities' => PersonnelResponsibility::where('user_id', $employee->id)->with('responsible')->latest('starts_on'),
                'training' => PersonnelTrainingParticipant::where('user_id', $employee->id)->with('training')->latest(),
                'absences' => AbsenceRequest::where('user_id', $employee->id)->latest(),
                default => WorkforceAccountEntry::where('user_id', $employee->id)
                    ->when($this->accountKind !== 'all', fn ($q) => $q->where('account', $this->accountKind))
                    ->where(fn ($q) => $q->where('account', '!=', 'time')
                        ->orWhereBetween('effective_on', [$this->accountPeriod['from'], $this->accountPeriod['until']]))
                    ->latest('effective_on')->latest('id'),
            };
            if ($this->formOpen && $this->recordId && in_array($this->formKind, ['plan_review', 'end_model'], true)) {
                $actionRecord = (clone $query)->findOrFail($this->recordId);
            }
            $records = $query->paginate(15, ['*'], 'workforceRecordsPage');
        }

        $ids = $this->personal ? null : app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), $this->ability());

        $vacationDate = $this->form['starts_on'] ?? null;
        $validVacationDate = Validator::make(['date' => $vacationDate], ['date' => 'required|date_format:Y-m-d'])->passes();
        $vacationWindowAvailable = $this->formOpen && $this->formKind === 'vacation' && $ready && $employee && $validVacationDate && $accounts->effectivePolicy($employee, $vacationDate)?->unit === 'minutes';

        return view('livewire.operations.workforce-accounts', ['actionRecord' => $actionRecord, 'vacationWindowAvailable' => $vacationWindowAvailable, 'ready' => $ready, 'employee' => $employee, 'summary' => $ready && $employee && $this->tab === 'account' ? $accounts->summary($employee, $this->accountPeriod['from'], $this->accountPeriod['until'], auth()->user()) : null, 'records' => $records, 'employees' => $this->personal || $this->embedded ? collect() : User::where('role', 'staff')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']), 'canConfigure' => ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit'), 'canProcesses' => ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'employees.master-data.edit'), 'canTraining' => ! $this->personal && $employee && app(PersonnelScopeService::class)->allows(auth()->user(), $employee, 'operations.qualifications.manage'), 'trainings' => $ready && $this->formOpen && $this->formKind === 'enroll' ? PersonnelTraining::where('status', 'scheduled')->where('ends_at', '>', now()->utc())->orderBy('starts_at')->get() : collect(), 'rules' => $ready && $this->formOpen && $this->formKind === 'rule' ? OperationsRuleProfile::orderByDesc('id')->get() : collect(), 'types' => $ready && $this->formOpen && $this->formKind === 'training' ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect(), 'assignees' => ! $this->personal && $this->formOpen && in_array($this->formKind, ['task', 'responsibility'], true) ? User::where('status', true)->orderBy('name')->get(['id', 'name']) : collect()]);
    }
}
