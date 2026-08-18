<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceAccountAssignment extends Model
{
    protected $fillable = [
        'device_id',
        'user_id',
        'employee_identity_account_id',
        'device_provisioning_profile_id',
        'desired_state',
        'status',
        'requested_at',
        'configured_at',
        'last_attempted_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'configured_at' => 'datetime',
        'last_attempted_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function identityAccount(): BelongsTo
    {
        return $this->belongsTo(EmployeeIdentityAccount::class, 'employee_identity_account_id');
    }

    public function provisioningProfile(): BelongsTo
    {
        return $this->belongsTo(DeviceProvisioningProfile::class, 'device_provisioning_profile_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'queued', 'configuring']);
    }
}
