<?php

namespace App\Livewire\Operations;

use App\Models\AbsenceRequest;
use App\Models\EmployeeQualification;
use App\Models\OperationInquiry;
use App\Models\Shift;
use App\Models\WorkTimeEntry;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsNavigation;
use Livewire\Component;

class Cockpit extends Component
{
    public function render()
    {
        OperationsAccess::requireReady();
        $user = auth()->user();
        $modules = OperationsNavigation::forUser($user);
        abort_unless(count($modules), 403);
        $queue = [];
        foreach ([['inquiries', 'Offene Anfragen', OperationInquiry::whereNull('order_id')->whereNull('duplicate_of_id')], ['qualifications', 'Nachweise prüfen', EmployeeQualification::where('status', 'pending')], ['absences', 'Abwesenheiten prüfen', AbsenceRequest::where('status', 'pending')], ['times', 'Zeiten prüfen', WorkTimeEntry::where('status', 'submitted')]] as [$slug,$label,$query]) {
            if (isset($modules[$slug])) {
                $queue[] = ['slug' => $slug, 'label' => $label, 'count' => $query->count()];
            }
        }
        $shifts = isset($modules['shift-management']) ? Shift::notCancelled()->during(now(config('operations.display_timezone'))->startOfDay(), now()->addDays(14))->with(['order.customer'])->withCount(['assignments as reserved' => fn ($q) => $q->blocking()])->orderBy('starts_at')->get() : collect();

        return view('livewire.operations.cockpit', ['modules' => $modules, 'queue' => $queue, 'shifts' => $shifts, 'openSlots' => $shifts->sum(fn ($shift) => max(0, $shift->required_staff - $shift->reserved))]);
    }
}
