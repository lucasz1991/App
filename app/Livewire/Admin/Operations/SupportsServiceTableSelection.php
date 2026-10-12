<?php

namespace App\Livewire\Admin\Operations;

use Livewire\Attributes\Locked;

/** Page-local UI selection only; it never changes services or planning state. */
trait SupportsServiceTableSelection
{
    #[Locked]
    public array $selectedServiceRows = [];

    #[Locked]
    public array $visibleServiceRows = [];

    #[Locked]
    public string $serviceSelectionContext = '';

    abstract protected function serviceSelectionAvailable(): bool;

    abstract protected function serviceSelectionState(): array;

    /** Fresh, authorized list scope, limited to server-rendered candidate IDs. */
    abstract protected function selectableServiceRowIds(array $candidateIds): array;

    protected function syncServiceTableSelection(array $visibleIds): void
    {
        $context = $this->serviceSelectionFingerprint();
        if ($this->serviceSelectionContext !== $context || ! $this->serviceSelectionAvailable()) {
            $this->selectedServiceRows = [];
        }
        $this->serviceSelectionContext = $context;
        $this->visibleServiceRows = $this->serviceSelectionAvailable()
            ? array_values(array_unique(array_map('intval', $visibleIds))) : [];
        $this->selectedServiceRows = array_values(array_intersect($this->selectedServiceRows, $this->visibleServiceRows));
    }

    public function toggleServiceSelection(int $id): void
    {
        $ids = $this->currentSelectableServiceRows();
        abort_unless($id > 0 && in_array($id, $ids, true), 404);
        $this->selectedServiceRows = array_values(array_intersect($this->selectedServiceRows, $ids));
        if (in_array($id, $this->selectedServiceRows, true)) {
            $this->selectedServiceRows = array_values(array_diff($this->selectedServiceRows, [$id]));
        } else {
            $this->selectedServiceRows[] = $id;
        }
    }

    public function toggleSelectAllServices(): void
    {
        $ids = $this->currentSelectableServiceRows();
        $this->selectedServiceRows = $ids !== [] && array_diff($ids, $this->selectedServiceRows) === [] ? [] : $ids;
    }

    public function clearServiceSelection(): void
    {
        $this->ensureAdmin();
        abort_unless($this->serviceSelectionAvailable(), 403);
        $this->selectedServiceRows = [];
    }

    private function currentSelectableServiceRows(): array
    {
        $this->ensureAdmin();
        abort_unless($this->serviceSelectionAvailable(), 403);
        // A combined filter/page update and action cannot reuse an old page's IDs.
        abort_unless($this->serviceSelectionContext === $this->serviceSelectionFingerprint(), 422);
        $freshIds = $this->selectableServiceRowIds($this->visibleServiceRows);

        return array_values(array_intersect($this->visibleServiceRows, array_map('intval', $freshIds)));
    }

    private function serviceSelectionFingerprint(): string
    {
        return hash('sha256', json_encode($this->serviceSelectionState(), JSON_THROW_ON_ERROR));
    }
}
