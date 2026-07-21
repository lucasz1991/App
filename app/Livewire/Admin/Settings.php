<?php

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Support\CompanyData;
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

    /** @var array<string, string> */
    public array $company = [];

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
        $this->company = CompanyData::all(uncached: true);
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

    public function saveCompany(): void
    {
        Gate::authorize('settings.manage');

        $validated = $this->validate([
            'company.name' => ['required', 'string', 'max:160'],
            'company.street' => ['required', 'string', 'max:160'],
            'company.postal_code' => ['required', 'string', 'max:20'],
            'company.city' => ['required', 'string', 'max:120'],
            'company.country' => ['required', 'string', 'max:120'],
            'company.phone' => ['nullable', 'string', 'max:60'],
            'company.emergency_phone' => ['nullable', 'string', 'max:60'],
            'company.email' => ['required', 'email', 'max:160'],
            'company.website' => ['nullable', 'url', 'max:255'],
            'company.managing_directors' => ['nullable', 'string', 'max:255'],
            'company.register_court' => ['nullable', 'string', 'max:160'],
            'company.commercial_register_number' => ['nullable', 'string', 'max:80'],
            'company.vat_id' => ['nullable', 'string', 'max:80'],
            'company.tax_number' => ['nullable', 'string', 'max:80'],
        ]);

        CompanyData::save($validated['company']);

        $this->dispatch('swal:toast', type: 'success', text: __('app.company_data_saved'));
    }

    public function render()
    {
        return view('livewire.admin.settings')
            ->layout('layouts.master', ['area' => 'admin']);
    }
}
