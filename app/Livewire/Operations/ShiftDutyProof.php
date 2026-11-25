<?php

namespace App\Livewire\Operations;

use App\Models\Shift;
use App\Services\Operations\CustomerProofService;
use App\Support\Operations\OperationsEnhancementsSchema;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftDutyProof extends Component
{
    #[Locked]
    public string $duty;

    #[Locked]
    public ?int $opened = null;

    public int $openCount = 0;

    public ?int $sectionId = null;

    public function updatedSectionId(): void
    {
        $this->opened = null;
    }

    public function mount(string $duty): void
    {
        OperationsEnhancementsSchema::requireReady();
        abort_unless(Schema::hasTable('duty_activities'), 503);
        $this->duty = $duty;
        $this->sectionId = request()->integer('section') ?: null;
    }

    private function shift(): Shift
    {
        return app(CustomerProofService::class)->ownShift($this->duty, auth()->user());
    }

    public function openProof(): void
    {
        $shift = $this->shift();
        $service = app(CustomerProofService::class);
        $rule = $service->dutyRule($shift, $this->sectionId);
        $record = $service->create([
            'order_id' => $shift->order_id, 'shift_id' => $shift->id, 'section_id' => $this->sectionId,
            'title' => $rule['proof_type'],
            'rows' => [['activity' => $rule['title'], 'quantity' => 1, 'unit' => 'Dienst']],
        ], auth()->user(), resume: true);
        $this->opened = $record->id;
        $this->openCount++;
    }

    public function render()
    {
        $shift = $this->shift();
        $service = app(CustomerProofService::class);
        $proof = $service->dutyRecord($shift, auth()->user(), $this->sectionId);

        return view('livewire.operations.shift-duty-proof', [
            'shift' => $shift, 'rule' => $service->dutyRule($shift, $this->sectionId), 'proof' => $proof, 'sections' => $shift->sectionActivityRules(),
        ])->layout('layouts.master');
    }
}
