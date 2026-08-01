<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Carbon\CarbonImmutable;
use Livewire\Component;

class Calendar extends Component
{
    use SupportsOperationsUi;

    public string $weekStart = '';

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->weekStart = CarbonImmutable::now()->startOfWeek()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->ensureAdmin();
        $this->weekStart = $this->resolvedWeekStart()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->ensureAdmin();
        $this->weekStart = $this->resolvedWeekStart()->addWeek()->toDateString();
    }

    public function today(): void
    {
        $this->ensureAdmin();
        $this->weekStart = CarbonImmutable::now()->startOfWeek()->toDateString();
    }

    public function render()
    {
        $this->ensureAdmin();
        $weekStart = $this->resolvedWeekStart();
        $weekEnd = $weekStart->endOfWeek();

        $shifts = Shift::query()
            ->with(['order.customer', 'assignments.user'])
            ->where('ends_at', '>=', $weekStart->startOfDay())
            ->where('starts_at', '<=', $weekEnd->endOfDay())
            ->orderBy('starts_at')
            ->get();

        $days = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $shifts): array {
            $date = $weekStart->addDays($offset);

            return [
                'date' => $date,
                'is_today' => $date->isToday(),
                'shifts' => $shifts->filter(fn (Shift $shift): bool => $shift->starts_at->lt($date->endOfDay())
                    && $shift->ends_at->gt($date->startOfDay()))->values(),
            ];
        });

        $confirmedStatus = $this->enumDefault(ShiftAssignmentStatus::class, 'confirmed');
        $requiredCount = (int) $shifts->sum('required_staff');
        $confirmedCount = $shifts->sum(fn (Shift $shift): int => $shift->assignments
            ->filter(fn (ShiftAssignment $assignment): bool => (string) ($assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status) === $confirmedStatus)
            ->count());

        return view('livewire.admin.operations.calendar', [
            'days' => $days,
            'weekStartDate' => $weekStart,
            'weekEndDate' => $weekEnd,
            'shiftCount' => $shifts->count(),
            'requiredCount' => $requiredCount,
            'confirmedCount' => $confirmedCount,
            'openCount' => max(0, $requiredCount - $confirmedCount),
        ]);
    }

    private function resolvedWeekStart(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->weekStart)->startOfWeek();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfWeek();
        }
    }
}
