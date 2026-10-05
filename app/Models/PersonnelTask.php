<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelTask extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'completed_at' => 'immutable_datetime'];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
