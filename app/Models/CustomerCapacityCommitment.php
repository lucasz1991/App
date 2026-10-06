<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;

class CustomerCapacityCommitment extends Model
{
    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['qualification_ids' => 'array', 'consented_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'revision' => 'integer', 'planned_break_minutes' => 'integer'];
}
