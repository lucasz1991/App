<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
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
        $nextWeekStart = $weekStart->addWeek()->startOfDay();

        $shifts = Shift::query()
            ->with(['order.customer', 'assignments.user'])
            ->where('status', '!=', ShiftStatus::Cancelled->value)
            ->where('ends_at', '>', $weekStart->startOfDay()->utc())
            ->where('starts_at', '<', $nextWeekStart->utc())
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

        $requiredCount = (int) $shifts->sum('required_staff');
        $reservedCount = $shifts->sum(fn (Shift $shift): int => $shift->assignments
            ->filter(fn (ShiftAssignment $assignment): bool => in_array(
                (string) ($assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status),
                ShiftAssignmentStatus::blockingValues(),
                true,
            ))
            ->count());

        return view('livewire.admin.operations.calendar', [
            'days' => $days,
            'weekStartDate' => $weekStart,
            'weekEndDate' => $weekEnd,
            'shiftCount' => $shifts->count(),
            'requiredCount' => $requiredCount,
            'reservedCount' => $reservedCount,
            'openCount' => max(0, $requiredCount - $reservedCount),
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
