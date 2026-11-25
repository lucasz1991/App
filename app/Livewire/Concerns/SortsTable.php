<?php

namespace App\Livewire\Concerns;

/**
 * Einheitliche Sortierung für x-tables.table (sort-action="tableSort").
 *
 * Die Komponente hält die Sortierung in den öffentlichen Feldern $sortBy und
 * $sortDir (Namen, #[Url]- und #[Locked]-Attribute bleiben in der Klasse) und
 * liefert die erlaubten Spalten über sortableColumns().
 *
 * tableSort($key, $dir):
 *   authorizeTableSort() → Spalte gegen Whitelist (sonst abort unsortableColumnStatus(),
 *   Standard 422) → Richtung asc|desc oder null (sonst 422; null schaltet um:
 *   gleiche Spalte asc → desc, sonst asc) → Seite zurücksetzen (sortPageName(),
 *   null = ohne Paginierung) → afterTableSort().
 */
trait SortsTable
{
    /** @return list<string> */
    abstract protected function sortableColumns(): array;

    public function tableSort(string $key, ?string $dir = null): void
    {
        $this->authorizeTableSort();
        abort_unless(in_array($key, $this->sortableColumns(), true), $this->unsortableColumnStatus());
        abort_unless($dir === null || in_array($dir, ['asc', 'desc'], true), 422);

        $this->sortDir = $dir ?? ($this->sortBy === $key && $this->sortDir === 'asc' ? 'desc' : 'asc');
        $this->sortBy = $key;

        $pageName = $this->sortPageName();
        if ($pageName !== null) {
            $this->resetPage($pageName);
        }

        $this->afterTableSort();
    }

    /** Zugriffsprüfung vor jeder Sortieränderung (z. B. access()/ensureAdmin()). */
    protected function authorizeTableSort(): void {}

    /** HTTP-Status für nicht erlaubte Spalten (403, wenn die Spalte rechteabhängig ist). */
    protected function unsortableColumnStatus(): int
    {
        return 422;
    }

    /** Paginator, der beim Sortieren auf Seite 1 springt; null, wenn die Liste nicht paginiert ist. */
    protected function sortPageName(): ?string
    {
        return 'page';
    }

    protected function afterTableSort(): void {}
}
