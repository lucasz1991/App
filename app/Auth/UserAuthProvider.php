<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;

class UserAuthProvider extends EloquentUserProvider
{
    /**
     * Nur User mit role = guest oder tutor sind authentifizierbar.
     */
    public function retrieveByCredentials(array $credentials)
    {
        // Standardverhalten: User anhand der Credentials laden
        $user = parent::retrieveByCredentials($credentials);
        if (!$user) {
            return null;
        }
        // Admin/Staff komplett aus der Auth ausschließen
        if (!in_array($user->role, ['admin', 'staff'])) {
            return null;
        }
        return $user;
    }
}
