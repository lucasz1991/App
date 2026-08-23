<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeviceIdentitySync extends Model
{
    use HasPublicUuid;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'device_id',
        'device_assignment_id',
        'user_id',
        'operation',
        'status',
        'deduplication_key',
        'correlation_id',
        'provider_job_id',
        'account_assignment_ids',
        'profile_assignment_ids',
        'attempts',
        'requested_at',
        'last_enqueued_at',
        'last_attempted_at',
        'dispatched_at',
        'completed_at',
        'result',
        'error_code',
        'error_message',
        'requested_by',
    ];

    protected $hidden = [
        'result',
    ];

    protected $casts = [
        'account_assignment_ids' => 'array',
        'profile_assignment_ids' => 'array',
        'attempts' => 'integer',
        'requested_at' => 'datetime',
        'last_enqueued_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'result' => 'encrypted:array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeviceAssignment::class, 'device_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeRetryable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_BLOCKED, self::STATUS_FAILED]);
    }
}
