<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Shift extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'order_id',
        'title',
        'role_name',
        'starts_at',
        'ends_at',
        'timezone',
        'location_name',
        'required_staff',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'required_staff' => 'integer',
        'status' => ShiftStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $shift): void {
            if (blank($shift->public_id)) {
                $shift->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shift_assignments')
            ->withPivot(['id', 'status', 'assigned_by', 'responded_at', 'note'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeWithStatus(Builder $query, ShiftStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof ShiftStatus ? $status->value : $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now());
    }

    public function scopeDuring(Builder $query, mixed $startsAt, mixed $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', ShiftStatus::Cancelled->value);
    }
}
