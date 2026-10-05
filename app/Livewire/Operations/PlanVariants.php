<?php

namespace App\Livewire\Operations;

use App\Models\Order;
use App\Models\PlanVariant;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\User;
use App\Services\Operations\PlanVariantService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\WorkforcePlanningSchema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PlanVariants extends Component
{
    #[Locked]
    public bool $showTrigger = true;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public ?int $revision = null;

    #[Locked]
    public string $status = 'draft';

    #[Locked]
    public string $fingerprint = '';

    #[Locked]
    public array $previewRows = [];

    #[Locked]
    public bool $valid = false;

    public bool $open = false;

    public bool $editOpen = false;

    public bool $entryOpen = false;

    #[Locked]
    public ?int $entryIndex = null;

    public array $selectedShiftIds = [];

    public string $name = '';

    public int $copyDays = 0;

    public array $form = [];

    public array $entry = [];

    public function mount(array $shiftIds = [], bool $showTrigger = true): void
    {
        $this->selectedShiftIds = $shiftIds;
        $this->showTrigger = $showTrigger;
        $this->access();
    }

    private function access(): void
    {
        OperationsAccess::authorize(auth()->user(), 'operations.manage');
        WorkforcePlanningSchema::requireReady();
    }

    public function toggleSelection(int $id): void
    {
        $this->access();
        Shift::findOrFail($id);
        $this->selectedShiftIds = in_array($id, $this->selectedShiftIds, true) ? array_values(array_diff($this->selectedShiftIds, [$id])) : [...$this->selectedShiftIds, $id];
    }

    public function capture(PlanVariantService $service): void
    {
        $this->access();
        $variant = $service->capture(array_map('intval', $this->selectedShiftIds), $this->name, $this->copyDays, auth()->user());
        $this->edit($variant->id);
    }

    public function edit(int $id): void
    {
        $this->access();
        $record = PlanVariant::findOrFail($id);
        $this->recordId = $record->id;
        $this->revision = $record->revision;
        $this->status = $record->status;
        $this->form = $record->only(['name', 'timezone', 'comment', 'entries']) + ['from' => $record->from->toDateString(), 'until' => $record->until->toDateString()];
        $this->open = false;
        $this->editOpen = true;
        $this->reset(['fingerprint', 'previewRows', 'valid']);
        $this->resetValidation();
    }

    public function save(PlanVariantService $service): void
    {
        $this->access();
        $record = $service->save($this->recordId, $this->revision, $this->form, auth()->user());
        $this->revision = $record->revision;
        $this->reset(['fingerprint', 'previewRows', 'valid']);
    }

    public function updatedForm(): void
    {
        $this->reset(['fingerprint', 'previewRows', 'valid']);
    }

    public function editEntry(int $index): void
    {
        $this->access();
        abort_unless($this->status === 'draft' && array_key_exists($index, $this->form['entries'] ?? []), 422);
        $this->entryIndex = $index;
        $this->entry = $this->form['entries'][$index];
        $this->entryOpen = true;
    }

    public function keepEntry(): void
    {
        $this->access();
        abort_unless($this->status === 'draft' && $this->entryIndex !== null, 422);
        $this->entry['user_ids'] = array_map('intval', $this->entry['user_ids'] ?? []);
        $this->entry['qualification_ids'] = array_map('intval', $this->entry['qualification_ids'] ?? []);
        $this->form['entries'][$this->entryIndex] = $this->entry;
        $this->entryOpen = false;
        $this->reset(['fingerprint', 'previewRows', 'valid']);
    }

    public function preview(PlanVariantService $service): void
    {
        $this->access();
        if ($this->status === 'draft') {
            $this->save($service);
        }
        $result = $service->preview(PlanVariant::findOrFail($this->recordId), auth()->user());
        $this->previewRows = $result['rows'];
        $this->fingerprint = $result['fingerprint'];
        $this->valid = $result['valid'];
    }

    public function approve(PlanVariantService $service): void
    {
        $this->access();
        abort_unless($this->valid && $this->fingerprint !== '', 422);
        $service->approve($this->recordId, $this->revision, $this->fingerprint, auth()->user());
        $this->status = 'approved';
        $this->revision++;
    }

    public function apply(PlanVariantService $service): void
    {
        $this->access();
        $service->apply($this->recordId, $this->revision, auth()->user());
        $this->status = 'applied';
        $this->revision++;
        $this->editOpen = false;
        $this->open = true;
        $this->dispatch('operations-plan-changed');
    }

    public function render()
    {
        $this->access();
        $entries = collect($this->form['entries'] ?? [])->map(fn ($entry, $index) => (object) ($entry + ['id' => $index, 'index' => $index, 'people_count' => count($entry['user_ids'])]));
        $preview = collect($this->previewRows)->map(fn ($row) => (object) ['id' => $row['index'], 'title' => $row['title'], 'starts_at' => $row['starts_at'], 'ends_at' => $row['ends_at'], 'open' => $row['open'], 'error' => collect($row['issues'])->flatten(1)->pluck('message')->merge($row['schedule_issues'])->implode(' · ') ?: 'Keine Konflikte']);

        return view('livewire.operations.plan-variants', ['variants' => PlanVariant::latest()->limit(30)->get(), 'shifts' => Shift::notCancelled()->where('starts_at', '>', now()->utc())->orderBy('starts_at')->limit(100)->get(), 'entries' => $entries, 'preview' => $preview, 'people' => User::where('role', 'staff')->where('status', true)->orderBy('name')->get(), 'types' => QualificationType::where('is_active', true)->orderBy('name')->get(), 'orders' => Order::where('status', '!=', 'cancelled')->orderByDesc('starts_at')->limit(100)->get()]);
    }
}
