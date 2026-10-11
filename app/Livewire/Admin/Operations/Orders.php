<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Operations\OrderLifecycleService;
use App\Services\Operations\OrderSchedulingService;
use App\Support\Operations\OperationsEnhancementsSchema;
use App\Support\Operations\OperationsPages;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Orders extends Component
{
    use SupportsOperationsUi;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public int $perPage = 50;

    public string $rangeFrom = '';

    public string $rangeTo = '';

    #[Locked]
    public string $sortBy = 'period';

    #[Locked]
    public string $sortDir = 'asc';

    private const SORTABLE_COLUMNS = ['service', 'title', 'customer', 'period', 'staff', 'status'];

    public function updatedPerPage(): void
    {
        $this->ensureAdmin();
        $this->validateOnly('perPage', ['perPage' => ['integer', Rule::in([25, 50, 100])]]);
        $this->resetPage('ordersPage');
    }

    public function tableSort(string $key, ?string $dir = null): void
    {
        $this->ensureAdmin();
        abort_unless(in_array($key, self::SORTABLE_COLUMNS, true), 422);
        abort_unless($dir === null || in_array($dir, ['asc', 'desc'], true), 422);
        $direction = $dir ?? ($this->sortBy === $key && $this->sortDir === 'asc' ? 'desc' : 'asc');
        $this->sortBy = $key;
        $this->sortDir = $direction;
        $this->resetPage('ordersPage');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('ordersPage');
        $this->syncUrl();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage('ordersPage');
        $this->syncUrl();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage('ordersPage');
        $this->syncUrl();
    }

    public ?int $selectedOrderId = null;

    public bool $formOpen = false;

    public bool $detailOpen = false;

    #[Locked]
    public ?int $customerFilterId = null;

    #[Locked]
    public bool $consolidated = false;

    #[Locked]
    public bool $profileEmbedded = false;

    #[Locked]
    public bool $detailOnly = false;

    #[Locked]
    public bool $embeddedDetail = false;

    #[Locked]
    public string $detailSection = 'overview';

    #[Locked]
    public ?int $fulfilmentRecordId = null;

    private bool $mounting = false;

    public static function listState(array $query): array
    {
        $state = [];
        foreach (['search' => '', 'status' => 'all'] as $name => $default) {
            $value = array_key_exists($name, $query) ? $query[$name] : $default;
            abort_unless(is_string($value) && mb_strlen($value) <= 100, 422);
            $state[$name] = $value;
        }
        abort_unless(in_array($state['status'], ['all', ...OrderStatus::values()], true), 422);
        if (array_key_exists('filter', $query)) {
            abort_unless(is_string($query['filter']) && mb_strlen($query['filter']) <= 100 && $query['filter'] === 'all', 422);
        }
        if (array_key_exists('from', $query) || array_key_exists('until', $query)) {
            abort_unless(is_string($query['from'] ?? null) && is_string($query['until'] ?? null), 422);
            try {
                [$state['from'], $state['until']] = self::validatedPeriod($query['from'], $query['until']);
            } catch (ValidationException) {
                abort(422, 'Ungültiger Auftragszeitraum.');
            }
        }

        return $state;
    }

    private static function displayTimezone(): string
    {
        $timezone = (string) config('operations.display_timezone', 'Europe/Berlin');
        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'Europe/Berlin';
        }
    }

    /** @return array{string, string} */
    private static function validatedPeriod(string $from, string $until): array
    {
        $validated = validator(['rangeFrom' => $from, 'rangeTo' => $until], [
            'rangeFrom' => ['required', 'string', 'regex:/\A(?!0000)\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d'],
            'rangeTo' => ['required', 'string', 'regex:/\A(?!0000)\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d', 'after_or_equal:rangeFrom'],
        ])->stopOnFirstFailure()->validate();
        // Count nominal calendar dates in UTC, independent of 23/25-hour DST days.
        $firstDay = CarbonImmutable::createFromFormat('!Y-m-d', $validated['rangeFrom'], 'UTC');
        $lastDay = CarbonImmutable::createFromFormat('!Y-m-d', $validated['rangeTo'], 'UTC');
        if ($firstDay->diffInDays($lastDay) > 93) {
            throw ValidationException::withMessages(['rangeTo' => 'Bitte höchstens 94 Tage auswählen.']);
        }

        return [$validated['rangeFrom'], $validated['rangeTo']];
    }

    private function usesPeriodFilter(): bool
    {
        return $this->consolidated && ! $this->profileEmbedded && ! $this->detailOnly;
    }

    public function updatingRangeFrom(string $value): void
    {
        $this->ensureAdmin();
        abort_unless($this->usesPeriodFilter(), 403);
        try {
            self::validatedPeriod($value, $this->rangeTo);
        } catch (ValidationException) {
            abort(422, 'Ungültiger Auftragszeitraum.');
        }
    }

    public function updatingRangeTo(string $value): void
    {
        $this->ensureAdmin();
        abort_unless($this->usesPeriodFilter(), 403);
        try {
            self::validatedPeriod($this->rangeFrom, $value);
        } catch (ValidationException) {
            abort(422, 'Ungültiger Auftragszeitraum.');
        }
    }

    public function applyPeriod(string $from, string $until): void
    {
        $this->ensureAdmin();
        abort_unless($this->usesPeriodFilter(), 403);
        [$this->rangeFrom, $this->rangeTo] = self::validatedPeriod($from, $until);
        $this->resetPage('ordersPage');
        $this->resetValidation(['rangeFrom', 'rangeTo']);
        $this->dispatch('operations-period-changed', from: $this->rangeFrom, until: $this->rangeTo);
        $this->syncUrl();
    }

    private function scopedQuery(): Builder
    {
        return Order::query()->when($this->customerFilterId, fn (Builder $query) => $query->where('customer_id', $this->customerFilterId));
    }

    public function detailSections(): array
    {
        return array_filter([
            'overview' => 'Übersicht',
            'shifts' => $this->detailOnly ? 'Schichten' : null,
            'staffing' => $this->detailOnly ? 'Besetzung' : null,
            'planning' => 'Bedarf & Planung',
            'proofs' => OperationsEnhancementsSchema::ready() ? 'Leistungsnachweise' : null,
            'costs' => OperationsEnhancementsSchema::ready() && auth()->user()->can('operations.costs.manage') ? 'Kosten' : null,
            'history' => 'Verlauf',
        ]);
    }

    public function setDetailSection(string $section): void
    {
        $this->ensureAdmin();
        abort_unless(array_key_exists($section, $this->detailSections()), 403);
        if ($this->selectedOrderId) {
            $this->scopedQuery()->findOrFail($this->selectedOrderId);
        }
        $this->detailSection = $section;
        if ($section !== 'proofs') {
            $this->fulfilmentRecordId = null;
        }
        $this->syncUrl();
    }

    public function openDetails(int $id): void
    {
        $this->selectRecord($id);
        $this->formOpen = false;
        $this->detailOpen = true;
        $this->syncUrl();
    }

    public function closeDetails(): void
    {
        $this->ensureAdmin();
        $this->detailOpen = false;
        if ($this->consolidated) {
            $this->selectedOrderId = null;
            $this->fulfilmentRecordId = null;
            $this->detailSection = 'overview';
            $this->syncUrl();
        }
    }

    public function updatedDetailOpen(bool $open): void
    {
        if ($this->consolidated && ! $open) {
            $this->closeDetails();
        }
    }

    public ?int $editingOrderId = null;

    public ?int $customerId = null;

    public string $title = '';

    public string $serviceType = '';

    public string $description = '';

    public string $status = '';

    public string $priority = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $timezone = 'Europe/Berlin';

    public string $locationName = '';

    public string $address = '';

    public string $postalCode = '';

    public string $city = '';

    public string $country = 'DE';

    public int $requiredStaff = 1;

    public string $requirementsText = '';

    public string $notes = '';

    public function mount(?int $initialOrderId = null, ?int $customerId = null, string $initialSection = 'overview', bool $consolidated = false, ?int $initialRecordId = null, bool $profileEmbedded = false, bool $detailOnly = false, bool $embeddedDetail = false): void
    {
        $this->ensureAdmin();
        $this->mounting = true;
        $this->detailOnly = $detailOnly;
        abort_if($embeddedDetail && ! $detailOnly, 404);
        $this->embeddedDetail = $embeddedDetail;
        abort_if($detailOnly && $initialOrderId === null, 404);
        abort_if($profileEmbedded && (! $consolidated || $customerId === null), 404);
        $this->profileEmbedded = $profileEmbedded;
        $this->status = $this->enumDefault(OrderStatus::class, 'requested');
        $this->priority = $this->enumDefault(OrderPriority::class, 'normal');
        $this->consolidated = $consolidated;
        if ($consolidated && ! $profileEmbedded && ! $detailOnly) {
            $state = self::listState(request()->query());
            $this->search = $state['search'];
            $this->statusFilter = $state['status'];
            $today = CarbonImmutable::now(self::displayTimezone())->startOfDay();
            $this->rangeFrom = $state['from'] ?? $today->subDays(30)->toDateString();
            $this->rangeTo = $state['until'] ?? $today->addDays(30)->toDateString();
            $this->dispatch('operations-period-changed', from: $this->rangeFrom, until: $this->rangeTo);
        }
        $this->fulfilmentRecordId = $initialRecordId;
        abort_if($initialRecordId !== null && ($initialRecordId < 1 || $initialSection !== 'proofs' || $initialOrderId === null), 404);
        $this->customerFilterId = $customerId;
        if ($customerId !== null) {
            abort_unless($customerId > 0, 404);
            Customer::findOrFail($customerId);
        }
        $this->setDetailSection($initialSection);
        $this->selectedOrderId = $initialOrderId ?? ($consolidated ? null : $this->scopedQuery()->latest('starts_at')->value('id'));
        if (! $profileEmbedded && $initialOrderId === null && request()->has('order')) {
            $raw = request()->query('order');
            abort_unless(is_string($raw) && ctype_digit($raw) && (int) $raw > 0, 404);
            $initialOrderId = (int) $raw;
        }
        if ($initialOrderId !== null) {
            $this->openDetails($initialOrderId);
        }
        $this->mounting = false;
    }

    #[On('operations-create')]
    public function createOrder(): void
    {
        $this->ensureAdmin();
        // The host owns its create action; this embedded instance supplies details only.
        if ($this->detailOnly) {
            return;
        }
        $this->detailOpen = false;
        if ($this->consolidated) {
            $this->selectedOrderId = null;
            $this->fulfilmentRecordId = null;
            $this->detailSection = 'overview';
        }
        $this->resetOrderForm();
        $this->customerId = $this->customerFilterId;
        $this->startsAt = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->endsAt = now()->addDay()->setTime(16, 0)->format('Y-m-d\TH:i');
        $this->formOpen = true;
        $this->syncUrl();
    }

    #[On('operations-plan-changed')]
    public function refreshPlan(): void
    {
        $this->ensureAdmin();
    }

    public function editOrder(int $orderId): void
    {
        $this->ensureAdmin();
        $this->detailOpen = false;
        $order = $this->scopedQuery()->findOrFail($orderId);

        $this->editingOrderId = $order->id;
        $this->customerId = $order->customer_id;
        $this->title = (string) $order->title;
        $this->serviceType = (string) $order->service_type;
        $this->description = (string) $order->description;
        $this->status = (string) ($order->status instanceof \BackedEnum ? $order->status->value : $order->status);
        $this->priority = (string) ($order->priority instanceof \BackedEnum ? $order->priority->value : $order->priority);
        $this->startsAt = $order->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->endsAt = $order->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->timezone = (string) ($order->timezone ?: 'Europe/Berlin');
        $this->locationName = (string) $order->location_name;
        $this->address = (string) $order->street;
        $this->postalCode = (string) $order->postal_code;
        $this->city = (string) $order->city;
        $this->country = (string) ($order->country ?: 'DE');
        $this->requiredStaff = (int) $order->required_staff;
        $this->requirementsText = is_array($order->requirements)
            ? implode(PHP_EOL, $order->requirements)
            : (string) $order->requirements;
        $this->notes = (string) $order->notes;
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function selectOrder(int $orderId): void
    {
        $this->selectRecord($orderId);
        $this->syncUrl();
    }

    private function selectRecord(int $orderId): void
    {
        $this->ensureAdmin();
        $this->scopedQuery()->findOrFail($orderId);
        if ($this->selectedOrderId !== null && $this->selectedOrderId !== $orderId) {
            $this->fulfilmentRecordId = null;
        }
        $this->selectedOrderId = $orderId;
        $this->resetValidation('statusChange');
    }

    private function syncUrl(): void
    {
        if (! $this->consolidated || $this->profileEmbedded || $this->detailOnly || $this->mounting) {
            return;
        }
        $this->ensureAdmin();
        $state = self::listState([
            'search' => $this->search, 'status' => $this->statusFilter,
            'from' => $this->rangeFrom, 'until' => $this->rangeTo,
        ]);
        if ($this->selectedOrderId) {
            $this->scopedQuery()->findOrFail($this->selectedOrderId);
        }
        $proofId = $this->detailOpen && $this->detailSection === 'proofs' ? $this->fulfilmentRecordId : null;
        $this->dispatch('rt-workspace-url', url: OperationsPages::url('cases', [
            'view' => 'orders', 'section' => $this->selectedOrderId ? $this->detailSection : 'overview',
            'customer' => $this->customerFilterId, 'search' => $state['search'],
            'status' => $state['status'] === 'all' ? null : $state['status'], 'order' => $this->selectedOrderId,
            'record' => $proofId, 'record_type' => $proofId ? 'proof' : null,
            'from' => $state['from'], 'until' => $state['until'],
        ]));
    }

    public function saveOrder(OrderSchedulingService $schedulingService): void
    {
        $this->ensureAdmin();
        if ($this->editingOrderId) {
            $this->scopedQuery()->findOrFail($this->editingOrderId);
        }
        abort_if($this->customerFilterId && $this->customerId !== $this->customerFilterId, 422);
        $currentCustomerId = $this->editingOrderId
            ? Order::query()->whereKey($this->editingOrderId)->value('customer_id')
            : null;

        $validated = $this->validate([
            'customerId' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where(function ($query) use ($currentCustomerId): void {
                    $query->whereNull('deleted_at')
                        ->where(function ($query) use ($currentCustomerId): void {
                            $query->where('is_active', true);

                            if ($currentCustomerId !== null) {
                                $query->orWhere('id', $currentCustomerId);
                            }
                        });
                }),
            ],
            'title' => ['required', 'string', 'max:180'],
            'serviceType' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'priority' => ['required', Rule::enum(OrderPriority::class)],
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s,Y-m-d H:i,Y-m-d H:i:s'],
            'endsAt' => ['required', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s,Y-m-d H:i,Y-m-d H:i:s', 'after:startsAt'],
            'timezone' => ['required', 'timezone'],
            'locationName' => ['nullable', 'string', 'max:180'],
            'address' => ['nullable', 'string', 'max:255'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'requiredStaff' => ['required', 'integer', 'min:1', 'max:999'],
            'requirementsText' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $order = $this->editingOrderId
            ? Order::query()->findOrFail($this->editingOrderId)
            : new Order;

        $payload = [
            'customer_id' => $validated['customerId'],
            'title' => trim($validated['title']),
            'service_type' => trim($validated['serviceType']) ?: null,
            'description' => trim($validated['description']) ?: null,
            'priority' => $validated['priority'],
            'starts_at' => Carbon::parse($validated['startsAt'], $validated['timezone'])->utc(),
            'ends_at' => Carbon::parse($validated['endsAt'], $validated['timezone'])->utc(),
            'timezone' => $validated['timezone'],
            'location_name' => trim($validated['locationName']) ?: null,
            'street' => trim($validated['address']) ?: null,
            'postal_code' => trim($validated['postalCode']) ?: null,
            'city' => trim($validated['city']) ?: null,
            'country' => trim($validated['country']) ?: null,
            'required_staff' => $validated['requiredStaff'],
            'requirements' => collect(preg_split('/\R/', $validated['requirementsText']) ?: [])
                ->map(fn (string $requirement): string => trim($requirement))
                ->filter()
                ->values()
                ->all(),
            'notes' => trim($validated['notes']) ?: null,
            'updated_by' => auth()->id(),
        ];

        if (! $order->exists) {
            $payload['status'] = $validated['status'];
            $payload['created_by'] = auth()->id();
        }

        try {
            $order = $schedulingService->save($order, $payload, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->selectedOrderId = $order->id;
        $this->formOpen = false;
        $this->resetOrderForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Auftrag gespeichert.');
        if ($this->detailOnly) {
            $this->dispatch('operations-plan-changed');
        }
    }

    public function changeStatus(string $status, OrderLifecycleService $lifecycle): void
    {
        $this->ensureAdmin();
        abort_unless($this->selectedOrderId, 404);

        $order = $this->scopedQuery()->findOrFail($this->selectedOrderId);

        try {
            $lifecycle->transition($order, $status, auth()->user());
            $this->resetValidation('statusChange');
            $this->dispatch('swal:toast', type: 'success', text: 'Auftragsstatus aktualisiert.');
            if ($this->detailOnly) {
                $this->dispatch('operations-plan-changed');
            }
        } catch (ValidationException $exception) {
            $this->addError('statusChange', collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
        } catch (\DomainException $exception) {
            $this->addError('statusChange', $exception->getMessage());
        }
    }

    public function render(OrderLifecycleService $lifecycle)
    {
        $this->ensureAdmin();
        if ($this->consolidated) {
            self::listState(['search' => $this->search, 'status' => $this->statusFilter]);
        }
        $periodBounds = null;
        if ($this->usesPeriodFilter()) {
            [$from, $until] = self::validatedPeriod($this->rangeFrom, $this->rangeTo);
            $zone = self::displayTimezone();
            $periodBounds = [
                CarbonImmutable::createFromFormat('!Y-m-d', $from, $zone)->startOfDay()->utc(),
                CarbonImmutable::createFromFormat('!Y-m-d', $until, $zone)->endOfDay()->utc(),
            ];
        }

        $ordersQuery = $this->scopedQuery()
            ->when($this->detailOnly, fn (Builder $query) => $query->whereRaw('1 = 0'))
            // Whole-day inclusive overlap applies to the contract, never to shift dates.
            ->when($periodBounds, fn (Builder $query) => $query
                ->where('starts_at', '<=', $periodBounds[1])
                ->where('ends_at', '>=', $periodBounds[0]))
            ->with([
                'customer',
                'shifts.assignments' => fn ($query) => $query->whereIn('status', ShiftAssignmentStatus::blockingValues()),
                'shifts.assignments.user.profile',
                'shifts.assignments.user.currentTeam',
            ])
            ->withCount('shifts')
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('title', 'like', $term)
                        ->orWhere('order_number', 'like', $term)
                        ->orWhere('service_type', 'like', $term)
                        ->orWhere('location_name', 'like', $term)
                        ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('company_name', 'like', $term));
                });
            })
            ->when($this->statusFilter !== 'all', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->orderBy(match ($this->sortBy) {
                'service', 'title' => 'title',
                'customer' => Customer::query()->select('company_name')->whereColumn('customers.id', 'orders.customer_id')->limit(1),
                'status' => 'status',
                'staff' => 'required_staff',
                default => 'starts_at',
            }, $this->sortDir === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');

        // Bound and clamp before loading relations so even stale page URLs load one page only.
        $perPage = in_array($this->perPage, [25, 50, 100], true) ? $this->perPage : 50;
        $totalOrders = (clone $ordersQuery)->toBase()->getCountForPagination();
        $ordersPage = min(max(1, (int) $this->getPage('ordersPage')), max(1, (int) ceil($totalOrders / $perPage)));
        if ((int) $this->getPage('ordersPage') !== $ordersPage) {
            $this->setPage($ordersPage, 'ordersPage');
        }
        $orders = $ordersQuery->paginate($perPage, ['*'], 'ordersPage', $ordersPage, $totalOrders);
        $orderTableItems = (clone $orders)->setCollection($orders->getCollection()->map(function (Order $order): object {
            $item = $this->serviceDetailProjection($order);
            // Orders show the contractual period, not the first/last constituent shift.
            $item->from = $item->orderFrom;
            $item->until = $item->orderUntil;
            $item->requiredStaff = (int) $order->required_staff;

            return $item;
        }));

        $selectedOrder = $this->selectedOrderId
            ? $this->scopedQuery()->with(['customer', 'shifts.assignments.user.profile', 'shifts.assignments.user.currentTeam', 'statusHistory.changedBy.profile', 'statusHistory.changedBy.currentTeam'])->findOrFail($this->selectedOrderId)
            : null;

        $serviceDetailItem = $this->serviceDetailProjection($selectedOrder);

        $transitionOptions = $selectedOrder
            ? collect($lifecycle->allowedTransitions($selectedOrder->status))
                ->map(fn (OrderStatus $status): array => ['value' => $status->value, 'label' => $status->label()])
                ->values()
                ->all()
            : [];

        return view('livewire.admin.operations.orders', [
            'orders' => $orders,
            'orderTableItems' => $orderTableItems,
            'displayTimezone' => self::displayTimezone(),
            'selectedOrder' => $selectedOrder,
            'serviceDetailItem' => $serviceDetailItem,
            'serviceDetailSummary' => $serviceDetailItem?->summary ?? ['shifts' => 0, 'required' => 0, 'reserved' => 0, 'confirmed' => 0, 'open' => 0],
            'originInquiry' => $selectedOrder && auth()->user()->can('operations.inquiries.manage') && Schema::hasTable('operation_inquiries') ? OperationInquiry::where('order_id', $selectedOrder->id)->first() : null,
            'customers' => Customer::query()
                ->when($this->profileEmbedded, fn (Builder $query) => $query->whereKey($this->customerFilterId))
                ->where(function (Builder $query): void {
                    $query->where('is_active', true);

                    if ($this->customerId !== null) {
                        $query->orWhere('id', $this->customerId);
                    }
                })
                ->orderBy('company_name')
                ->get(),
            'statusOptions' => $this->enumOptions(OrderStatus::class),
            'priorityOptions' => $this->enumOptions(OrderPriority::class),
            'transitionOptions' => $transitionOptions,
            'openCount' => $this->detailOnly ? 0 : $this->scopedQuery()->whereNotIn('status', ['completed', 'invoiced', 'cancelled'])->count(),
            'startsSoonCount' => $this->detailOnly ? 0 : $this->scopedQuery()
                ->whereNotIn('status', ['completed', 'invoiced', 'cancelled'])
                ->whereBetween('starts_at', [now()->utc(), now()->addDays(7)->utc()])
                ->count(),
            'inProgressCount' => $this->detailOnly ? 0 : $this->scopedQuery()->where('status', OrderStatus::InProgress)->count(),
            'withoutShiftsCount' => $this->detailOnly ? 0 : $this->scopedQuery()
                ->whereNotIn('status', ['completed', 'invoiced', 'cancelled'])
                ->whereDoesntHave('shifts')
                ->count(),
        ]);
    }

    /** Build the complete selected service from loaded data, independent of list filters/pages. */
    private function serviceDetailProjection(?Order $order): ?object
    {
        if (! $order) {
            return null;
        }

        $timezone = (string) config('operations.display_timezone', 'Europe/Berlin');
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = 'Europe/Berlin';
        }

        // Never parse missing/invalid schedule data into "now" for a display preview.
        $date = static function (Order|Shift $record, string $attribute) use ($timezone): ?CarbonImmutable {
            try {
                $value = $record->{$attribute};

                return $value ? CarbonImmutable::instance($value)->setTimezone($timezone) : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $shifts = $order->shifts->sort(function (Shift $left, Shift $right) use ($date): int {
            $leftDate = $date($left, 'starts_at');
            $rightDate = $date($right, 'starts_at');

            return (($leftDate?->getTimestamp() ?? PHP_INT_MAX) <=> ($rightDate?->getTimestamp() ?? PHP_INT_MAX))
                ?: ($left->id <=> $right->id);
        })->values();
        $summary = ['shifts' => 0, 'required' => 0, 'reserved' => 0, 'confirmed' => 0, 'open' => 0];
        $seats = collect();
        $shiftDetails = collect();
        foreach ($shifts as $shift) {
            $active = ! in_array($shift->status, [ShiftStatus::Completed, ShiftStatus::Cancelled], true);
            $blocking = $shift->assignments->filter(fn (ShiftAssignment $assignment): bool => $assignment->status->blocksAvailability());
            $reserved = $blocking->count();
            $from = $date($shift, 'starts_at');
            $until = $date($shift, 'ends_at');
            $shiftDetails->push((object) [
                'id' => $shift->id, 'shift' => $shift, 'active' => $active,
                'from' => $from, 'until' => $until, 'day' => $from?->format('Y-m-d') ?? 'unknown',
                'summary' => [
                    'shifts' => 1, 'required' => max(0, (int) $shift->required_staff), 'reserved' => $reserved,
                    'confirmed' => $blocking->where('status', ShiftAssignmentStatus::Confirmed)->count(),
                    // Finished/cancelled shifts are history, never an actionable vacancy.
                    'open' => $active ? max(0, $shift->required_staff - $reserved) : 0,
                ],
                'assignments' => $shift->assignments->map(fn (ShiftAssignment $assignment): array => [
                    'id' => $assignment->id, 'user' => $assignment->user, 'status' => $assignment->status,
                    'blocking' => $assignment->status->blocksAvailability(),
                ])->values(),
                'seats' => $blocking->map(fn (ShiftAssignment $assignment): array => [
                    'id' => $assignment->id, 'user' => $assignment->user, 'shift' => $shift->title,
                    'starts_at' => $from, 'ends_at' => $until,
                ])->values(),
            ]);
            if (! $active) {
                continue;
            }
            $summary['shifts']++;
            $summary['required'] += $shift->required_staff;
            $summary['reserved'] += $reserved;
            $summary['confirmed'] += $shift->assignments->where('status', ShiftAssignmentStatus::Confirmed)->count();
            $summary['open'] += max(0, $shift->required_staff - $reserved);
            foreach ($blocking as $assignment) {
                $seats->push([
                    'id' => $assignment->id, 'user' => $assignment->user, 'shift' => $shift->title,
                    'starts_at' => $date($shift, 'starts_at'), 'ends_at' => $date($shift, 'ends_at'),
                ]);
            }
        }

        return (object) [
            'id' => $order->id, 'order' => $order, 'count' => $shifts->count(), 'shifts' => $shifts,
            'shiftDetails' => $shiftDetails,
            'timezone' => $timezone, 'summary' => $summary, 'staffingSeats' => $seats,
            'orderFrom' => $date($order, 'starts_at'), 'orderUntil' => $date($order, 'ends_at'),
            'staffingUsers' => $seats->pluck('user')->filter()->unique('id')->values(),
            'from' => $shifts->isEmpty() ? $date($order, 'starts_at') : $shifts->map(fn (Shift $shift) => $date($shift, 'starts_at'))->filter()->min(),
            'until' => $shifts->isEmpty() ? $date($order, 'ends_at') : $shifts->map(fn (Shift $shift) => $date($shift, 'ends_at'))->filter()->max(),
        ];
    }

    private function resetOrderForm(): void
    {
        $this->reset([
            'editingOrderId', 'customerId', 'title', 'serviceType', 'description', 'startsAt', 'endsAt',
            'locationName', 'address', 'postalCode', 'city', 'requirementsText', 'notes',
        ]);
        $this->status = $this->enumDefault(OrderStatus::class, 'requested');
        $this->priority = $this->enumDefault(OrderPriority::class, 'normal');
        $this->timezone = 'Europe/Berlin';
        $this->country = 'DE';
        $this->requiredStaff = 1;
        $this->resetValidation();
    }
}
