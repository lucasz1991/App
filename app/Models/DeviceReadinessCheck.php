<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceReadinessCheck extends Model
{
    protected $fillable = [
        'device_id',
        'check_key',
        'label',
        'status',
        'source',
        'evidence',
        'checked_at',
    ];

    protected $hidden = [
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'encrypted:array',
        'checked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scopePassing(Builder $query): Builder
    {
        return $query->where('status', 'passed');
    }

    public function scopeFailing(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'blocked']);
    }
}
