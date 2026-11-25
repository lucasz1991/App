<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Str;

/**
 * Setzt die Paginierung zurück, sobald sich ein Filter- oder Suchfeld ändert.
 *
 * Ersetzt die vielen handgeschriebenen updatedSearch()/updatedFilter()-Hooks,
 * die nur resetPage() aufriefen. Die Komponente nennt ihre Filterfelder in
 * pageResetProperties():
 *
 *     protected function pageResetProperties(): array
 *     {
 *         return ['search', 'statusFilter'];               // Paginator aus filterPageName()
 *         // oder je Feld ein eigener Paginator:
 *         return ['contactSearch' => 'contactsPage'];
 *     }
 *
 * Ablauf je Änderung eines gelisteten Felds (Livewire-Trait-Hook, läuft VOR
 * einem gleichnamigen updatedX() der Klasse):
 *   beforeFilterReset($feld) → resetPage($paginator) → afterFilterReset($feld)
 *
 * beforeFilterReset() ist für Prüfungen gedacht, die vor dem Zurücksetzen
 * laufen müssen (Zugriff, Validierung); afterFilterReset() für Folgeaktionen
 * (URL synchronisieren, Auswahl leeren). Spezifische updatedX()-Hooks mit
 * weiteren Nebenwirkungen dürfen bestehen bleiben, rufen resetPage() aber
 * nicht erneut auf. Verlangt WithPagination in der Komponente.
 */
trait ResetsPageOnFilterChange
{
    /**
     * Filterfelder, deren Änderung die Seite zurücksetzt.
     *
     * @return array<int|string, string> Liste von Feldnamen oder Feld => Paginatorname
     */
    abstract protected function pageResetProperties(): array;

    /** Paginator, der für Felder ohne eigenen Paginatornamen zurückgesetzt wird. */
    protected function filterPageName(): string
    {
        return 'page';
    }

    protected function beforeFilterReset(string $property): void {}

    protected function afterFilterReset(string $property): void {}

    /**
     * Livewire ruft updated{TraitName}() für jeden Trait nach jeder Feldänderung auf.
     * Öffentlich, weil Livewire Trait-Hooks von außen aufruft; als updated*-Methode
     * ist sie vom Client nicht als Aktion aufrufbar.
     */
    public function updatedResetsPageOnFilterChange(string $property, mixed $value = null): void
    {
        $root = Str::before($property, '.');
        $pageName = $this->filterPageNameFor($root);
        if ($pageName === null) {
            return;
        }

        $this->beforeFilterReset($root);
        $this->resetPage($pageName);
        $this->afterFilterReset($root);
    }

    private function filterPageNameFor(string $property): ?string
    {
        foreach ($this->pageResetProperties() as $key => $value) {
            if (is_string($key) && $key === $property) {
                return $value;
            }
            if (is_int($key) && $value === $property) {
                return $this->filterPageName();
            }
        }

        return null;
    }
}
