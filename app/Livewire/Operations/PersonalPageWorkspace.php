<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationsMonthClosing;
use App\Models\PersonnelPlanReview;
use App\Models\PersonnelSignatureRequest;
use App\Models\PersonnelTask;
use App\Models\PersonnelWorkflowRun;
use App\Models\SicknessEvidenceWorkflow;
use App\Models\User;
use App\Models\WorkTimeCaptureReceipt;
use App\Models\WorkTimeEntry;
use App\Services\Operations\EmployeeDocumentVersionService;
use App\Services\Operations\PayrollClosingService;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\WorkforceAccountService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\WorkTimeSchema;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PersonalPageWorkspace extends Component
{
    #[Locked]
    public string $page = 'people';

    #[Locked]
    public string $view = '';

    #[Locked]
    public string $section = '';

    #[Locked]
    public array $context = [];

    public int $userId = 0;

    #[Locked]
    public string $ruleView = 'profiles';

    public function mount(string $page, string $initialView = '', string $initialSection = '', array $context = []): void
    {
        $this->page = $page;
        $this->context = array_intersect_key($context, array_flip(['user', 'user_id', 'record', 'record_id', 'record_type', 'revision']));
        $this->userId = max(0, (int) ($context['user_id'] ?? $context['user'] ?? 0));
        $views = self::availableViews(auth()->user(), $page);
        $sections = self::availableSections(auth()->user(), $page);
        abort_unless($views || $sections, 403);
        abort_if($initialView !== '' && ! isset($views[$initialView]), 403);
        $this->view = isset($views[$initialView]) ? $initialView : (array_key_first($views) ?? '');
        if ($initialSection !== '') {
            abort_unless(isset($sections[$initialSection]), 403);
            $this->section = $initialSection;
        } elseif ($this->view === '') {
            $this->section = array_key_first($sections);
        }
        $this->selectEmployeeIfRequired();
        $this->access();
        $this->validateRecordContext();
    }

    public static function availableViews(User $actor, string $page): array
    {
        if (! $actor->status) {
            return [];
        }
        $definitions = match ($page) {
            'people' => [
                'employees' => ['Mitarbeiter', $actor->can('employees.view')],
                'documents' => ['Unterlagen', $actor->can('employees.master-data.view') && EmployeeDocumentVersionService::ready()],
                'qualifications' => ['Nachweise', $actor->can('operations.qualifications.manage') && PersonnelReview::moduleReady('qualifications')],
                'training' => ['Schulungen', $actor->can('operations.qualifications.manage') && app(PersonnelProcessService::class)->ready()],
            ],
            'personnel-processes' => [
                'tasks' => ['Aufgaben', $actor->can('employees.master-data.view') && app(PersonnelProcessService::class)->ready()],
                'workflows' => ['On-/Offboarding', $actor->can('employees.master-data.view') && app(PersonnelEnhancementService::class)->ready()],
                'recruiting' => ['Bewerbungen', $actor->can('employees.master-data.view') && self::globalAllowed($actor, 'employees.recruiting.manage') && app(PersonnelEnhancementService::class)->ready()],
                'development' => ['Entwicklung', $actor->can('employees.master-data.view') && $actor->can('employees.development.manage') && app(PersonnelEnhancementService::class)->ready()],
            ],
            'leave' => [
                'requests' => ['Anträge', $actor->can('operations.absences.review') && PersonnelReview::moduleReady('absences')],
                'calendar' => ['Kalender', $actor->can('operations.absences.review') && OperationsAccess::ready()],
                'leave-accounts' => ['Urlaubskonten', $actor->can('employees.master-data.view') && app(WorkforceAccountService::class)->ready()],
                'time-accounts' => ['Stundenkonten', $actor->can('employees.master-data.view') && app(WorkforceAccountService::class)->ready()],
            ],
            'time-review' => [
                'times' => ['Zeitmeldungen', $actor->can('operations.time.review') && OperationsAccess::ready()],
                'conflicts' => ['Erfassungskonflikte', $actor->can('operations.time.review') && WorkTimeSchema::ready()],
            ],
            'payroll' => [
                'closing' => ['Monatsabschluss', $actor->can('operations.time.review') && app(PayrollClosingService::class)->ready() && OperationsEnhancementsSchema::ready()],
                'export' => ['Export', $actor->can('operations.time.export') && OperationsAccess::ready()],
                'history' => ['Historie', $actor->can('operations.time.export') && OperationsAccess::ready()],
            ],
            default => [],
        };

        return collect($definitions)->filter(fn ($item) => $item[1])->map(fn ($item) => $item[0])->all();
    }

    public static function availableSections(User $actor, string $page): array
    {
        if (! $actor->status) {
            return [];
        }
        $accounts = $actor->can('employees.master-data.view') && app(WorkforceAccountService::class)->ready();
        $enhanced = $actor->can('employees.master-data.view') && app(PersonnelEnhancementService::class)->ready();
        $definitions = match ($page) {
            'people' => [
                'signatures' => ['Unterzeichnungen', $enhanced],
                'emergency' => ['Notfallkontakt', $enhanced && $actor->can('employees.emergency.access')],
            ],
            'personnel-processes' => [
                'reports' => ['Personalberichte', $enhanced && $actor->can('operations.time.review')],
                'surveys' => ['Umfragen', $enhanced],
            ],
            'leave' => [
                'models' => ['Arbeitsmodelle', $accounts],
                'policies' => ['Urlaubsrichtlinien', $accounts],
                'rules' => ['Regelzuordnungen', $accounts && $actor->can('operations.rules.manage')],
                'checks' => ['Planprüfungen', $accounts && Schema::hasTable('personnel_plan_reviews')],
                'responsibilities' => ['Zuständigkeiten', $accounts && $actor->isAdmin()],
                'calendars' => ['Regionalkalender', $enhanced && $actor->can('operations.rules.manage')],
                'approvals' => ['Freigabeketten', $enhanced && $actor->can('operations.absences.review')],
                'sickness' => ['Krankmeldungsnachweise', $enhanced && $actor->can('employees.master-data.edit') && $actor->can('operations.absences.review')],
            ],
            'time-review' => [
                'rules' => ['Fachregeln', $actor->can('operations.rules.manage') && PersonnelReview::moduleReady('rules')],
                'terminal' => ['Terminalzugänge', $actor->can('operations.terminal.manage') && OperationsEnhancementsSchema::ready()],
            ],
            'payroll' => [
                'payroll-references' => ['Lohnzuordnung', $actor->can('operations.time.export') && Schema::hasTable('employee_payroll_references')],
            ],
            default => [],
        };

        return collect($definitions)->filter(fn ($item) => $item[1])->map(fn ($item) => $item[0])->all();
    }

    private static function globalAllowed(User $actor, string $ability): bool
    {
        return $actor->can($ability) && app(PersonnelScopeService::class)->visibleUserIds($actor, $ability) === null;
    }

    public function setView(string $view): void
    {
        abort_unless(isset(self::availableViews(auth()->user(), $this->page)[$view]), 403);
        $this->view = $view;
        $this->section = '';
        $this->forgetRecord();
        $this->selectEmployeeIfRequired(allowScopeFallback: true);
        $this->access();
        $this->syncUrl();
    }

    public function setSection(string $section): void
    {
        if ($section === '') {
            abort_unless($this->view !== '' && isset(self::availableViews(auth()->user(), $this->page)[$this->view]), 403);
        } else {
            abort_unless(isset(self::availableSections(auth()->user(), $this->page)[$section]), 403);
        }
        $this->section = $section;
        $this->forgetRecord();
        $this->selectEmployeeIfRequired(allowScopeFallback: true);
        $this->access();
        $this->syncUrl();
    }

    public function setRuleView(string $view): void
    {
        $this->access();
        abort_unless($this->page === 'time-review' && $this->section === 'rules' && in_array($view, ['profiles', 'rates'], true), 403);
        abort_unless($view !== 'rates' || OperationsEnhancementsSchema::ready(), 503);
        $this->ruleView = $view;
    }

    public function updatedUserId(): void
    {
        $this->forgetRecord();
        $this->access();
        $this->syncUrl();
    }

    public function showEmployees(): void
    {
        abort_unless($this->page === 'people' && $this->view === 'employees' && isset(self::availableViews(auth()->user(), $this->page)['employees']), 403);
        $this->userId = 0;
        $this->forgetRecord();
        $this->syncUrl();
    }

    private function forgetRecord(): void
    {
        unset($this->context['record'], $this->context['record_id'], $this->context['record_type'], $this->context['revision']);
    }

    private function syncUrl(): void
    {
        $context = array_diff_key($this->context, array_flip(['user', 'user_id']));
        $this->dispatch('rt-workspace-url', url: OperationsPages::url($this->page, ['view' => $this->view, 'section' => $this->section, 'user' => $this->userId ?: null] + $context));
    }

    private function validateRecordContext(): void
    {
        $id = (int) ($this->context['record_id'] ?? $this->context['record'] ?? 0);
        if (! $id) {
            return;
        }
        if (($this->context['record_type'] ?? '') === 'capture-conflict') {
            abort_unless($this->page === 'time-review' && $this->view === 'conflicts' && $this->section === '', 404);
            $ids = app(PersonnelScopeService::class)->visibleUserIds(auth()->user(), 'operations.time.review');
            WorkTimeCaptureReceipt::where('status', 'conflict')->when($ids !== null, fn ($query) => $query->whereHas('device', fn ($query) => $query->whereIn('user_id', $ids)))->findOrFail($id);
            abort_if(isset($this->context['revision']), 409, 'Erfassungskonflikte besitzen keine Dienstrevision.');

            return;
        }
        $type = $this->context['record_type'] ?? match (true) {
            $this->section === 'checks' => 'plan-review',
            $this->page === 'people' && $this->view === 'qualifications' => 'qualification',
            $this->page === 'leave' && in_array($this->view, ['requests', 'calendar'], true) => 'absence',
            $this->page === 'time-review' && $this->view === 'times' && $this->section === '' => 'work-time',
            $this->page === 'payroll' && $this->view === 'closing' && $this->section === '' => 'month-closing',
            default => '',
        };
        $definition = match ($type) {
            'qualification' => [EmployeeQualification::class, 'people', 'qualifications', '', 'operations.qualifications.manage'],
            'absence' => [AbsenceRequest::class, 'leave', null, '', 'operations.absences.review'],
            'plan-review' => [PersonnelPlanReview::class, 'leave', null, 'checks', 'employees.master-data.view'],
            'task' => [PersonnelTask::class, 'personnel-processes', null, '', 'employees.master-data.view'],
            'workflow-run' => [PersonnelWorkflowRun::class, 'personnel-processes', 'workflows', '', 'employees.master-data.view'],
            'signature' => [PersonnelSignatureRequest::class, 'people', null, 'signatures', 'employees.master-data.view'],
            'sickness' => [SicknessEvidenceWorkflow::class, 'leave', null, 'sickness', 'employees.master-data.view'],
            'work-time' => [WorkTimeEntry::class, 'time-review', 'times', '', 'operations.time.review'],
            'month-closing' => [OperationsMonthClosing::class, 'payroll', 'closing', '', 'operations.time.review'],
            default => null,
        };
        if (! $definition) {
            abort_if(isset($this->context['record_type']) || isset($this->context['revision']), 404);

            return;
        }
        [$class, $page, $view, $section, $ability] = $definition;
        abort_unless($this->page === $page && ($view === null || $this->view === $view) && $this->section === $section, 404);
        abort_if($type === 'absence' && ! in_array($this->view, ['requests', 'calendar'], true), 404);
        abort_if($type === 'task' && ! in_array($this->view, ['tasks', 'workflows'], true), 404);
        $record = app(PersonnelScopeService::class)->applyRelatedQuery($class::query(), auth()->user(), $ability)->findOrFail($id);
        if ($this->userId && $record->user_id) {
            abort_unless((int) $record->user_id === $this->userId, 404);
        }
        abort_if(isset($this->context['revision']) && (int) $record->revision !== (int) $this->context['revision'], 409, 'Eintrag wurde geändert.');
    }

    private function personAbility(): ?string
    {
        if ($this->section === 'terminal') {
            return 'operations.terminal.manage';
        }
        if ($this->page === 'people' && $this->view === 'training' && $this->section === '') {
            return 'operations.qualifications.manage';
        }
        if (($this->page === 'people' && ($this->view === 'documents' || in_array($this->section, ['signatures', 'emergency'], true)))
            || $this->page === 'personnel-processes' || ($this->page === 'leave' && ($this->section !== '' || in_array($this->view, ['leave-accounts', 'time-accounts'], true)))) {
            return 'employees.master-data.view';
        }

        return null;
    }

    private function selectEmployeeIfRequired(bool $allowScopeFallback = false): void
    {
        $ability = $this->personAbility();
        if (! $ability) {
            return;
        }
        $employees = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff'), auth()->user(), $ability);
        if ($this->userId && $allowScopeFallback && ! (clone $employees)->whereKey($this->userId)->exists()) {
            $this->userId = 0;
        }
        if (! $this->userId) {
            $this->userId = (int) $employees->orderBy('name')->value('id');
        }
    }

    private function access(): void
    {
        $actor = auth()->user()->fresh();
        abort_unless($actor->status, 403);
        abort_unless($this->section !== '' ? isset(self::availableSections($actor, $this->page)[$this->section]) : isset(self::availableViews($actor, $this->page)[$this->view]), 403);
        if ($this->userId && ($ability = $this->personAbility())) {
            app(PersonnelScopeService::class)->authorize($actor, $this->userId, $ability);
            User::where('role', 'staff')->findOrFail($this->userId);
        }
    }

    public function render()
    {
        $this->access();
        $views = self::availableViews(auth()->user(), $this->page);
        $sections = self::availableSections(auth()->user(), $this->page);
        $icons = ['employees' => 'fa-users', 'documents' => 'fa-folder-open', 'qualifications' => 'fa-award', 'training' => 'fa-graduation-cap', 'tasks' => 'fa-list-check', 'workflows' => 'fa-user-check', 'recruiting' => 'fa-user-plus', 'development' => 'fa-seedling', 'requests' => 'fa-inbox', 'calendar' => 'fa-calendar-days', 'leave-accounts' => 'fa-umbrella-beach', 'time-accounts' => 'fa-wallet', 'times' => 'fa-clock', 'conflicts' => 'fa-triangle-exclamation', 'closing' => 'fa-check-double', 'export' => 'fa-file-export', 'history' => 'fa-clock-rotate-left'];
        $viewOptions = collect($views)->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'icon' => $icons[$value]])->values()->all();
        $employees = collect();
        if ($ability = $this->personAbility()) {
            $employees = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff'), auth()->user(), $ability)->orderBy('name')->get(['id', 'name']);
        }
        $recordId = max(0, (int) ($this->context['record_id'] ?? $this->context['record'] ?? 0)) ?: null;
        $contentKey = implode('-', [$this->page, $this->view, $this->section, $this->userId, $recordId ?? 0, $this->ruleView]);

        return view('livewire.operations.personal-page-workspace', compact('views', 'sections', 'viewOptions', 'employees', 'recordId', 'contentKey'));
    }
}
