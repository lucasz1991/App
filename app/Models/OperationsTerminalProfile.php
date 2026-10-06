<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsTerminalProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['pin_hash', 'latitude', 'longitude'];

    protected $casts = ['location_consent' => 'boolean', 'consented_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
