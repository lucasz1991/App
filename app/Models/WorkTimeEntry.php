<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkTimeEntry extends Model
{
    use CapturesDropboxChanges;

    protected $attributes = ['revision' => 1, 'status' => 'running', 'pause_seconds' => 0];

    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['plan_snapshot' => 'array', 'revision' => 'integer', 'pause_seconds' => 'integer', 'submitted_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];

    protected function pausedAt(): Attribute
    {
        return $this->zonedDateTimeAttribute();
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShiftAssignment::class, 'shift_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkTimeEvent::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(WorkTimeRevision::class);
    }

    public function netSeconds(): int
    {
        $end = $this->ends_at ?? now()->utc();
        $pause = $this->pause_seconds + ($this->paused_at ? max(0, (int) $this->paused_at->diffInSeconds($end)) : 0);

        return max(0, (int) $this->starts_at->diffInSeconds($end) - $pause);
    }
}
