<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationInquiry;
use App\Services\Operations\InquiryWorkflowService;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class InquiryInbox extends Component
{
    use WithPagination;

    public string $search = '';

    public string $filter = 'active';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $revision = null;

    public bool $editing = false;

    public bool $detailOpen = false;

    public array $form = [];

    public string $amount = '';

    public string $terms = '';

    public string $acceptance = '';

    public bool $authorized = false;

    public string $duplicateId = '';

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.inquiries.manage');
        OperationsAccess::requireReady();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function close(): void
    {
        $this->access();
        $this->selectedId = null;
        $this->editing = false;
        $this->detailOpen = false;
    }

    public function create(): void
    {
        $this->access();
        $this->reset(['selectedId', 'revision', 'amount', 'terms', 'acceptance', 'authorized', 'duplicateId']);
        $this->form = ['channel' => 'manual', 'source_reference' => '', 'title' => '', 'original' => '', 'customer_id' => '', 'contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'starts_at' => '', 'ends_at' => '', 'timezone' => 'Europe/Berlin', 'location_name' => '', 'role_name' => '', 'required_staff' => 1];
        $this->editing = true;
        $this->detailOpen = true;
        $this->resetValidation();
    }

    public function select(int $id): void
    {
        $this->access();
        $record = OperationInquiry::findOrFail($id);
        $this->selectedId = $id;
        $this->detailOpen = true;
        $this->revision = $record->revision;
        $this->editing = false;
        $this->form = $record->only(['channel', 'source_reference', 'title', 'original', 'customer_id', 'contact_name', 'contact_email', 'contact_phone', 'timezone', 'location_name', 'role_name', 'required_staff']);
        $this->form['starts_at'] = $record->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->form['ends_at'] = $record->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->amount = isset($record->offer['amount_cents']) ? number_format($record->offer['amount_cents'] / 100, 2, '.', '') : '';
        $this->terms = $record->offer['terms'] ?? '';
        $this->reset(['acceptance', 'authorized', 'duplicateId']);
        $this->resetValidation();
    }

    public function save(InquiryWorkflowService $service): void
    {
        $this->access();
        $record = $service->save($this->selectedId ? OperationInquiry::findOrFail($this->selectedId) : null, $this->form, auth()->user(), $this->revision);
        $this->select($record->id);
    }

    public function transition(string $action, InquiryWorkflowService $service): void
    {
        $this->access();
        abort_unless($this->selectedId, 404);
        $record = $service->transition(OperationInquiry::findOrFail($this->selectedId), $this->revision, $action, ['amount' => $this->amount, 'terms' => $this->terms, 'note' => $this->acceptance, 'authorized' => $this->authorized, 'original_id' => $this->duplicateId], auth()->user());
        $this->select($record->id);
    }

    public function render()
    {
        $this->access();
        $query = OperationInquiry::with('customer')->when($this->filter === 'active', fn ($q) => $q->whereNull('order_id')->whereNull('duplicate_of_id'))
            ->when($this->filter !== 'active' && $this->filter !== 'all', fn ($q) => $q->where('channel', $this->filter))
            ->when(filled($this->search), fn ($q) => $q->where(fn ($q) => $q->where('title', 'like', '%'.mb_substr($this->search, 0, 100).'%')->orWhereHas('customer', fn ($q) => $q->where('company_name', 'like', '%'.mb_substr($this->search, 0, 100).'%'))));

        return view('livewire.operations.inquiry-inbox', [
            'inquiries' => $query->latest('updated_at')->paginate(15),
            'selected' => $this->selectedId ? OperationInquiry::with(['customer', 'order', 'duplicateOf'])->find($this->selectedId) : null,
            'customers' => Customer::where('is_active', true)->orderBy('company_name')->get(['id', 'company_name']),
            'history' => $this->selectedId ? OperationAudit::where('subject_type', 'OperationInquiry')->where('subject_id', $this->selectedId)->with('actor:id,name')->latest('id')->limit(30)->get() : collect(),
        ]);
    }
}
