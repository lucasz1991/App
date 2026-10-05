<?php

namespace App\Livewire\Operations;

use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\QualificationType;
use App\Models\WorkforcePool;
use App\Services\Operations\OrderDemandService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\PlanningSchema;
use App\Support\Operations\WorkforcePlanningSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class OrderDemands extends Component
{
    #[Locked]
    public int $orderId;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $revision = null;

    public bool $formOpen = false;

    public int $breakMinutes = 30;

    public array $form = ['role_name' => '', 'required_staff' => 1, 'starts_at' => '', 'ends_at' => '', 'timezone' => 'Europe/Berlin', 'staffing_mode' => 'minimum', 'maximum_staff' => null, 'qualification_ids' => [], 'workforce_pool_id' => null];

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        PlanningSchema::requireReady();
        Order::findOrFail($this->orderId);
    }

    public function edit(?int $id = null): void
    {
        $this->access();
        $this->reset(['form', 'revision']);
        $this->editingId = $id;
        if ($id) {
            $record = OrderDemand::where('order_id', $this->orderId)->findOrFail($id);
            $this->revision = $record->revision;
            $this->form = array_merge($this->form, $record->only(['role_name', 'required_staff', 'timezone']), ['starts_at' => $record->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $record->ends_at->format('Y-m-d\TH:i')]);
            if (WorkforcePlanningSchema::ready()) {
                $this->form = array_merge($this->form, $record->only(['staffing_mode', 'maximum_staff', 'qualification_ids', 'workforce_pool_id']));
                $this->form['qualification_ids'] ??= [];
            }
        } else {
            $order = Order::findOrFail($this->orderId);
            $this->form['timezone'] = $order->timezone;
        }
        $this->formOpen = true;
        $this->resetValidation();
    }

    public function save(OrderDemandService $service): void
    {
        $this->access();
        $data = $this->form;
        $data['workforce_pool_id'] = $data['workforce_pool_id'] ?: null;
        $data['maximum_staff'] = $data['maximum_staff'] ?: null;
        $data['qualification_ids'] = array_map('intval', $data['qualification_ids'] ?? []);
        $service->save($this->orderId, $this->editingId, $this->revision, $data, auth()->user());
        $this->formOpen = false;
    }

    public function generate(int $id, int $revision, OrderDemandService $service): void
    {
        $this->access();
        OrderDemand::where('order_id', $this->orderId)->findOrFail($id);
        $service->generate($id, $revision, $this->breakMinutes, auth()->user());
        $this->dispatch('operations-plan-changed');
    }

    public function cancel(int $id, int $revision, OrderDemandService $service): void
    {
        $this->access();
        OrderDemand::where('order_id', $this->orderId)->findOrFail($id);
        $service->cancel($id, $revision, auth()->user());
    }

    public function render()
    {
        $this->access();
        $demands = OrderDemand::where('order_id', $this->orderId)->orderBy('starts_at')->get();
        $demands->each(fn ($d) => $d->setAttribute('coverage', app(OrderDemandService::class)->coverage($d)));

        $extended = WorkforcePlanningSchema::ready();
        $matrix = $extended && $this->editingId ? collect(app(OrderDemandService::class)->coverage(OrderDemand::findOrFail($this->editingId))['segments'])->map(fn ($row, $index) => (object) ($row + ['id' => $index])) : collect();

        return view('livewire.operations.order-demands', ['demands' => $demands, 'extended' => $extended, 'matrix' => $matrix, 'pools' => $extended ? WorkforcePool::where('is_active', true)->orderBy('name')->get() : collect(), 'types' => $extended ? QualificationType::where('is_active', true)->orderBy('name')->get() : collect()]);
    }
}
