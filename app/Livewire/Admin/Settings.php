<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class Settings extends Component
{
    /** Wartungsmodus: nur Admins koennen die App nutzen, alle anderen sehen die Wartungsseite. */
    public bool $maintenanceMode = false;

    /** Empfaengeradresse fuer Systemnachrichten / Testmails aus der Mailverwaltung. */
    public string $adminEmail = '';

    /** Gueltigkeit neuer Mitarbeiter-Einladungslinks in Tagen. */
    public int $invitationExpiryDays = 7;

    public function mount(): void
    {
        Gate::authorize('settings.manage');
        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        $this->maintenanceMode = (bool) (Setting::getValueUncached('system', 'maintenance_mode') ?? false);
        $this->adminEmail = (string) (Setting::getValueUncached('mails', 'admin_email') ?? '');

        $days = (int) (Setting::getValueUncached('invitations', 'expiry_days') ?? 7);
        $this->invitationExpiryDays = $days > 0 ? $days : 7;
    }

    public function saveSystem(): void
    {
        Gate::authorize('settings.manage');

        Setting::setValue('system', 'maintenance_mode', (bool) $this->maintenanceMode);

        // Der MaintenanceMode-Middleware-Cache liest den Schluessel 'maintenance_mode'
        // 60s lang aus dem Cache — nach dem Umschalten sofort invalidieren.
        Cache::forget('maintenance_mode');

        $this->dispatch('swal:toast', type: 'success', text: __('app.settings_saved'));
    }

    public function saveInvitations(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'invitationExpiryDays' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        Setting::setValue('invitations', 'expiry_days', (int) $this->invitationExpiryDays);

        $this->dispatch('swal:toast', type: 'success', text: __('app.settings_saved'));
    }

    public function saveMails(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'adminEmail' => ['nullable', 'email'],
        ]);

        Setting::setValue('mails', 'admin_email', (string) $this->adminEmail);

        $this->dispatch('swal:toast', type: 'success', text: __('app.settings_saved'));
    }

    public function render()
    {
        return view('livewire.admin.settings')
            ->layout('layouts.master', ['area' => 'admin']);
    }
}
