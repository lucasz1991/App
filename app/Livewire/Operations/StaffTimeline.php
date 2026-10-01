<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\ShiftAssignment;
use App\Models\User;
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
        $assignments = $this->absencesOnly ? collect() : ShiftAssignment::blocking()->whereIn('user_id', $users->pluck('id'))->whereHas('shift', fn ($q) => $q->notCancelled()->during($from, $until))->with('shift.order.customer')->get()->groupBy('user_id');
        $absences = AbsenceRequest::whereIn('user_id', $users->pluck('id'))->whereIn('status', ['pending', 'approved'])
            ->when($this->absencesOnly && $this->absenceKind !== 'all', fn ($q) => $q->where('kind', $this->absenceKind))
            ->when($this->absencesOnly && $this->absenceStatus !== 'all', fn ($q) => $q->where('status', $this->absenceStatus))
            ->where('starts_at', '<', $until->utc())->where('ends_at', '>', $from->utc())->get()->groupBy('user_id');
        $days = collect();
        for ($day = $from; $day->lt($until); $day = $day->addDay()) {
            $days->push($day);
        }
        $rows = $users->getCollection()->map(function ($user) use ($days, $assignments, $absences) {
            return ['user' => $user, 'days' => $days->map(function ($day) use ($user, $assignments, $absences) {
                $end = $day->addDay();
                $events = collect();
                foreach ($assignments->get($user->id, collect()) as $assignment) {
                    $shift = $assignment->shift;
                    if ($shift->starts_at->lt($end) && $shift->ends_at->gt($day)) {
                        $events->push(['id' => 'shift-'.$assignment->id, 'kind' => 'shift', 'title' => $shift->title, 'start' => $shift->starts_at, 'end' => $shift->ends_at, 'detail' => $shift->order?->customer?->company_name, 'status' => $assignment->status->label(), 'shift_id' => $shift->id]);
                    }
                }
                foreach ($absences->get($user->id, collect()) as $absence) {
                    if ($absence->starts_at->lt($end) && $absence->ends_at->gt($day)) {
                        $events->push(['id' => 'absence-'.$absence->id, 'absence_id' => $absence->id, 'kind' => 'absence', 'title' => ['vacation' => 'Urlaub', 'unavailable' => 'Nicht verfügbar', 'other' => 'Abwesenheit'][$absence->kind], 'start' => $absence->starts_at, 'end' => $absence->ends_at, 'detail' => '', 'status' => $absence->status === 'approved' ? 'Genehmigt' : 'Beantragt', 'shift_id' => null]);
                    }
                }
                $events = $events->sortBy(fn ($e) => $e['start']->timestamp)->values();
                $cursor = $day->timestamp;
                $free = [];
                foreach ($events as $event) {
                    $start = max($day->timestamp, $event['start']->timestamp);
                    $finish = min($end->timestamp, $event['end']->timestamp);
                    if ($start > $cursor) {
                        $free[] = [$cursor, $start];
                    }
                    $cursor = max($cursor, $finish);
                }
                if ($cursor < $end->timestamp) {
                    $free[] = [$cursor, $end->timestamp];
                }

                return ['date' => $day, 'events' => $events, 'free' => $free];
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
