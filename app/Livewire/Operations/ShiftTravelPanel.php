<?php

namespace App\Livewire\Operations;

use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Routing\TravelAssessmentService;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftTravelPanel extends Component
{
    #[Locked]
    public int $shiftId;

    #[Locked]
    public int $revision;

    #[Locked]
    public array $employeeIds = [];

    #[Locked]
    public array $assessments = [];

    #[Locked]
    public array $geometry = [];

    #[Locked]
    public bool $loaded = false;

    public function mount(int $shiftId, array $employeeIds): void
    {
        $this->shiftId = $shiftId;
        $shift = $this->shift();
        $this->revision = (int) $shift->revision;
        $this->employeeIds = array_slice(array_values(array_unique(array_map('intval', $employeeIds))), 0, 24);
    }

    private function shift(): Shift
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');

        return Shift::notCancelled()->findOrFail($this->shiftId);
    }

    private function currentShift(): Shift
    {
        $shift = $this->shift();
        abort_unless((int) $shift->revision === $this->revision, 409, 'Die Schicht wurde geändert. Bitte die aktuelle Auswahl erneut öffnen.');

        return $shift;
    }

    public function load(): void
    {
        $shift = $this->currentShift();
        $users = User::whereIn('id', $this->employeeIds)->where('role', 'staff')->where('status', true)->get();
        $details = app(StaffEligibilityService::class)->assessDetailedMany($shift, $users, false, ['include_employee_location' => true, 'actor' => auth()->user()]);
        $this->assessments = collect($details)->map(fn ($detail) => array_intersect_key($detail, array_flip(['issues', 'warnings', 'travel', 'arrival'])))->all();
        $this->geometry = [];
        $this->loaded = true;
    }

    public function showRoute(int $employeeId, string $direction): void
    {
        $shift = $this->currentShift();
        abort_unless(in_array($employeeId, $this->employeeIds, true) && in_array($direction, ['before', 'after', 'arrival'], true), 403);
        $user = User::where('role', 'staff')->where('status', true)->findOrFail($employeeId);
        $result = app(TravelAssessmentService::class)->geometryFor($shift, $user, $direction, ['actor' => auth()->user()]);
        $this->geometry = ($result['status'] ?? null) === 'known' ? ($result['points'] ?? []) : [];
        if ($this->geometry === []) {
            $this->addError('route', 'Die Route ist aktuell nicht verfügbar. Die Zuteilung bleibt bedienbar.');
        } else {
            $this->resetValidation('route');
        }
    }

    public function render()
    {
        $this->currentShift();

        return view('livewire.operations.shift-travel-panel', ['names' => User::whereIn('id', $this->employeeIds)->get()->pluck('name', 'id')]);
    }
}
