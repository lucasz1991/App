<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\CustomerCondition;
use App\Models\CustomerContact;
use App\Models\CustomerLocation;
use App\Services\Operations\CustomerWorkflowService;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CustomerRelations extends Component
{
    public string $customerId = '';

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public ?int $contextCustomerId = null;

    #[Locked]
    public string $section = 'all';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public ?int $formCustomerId = null;

    #[Locked]
    public string $kind = 'contact';

    public bool $formOpen = false;

    public array $form = [];

    public function mount(?int $customerId = null, string $section = 'all', bool $embedded = false): void
    {
        abort_unless(in_array($section, ['all', 'contacts', 'conditions'], true), 404);
        $this->customerId = $customerId ? (string) $customerId : '';
        $this->embedded = $embedded;
        $this->contextCustomerId = $embedded ? $customerId : null;
        $this->section = $section;
        $this->access();
    }

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.inquiries.manage');
        if ($this->embedded) {
            abort_unless($this->contextCustomerId && $this->customerId === (string) $this->contextCustomerId, 403);
            Customer::findOrFail($this->contextCustomerId);
        }
    }

    public function updatedCustomerId(): void
    {
        $this->access();
        $this->formOpen = false;
        $this->reset(['selectedId', 'revision', 'form']);
    }

    public function create(string $kind): void
    {
        $this->access();
        Customer::findOrFail($this->customerId);
        $this->formCustomerId = (int) $this->customerId;
        abort_unless(in_array($kind, ['contact', 'condition', 'location'], true), 404);
        $this->kind = $kind;
        $this->selectedId = $this->revision = null;
        $this->form = match ($kind) {
            'contact' => ['name' => '', 'email' => '', 'phone' => '', 'roles' => ['dispatch'], 'is_active' => true],
            'location' => ['name' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country' => 'DE', 'access_note' => '', 'is_active' => true],
            default => ['code' => '', 'label' => '', 'unit' => 'Stunde', 'price' => '', 'valid_from' => '', 'valid_until' => '', 'terms' => ''],
        };
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function editContact(int $id): void
    {
        $this->edit('contact', $id);
    }

    public function editLocation(int $id): void
    {
        $this->edit('location', $id);
    }

    private function edit(string $kind, int $id): void
    {
        $this->create($kind);
        $record = ($kind === 'contact' ? CustomerContact::query() : CustomerLocation::query())->where('customer_id', $this->customerId)->findOrFail($id);
        $this->selectedId = $record->id;
        $this->revision = $record->revision;
        $this->form = $record->only(array_keys($this->form));
    }

    public function endCondition(int $id): void
    {
        $this->access();
        $record = CustomerCondition::where('customer_id', $this->customerId)->findOrFail($id);
        $this->kind = 'condition_end';
        $this->formCustomerId = (int) $this->customerId;
        $this->selectedId = $id;
        $this->revision = $record->revision;
        $this->form = ['date' => ''];
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function save(CustomerWorkflowService $service): void
    {
        $this->access();
        $customer = Customer::findOrFail($this->customerId);
        abort_unless($customer->id === $this->formCustomerId, 422, 'Kundenstand wurde geändert.');
        match ($this->kind) {
            'contact' => $service->contact($customer, $this->selectedId, $this->revision, $this->form, auth()->user()),
            'location' => $service->location($customer, $this->selectedId, $this->revision, $this->form, auth()->user()),
            'condition' => $service->condition($customer, $this->form, auth()->user()),
            'condition_end' => $this->finishCondition($customer, $service),
            default => abort(404),
        };
        $this->formOpen = false;
    }

    private function finishCondition(Customer $customer, CustomerWorkflowService $service): void
    {
        CustomerCondition::where('customer_id', $customer->id)->findOrFail($this->selectedId);
        $service->endCondition($this->selectedId, $this->revision, $this->form['date'] ?? '', auth()->user());
    }

    public function render()
    {
        $this->access();
        $ready = CustomerWorkflowService::ready();

        return view('livewire.operations.customer-relations', [
            'ready' => $ready, 'customers' => $this->embedded ? collect() : Customer::orderBy('company_name')->get(['id', 'company_name']),
            'contacts' => $ready && $this->section !== 'conditions' ? CustomerContact::where('customer_id', $this->customerId)->orderBy('name')->get() : collect(),
            'locations' => $ready && $this->section !== 'conditions' ? CustomerLocation::where('customer_id', $this->customerId)->orderBy('name')->get() : collect(),
            'conditions' => $ready && $this->section !== 'contacts' ? CustomerCondition::where('customer_id', $this->customerId)->orderByDesc('valid_from')->get() : collect(),
        ]);
    }
}
