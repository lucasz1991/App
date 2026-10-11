<?php

namespace App\Livewire\Admin;

use App\Support\EmployeeLocation\EmployeeLocationSettings as Settings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class EmployeeLocationSettings extends Component
{
    public array $config = [];

    public function mount(): void
    {
        $this->authorizeSettings();
        $this->config = Settings::read();
    }

    private function authorizeSettings(): void
    {
        Gate::authorize('settings.manage');
        abort_unless(auth()->user()?->isActive() && auth()->user()?->isSuperAdmin(), 403);
    }

    public function save(): void
    {
        $this->authorizeSettings();
        Settings::save($this->config, auth()->user());
        $this->config = Settings::read();
        $this->dispatch('swal:toast', type: 'success', text: 'Mitarbeiter-Standorte gespeichert.');
    }

    public function render()
    {
        $this->authorizeSettings();

        return view('livewire.admin.employee-location-settings', ['ready' => Settings::ready()]);
    }
}
