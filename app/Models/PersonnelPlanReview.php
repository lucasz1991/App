<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonnelPlanReview extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['plan_revision' => 'integer', 'origin_revision' => 'integer', 'revision' => 'integer', 'origin_snapshot' => 'array', 'initial_snapshot' => 'array', 'latest_snapshot' => 'array', 'reviewed_at' => 'immutable_datetime'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    protected function reviewedAt(): Attribute
    {
        return $this->utcInstant();
    }
}
