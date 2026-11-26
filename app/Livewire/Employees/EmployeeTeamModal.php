<?php

namespace App\Livewire\Admin\Employees;

use App\Services\Personnel\EmployeeTeamService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EmployeeTeamModal extends Component
{
    public bool $showModal = false;

    #[Locked]
    public array $employeeIds = [];

    #[Locked]
    public array $snapshots = [];

    public ?int $teamId = null;

    #[On('open-employee-team-modal')]
    public function open(array $ids): void
    {
        $this->resetValidation();
        $prepared = app(EmployeeTeamService::class)->prepare($ids);
        $this->employeeIds = $prepared['ids'];
        $this->snapshots = $prepared['snapshots'];
        $current = $prepared['employees']->pluck('current_team_id')->unique();
        $this->teamId = $current->count() === 1 ? $current->first() : null;
        $this->showModal = true;
    }

    public function save(): void
    {
        abort_unless($this->showModal, 403);
        $this->validate(['teamId' => ['required', 'integer', 'min:1']]);
        $changed = app(EmployeeTeamService::class)->change($this->employeeIds, $this->teamId, $this->snapshots);
        $ids = $this->employeeIds;
        $this->close();
        $this->dispatch('employee-teams-updated', ids: $ids);
        $this->dispatch('employeeSaved');
        $this->dispatch('refresh-navigation-menu');
        $this->dispatch('swal:toast', type: 'success', text: $changed === 0 ? 'Die ausgewählten Mitarbeiter sind bereits diesem Team zugeordnet.' : 'Teamzuordnung für '.$changed.' Mitarbeiter aktualisiert.');
    }

    public function close(): void
    {
        $this->reset(['showModal', 'employeeIds', 'snapshots', 'teamId']);
        $this->resetValidation();
    }

    public function render()
    {
        $service = app(EmployeeTeamService::class);
        $canManage = $service->canManage();
        $employees = collect();
        $teams = collect();
        if ($this->showModal) {
            $employees = $service->prepare($this->employeeIds)['employees'];
            $teams = $service->availableTeams();
        }

        return view('livewire.admin.employees.employee-team-modal', compact('employees', 'teams', 'canManage'));
    }
}
