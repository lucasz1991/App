<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceWorkplace extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'revoked_at' => 'datetime'];
}
