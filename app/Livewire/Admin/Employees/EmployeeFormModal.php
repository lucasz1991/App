<?php

namespace App\Livewire\Admin\Employees;

use Livewire\Component;
use App\Models\User;
use App\Models\Team;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\On;

class EmployeeFormModal extends Component
{
    public bool $showModal = false;

    public ?int $userId = null;

    // Felder
    public string $name = '';
    public string $email = '';

    // Passwort (nur Create oder optional Reset)
    public ?string $password = null;
    public ?string $password_confirmation = null;

    // Genau EIN Team (Current Team)
    public ?int $primary_team_id = null;

    protected $listeners = [
        'open-employee-form' => 'open',
    ];

    public function rules()
    {
        $userId = $this->userId ?? 0;

        return [
            'name'  => ['required','string','max:255'],
            'email' => ['required','email','max:255', Rule::unique('users','email')->ignore($userId)],
            'password' => [$this->userId ? 'nullable' : 'required','min:8','confirmed'],
            'primary_team_id' => ['required','integer','exists:teams,id'],
        ];
    }

    #[On('open-employee-form')]
    public function open(?int $id = null): void
    {
        Gate::authorize('employees.create');

        $this->resetValidation();
        $this->reset([
            'userId', 'name', 'email',
            'password', 'password_confirmation',
            'primary_team_id',
        ]);

        if ($id) {
            $user = User::with('currentTeam')->findOrFail($id);

            // Admin-Konten duerfen nur von Admins bearbeitet werden
            if ($user->isAdmin() && ! auth()->user()->isAdmin()) {
                abort(403);
            }

            $this->userId = $user->id;
            $this->name = (string) ($user->name ?? '');
            $this->email = (string) ($user->email ?? '');
            $this->primary_team_id = $user->currentTeam?->id;
        }

        $this->showModal = true;
    }

    public function save()
    {
        Gate::authorize('employees.create');
        $this->validate();

        $user = $this->userId ? User::findOrFail($this->userId) : new User();

        // Admin-Konten duerfen nur von Admins bearbeitet werden
        if ($this->userId && $user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        $user->name  = $this->name;
        $user->email = $this->email;

        // Neue Mitarbeiter immer 'staff'
        if (! $this->userId) {
            $user->role = 'staff';
        }

        if (!$this->userId) {
            $user->password = Hash::make($this->password);
        } elseif ($this->password) {
            $user->password = Hash::make($this->password);
        }

        // current_team_id direkt setzen
        $user->current_team_id = $this->primary_team_id;

        $user->save();

        // Genau eine Mitgliedschaft sicherstellen
        if ($this->primary_team_id) {
            $primary = Team::find($this->primary_team_id);
            if ($primary) {
                $user->teams()->sync([$primary->id]);

                // Optional: Jetstream-internen Switch (falls du Features nutzt, die darauf hören)
                $user->switchTeam($primary);
            }
        }

        $this->dispatch('employeeSaved');

        $this->showModal = false;

        // Toast
        $this->dispatch('swal:toast', type: 'success', title: 'Gespeichert', text: 'Mitarbeiter wurde gespeichert.');
    }

    public function close()
    {
        $this->showModal = false;
    }

    public function render()
    {
        $teams = Team::where('personal_team', false)
            ->orderBy('name')
            ->get(['id', 'name']);
        return view('livewire.admin.employees.employee-form-modal', compact('teams'));
    }
}
