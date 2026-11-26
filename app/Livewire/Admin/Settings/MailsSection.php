<?php

namespace App\Livewire\Admin\Settings;

use App\Support\Settings\MailSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Einstellungen > E-Mail > E-Mails (Empfaengeradresse und Outlook-Add-in-Status).
 * Der Akkordeon-Rahmen bleibt in der Seite.
 */
class MailsSection extends Component
{
    /** Empfaengeradresse fuer Systemnachrichten / Testmails aus der Mailverwaltung. */
    public string $adminEmail = '';

    public function mount(): void
    {
        Gate::authorize('settings.manage');

        $this->adminEmail = MailSettings::adminEmail();
    }

    public function saveMails(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'adminEmail' => ['nullable', 'email'],
        ]);

        MailSettings::setAdminEmail((string) $this->adminEmail);

        $this->dispatch('mail-settings-saved', fields: ['adminEmail']);
        $this->skipRender();
    }

    public function render()
    {
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings.mails-section');
    }
}
