<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanVariant extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['entries' => 'array', 'baseline' => 'array', 'from' => 'immutable_date', 'until' => 'immutable_date', 'revision' => 'integer', 'approved_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];
}
