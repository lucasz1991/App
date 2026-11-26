<?php

namespace App\Livewire\Employees;

use App\Services\Personnel\EmployeeTeamService;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Teamdialog der Mitarbeiterverwaltung in zwei Modi:
 *
 * - single: primäres Team für die Auswahl wechseln ("Team wechseln")
 * - bulk:   Teammitgliedschaften für die Auswahl bearbeiten ("Teams bearbeiten")
 *
 * Auf einer Seite können beide Modi als getrennte Instanzen derselben Klasse
 * liegen. Deshalb hört jede Instanz nur auf das Öffnen-Ereignis ihres Modus.
 */
class EmployeeTeamModal extends Component
{
    public const MODE_SINGLE = 'single';

    public const MODE_BULK = 'bulk';

    private const OPEN_EVENTS = [
        self::MODE_SINGLE => 'open-employee-team-modal',
        self::MODE_BULK => 'open-employee-team-bulk-modal',
    ];

    /**
     * Leer heißt: Snapshot stammt aus der Zeit vor der Zusammenführung und
     * trägt keinen Modus (siehe hydrate()). mount() setzt immer einen Modus.
     */
    #[Locked]
    public string $mode = '';

    public bool $showModal = false;

    #[Locked]
    public array $employeeIds = [];

    #[Locked]
    public array $snapshots = [];

    // Modus single
    public ?int $teamId = null;

    // Modus bulk
    public string $operation = 'add';

    public array $teamIds = [];

    public ?int $replacementTeamId = null;

    public function mount(string $mode = self::MODE_SINGLE): void
    {
        if (! isset(self::OPEN_EVENTS[$mode])) {
            throw new InvalidArgumentException('Unbekannter Modus für den Teamdialog: '.$mode);
        }

        $this->mode = $mode;
    }

    public function hydrate(): void
    {
        if (isset(self::OPEN_EVENTS[$this->mode])) {
            return;
        }

        // Übergang Phase 7 (nach Auslieferung entfernbar): Snapshots der
        // früheren Einzel- und Mehrfachklasse kennen keinen Modus. Ein alter
        // Einzeldialog läuft als "single" weiter; ein alter Mehrfachdialog
        // (erkennbar am Komponentennamen im Snapshot) läuft ab, statt im
        // falschen Modus zu arbeiten – Livewire bietet dann das Neuladen an.
        $name = $this->legacySnapshotName();
        abort_unless($name !== null && ! str_ends_with($name, 'employee-team-bulk-modal'), 419);

        $this->mode = self::MODE_SINGLE;
    }

    private function legacySnapshotName(): ?string
    {
        foreach ((array) request('components', []) as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);

            if (($snapshot['memo']['id'] ?? null) === $this->getId()) {
                return $snapshot['memo']['name'] ?? null;
            }
        }

        return null;
    }

    protected function getListeners()
    {
        return isset(self::OPEN_EVENTS[$this->mode]) ? [self::OPEN_EVENTS[$this->mode] => 'open'] : [];
    }

    public function open(array $ids): void
    {
        if ($this->isBulk()) {
            $prepared = app(EmployeeTeamService::class)->prepare($ids);
            $this->reset(['operation', 'teamIds', 'replacementTeamId']);
            $this->resetValidation();
            $this->employeeIds = $prepared['ids'];
            $this->snapshots = $prepared['snapshots'];
            $this->showModal = true;

            return;
        }

        $this->resetValidation();
        $prepared = app(EmployeeTeamService::class)->prepare($ids);
        $this->employeeIds = $prepared['ids'];
        $this->snapshots = $prepared['snapshots'];
        $current = $prepared['employees']->pluck('current_team_id')->unique();
        $this->teamId = $current->count() === 1 ? $current->first() : null;
        $this->showModal = true;
    }

    public function updatedOperation(): void
    {
        if (! $this->isBulk()) {
            return;
        }

        $this->reset(['teamIds', 'replacementTeamId']);
        $this->resetValidation();
    }

    public function updatedTeamIds(): void
    {
        if (! $this->isBulk()) {
            return;
        }

        $this->replacementTeamId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        abort_unless($this->showModal, 403);

        if ($this->isBulk()) {
            $ids = $this->employeeIds;
            $changed = app(EmployeeTeamService::class)->changeMembershipsForSelection($ids, $this->operation, $this->teamIds, $this->snapshots, $this->replacementTeamId);
            $this->close();
            $this->dispatchSaved($ids, $changed ? 'Teammitgliedschaften für '.$changed.' Mitarbeiter gespeichert.' : 'Die Teammitgliedschaften sind unverändert.');

            return;
        }

        $this->validate(['teamId' => ['required', 'integer', 'min:1']]);
        $changed = app(EmployeeTeamService::class)->change($this->employeeIds, $this->teamId, $this->snapshots);
        $ids = $this->employeeIds;
        $this->close();
        $this->dispatchSaved($ids, $changed === 0 ? 'Die ausgewählten Mitarbeiter sind bereits diesem Team zugeordnet.' : 'Teamzuordnung für '.$changed.' Mitarbeiter aktualisiert.');
    }

    public function close(): void
    {
        $this->reset(['showModal', 'employeeIds', 'snapshots', 'teamId', 'operation', 'teamIds', 'replacementTeamId']);
        $this->resetValidation();
    }

    public function render()
    {
        $service = app(EmployeeTeamService::class);
        $isBulk = $this->isBulk();

        if ($isBulk) {
            $state = ['employees' => collect(), 'teams' => collect(), 'replacementTeams' => collect(), 'replacementRequired' => 0];
            if ($this->showModal) {
                $state = $service->bulkEditorState($this->employeeIds, $this->operation, $this->teamIds);
            }

            return view('livewire.employees.employee-team-modal', $state + ['canManage' => $service->canManage(), 'isBulk' => true]);
        }

        $canManage = $service->canManage();
        $employees = collect();
        $teams = collect();
        if ($this->showModal) {
            $employees = $service->prepare($this->employeeIds)['employees'];
            $teams = $service->availableTeams();
        }

        return view('livewire.employees.employee-team-modal', compact('employees', 'teams', 'canManage', 'isBulk'));
    }

    private function isBulk(): bool
    {
        return $this->mode === self::MODE_BULK;
    }

    private function dispatchSaved(array $ids, string $message): void
    {
        $this->dispatch('employee-teams-updated', ids: $ids);
        $this->dispatch('employeeSaved');
        $this->dispatch('refresh-navigation-menu');
        $this->dispatch('swal:toast', type: 'success', text: $message);
    }
}
