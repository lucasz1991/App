<?php

namespace App\Livewire\Admin\Employees;

use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Personnel\EmployeeTeamService;
use App\Support\Rbac\RbacCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class TeamRbacModal extends Component
{
    public bool $showModal = false;

    public bool $showCreateTeamModal = false;

    #[Locked]
    public bool $embedded = false;

    public ?int $selectedTeamId = null;

    public string $newTeamName = '';

    /**
     * Matrix: team_id => permission_key => bool
     *
     * @var array<string, array<string, bool>>
     */
    public array $matrix = [];

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;

        if ($embedded) {
            $this->authorizeManager();
            $this->prepareManager();
        }
    }

    #[On('open-team-rbac-modal')]
    public function open(?int $teamId = null): void
    {
        $this->authorizeManager();
        if ($teamId !== null) {
            abort_unless(Team::where('personal_team', false)->whereKey($teamId)->exists(), 404);
            $this->selectedTeamId = $teamId;
        }

        $this->resetTeamCreation();
        $this->showModal = true;
        $this->prepareManager();
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->resetTeamCreation();
        $this->resetValidation();
    }

    public function openCreateTeam(): void
    {
        $this->authorizeManager();
        $this->resetTeamCreation();
        $this->showCreateTeamModal = true;

        if (! $this->embedded) {
            $this->showModal = true;
        }
    }

    public function closeCreateTeam(): void
    {
        $this->resetTeamCreation();
        $this->dispatch('team-create-closed');
    }

    private function resetTeamCreation(): void
    {
        $this->showCreateTeamModal = false;
        $this->newTeamName = '';
        $this->resetValidation('newTeamName');
    }

    public function setTeam(int $teamId): void
    {
        $this->authorizeManager();
        abort_unless(Team::where('personal_team', false)->whereKey($teamId)->exists(), 404);
        $this->selectedTeamId = $teamId;
        $this->initializeTeam($teamId);
    }

    public function setSelectedTeamToFalse(): void
    {
        $this->authorizeManager();
        if ($this->selectedTeamId === null) {
            return;
        }

        $teamId = (int) $this->selectedTeamId;
        $this->matrix[(string) $teamId] = $this->defaultMatrixForTeam();
    }

    public function setSelectedTeamToTrue(): void
    {
        $this->authorizeManager();
        if ($this->selectedTeamId === null) {
            return;
        }

        $teamId = (int) $this->selectedTeamId;
        $permissions = RbacCatalog::allPermissions();
        $permissionMatrix = [];
        foreach ($permissions as $permission) {
            $encodedPermission = $this->permissionKey($permission);
            $permissionMatrix[$encodedPermission] = true;
        }

        $this->matrix[(string) $teamId] = $permissionMatrix;
    }

    public function save(): void
    {
        $this->authorizeManager();

        $teams = $this->teams();
        $teamIds = $teams->pluck('id')->map(fn ($id) => (int) $id)->all();
        $teamSet = array_flip($teamIds);
        $permissions = RbacCatalog::allPermissions();
        $permissionMap = [];
        foreach ($permissions as $permission) {
            $permissionMap[$this->permissionKey($permission)] = $permission;
        }

        $updatedTeamIds = [];
        foreach ($this->matrix as $teamId => $permissionMatrix) {
            $teamId = (int) $teamId;
            if (! isset($teamSet[$teamId])) {
                continue;
            }

            $payload = [];

            foreach ($permissionMatrix as $encodedPermission => $enabled) {
                if (! isset($permissionMap[$encodedPermission])) {
                    continue;
                }
                $payload[$permissionMap[$encodedPermission]] = (bool) $enabled;
            }

            Team::query()->where('id', $teamId)->update([
                'rbac_permissions' => $payload,
            ]);
            $updatedTeamIds[] = $teamId;
        }

        $this->dispatch('team-rights-updated', teamIds: $updatedTeamIds);
        $this->dispatch('refresh-navigation-menu');
        $this->dispatch('swal:toast', type: 'success', title: 'Gespeichert', text: 'Team-Rechte wurden gespeichert.');
    }

    /**
     * @return Collection<int, Team>
     */
    public function teams()
    {
        $this->authorizeManager();

        return Team::query()
            ->where('personal_team', false)
            ->orderBy('name')
            ->get(['id', 'name', 'rbac_permissions']);
    }

    public function createTeam(): void
    {
        $this->authorizeManager();
        $team = app(EmployeeTeamService::class)->create($this->newTeamName);
        $this->resetTeamCreation();
        $this->selectedTeamId = (int) $team->id;
        $this->matrix[(string) $team->id] = $this->defaultMatrixForTeam();
        $this->dispatch('employeeSaved');
        $this->dispatch('team-create-closed');
        $this->dispatch('swal:toast', type: 'success', text: 'Team erstellt. Berechtigungen sind zunächst ausgeschaltet.');
    }

    private function authorizeManager(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
        $actor = User::with('currentTeam')->find(auth()->id());
        abort_unless($actor?->isActive() && $actor->canViewManagementDashboard(), 403);
        Gate::forUser($actor)->authorize('roles.manage');
    }

    protected function loadMatrix(): void
    {
        $this->matrix = [];
        $permissions = RbacCatalog::allPermissions();
        $legacy = Setting::getValue('rbac', 'team_permissions');
        $legacy = is_array($legacy) ? $legacy : [];

        foreach ($this->teams() as $team) {
            $teamPermissions = [];
            $stored = is_array($team->rbac_permissions) ? $team->rbac_permissions : [];

            foreach ($permissions as $permission) {
                $encodedPermission = $this->permissionKey($permission);
                $teamPermissions[$encodedPermission] = (bool) ($stored[$permission] ?? false);
            }

            if (! empty($stored)) {
                $this->matrix[(string) $team->id] = $teamPermissions;

                continue;
            }

            // Legacy-Fallback: aus settings lesen (falls alte Daten existieren)
            if (isset($legacy[(string) $team->id]) && is_array($legacy[(string) $team->id])) {
                foreach ($permissions as $permission) {
                    $encodedPermission = $this->permissionKey($permission);
                    $teamPermissions[$encodedPermission] = (bool) ($legacy[(string) $team->id][$permission] ?? false);
                }
            }

            $this->matrix[(string) $team->id] = $teamPermissions;
        }
    }

    protected function prepareManager(): void
    {
        $this->loadMatrix();

        if ($this->selectedTeamId === null) {
            $firstTeamId = $this->teams()->pluck('id')->first();
            $this->selectedTeamId = $firstTeamId ? (int) $firstTeamId : null;
        }

        if ($this->selectedTeamId !== null) {
            $this->initializeTeam((int) $this->selectedTeamId);
        }
    }

    protected function initializeTeam(int $teamId): void
    {
        $key = (string) $teamId;
        if (! isset($this->matrix[$key]) || ! is_array($this->matrix[$key])) {
            $this->matrix[$key] = $this->defaultMatrixForTeam();

            return;
        }

        $permissions = RbacCatalog::allPermissions();
        foreach ($permissions as $permission) {
            $encodedPermission = $this->permissionKey($permission);
            $current = $this->matrix[$key][$encodedPermission] ?? false;
            $this->matrix[$key][$encodedPermission] = (bool) $current;
        }
    }

    /**
     * @return array<string, bool>
     */
    protected function defaultMatrixForTeam(): array
    {
        $defaults = [];
        foreach (RbacCatalog::defaultTeamPermissions() as $permission => $enabled) {
            $defaults[$this->permissionKey($permission)] = (bool) $enabled;
        }

        return $defaults;
    }

    public function permissionKey(string $permission): string
    {
        return str_replace('.', '__dot__', $permission);
    }

    public function render()
    {
        $teams = collect();
        $permissionGroups = [];
        if ($this->embedded || $this->showModal) {
            $teams = $this->teams();
            $permissionGroups = RbacCatalog::permissionGroups();
        }

        return view('livewire.admin.employees.team-rbac-modal', [
            'teams' => $teams,
            'permissionGroups' => $permissionGroups,
        ]);
    }
}
