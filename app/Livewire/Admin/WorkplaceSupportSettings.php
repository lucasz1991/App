<?php

namespace App\Livewire\Admin;

use App\Models\EmployeeWorkplaceProvision;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\DeviceWorkplaceService;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use App\Services\DeviceManagement\MicrosoftProvisioningSettings;
use App\Services\DeviceManagement\WorkplaceProfileCatalog;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkplaceSupportSettings extends Component
{
    public string $supportEmail = '';

    public string $supportPhone = '';

    public int $retentionDays = 30;

    public string $tenantId = '';

    public string $clientId = '';

    public string $clientSecret = '';

    public bool $writerEnabled = false;

    public bool $tenantApproval = false;

    public ?int $employeeId = null;

    public string $principal = '';

    public string $usageLocation = 'DE';

    public string $profileKey = 'railtime_basic';

    public string $skuId = '';

    public bool $employeeConfirmed = false;

    public bool $executeConfirmed = false;

    public bool $showProvision = false;

    public string $programProfile = 'byod_managed';

    public string $programLines = '';

    #[Locked]
    public string $notice = '';

    #[Locked]
    public bool $secretConfigured = false;

    private function actor(): User
    {
        $actor = auth()->user()?->fresh();
        abort_unless($actor?->isActive() && $actor->isSuperAdmin() && $actor->email_verified_at, 403);
        Gate::forUser($actor)->authorize('settings.manage');

        return $actor;
    }

    public function mount(): void
    {
        $this->actor();
        $settings = (array) Setting::getValueUncached('device_management', 'support');
        $this->supportEmail = $settings['email'] ?? '';
        $this->supportPhone = $settings['phone'] ?? '';
        $this->retentionDays = $settings['retention_days'] ?? 30;
        $this->loadWriter();
        $this->loadPrograms();
    }

    private function loadWriter(): void
    {
        $values = app(MicrosoftProvisioningSettings::class)->configuration();
        $this->tenantId = $values['tenant_id'];
        $this->clientId = $values['client_id'];
        $this->writerEnabled = $values['enabled'];
        $this->secretConfigured = $values['secret'] !== '';
        $this->clientSecret = '';
        $this->tenantApproval = false;
    }

    public function saveSupport(): void
    {
        $this->actor();
        $this->validate(['supportEmail' => ['nullable', 'email:rfc', 'max:191'], 'supportPhone' => ['nullable', 'regex:/^[+0-9 ()\/-]{0,40}$/'], 'retentionDays' => ['required', 'integer', 'between:1,90']]);
        Setting::setValue('device_management', 'support', ['email' => $this->supportEmail, 'phone' => $this->supportPhone, 'retention_days' => $this->retentionDays]);
        $this->notice = 'Öffentlicher IT-Kontakt und Aufbewahrung für neue Diagnosen gespeichert.';
    }

    public function loadPrograms(): void
    {
        $this->actor();
        $programs = WorkplaceProfileCatalog::scope($this->programProfile)['programs'];
        $this->programLines = implode("\n", array_map(fn ($entry) => $entry['package_id'].'@'.$entry['version'], $programs));
    }

    public function savePrograms(): void
    {
        $actor = $this->actor();
        $this->validate(['programLines' => ['string', 'max:6000']]);
        $programs = array_map(function ($line): array {
            $parts = explode('@', trim($line), 2);

            return ['package_id' => $parts[0], 'version' => $parts[1] ?? ''];
        }, array_values(array_filter(preg_split('/\R/', $this->programLines), fn ($line) => trim($line) !== '')));
        app(DeviceWorkplaceService::class)->updatePrograms($this->programProfile, $programs, $actor);
        $this->notice = 'Paketumfang gespeichert. Betroffene Geräte benötigen eine neue Mitarbeiterfreigabe; offene Aufträge wurden gesperrt. Es wurde nichts installiert.';
    }

    public function saveWriter(): void
    {
        $actor = $this->actor();
        try {
            app(MicrosoftProvisioningSettings::class)->save(['tenant_id' => $this->tenantId, 'client_id' => $this->clientId, 'secret' => $this->clientSecret,
                'enabled' => $this->writerEnabled, 'tenant_approval_confirmed' => $this->tenantApproval], $actor);
        } finally {
            $this->clientSecret = '';
        }
        $this->loadWriter();
        $this->notice = 'Getrennter Schreibzugang lokal gespeichert. Keine Microsoft-Konten verändert. Jeder Auftrag benötigt eine eigene Freigabe.';
    }

    public function approve(): void
    {
        $actor = $this->actor();
        $this->validate(['employeeId' => ['required', 'integer']]);
        app(EmployeeWorkplaceProvisioner::class)->approve(User::findOrFail($this->employeeId), ['principal' => $this->principal, 'usage_location' => $this->usageLocation,
            'profile_key' => $this->profileKey, 'sku_id' => $this->skuId ?: null, 'confirmed' => $this->employeeConfirmed], $actor);
        $this->employeeConfirmed = false;
        $this->notice = 'Mitarbeiterauftrag freigegeben – noch keine Microsoft-Änderung und keine Willkommensmail.';
    }

    public function runProvision(int $id): void
    {
        $actor = $this->actor();
        $this->validate(['executeConfirmed' => ['accepted']]);
        app(EmployeeWorkplaceProvisioner::class)->run(EmployeeWorkplaceProvision::findOrFail($id), $actor);
        $this->executeConfirmed = false;
        $this->notice = 'Auftrag geprüft. Konto, Lizenz und Exchange-Dienststatus stehen getrennt in der Liste. Fehler erfordern Prüfung.';
    }

    public function cancelProvision(int $id): void
    {
        app(EmployeeWorkplaceProvisioner::class)->cancel(EmployeeWorkplaceProvision::findOrFail($id), $this->actor());
        $this->notice = 'Lokale Freigabe widerrufen. Eine Microsoft-Anmeldung kann den Mitarbeiter über diesen Auftrag nicht aktivieren. Microsoft-Konto und Lizenz bleiben unverändert.';
    }

    public function render()
    {
        $this->actor();

        return view('livewire.admin.workplace-support-settings', [
            'employees' => User::query()->where('status', false)->whereIn('role', ['staff', 'admin'])->orderBy('name')->limit(200)->get(['id', 'name', 'email']),
            'orders' => EmployeeWorkplaceProvision::query()->with('user:id,name')->latest()->limit(100)->get(),
        ]);
    }
}
