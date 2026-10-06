<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsMonitorProfile extends Model
{
    protected $attributes = ['revision' => 1, 'is_active' => false, 'auto_cases' => false];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'auto_cases' => 'boolean', 'revision' => 'integer', 'start_grace_minutes' => 'integer', 'end_grace_minutes' => 'integer'];
}
