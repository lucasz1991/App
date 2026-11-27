<?php

namespace App\Livewire\Operations;

use App\Livewire\Operations\Concerns\ManagesEnhancementRecords;
use App\Models\OperationsMonthClosing;
use App\Models\OperationsRateRule;
use App\Models\OperationsTerminalProfile;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsRuleEvaluationService;
use App\Services\Operations\PayrollClosingService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\WorkTimeActivityService;
use App\Services\Operations\WorkTimeTerminalService;
use App\Support\Exports\CsvDownload;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Zeitwirtschaft: Fachregeln, Monatsabschluss und Terminalzugang.
 *
 * Bis Phase 7 Teil der Sammelkomponente OperationsEnhancements; die Leistungen
 * (Nachweise, Reisen, Partner, Eingangsprüfung, Auftragskosten) liegen seitdem
 * in ServiceFulfilment.
 */
class TimeAdministration extends Component
{
    use ManagesEnhancementRecords;

    public const TABS = ['rules' => 'Fachregeln', 'payroll' => 'Monatsabschluss', 'terminal' => 'Terminalzugang'];

    /** Nur lesender Abschlussverlauf mit Export (Seite „Monatsabschluss & Export“ → Historie). */
    #[Locked]
    public bool $payrollExportOnly = false;

    public function mount(bool $personal = false, string $tab = 'rules', ?int $initialRecordId = null, bool $embedded = false, bool $payrollExportOnly = false, ?int $initialUserId = null): void
    {
        $this->personal = $personal;
        $this->embedded = $embedded;
        $this->payrollExportOnly = $payrollExportOnly;
        $this->contextUserId = $initialUserId;
        abort_if($payrollExportOnly && ($personal || $tab !== 'payroll'), 403);
        $allowed = $this->tabs();
        abort_unless($allowed, 403);
        $this->tab = array_key_exists($tab, $allowed) ? $tab : array_key_first($allowed);
        $this->form = $this->blankTabForm();
        $this->access();
        if ($initialRecordId) {
            $this->openDetails($initialRecordId);
        }
    }

    protected function tabOffered(string $tab): bool
    {
        return (! $this->personal || $tab === 'terminal') && (! $this->payrollExportOnly || $tab === 'payroll');
    }

    protected function ability(string $tab): string
    {
        return match ($tab) {
            'rules' => 'operations.rules.manage', 'payroll' => $this->payrollExportOnly ? 'operations.time.export' : 'operations.time.review', default => 'operations.terminal.manage'
        };
    }

    protected function blankTabForm(): array
    {
        return [];
    }

    protected function resetTabState(): void
    {
        $this->reset('selectedId', 'selectedRevision', 'detailOpen', 'formOpen', 'form', 'note', 'search');
    }

    protected function access(): User
    {
        $actor = $this->freshActor(requireReady: false);
        abort_unless($this->canTab($this->tab) && (! $this->personal || $this->tab === 'terminal'), 403);
        if ($this->personal) {
            OperationsAccess::own($actor, $actor->id);
        } else {
            OperationsAccess::authorize($actor, $this->ability($this->tab));
            if ($this->contextUserId) {
                app(PersonnelScopeService::class)->authorize($actor, $this->contextUserId, $this->ability($this->tab));
            }
        }

        return $actor;
    }

    private function query()
    {
        $actor = $this->access();
        if ($this->tab === 'rules') {
            return OperationsRateRule::with('user:id,name,role,status')->orderByDesc('id');
        }
        if ($this->tab === 'payroll') {
            return app(PersonnelScopeService::class)->applyRelatedQuery(OperationsMonthClosing::with('user:id,name'), $actor, $this->ability('payroll'))
                ->when($this->contextUserId, fn ($q) => $q->where('user_id', $this->contextUserId))
                ->when($this->payrollExportOnly, fn ($q) => $q->whereHas('revisions', fn ($r) => $r->where('action', 'closed')))
                ->when(filled($this->search), function ($query) {
                    $term = '%'.mb_substr(trim($this->search), 0, 100).'%';
                    $query->where(fn ($q) => $q->where('month', 'like', $term)->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)));
                })->orderByDesc('id');
        }
        $query = OperationsTerminalProfile::with('user:id,name,role,status');

        return $this->personal ? $query->where('user_id', $actor->id) : app(PersonnelScopeService::class)->applyRelatedQuery($query, $actor, 'operations.terminal.manage')->when($this->contextUserId, fn ($q) => $q->where('user_id', $this->contextUserId));
    }

    public function create(): void
    {
        $this->access();
        abort_if($this->payrollExportOnly, 403);
        OperationsEnhancementsSchema::requireReady();
        $this->reset('selectedId', 'selectedRevision', 'note');
        $this->resetValidation();
        $this->form = match ($this->tab) {
            'rules' => ['name' => '', 'kind' => 'planning', 'user_id' => '', 'starts_on' => '', 'ends_on' => '', 'confirmed' => false, 'configuration' => ['type' => 'rolling_minutes', 'days' => '', 'limit' => '', 'timezone' => config('operations.display_timezone'), 'activity' => 'all', 'window_start' => '', 'window_end' => '', 'wage_code' => '', 'multiplier_bps' => '', 'rounding_minutes' => 0, 'rounding_mode' => 'none', 'additive' => false, 'weekdays' => []]],
            'payroll' => ['user_id' => '', 'month_date' => ''],
            'terminal' => ['user_id' => '', 'pin' => '', 'terminal_id' => '', 'location_consent' => false, 'latitude' => '', 'longitude' => '', 'radius_metres' => ''],
        };
        if ($this->contextUserId && array_key_exists('user_id', $this->form)) {
            $this->form['user_id'] = $this->contextUserId;
        }
        $this->formOpen = true;
    }

    public function updatedFormKind(string $kind): void
    {
        if ($this->tab !== 'rules') {
            return;
        }

        $activity = $this->form['configuration']['activity'] ?? '';
        if ($kind === 'remuneration' && ! array_key_exists($activity, WorkTimeActivityService::KINDS)) {
            $this->form['configuration']['activity'] = '';
        } elseif ($kind === 'planning' && ! in_array($activity, ['all', 'on_call', 'travel'], true)) {
            $this->form['configuration']['activity'] = 'all';
        }
    }

    public function save(): void
    {
        $actor = $this->access();
        abort_if($this->payrollExportOnly, 403);
        OperationsEnhancementsSchema::requireReady();
        if ($this->tab === 'payroll') {
            $this->validate([
                'form.user_id' => 'required|integer|min:1',
                'form.month_date' => 'required|date_format:Y-m,Y-m-d',
            ]);
        }
        $data = $this->nulls($this->form);
        $result = match ($this->tab) {
            'rules' => app(OperationsRuleEvaluationService::class)->configure($data, $actor),
            'payroll' => app(PayrollClosingService::class)->prepare(User::findOrFail((int) $data['user_id']), CarbonImmutable::parse($data['month_date'])->format('Y-m'), $actor),
            'terminal' => app(WorkTimeTerminalService::class)->configure($this->personal ? $actor : User::findOrFail((int) $data['user_id']), $data, $actor),
        };
        $this->reset('form');
        $this->formOpen = false;
        $this->openDetails($result->id);
    }

    public function openDetails(int $id): void
    {
        $this->access();
        OperationsEnhancementsSchema::requireReady();
        $record = $this->query()->findOrFail($id);
        $this->selectedId = $record->id;
        $this->selectedRevision = $this->payrollExportOnly ? (int) $record->revisions()->where('action', 'closed')->latest('revision')->value('revision') : ($record->revision ?? 1);
        $this->form = [];
        $this->reset('note');
        $this->resetValidation();
        $this->detailOpen = true;
    }

    public function act(string $action): void
    {
        $actor = $this->access();
        abort_if($this->payrollExportOnly, 403);
        OperationsEnhancementsSchema::requireReady();
        abort_unless($this->selectedId && $this->selectedRevision, 422);
        $record = $this->query()->findOrFail($this->selectedId);
        abort_unless($this->tab === 'payroll', 422);
        if ($action === 'close') {
            app(PayrollClosingService::class)->close($record->id, $this->selectedRevision, $actor);
        } elseif ($action === 'reopen') {
            app(PayrollClosingService::class)->reopen($record->id, $this->selectedRevision, $this->note, $actor);
        } elseif ($action === 'prepare') {
            app(PayrollClosingService::class)->prepare(User::findOrFail($record->user_id), $record->month, $actor);
        } else {
            abort(422);
        }
        $this->openDetails($record->id);
    }

    public function exportPayroll(bool $delta = false, ?int $revision = null)
    {
        $actor = $this->access();
        abort_unless($this->tab === 'payroll', 403);
        $record = $this->query()->findOrFail($this->selectedId);
        $revision ??= $this->selectedRevision;
        $csv = app(PayrollClosingService::class)->csv($record->id, $revision, $actor, $delta);

        return CsvDownload::stream($csv, 'RailTime-Lohnvorbereitung-'.$record->month.'-r'.$revision.'.csv');
    }

    public function returnTime(int $id, int $revision): void
    {
        $actor = $this->access();
        abort_if($this->payrollExportOnly, 403);
        abort_unless($this->tab === 'payroll', 403);
        $closing = $this->query()->findOrFail($this->selectedId);
        abort_unless($closing->status === 'reopened', 409);
        $a = CarbonImmutable::parse($closing->month.'-01', $closing->timezone)->utc();
        $b = $a->setTimezone($closing->timezone)->addMonth()->utc();
        $entry = WorkTimeEntry::where('user_id', $closing->user_id)->where('starts_at', '<', $b)->where('ends_at', '>', $a)->findOrFail($id);
        app(PayrollClosingService::class)->returnForCorrection($entry->id, $revision, $this->note, $actor);
        $this->openDetails($closing->id);
    }

    public function render()
    {
        $actor = $this->access();
        $ready = $this->ready();
        $records = $ready ? $this->query()->paginate(15) : collect();
        $selected = $ready && $this->selectedId ? $this->query()->find($this->selectedId) : null;
        $staff = collect();
        if (! $this->personal && $ready && $this->formOpen && ! $this->payrollExportOnly) {
            $ability = match ($this->tab) {
                'payroll' => 'operations.time.review', 'rules' => 'operations.rules.manage', default => 'operations.terminal.manage'
            };
            $staff = app(PersonnelScopeService::class)->applyUsers(User::where('role', 'staff')->where('status', true), $actor, $ability)->orderBy('name')->get(['id', 'name']);
        }
        $closedVersions = $selected instanceof OperationsMonthClosing ? $selected->revisions()->where('action', 'closed')->latest('revision')->get(['id', 'revision']) : collect();
        $correctionTimes = ! $this->payrollExportOnly && $selected instanceof OperationsMonthClosing && $selected->status === 'reopened' ? WorkTimeEntry::where('user_id', $selected->user_id)->where('status', 'approved')->where('starts_at', '<', CarbonImmutable::parse($selected->month.'-01', $selected->timezone)->addMonth()->utc())->where('ends_at', '>', CarbonImmutable::parse($selected->month.'-01', $selected->timezone)->utc())->get() : collect();
        $closingSnapshot = $selected instanceof OperationsMonthClosing ? ($this->payrollExportOnly ? $selected->revisions()->where('action', 'closed')->latest('revision')->value('snapshot') : $selected->snapshot) : [];

        return view('livewire.operations.time-administration', compact('ready', 'records', 'selected', 'staff', 'closedVersions', 'correctionTimes', 'closingSnapshot'));
    }
}
