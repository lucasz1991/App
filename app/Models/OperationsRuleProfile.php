<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsRuleProfile extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'approved_at' => 'immutable_datetime', 'minimum_rest_minutes' => 'integer', 'maximum_shift_minutes' => 'integer', 'break_after_minutes' => 'integer', 'minimum_break_minutes' => 'integer'];
}
