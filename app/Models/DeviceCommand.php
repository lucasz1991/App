<?php

namespace App\Models;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommand extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'device_id',
        'device_assignment_id',
        'provider',
        'type',
        'status',
        'is_destructive',
        'justification',
        'payload',
        'result',
        'correlation_id',
        'provider_job_id',
        'requested_by',
        'approved_by',
        'requested_at',
        'approved_at',
        'dispatched_at',
        'completed_at',
        'error',
    ];

    protected $hidden = [
        'payload',
        'result',
    ];

    protected $casts = [
        'type' => DeviceCommandType::class,
        'status' => DeviceCommandStatus::class,
        'is_destructive' => 'boolean',
        'payload' => 'encrypted:array',
        'result' => 'encrypted:array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class, 'device_assignment_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DeviceCommandStatus::PendingApproval->value,
            DeviceCommandStatus::Approved->value,
            DeviceCommandStatus::Queued->value,
            DeviceCommandStatus::Dispatched->value,
            DeviceCommandStatus::Running->value,
        ]);
    }

    public function scopeDestructive(Builder $query): Builder
    {
        return $query->where('is_destructive', true);
    }
}
