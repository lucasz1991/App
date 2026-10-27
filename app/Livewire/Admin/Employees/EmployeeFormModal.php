<?php

namespace App\Livewire\Admin\Employees;

use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Personnel\EmployeeTeamService;
use App\Support\EmployeeWelcomeService;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EmployeeFormModal extends Component
{
    public bool $showModal = false;

    public bool $prepareMicrosoft = true;

    #[Locked]
    public ?int $userId = null;

    public array $selected_team_ids = [];

    #[Locked]
    public ?string $teamSnapshot = null;

    #[Locked]
    public array $originalTeamState = [];

    public string $name = '';

    public string $email = '';

    public ?string $password = null;

    public ?string $password_confirmation = null;

    public ?int $primary_team_id = null;

    public ?string $first_name = null;

    public ?string $last_name = null;

    public ?string $phone = null;

    public ?string $mobile = null;

    public ?string $street = null;

    public ?string $postal_code = null;

    public ?string $city = null;

    public ?string $country = null;

    public ?string $birth_date = null;

    public ?string $birth_place = null;

    public ?string $birth_name = null;

    public ?string $nationality = null;

    public ?string $education = null;

    public ?string $position = null;

    public ?string $personnel_nr = null;

    public ?string $entry_date = null;

    public ?string $multiple_employment = null;

    public ?string $employment_type = null;

    public ?string $weekly_working_hours = null;

    public ?string $additional_information = null;

    public ?string $tax_identification_number = null;

    public ?string $social_security_number = null;

    public ?string $iban = null;

    public ?string $health_insurance = null;

    public ?string $tax_class = null;

    public ?string $children_count = null;

    public ?string $religion = null;

    public ?string $compensation_type = null;

    public ?string $compensation_amount = null;

    protected $listeners = ['open-employee-form' => 'open'];

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId ?? 0)],
            'password' => [$this->userId || $this->prepareMicrosoft ? 'nullable' : 'required', 'min:8', 'confirmed'],
            'primary_team_id' => [$this->userId && ! app(EmployeeTeamService::class)->canManage() ? 'nullable' : 'required', 'integer', 'exists:teams,id'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:50'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:120'],
            'birth_name' => ['nullable', 'string', 'max:120'],
            'nationality' => ['nullable', 'string', 'max:120'],
            'education' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->canEditMasterData()) {
            $rules += [
                'position' => ['nullable', 'string', 'max:120'],
                'personnel_nr' => ['nullable', 'string', 'max:100'],
                'entry_date' => ['nullable', 'date'],
                'multiple_employment' => ['nullable', Rule::in(['0', '1'])],
                'employment_type' => ['nullable', Rule::in(['employee', 'worker'])],
                'weekly_working_hours' => ['nullable', 'numeric', 'min:0', 'max:168'],
                'additional_information' => ['nullable', 'string', 'max:4000'],
            ];
        }

        if ($this->canEditCompensation()) {
            $rules += [
                'tax_identification_number' => ['nullable', 'string', 'max:50'],
                'social_security_number' => ['nullable', 'string', 'max:50'],
                'iban' => ['nullable', 'string', 'max:50'],
                'health_insurance' => ['nullable', 'string', 'max:160'],
                'tax_class' => ['nullable', 'string', 'max:20'],
                'children_count' => ['nullable', 'integer', 'min:0', 'max:30'],
                'religion' => ['nullable', 'string', 'max:80'],
                'compensation_type' => ['nullable', Rule::in(['salary', 'fixed_salary', 'hourly_wage'])],
                'compensation_amount' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        return $rules;
    }

    #[On('open-employee-form')]
    public function open(?int $id = null): void
    {
        Gate::authorize('employees.create');
        $this->resetValidation();
        $this->resetForm();

        if ($id) {
            $user = User::with(['currentTeam', 'profile', 'teams', 'ownedTeams'])->findOrFail($id);
            if ($user->isAdmin() && ! auth()->user()->isAdmin()) {
                abort(403);
            }

            $this->userId = $user->id;
            $this->name = (string) $user->name;
            $this->email = (string) $user->email;
            $this->primary_team_id = $user->currentTeam?->id;
            $this->selected_team_ids = $user->teams->filter(fn (Team $team) => ! $team->personal_team && ! $user->ownsTeam($team))
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $this->originalTeamState = ['teamIds' => $this->selected_team_ids, 'primaryTeamId' => $this->primary_team_id];
            if (app(EmployeeTeamService::class)->canManage()) {
                $this->teamSnapshot = app(EmployeeTeamService::class)->prepare([$user->id])['snapshots'][$user->id];
            }
            $profile = $user->profile;

            foreach (['first_name', 'last_name', 'phone', 'mobile', 'street', 'postal_code', 'city', 'country', 'birth_place', 'birth_name', 'nationality', 'education'] as $field) {
                $this->{$field} = $profile?->{$field};
            }
            $this->birth_date = $profile?->birth_date?->format('Y-m-d');

            if (Gate::allows('employees.master-data.view')) {
                foreach (['position', 'personnel_nr', 'employment_type', 'weekly_working_hours', 'additional_information'] as $field) {
                    $this->{$field} = $profile?->{$field};
                }
                $this->entry_date = $profile?->entry_date?->format('Y-m-d');
                $this->multiple_employment = is_null($profile?->multiple_employment) ? null : ($profile->multiple_employment ? '1' : '0');
            }

            if (Gate::allows('employees.compensation.view')) {
                foreach (['tax_identification_number', 'social_security_number', 'iban', 'health_insurance', 'tax_class', 'children_count', 'religion', 'compensation_type', 'compensation_amount'] as $field) {
                    $this->{$field} = $profile?->{$field};
                }
            }
        }

        $this->showModal = true;
    }

    public function save(): void
    {
        Gate::authorize('employees.create');
        $this->validate();

        if ($this->userId) {
            DB::transaction(fn () => $this->persistEmployee(), 3);
            $this->dispatch('employee-teams-updated', ids: [$this->userId]);
        } else {
            // New employees retain the established single-team onboarding path.
            $this->persistEmployee();
        }

        $this->dispatch('employeeSaved');
        $this->showModal = false;
        $this->dispatch('swal:toast', type: 'success', title: __('app.saved'), text: __('app.employee_saved'));
    }

    private function persistEmployee(): void
    {
        $isNewEmployee = ! $this->userId;
        $user = $this->userId ? User::whereKey($this->userId)->lockForUpdate()->firstOrFail() : new User;
        if ($this->userId && $user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }
        if ($this->userId) {
            $this->saveTeamMemberships();
            $user->refresh();
        }

        $structuredName = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));
        $user->name = $structuredName !== '' ? $structuredName : $this->name;
        $user->email = $this->email;
        if (! $this->userId) {
            $user->current_team_id = $this->primary_team_id;
            $user->role = 'staff';
            $user->locale = app()->getLocale();
            if ($this->prepareMicrosoft) {
                $user->status = false;
                $user->email_verified_at = null;
            }
        }
        if (! $this->userId || $this->password) {
            $user->password = Hash::make(! $this->userId && $this->prepareMicrosoft ? Str::random(64) : (string) $this->password);
        }
        $user->save();

        $profileValues = $this->nullableValues([
            'first_name', 'last_name', 'phone', 'mobile', 'street', 'postal_code', 'city', 'country',
            'birth_date', 'birth_place', 'birth_name', 'nationality', 'education',
        ]);

        if ($this->canEditMasterData()) {
            $profileValues += $this->nullableValues([
                'position', 'personnel_nr', 'entry_date', 'multiple_employment', 'employment_type',
                'weekly_working_hours', 'additional_information',
            ]);
        }

        if ($this->canEditCompensation()) {
            $profileValues += $this->nullableValues([
                'tax_identification_number', 'social_security_number', 'iban', 'health_insurance',
                'tax_class', 'children_count', 'religion', 'compensation_type', 'compensation_amount',
            ]);
        }

        UserProfile::updateOrCreate(['user_id' => $user->id], $profileValues);

        if ($isNewEmployee && $this->primary_team_id && ($team = Team::find($this->primary_team_id))) {
            $user->teams()->sync([$team->id]);
            $user->switchTeam($team);
        }

        // Die direkte Pivot-Synchronisation loest nicht in jedem Pfad ein
        // Jetstream-Teamereignis aus. Nach allen Personenschreibvorgaengen
        // wird deshalb nochmals derselbe idempotente Neuaufbau eingeplant.
        app(OutlookAddinSnapshotRefreshScheduler::class)->scheduleForUser($user);

        if ($this->canEditMasterData() || $this->canEditCompensation()) {
            activity('employee-master-data')
                ->causedBy(auth()->user())
                ->performedOn($user)
                ->withProperties(['target_user_id' => $user->id])
                ->log('employee_master_data_updated');
        }

        if ($isNewEmployee && ! $this->prepareMicrosoft) {
            app(EmployeeWelcomeService::class)->send($user->fresh('currentTeam'));
        }

    }

    private function saveTeamMemberships(): void
    {
        $service = app(EmployeeTeamService::class);
        if ($service->canManage()) {
            if ($this->teamSnapshot === null) {
                throw ValidationException::withMessages(['selected_team_ids' => 'Bitte öffnen Sie das Mitarbeiterformular erneut, bevor Sie Teams ändern.']);
            }
            try {
                $service->updateMemberships($this->userId, $this->selected_team_ids, (int) $this->primary_team_id, $this->teamSnapshot);
            } catch (ValidationException $exception) {
                $keys = ['teamIds' => 'selected_team_ids', 'primaryTeamId' => 'primary_team_id', 'snapshot' => 'teamSnapshot'];
                $errors = [];
                foreach ($exception->errors() as $field => $messages) {
                    $errors[$keys[$field] ?? $field] = $messages;
                }
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        // Ordinary employee editors retain their existing non-team editing
        // rights, but a hidden field cannot be used to bypass team authority.
        $expected = $this->originalTeamState;
        $selected = array_filter($this->selected_team_ids, fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)));
        $actualIds = array_map('intval', $selected);
        $expectedIds = $expected['teamIds'] ?? [];
        sort($actualIds);
        sort($expectedIds);
        if (count($selected) !== count($this->selected_team_ids) || $actualIds !== $expectedIds) {
            throw ValidationException::withMessages(['selected_team_ids' => 'Sie besitzen keine Berechtigung zum Ändern der Teammitgliedschaften.']);
        }
        if (! array_key_exists('primaryTeamId', $expected) || $this->primary_team_id !== $expected['primaryTeamId']) {
            throw ValidationException::withMessages(['primary_team_id' => 'Sie besitzen keine Berechtigung zum Ändern des primären Teams.']);
        }
    }

    public function close(): void
    {
        $this->showModal = false;
    }

    public function render()
    {
        $canManageTeams = app(EmployeeTeamService::class)->canManage();
        $state = ['teams' => Team::where('personal_team', false)->orderBy('name')->get(['id', 'name']), 'fixedTeams' => collect(), 'primaryTeams' => collect()];
        if ($this->showModal && $this->userId && $canManageTeams) {
            $state = app(EmployeeTeamService::class)->editorState($this->userId, $this->selected_team_ids);
        }

        return view('livewire.admin.employees.employee-form-modal', [
            ...$state,
            'canManageTeams' => $canManageTeams,
            'canViewMasterData' => Gate::allows('employees.master-data.view'),
            'canEditMasterData' => $this->canEditMasterData(),
            'canViewCompensation' => Gate::allows('employees.compensation.view'),
            'canEditCompensation' => $this->canEditCompensation(),
        ]);
    }

    /** @param array<int, string> $fields */
    private function nullableValues(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->{$field} === '' ? null : $this->{$field};
        }

        return $values;
    }

    private function resetForm(): void
    {
        $this->reset();
        $this->showModal = false;
        $this->name = '';
        $this->email = '';
    }

    private function canEditMasterData(): bool
    {
        return Gate::allows('employees.master-data.view')
            && Gate::allows('employees.master-data.edit');
    }

    private function canEditCompensation(): bool
    {
        return Gate::allows('employees.compensation.view')
            && Gate::allows('employees.compensation.edit');
    }
}
