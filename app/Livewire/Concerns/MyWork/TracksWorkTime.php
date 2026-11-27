<?php

namespace App\Livewire\Concerns\MyWork;

use App\Models\PersonnelTraining;
use App\Models\ShiftAssignment;
use App\Models\WorkTimeEntry;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\ReportingPeriod;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Reiter „Zeiten" und die Stempeluhr auf „Heute": Zeitraum, Statusfilter, Nachträge,
 * Korrekturen, Arbeitsabschnitte und das Starten/Stempeln eigener Dienste.
 *
 * Erwartet in der Komponente: access(), displayTimezone(), withFormErrors(), showTab(),
 * openForm(), $tab und $eventKey.
 */
trait TracksWorkTime
{
    abstract private function access(): void;
    abstract private function displayTimezone(): string;
    abstract private function withFormErrors(string $prefix, callable $action): mixed;
    abstract public function showTab(string $tab): void;
    abstract public function openForm(string $form): void;

    private const TIME_FILTERS = [
        'all' => 'Alle Status', 'running' => 'Läuft', 'paused' => 'In Pause',
        'completed' => 'Bereit zum Einreichen', 'returned' => 'Zurückgegeben',
        'submitted' => 'Eingereicht', 'approved' => 'Freigegeben', 'exported' => 'Exportiert',
    ];

    public bool $manualOpen = false;

    public bool $correctionOpen = false;

    #[Locked]
    public string $timeFilter = 'all';

    public bool $generalManualOpen = false;

    public bool $generalStartOpen = false;

    public bool $sectionsOpen = false;

    #[Locked]
    public ?int $sectionsId = null;

    public array $generalManual = ['work_context' => 'internal', 'title' => '', 'timezone' => 'Europe/Berlin', 'training_session_id' => null, 'starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    #[Locked]
    public string $timeFrom = '';

    #[Locked]
    public string $timeUntil = '';

    public array $timePeriod = ['from' => '', 'until' => ''];

    #[Locked]
    public ?int $correctingId = null;

    #[Locked]
    public ?int $correctingRevision = null;

    public array $correction = ['starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    public ?int $manualAssignmentId = null;

    #[Locked]
    public ?int $manualPlanRevision = null;

    public array $manualTime = ['starts_at' => '', 'ends_at' => '', 'pause_minutes' => 0, 'note' => ''];

    public function openSections(int $id): void
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $entry = WorkTimeEntry::where('user_id', auth()->id())->findOrFail($id);
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 403);
        $this->sectionsId = $id;
        $this->sectionsOpen = true;
    }

    public function closeSections(): void
    {
        $this->access();
        $this->reset(['sectionsId', 'sectionsOpen']);
    }

    public function openGeneralManual(): void
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $this->generalManualOpen = true;
        $this->resetValidation();
    }

    public function openTrainingManual(int $id): void
    {
        $this->access();
        WorkTimeSchema::requireReady();
        $training = PersonnelTraining::where('status', 'scheduled')
            ->whereHas('participants', fn ($query) => $query->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']))->find($id);
        abort_unless($training, 404);
        $this->openGeneralManual();
        $this->reset('generalManual');
        $this->generalManual = array_replace($this->generalManual, ['work_context' => 'training', 'training_session_id' => $training->id, 'title' => $training->title, 'timezone' => $training->timezone]);
    }

    public function saveGeneralManual(WorkTimeService $service): void
    {
        $this->access();
        $this->resetValidation();
        $data = $this->generalManual;
        if (($data['work_context'] ?? '') !== 'training' || empty($data['training_session_id'])) {
            unset($data['training_session_id']);
        }
        $this->withFormErrors('generalManual', fn () => $service->manualContext($data, $this->eventKey, auth()->user()));
        $this->eventKey = (string) Str::uuid();
        $this->reset(['generalManual', 'generalManualOpen']);
        session()->flash('operations.saved', 'Arbeitszeit gespeichert.');
    }

    public function applyTimePeriod(): void
    {
        $this->access();
        $this->resetValidation(['timePeriod.from', 'timePeriod.until']);
        $this->validate(['timePeriod.from' => 'required|date_format:Y-m-d', 'timePeriod.until' => 'required|date_format:Y-m-d|after_or_equal:timePeriod.from'], [
            'timePeriod.from.required' => 'Bitte ein Startdatum wählen.',
            'timePeriod.until.required' => 'Bitte ein Enddatum wählen.',
            'timePeriod.*.date_format' => 'Bitte ein gültiges Datum eingeben.',
            'timePeriod.until.after_or_equal' => 'Das Enddatum muss am oder nach dem Startdatum liegen.',
        ]);
        $this->withFormErrors('timePeriod', fn () => ReportingPeriod::bounds($this->timePeriod['from'], $this->timePeriod['until']));
        $this->timeFrom = $this->timePeriod['from'];
        $this->timeUntil = $this->timePeriod['until'];
        $this->resetPage('timesPage');
        $this->dispatch('personal-time-period-applied');
    }

    public function cancelTimePeriod(): void
    {
        $this->access();
        $this->timePeriod = ['from' => $this->timeFrom, 'until' => $this->timeUntil];
        $this->resetValidation(['timePeriod.from', 'timePeriod.until']);
        $this->dispatch('personal-time-period-applied');
    }

    public function showTimeFilter(string $filter): void
    {
        $this->access();
        abort_unless(array_key_exists($filter, self::TIME_FILTERS), 404);
        $this->timeFilter = $filter;
        $this->resetPage('timesPage');
    }

    public function openCurrentTimeIssues(): void
    {
        $this->showTab('time');
        $this->setCurrentTimePeriod();
        $this->showTimeFilter('all');
        $this->dispatch('personal-time-issues-opened');
    }

    public function showCaptureConflicts(?string $eventKey = null): void
    {
        $this->showTab('time');
        $this->dispatch('personal-time-conflicts-opened', eventKey: Str::isUuid($eventKey ?? '') ? $eventKey : null);
    }

    private function setCurrentTimePeriod(): void
    {
        $today = CarbonImmutable::now($this->displayTimezone());
        $this->timeFrom = $today->startOfMonth()->toDateString();
        $this->timeUntil = $today->toDateString();
        $this->timePeriod = ['from' => $this->timeFrom, 'until' => $this->timeUntil];
    }

    public function updatedManualAssignmentId(): void
    {
        $this->manualPlanRevision = null;
    }

    public function saveManual(WorkTimeService $service): void
    {
        $this->access();
        $this->resetValidation();
        $this->validate(['manualAssignmentId' => 'required|integer']);
        $assignment = ShiftAssignment::where('user_id', auth()->id())->findOrFail($this->manualAssignmentId);
        $this->withFormErrors('manualTime', fn () => $service->manual($assignment->id, $this->manualPlanRevision ?? $assignment->plan_revision, $this->manualTime, $this->eventKey, auth()->user()));
        $this->eventKey = (string) Str::uuid();
        $this->reset(['manualTime', 'manualAssignmentId', 'manualPlanRevision']);
        $this->manualOpen = false;
        session()->flash('operations.saved', 'Zeit nachgetragen.');
    }

    public function openManualAssignment(int $id, ?int $revision = null): void
    {
        $this->access();
        $assignment = $this->manualAssignments()->firstWhere('id', $id);
        abort_unless($assignment, 404);
        if ($revision !== null && $revision !== $assignment->plan_revision) {
            throw ValidationException::withMessages(['workflow' => 'Der Dienst wurde geändert. Bitte den veröffentlichten Dienststand prüfen.']);
        }
        $this->openForm('manual');
        $this->manualAssignmentId = $assignment->id;
        $this->manualPlanRevision = $assignment->plan_revision;
        $this->reset('manualTime');
    }

    private function manualAssignments(): Collection
    {
        [$from, $until] = ReportingPeriod::bounds($this->timeFrom, $this->timeUntil);
        return ShiftAssignment::where('user_id', auth()->id())->where('status', 'confirmed')->whereDoesntHave('timeEntry')
            ->whereHas('shift', fn ($q) => $q->where('published_revision', '>', 0)->whereColumn('published_revision', 'revision')
                ->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision')->where('starts_at', '<=', now()->utc())
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->where(fn ($period) => $period->where('ends_at', '>=', now()->subDays(90)->utc())
                    ->orWhere(fn ($selected) => $selected->where('starts_at', '>=', $from)->where('starts_at', '<', $until))))
            ->with('shift')->orderByDesc('id')->get();
    }

    private function trainingOptions(): array
    {
        if (! in_array($this->tab, ['today', 'time'], true) || ! Schema::hasTable('personnel_trainings') || ! Schema::hasTable('personnel_training_participants')) {
            return ['clockTrainings' => collect(), 'startableTrainings' => collect()];
        }
        $now = now()->utc();
        $query = PersonnelTraining::where('status', 'scheduled')->whereHas('participants', fn ($q) => $q
            ->where('user_id', auth()->id())->whereIn('status', ['confirmed', 'attended']));
        $trainings = (clone $query)->where('starts_at', '<=', $now)->latest('starts_at')->limit(100)->get();
        $selectedId = filter_var($this->generalManual['training_session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($selectedId && ! $trainings->contains('id', $selectedId) && ($selected = $query->find($selectedId))) {
            $trainings->push($selected);
        }
        $startable = PersonnelTraining::where('status', 'scheduled')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
            ->whereHas('participants', fn ($q) => $q->where('user_id', auth()->id())->where('status', 'confirmed'))->orderBy('starts_at')->get();
        return ['clockTrainings' => $trainings, 'startableTrainings' => $startable];
    }

    public function start(int $id, int $revision, WorkTimeService $service): void
    {
        $this->access();
        $service->start($id, $revision, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
    }

    public function clock(int $id, int $revision, string $action, WorkTimeService $service): void
    {
        $this->access();
        $service->clock($id, $revision, $action, $this->eventKey, auth()->user());
        $this->eventKey = (string) Str::uuid();
    }

    public function editTime(int $id, ?int $revision = null): void
    {
        $this->access();
        $entry = WorkTimeEntry::where('user_id', auth()->id())->findOrFail($id);
        if ($revision !== null && (int) $entry->revision !== $revision) {
            throw ValidationException::withMessages(['workflow' => 'Diese Zeitmeldung wurde geändert. Bitte den aktuellen Stand prüfen.']);
        }
        abort_unless(in_array($entry->status, ['completed', 'returned'], true), 403);
        $this->correctingId = $entry->id;
        $this->resetValidation();
        $this->correctionOpen = true;
        $this->correctingRevision = $entry->revision;
        $this->correction = ['starts_at' => $entry->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $entry->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => intdiv($entry->pause_seconds, 60), 'note' => ''];
    }

    public function correct(WorkTimeService $service): void
    {
        $this->access();
        $this->resetValidation();
        $this->withFormErrors('correction', fn () => $service->correct($this->correctingId, $this->correctingRevision, $this->correction, auth()->user()));
        $this->correctingId = null;
        $this->correctionOpen = false;
        session()->flash('operations.saved', 'Korrektur gespeichert. Die Zeitmeldung kann erneut zur Prüfung eingereicht werden.');
    }

    public function cancelCorrection(): void
    {
        $this->access();
        $this->correctingId = null;
        $this->correctionOpen = false;
    }

    /** Eigene Zeitmeldungen; der Zeitraum greift nur im Reiter „Zeiten", der Statusfilter immer. */
    private function timeEntriesQuery(): Builder
    {
        $timeQuery = WorkTimeEntry::where('user_id', auth()->id());
        if ($this->tab === 'time') {
            ReportingPeriod::apply($timeQuery, $this->timeFrom, $this->timeUntil);
        }
        if ($this->timeFilter !== 'all') {
            $timeQuery->where('status', $this->timeFilter);
        }

        return $timeQuery;
    }

    private function activeTimeEntry(): ?WorkTimeEntry
    {
        return WorkTimeEntry::where('user_id', auth()->id())->whereIn('status', ['running', 'paused'])->first();
    }

    /** Der eine bestätigte Dienst, der sich gerade stempeln lässt (Heute und Zeiten). */
    private function clockDuty(Collection $visible, ?WorkTimeEntry $activeTime): ?object
    {
        $now = CarbonImmutable::now('UTC');

        return ! $activeTime && in_array($this->tab, ['today', 'time'], true)
            ? $visible->first(fn ($assignment) => $assignment->status->value === 'confirmed'
                && ! $assignment->plan_is_stale && ! $assignment->timeEntry
                && $assignment->shift->starts_at->lte($now->addMinutes(config('operations.clock_start_early_minutes')))
                && $assignment->shift->ends_at->gt($now)
                && $assignment->shift()->whereNotIn('status', ['draft', 'cancelled', 'completed'])->exists())
            : null;
    }

    private function timeCompleteness(): ?array
    {
        return in_array($this->tab, ['today', 'time'], true) && WorkTimeSchema::ready()
            ? app(WorkTimeExtensionService::class)->completeness(auth()->user(),
                $this->tab === 'time' ? $this->timeFrom : now($this->displayTimezone())->startOfMonth()->toDateString(),
                $this->tab === 'time' ? $this->timeUntil : now($this->displayTimezone())->toDateString(), auth()->user()) : null;
    }

    /** Daten für Stempeluhr, Zeitformulare und die Liste im Reiter „Zeiten". */
    private function timeViewData(Builder $timeQuery, ?array $timeCompleteness, ?object $clockDuty, ?WorkTimeEntry $activeTime): array
    {
        return [
            'timeCompleteness' => $timeCompleteness,
            'timeFilterOptions' => self::TIME_FILTERS,
            'clockDuty' => $clockDuty,
            'correctingEntry' => $this->correctionOpen && $this->correctingId
                ? WorkTimeEntry::where('user_id', auth()->id())->find($this->correctingId) : null,
            'workTimeReady' => WorkTimeSchema::ready(),
            ...$this->trainingOptions(),
            'activeTime' => $activeTime,
            'times' => $this->tab === 'time' ? $timeQuery->latest('starts_at')->paginate(20, ['*'], 'timesPage') : collect(),
            'manualAssignments' => $this->tab === 'time' ? $this->manualAssignments() : collect(),
        ];
    }
}
