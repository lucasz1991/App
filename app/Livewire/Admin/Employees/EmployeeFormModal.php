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
            'password' => [$this->userId ? 'nullable' : 'required', 'min:8', 'confirmed'],
            'primary_team_id' => ['required', 'integer', 'exists:teams,id'],
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
            $user = User::with(['currentTeam', 'profile'])->findOrFail($id);
            if ($user->isAdmin() && ! auth()->user()->isAdmin()) {
                abort(403);
            }

            $this->userId = $user->id;
            $this->name = (string) $user->name;
            $this->email = (string) $user->email;
            $this->primary_team_id = $user->currentTeam?->id;
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

        $isNewEmployee = ! $this->userId;
        $user = $this->userId ? User::findOrFail($this->userId) : new User;
        if ($this->userId && $user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }

        $structuredName = trim(implode(' ', array_filter([$this->first_name, $this->last_name])));
        $user->name = $structuredName !== '' ? $structuredName : $this->name;
        $user->email = $this->email;
        $user->current_team_id = $this->primary_team_id;
        if (! $this->userId) {
            $user->role = 'staff';
        }
        if (! $this->userId || $this->password) {
            $user->password = Hash::make((string) $this->password);
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

        if ($this->primary_team_id && ($team = Team::find($this->primary_team_id))) {
            $user->teams()->sync([$team->id]);
            $user->switchTeam($team);
        }

        if ($this->canEditMasterData() || $this->canEditCompensation()) {
            activity('employee-master-data')
                ->causedBy(auth()->user())
                ->performedOn($user)
                ->withProperties(['target_user_id' => $user->id])
                ->log('employee_master_data_updated');
        }

        if ($isNewEmployee) {
            app(EmployeeWelcomeService::class)->send($user->fresh('currentTeam'));
        }

        $this->dispatch('employeeSaved');
        $this->showModal = false;
        $this->dispatch('swal:toast', type: 'success', title: __('app.saved'), text: __('app.employee_saved'));
    }

    public function close(): void
    {
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.admin.employees.employee-form-modal', [
            'teams' => Team::where('personal_team', false)->orderBy('name')->get(['id', 'name']),
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
