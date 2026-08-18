<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceProvisioningProfile extends Model
{
    use HasPublicUuid;

    protected $fillable = [
        'provider',
        'type',
        'name',
        'version',
        'platforms',
        'configuration',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'configuration',
    ];

    protected $casts = [
        'version' => 'integer',
        'platforms' => 'array',
        'configuration' => 'encrypted:array',
        'is_active' => 'boolean',
    ];

    public function accountAssignments(): HasMany
    {
        return $this->hasMany(DeviceAccountAssignment::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DeviceArtifact::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->whereJsonContains('platforms', $platform);
    }
}
