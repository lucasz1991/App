<?php

namespace App\Models;

use App\Enums\DeviceComplianceStatus;
use App\Enums\DeviceLifecycleStatus;
use App\Enums\DeviceManagementStatus;
use App\Enums\DevicePlatform;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use HasPublicUuid;
    use SoftDeletes;

    protected $fillable = [
        'asset_tag',
        'serial_number',
        'hostname',
        'display_name',
        'form_factor',
        'platform',
        'ownership',
        'lifecycle_status',
        'management_status',
        'compliance_status',
        'primary_provider',
        'primary_provider_device_id',
        'manufacturer',
        'model',
        'os_version',
        'declared_location',
        'location_data',
        'metadata',
        'last_seen_at',
        'last_synced_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'location_data',
        'metadata',
    ];

    protected $casts = [
        'platform' => DevicePlatform::class,
        'lifecycle_status' => DeviceLifecycleStatus::class,
        'management_status' => DeviceManagementStatus::class,
        'compliance_status' => DeviceComplianceStatus::class,
        'location_data' => 'encrypted:array',
        'metadata' => 'encrypted:array',
        'last_seen_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class)->latest('assigned_at');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(DeviceAssignment::class)
            ->where('status', DeviceAssignment::STATUS_ACTIVE)
            ->latest('assigned_at');
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'device_assignments')
            ->wherePivot('status', DeviceAssignment::STATUS_ACTIVE)
            ->withPivot(['status', 'assigned_at', 'returned_at', 'handover_at', 'handover_notes'])
            ->withTimestamps();
    }

    public function accountAssignments(): HasMany
    {
        return $this->hasMany(DeviceAccountAssignment::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(DeviceEnrollment::class)->latest('invited_at');
    }

    public function readinessChecks(): HasMany
    {
        return $this->hasMany(DeviceReadinessCheck::class)->orderBy('label');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(DeviceArtifact::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class)->latest('requested_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeInInventory(Builder $query): Builder
    {
        return $query->where('lifecycle_status', DeviceLifecycleStatus::Inventory->value);
    }

    public function scopeManaged(Builder $query): Builder
    {
        return $query->where('management_status', DeviceManagementStatus::Managed->value);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('lifecycle_status', '!=', DeviceLifecycleStatus::Retired->value);
    }

    public function scopeForPlatform(Builder $query, DevicePlatform|string $platform): Builder
    {
        return $query->where(
            'platform',
            $platform instanceof DevicePlatform ? $platform->value : $platform,
        );
    }
}
