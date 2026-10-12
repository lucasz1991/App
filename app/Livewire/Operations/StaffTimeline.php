<?php

namespace App\Livewire\Operations;

use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\AbsenceRequest;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PlanningTravelDisplay;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffTimelineLayout;
use App\Services\Operations\TimelinePlanningSuggestionService;
use App\Services\Operations\TimelineWorkloadService;
use App\Services\Routing\TravelAssessmentService;
use App\Services\Routing\TomTomRoutingService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use App\Support\Operations\OperationsTransaction;
use App\Support\Operations\PlanningLocks;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithoutUrlPagination;
use Livewire\WithPagination;

class StaffTimeline extends Component
{
    use WithoutUrlPagination, WithPagination;

    private const INITIAL_BATCH_SIZE = 24;

    #[Locked]
    #[Reactive]
    public string $from;

    #[Locked]
    #[Reactive]
    public string $until;

    /** Only the embedded planning timeline can request another bounded time window. */
    #[Locked]
    public ?string $periodNavigationOwner = null;

    #[Locked]
    public string $renderedPeriod = '';

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

    /** Native customer scope for day-level staffing counts; never a staff-page filter. */
    #[Locked]
    #[Reactive]
    public ?int $attentionCustomerId = null;

    #[Locked]
    public bool $showSuggestions = false;

    /** Vorschläge beim ersten Seitenaufbau erst nachladen (wire:init), damit der Plan sofort steht. */
    #[Locked]
    public bool $suggestionsReady = true;

    #[Locked]
    public bool $suggestionsAvailable = true;

    /** Stand der gemerkten Besetzungsvorschau; jede Planänderung zählt hoch. */
    #[Locked]
    public int $previewVersion = 0;

    /** Im Seitenpanel „Noch zu verteilen“ gewählte Schicht: Tag und passende Mitarbeitende werden markiert. */
    #[Locked]
    public ?int $focusShiftId = null;

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

    #[Locked]
    public array $planningTravel = [];

    #[On('shift-travel-refresh-requested')]
    public function loadPlanningTravel(int $shiftId): void
    {
        $this->ensurePlanning();
        abort_unless($this->assignmentOpen && $this->planningShiftId === $shiftId && $this->planningUserId, 403);
        $shift = app(TimelinePlanningSuggestionService::class)->openShifts($this->from, $this->until, auth()->user())->findOrFail($shiftId);
        $users = User::whereKey($this->planningUserId)->where('role', 'staff')->where('status', true)->get();
        $details = TomTomRoutingService::withinOperation(fn () => app(StaffEligibilityService::class)->assessDetailedMany($shift, $users, false, ['include_employee_location' => true, 'actor' => auth()->user()], true));
        $this->planningTravel[$shiftId] = app(PlanningTravelDisplay::class)->stamp($details);
    }

    public function openCell(int $userId, string $date): void
    {
        $this->ensurePlanning();
        app(TimelinePlanningSuggestionService::class)->cellChoices($userId, $date, $this->from, $this->until, auth()->user(), true);
        $this->resetPlanningSelection();
        $this->planningUserId = $userId;
        $this->planningDate = $date;
        $this->assignmentOpen = true;
    }

    #[Renderless]
    public function previewCell(int $userId, string $date): array
    {
        $this->ensurePlanning();
        $choices = app(TimelinePlanningSuggestionService::class)->cellChoices($userId, $date, $this->from, $this->until, auth()->user(), true);
        $eligible = $choices->where('eligible', true)->count();

        return ['state' => $eligible > 0 ? 'suitable' : ($choices->isEmpty() ? 'empty' : 'blocked'),
            'label' => $eligible > 0 ? $eligible.' passend'.($choices->count() === 30 ? ' · Auswahl begrenzt' : '')
                : ($choices->isEmpty() ? 'Keine offenen Dienste' : 'Keine passende Schicht'),
            'detail' => $eligible > 0 ? 'Kernregeln geprüft · Fahrten beim Auswählen prüfen'
                : ($choices->isEmpty() ? 'Für diesen Tag ist nichts zu verteilen.' : 'Details und Konfliktgründe per Klick anzeigen.')];
    }

    public function selectCellShift(int $shiftId, int $revision): void
    {
        $this->ensurePlanning();
        abort_unless($this->assignmentOpen && $this->planningUserId && $this->planningDate, 422);
        $choice = app(TimelinePlanningSuggestionService::class)->cellChoices($this->planningUserId, $this->planningDate, $this->from, $this->until, auth()->user(), true, $this->planningTravel)
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
        $proposal = $this->planningPreview()['proposals']
            ->first(fn ($proposal) => $proposal['shift']->id === $shiftId && $proposal['user']->id === $userId && $proposal['revision'] === $revision);
        // Die Vorschau ist gemerkt: Revision und Eignung dieser einen Schicht immer frisch prüfen.
        $shift = $proposal ? Shift::query()->find($shiftId) : null;
        $current = $shift && (int) $shift->revision === $revision
            && app(TimelinePlanningSuggestionService::class)->rankedCandidates($shift, auth()->user())->contains(fn (array $candidate) => $candidate['user']->id === $userId);
        if (! $current) {
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
        $initialShift = Shift::findOrFail($this->planningShiftId);
        $initialUser = User::where('role', 'staff')->where('status', true)->findOrFail($this->planningUserId);
        $travel = app(TravelAssessmentService::class)->preflight($initialShift, collect([$initialUser]));
        OperationsTransaction::run(function () use ($travel) {
            PlanningLocks::acquire([$this->planningShiftId], [$this->planningUserId]);
            $shift = app(TimelinePlanningSuggestionService::class)->openShifts($this->from, $this->until, auth()->user())->whereKey($this->planningShiftId)->first();
            if (! $shift) {
                throw ValidationException::withMessages(['workflow' => 'Für diese Schicht ist kein offener Einsatzplatz mehr verfügbar.']);
            }
            $user = User::where('role', 'staff')->where('status', true)->findOrFail($this->planningUserId);
            app(ShiftAssignmentService::class)->assign($shift, $user, auth()->user(), $this->assignmentStatus, null, $this->planningRevision, null, $travel);
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

    /** Offenes Seitenpanel = Vorschläge sichtbar; geschlossen = ausgeblendet. Kein eigener Schalter mehr. */
    #[On('operations-timeline-distribution')]
    public function syncDistribution(bool $open, ?int $shiftId = null): void
    {
        $this->ensurePlanning();
        $focusShiftId = $open ? $shiftId : null;
        if ($this->showSuggestions === $open && $this->focusShiftId === $focusShiftId) {
            // Parent window changes also repeat the current panel state. Keep
            // wire:init's deferred preview and avoid a second identical HTML
            // projection/morph for this presentation-only notification.
            $this->skipRender();

            return;
        }
        if ($open && ! $this->showSuggestions) {
            $this->previewVersion++;
        }
        $this->showSuggestions = $open;
        $this->suggestionsAvailable = true;
        $this->suggestionsReady = ! $open;
        $this->focusShiftId = $focusShiftId;
        $this->resetValidation();
    }

    public function openFocusCandidate(int $shiftId, int $userId, int $revision): void
    {
        $this->ensurePlanning();
        abort_unless($this->focusShiftId === $shiftId, 422);
        $shift = Shift::query()->findOrFail($shiftId);
        $candidate = app(TimelinePlanningSuggestionService::class)->rankedCandidates($shift, auth()->user())
            ->first(fn (array $candidate) => $candidate['user']->id === $userId);
        if (! $candidate || (int) $shift->revision !== $revision) {
            throw ValidationException::withMessages(['workflow' => 'Einsatz oder Eignung wurde geändert. Bitte neu auswählen.']);
        }
        $this->resetPlanningSelection();
        $this->planningShiftId = $shiftId;
        $this->planningUserId = $userId;
        $this->planningRevision = $revision;
        $this->planningReasons = $candidate['reasons'];
        $this->assignmentOpen = true;
    }

    #[On('operations-timeline-suggestions-toggle')]
    public function toggleSuggestions(): void
    {
        $this->ensurePlanning();
        $this->showSuggestions = ! $this->showSuggestions;
        $this->suggestionsAvailable = true;
        $this->suggestionsReady = ! $this->showSuggestions;
        $this->previewVersion += $this->showSuggestions ? 1 : 0;
        $this->resetValidation();
    }

    #[On('operations-plan-changed')]
    public function refreshPlanning(): void
    {
        if ($this->planningEnabled && ! $this->absencesOnly) {
            $this->ensurePlanning();
            $this->previewVersion++;
            $this->suggestionsAvailable = true;
            $this->suggestionsReady = ! $this->showSuggestions;
        }
    }

    public function loadSuggestions(): void
    {
        $this->ensurePlanning();
        if ($this->suggestionsReady && Cache::has($this->previewKey())) {
            $this->skipRender();

            return;
        }
        Cache::put($this->previewKey(), app(TimelinePlanningSuggestionService::class)->preview($this->from, $this->until, auth()->user()), 90);
        $this->suggestionsReady = true;
    }

    /**
     * Besetzungsvorschau, je Zeitleiste und Planstand kurz gemerkt: Fokuswechsel, Nachladen und
     * Klicks rechnen sie nicht neu. Eigene Änderungen zählen previewVersion hoch, fremde altern nach 90 s aus.
     *
     * @return array{proposals: Collection, open_total: int, limited: bool}
     */
    private function planningPreview(): array
    {
        $preview = Cache::get($this->previewKey());
        if (! is_array($preview)) {
            $this->suggestionsReady = false;
        }

        return $preview ?? ['proposals' => collect(), 'open_total' => 0, 'limited' => false];
    }

    private function previewKey(): string
    {
        return implode(':', ['staff-timeline-preview', auth()->id(), $this->getId(), $this->from, $this->until, $this->previewVersion]);
    }

    private function ensurePlanning(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        OperationsAccess::requireReady();
        abort_unless($this->planningEnabled && ! $this->absencesOnly, 403);
    }

    private function resetPlanningSelection(): void
    {
        $this->reset(['planningUserId', 'planningDate', 'planningShiftId', 'planningRevision', 'planningReasons', 'assignmentOpen', 'assignmentStatus', 'planningTravel']);
        $this->resetValidation();
    }

    public function mount(): void
    {
        $this->renderedPeriod = $this->from.'|'.$this->until;
        $this->resetPage('staffPage');
        // Mit offenem Seitenpanel geöffnet: erst den Plan zeigen, Vorschläge im Folgeaufruf.
        $this->suggestionsAvailable = ! ($this->planningEnabled && ! $this->absencesOnly)
            || app(TimelinePlanningSuggestionService::class)->openShifts($this->from, $this->until, auth()->user())->exists();
        $this->suggestionsReady = ! ($this->showSuggestions && $this->planningEnabled && ! $this->absencesOnly && $this->suggestionsAvailable);
    }

    public function updatedSearch(): void
    {
        $this->resetPage('staffPage');
    }

    public function hydrate(): void
    {
        $this->synchronizePeriodContext();
    }

    private function synchronizePeriodContext(): void
    {
        $period = $this->from.'|'.$this->until;
        if ($period === $this->renderedPeriod) {
            return;
        }
        $this->renderedPeriod = $period;
        $this->resetPlanningSelection();
        $this->focusShiftId = null;
        $this->previewVersion++;
        $this->suggestionsAvailable = true;
        $this->suggestionsReady = ! ($this->planningEnabled && ! $this->absencesOnly && $this->showSuggestions);
        $this->dispatch('operations-timeline-window-reset', timelineId: $this->getId());
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
        $this->synchronizePeriodContext();
        // Livewire's successful validate() clears the entire ErrorBag. A read
        // projection must not erase a rejected assignment's workflow/status errors.
        Validator::make(['from' => $this->from, 'until' => $this->until], [
            'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from',
        ])->validate();
        $zone = config('operations.display_timezone', 'Europe/Berlin');
        $from = CarbonImmutable::parse($this->from, $zone);
        $until = CarbonImmutable::parse($this->until, $zone)->addDay();
        abort_if($from->diffInDays($until) > 94, 422);
        $selectedFrom = $from;
        $selectedUntil = $until;
        $selectedDays = collect();
        for ($day = $from; $day->lt($until); $day = $day->addDay()) {
            $selectedDays->push($day);
        }
        // Preload only a bounded three-day reserve on each side of the planning
        // window. Business actions, staffing scope and workload stay selected-only.
        $windowBuffered = $this->periodNavigationOwner && $this->planningEnabled && ! $this->absencesOnly;
        $axisFrom = $windowBuffered ? $from->subDays(3)->max(CarbonImmutable::parse('0001-01-01', $zone)) : $from;
        $axisUntil = $windowBuffered ? $until->addDays(3)->min(CarbonImmutable::parse('9999-12-31', $zone)->addDay()) : $until;
        $staffQuery = $this->staffQuery($from, $until);
        $staffTotal = (clone $staffQuery)->count();
        $currentBlock = max(1, $this->getPage('staffPage'));
        $maxBlocks = max(1, (int) ceil($staffTotal / self::INITIAL_BATCH_SIZE));
        $visibleLimit = self::INITIAL_BATCH_SIZE * min($currentBlock, $maxBlocks);
        $users = $staffQuery->paginate($visibleLimit, ['id', 'name', 'email', 'status', 'current_team_id', 'profile_photo_path'], 'staffPage', 1, $staffTotal);
        $from = $axisFrom;
        $until = $axisUntil;
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
        $dayAttention = $this->planningEnabled && ! $this->absencesOnly ? $this->dayAttention($days) : [];
        $workloads = $this->planningEnabled && ! $this->absencesOnly
            ? app(TimelineWorkloadService::class)->forRows($users->getCollection(), $assignments,
                $absences->map(fn ($items) => $items->filter(fn ($absence) => $absence->starts_at->lt($selectedUntil) && $absence->ends_at->gt($selectedFrom))),
                $selectedDays) : collect();
        $layout = app(StaffTimelineLayout::class);
        $rows = $users->getCollection()->map(function ($user) use ($days, $selectedDays, $from, $until, $assignments, $absences, $layout) {
            $events = collect();
            foreach ($assignments->get($user->id, collect()) as $assignment) {
                $shift = $assignment->shift;
                $events->push(['id' => 'shift-'.$assignment->id, 'kind' => 'shift', 'title' => $shift->title, 'start' => $shift->starts_at, 'end' => $shift->ends_at, 'detail' => $shift->order?->customer?->company_name, 'order_title' => $shift->order?->title, 'order_number' => $shift->order?->order_number, 'status' => $assignment->status->label(), 'status_value' => $assignment->status->value, 'shift_status' => $shift->status->value, 'shift_status_label' => $shift->status->label(), 'role_name' => $shift->role_name, 'location_name' => $shift->location_name ?: $shift->order?->location_name, 'location_preview' => TimelineLocationPreview::fromShift($shift), 'planned_break_minutes' => $shift->planned_break_minutes, 'shift_id' => $shift->id]);
            }
            foreach ($absences->get($user->id, collect()) as $absence) {
                $events->push(['id' => 'absence-'.$absence->id, 'absence_id' => $absence->id, 'kind' => 'absence', 'title' => ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit', 'sick' => $this->absencesOnly ? 'Krankmeldung' : 'Abwesenheit'][$absence->kind] ?? 'Abwesenheit', 'start' => $absence->starts_at, 'end' => $absence->ends_at, 'detail' => '', 'status' => ['approved' => 'Genehmigt', 'reported' => 'Gemeldet', 'pending' => 'Beantragt'][$absence->status] ?? OperationsNavigation::status($absence->status), 'status_value' => $absence->status, 'shift_status' => null, 'shift_id' => null]);
            }
            $events = $layout->assignLanes($events->filter(fn (array $event) => $event['start']->lt($until) && $event['end']->gt($from))->values());
            $rowLaneCount = $events->isEmpty() ? 1 : (int) $events->max('lane') + 1;

            return ['user' => $user, 'weekly_working_hours' => $this->absencesOnly ? null : $user->profile?->weekly_working_hours,
                'planned_hours_by_week' => $layout->plannedHoursByWeek($assignments->get($user->id, collect()), $selectedDays),
                'events' => $layout->periodEvents($days, $events),
                'lane_count' => $rowLaneCount,
                'days' => $days->map(function ($day) use ($events, $layout, $rowLaneCount) {
                    $end = $day->addDay();
                    $dayEvents = $events->filter(fn (array $event) => $event['start']->lt($end) && $event['end']->gt($day));

                    return $layout->cell($day, $dayEvents, $rowLaneCount);
                })];
        });

        $planningPreview = $this->planningEnabled && ! $this->absencesOnly && $this->showSuggestions && $this->suggestionsReady && $this->suggestionsAvailable
            ? $this->planningPreview()
            : ['proposals' => collect(), 'open_total' => 0, 'limited' => false];
        $proposalRows = $planningPreview['proposals']->groupBy(fn ($proposal) => $proposal['user']->id)->map(fn ($proposals) => $layout->periodEvents($days, $proposals->map(fn ($proposal) => [
            'id' => 'proposal-'.$proposal['shift']->id.'-'.$proposal['user']->id,
            'kind' => 'proposal', 'shift_id' => $proposal['shift']->id, 'user_id' => $proposal['user']->id, 'revision' => $proposal['revision'],
            'title' => $proposal['shift']->title, 'start' => $proposal['shift']->starts_at, 'end' => $proposal['shift']->ends_at,
            'reasons' => $proposal['reasons'],
            'fit' => $proposal['fit'], 'fit_label' => $proposal['fit_label'],
            'urgency' => $proposal['urgency'], 'urgency_label' => $proposal['urgency_label'],
        ])));
        $focus = $this->focusData($days, $layout, $zone);
        $planningUser = $this->assignmentOpen && $this->planningUserId ? User::find($this->planningUserId) : null;
        $planningShift = $this->assignmentOpen && $this->planningShiftId ? Shift::with('order.customer')->find($this->planningShiftId) : null;
        $planningAssessment = $planningShift && $planningUser ? (app(PlanningTravelDisplay::class)->current($planningShift, collect([$planningUser]), $this->planningTravel[$planningShift->id] ?? [])[$planningUser->id] ?? []) : [];
        $choices = $this->assignmentOpen && $this->planningDate && $this->planningUserId && ! $this->planningShiftId
            ? app(TimelinePlanningSuggestionService::class)->cellChoices($this->planningUserId, $this->planningDate, $this->from, $this->until, auth()->user(), true, $this->planningTravel)->map(fn ($choice) => (object) [
                'id' => $choice['shift']->id, 'title' => $choice['shift']->title, 'revision' => $choice['revision'],
                'period' => $this->planningPeriod($choice['shift'], $zone),
                'open' => max(0, $choice['shift']->required_staff - $choice['shift']->reserved_count), 'eligible' => $choice['eligible'], 'issues' => $choice['issues'], 'travel' => $choice['travel'],
            ]) : collect();

        return view('livewire.operations.staff-timeline', compact('users', 'rows', 'days', 'selectedDays', 'selectedFrom', 'selectedUntil', 'axisFrom', 'axisUntil', 'zone', 'dayAttention', 'workloads', 'planningPreview', 'proposalRows', 'planningUser', 'planningShift', 'planningAssessment', 'choices', 'focus'));
    }

    /**
     * One bounded, read-only staffing projection for all loaded dates. Requested
     * assignments reserve places but still need a response; an unfilled shift
     * with requests is counted only once. No eligibility/routing is inferred.
     *
     * @return array<string, array{shifts: int, open_shifts: int, open_places: int, awaiting_shifts: int, requested_places: int}>
     */
    private function dayAttention(Collection $days): array
    {
        if ($days->isEmpty()) {
            return [];
        }
        $empty = ['shifts' => 0, 'open_shifts' => 0, 'open_places' => 0, 'awaiting_shifts' => 0, 'requested_places' => 0];
        $dates = $days->map->toDateString()->all();
        $indexes = array_flip($dates);
        $deltas = array_fill(0, count($dates) + 1, $empty);
        $from = $days->first();
        $until = $days->last()->addDay();
        $closedOrders = [OrderStatus::Completed->value, OrderStatus::Invoiced->value, OrderStatus::Cancelled->value];
        $shifts = Shift::query()
            ->select(['id', 'starts_at', 'ends_at', 'timezone', 'required_staff'])
            ->during($from, $until)
            ->whereNotIn('status', [ShiftStatus::Completed->value, ShiftStatus::Cancelled->value])
            ->whereHas('order', fn (Builder $query) => $query->whereNotIn('status', $closedOrders)
                ->when($this->attentionCustomerId !== null, fn (Builder $query) => $query->where('customer_id', $this->attentionCustomerId)))
            ->withCount([
                'assignments as reserved_count' => fn (Builder $query) => $query->whereIn('status', ShiftAssignmentStatus::blockingValues()),
                'assignments as requested_count' => fn (Builder $query) => $query->where('status', ShiftAssignmentStatus::Requested->value),
            ])->get();

        foreach ($shifts as $shift) {
            $open = max(0, (int) $shift->required_staff - (int) $shift->reserved_count);
            $requested = (int) $shift->requested_count;
            if (($open === 0 && $requested === 0) || ! $shift->starts_at->lt($shift->ends_at)) {
                continue;
            }
            // Difference totals keep long/overnight duties O(shifts + dates),
            // with the native half-open overlap rule and local DST boundaries.
            $start = $shift->starts_at->max($from)->setTimezone($from->timezone)->toDateString();
            $end = $shift->ends_at->min($until)->subMicrosecond()->setTimezone($from->timezone)->toDateString();
            $contribution = ['shifts' => 1, 'open_shifts' => $open > 0 ? 1 : 0, 'open_places' => $open,
                'awaiting_shifts' => $requested > 0 ? 1 : 0, 'requested_places' => $requested];
            foreach ($contribution as $key => $value) {
                $deltas[$indexes[$start]][$key] += $value;
                $deltas[$indexes[$end] + 1][$key] -= $value;
            }
        }

        $summary = [];
        $running = $empty;
        foreach ($dates as $index => $date) {
            foreach ($empty as $key => $zero) {
                $running[$key] += $deltas[$index][$key];
            }
            $summary[$date] = $running;
        }

        return $summary;
    }

    /** @return array{shift: ?Shift, date: ?string, event: ?array, candidates: Collection} */
    private function focusData($days, StaffTimelineLayout $layout, string $zone): array
    {
        $empty = ['shift' => null, 'date' => null, 'event' => null, 'candidates' => collect()];
        if (! $this->planningEnabled || $this->absencesOnly || ! $this->focusShiftId) {
            return $empty;
        }
        $shift = Shift::query()->find($this->focusShiftId);
        if (! $shift) {
            return $empty;
        }
        $event = $layout->periodEvents($days, collect([[
            'id' => 'focus-'.$shift->id, 'kind' => 'focus', 'shift_id' => $shift->id, 'title' => $shift->title,
            'start' => $shift->starts_at, 'end' => $shift->ends_at,
        ]]))->first();

        return [
            'shift' => $shift,
            'date' => $shift->starts_at->setTimezone($zone)->toDateString(),
            'event' => $event,
            'candidates' => app(TimelinePlanningSuggestionService::class)->rankedCandidatesForDisplay($shift, auth()->user())->keyBy(fn (array $candidate) => $candidate['user']->id),
        ];
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
        $query = User::with(['profile:id,user_id,first_name,last_name,position,weekly_working_hours', 'currentTeam'])
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
