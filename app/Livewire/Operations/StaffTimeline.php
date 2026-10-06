<?php

namespace App\Livewire\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\AbsenceRequest;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffTimelineLayout;
use App\Services\Operations\TimelinePlanningSuggestionService;
use App\Services\Operations\TimelineWorkloadService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class StaffTimeline extends Component
{
    use WithoutUrlPagination, WithPagination;

    private const INITIAL_BATCH_SIZE = 24;

    #[Locked]
    public string $from;

    #[Locked]
    public string $until;

    public string $search = '';

    #[Locked]
    public bool $absencesOnly = false;

    #[Locked]
    public bool $searchInHeader = false;

    #[Locked]
    public string $absenceKind = 'all';

    #[Locked]
    public string $absenceStatus = 'all';

    #[Locked]
    public bool $planningEnabled = false;

    #[Locked]
    public bool $showSuggestions = false;

    #[Locked]
    public ?int $planningUserId = null;

    #[Locked]
    public ?string $planningDate = null;

    #[Locked]
    public ?int $planningShiftId = null;

    #[Locked]
    public ?int $planningRevision = null;

    public bool $assignmentOpen = false;

    public string $assignmentStatus = 'requested';

    #[Locked]
    public array $planningReasons = [];

    public function openCell(int $userId, string $date): void
    {
        $this->ensurePlanning();
        app(TimelinePlanningSuggestionService::class)->cellChoices($userId, $date, $this->from, $this->until, auth()->user());
        $this->resetPlanningSelection();
        $this->planningUserId = $userId;
        $this->planningDate = $date;
        $this->assignmentOpen = true;
    }

    #[Renderless]
    public function previewCell(int $userId, string $date): array
    {
        $this->ensurePlanning();
        $choices = app(TimelinePlanningSuggestionService::class)->cellChoices($userId, $date, $this->from, $this->until, auth()->user());
        $eligible = $choices->where('eligible', true)->count();

        return ['state' => $eligible > 0 ? 'suitable' : ($choices->isEmpty() ? 'empty' : 'blocked'),
            'label' => $eligible > 0 ? $eligible.' passend'.($choices->count() === 30 ? ' · Auswahl begrenzt' : '')
                : ($choices->isEmpty() ? 'Keine offenen Dienste' : 'Keine passende Schicht'),
            'detail' => $eligible > 0 ? 'Vorläufig geprüft · zum Auswählen klicken'
                : ($choices->isEmpty() ? 'Für diesen Tag ist nichts zu verteilen.' : 'Details und Konfliktgründe per Klick anzeigen.')];
    }

    public function selectCellShift(int $shiftId, int $revision): void
    {
        $this->ensurePlanning();
        abort_unless($this->assignmentOpen && $this->planningUserId && $this->planningDate, 422);
        $choice = app(TimelinePlanningSuggestionService::class)->cellChoices($this->planningUserId, $this->planningDate, $this->from, $this->until, auth()->user())
            ->first(fn ($choice) => $choice['shift']->id === $shiftId);
        if (! $choice || $choice['revision'] !== $revision || ! $choice['eligible']) {
            throw ValidationException::withMessages(['workflow' => 'Einsatz oder Eignung wurde geändert. Bitte neu auswählen.']);
        }
        $this->planningShiftId = $shiftId;
        $this->planningRevision = $revision;
        $this->planningReasons = [];
        $this->resetValidation();
    }

    public function openSuggestion(int $shiftId, int $userId, int $revision): void
    {
        $this->ensurePlanning();
        abort_unless($this->showSuggestions, 422);
        $proposal = app(TimelinePlanningSuggestionService::class)->preview($this->from, $this->until, auth()->user())['proposals']
            ->first(fn ($proposal) => $proposal['shift']->id === $shiftId && $proposal['user']->id === $userId && $proposal['revision'] === $revision);
        if (! $proposal) {
            throw ValidationException::withMessages(['workflow' => 'Vorschlag ist nicht mehr aktuell. Bitte neu auswählen.']);
        }
        $this->resetPlanningSelection();
        $this->planningShiftId = $shiftId;
        $this->planningUserId = $userId;
        $this->planningRevision = $revision;
        $this->planningReasons = $proposal['reasons'];
        $this->assignmentOpen = true;
    }

    public function confirmAssignment(): void
    {
        $this->ensurePlanning();
        abort_unless($this->assignmentOpen && $this->planningShiftId && $this->planningUserId && $this->planningRevision !== null, 422);
        $this->validate(['assignmentStatus' => ['required', Rule::in(ShiftAssignmentStatus::blockingValues())]]);
        OperationsTransaction::run(function () {
            PlanningLocks::acquire([$this->planningShiftId], [$this->planningUserId]);
            $shift = app(TimelinePlanningSuggestionService::class)->openShifts($this->from, $this->until, auth()->user())->whereKey($this->planningShiftId)->first();
            if (! $shift) {
                throw ValidationException::withMessages(['workflow' => 'Für diese Schicht ist kein offener Einsatzplatz mehr verfügbar.']);
            }
            $user = User::where('role', 'staff')->where('status', true)->findOrFail($this->planningUserId);
            app(ShiftAssignmentService::class)->assign($shift, $user, auth()->user(), $this->assignmentStatus, null, $this->planningRevision);
        });
        $this->resetPlanningSelection();
        $this->dispatch('operations-plan-changed');
    }

    public function backToCellChoices(): void
    {
        $this->ensurePlanning();
        abort_unless($this->planningDate !== null, 422);
        $this->reset(['planningShiftId', 'planningRevision', 'planningReasons']);
        $this->resetValidation();
    }

    #[On('operations-timeline-suggestions-toggle')]
    public function toggleSuggestions(): void
    {
        $this->ensurePlanning();
        $this->showSuggestions = ! $this->showSuggestions;
        $this->resetValidation();
    }

    #[On('operations-plan-changed')]
    public function refreshPlanning(): void
    {
        if ($this->planningEnabled && ! $this->absencesOnly) {
            $this->ensurePlanning();
        }
    }

    private function ensurePlanning(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        OperationsAccess::requireReady();
        abort_unless($this->planningEnabled && ! $this->absencesOnly, 403);
    }

    private function resetPlanningSelection(): void
    {
        $this->reset(['planningUserId', 'planningDate', 'planningShiftId', 'planningRevision', 'planningReasons', 'assignmentOpen', 'assignmentStatus']);
        $this->resetValidation();
    }

    public function mount(): void
    {
        $this->resetPage('staffPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('staffPage');
    }

    public function loadMore(): void
    {
        OperationsAccess::authorize(auth()->user(), $this->absencesOnly ? 'operations.absences.review' : 'operations.manage');
        OperationsAccess::requireReady();
        $this->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from']);

        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $from = CarbonImmutable::parse($this->from, $zone);
        $until = CarbonImmutable::parse($this->until, $zone)->addDay();
        abort_if($from->diffInDays($until) > 94, 422);

        $loadedThrough = max(1, $this->getPage('staffPage')) * self::INITIAL_BATCH_SIZE;
        if ($loadedThrough < $this->staffQuery($from, $until)->count()) {
            $this->nextPage('staffPage');
        }
    }

    public function render()
    {
        OperationsAccess::authorize(auth()->user(), $this->absencesOnly ? 'operations.absences.review' : 'operations.manage');
        OperationsAccess::requireReady();
        $this->validate(['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from']);
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $from = CarbonImmutable::parse($this->from, $zone);
        $until = CarbonImmutable::parse($this->until, $zone)->addDay();
        abort_if($from->diffInDays($until) > 94, 422);
        $staffQuery = $this->staffQuery($from, $until);
        $staffTotal = (clone $staffQuery)->count();
        $currentBlock = max(1, $this->getPage('staffPage'));
        $maxBlocks = max(1, (int) ceil($staffTotal / self::INITIAL_BATCH_SIZE));
        $visibleLimit = self::INITIAL_BATCH_SIZE * min($currentBlock, $maxBlocks);
        $users = $staffQuery->paginate($visibleLimit, ['id', 'name', 'email', 'status', 'current_team_id', 'profile_photo_path'], 'staffPage', 1);
        $weekFrom = $from->startOfWeek(CarbonImmutable::MONDAY);
        $weekUntil = $until->subDay()->startOfWeek(CarbonImmutable::MONDAY)->addWeek();
        $assignments = $this->absencesOnly ? collect() : ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))->whereHas('shift', fn ($q) => $q->notCancelled()->during($weekFrom, $weekUntil))->with('shift.order.customer')->get()->groupBy('user_id');
        $absences = AbsenceRequest::whereIn('user_id', $users->pluck('id'))
            ->when(! $this->absencesOnly || $this->absenceStatus === 'all', fn ($q) => $q->where(fn ($statuses) => $statuses->whereIn('status', ['pending', 'approved'])->orWhere(fn ($reported) => $reported->where('kind', 'sick')->where('status', 'reported'))))
            ->when($this->absencesOnly && $this->absenceKind !== 'all', fn ($q) => $q->where('kind', $this->absenceKind))
            ->when($this->absencesOnly && $this->absenceStatus !== 'all', fn ($q) => $q->where('status', $this->absenceStatus))
            ->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc())->get()->groupBy('user_id');
        $days = collect();
        for ($day = $from; $day->lt($until); $day = $day->addDay()) {
            $days->push($day);
        }
        $workloads = $this->planningEnabled && ! $this->absencesOnly
            ? app(TimelineWorkloadService::class)->forRows($users->getCollection(), $assignments, $absences, $days) : collect();
        $layout = app(StaffTimelineLayout::class);
        $rows = $users->getCollection()->map(function ($user) use ($days, $from, $until, $assignments, $absences, $layout) {
            $events = collect();
            foreach ($assignments->get($user->id, collect()) as $assignment) {
                $shift = $assignment->shift;
                $events->push(['id' => 'shift-'.$assignment->id, 'kind' => 'shift', 'title' => $shift->title, 'start' => $shift->starts_at, 'end' => $shift->ends_at, 'detail' => $shift->order?->customer?->company_name, 'status' => $assignment->status->label(), 'status_value' => $assignment->status->value, 'shift_status' => $shift->status->value, 'shift_status_label' => $shift->status->label(), 'role_name' => $shift->role_name, 'location_name' => $shift->location_name ?: $shift->order?->location_name, 'location_preview' => TimelineLocationPreview::fromShift($shift), 'planned_break_minutes' => $shift->planned_break_minutes, 'shift_id' => $shift->id]);
            }
            foreach ($absences->get($user->id, collect()) as $absence) {
                $events->push(['id' => 'absence-'.$absence->id, 'absence_id' => $absence->id, 'kind' => 'absence', 'title' => ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit', 'sick' => $this->absencesOnly ? 'Krankmeldung' : 'Abwesenheit'][$absence->kind] ?? 'Abwesenheit', 'start' => $absence->starts_at, 'end' => $absence->ends_at, 'detail' => '', 'status' => ['approved' => 'Genehmigt', 'reported' => 'Gemeldet', 'pending' => 'Beantragt'][$absence->status] ?? OperationsNavigation::status($absence->status), 'status_value' => $absence->status, 'shift_status' => null, 'shift_id' => null]);
            }
            $events = $layout->assignLanes($events->filter(fn (array $event) => $event['start']->lt($until) && $event['end']->gt($from))->values());
            $rowLaneCount = $events->isEmpty() ? 1 : (int) $events->max('lane') + 1;

            return ['user' => $user, 'weekly_working_hours' => $this->absencesOnly ? null : $user->profile?->weekly_working_hours,
                'planned_hours_by_week' => $layout->plannedHoursByWeek($assignments->get($user->id, collect()), $days),
                'events' => $layout->periodEvents($days, $events),
                'lane_count' => $rowLaneCount,
                'days' => $days->map(function ($day) use ($events, $layout, $rowLaneCount) {
                    $end = $day->addDay();
                    $dayEvents = $events->filter(fn (array $event) => $event['start']->lt($end) && $event['end']->gt($day));

                    return $layout->cell($day, $dayEvents, $rowLaneCount);
                })];
        });

        $planningPreview = $this->planningEnabled && ! $this->absencesOnly && $this->showSuggestions
            ? app(TimelinePlanningSuggestionService::class)->preview($this->from, $this->until, auth()->user())
            : ['proposals' => collect(), 'open_total' => 0, 'limited' => false];
        $proposalRows = $planningPreview['proposals']->groupBy(fn ($proposal) => $proposal['user']->id)->map(fn ($proposals) => $layout->periodEvents($days, $proposals->map(fn ($proposal) => [
            'id' => 'proposal-'.$proposal['shift']->id.'-'.$proposal['user']->id,
            'kind' => 'proposal', 'shift_id' => $proposal['shift']->id, 'user_id' => $proposal['user']->id, 'revision' => $proposal['revision'],
            'title' => $proposal['shift']->title, 'start' => $proposal['shift']->starts_at, 'end' => $proposal['shift']->ends_at,
            'reasons' => $proposal['reasons'],
            'fit' => $proposal['fit'], 'fit_label' => $proposal['fit_label'],
            'urgency' => $proposal['urgency'], 'urgency_label' => $proposal['urgency_label'],
        ])));
        $planningUser = $this->assignmentOpen && $this->planningUserId ? User::find($this->planningUserId) : null;
        $planningShift = $this->assignmentOpen && $this->planningShiftId ? Shift::with('order.customer')->find($this->planningShiftId) : null;
        $choices = $this->assignmentOpen && $this->planningDate && $this->planningUserId && ! $this->planningShiftId
            ? app(TimelinePlanningSuggestionService::class)->cellChoices($this->planningUserId, $this->planningDate, $this->from, $this->until, auth()->user())->map(fn ($choice) => (object) [
                'id' => $choice['shift']->id, 'title' => $choice['shift']->title, 'revision' => $choice['revision'],
                'period' => $this->planningPeriod($choice['shift'], $zone),
                'open' => max(0, $choice['shift']->required_staff - $choice['shift']->reserved_count), 'eligible' => $choice['eligible'], 'issues' => $choice['issues'],
            ]) : collect();

        return view('livewire.operations.staff-timeline', compact('users', 'rows', 'days', 'zone', 'workloads', 'planningPreview', 'proposalRows', 'planningUser', 'planningShift', 'choices'));
    }

    private function planningPeriod(Shift $shift, string $zone): string
    {
        $start = $shift->starts_at->setTimezone($zone);
        $end = $shift->ends_at->setTimezone($zone);
        $format = $start->offset !== $end->offset ? 'd.m. H:i P' : 'd.m. H:i';

        return $start->format($format).' – '.$end->format($format);
    }

    private function staffQuery(CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        $query = User::with(['profile', 'currentTeam'])
            ->where('role', 'staff')
            ->where(fn ($query) => $query->where('status', true)
                ->orWhereIn('id', ShiftAssignment::blocking()
                    ->whereHas('shift', fn ($shiftQuery) => $shiftQuery->notCancelled()->during($from, $until))
                    ->select('user_id')))
            ->when(filled($this->search), fn ($query) => $query->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%'))
            ->orderBy('name');

        return $this->absencesOnly ? app(PersonnelScopeService::class)->applyUsers($query, auth()->user(), 'operations.absences.review') : $query;
    }
}
