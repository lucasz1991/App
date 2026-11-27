<?php

namespace App\Livewire\Concerns\AiIntake;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Liste des KI-Eingangs: Suche, Zustandsfilter und Seitenwechsel.
 *
 * Teil von App\Livewire\Operations\AiIntakeInbox; Zustand (Properties), actor(),
 * scope(), selected() und perform() liegen in der Komponente.
 */
trait ManagesIntakeList
{
    /** Von der Komponente bereitzustellen (App\Livewire\Operations\AiIntakeInbox). */
    abstract private function scope(): \Illuminate\Database\Eloquent\Builder;

    public function activeFilterCount(): int
    {
        return (int) (trim($this->search) !== '') + (int) ($this->statusFilter !== 'all');
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->resetPage('aiIntakePage');
    }

    /** Aktuelle Seite der Eingangsliste; ohne eingerichtete AI-Annahme gibt es keine Liste. */
    private function intakePage(bool $ready): ?LengthAwarePaginator
    {
        if (! $ready) {
            return null;
        }

        return $this->scope()->with('customer')
            ->when($this->statusFilter !== 'all', fn ($query) => $query->where('status', $this->statusFilter))
            ->when(trim($this->search) !== '', fn ($query) => $query->where('title', 'like', '%'.mb_substr(trim($this->search), 0, 100).'%'))
            ->latest('id')->paginate(12, ['*'], 'aiIntakePage');
    }
}
