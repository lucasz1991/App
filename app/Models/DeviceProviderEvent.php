<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceProviderEvent extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_ACCEPTED = 'accepted';

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payload_hash',
        'status',
        'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}
