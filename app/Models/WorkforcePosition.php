<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkforcePosition extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['qualification_ids' => 'array', 'target_fte' => 'decimal:3', 'full_time_week_minutes' => 'integer', 'revision' => 'integer', 'approved_at' => 'immutable_datetime'];
}
