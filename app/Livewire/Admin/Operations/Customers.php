<?php

namespace App\Livewire\Admin\Operations;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class Customers extends Component
{
    use SupportsOperationsUi;

    public string $search = '';
    public string $activeFilter = 'active';
    public ?int $selectedCustomerId = null;
    public bool $formOpen = false;
    public ?int $editingCustomerId = null;
    public string $companyName = '';
    public string $contactName = '';
    public string $email = '';
    public string $phone = '';
    public string $address = '';
    public string $postalCode = '';
    public string $city = '';
    public string $country = 'DE';
    public string $notes = '';
    public bool $isActive = true;

    public function mount(): void
    {
        $this->ensureAdmin();
        $this->selectedCustomerId = Customer::query()->orderByDesc('is_active')->orderBy('company_name')->value('id');
    }

    public function createCustomer(): void
    {
        $this->ensureAdmin();
        $this->resetCustomerForm();
        $this->formOpen = true;
    }

    public function editCustomer(int $customerId): void
    {
        $this->ensureAdmin();
        $customer = Customer::query()->findOrFail($customerId);

        $this->editingCustomerId = $customer->id;
        $this->companyName = (string) $customer->company_name;
        $this->contactName = (string) $customer->contact_name;
        $this->email = (string) $customer->email;
        $this->phone = (string) $customer->phone;
        $this->address = (string) $customer->street;
        $this->postalCode = (string) $customer->postal_code;
        $this->city = (string) $customer->city;
        $this->country = (string) ($customer->country ?: 'DE');
        $this->notes = (string) $customer->notes;
        $this->isActive = (bool) $customer->is_active;
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function selectCustomer(int $customerId): void
    {
        $this->ensureAdmin();
        Customer::query()->findOrFail($customerId);
        $this->selectedCustomerId = $customerId;
    }

    public function saveCustomer(): void
    {
        $this->ensureAdmin();
        $validated = $this->validate([
            'companyName' => ['required', 'string', 'max:160'],
            'contactName' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:1000'],
            'postalCode' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'isActive' => ['boolean'],
        ]);

        $customer = $this->editingCustomerId
            ? Customer::query()->findOrFail($this->editingCustomerId)
            : new Customer;

        $customer->fill([
            'company_name' => trim($validated['companyName']),
            'contact_name' => trim($validated['contactName']) ?: null,
            'email' => trim($validated['email']) ?: null,
            'phone' => trim($validated['phone']) ?: null,
            'street' => trim($validated['address']) ?: null,
            'postal_code' => trim($validated['postalCode']) ?: null,
            'city' => trim($validated['city']) ?: null,
            'country' => trim($validated['country']) ?: null,
            'notes' => trim($validated['notes']) ?: null,
            'is_active' => $validated['isActive'],
        ])->save();

        $this->selectedCustomerId = $customer->id;
        $this->formOpen = false;
        $this->resetCustomerForm();
        $this->dispatch('swal:toast', type: 'success', text: 'Kunde gespeichert.');
    }

    public function toggleCustomerActive(int $customerId): void
    {
        $this->ensureAdmin();
        $customer = Customer::query()->findOrFail($customerId);
        $customer->update(['is_active' => ! $customer->is_active]);
        $this->selectedCustomerId = $customer->id;
        $this->dispatch('swal:toast', type: 'success', text: $customer->is_active ? 'Kunde aktiviert.' : 'Kunde deaktiviert.');
    }

    public function render()
    {
        $this->ensureAdmin();

        $customers = Customer::query()
            ->withCount('orders')
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $term = '%' . trim($this->search) . '%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('company_name', 'like', $term)
                        ->orWhere('customer_number', 'like', $term)
                        ->orWhere('contact_name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($this->activeFilter === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->activeFilter === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('company_name')
            ->get();

        $selectedCustomer = $this->selectedCustomerId
            ? Customer::query()->with(['orders' => fn ($query) => $query->latest('starts_at')->limit(8)])->find($this->selectedCustomerId)
            : null;

        return view('livewire.admin.operations.customers', [
            'customers' => $customers,
            'selectedCustomer' => $selectedCustomer,
            'activeCount' => Customer::query()->where('is_active', true)->count(),
            'totalCount' => Customer::query()->count(),
        ]);
    }

    private function resetCustomerForm(): void
    {
        $this->reset([
            'editingCustomerId', 'companyName', 'contactName', 'email', 'phone', 'address', 'postalCode', 'city', 'notes',
        ]);
        $this->country = 'DE';
        $this->isActive = true;
        $this->resetValidation();
    }
}
