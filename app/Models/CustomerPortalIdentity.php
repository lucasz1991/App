<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class CustomerPortalIdentity extends Authenticatable
{
    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_counter', 'two_factor_pending_secret', 'two_factor_pending_session_hash', 'two_factor_pending_expires_at'];

    protected $casts = ['active' => 'boolean', 'revision' => 'integer', 'password' => 'hashed', 'email_verified_at' => 'immutable_datetime', 'two_factor_secret' => 'encrypted', 'two_factor_confirmed_at' => 'immutable_datetime', 'two_factor_recovery_codes' => 'encrypted:array', 'two_factor_last_counter' => 'integer', 'two_factor_pending_secret' => 'encrypted', 'two_factor_pending_expires_at' => 'immutable_datetime'];

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerPortalMembership::class, 'identity_id');
    }

    public function sendPasswordResetNotification($token): void
    {
        // Do not let the generic employee password broker bypass the portal outbox switch.
        throw new \LogicException('Customer portal password resets require the dedicated invitation outbox.');
    }
}
