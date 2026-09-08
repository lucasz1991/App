<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceManagementConsent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['scope'];

    protected $casts = ['scope' => 'encrypted:array', 'revision' => 'integer', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
}
