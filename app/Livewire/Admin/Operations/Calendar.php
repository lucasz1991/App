<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\Shift;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class Calendar extends Component
{
    use SupportsOperationsUi;

    public string $weekStart = '';

    public string $anchorDate = '';

    public string $viewMode = 'week';

    public string $search = '';

    public string $customerFilter = 'all';

    public string $statusFilter = 'active';

    public bool $onlyOpen = false;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->today();
    }

    public function switchView(string $view): void
    {
        $this->ensureAdmin();
        abort_unless(in_array($view, ['day', 'week', 'month', 'list'], true), 404);
        $this->viewMode = $view;
        $this->resetValidation();
    }

    public function updatedAnchorDate(): void
    {
        $this->ensureAdmin();
        $this->validate(['anchorDate' => 'required|date_format:Y-m-d']);
        $this->weekStart = $this->resolvedAnchor()->startOfWeek()->toDateString();
    }

    public function showDay(string $date): void
    {
        $this->ensureAdmin();
        $this->anchorDate = $date;
        $this->updatedAnchorDate();
        $this->viewMode = 'day';
    }

    public function previousPeriod(): void
    {
        $this->movePeriod(-1);
    }

    public function nextPeriod(): void
    {
        $this->movePeriod(1);
    }

    private function movePeriod(int $direction): void
    {
        $this->ensureAdmin();
        $date = $this->resolvedAnchor();
        $date = match ($this->viewMode) {
            'day' => $date->addDays($direction),
            'month' => $date->addMonthsNoOverflow($direction),
            default => $date->addWeeks($direction),
        };
        $this->anchorDate = $date->toDateString();
        $this->weekStart = $date->startOfWeek()->toDateString();
    }

    public function previousWeek(): void
    {
        $this->ensureAdmin();
        $this->weekStart = $this->resolvedWeekStart()->subWeek()->toDateString();
        $this->anchorDate = $this->weekStart;
    }

    public function nextWeek(): void
    {
        $this->ensureAdmin();
        $this->weekStart = $this->resolvedWeekStart()->addWeek()->toDateString();
        $this->anchorDate = $this->weekStart;
    }

    public function today(): void
    {
        $this->ensureAdmin();
        $date = CarbonImmutable::now($this->displayTimezone());
        $this->anchorDate = $date->toDateString();
        $this->weekStart = $date->startOfWeek()->toDateString();
    }

    public function openShift(int $id): void
    {
        $this->ensureAdmin();
        Shift::query()->findOrFail($id);
        $this->redirectRoute(OperationsAccess::ready() ? 'operations.workspace' : 'admin.operations.preview', ['module' => 'shift-management', 'shift' => $id]);
    }

    private function displayTimezone(): string
    {
        return (string) config('operations.display_timezone', 'Europe/Berlin');
    }

    private function resolvedAnchor(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $this->anchorDate, $this->displayTimezone());
        } catch (\Throwable) {
            return CarbonImmutable::now($this->displayTimezone())->startOfDay();
        }
    }

    private function resolvedWeekStart(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart, $this->displayTimezone())->startOfWeek();
        } catch (\Throwable) {
            return $this->resolvedAnchor()->startOfWeek();
        }
    }

    public function render()
    {
        $this->ensureAdmin();
        abort_unless(in_array($this->viewMode, ['day', 'week', 'month', 'list'], true), 404);
        $anchor = $this->resolvedAnchor();
        $from = match ($this->viewMode) {
            'day' => $anchor->startOfDay(),
            'month' => $anchor->startOfMonth()->startOfWeek(),
            default => $this->resolvedWeekStart(),
        };
        $until = match ($this->viewMode) {
            'day' => $from->addDay(),
            'month' => $anchor->endOfMonth()->endOfWeek()->addDay()->startOfDay(),
            default => $from->addWeek(),
        };
        $shifts = Shift::query()
            ->with(['order.customer', 'assignments.user'])
            ->where('ends_at', '>', $from->utc())
            ->where('starts_at', '<', $until->utc())
            ->when($this->statusFilter === 'active', fn (Builder $q) => $q->where('status', '!=', ShiftStatus::Cancelled->value))
            ->when(! in_array($this->statusFilter, ['all', 'active'], true), fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->customerFilter !== 'all', fn (Builder $q) => $q->whereHas('order', fn (Builder $order) => $order->where('customer_id', $this->customerFilter)))
            ->when(filled($this->search), function (Builder $q): void {
                $term = '%'.mb_substr(trim($this->search), 0, 100).'%';
                $q->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('location_name', 'like', $term)->orWhereHas('order.customer', fn (Builder $customer) => $customer->where('company_name', 'like', $term)));
            })
            ->orderBy('starts_at')
            ->get()
            ->map(function (Shift $shift): Shift {
                $reserved = $shift->assignments->filter(fn ($assignment) => in_array($assignment->status->value, ShiftAssignmentStatus::blockingValues(), true))->count();
                $shift->setAttribute('calendar_reserved', $reserved);
                $shift->setAttribute('calendar_open', $shift->status === ShiftStatus::Cancelled ? 0 : max(0, $shift->required_staff - $reserved));
                $shift->setAttribute('calendar_starts', $shift->starts_at->setTimezone($this->displayTimezone()));
                $shift->setAttribute('calendar_ends', $shift->ends_at->setTimezone($this->displayTimezone()));

                return $shift;
            })
            ->when($this->onlyOpen, fn ($items) => $items->filter(fn (Shift $shift) => $shift->calendar_open > 0))->values();

        $days = collect(range(0, (int) $from->diffInDays($until) - 1))->map(function (int $offset) use ($from, $anchor, $shifts): array {
            $date = $from->addDays($offset);

            return [
                'date' => $date,
                'is_today' => $date->isToday(),
                'in_month' => $date->month === $anchor->month,
                'shifts' => $shifts->filter(fn (Shift $shift) => $shift->starts_at->lt($date->addDay()) && $shift->ends_at->gt($date))->values(),
            ];
        });

        return view('livewire.admin.operations.calendar', [
            'days' => $days, 'shifts' => $shifts,
            'weekStartDate' => $from, 'weekEndDate' => $until->subDay(),
            'periodLabel' => match ($this->viewMode) {
                'day' => $anchor->locale('de')->isoFormat('dddd, D. MMMM YYYY'),
                'month' => $anchor->locale('de')->isoFormat('MMMM YYYY'),
                default => $from->format('d.m.').' – '.$until->subDay()->format('d.m.Y'),
            },
            'displayTimezone' => $this->displayTimezone(),
            'customers' => Customer::orderBy('company_name')->get(['id', 'company_name']),
            'statusOptions' => $this->enumOptions(ShiftStatus::class),
            'shiftCount' => $shifts->count(),
            'requiredCount' => (int) $shifts->sum('required_staff'),
            'reservedCount' => (int) $shifts->sum('calendar_reserved'),
            'openCount' => (int) $shifts->sum('calendar_open'),
        ]);
    }
}
