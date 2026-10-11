<?php

namespace App\Support\Privacy;

use App\Models\User;

class SessionLifetimePolicy
{
    public static function minutes(?User $user): int
    {
        return $user?->role === 'staff' && $user->isActive()
            ? (int) config('session.employee_lifetime', 4320)
            : (int) config('session.ordinary_lifetime', config('session.lifetime', 120));
    }
}
