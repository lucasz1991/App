<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommercialOfferRevision extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'revision' => 'integer', 'state_version' => 'integer', 'total_cents' => 'integer', 'valid_until' => 'immutable_date', 'issued_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime'];
}
