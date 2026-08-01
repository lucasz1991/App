<?php

namespace App\Livewire\Admin\Operations;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Operations\OrderLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Orders extends Component
{
    use SupportsOperationsUi;

    public string $search = '';
    public string $statusFilter = 'all';
    public ?int $selectedOrderId = null;
    public bool $formOpen = false;
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

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->status = $this->enumDefault(OrderStatus::class, 'requested');
        $this->priority = $this->enumDefault(OrderPriority::class, 'normal');
        $this->selectedOrderId = Order::query()->latest('starts_at')->value('id');
    }

    public function createOrder(): void
    {
        $this->ensureAdmin();
        $this->resetOrderForm();
        $this->startsAt = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
        $this->endsAt = now()->addDay()->setTime(16, 0)->format('Y-m-d\TH:i');
        $this->formOpen = true;
    }

    public function editOrder(int $orderId): void
    {
        $this->ensureAdmin();
        $order = Order::query()->findOrFail($orderId);

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
        $this->ensureAdmin();
        Order::query()->findOrFail($orderId);
        $this->selectedOrderId = $orderId;
        $this->resetValidation('statusChange');
    }

    public function saveOrder(): void
    {
        $this->ensureAdmin();
        $validated = $this->validate([
            'customerId' => ['required', 'integer', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:180'],
            'serviceType' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'priority' => ['required', Rule::enum(OrderPriority::class)],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date', 'after:startsAt'],
            'timezone' => ['required', 'timezone'],
            'locationName' => ['nullable', 'string', 'max:180'],
            'address' => ['nullable', 'string', 'max:1000'],
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
            'starts_at' => Carbon::parse($validated['startsAt'], $validated['timezone']),
            'ends_at' => Carbon::parse($validated['endsAt'], $validated['timezone']),
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

        $order->fill($payload)->save();

        $this->selectedOrderId = $order->id;
        $this->formOpen = false;
        $this->resetOrderForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Auftrag gespeichert.');
    }

    public function changeStatus(string $status, OrderLifecycleService $lifecycle): void
    {
        $this->ensureAdmin();
        abort_unless($this->selectedOrderId, 404);

        $order = Order::query()->findOrFail($this->selectedOrderId);

        try {
            $lifecycle->transition($order, $status, auth()->user());
            $this->resetValidation('statusChange');
            $this->dispatch('swal:toast', type: 'success', text: 'Auftragsstatus aktualisiert.');
        } catch (ValidationException $exception) {
            $this->addError('statusChange', collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
        } catch (\DomainException $exception) {
            $this->addError('statusChange', $exception->getMessage());
        }
    }

    public function render()
    {
        $this->ensureAdmin();

        $orders = Order::query()
            ->with('customer')
            ->withCount('shifts')
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%' . trim($this->search) . '%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('title', 'like', $term)
                        ->orWhere('order_number', 'like', $term)
                        ->orWhere('service_type', 'like', $term)
                        ->orWhere('location_name', 'like', $term)
                        ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('company_name', 'like', $term));
                });
            })
            ->when($this->statusFilter !== 'all', fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->orderBy('starts_at')
            ->get();

        $selectedOrder = $this->selectedOrderId
            ? Order::query()->with(['customer', 'shifts.assignments', 'statusHistory.changedBy'])->find($this->selectedOrderId)
            : null;

        return view('livewire.admin.operations.orders', [
            'orders' => $orders,
            'selectedOrder' => $selectedOrder,
            'customers' => Customer::query()->where('is_active', true)->orderBy('company_name')->get(),
            'statusOptions' => $this->enumOptions(OrderStatus::class),
            'priorityOptions' => $this->enumOptions(OrderPriority::class),
            'openCount' => Order::query()->whereNotIn('status', ['completed', 'invoiced', 'cancelled'])->count(),
            'startsSoonCount' => Order::query()->whereBetween('starts_at', [now(), now()->addDays(7)])->count(),
        ]);
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
