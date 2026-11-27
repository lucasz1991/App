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
use Illuminate\Database\Query\Expression;

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

    /** Old shift bookings, actual duty starts and assignments confirmed on a day slip. */
    public function scopeWithoutRecordedTime(Builder $query): Builder
    {
        $assignmentId = match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => 'cast(shift_assignments.id as char)',
            'pgsql' => 'to_jsonb(shift_assignments.id)',
            default => 'shift_assignments.id',
        };

        return $query->whereDoesntHave('timeEntry')->whereNotExists(function ($events) use ($assignmentId) {
            $events->selectRaw('1')->from('work_time_events')
                ->where('work_time_events.kind', 'duty_start')
                ->whereColumn('work_time_events.actor_id', 'shift_assignments.user_id')
                ->whereJsonContains('work_time_events.data->assignment_id', new Expression($assignmentId));
        })->whereNotExists(function ($entries) use ($assignmentId) {
            $entries->selectRaw('1')->from('work_time_entries')
                ->whereColumn('work_time_entries.user_id', 'shift_assignments.user_id')
                ->whereJsonContains('work_time_entries.plan_snapshot->day_review->assignment_ids', new Expression($assignmentId));
        });
    }

    public function scopeWithRecordedTime(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $assignment) => $assignment->withoutRecordedTime());
    }

    public function hasRecordedTime(): bool
    {
        return self::whereKey($this->getKey())->withRecordedTime()->exists();
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
