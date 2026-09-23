<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PlanChangeService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\StaffEligibilityService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class ShiftManagement extends Component
{
    use SupportsOperationsUi;
    use WithPagination;

    public string $attentionFilter = 'all';

    public string $candidateSearch = '';

    public function updatedCandidateSearch(): void
    {
        $this->resetPage('candidatesPage');
    }

    public function chooseCandidate(int $id): void
    {
        $this->ensureAdmin();
        $shift = Shift::findOrFail($this->selectedShiftId);
        $user = User::where('status', true)->where('role', 'staff')->findOrFail($id);
        app(StaffEligibilityService::class)->assertEligible($shift, $user);
        $this->employeeId = $id;
    }

    public string $rangeFrom = '';

    public string $rangeTo = '';

    public string $orderFilter = 'all';

    public string $search = '';

    public string $statusFilter = 'all';

    #[Locked]
    public string $viewMode = 'day';

    public function setView(string $view): void
    {
        $this->ensureAdmin();
        abort_unless(in_array($view, ['table', 'day', 'staffing', 'orders', 'timeline'], true), 422);
        if ($view === 'timeline') {
            $this->reset(['search', 'statusFilter', 'orderFilter', 'attentionFilter']);
        }
        $this->viewMode = $view;
    }

    public function movePeriod(int $direction): void
    {
        $this->ensureAdmin();
        abort_unless(in_array($direction, [-1, 1], true), 422);
        [$from, $to] = $this->resolvedRange();
        $days = (int) $from->diffInDays($to->copy()->startOfDay()) + 1;
        $this->rangeFrom = $from->addDays($days * $direction)->toDateString();
        $this->rangeTo = $to->addDays($days * $direction)->toDateString();
    }

    public function currentWeek(): void
    {
        $this->ensureAdmin();
        $today = now((string) config('operations.display_timezone', 'Europe/Berlin'));
        $this->rangeFrom = $today->copy()->startOfWeek()->toDateString();
        $this->rangeTo = $today->copy()->endOfWeek()->toDateString();
    }

    #[On('operations-plan-changed')]
    public function refreshPlan(): void
    {
        $this->ensureAdmin();
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
        $this->rangeTo = $today->copy()->endOfWeek()->format('Y-m-d');
        $this->status = $this->enumDefault(ShiftStatus::class, 'draft');
        $this->assignmentStatus = $this->enumDefault(ShiftAssignmentStatus::class, 'confirmed');
        if (request()->has('order')) {
            $orderId = filter_var(request()->query('order'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($orderId, 404);
            $order = Order::query()->find($orderId);
            abort_unless($order, 404);
            $this->orderFilter = (string) $order->id;
            $this->rangeFrom = $order->starts_at->setTimezone($today->timezone)->toDateString();
            $this->rangeTo = $order->ends_at->setTimezone($today->timezone)->toDateString();
            $this->viewMode = 'orders';
        }
        $selection = Shift::query()->when($this->orderFilter !== 'all', fn (Builder $query) => $query->where('order_id', (int) $this->orderFilter));
        $this->selectedShiftId = (clone $selection)->orderBy('starts_at')->value('id');
        if (request()->integer('shift')) {
            $selected = (clone $selection)->find(request()->integer('shift'));
            abort_unless($selected, 404);
            $this->selectedShiftId = $selected->id;
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
        $this->reset(['candidateSearch', 'employeeId']);
        $this->resetPage('candidatesPage');
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
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s,Y-m-d H:i,Y-m-d H:i:s'],
            'endsAt' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s,Y-m-d H:i,Y-m-d H:i:s', 'after:startsAt'],
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
                        ->orWhere('order_number', 'like', $term)
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('company_name', 'like', $term))));
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $native = OperationsAccess::ready();
        foreach ($shifts as $shift) {
            $active = $shift->assignments->filter(fn ($assignment) => $assignment->status->blocksAvailability());
            $issues = $native && ! in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true)
                ? app(StaffEligibilityService::class)->assessMany($shift, $active->pluck('user')->filter()) : [];
            $shift->setAttribute('planning_conflict_count', collect($issues)->filter(fn ($reasons) => $reasons !== [])->count());
            $shift->setAttribute('feedback_pending', $shift->assignments->filter(fn ($a) => $a->status === ShiftAssignmentStatus::Requested && $a->plan_revision > 0 && $a->plan_revision === $shift->published_revision)->count());
            $shift->setAttribute('feedback_declined', $shift->assignments->filter(fn ($a) => $a->status === ShiftAssignmentStatus::Declined && $a->plan_revision > 0 && $a->plan_revision === $shift->published_revision)->count());
        }
        $shifts = $shifts->filter(fn (Shift $shift) => match ($this->attentionFilter) {
            'conflicts' => $shift->planning_conflict_count > 0,
            'unpublished' => $shift->revision !== $shift->published_revision && ! in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true),
            'awaiting' => $shift->feedback_pending > 0 && ! in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true),
            'declined' => $shift->feedback_declined > 0 && ! in_array($shift->status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true),
            default => true,
        })->values();

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

        $summary = $this->staffingSummary($shifts);
        $candidates = $this->detailOpen && $selectedShift ? User::where('status', true)->where('role', 'staff')
            ->when(trim($this->candidateSearch) !== '', fn ($q) => $q->where('name', 'like', '%'.mb_substr(trim($this->candidateSearch), 0, 100).'%'))
            ->orderBy('name')->orderBy('id')->paginate(8, ['id', 'name', 'role', 'status'], 'candidatesPage') : null;
        if ($candidates) {
            $eligibility = app(StaffEligibilityService::class)->assessMany($selectedShift, $candidates->getCollection());
            $candidates->getCollection()->each(function ($user) use ($eligibility) {
                $user->setAttribute('planning_issues', $eligibility[$user->id]);
            });
        }
        $assignmentIssues = $native && $this->detailOpen && $selectedShift
            ? app(StaffEligibilityService::class)->assessMany($selectedShift, $selectedShift->assignments->filter(fn ($a) => $a->status->blocksAvailability())->pluck('user')->filter()) : [];
        $openings = $native && $this->detailOpen && $selectedShift ? app(PlanChangeService::class)->openings($selectedShift->assignments, (int) $selectedShift->published_revision) : collect();
        $feedback = $native && $this->detailOpen && $selectedShift ? $selectedShift->assignments->whereIn('status', [ShiftAssignmentStatus::Requested, ShiftAssignmentStatus::Confirmed, ShiftAssignmentStatus::Declined])->map(function ($assignment) use ($selectedShift, $assignmentIssues, $openings) {
            $assignment->setAttribute('planning_issues', $assignmentIssues[$assignment->user_id] ?? []);
            $opened = $assignment->plan_revision > 0 && $assignment->plan_revision === $selectedShift->published_revision ? $openings->get($assignment->id) : null;
            $assignment->setAttribute('plan_opened_at', $opened ? CarbonImmutable::parse($opened->getRawOriginal('created_at'), 'UTC') : null);

            return $assignment;
        }) : collect();
        $orderGroups = $shifts->groupBy('order_id')->map(function (Collection $items): array {
            $order = $items->first()->order;

            return [
                'label' => $order ? $order->order_number.' · '.$order->title : 'Leistung nicht verfügbar',
                'customer' => $order?->customer?->company_name,
                'items' => $items,
                'summary' => $this->staffingSummary($items),
            ];
        });

        return view('livewire.admin.operations.shift-management', [
            'nativeOperations' => OperationsAccess::ready(),
            'qualificationTypes' => OperationsAccess::ready() ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect(),
            'shifts' => $shifts,
            'dailyGroups' => $dailyGroups,
            'orderGroups' => $orderGroups,
            'staffingGroups' => collect([
                'open' => 'Besetzung offen',
                'awaiting' => 'Bestätigung ausstehend',
                'staffed' => 'Besetzt',
                'closed' => 'Abgeschlossen / storniert',
            ])->map(fn (string $label, string $key): array => ['label' => $label, 'items' => $staffingGroups->get($key, collect())]),
            'displayTimezone' => (string) config('operations.display_timezone', 'Europe/Berlin'),
            'selectedShift' => $selectedShift,
            'candidates' => $candidates,
            'feedback' => $feedback,
            'planChanges' => $native && $this->detailOpen && $selectedShift
                ? ($selectedShift->revision !== $selectedShift->published_revision ? app(PlanChangeService::class)->changes($selectedShift) : app(PlanChangeService::class)->publishedChanges($selectedShift->id, $selectedShift->published_revision)) : [],
            'orders' => Order::query()
                ->with('customer')
                ->where(function (Builder $query): void {
                    $query->where('status', '!=', 'cancelled');

                    if ($this->orderId !== null) {
                        $query->orWhere('id', $this->orderId);
                    }
                    if ($this->orderFilter !== 'all') {
                        $query->orWhere('id', (int) $this->orderFilter);
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
            'shiftCount' => $summary['shifts'],
            'requiredCount' => $summary['required'],
            'reservedCount' => $summary['reserved'],
            'confirmedCount' => $summary['confirmed'],
            'openCount' => $summary['open'],
        ]);
    }

    /** @return array{shifts: int, required: int, reserved: int, confirmed: int, open: int} */
    private function staffingSummary(Collection $shifts): array
    {
        $summary = ['shifts' => 0, 'required' => 0, 'reserved' => 0, 'confirmed' => 0, 'open' => 0];
        foreach ($shifts as $shift) {
            if (in_array($shift->status, [ShiftStatus::Completed, ShiftStatus::Cancelled], true)) {
                continue;
            }
            $reserved = $shift->assignments->filter(fn (ShiftAssignment $assignment): bool => $assignment->status->blocksAvailability())->count();
            $summary['shifts']++;
            $summary['required'] += $shift->required_staff;
            $summary['reserved'] += $reserved;
            $summary['confirmed'] += $shift->assignments->where('status', ShiftAssignmentStatus::Confirmed)->count();
            $summary['open'] += max(0, $shift->required_staff - $reserved);
        }

        return $summary;
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
