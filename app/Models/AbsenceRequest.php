<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbsenceRequest extends Model
{
    protected $attributes = ['revision' => 1, 'status' => 'pending'];

    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'reviewed_at' => 'immutable_datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
