<?php

namespace App\Livewire\Admin;

use App\Actions\Jetstream\DeleteUser;
use App\Models\DeviceAssignment;
use App\Models\User;
use App\Models\UserProfile as ProfileModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Component;

class UserProfile extends Component
{
    private const USER_FIELDS = ['name', 'email'];

    private const PROFILE_FIELDS = [
        'first_name',
        'last_name',
        'phone',
        'mobile',
        'street',
        'postal_code',
        'city',
        'country',
        'birth_date',
        'birth_place',
        'birth_name',
        'nationality',
        'education',
        'position',
        'personnel_nr',
        'entry_date',
        'multiple_employment',
        'employment_type',
        'weekly_working_hours',
        'additional_information',
        'tax_identification_number',
        'social_security_number',
        'iban',
        'health_insurance',
        'tax_class',
        'children_count',
        'religion',
        'compensation_type',
        'compensation_amount',
    ];

    private const MASTER_DATA_FIELDS = [
        'first_name',
        'last_name',
        'birth_place',
        'birth_name',
        'nationality',
        'education',
        'position',
        'personnel_nr',
        'entry_date',
        'multiple_employment',
        'employment_type',
        'weekly_working_hours',
        'additional_information',
    ];

    private const COMPENSATION_FIELDS = [
        'tax_identification_number',
        'social_security_number',
        'iban',
        'health_insurance',
        'tax_class',
        'children_count',
        'religion',
        'compensation_type',
        'compensation_amount',
    ];

    public $userId;

    public $user;

    /** @var array<string, mixed> */
    public array $inlineValues = [];

    /** @var array<int, string> */
    public array $dirtyInlineFields = [];

    public function mount($userId)
    {
        // Die reduzierte Personenvorschau steht allen aktiven Kolleginnen und
        // Kollegen offen. Vollstaendige Personalprofile bleiben dagegen
        // ausschliesslich Administratoren und der Verwaltung vorbehalten.
        abort_unless(auth()->user()?->canViewManagementDashboard(), 403);
        Gate::authorize('employees.view');

        $this->userId = $userId;
        $this->loadUser();

        if (Gate::allows('employees.master-data.view') || Gate::allows('employees.compensation.view')) {
            activity('employee-master-data')
                ->causedBy(auth()->user())
                ->performedOn($this->user)
                ->withProperties(['target_user_id' => $this->user->id])
                ->log('employee_master_data_viewed');
        }
    }

    public function loadUser(): void
    {
        $this->user = User::findOrFail($this->userId);
        $this->syncInlineValues();
        $this->dirtyInlineFields = [];
    }

    public function updatedInlineValues(mixed $value, string $field): void
    {
        abort_unless(in_array($field, array_merge(self::USER_FIELDS, self::PROFILE_FIELDS), true), 422);

        if (! in_array($field, $this->dirtyInlineFields, true)) {
            $this->dirtyInlineFields[] = $field;
        }
    }

    /**
     * Admin-Konten duerfen nur von Admins verwaltet werden
     * (gleiche Regel wie in Admin\Employees).
     */
    protected function guardAdminAccount(User $user): void
    {
        if ($user->isAdmin() && ! auth()->user()->isAdmin()) {
            abort(403);
        }
    }

    public function activateUser(): void
    {
        Gate::authorize('users.edit');

        $this->loadUser();
        $this->guardAdminAccount($this->user);

        if ($this->user && ! $this->user->status) {
            $this->user->update(['status' => true]);
            $this->dispatch('swal:toast', type: 'success', text: __('app.user_activated'));
        } else {
            $this->dispatch('swal:toast', type: 'info', text: __('app.user_already_active'));
        }

        $this->loadUser();
    }

    public function deactivateUser(): void
    {
        Gate::authorize('users.edit');

        $this->loadUser();
        $this->guardAdminAccount($this->user);

        if ($this->user && $this->user->status) {
            $this->user->update(['status' => false]);
            $this->dispatch('swal:toast', type: 'success', text: __('app.user_deactivated'));
        } else {
            $this->dispatch('swal:toast', type: 'info', text: __('app.user_already_inactive'));
        }

        $this->loadUser();
    }

    public function deleteUser()
    {
        Gate::authorize('employees.delete');

        $user = User::find($this->userId);

        if (! $user) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.user_not_found'));

            return $this->redirectRoute($this->employeesRoute(), navigate: true);
        }

        $this->guardAdminAccount($user);

        if ($user->id === auth()->id()) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.cannot_delete_self'));

            return;
        }

        if ($user->isSuperAdmin()) {
            $this->dispatch('swal:toast', type: 'error', text: __('app.cannot_delete_super_admin'));

            return;
        }

        try {
            app(DeleteUser::class)->delete($user);
        } catch (\Throwable $e) {
            Log::error('Benutzer konnte nicht geloescht werden.', [
                'user_id' => $user->id,
                'deleted_by' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('swal:toast', type: 'error', text: __('app.user_delete_failed'));

            return;
        }

        $this->dispatch('swal:toast', type: 'success', text: __('app.user_deleted'));

        return $this->redirectRoute($this->employeesRoute(), navigate: true);
    }

    public function saveInlineField(string $field): bool
    {
        abort_unless(in_array($field, array_merge(self::USER_FIELDS, self::PROFILE_FIELDS), true), 422);

        $user = User::findOrFail($this->userId);
        $profile = ProfileModel::firstWhere('user_id', $user->id);
        $this->guardAdminAccount($user);
        $this->authorizeInlineField($field);

        $property = 'inlineValues.'.$field;
        $this->validateOnly($property, [$property => $this->inlineRule($field)]);

        $value = $this->normalizeInlineValue($field, $this->inlineValues[$field] ?? null);
        $persistedValues = $this->inlineValueMap($user, $profile);

        abort_unless(array_key_exists($field, $persistedValues), 403);

        if ($this->inlineValuesAreEquivalent($field, $value, $persistedValues[$field])) {
            $this->user = $user;
            $this->inlineValues[$field] = $persistedValues[$field];
            $this->forgetDirtyInlineFields([$field]);
            $this->resetValidation($property);

            return true;
        }

        if (in_array($field, self::USER_FIELDS, true)) {
            if ($field === 'email' && $value !== $user->email) {
                $user->email_verified_at = null;
            }

            $user->{$field} = $value;
            $user->save();
        } else {
            $profile ??= ProfileModel::firstOrCreate(['user_id' => $user->id]);
            $profile->update([$field => $value]);
        }

        activity('employee-master-data')
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties([
                'target_user_id' => $user->id,
                'field' => $field,
            ])
            ->log('employee_profile_inline_updated');

        $this->user = $user->fresh();
        $refreshedValues = $this->inlineValueMap(
            $this->user,
            ProfileModel::firstWhere('user_id', $this->user->id),
        );
        $this->inlineValues[$field] = $refreshedValues[$field];
        $this->forgetDirtyInlineFields([$field]);
        $this->resetValidation($property);
        $this->dispatch(
            'employee-profile-field-saved',
            field: $field,
            savedFields: [$field],
        );

        if ((int) $user->id === (int) auth()->id() && in_array($field, self::USER_FIELDS, true)) {
            $this->dispatch('refresh-navigation-menu');
        }

        return true;
    }

    public function savePendingInlineChanges(): bool
    {
        $user = User::findOrFail($this->userId);
        $profile = ProfileModel::firstWhere('user_id', $user->id);
        $this->guardAdminAccount($user);

        $persistedValues = $this->inlineValueMap($user, $profile);
        $requestedFields = array_values(array_unique($this->dirtyInlineFields));

        abort_if(array_diff($requestedFields, array_keys($persistedValues)) !== [], 422);
        abort_if(array_diff($requestedFields, array_keys($this->inlineValues)) !== [], 422);

        $changedFields = [];

        foreach ($requestedFields as $field) {
            $persistedValue = $persistedValues[$field];

            if (! $this->inlineValuesAreEquivalent($field, $this->inlineValues[$field], $persistedValue)) {
                $changedFields[] = $field;
            }
        }

        if ($changedFields === []) {
            $this->user = $user;
            $this->syncInlineValues();
            $this->forgetDirtyInlineFields($requestedFields);
            $this->resetValidation();
            $this->dispatch('employee-profile-field-saved', savedFields: $requestedFields);

            return true;
        }

        $rules = [];

        foreach ($changedFields as $field) {
            $this->authorizeInlineField($field);
            $rules['inlineValues.'.$field] = $this->inlineRule($field);
        }

        $this->validate($rules);

        $userUpdates = [];
        $profileUpdates = [];

        foreach ($changedFields as $field) {
            $value = $this->normalizeInlineValue($field, $this->inlineValues[$field] ?? null);

            if (in_array($field, self::USER_FIELDS, true)) {
                $userUpdates[$field] = $value;
            } else {
                $profileUpdates[$field] = $value;
            }
        }

        DB::transaction(function () use ($user, $userUpdates, $profileUpdates): void {
            if ($userUpdates !== []) {
                if (array_key_exists('email', $userUpdates) && $userUpdates['email'] !== $user->email) {
                    $userUpdates['email_verified_at'] = null;
                }

                $user->forceFill($userUpdates)->save();
            }

            if ($profileUpdates !== []) {
                $profile = ProfileModel::firstOrCreate(['user_id' => $user->id]);
                $profile->update($profileUpdates);
            }
        });

        activity('employee-master-data')
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties([
                'target_user_id' => $user->id,
                'fields' => $changedFields,
            ])
            ->log('employee_profile_inline_updated');

        $this->user = $user->fresh();
        $this->syncInlineValues();
        $this->forgetDirtyInlineFields($requestedFields);
        $this->resetValidation();
        $this->dispatch(
            'employee-profile-field-saved',
            fields: $changedFields,
            savedFields: $requestedFields,
        );

        if ((int) $user->id === (int) auth()->id()
            && array_intersect($changedFields, self::USER_FIELDS) !== []) {
            $this->dispatch('refresh-navigation-menu');
        }

        return true;
    }

    public function cancelInlineField(string $field): void
    {
        abort_unless(in_array($field, array_merge(self::USER_FIELDS, self::PROFILE_FIELDS), true), 422);
        $this->authorizeInlineField($field);

        $this->user = User::findOrFail($this->userId);
        $this->guardAdminAccount($this->user);
        $persistedValues = $this->inlineValueMap(
            $this->user,
            ProfileModel::firstWhere('user_id', $this->user->id),
        );
        $this->inlineValues[$field] = $persistedValues[$field];
        $this->forgetDirtyInlineFields([$field]);
        $this->resetValidation('inlineValues.'.$field);
    }

    public function render()
    {
        $profile = ProfileModel::firstWhere('user_id', $this->userId);
        $deviceAssignments = Gate::allows('devices.view')
            ? DeviceAssignment::query()
                ->where('user_id', $this->userId)
                ->with(['device.readinessChecks'])
                ->latest('assigned_at')
                ->get()
            : collect();

        return view('livewire.admin.user-profile', [
            'user' => $this->user,
            'profile' => $profile,
            'deviceAssignments' => $deviceAssignments,
            'canEditEmployee' => Gate::allows('employees.create'),
            'canViewMasterData' => Gate::allows('employees.master-data.view'),
            'canEditMasterData' => Gate::allows('employees.master-data.view')
                && Gate::allows('employees.master-data.edit'),
            'canViewCompensation' => Gate::allows('employees.compensation.view'),
            'canEditCompensation' => Gate::allows('employees.compensation.view')
                && Gate::allows('employees.compensation.edit'),
        ])->layout('layouts.master', ['area' => auth()->user()->usesAdminLayout() ? 'admin' : 'user']);
    }

    private function authorizeInlineField(string $field): void
    {
        if (in_array($field, self::COMPENSATION_FIELDS, true)) {
            Gate::authorize('employees.compensation.view');
            Gate::authorize('employees.compensation.edit');

            return;
        }

        if (in_array($field, self::MASTER_DATA_FIELDS, true)) {
            Gate::authorize('employees.master-data.view');
            Gate::authorize('employees.master-data.edit');

            return;
        }

        Gate::authorize('employees.create');
    }

    private function inlineRule(string $field): array
    {
        return match ($field) {
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'first_name', 'last_name', 'birth_place', 'birth_name', 'nationality', 'position' => ['nullable', 'string', 'max:120'],
            'phone', 'mobile', 'tax_identification_number', 'social_security_number', 'iban' => ['nullable', 'string', 'max:50'],
            'street', 'city', 'country', 'education' => ['nullable', 'string', 'max:255'],
            'postal_code', 'tax_class' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'entry_date' => ['nullable', 'date'],
            'personnel_nr' => ['nullable', 'string', 'max:100'],
            'multiple_employment' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'employment_type' => ['nullable', Rule::in(['employee', 'worker'])],
            'weekly_working_hours' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'additional_information' => ['nullable', 'string', 'max:4000'],
            'health_insurance' => ['nullable', 'string', 'max:160'],
            'children_count' => ['nullable', 'integer', 'min:0', 'max:30'],
            'religion' => ['nullable', 'string', 'max:80'],
            'compensation_type' => ['nullable', Rule::in(['salary', 'fixed_salary', 'hourly_wage'])],
            'compensation_amount' => ['nullable', 'numeric', 'min:0'],
            default => abort(422),
        };
    }

    private function normalizeInlineValue(string $field, mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '') {
            return null;
        }

        if ($field === 'multiple_employment') {
            return (bool) ((int) $value);
        }

        return $value;
    }

    private function inlineValuesAreEquivalent(string $field, mixed $candidate, mixed $persisted): bool
    {
        $candidate = $this->normalizeInlineValue($field, $candidate);
        $persisted = $this->normalizeInlineValue($field, $persisted);

        if ($candidate === null || $persisted === null) {
            return $candidate === $persisted;
        }

        if (in_array($field, ['weekly_working_hours', 'children_count', 'compensation_amount'], true)) {
            return (float) $candidate === (float) $persisted;
        }

        if ($field === 'multiple_employment') {
            return (bool) $candidate === (bool) $persisted;
        }

        return (string) $candidate === (string) $persisted;
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function forgetDirtyInlineFields(array $fields): void
    {
        $this->dirtyInlineFields = array_values(array_diff($this->dirtyInlineFields, $fields));
    }

    private function syncInlineValues(): void
    {
        $profile = ProfileModel::firstWhere('user_id', $this->user?->id);

        $this->inlineValues = $this->inlineValueMap($this->user, $profile);
    }

    /**
     * Only expose fields the current user is allowed to view. Livewire public
     * state must never contain encrypted master or compensation data otherwise.
     *
     * @return array<string, mixed>
     */
    private function inlineValueMap(User $user, ?ProfileModel $profile): array
    {
        $values = [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];

        foreach (self::PROFILE_FIELDS as $field) {
            if (in_array($field, self::MASTER_DATA_FIELDS, true)
                && ! Gate::allows('employees.master-data.view')) {
                continue;
            }

            if (in_array($field, self::COMPENSATION_FIELDS, true)
                && ! Gate::allows('employees.compensation.view')) {
                continue;
            }

            $value = $profile?->{$field};

            if (in_array($field, ['birth_date', 'entry_date'], true)) {
                $value = $value?->format('Y-m-d');
            } elseif ($field === 'multiple_employment') {
                $value = is_null($value) ? '' : ($value ? '1' : '0');
            }

            $values[$field] = $value ?? '';
        }

        return $values;
    }

    private function employeesRoute(): string
    {
        return auth()->user()->usesAdminLayout() ? 'admin.employees' : 'employees.index';
    }
}
