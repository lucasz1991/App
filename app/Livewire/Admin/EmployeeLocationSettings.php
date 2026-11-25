<?php

namespace App\Livewire\Admin;

use App\Livewire\Concerns\AuthorizesSuperAdminSettings;
use App\Models\MobileLocationInstallation;
use App\Support\EmployeeLocation\EmployeeLocationSettings as Settings;
use Livewire\Component;

class EmployeeLocationSettings extends Component
{
    use AuthorizesSuperAdminSettings;

    public array $config = [];

    public function mount(): void
    {
        $this->authorizeSuperAdminSettings();
        $this->config = Settings::read();
    }

    public function save(): void
    {
        $this->authorizeSuperAdminSettings();
        Settings::save($this->config, auth()->user());
        $this->config = Settings::read();
        $this->dispatch('swal:toast', type: 'success', text: 'Mitarbeiter-Standorte gespeichert.');
    }

    public function render()
    {
        $this->authorizeSuperAdminSettings();

        $ready = Settings::ready();
        $runtime = $ready ? MobileLocationInstallation::whereNull('revoked_at')
            ->selectRaw('COUNT(*) AS installations, SUM(CASE WHEN session_id IS NOT NULL AND permit_expires_at > ? THEN 1 ELSE 0 END) AS permits', [now()->utc()])->first() : null;

        return view('livewire.admin.employee-location-settings', [
            'ready' => $ready,
            'runtime' => ['enabled' => Settings::read()['enabled'], 'installations' => (int) ($runtime?->installations ?? 0), 'permits' => (int) ($runtime?->permits ?? 0)],
        ]);
    }
}
