<?php

namespace App\Livewire\Devices;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftEmployeeLinkService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class MicrosoftEmployeeLinks extends Component
{
    public bool $showModal = false;

    public string $employee_id = '';

    public string $object_id = '';

    public string $principal = '';

    public string $successMessage = '';

    public function mount(): void
    {
        Gate::authorize('devices.accounts.manage');
    }

    public function openModal(): void
    {
        Gate::authorize('devices.accounts.manage');
        $this->reset('employee_id', 'object_id', 'principal', 'successMessage');
        $this->resetValidation();
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->reset('showModal', 'employee_id', 'object_id', 'principal', 'successMessage');
        $this->resetValidation();
    }

    public function save(MicrosoftEmployeeLinkService $links): void
    {
        Gate::authorize('devices.accounts.manage');
        $this->successMessage = '';
        $this->validate([
            'employee_id' => ['required', 'integer', 'exists:users,id'],
            'object_id' => ['required', 'string'],
            'principal' => ['required', 'string'],
        ], [], [
            'employee_id' => 'Mitarbeiter',
            'object_id' => 'Microsoft-Objekt-ID',
            'principal' => 'Microsoft-Anmeldename',
        ]);

        $links->bind(User::query()->findOrFail($this->employee_id), $this->object_id, $this->principal, auth()->user());
        $this->reset('employee_id', 'object_id', 'principal');
        $this->resetValidation();
        $this->successMessage = 'Microsoft-Konto zugeordnet. Der nächste Geräteabgleich verwendet diese Mitarbeiterbindung.';
    }

    public function render(): View
    {
        Gate::authorize('devices.accounts.manage');
        $tenantId = (string) (app(MicrosoftDeviceSettings::class)->configuration()['tenant_id'] ?? '');

        return view('livewire.devices.microsoft-employee-links', [
            'tenantId' => $tenantId,
            'employees' => $this->showModal
                ? User::query()->where('status', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'accounts' => $this->showModal
                ? EmployeeIdentityAccount::query()
                    ->forProvider(AccountProvider::Microsoft365)
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
                    ->with('user:id,name,status')
                    ->orderBy('principal')
                    ->get(['id', 'user_id', 'tenant_id', 'external_id', 'principal', 'lifecycle_status'])
                : collect(),
        ]);
    }
}
