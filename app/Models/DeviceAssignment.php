<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'device_id',
        'user_id',
        'status',
        'assigned_by',
        'returned_by',
        'assigned_at',
        'returned_at',
        'handover_at',
        'handover_notes',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
        'handover_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->whereNull('returned_at');
    }
}
