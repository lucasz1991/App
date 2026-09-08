<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class SupportRemoteSession extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $hidden = ['provider_reference'];

    protected $casts = ['file_transfer' => 'boolean', 'provider_reference' => 'encrypted:array', 'expires_at' => 'datetime', 'consented_at' => 'datetime', 'ended_at' => 'datetime'];
}
