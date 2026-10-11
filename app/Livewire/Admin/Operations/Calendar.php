<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Operations\PlanningCapacityService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OrderSelection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Calendar extends Component
{
    use SupportsOperationsUi;

    public string $weekStart = '';

    public string $anchorDate = '';

    #[Locked]
    public array $planningRange = [];

    /** Monatsansicht ist der Einstieg: der ganze Monat mit Lücken auf einen Blick. */
    public string $viewMode = 'month';

    public string $search = '';

    public string $customerFilter = 'all';

    public string $orderFilter = 'all';

    public string $orderSearch = '';

    public string $statusFilter = 'active';

    public bool $onlyOpen = false;

    /** Seitenpanel wie im Schichtplan (rtShiftDetailDrawer): Details einer angeklickten Schicht. */
    public bool $detailOpen = false;

    /** Vom gemeinsamen Drawer mitgeführt; der Kalender hat kein eigenes Schichtformular. */
    public bool $formOpen = false;

    #[Locked]
    public ?int $selectedShiftId = null;

    public function mount(): void
    {
        $this->ensureAdmin();
        $date = CarbonImmutable::now($this->displayTimezone());
        $this->anchorDate = $date->toDateString();
        $this->weekStart = $date->startOfWeek()->toDateString();
        if (request()->has('customer')) {
            $value = request()->query('customer');
            $customerId = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            abort_unless($customerId && Customer::whereKey($customerId)->exists(), 404);
            $this->customerFilter = (string) $customerId;
        }
        if (request()->has('order')) {
            $value = request()->query('order');
            $orderId = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            abort_unless($orderId, 404);
            $order = Order::query()->when($this->customerFilter !== 'all', fn (Builder $query) => $query->where('customer_id', (int) $this->customerFilter))->find($orderId);
            abort_unless($order, 404);
            $this->orderFilter = (string) $order->id;
            $this->customerFilter = (string) $order->customer_id;
            $this->anchorDate = $order->starts_at?->setTimezone($this->displayTimezone())->toDateString() ?? $this->anchorDate;
            $this->weekStart = $this->resolvedAnchor()->startOfWeek()->toDateString();
            if ($order->status->value === 'cancelled') {
                $this->statusFilter = 'all';
            }
        }
        if (request()->hasAny(['from', 'until'])) {
            abort_unless(is_string(request()->query('from')) && is_string(request()->query('until')), 422);
            try {
                [$from, $until] = app(PlanningCapacityService::class)->range(request()->query('from'), request()->query('until'));
            } catch (ValidationException) {
                abort(422, 'Ungültiger Planungszeitraum.');
            }
            $this->planningRange = ['from' => $from->toDateString(), 'until' => $until->subDay()->toDateString()];
            $this->anchorDate = $from->toDateString();
            $this->weekStart = $from->startOfWeek()->toDateString();
        }
        $this->notifyPlanningPeriod();
        $this->notifyPlanningFilters();
    }

    public function updatedCustomerFilter(): void
    {
        $this->ensureAdmin();
        $this->orderSearch = '';
        if ($this->orderFilter !== 'all' && $this->customerFilter !== 'all'
            && ! Order::whereKey($this->orderFilter)->where('customer_id', $this->customerFilter)->exists()) {
            $this->orderFilter = 'all';
        }
        $this->notifyPlanningFilters();
    }

    public function updatedOrderFilter(): void
    {
        $this->ensureAdmin();
        $this->notifyPlanningFilters();
    }

    public function resetFilters(): void
    {
        $this->ensureAdmin();
        $this->reset(['search', 'customerFilter', 'orderFilter', 'orderSearch', 'statusFilter', 'onlyOpen']);
        $this->notifyPlanningFilters();
    }

    public function switchView(string $view): void
    {
        $this->ensureAdmin();
        abort_unless(in_array($view, ['day', 'week', 'month', 'list'], true), 404);
        if ($this->viewMode !== $view) {
            $this->planningRange = [];
        }
        $this->viewMode = $view;
        $this->resetValidation();
        $this->notifyPlanningPeriod();
    }

    public function updatedAnchorDate(): void
    {
        $this->ensureAdmin();
        $this->validate(['anchorDate' => 'required|date_format:Y-m-d']);
        $this->planningRange = [];
        $this->weekStart = $this->resolvedAnchor()->startOfWeek()->toDateString();
        $this->notifyPlanningPeriod();
    }

    public function showDay(string $date): void
    {
        $this->ensureAdmin();
        $this->anchorDate = $date;
        $this->viewMode = 'day';
        $this->updatedAnchorDate();
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
        $this->planningRange = [];
        $date = $this->resolvedAnchor();
        $date = match ($this->viewMode) {
            'day' => $date->addDays($direction),
            'month' => $date->addMonthsNoOverflow($direction),
            default => $date->addWeeks($direction),
        };
        $this->anchorDate = $date->toDateString();
        $this->weekStart = $date->startOfWeek()->toDateString();
        $this->notifyPlanningPeriod();
    }

    public function previousWeek(): void
    {
        $this->ensureAdmin();
        $this->planningRange = [];
        $this->weekStart = $this->resolvedWeekStart()->subWeek()->toDateString();
        $this->anchorDate = $this->weekStart;
        $this->notifyPlanningPeriod();
    }

    public function nextWeek(): void
    {
        $this->ensureAdmin();
        $this->planningRange = [];
        $this->weekStart = $this->resolvedWeekStart()->addWeek()->toDateString();
        $this->anchorDate = $this->weekStart;
        $this->notifyPlanningPeriod();
    }

    public function today(): void
    {
        $this->ensureAdmin();
        $this->planningRange = [];
        $date = CarbonImmutable::now($this->displayTimezone());
        $this->anchorDate = $date->toDateString();
        $this->weekStart = $date->startOfWeek()->toDateString();
        $this->notifyPlanningPeriod();
    }

    public function openDetails(int $id): void
    {
        $this->ensureAdmin();
        Shift::query()->findOrFail($id);
        $this->selectedShiftId = $id;
        $this->detailOpen = true;
        $this->formOpen = false;
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
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->anchorDate, $this->displayTimezone());
            if ($date && $date->toDateString() === $this->anchorDate) {
                return $date;
            }
        } catch (\Throwable) {
        }

        return CarbonImmutable::now($this->displayTimezone())->startOfDay();
    }

    private function resolvedWeekStart(): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart, $this->displayTimezone());
            if ($date && $date->toDateString() === $this->weekStart) {
                return $date->startOfWeek();
            }
        } catch (\Throwable) {
        }

        return $this->resolvedAnchor()->startOfWeek();
    }

    private function visiblePeriod(): array
    {
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

        return [$from, $until];
    }

    private function notifyPlanningPeriod(): void
    {
        [$from, $until] = $this->visiblePeriod();
        $this->dispatch('operations-period-changed',
            from: $this->planningRange['from'] ?? $from->toDateString(),
            until: $this->planningRange['until'] ?? $until->subDay()->toDateString());
    }

    private function notifyPlanningFilters(): void
    {
        $customer = $this->customerFilter === 'all' ? null : filter_var($this->customerFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $order = $this->orderFilter === 'all' ? null : filter_var($this->orderFilter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($customer === false || $order === false, 422);
        if ($customer !== null) {
            Customer::findOrFail($customer);
        }
        if ($order !== null) {
            Order::query()->when($customer !== null, fn (Builder $query) => $query->where('customer_id', $customer))->findOrFail($order);
        }
        $this->dispatch('operations-planning-filters-changed', customer: $customer, order: $order);
    }

    public function render()
    {
        $this->ensureAdmin();
        abort_unless(in_array($this->viewMode, ['day', 'week', 'month', 'list'], true), 404);
        $anchor = $this->resolvedAnchor();
        [$from, $until] = $this->visiblePeriod();
        $shifts = Shift::query()
            ->with(['order.customer', 'assignments.user'])
            ->where('ends_at', '>', $from->utc())
            ->where('starts_at', '<', $until->utc())
            ->when($this->statusFilter === 'active', fn (Builder $q) => $q->where('status', '!=', ShiftStatus::Cancelled->value))
            ->when(! in_array($this->statusFilter, ['all', 'active'], true), fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->when($this->customerFilter !== 'all', fn (Builder $q) => $q->whereHas('order', fn (Builder $order) => $order->where('customer_id', $this->customerFilter)))
            ->when($this->orderFilter !== 'all', fn (Builder $q) => $q->where('order_id', $this->orderFilter))
            ->when(filled($this->search), function (Builder $q): void {
                $term = '%'.mb_substr(trim($this->search), 0, 100).'%';
                $q->where(fn (Builder $q) => $q->where('title', 'like', $term)->orWhere('location_name', 'like', $term)->orWhereHas('order.customer', fn (Builder $customer) => $customer->where('company_name', 'like', $term)));
            })
            ->orderBy('starts_at')
            ->get()
            ->map(function (Shift $shift): Shift {
                $reserved = $shift->assignments->filter(fn ($assignment) => in_array($assignment->status->value, ShiftAssignmentStatus::blockingValues(), true))->count();
                $requested = $shift->assignments->filter(fn ($assignment) => $assignment->status === ShiftAssignmentStatus::Requested)->count();
                $shift->setAttribute('calendar_reserved', $reserved);
                $shift->setAttribute('calendar_requested', $requested);
                $closed = in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true);
                $open = $closed ? 0 : max(0, $shift->required_staff - $reserved);
                $shift->setAttribute('calendar_open', $open);
                // Ein Zustand je Schicht, damit Farbe und Text ohne Legende eindeutig sind.
                $shift->setAttribute('calendar_state', $closed ? 'closed' : ($open > 0 ? 'open' : ($requested > 0 ? 'pending' : 'staffed')));
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

        $selectedShift = $this->detailOpen && $this->selectedShiftId
            ? Shift::query()->with(array_merge(['order.customer', 'assignments.user.profile', 'assignments.user.currentTeam'], OperationsAccess::ready() ? ['qualifications'] : []))->find($this->selectedShiftId)
            : null;

        return view('livewire.admin.operations.calendar', [
            'selectedShift' => $selectedShift,
            'days' => $days, 'shifts' => $shifts,
            'weekStartDate' => $from, 'weekEndDate' => $until->subDay(),
            'periodLabel' => match ($this->viewMode) {
                'day' => $anchor->locale('de')->isoFormat('dddd, D. MMMM YYYY'),
                'month' => $anchor->locale('de')->isoFormat('MMMM YYYY'),
                default => $from->format('d.m.').' – '.$until->subDay()->format('d.m.Y'),
            },
            'displayTimezone' => $this->displayTimezone(),
            'customers' => Customer::orderBy('company_name')->get(['id', 'company_name']),
            'orders' => OrderSelection::options($this->orderSearch, [$this->orderFilter], $this->customerFilter !== 'all' ? (int) $this->customerFilter : null, true),
            'statusOptions' => $this->enumOptions(ShiftStatus::class),
            'shiftCount' => $shifts->count(),
            'requiredCount' => (int) $shifts->sum('required_staff'),
            'reservedCount' => (int) $shifts->sum('calendar_reserved'),
            'openCount' => (int) $shifts->sum('calendar_open'),
            'filterCount' => (int) ($this->customerFilter !== 'all') + (int) ($this->orderFilter !== 'all') + (int) ($this->statusFilter !== 'active') + (int) $this->onlyOpen,
        ]);
    }
}
