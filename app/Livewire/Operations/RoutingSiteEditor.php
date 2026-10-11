<?php

namespace App\Livewire\Operations;

use App\Models\CustomerLocation;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Operations\OperationsAuditService;
use App\Services\Operations\OrderSchedulingService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\SiteSnapshotService;
use App\Support\Operations\OperationsAccess;
use Livewire\Attributes\Locked;
use Livewire\Component;

class RoutingSiteEditor extends Component
{
    #[Locked]
    public string $subjectType;

    #[Locked]
    public int $subjectId;

    #[Locked]
    public string $basis;

    public string $customerLocationId = '';

    public function mount(string $subjectType, int $subjectId): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $record = $this->record();
        $this->customerLocationId = (string) ($record->customer_location_id ?? '');
        $this->basis = $this->fingerprint($record);
    }

    private function record(): Order|Shift
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        abort_unless(SiteSnapshotService::ready(), 503);

        return match ($this->subjectType) {
            'shift' => Shift::with('order')->findOrFail($this->subjectId),
            'order' => Order::findOrFail($this->subjectId),
            default => abort(404),
        };
    }

    private function fingerprint(Order|Shift $record): string
    {
        return hash('sha256', json_encode([$record->updated_at?->format('Y-m-d H:i:s.u'), $record->revision, $record->customer_location_id, $record->location_snapshot], JSON_THROW_ON_ERROR));
    }

    public function save(): void
    {
        $record = $this->record();
        if ($this->fingerprint($record) !== $this->basis) {
            $this->addError('customerLocationId', 'Der Datensatz wurde geändert. Bitte neu öffnen.');

            return;
        }
        $this->validate(['customerLocationId' => ['required', 'integer', 'min:1']]);
        $customerId = $record instanceof Shift ? $record->order->customer_id : $record->customer_id;
        $location = CustomerLocation::where('customer_id', $customerId)->findOrFail($this->customerLocationId);
        $snapshot = app(SiteSnapshotService::class)->snapshotForLocation($location);
        // Explicit refresh also updates a saved snapshot of the same directory entry.
        $attributes = ['customer_location_id' => $location->id, 'location_snapshot' => $snapshot, 'location_name' => $location->name];
        if ($record instanceof Shift) {
            $record = app(ShiftSchedulingService::class)->save($record, $attributes + ['expected_revision' => $record->revision], auth()->user());
        } else {
            $record = app(OrderSchedulingService::class)->save($record, $attributes + ['expected_updated_at' => $record->updated_at?->format('Y-m-d H:i:s.u')], auth()->user());
            app(OperationsAuditService::class)->record($record, auth()->user(), 'order.location.confirmed', ['customer_location_id' => $location->id, 'source_revision' => $location->revision]);
        }
        $this->basis = $this->fingerprint($record);
        $this->dispatch('operations-plan-changed');
        $this->dispatch('swal:toast', type: 'success', text: 'Bestätigter Einsatzstandort übernommen.');
    }

    public function render()
    {
        $record = $this->record();
        $customerId = $record instanceof Shift ? $record->order->customer_id : $record->customer_id;

        return view('livewire.operations.routing-site-editor', [
            'record' => $record,
            'locations' => CustomerLocation::where('customer_id', $customerId)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
