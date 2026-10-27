<?php

namespace App\Livewire\Admin\Employees;

use App\Services\Personnel\EmployeeTeamService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EmployeeTeamBulkModal extends Component
{
    public bool $showModal = false;

    #[Locked]
    public array $employeeIds = [];

    #[Locked]
    public array $snapshots = [];

    public string $operation = 'add';

    public array $teamIds = [];

    public ?int $replacementTeamId = null;

    #[On('open-employee-team-bulk-modal')]
    public function open(array $ids): void
    {
        $prepared = app(EmployeeTeamService::class)->prepare($ids);
        $this->reset(['operation', 'teamIds', 'replacementTeamId']);
        $this->resetValidation();
        $this->employeeIds = $prepared['ids'];
        $this->snapshots = $prepared['snapshots'];
        $this->showModal = true;
    }

    public function updatedOperation(): void
    {
        $this->reset(['teamIds', 'replacementTeamId']);
        $this->resetValidation();
    }

    public function updatedTeamIds(): void
    {
        $this->replacementTeamId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        abort_unless($this->showModal, 403);
        $ids = $this->employeeIds;
        $changed = app(EmployeeTeamService::class)->changeMembershipsForSelection($ids, $this->operation, $this->teamIds, $this->snapshots, $this->replacementTeamId);
        $this->close();
        $this->dispatch('employee-teams-updated', ids: $ids);
        $this->dispatch('employeeSaved');
        $this->dispatch('refresh-navigation-menu');
        $this->dispatch('swal:toast', type: 'success', text: $changed ? 'Teammitgliedschaften für '.$changed.' Mitarbeiter gespeichert.' : 'Die Teammitgliedschaften sind unverändert.');
    }

    public function close(): void
    {
        $this->reset(['showModal', 'employeeIds', 'snapshots', 'operation', 'teamIds', 'replacementTeamId']);
        $this->resetValidation();
    }

    public function render()
    {
        $service = app(EmployeeTeamService::class);
        $state = ['employees' => collect(), 'teams' => collect(), 'replacementTeams' => collect(), 'replacementRequired' => 0];
        if ($this->showModal) {
            $state = $service->bulkEditorState($this->employeeIds, $this->operation, $this->teamIds);
        }

        return view('livewire.admin.employees.employee-team-bulk-modal', $state + ['canManage' => $service->canManage()]);
    }
}
