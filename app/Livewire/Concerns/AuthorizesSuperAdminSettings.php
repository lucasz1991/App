<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Einheitlicher Wächter für die Systemeinstellungen, die nur der Super-Admin
 * ändern darf (Einstellungen, Wissensbasis, Gerätedienste, TomTom, Standort,
 * Microsoft-Import, Systemzustand).
 *
 * Verlangt die Fähigkeit settings.manage, die Super-Admin-Rolle und ein
 * aktives Konto. Früher prüfte nur ein Teil der Kopien isActive(); die Route
 * deckt das beim Seitenaufruf über auth.status ab, Livewire-Folgeaufrufe
 * werden jetzt ebenfalls einheitlich abgewiesen.
 */
trait AuthorizesSuperAdminSettings
{
    protected function authorizeSuperAdminSettings(): User
    {
        Gate::authorize('settings.manage');

        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isActive() && $actor->isSuperAdmin(), 403);

        return $actor;
    }
}
