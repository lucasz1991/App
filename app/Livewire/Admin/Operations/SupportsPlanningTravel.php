<?php

namespace App\Livewire\Admin\Operations;

use App\Models\User;
use App\Services\Operations\PlanningTravelDisplay;
use App\Services\Operations\ShiftStaffingCandidates;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\TimelinePlanningSuggestionService;
use App\Services\Routing\TomTomRoutingService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

trait SupportsPlanningTravel
{
    #[Locked]
    public array $planningTravel = [];

    #[On('shift-travel-refresh-requested')]
    public function refreshShiftTravel(int $shiftId): void
    {
        $this->ensureAdmin();
        abort_unless(($this->detailOpen && $this->selectedShiftId === $shiftId) || ($this->distributionOpen && $this->distributionShiftId === $shiftId), 403);
        $this->loadShiftTravel($shiftId);
    }

    public function loadShiftTravel(int $shiftId): void
    {
        $this->ensureAdmin();
        [$from, $to] = $this->resolvedRange();
        $shift = $this->customerShifts()->notCancelled()->whereKey($shiftId)
            ->where(fn ($query) => $query->during($from, $to->copy()->addDay())
                ->when($this->detailOpen && $this->selectedShiftId === $shiftId, fn ($q) => $q->orWhereKey($shiftId)))
            ->firstOrFail();
        $ids = $shift->assignments()->blocking()->pluck('user_id');
        if ($this->detailOpen && $this->selectedShiftId === $shiftId && $this->candidatesReady) {
            $ranked = app(ShiftStaffingCandidates::class)->ranked($shift, $this->staffingFilters(), $this->planningTravel[$shiftId] ?? []);
            $ids = $ranked->take($this->candidateLimit)->filter(fn ($user) => $user->staffing_state !== 'blocked')
                ->sortBy(fn ($user) => ($user->planning_travel['route_state'] ?? '') === 'pending' ? 0 : 1)->pluck('id')->merge($ids);
        } elseif ($this->distributionOpen && $this->distributionShiftId === $shiftId) {
            $ids = app(TimelinePlanningSuggestionService::class)->rankedCandidatesForDisplay($shift, auth()->user(), $this->planningTravel[$shiftId] ?? [])
                ->take(6)->pluck('user.id')->merge($ids);
        }
        $users = User::whereIn('id', $ids->unique()->take(24))->where('role', 'staff')->where('status', true)->get();
        $details = TomTomRoutingService::withinOperation(fn () => app(StaffEligibilityService::class)->assessDetailedMany($shift, $users, false,
            ['include_employee_location' => true, 'actor' => auth()->user()], true));
        $fresh = $shift->fresh();
        $stamped = app(PlanningTravelDisplay::class)->stamp($details);
        $validated = app(PlanningTravelDisplay::class)->current($fresh, $users, $stamped);
        foreach ($validated as $id => $detail) {
            if (($detail['_display_checked_at'] ?? null) === ($stamped[$id]['_display_checked_at'] ?? null)) {
                $this->planningTravel[$shiftId][$id] = $stamped[$id];
            } else {
                unset($this->planningTravel[$shiftId][$id]);
            }
        }
        // Bound the Livewire snapshot. Old selections are harmless but do not accumulate indefinitely.
        if (count($this->planningTravel) > 30) {
            $this->planningTravel = array_slice($this->planningTravel, -30, null, true);
        }
    }

    private function staffingFilters(): array
    {
        return ['search' => $this->candidateSearch, 'qualification_id' => $this->candidateQualification,
            'pool_id' => $this->candidatePool, 'suitability' => $this->candidateSuitability, 'region' => $this->candidateRegion];
    }
}
