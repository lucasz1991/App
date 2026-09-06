<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrosoftDeviceLink extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['owner_ids'];

    protected $casts = [
        'owner_ids' => 'encrypted:array',
        'entra_managed' => 'boolean',
        'entra_compliant' => 'boolean',
        'directory_activity_at' => 'datetime',
        'intune_synced_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function suggestedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_user_id');
    }
}
