<?php

namespace App\Livewire\Operations;

use App\Models\Customer;
use App\Models\User;
use App\Services\Operations\DistributionAutoAssignmentService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class DistributionAutoAssignment extends Component
{
    #[Locked]
    #[Reactive]
    public string $from;

    #[Locked]
    #[Reactive]
    public string $until;

    #[Locked]
    #[Reactive]
    public string $customerFilter = 'all';

    #[Locked]
    public array $preview = [];

    #[Locked]
    public array $outcome = [];

    public function mount(): void
    {
        $this->actor(false);
        $this->scope();
    }

    public function hydrate(): void
    {
        $this->actor(false);
        $this->synchronizeScope();
    }

    public function prepare(): void
    {
        $actor = $this->actor();
        $this->preview = [];
        $this->outcome = [];
        $this->resetValidation('autoAssignment');
        try {
            $this->preview = app(DistributionAutoAssignmentService::class)->prepare($this->from, $this->until, $this->customerFilter, $actor);
        } catch (ValidationException $exception) {
            $this->addError('autoAssignment', collect($exception->errors())->flatten()->implode(' '));
        }
    }

    public function apply(): void
    {
        $actor = $this->actor();
        $this->resetValidation('autoAssignment');
        try {
            $this->outcome = app(DistributionAutoAssignmentService::class)->apply($this->preview, $this->from, $this->until, $this->customerFilter, $actor);
            $this->preview = [];
            $this->dispatch('operations-plan-changed');
        } catch (ValidationException $exception) {
            if ($this->preview !== []) {
                $this->preview['valid'] = false;
            }
            $this->addError('autoAssignment', collect($exception->errors())->flatten()->implode(' '));
        }
    }

    public function resetPreview(): void
    {
        $this->actor();
        $this->preview = [];
        $this->outcome = [];
        $this->resetValidation('autoAssignment');
    }

    public function render()
    {
        $this->actor(false);
        $this->synchronizeScope();
        $available = app(DistributionAutoAssignmentService::class)->available();

        return view('livewire.operations.distribution-auto-assignment', [
            'available' => $available,
            'prepared' => $this->preview !== [],
            'canApply' => $available && ($this->preview['valid'] ?? false) === true && ! empty($this->preview['entries']),
            'customerLabel' => $this->customerFilter === 'all' ? 'Alle Kunden' : (Customer::whereKey($this->customerFilter)->value('company_name') ?? 'Kunde nicht verfügbar'),
        ]);
    }

    private function actor(bool $requireBulkReady = true): User
    {
        abort_unless(auth()->user() instanceof User, 403);

        return app(DistributionAutoAssignmentService::class)->access(auth()->user(), $requireBulkReady);
    }

    private function scope(): array
    {
        return app(DistributionAutoAssignmentService::class)->scope($this->from, $this->until, $this->customerFilter);
    }

    private function synchronizeScope(): void
    {
        $scope = $this->scope();
        if (($this->preview !== [] && ($this->preview['scope'] ?? null) !== $scope)
            || ($this->outcome !== [] && ($this->outcome['scope'] ?? null) !== $scope)) {
            $this->preview = [];
            $this->outcome = [];
            $this->resetValidation('autoAssignment');
        }
    }
}
