<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ShiftManagement extends Component
{
    use SupportsOperationsUi;

    public string $rangeFrom = '';

    public string $rangeTo = '';

    public string $orderFilter = 'all';

    public string $search = '';

    public string $statusFilter = 'all';

    #[Locked]
    public string $viewMode = 'table';

    public function setView(string $view): void
    {
        $this->ensureAdmin();
        abort_unless(in_array($view, ['table', 'day', 'staffing'], true), 422);
        $this->viewMode = $view;
    }

    public ?int $selectedShiftId = null;

    public bool $formOpen = false;

    public bool $detailOpen = false;

    public function openDetails(int $id): void
    {
        $this->selectShift($id);
        $this->formOpen = false;
        $this->detailOpen = true;
    }

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

    #[Locked]
    public ?int $editingRevision = null;

    public int $plannedBreakMinutes = 0;

    public array $qualificationIds = [];

    public function mount(): void
    {
        $this->ensureAdmin();
        $today = now((string) config('operations.display_timezone', 'Europe/Berlin'));
        $this->rangeFrom = $today->copy()->startOfWeek()->format('Y-m-d');
        $this->rangeTo = $today->copy()->endOfWeek()->addWeek()->format('Y-m-d');
        $this->status = $this->enumDefault(ShiftStatus::class, 'draft');
        $this->assignmentStatus = $this->enumDefault(ShiftAssignmentStatus::class, 'confirmed');
        $this->selectedShiftId = Shift::query()->orderBy('starts_at')->value('id');
        if (request()->integer('shift') && Shift::whereKey(request()->integer('shift'))->exists()) {
            $this->selectedShiftId = request()->integer('shift');
            $this->detailOpen = true;
        }
    }

    #[On('operations-create')]
    public function createShift(): void
    {
        $this->ensureAdmin();
        $this->detailOpen = false;
        $this->resetShiftForm();
        $this->orderId = $this->orderFilter !== 'all' ? (int) $this->orderFilter : null;
        $this->startsAt = now($this->timezone)->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->endsAt = now($this->timezone)->addDay()->setTime(16, 0)->format('Y-m-d\TH:i');
        $this->formOpen = true;
    }

    public function editShift(int $shiftId): void
    {
        $this->ensureAdmin();
        $this->detailOpen = false;
        $shift = Shift::query()->findOrFail($shiftId);

        $this->editingShiftId = $shift->id;
        $this->editingRevision = $shift->revision;
        $this->plannedBreakMinutes = $shift->planned_break_minutes ?? 0;
        $this->qualificationIds = OperationsAccess::ready() ? $shift->qualifications()->pluck('qualification_types.id')->all() : [];
        $this->orderId = $shift->order_id;
        $this->title = (string) $shift->title;
        $this->roleName = (string) $shift->role_name;
        $this->timezone = (string) ($shift->timezone ?: 'Europe/Berlin');
        $this->startsAt = $shift->starts_at?->setTimezone($this->timezone)->format('Y-m-d\TH:i') ?? '';
        $this->endsAt = $shift->ends_at?->setTimezone($this->timezone)->format('Y-m-d\TH:i') ?? '';
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
            'plannedBreakMinutes' => ['required', 'integer', 'min:0', 'max:1439'],
            'qualificationIds' => ['array', 'max:50'],
            'qualificationIds.*' => ['integer', 'distinct', 'exists:qualification_types,id'],
        ]);

        $shift = $this->editingShiftId
            ? Shift::query()->findOrFail($this->editingShiftId)
            : new Shift(['created_by' => auth()->id()]);

        $attributes = [
            'order_id' => $validated['orderId'],
            'title' => trim($validated['title']),
            'role_name' => trim($validated['roleName']) ?: null,
            'starts_at' => OperationsDateTime::local($validated['startsAt'], $validated['timezone']),
            'ends_at' => OperationsDateTime::local($validated['endsAt'], $validated['timezone'], 'endsAt'),
            'timezone' => $validated['timezone'],
            'location_name' => trim($validated['locationName']) ?: null,
            'required_staff' => $validated['requiredStaff'],
            'status' => $validated['status'],
            'notes' => trim($validated['notes']) ?: null,
        ];
        if (OperationsAccess::ready()) {
            $attributes['planned_break_minutes'] = $this->plannedBreakMinutes;
            $attributes['expected_revision'] = $this->editingRevision;
        }

        try {
            $shift = DB::transaction(function () use ($schedulingService, $shift, $attributes) {
                $saved = $schedulingService->save($shift, $attributes, auth()->user());
                if (OperationsAccess::ready()) {
                    $ids = array_map('intval', $this->qualificationIds);
                    $currentIds = $saved->qualifications()->pluck('qualification_types.id')->all();
                    sort($ids);
                    sort($currentIds);
                    if ($ids !== $currentIds) {
                        app(PlanPublicationService::class)->requirements($saved, $saved->revision, $ids, auth()->user());
                    }
                }

                return $saved->fresh();
            });
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
            ->where('ends_at', '>', $from->copy()->utc())
            ->where('starts_at', '<=', $to->copy()->utc())
            ->when($this->orderFilter !== 'all', fn (Builder $query) => $query->where('order_id', (int) $this->orderFilter))
            ->when($this->statusFilter !== 'all', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.mb_substr(trim($this->search), 0, 180).'%';
                $query->where(fn (Builder $search) => $search
                    ->where('title', 'like', $term)
                    ->orWhere('role_name', 'like', $term)
                    ->orWhere('location_name', 'like', $term)
                    ->orWhereHas('order', fn (Builder $order) => $order
                        ->where('title', 'like', $term)
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('company_name', 'like', $term))));
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $dailyGroups = collect();
        if ($this->viewMode === 'day') {
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                $dayEnd = $day->copy()->addDay();
                $items = $shifts->filter(fn (Shift $shift): bool => $shift->starts_at->lt($dayEnd) && $shift->ends_at->gt($day))->values();
                if ($items->isNotEmpty()) {
                    $dailyGroups->put($day->toDateString(), [
                        'label' => $day->copy()->locale('de')->translatedFormat('l, d. F Y'),
                        'items' => $items,
                    ]);
                }
            }
        }

        $staffingGroups = $shifts->groupBy(function (Shift $shift): string {
            if (in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true)) {
                return 'closed';
            }

            $reserved = $shift->assignments->filter(fn (ShiftAssignment $assignment): bool => $assignment->status->blocksAvailability())->count();
            $confirmed = $shift->assignments->where('status', ShiftAssignmentStatus::Confirmed)->count();

            return $reserved < $shift->required_staff ? 'open' : ($confirmed < $shift->required_staff ? 'awaiting' : 'staffed');
        });

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
            'nativeOperations' => OperationsAccess::ready(),
            'qualificationTypes' => OperationsAccess::ready() ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect(),
            'shifts' => $shifts,
            'dailyGroups' => $dailyGroups,
            'staffingGroups' => collect([
                'open' => 'Besetzung offen',
                'awaiting' => 'Bestätigung ausstehend',
                'staffed' => 'Besetzt',
                'closed' => 'Abgeschlossen / storniert',
            ])->map(fn (string $label, string $key): array => ['label' => $label, 'items' => $staffingGroups->get($key, collect())]),
            'displayTimezone' => (string) config('operations.display_timezone', 'Europe/Berlin'),
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
            'openCount' => $activeShifts->sum(fn (Shift $shift) => max(0, $shift->required_staff - $shift->assignments->filter(fn ($a) => $a->status->blocksAvailability())->count())),
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvedRange(): array
    {
        $timezone = (string) config('operations.display_timezone', 'Europe/Berlin');
        try {
            $from = Carbon::createFromFormat('!Y-m-d', $this->rangeFrom, $timezone)->startOfDay();
        } catch (\Throwable) {
            $from = now($timezone)->startOfWeek()->startOfDay();
            $this->rangeFrom = $from->toDateString();
        }

        try {
            $to = Carbon::createFromFormat('!Y-m-d', $this->rangeTo, $timezone)->endOfDay();
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
        $this->editingRevision = null;
        $this->plannedBreakMinutes = 0;
        $this->qualificationIds = [];
        $this->requiredStaff = 1;
        $this->status = $this->enumDefault(ShiftStatus::class, 'draft');
        $this->resetValidation();
    }

    public function publish(int $id, int $revision, PlanPublicationService $service): void
    {
        $this->ensureAdmin();
        OperationsAccess::requireReady();
        $service->publish(Shift::findOrFail($id), $revision, auth()->user());
        $this->dispatch('swal:toast', type: 'success', text: 'Dienst veröffentlicht.');
    }
}
