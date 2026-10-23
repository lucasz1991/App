<?php

namespace App\Models;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Concerns\CapturesDropboxChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShiftAssignment extends Model
{
    use CapturesDropboxChanges;
    use HasFactory;

    protected $fillable = [
        'shift_id',
        'user_id',
        'status',
        'assigned_by',
        'responded_at',
        'note',
    ];

    protected $casts = [
        'status' => ShiftAssignmentStatus::class,
        'responded_at' => 'datetime',
        'plan_revision' => 'integer',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timeEntry(): HasOne
    {
        return $this->hasOne(WorkTimeEntry::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ShiftAssignmentStatus::blockingValues());
    }

    public function scopeVisibleInTimeline(Builder $query): Builder
    {
        return $query->where(fn (Builder $visible) => $visible->whereIn('status', ShiftAssignmentStatus::blockingValues())
            ->orWhere(fn (Builder $history) => $history->where('status', ShiftAssignmentStatus::Reported)
                ->whereHas('shift', fn (Builder $shift) => $shift->where('status', ShiftStatus::Completed)->where('ends_at', '<=', now()->utc()))));
    }
}
