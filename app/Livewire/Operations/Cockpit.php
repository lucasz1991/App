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
    /**
     * Feather-Icon je Warteschlangen-Kachel - dieselben Namen wie in
     * ApplicationNavigation::$icons, damit KPI-Kachel und Sidebar-Eintrag
     * fuer dasselbe Modul optisch zusammengehoeren.
     */
    private const QUEUE_ICONS = [
        'inquiries' => 'inbox',
        'qualifications' => 'award',
        'absences' => 'calendar',
        'times' => 'check-circle',
    ];

    public function render()
    {
        OperationsAccess::requireReady();
        $user = auth()->user();
        $modules = OperationsNavigation::forUser($user);
        abort_unless(count($modules), 403);
        $queue = [];
        foreach ([['inquiries', 'Offene Anfragen', OperationInquiry::whereNull('order_id')->whereNull('duplicate_of_id')], ['qualifications', 'Nachweise prüfen', EmployeeQualification::where('status', 'pending')], ['absences', 'Abwesenheiten prüfen', AbsenceRequest::where('status', 'pending')], ['times', 'Zeiten prüfen', WorkTimeEntry::where('status', 'submitted')]] as [$slug,$label,$query]) {
            if (isset($modules[$slug])) {
                $queue[] = ['slug' => $slug, 'label' => $label, 'count' => $query->count(), 'icon' => self::QUEUE_ICONS[$slug]];
            }
        }
        $shifts = isset($modules['shift-management']) ? Shift::notCancelled()->during(now(config('operations.display_timezone'))->startOfDay(), now()->addDays(14))->with(['order.customer'])->withCount(['assignments as reserved' => fn ($q) => $q->blocking()])->orderBy('starts_at')->get() : collect();

        return view('livewire.operations.cockpit', [
            'modules' => $modules,
            'queue' => $queue,
            'shifts' => $shifts,
            'openSlots' => $shifts->sum(fn ($shift) => max(0, $shift->required_staff - $shift->reserved)),
            'weekCoverage' => isset($modules['shift-management']) ? $this->weekCoverage($shifts) : null,
        ]);
    }

    /**
     * Rollierende 7-Tage-Sicht fuer das Besetzungsdiagramm, aus den bereits
     * geladenen 14-Tage-Schichten aggregiert - keine zusaetzliche Abfrage.
     *
     * @return array{labels:array<int,string>, required:array<int,int>, reserved:array<int,int>}
     */
    private function weekCoverage(\Illuminate\Support\Collection $shifts): array
    {
        $today = now(config('operations.display_timezone'))->startOfDay();
        $days = collect(range(0, 6))->map(fn (int $offset) => $today->copy()->addDays($offset));
        $byDate = $shifts->groupBy(fn (Shift $shift) => $shift->starts_at->toDateString());

        return [
            'labels' => $days->map(fn ($day) => $day->translatedFormat('D d.'))->values()->all(),
            'required' => $days->map(fn ($day) => (int) $byDate->get($day->toDateString(), collect())->sum('required_staff'))->values()->all(),
            'reserved' => $days->map(fn ($day) => (int) $byDate->get($day->toDateString(), collect())->sum('reserved'))->values()->all(),
        ];
    }
}
