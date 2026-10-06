<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerLocation;
use App\Models\User;
use App\Services\CustomerPortal\CustomerCapacityService;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerCapacityConsent extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $revision = null;

    public bool $formOpen = false;

    public bool $confirmed = false;

    public string $search = '';

    private function actor(): User
    {
        CustomerPortalIntakeSchema::requireReady();
        $actor = User::findOrFail(auth()->id());
        OperationsAccess::own($actor, $actor->id);

        return $actor;
    }

    public function updatedSearch(): void
    {
        $this->actor();
        $this->resetPage();
    }

    public function open(int $id): void
    {
        $actor = $this->actor();
        $record = CustomerCapacityCommitment::where('user_id', $actor->id)->find($id);
        abort_unless($record, 404);
        abort_unless($record->status === 'requested' && $record->starts_at->isFuture(), 409);
        $this->recordId = $record->id;
        $this->revision = $record->revision;
        $this->confirmed = false;
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function close(): void
    {
        $this->actor();
        $this->formOpen = false;
        $this->recordId = null;
        $this->revision = null;
        $this->confirmed = false;
        $this->resetValidation();
    }

    public function respond(bool $accept): void
    {
        $actor = $this->actor();
        abort_unless($this->formOpen && $this->recordId && $this->revision, 409);
        $this->validate(['confirmed' => 'accepted']);
        app(CustomerCapacityService::class)->consent($actor, $this->recordId, $this->revision, $accept);
        $this->close();
        session()->flash('operations.saved', $accept ? 'Verfügbarkeit bestätigt.' : 'Anfrage abgelehnt.');
    }

    private function row(CustomerCapacityCommitment $record, array $customers, array $locations): object
    {
        return (object) ['id' => $record->id, 'revision' => $record->revision, 'title' => $record->role_name, 'customer' => $customers[$record->customer_id] ?? 'Kunde',
            'location' => $locations[$record->location_id] ?? 'Einsatzort', 'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at, 'timezone' => $record->timezone,
            'planned_break_minutes' => $record->planned_break_minutes, 'status' => $record->status, 'can_respond' => $record->status === 'requested' && $record->starts_at->isFuture()];
    }

    public function render()
    {
        $actor = $this->actor();
        $search = mb_substr(trim($this->search), 0, 100);
        $query = CustomerCapacityCommitment::where('user_id', $actor->id)->where('ends_at', '>=', now()->utc())->orderBy('starts_at')->orderBy('id');
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $customers = Customer::where(fn ($names) => $names->where('company_name', 'like', '%'.$search.'%')->orWhere('customer_number', 'like', '%'.$search.'%'))->select('id');
                $q->where('role_name', 'like', '%'.$search.'%')->orWhereIn('customer_id', $customers)->orWhereIn('location_id', CustomerLocation::where('name', 'like', '%'.$search.'%')->select('id'));
            });
        }
        $items = $query->paginate(20);
        $selected = $this->formOpen && $this->recordId ? CustomerCapacityCommitment::where('user_id', $actor->id)->findOrFail($this->recordId) : null;
        $all = $items->getCollection()->concat($selected ? [$selected] : []);
        $customers = Customer::whereIn('id', $all->pluck('customer_id')->unique())->pluck('company_name', 'id')->all();
        $locations = CustomerLocation::whereIn('id', $all->pluck('location_id')->unique())->pluck('name', 'id')->all();
        $items->setCollection($items->getCollection()->map(fn ($record) => $this->row($record, $customers, $locations)));

        return view('livewire.operations.customer-capacity-consent', ['items' => $items, 'selected' => $selected ? $this->row($selected, $customers, $locations) : null]);
    }
}
