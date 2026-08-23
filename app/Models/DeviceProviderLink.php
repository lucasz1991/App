<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceProviderLink extends Model
{
    public const ROLE_PRIMARY = 'primary';

    public const ROLE_SUPPORT = 'support';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_STALE = 'stale';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'device_id',
        'provider',
        'external_device_id',
        'role',
        'status',
        'last_seen_at',
        'last_synced_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', strtolower(trim($provider)));
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_DISABLED);
    }
}
