<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RotationCycle extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['slots' => 'array', 'exceptions' => 'array', 'revision' => 'integer', 'cycle_days' => 'integer'];
}
