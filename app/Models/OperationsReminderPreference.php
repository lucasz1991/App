<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsReminderPreference extends Model
{
    protected $attributes = ['revision' => 1, 'is_active' => false];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'revision' => 'integer', 'lead_minutes' => 'integer'];
}
