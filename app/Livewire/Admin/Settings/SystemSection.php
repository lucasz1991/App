<?php

namespace App\Livewire\Admin\Settings;

use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Einstellungen > System > Wartungsmodus. Der Akkordeon-Rahmen bleibt in der Seite. */
class SystemSection extends Component
{
    /** Wartungsmodus: nur Admins koennen die App nutzen, alle anderen sehen die Wartungsseite. */
    public bool $maintenanceMode = false;

    public function mount(): void
    {
        Gate::authorize('settings.manage');

        $this->maintenanceMode = SystemSettings::maintenanceMode();
    }

    public function saveSystem(): void
    {
        Gate::authorize('settings.manage');

        SystemSettings::setMaintenanceMode((bool) $this->maintenanceMode);

        $this->dispatch('system-settings-saved', fields: ['maintenanceMode']);
    }

    public function updatedMaintenanceMode(): void
    {
        $this->saveSystem();
    }

    public function render()
    {
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings.system-section');
    }
}
