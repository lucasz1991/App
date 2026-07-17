<?php

namespace App\Livewire\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class Employees extends Component
{
    use WithPagination;

    protected $paginationTheme = 'tailwind';

    // Auswahl / Bulk
    public array $selectedEmployees = [];
    public bool $selectAll = false;

    // Filter / Suche / Sortierung
    public string $search = '';
    public ?int $teamId = null; // Team-Filter (optional)
    public string $sortBy = 'created_at';
    public string $sortDir = 'desc';
    public int $perPage = 15;

    // Zaehler (Anzeige)
    public int $employeesTotal = 0;

    /** Spalten, nach denen sortiert werden darf */
    private const SORTABLE_COLUMNS = ['id', 'name', 'email', 'created_at'];

    protected $listeners = [
        'employeeSaved' => '$refresh',
        'table-sort' => 'tableSort',
        'toggleEmployeeSelection' => 'toggleEmployeeSelection',
    ];

    public function mount(): void
    {
        Gate::authorize('employees.view');
    }

    public function tableSort($key, $dir = null): void
    {
        if (! in_array($key, self::SORTABLE_COLUMNS, true)) {
            return;
        }

        $this->sortBy = $key;
        $this->sortDir = $dir === 'desc' ? 'desc' : 'asc';
        $this->resetPage();
    }

    public function toggleEmployeeSelection($id): void
    {
        $id = (int) (is_array($id) ? ($id[0] ?? 0) : $id);
        if (! $id) {
            return;
        }

        if (in_array($id, array_map('intval', $this->selectedEmployees), true)) {
            $this->selectedEmployees = array_values(array_diff(array_map('intval', $this->selectedEmployees), [$id]));
        } else {
            $this->selectedEmployees[] = $id;
        }
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingTeamId()
    {
        $this->resetPage();
    }

    public function updatingPerPage()
    {
        $this->resetPage();
    }

    public function sort($col)
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'asc';
        }
    }

    public function toggleSelectAll(): void
    {
        $this->selectAll = ! $this->selectAll;
        if ($this->selectAll) {
            $this->selectedEmployees = $this->currentIds();
        } else {
            $this->selectedEmployees = [];
        }
    }

    public function currentIds(): array
    {
        return $this->employees->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function openCreate(): void
    {
        Gate::authorize('employees.create');
        $this->dispatch('open-employee-form')->to(\App\Livewire\Admin\Employees\EmployeeFormModal::class);
    }

    public function openEdit(int $id): void
    {
        Gate::authorize('employees.create');
        $this->dispatch('open-employee-form', id: $id)->to(\App\Livewire\Admin\Employees\EmployeeFormModal::class);
    }

    public function openTeamRbacModal(): void
    {
        $this->dispatch('open-team-rbac-modal')->to(\App\Livewire\Admin\Employees\TeamRbacModal::class);
    }

    public function openInvite(): void
    {
        Gate::authorize('employees.create');
        $this->dispatch('open-invite-employee')->to(\App\Livewire\Admin\Employees\InviteEmployeeModal::class);
    }

    // Beispiel-Bulk-Aktionen (Platzhalter)
    public function exportSelected(): void
    {
        // TODO: Export-Logik
        $this->dispatch('swal:toast', type: 'info', title: 'Export', text: 'Export vorbereitet.');
    }

    public function clearSelection(): void
    {
        $this->selectedEmployees = [];
        $this->selectAll = false;
    }

    public function activateUser($userId)
    {
        Gate::authorize('employees.create');

        $user = User::find($userId);

        // Admin-Konten duerfen nur von Admins verwaltet werden
        if ($user && $user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        if ($user && ! $user->status) {
            $user->update(['status' => true]);
            $this->dispatch('swal:toast', type: 'success', text: 'Benutzer erfolgreich aktiviert.');
        } else {
            $this->dispatch('swal:toast', type: 'info', text: 'Benutzer ist bereits aktiv.');
        }
    }

    public function deactivateUser($userId)
    {
        Gate::authorize('employees.create');

        $user = User::find($userId);

        // Admin-Konten duerfen nur von Admins verwaltet werden
        if ($user && $user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        if ($user && $user->status) {
            $user->update(['status' => false]);
            $this->dispatch('swal:toast', type: 'success', text: 'Benutzer erfolgreich deaktiviert.');
        } else {
            $this->dispatch('swal:toast', type: 'info', text: 'Benutzer ist bereits inaktiv.');
        }
    }

    public function getEmployeesProperty()
    {
        $allowedRoles = ['admin', 'staff'];

        $base = User::query()
            ->with('currentTeam')
            ->whereIn('role', $allowedRoles)
            ->when($this->search, fn ($q) => $q->where(function ($qq) {
                $s = '%' . $this->search . '%';
                $qq->where('name', 'like', $s)
                    ->orWhere('email', 'like', $s)
                    ->orWhere('id', $this->search);
            }))
            ->when($this->teamId, fn ($q) => $q->whereHas('teams', fn ($qq) => $qq->where('teams.id', $this->teamId)));

        // Gesamtanzahl vor Pagination
        $this->employeesTotal = (clone $base)->count();

        // Whitelist gegen manipulierte Livewire-Payloads
        $sortBy = in_array($this->sortBy, self::SORTABLE_COLUMNS, true) ? $this->sortBy : 'created_at';
        $sortDir = $this->sortDir === 'asc' ? 'asc' : 'desc';
        $perPage = min(max((int) $this->perPage, 5), 100);

        return $base->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    public function render()
    {
        $teams = Team::where('personal_team', false)
            ->orderBy('name')
            ->get(['id', 'name']);
        $employees = $this->employees;

        return view('livewire.admin.employees', compact('employees', 'teams'))
            ->layout('layouts.master');
    }
}
