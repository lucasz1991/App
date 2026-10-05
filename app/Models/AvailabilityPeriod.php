<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilityPeriod extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['from' => 'immutable_date', 'until' => 'immutable_date', 'due_at' => 'immutable_datetime', 'revision' => 'integer'];
}
