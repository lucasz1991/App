<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalAutomationProfile extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['allowed_roles' => 'array', 'location_ids' => 'array', 'condition_ids' => 'array', 'auto_reject' => 'boolean', 'valid_from' => 'immutable_date', 'valid_until' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'revision' => 'integer'];
}
