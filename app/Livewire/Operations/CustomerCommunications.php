<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerInteraction;
use App\Models\CustomerPortalMessage;
use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\User;
use App\Services\Operations\OperationsAuditService;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsDateTime;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerCommunications extends Component
{
    use WithPagination;

    #[Locked]
    public int $customerId;

    #[Locked]
    public string $view = 'records';

    #[Locked]
    public ?int $selectedId = null;

    public bool $formOpen = false;

    public bool $detailOpen = false;

    public array $form = [];

    public string $search = '';

    public string $channelFilter = 'all';

    public function mount(int $customerId, string $initialView = ''): void
    {
        abort_unless($customerId > 0, 404);
        $this->customerId = $customerId;
        $views = $this->views();
        abort_unless($views, 403);
        Customer::findOrFail($this->customerId);
        $this->view = $initialView ?: array_key_first($views);
        abort_unless(array_key_exists($this->view, $views), 403);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && ($fresh = $actor->fresh()) && $fresh->status, 403);

        return $fresh;
    }

    private function canRecord(User $actor): bool
    {
        return $actor->can('operations.manage') || $actor->can('operations.inquiries.manage');
    }

    private function canPortal(User $actor): bool
    {
        if (! CustomerPortalWorkflowSchema::ready() || ! $actor->can('customers.portal.publish')) {
            return false;
        }
        try {
            app(CustomerPortalScope::class)->authorizeManager($actor, $this->customerId, 'customers.portal.publish');

            return true;
        } catch (AuthorizationException $exception) {
            return false;
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return false;
        }
    }

    public function views(): array
    {
        $actor = $this->actor();

        return array_filter([
            'records' => $this->canRecord($actor) ? 'Kommunikationsprotokoll' : null,
            'ai' => $actor->can('operations.inquiries.manage') && AiIntakeSchema::ready() ? 'AI-Postfach' : null,
            'portal' => $this->canPortal($actor) ? 'Portalnachrichten' : null,
        ]);
    }

    private function access(): User
    {
        $actor = $this->actor();
        abort_unless(array_key_exists($this->view, $this->views()), 403);
        Customer::findOrFail($this->customerId);

        return $actor;
    }

    private function manualAccess(): User
    {
        $actor = $this->actor();
        abort_unless($this->canRecord($actor), 403);
        Customer::findOrFail($this->customerId);
        abort_unless(CustomerInteraction::ready() && Schema::hasTable('operation_audits'), 503, 'Kommunikationsprotokoll noch nicht verfügbar.');

        return $actor;
    }

    public function setView(string $view): void
    {
        abort_unless(array_key_exists($view, $this->views()), 403);
        $this->close();
        $this->view = $view;
        $this->reset(['search', 'channelFilter']);
        $this->resetPage('customerCommunicationPage');
    }

    public function updatedSearch(): void
    {
        $this->access();
        $this->resetPage('customerCommunicationPage');
    }

    public function updatedChannelFilter(): void
    {
        $this->access();
        $this->filters();
        $this->resetPage('customerCommunicationPage');
    }

    private function filters(): void
    {
        Validator::make(['search' => $this->search, 'channel' => $this->channelFilter], ['search' => 'string|max:100', 'channel' => [Rule::in(['all', ...array_keys(CustomerInteraction::CHANNELS)])]])->validate();
        abort_unless($this->view === 'records' || $this->channelFilter === 'all', 422);
    }

    public function resetFilters(): void
    {
        $this->access();
        $this->reset(['search', 'channelFilter']);
        $this->resetPage('customerCommunicationPage');
    }

    public function create(): void
    {
        $this->manualAccess();
        $this->resetValidation();
        $this->selectedId = null;
        $this->detailOpen = false;
        $timezone = config('operations.display_timezone', 'Europe/Berlin');
        $this->form = ['channel' => 'note', 'direction' => 'internal', 'occurred_at' => now($timezone)->format('Y-m-d\TH:i'), 'timezone' => $timezone, 'subject' => '', 'body' => '', 'contact_id' => '', 'order_id' => '', 'inquiry_id' => ''];
        $this->formOpen = true;
    }

    public function close(): void
    {
        $this->access();
        $this->reset(['formOpen', 'detailOpen', 'selectedId', 'form']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $actor = $this->manualAccess();
        abort_unless($this->formOpen, 409);
        $data = $this->validate([
            'form.channel' => ['required', Rule::in(array_keys(CustomerInteraction::CHANNELS))],
            'form.direction' => ['required', Rule::in(array_keys(CustomerInteraction::DIRECTIONS))],
            'form.occurred_at' => 'required|string|max:19', 'form.timezone' => 'required|timezone|max:64',
            'form.subject' => 'required|string|max:180', 'form.body' => 'required|string|max:5000',
            'form.contact_id' => 'nullable|integer|min:1', 'form.order_id' => 'nullable|integer|min:1', 'form.inquiry_id' => 'nullable|integer|min:1',
        ])['form'];
        abort_unless($data['channel'] !== 'note' || $data['direction'] === 'internal', 422);
        $data['occurred_at'] = OperationsDateTime::local($data['occurred_at'], $data['timezone'], 'form.occurred_at');
        if ($data['occurred_at']->isFuture()) {
            throw ValidationException::withMessages(['form.occurred_at' => 'Für geplante Kontakte eine Wiedervorlage anlegen.']);
        }
        foreach (['contact_id', 'order_id', 'inquiry_id'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? (int) $data[$field] : null;
        }
        OperationsTransaction::run(function () use ($data, $actor): void {
            Customer::lockForUpdate()->findOrFail($this->customerId);
            $actor = $this->manualAccess();
            if ($data['contact_id']) {
                abort_unless($actor->can('operations.inquiries.manage'), 403);
                abort_unless(Schema::hasTable('customer_contacts'), 503);
                CustomerContact::where('customer_id', $this->customerId)->where('is_active', true)->lockForUpdate()->findOrFail($data['contact_id']);
            }
            if ($data['order_id']) {
                abort_unless($actor->can('operations.manage'), 403);
                Order::where('customer_id', $this->customerId)->lockForUpdate()->findOrFail($data['order_id']);
            }
            if ($data['inquiry_id']) {
                abort_unless($actor->can('operations.inquiries.manage'), 403);
                abort_unless(Schema::hasTable('operation_inquiries'), 503);
                $inquiry = OperationInquiry::where('customer_id', $this->customerId)->lockForUpdate()->findOrFail($data['inquiry_id']);
                abort_unless(! $data['order_id'] || ! $inquiry->order_id || (int) $inquiry->order_id === $data['order_id'], 422);
            }
            $record = CustomerInteraction::create($data + ['customer_id' => $this->customerId, 'created_by' => $actor->id]);
            app(OperationsAuditService::class)->record($record, $actor, 'customer.interaction.recorded', ['customer_id' => $this->customerId, 'channel' => $record->channel, 'direction' => $record->direction]);
        });
        $this->close();
        $this->resetPage('customerCommunicationPage');
        $this->dispatch('customer-profile-updated', customerId: $this->customerId);
        $this->dispatch('swal:toast', type: 'success', text: 'Kontakt protokolliert.');
    }

    public function openDetails(int $id): void
    {
        $this->access();
        $this->record($id);
        $this->formOpen = false;
        $this->form = [];
        $this->selectedId = $id;
        $this->detailOpen = true;
    }

    private function record(int $id)
    {
        if ($this->view === 'portal') {
            return CustomerPortalMessage::where('customer_id', $this->customerId)
                ->when(! $this->canRecord($this->actor()), fn ($query) => $query->where('visibility', 'customer'))
                ->findOrFail($id);
        }
        abort_unless(CustomerInteraction::ready(), 503);

        return CustomerInteraction::where('customer_id', $this->customerId)->findOrFail($id);
    }

    public function render()
    {
        $actor = $this->access();
        $this->filters();
        $ready = CustomerInteraction::ready() && Schema::hasTable('operation_audits');
        $records = null;
        if ($this->view !== 'ai' && ($this->view === 'portal' || $ready)) {
            $query = $this->view === 'portal'
                ? CustomerPortalMessage::query()->select(['id', 'subject', 'visibility', 'identity_id', 'order_id', 'created_at'])
                : CustomerInteraction::query()->select(['id', 'subject', 'channel', 'direction', 'occurred_at', 'timezone', 'contact_id', 'order_id', 'inquiry_id', 'created_at']);
            $records = $query->where('customer_id', $this->customerId)
                ->when($this->view === 'portal' && ! $this->canRecord($actor), fn ($q) => $q->where('visibility', 'customer'))
                ->when(trim($this->search) !== '', fn ($q) => $q->where('subject', 'like', '%'.trim($this->search).'%'))
                ->when($this->view === 'records' && $this->channelFilter !== 'all', fn ($q) => $q->where('channel', $this->channelFilter))
                ->orderByDesc($this->view === 'portal' ? 'created_at' : 'occurred_at')->orderByDesc('id')
                ->paginate(15, ['*'], 'customerCommunicationPage');
        }
        $selected = $this->detailOpen && $this->selectedId ? $this->record($this->selectedId) : null;
        $contacts = $orders = $inquiries = collect();
        if ($this->formOpen && $this->canRecord($actor) && $ready) {
            if ($actor->can('operations.inquiries.manage') && Schema::hasTable('customer_contacts')) {
                $contacts = CustomerContact::where('customer_id', $this->customerId)->where('is_active', true)->orderBy('name')->limit(100)->get(['id', 'name']);
            }
            if ($actor->can('operations.inquiries.manage') && Schema::hasTable('operation_inquiries')) {
                $inquiries = OperationInquiry::where('customer_id', $this->customerId)->latest('id')->limit(100)->get(['id', 'title']);
            }
            if ($actor->can('operations.manage')) {
                $orders = Order::where('customer_id', $this->customerId)->latest('id')->limit(100)->get(['id', 'order_number', 'title']);
            }
        }

        return view('livewire.operations.customer-communications', compact('records', 'selected', 'contacts', 'orders', 'inquiries', 'ready') + ['views' => $this->views(), 'canRecord' => $this->canRecord($actor), 'canLinkContacts' => $actor->can('operations.inquiries.manage') && Schema::hasTable('customer_contacts'), 'canLinkInquiries' => $actor->can('operations.inquiries.manage') && Schema::hasTable('operation_inquiries'), 'canLinkOrders' => $actor->can('operations.manage')]);
    }
}
