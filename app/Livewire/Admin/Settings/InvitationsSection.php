<?php

namespace App\Livewire\Admin\Settings;

use App\Support\Settings\InvitationSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Einstellungen > Benutzer > Einladungen. Der Akkordeon-Rahmen bleibt in der Seite. */
class InvitationsSection extends Component
{
    /** Gueltigkeit neuer Mitarbeiter-Einladungslinks in Tagen. */
    public int $invitationExpiryDays = InvitationSettings::DEFAULT_EXPIRY_DAYS;

    public function mount(): void
    {
        Gate::authorize('settings.manage');

        $this->invitationExpiryDays = InvitationSettings::expiryDays(uncached: true);
    }

    public function saveInvitations(): void
    {
        Gate::authorize('settings.manage');

        $this->validate([
            'invitationExpiryDays' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        InvitationSettings::setExpiryDays((int) $this->invitationExpiryDays);

        $this->dispatch('invitation-settings-saved', fields: ['invitationExpiryDays']);
        $this->skipRender();
    }

    public function render()
    {
        Gate::authorize('settings.manage');

        return view('livewire.admin.settings.invitations-section');
    }
}
