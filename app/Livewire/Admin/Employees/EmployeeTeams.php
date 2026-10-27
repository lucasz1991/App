<?php

namespace App\Livewire\Admin\Employees;

use App\Services\Personnel\EmployeeTeamService;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeTeams extends Component
{
    #[Locked]
    public int $userId;

    #[Locked]
    public string $snapshot = '';

    public array $teamIds = [];

    public ?int $primaryTeamId = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->reload();
    }

    public function reload(): void
    {
        $prepared = app(EmployeeTeamService::class)->prepare([$this->userId]);
        $employee = $prepared['employees']->first();
        $this->snapshot = $prepared['snapshots'][$this->userId];
        $this->teamIds = $employee->teams->filter(fn ($team) => ! $team->personal_team && ! $employee->ownsTeam($team))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $this->primaryTeamId = $employee->current_team_id ? (int) $employee->current_team_id : null;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate(['primaryTeamId' => ['required', 'integer', 'min:1']]);
        $changed = app(EmployeeTeamService::class)->updateMemberships($this->userId, $this->teamIds, $this->primaryTeamId, $this->snapshot);
        $this->reload();
        $this->dispatch('employee-teams-updated', ids: [$this->userId]);
        $this->dispatch('employeeSaved');
        $this->dispatch('refresh-navigation-menu');
        $this->dispatch('swal:toast', type: 'success', text: $changed ? 'Teammitgliedschaften gespeichert.' : 'Die Teammitgliedschaften sind unverändert.');
    }

    public function render()
    {
        $service = app(EmployeeTeamService::class);
        $state = $service->editorState($this->userId, $this->teamIds);

        return view('livewire.admin.employees.employee-teams', $state + ['canManage' => $service->canManage()]);
    }
}
