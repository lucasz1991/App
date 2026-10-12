<?php

namespace App\Support\Privacy;

use App\Models\User;

class SessionLifetimePolicy
{
    public static function minutes(?User $user): int
    {
        if ($user?->isActive()) {
            if ($user->role === 'staff') {
                return (int) config('session.employee_lifetime', 4320);
            }
            if ($user->isAdmin()) {
                return (int) config('session.administrator_lifetime', 4320);
            }
        }

        return (int) config('session.ordinary_lifetime', config('session.lifetime', 120));
    }
}
