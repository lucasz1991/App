<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceDesktopEnrollment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = ['expires_at' => 'datetime', 'claimed_at' => 'datetime', 'revoked_at' => 'datetime'];
}
