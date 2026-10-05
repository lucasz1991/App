<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkModel extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['daily_minutes' => 'array', 'work_windows' => 'array', 'valuation_rules' => 'array', 'weekly_target_minutes' => 'integer', 'maximum_weekly_minutes' => 'integer', 'revision' => 'integer', 'approved_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
