<?php

namespace App\Support\Ai;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class AssistantAccess
{
    public function allowed(?User $user): bool
    {
        if (! $user instanceof User || ! $user->isActive()) {
            return false;
        }

        // Bewusst vor dem Gate: AuthServiceProvider gewaehrt globalen Admins
        // alle delegierbaren Rechte. Das systemweite AUS muss auch fuer sie
        // gelten.
        if (! AssistantSettings::enabled()) {
            return false;
        }

        return Gate::forUser($user)->allows('assistant.use');
    }

    /**
     * Nichtwerfende Layout-Pruefung. Bei Fehlern wird der globale Assistent
     * ausgeblendet, statt die aufgerufene RailTime-Seite zu blockieren.
     */
    public function shouldRender(
        ?User $user,
        ?string $routeName = null,
        bool $chrome = true,
    ): bool {
        if (! $chrome) {
            return false;
        }

        $routeName ??= request()->route()?->getName();

        if (in_array($routeName, (array) config('assistant.excluded_routes', []), true)) {
            return false;
        }

        try {
            return $this->allowed($user);
        } catch (Throwable) {
            return false;
        }
    }

    public function authorize(?User $user = null): User
    {
        $user ??= auth()->user();

        abort_unless($this->allowed($user), 403);

        return $user;
    }
}
