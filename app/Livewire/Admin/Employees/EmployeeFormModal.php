<?php

namespace App\Livewire\Admin\Employees;

use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\EmployeeWelcomeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;

class EmployeeFormModal extends Component
{
    public bool $showModal = false;

    public ?int $userId = null;

    // Felder
    public string $name = '';

    public string $email = '';

    // Firmen- und persoenliche Stammdaten
    public ?string $position = null;

    public ?string $personnel_nr = null;

    public ?string $phone = null;

    public ?string $mobile = null;

    public ?string $street = null;

    public ?string $postal_code = null;

    public ?string $city = null;

    public ?string $country = null;

    public ?string $birth_date = null;

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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [$this->userId ? 'nullable' : 'required', 'min:8', 'confirmed'],
            'primary_team_id' => ['required', 'integer', 'exists:teams,id'],
            'position' => ['nullable', 'string', 'max:100'],
            'personnel_nr' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
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
            'position', 'personnel_nr', 'phone', 'mobile',
            'street', 'postal_code', 'city', 'country', 'birth_date',
        ]);

        if ($id) {
            $user = User::with(['currentTeam', 'profile'])->findOrFail($id);

            // Admin-Konten duerfen nur von Admins bearbeitet werden
            if ($user->isAdmin() && ! auth()->user()->isAdmin()) {
                abort(403);
            }

            $this->userId = $user->id;
            $this->name = (string) ($user->name ?? '');
            $this->email = (string) ($user->email ?? '');
            $this->primary_team_id = $user->currentTeam?->id;
            $this->position = $user->profile?->position;
            $this->personnel_nr = $user->profile?->personnel_nr;
            $this->phone = $user->profile?->phone;
            $this->mobile = $user->profile?->mobile;
            $this->street = $user->profile?->street;
            $this->postal_code = $user->profile?->postal_code;
            $this->city = $user->profile?->city;
            $this->country = $user->profile?->country;
            $this->birth_date = $user->profile?->birth_date?->format('Y-m-d');
        }

        $this->showModal = true;
    }

    public function save()
    {
        Gate::authorize('employees.create');
        $this->validate();

        $isNewEmployee = ! $this->userId;
        $user = $this->userId ? User::findOrFail($this->userId) : new User;

        // Admin-Konten duerfen nur von Admins bearbeitet werden
        if ($this->userId && $user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        $user->name = $this->name;
        $user->email = $this->email;

        // Neue Mitarbeiter immer 'staff'
        if (! $this->userId) {
            $user->role = 'staff';
        }

        if (! $this->userId) {
            $user->password = Hash::make($this->password);
        } elseif ($this->password) {
            $user->password = Hash::make($this->password);
        }

        // current_team_id direkt setzen
        $user->current_team_id = $this->primary_team_id;

        $user->save();

        UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'position' => $this->position ?: null,
                'personnel_nr' => $this->personnel_nr ?: null,
                'phone' => $this->phone ?: null,
                'mobile' => $this->mobile ?: null,
                'street' => $this->street ?: null,
                'postal_code' => $this->postal_code ?: null,
                'city' => $this->city ?: null,
                'country' => $this->country ?: null,
                'birth_date' => $this->birth_date ?: null,
            ]
        );

        // Genau eine Mitgliedschaft sicherstellen
        if ($this->primary_team_id) {
            $primary = Team::find($this->primary_team_id);
            if ($primary) {
                $user->teams()->sync([$primary->id]);

                // Optional: Jetstream-internen Switch (falls du Features nutzt, die darauf hören)
                $user->switchTeam($primary);
            }
        }

        if ($isNewEmployee) {
            app(EmployeeWelcomeService::class)->send($user->fresh('currentTeam'));
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
