<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class DeviceDesktopJob extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'result', 'result_hash'];

    protected $casts = ['payload' => 'encrypted:array', 'result' => 'encrypted:array',
        'expires_at' => 'datetime', 'offered_at' => 'datetime', 'completed_at' => 'datetime'];
}
