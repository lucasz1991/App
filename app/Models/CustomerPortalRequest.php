<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CustomerPortalRequest extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'response' => 'encrypted:array', 'revision' => 'integer', 'reviewed_at' => 'immutable_datetime'];

    protected function reviewedAt(): Attribute
    {
        return $this->utcInstant();
    }
}
