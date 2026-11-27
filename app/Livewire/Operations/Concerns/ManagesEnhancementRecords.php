<?php

namespace App\Livewire\Operations\Concerns;

use App\Livewire\Concerns\ResetsPageOnFilterChange;
use App\Livewire\Concerns\ResolvesOperationsActor;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsEnhancementsSchema;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

/**
 * Gemeinsamer Rahmen der Vorgangslisten, die bis Phase 7 in der
 * Sammelkomponente OperationsEnhancements lagen: ein gesperrter Reiter,
 * eine Liste mit Suche, ein Formular- und ein Detaildialog.
 *
 * Die Fachbereiche sind eigene Komponenten:
 *   - ServiceFulfilment  (Leistungen: Nachweise, Reisen, Partner, Eingangsprüfung, Auftragskosten)
 *   - TimeAdministration (Zeitwirtschaft: Fachregeln, Monatsabschluss, Terminalzugang)
 *
 * Die Komponente liefert TABS (Reiter => Bezeichnung), ability(), access(),
 * tabOffered(), blankTabForm() und resetTabState().
 */
trait ManagesEnhancementRecords
{
    use ResetsPageOnFilterChange, ResolvesOperationsActor, WithPagination;

    #[Locked]
    public bool $personal = false;

    #[Locked]
    public bool $embedded = false;

    #[Locked]
    public ?int $contextUserId = null;

    #[Locked]
    public string $tab = '';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $selectedRevision = null;

    public bool $formOpen = false;

    public bool $detailOpen = false;

    public string $search = '';

    public array $form = [];

    public string $note = '';

    /** Fähigkeit, die der Reiter außerhalb der persönlichen Ansicht verlangt. */
    abstract protected function ability(string $tab): string;

    /** Zugriff für den aktuellen Reiter prüfen und den frisch geladenen Akteur liefern. */
    abstract protected function access(): User;

    /** Ob der Reiter in der aktuellen Einbettung (persönlich, Auftrag, nur Export) angeboten wird. */
    abstract protected function tabOffered(string $tab): bool;

    /** Formularzustand direkt nach dem Öffnen eines Reiters. */
    abstract protected function blankTabForm(): array;

    /** Auswahl, Dialoge und Eingaben beim Reiterwechsel verwerfen. */
    abstract protected function resetTabState(): void;

    /**
     * Übergang Phase 7: Der alte Komponentenname operations.operations-enhancements
     * löst auf ServiceFulfilment auf. Ein vor dem Umbau geöffneter Snapshot mit
     * einem Reiter, der jetzt zu einer anderen Komponente gehört, läuft mit 419 ab
     * (Hinweis zur abgelaufenen Sitzung) statt mit einem Serverfehler; Snapshots
     * mit einem eigenen Reiter arbeiten unverändert weiter.
     */
    public function hydrate(): void
    {
        abort_unless(array_key_exists($this->tab, static::TABS), 419);
    }

    public function ready(): bool
    {
        return OperationsEnhancementsSchema::ready();
    }

    public function tabs(): array
    {
        return array_filter(static::TABS, fn ($label, $key) => $this->tabOffered($key) && $this->canTab($key), ARRAY_FILTER_USE_BOTH);
    }

    private function canTab(string $tab): bool
    {
        $actor = auth()->user()?->fresh();

        return $actor && $actor->status && ($this->personal ? OperationsAccess::isEmployee($actor) : $actor->can($this->ability($tab)));
    }

    public function setTab(string $tab): void
    {
        $this->access();
        abort_unless(array_key_exists($tab, $this->tabs()), 403);
        $this->tab = $tab;
        $this->resetTabState();
        $this->form = $this->blankTabForm();
        $this->resetPage();
    }

    protected function pageResetProperties(): array
    {
        return ['search'];
    }

    private function nulls(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? $this->nulls($value) : ($value === '' ? null : $value);
        }

        return $data;
    }
}
