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
use InvalidArgumentException;

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

    public function providerLinks(): HasMany
    {
        return $this->hasMany(DeviceProviderLink::class)->orderBy('role')->orderBy('provider');
    }

    public function providerLinkFor(string $provider): ?DeviceProviderLink
    {
        $provider = $this->normalizedProviderKey($provider);

        return $this->providerLinks()->where('provider', $provider)->first();
    }

    /**
     * Resolve a provider's own identifier. The normalized link is authoritative;
     * the legacy primary columns remain a read fallback during the transition.
     */
    public function providerDeviceIdFor(string $provider): ?string
    {
        $provider = $this->normalizedProviderKey($provider);
        $externalId = trim((string) ($this->providerLinkFor($provider)?->external_device_id ?? ''));
        if ($externalId !== '') {
            return $externalId;
        }

        if (strtolower(trim((string) $this->primary_provider)) === $provider) {
            $legacyId = trim((string) ($this->primary_provider_device_id ?? ''));

            return $legacyId !== '' ? $legacyId : null;
        }

        return null;
    }

    /**
     * Keep the compatibility columns and normalized primary link aligned.
     * This method stores identifiers only; provider credentials never belong
     * to a device/provider link.
     */
    public function syncPrimaryProviderLink(): ?DeviceProviderLink
    {
        $provider = trim((string) $this->primary_provider);
        if ($provider === '') {
            return null;
        }

        return $this->ensureProviderLink(
            $provider,
            DeviceProviderLink::ROLE_PRIMARY,
            filled($this->primary_provider_device_id)
                ? (string) $this->primary_provider_device_id
                : null,
        );
    }

    public function ensureProviderLink(
        string $provider,
        string $role = DeviceProviderLink::ROLE_SUPPORT,
        ?string $externalDeviceId = null,
    ): DeviceProviderLink {
        $provider = $this->normalizedProviderKey($provider);
        if (! in_array($role, [DeviceProviderLink::ROLE_PRIMARY, DeviceProviderLink::ROLE_SUPPORT], true)) {
            throw new InvalidArgumentException('Ungültige Rolle für die Geräteprovider-Verknüpfung.');
        }

        $externalDeviceId = trim((string) $externalDeviceId);
        if ($externalDeviceId !== ''
            && (mb_strlen($externalDeviceId) > 191
                || ! preg_match('/\A[A-Za-z0-9._:@$+=\/-]+\z/', $externalDeviceId))) {
            throw new InvalidArgumentException('Die externe Geräte-ID entspricht nicht dem Connector-Vertrag.');
        }
        $externalDeviceId = $externalDeviceId !== '' ? $externalDeviceId : null;

        if ($role === DeviceProviderLink::ROLE_PRIMARY) {
            $this->providerLinks()
                ->where('provider', '!=', $provider)
                ->where('role', DeviceProviderLink::ROLE_PRIMARY)
                ->update(['role' => DeviceProviderLink::ROLE_SUPPORT]);
        }

        $link = $this->providerLinks()->firstOrNew(['provider' => $provider]);
        $link->role = $role === DeviceProviderLink::ROLE_PRIMARY
            ? DeviceProviderLink::ROLE_PRIMARY
            : ($link->role ?: DeviceProviderLink::ROLE_SUPPORT);
        if ($externalDeviceId !== null) {
            if (filled($link->external_device_id)
                && ! hash_equals((string) $link->external_device_id, $externalDeviceId)) {
                throw new InvalidArgumentException('Die Geräteprovider-Verknüpfung besitzt bereits eine andere externe ID.');
            }
            $link->external_device_id = $externalDeviceId;
        }
        $link->status = $externalDeviceId !== null
            ? DeviceProviderLink::STATUS_ACTIVE
            : ($link->status ?: DeviceProviderLink::STATUS_PENDING);
        $link->save();

        $this->unsetRelation('providerLinks');

        return $link;
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

    private function normalizedProviderKey(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (! preg_match('/^[a-z0-9_-]{2,64}$/', $provider)) {
            throw new InvalidArgumentException('Ungültiger Geräteprovider.');
        }

        return $provider;
    }
}
