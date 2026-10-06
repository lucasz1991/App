<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload', 'dedup_key'];

    protected $casts = ['customer_id' => 'integer', 'membership_id' => 'integer', 'invitation_id' => 'integer', 'setting_revision' => 'integer', 'membership_revision' => 'integer', 'contact_revision' => 'integer', 'payload' => 'encrypted:array', 'attempts' => 'integer', 'attempted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
}
