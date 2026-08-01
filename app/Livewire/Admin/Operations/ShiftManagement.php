<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ShiftManagement extends Component
{
    use SupportsOperationsUi;

    public string $rangeFrom = '';

    public string $rangeTo = '';

    public string $orderFilter = 'all';

    public ?int $selectedShiftId = null;

    public bool $formOpen = false;

    public ?int $editingShiftId = null;

    public ?int $orderId = null;

    public string $title = '';

    public string $roleName = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $timezone = 'Europe/Berlin';

    public string $locationName = '';

    public int $requiredStaff = 1;

    public string $status = '';

    public string $notes = '';

    public ?int $employeeId = null;

    public string $assignmentStatus = '';

    public string $assignmentNote = '';

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->rangeFrom = now()->startOfWeek()->format('Y-m-d');
        $this->rangeTo = now()->endOfWeek()->addWeek()->format('Y-m-d');
        $this->status = $this->enumDefault(ShiftStatus::class, 'draft');
        $this->assignmentStatus = $this->enumDefault(ShiftAssignmentStatus::class, 'confirmed');
        $this->selectedShiftId = Shift::query()->orderBy('starts_at')->value('id');
    }

    public function createShift(): void
    {
        $this->ensureAdmin();
        $this->resetShiftForm();
        $this->orderId = $this->orderFilter !== 'all' ? (int) $this->orderFilter : null;
        $this->startsAt = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->endsAt = now()->addDay()->setTime(16, 0)->format('Y-m-d\TH:i');
        $this->formOpen = true;
    }

    public function editShift(int $shiftId): void
    {
        $this->ensureAdmin();
        $shift = Shift::query()->findOrFail($shiftId);

        $this->editingShiftId = $shift->id;
        $this->orderId = $shift->order_id;
        $this->title = (string) $shift->title;
        $this->roleName = (string) $shift->role_name;
        $this->startsAt = $shift->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->endsAt = $shift->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->timezone = (string) ($shift->timezone ?: 'Europe/Berlin');
        $this->locationName = (string) $shift->location_name;
        $this->requiredStaff = (int) $shift->required_staff;
        $this->status = (string) ($shift->status instanceof \BackedEnum ? $shift->status->value : $shift->status);
        $this->notes = (string) $shift->notes;
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function selectShift(int $shiftId): void
    {
        $this->ensureAdmin();
        Shift::query()->findOrFail($shiftId);
        $this->selectedShiftId = $shiftId;
        $this->resetValidation('assignment');
    }

    public function saveShift(ShiftSchedulingService $schedulingService): void
    {
        $this->ensureAdmin();
        $currentOrderId = $this->editingShiftId
            ? Shift::query()->whereKey($this->editingShiftId)->value('order_id')
            : null;

        $validated = $this->validate([
            'orderId' => [
                'required',
                'integer',
                Rule::exists('orders', 'id')->where(function ($query) use ($currentOrderId): void {
                    $query->whereNull('deleted_at')
                        ->where(function ($query) use ($currentOrderId): void {
                            $query->where('status', '!=', 'cancelled');

                            if ($currentOrderId !== null) {
                                $query->orWhere('id', $currentOrderId);
                            }
                        });
                }),
            ],
            'title' => ['required', 'string', 'max:180'],
            'roleName' => ['required', 'string', 'max:160'],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'timezone' => ['required', 'timezone'],
            'locationName' => ['nullable', 'string', 'max:180'],
            'requiredStaff' => ['required', 'integer', 'min:1', 'max:999'],
            'status' => ['required', Rule::enum(ShiftStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $shift = $this->editingShiftId
            ? Shift::query()->findOrFail($this->editingShiftId)
            : new Shift(['created_by' => auth()->id()]);

        $attributes = [
            'order_id' => $validated['orderId'],
            'title' => trim($validated['title']),
            'role_name' => trim($validated['roleName']) ?: null,
            'starts_at' => Carbon::parse($validated['startsAt'], $validated['timezone'])->utc(),
            'ends_at' => Carbon::parse($validated['endsAt'], $validated['timezone'])->utc(),
            'timezone' => $validated['timezone'],
            'location_name' => trim($validated['locationName']) ?: null,
            'required_staff' => $validated['requiredStaff'],
            'status' => $validated['status'],
            'notes' => trim($validated['notes']) ?: null,
        ];

        try {
            $shift = $schedulingService->save($shift, $attributes, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->selectedShiftId = $shift->id;
        $this->formOpen = false;
        $this->resetShiftForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Schicht gespeichert.');
    }

    public function assignEmployee(ShiftAssignmentService $assignmentService): void
    {
        $this->ensureAdmin();
        abort_unless($this->selectedShiftId, 404);

        $validated = $this->validate([
            'employeeId' => ['required', 'integer', 'exists:users,id'],
            'assignmentStatus' => ['required', Rule::in(ShiftAssignmentStatus::blockingValues())],
            'assignmentNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $shift = Shift::query()->findOrFail($this->selectedShiftId);
        $employee = User::query()->where('status', true)->where('role', 'staff')->findOrFail($validated['employeeId']);

        try {
            $assignmentService->assign(
                $shift,
                $employee,
                auth()->user(),
                $validated['assignmentStatus'],
                trim($validated['assignmentNote']) ?: null,
            );
            $this->reset(['employeeId', 'assignmentNote']);
            $this->resetValidation('assignment');
            $this->dispatch('swal:toast', type: 'success', text: 'Mitarbeiter zugewiesen.');
        } catch (ValidationException $exception) {
            $this->addError('assignment', collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
        } catch (\DomainException $exception) {
            $this->addError('assignment', $exception->getMessage());
        }
    }

    public function removeAssignment(int $assignmentId, ShiftAssignmentService $assignmentService): void
    {
        $this->ensureAdmin();
        abort_unless($this->selectedShiftId, 404);

        $assignment = ShiftAssignment::query()
            ->where('shift_id', $this->selectedShiftId)
            ->findOrFail($assignmentId);

        try {
            $assignmentService->cancel($assignment, auth()->user(), 'Aus der Einsatzplanung entfernt.');
            $this->resetValidation('assignment');
            $this->dispatch('swal:toast', type: 'success', text: 'Zuweisung entfernt.');
        } catch (ValidationException $exception) {
            $this->addError('assignment', collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
        } catch (\DomainException $exception) {
            $this->addError('assignment', $exception->getMessage());
        }
    }

    public function render()
    {
        $this->ensureAdmin();

        [$from, $to] = $this->resolvedRange();

        $shifts = Shift::query()
            ->with(['order.customer', 'assignments.user'])
            ->where('ends_at', '>=', $from->copy()->utc())
            ->where('starts_at', '<=', $to->copy()->utc())
            ->when($this->orderFilter !== 'all', fn (Builder $query) => $query->where('order_id', (int) $this->orderFilter))
            ->orderBy('starts_at')
            ->get();

        $selectedShift = $this->selectedShiftId
            ? Shift::query()->with(['order.customer', 'assignments.user'])->find($this->selectedShiftId)
            : null;

        $activeShifts = $shifts->reject(fn (Shift $shift): bool => $shift->status === ShiftStatus::Cancelled);
        $reservedCount = $activeShifts->sum(fn (Shift $shift): int => $shift->assignments
            ->filter(fn (ShiftAssignment $assignment): bool => in_array(
                (string) ($assignment->status instanceof \BackedEnum ? $assignment->status->value : $assignment->status),
                ShiftAssignmentStatus::blockingValues(),
                true,
            ))
            ->count());
        $requiredCount = (int) $activeShifts->sum('required_staff');

        return view('livewire.admin.operations.shift-management', [
            'shifts' => $shifts,
            'selectedShift' => $selectedShift,
            'orders' => Order::query()
                ->with('customer')
                ->where(function (Builder $query): void {
                    $query->where('status', '!=', 'cancelled');

                    if ($this->orderId !== null) {
                        $query->orWhere('id', $this->orderId);
                    }
                })
                ->orderByDesc('starts_at')
                ->get(),
            'employees' => User::query()->with('profile')->where('status', true)->where('role', 'staff')->orderBy('name')->get(),
            'statusOptions' => $this->enumOptions(ShiftStatus::class),
            'assignmentStatusOptions' => collect($this->enumOptions(ShiftAssignmentStatus::class))
                ->whereIn('value', ShiftAssignmentStatus::blockingValues())
                ->values()
                ->all(),
            'shiftCount' => $activeShifts->count(),
            'requiredCount' => $requiredCount,
            'reservedCount' => $reservedCount,
            'openCount' => max(0, $requiredCount - $reservedCount),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvedRange(): array
    {
        try {
            $from = Carbon::createFromFormat('Y-m-d', $this->rangeFrom)->startOfDay();
        } catch (\Throwable) {
            $from = now()->startOfWeek()->startOfDay();
            $this->rangeFrom = $from->toDateString();
        }

        try {
            $to = Carbon::createFromFormat('Y-m-d', $this->rangeTo)->endOfDay();
        } catch (\Throwable) {
            $to = $from->copy()->addWeeks(2)->endOfDay();
            $this->rangeTo = $to->toDateString();
        }

        if ($to->lessThan($from)) {
            $to = $from->copy()->endOfDay();
            $this->rangeTo = $to->toDateString();
        }

        if ($from->diffInDays($to) > 93) {
            $to = $from->copy()->addDays(93)->endOfDay();
            $this->rangeTo = $to->toDateString();
        }

        return [$from, $to];
    }

    private function resetShiftForm(): void
    {
        $this->reset([
            'editingShiftId', 'orderId', 'title', 'roleName', 'startsAt', 'endsAt', 'locationName', 'notes',
        ]);
        $this->timezone = 'Europe/Berlin';
        $this->requiredStaff = 1;
        $this->status = $this->enumDefault(ShiftStatus::class, 'draft');
        $this->resetValidation();
    }
}
