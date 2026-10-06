<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalInvitation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'recipient_email'];

    protected $casts = ['customer_id' => 'integer', 'membership_id' => 'integer', 'setting_revision' => 'integer', 'membership_revision' => 'integer', 'contact_revision' => 'integer', 'identity_revision' => 'integer', 'recipient_email' => 'encrypted', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
}
