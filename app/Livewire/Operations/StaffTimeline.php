<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\StaffTimelineLayout;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
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
        $absences = AbsenceRequest::whereIn('user_id', $users->pluck('id'))->whereIn('status', ['pending', 'approved'])
            ->when($this->absencesOnly && $this->absenceKind !== 'all', fn ($q) => $q->where('kind', $this->absenceKind))
            ->when($this->absencesOnly && $this->absenceStatus !== 'all', fn ($q) => $q->where('status', $this->absenceStatus))
            ->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc())->get()->groupBy('user_id');
        $days = collect();
        for ($day = $from; $day->lt($until); $day = $day->addDay()) {
            $days->push($day);
        }
        $layout = app(StaffTimelineLayout::class);
        $rows = $users->getCollection()->map(function ($user) use ($days, $assignments, $absences, $layout) {
            return ['user' => $user, 'weekly_working_hours' => $this->absencesOnly ? null : $user->profile?->weekly_working_hours,
                'planned_hours_by_week' => $layout->plannedHoursByWeek($assignments->get($user->id, collect()), $days),
                'days' => $days->map(function ($day) use ($user, $assignments, $absences, $layout) {
                    $end = $day->addDay();
                    $events = collect();
                    foreach ($assignments->get($user->id, collect()) as $assignment) {
                        $shift = $assignment->shift;
                        if ($shift->starts_at->lt($end) && $shift->ends_at->gt($day)) {
                            $events->push(['id' => 'shift-'.$assignment->id, 'kind' => 'shift', 'title' => $shift->title, 'start' => $shift->starts_at, 'end' => $shift->ends_at, 'detail' => $shift->order?->customer?->company_name, 'status' => $assignment->status->label(), 'status_value' => $assignment->status->value, 'shift_status' => $shift->status->value, 'shift_status_label' => $shift->status->label(), 'role_name' => $shift->role_name, 'location_name' => $shift->location_name ?: $shift->order?->location_name, 'planned_break_minutes' => $shift->planned_break_minutes, 'shift_id' => $shift->id]);
                        }
                    }
                    foreach ($absences->get($user->id, collect()) as $absence) {
                        if ($absence->starts_at->lt($end) && $absence->ends_at->gt($day)) {
                            $events->push(['id' => 'absence-'.$absence->id, 'absence_id' => $absence->id, 'kind' => 'absence', 'title' => ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit'][$absence->kind], 'start' => $absence->starts_at, 'end' => $absence->ends_at, 'detail' => '', 'status' => $absence->status === 'approved' ? 'Genehmigt' : 'Beantragt', 'status_value' => $absence->status, 'shift_status' => null, 'shift_id' => null]);
                        }
                    }

                    return $layout->cell($day, $events);
                })];
        });

        return view('livewire.operations.staff-timeline', compact('users', 'rows', 'days', 'zone'));
    }

    private function staffQuery(CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return User::with(['profile', 'currentTeam'])
            ->where('role', 'staff')
            ->where(fn ($query) => $query->where('status', true)
                ->orWhereIn('id', ShiftAssignment::blocking()
                    ->whereHas('shift', fn ($shiftQuery) => $shiftQuery->notCancelled()->during($from, $until))
                    ->select('user_id')))
            ->when(filled($this->search), fn ($query) => $query->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%'))
            ->orderBy('name');
    }
}
