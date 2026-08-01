<?php

namespace App\Models;

use App\Enums\ShiftAssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
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
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', ShiftAssignmentStatus::blockingValues());
    }
}
