<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class OperationsTerminalSession extends Model
{
    use HasUtcInstants;

    protected function expiresAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function revokedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'device_id'];

    protected $casts = ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'sequence' => 'integer'];
}
