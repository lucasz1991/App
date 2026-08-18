<?php

namespace App\Models;

use App\Enums\AccountProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeIdentityAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'external_id',
        'principal',
        'email',
        'lifecycle_status',
        'provisioning_status',
        'license_status',
        'metadata',
        'last_synced_at',
    ];

    protected $hidden = [
        'metadata',
    ];

    protected $casts = [
        'provider' => AccountProvider::class,
        'metadata' => 'encrypted:array',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deviceAssignments(): HasMany
    {
        return $this->hasMany(DeviceAccountAssignment::class);
    }

    public function scopeForProvider(Builder $query, AccountProvider|string $provider): Builder
    {
        return $query->where(
            'provider',
            $provider instanceof AccountProvider ? $provider->value : $provider,
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('lifecycle_status', 'active');
    }
}
