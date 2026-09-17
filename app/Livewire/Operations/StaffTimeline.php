<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class StaffTimeline extends Component
{
    use WithPagination;

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

    public function updatedSearch(): void
    {
        $this->resetPage('staffPage');
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
        $users = User::where('role', 'staff')->where(fn ($q) => $q->where('status', true)->orWhereIn('id', ShiftAssignment::blocking()->whereHas('shift', fn ($q) => $q->notCancelled()->during($from, $until))->select('user_id')))
            ->when(filled($this->search), fn ($q) => $q->where('name', 'like', '%'.mb_substr($this->search, 0, 100).'%'))->orderBy('name')->paginate(12, ['id', 'name', 'status'], 'staffPage');
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

        return view('livewire.operations.staff-timeline', compact('users','rows','days','zone'));
    }
}
