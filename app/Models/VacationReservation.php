<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacationReservation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'snapshot' => 'array'];

    public function absence(): BelongsTo
    {
        return $this->belongsTo(AbsenceRequest::class, 'absence_request_id');
    }
}
