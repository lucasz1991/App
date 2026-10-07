<?php

namespace App\Livewire\Operations;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Models\User;
use App\Services\Operations\AiPlanningService;
use App\Support\Operations\OperationsAccess;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class AiPlanningHelper extends Component
{
    #[Locked]
    public ?int $shiftId = null;

    #[Locked]
    public string $from = '';

    #[Locked]
    public string $until = '';

    #[Locked]
    public array $proposal = [];

    #[Locked]
    public ?int $variantId = null;

    #[Locked]
    public bool $draftApplied = false;

    #[Locked]
    public array $publishedApplied = [];

    public bool $open = false;

    public function mount(?int $shiftId = null, string $from = '', string $until = ''): void
    {
        $this->shiftId = $shiftId;
        $this->from = $from;
        $this->until = $until;
        $this->actor();
    }

    #[On('open-ai-period-planning')]
    public function openPeriod(): void
    {
        $this->actor();
        if ($this->shiftId === null) {
            $this->open = true;
        }
    }

    public function analyze(AiPlanningService $service): void
    {
        $actor = $this->actor();
        $this->reset(['proposal', 'variantId', 'draftApplied', 'publishedApplied']);
        $this->resetValidation();
        try {
            $this->proposal = $this->shiftId === null
                ? $service->analyzePeriod($this->from, $this->until, $actor)
                : $service->analyzeShift($this->shiftId, $actor);
        } catch (ValidationException $exception) {
            $this->addError('aiPlanning', collect($exception->errors())->flatten()->first());
        }
    }

    public function choose(int $userId, AiPlanningService $service): void
    {
        $actor = $this->actor();
        abort_unless($this->shiftId !== null, 422);
        try {
            $service->selectCandidate($this->proposal['token'] ?? '', $this->shiftId, $userId, $actor);
            $this->dispatch('operations-ai-candidate-choice', token: $this->proposal['token'], shiftId: $this->shiftId, userId: $userId)->to(ShiftManagement::class);
        } catch (ValidationException $exception) {
            $this->addError('aiPlanning', collect($exception->errors())->flatten()->first());
        }
    }

    public function saveVariant(AiPlanningService $service): void
    {
        $actor = $this->actor();
        abort_unless($this->shiftId === null, 422);
        try {
            $this->variantId = $service->saveDraftVariant($this->proposal['token'] ?? '', $actor)->id;
            $this->resetValidation('aiPlanning');
        } catch (ValidationException $exception) {
            $this->addError('aiPlanning', collect($exception->errors())->flatten()->first());
        }
    }

    public function applyDraft(AiPlanningService $service): void
    {
        $actor = $this->actor();
        abort_unless($this->shiftId === null && $this->variantId !== null, 422);
        try {
            $service->applyDraftVariant($this->proposal['token'] ?? '', $actor);
            $this->draftApplied = true;
            $this->resetValidation('aiPlanning');
            $this->dispatch('operations-plan-changed');
        } catch (ValidationException $exception) {
            $this->addError('aiPlanning', collect($exception->errors())->flatten()->first());
        }
    }

    public function confirmPublished(int $shiftId, int $userId, AiPlanningService $service): void
    {
        $actor = $this->actor();
        abort_unless($this->shiftId === null, 422);
        try {
            $service->confirmPublished($this->proposal['token'] ?? '', $shiftId, $userId, $actor);
            $this->publishedApplied[$shiftId.':'.$userId] = true;
            $this->resetValidation('aiPlanning');
            $this->dispatch('operations-plan-changed');
        } catch (ValidationException $exception) {
            $this->addError('aiPlanning', collect($exception->errors())->flatten()->first());
        }
    }

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor instanceof User, 403);
        OperationsAccess::authorize($actor, 'operations.manage');
        OperationsAccess::requireReady();

        return $actor;
    }

    public function render()
    {
        $this->actor();

        return view('livewire.operations.ai-planning-helper', ['available' => app(AiPlanningService::class)->available()]);
    }
}
