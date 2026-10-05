<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelTrainingParticipant extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'reviewed_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function training(): BelongsTo
    {
        return $this->belongsTo(PersonnelTraining::class, 'personnel_training_id');
    }
}
