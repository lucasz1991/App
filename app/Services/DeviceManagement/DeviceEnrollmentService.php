<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceEnrollmentStatus;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceEnrollment;
use App\Models\DeviceProviderLink;
use App\Models\User;
use App\Services\DeviceManagement\Data\EnrollmentClaim;
use App\Services\DeviceManagement\Data\EnrollmentInvitation;
use App\Services\DeviceManagement\Data\EnrollmentResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DeviceEnrollmentService
{
    public function __construct(
        private readonly DeviceProviderRegistry $providers,
        private readonly DeviceManagementSettings $settings,
        private readonly DeviceEnrollmentModeCatalog $modes,
    ) {}

    public function invite(
        Device $device,
        User $assignee,
        string $providerKey,
        string $mode,
        User $creator,
        ?int $ttlMinutes = null,
    ): EnrollmentInvitation {
        Gate::forUser($creator)->authorize('devices.enrollment.manage');
        if (! $assignee->isActive()) {
            throw ValidationException::withMessages(['assignee' => 'Für ein deaktiviertes Mitarbeiterkonto kann keine Registrierung erstellt werden.']);
        }
        $providerKey = strtolower(trim($providerKey));
        try {
            $provider = $this->providers->get($providerKey);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['provider' => 'Der gewählte Geräteprovider ist ungültig.']);
        }
        if (! $provider->enabled() || ! ($provider->capabilities()['enrollment'] ?? false)) {
            throw ValidationException::withMessages(['provider' => 'Der Provider ist nicht für Registrierungen verfügbar.']);
        }

        $platform = $device->platform instanceof \BackedEnum
            ? (string) $device->platform->value
            : (string) $device->platform;
        if (! $provider->supportsPlatform($platform)) {
            throw ValidationException::withMessages(['provider' => 'Der Provider unterstützt die Plattform dieses Geräts nicht.']);
        }
        if (! preg_match('/^[a-z0-9_-]{2,40}$/', $mode)) {
            throw ValidationException::withMessages(['mode' => 'Die Registrierungsart ist ungültig.']);
        }
        $mode = strtolower(trim($mode));
        $this->modes->assertSupported($device, $providerKey, $mode);

        $ttl = min(10080, max(15, $ttlMinutes ?? $this->settings->enrollmentTtlMinutes()));
        $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $enrollment = DB::transaction(function () use ($device, $assignee, $providerKey, $mode, $creator, $ttl, $plainToken): DeviceEnrollment {
            $lockedDevice = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            $currentPrimaryProvider = strtolower(trim((string) $lockedDevice->primary_provider));
            $linkRole = $currentPrimaryProvider === '' || $currentPrimaryProvider === $providerKey
                ? DeviceProviderLink::ROLE_PRIMARY
                : DeviceProviderLink::ROLE_SUPPORT;
            if ($currentPrimaryProvider === '') {
                $lockedDevice->forceFill([
                    'primary_provider' => $providerKey,
                    'updated_by' => $creator->getKey(),
                ])->save();
            }
            $providerLink = $lockedDevice->ensureProviderLink($providerKey, $linkRole);
            if ($providerLink->status === DeviceProviderLink::STATUS_DISABLED) {
                throw ValidationException::withMessages([
                    'provider' => 'Die Provider-Verknüpfung dieses Geräts ist deaktiviert.',
                ]);
            }

            $assignment = DeviceAssignment::query()
                ->where('device_id', $device->getKey())
                ->where('user_id', $assignee->getKey())
                ->active()
                ->lockForUpdate()
                ->first();
            if (! $assignment) {
                throw ValidationException::withMessages([
                    'assignee' => 'Die Einladung muss an die aktuelle aktive Gerätezuweisung gebunden sein.',
                ]);
            }

            DeviceEnrollment::query()
                ->where('device_id', $device->getKey())
                ->where('provider', $providerKey)
                ->whereIn('status', [DeviceEnrollmentStatus::Invited->value, DeviceEnrollmentStatus::Claimed->value])
                ->lockForUpdate()
                ->get()
                ->each(function (DeviceEnrollment $existing): void {
                    $existing->forceFill([
                        'status' => DeviceEnrollmentStatus::Revoked,
                        'revoked_at' => now(),
                    ])->save();
                });

            $enrollment = new DeviceEnrollment([
                'device_id' => $device->getKey(),
                'user_id' => $assignee->getKey(),
                'device_assignment_id' => $assignment->getKey(),
                'provider' => $providerKey,
                'mode' => $mode,
                'status' => DeviceEnrollmentStatus::Invited,
                'invited_at' => now(),
                'expires_at' => now()->addMinutes($ttl),
                'created_by' => $creator->getKey(),
                'metadata' => [
                    'limited_management' => $this->modes->isLimited($device, $providerKey, $mode),
                ],
            ]);
            $enrollment->setPlainToken($plainToken)->save();

            activity('device-management')
                ->performedOn($enrollment)
                ->causedBy($creator)
                ->event('device-enrollment.invited')
                ->withProperties([
                    'enrollment_id' => (string) $enrollment->public_id,
                    'device_id' => (string) $device->public_id,
                    'assignee_id' => (int) $assignee->getKey(),
                    'device_assignment_id' => (int) $assignment->getKey(),
                    'provider' => $providerKey,
                    'provider_link_id' => (int) $providerLink->getKey(),
                    'expires_at' => $enrollment->expires_at?->toIso8601String(),
                ])
                ->log('Geräteregistrierung eingeladen');

            return $enrollment;
        });

        // The only point at which the clear token leaves this service. It is
        // never persisted, logged or included in activity metadata.
        return new EnrollmentInvitation($enrollment, $plainToken);
    }

    public function claim(string $plainToken, User $actor): EnrollmentClaim
    {
        $plainToken = trim($plainToken);
        if ($plainToken === '' || strlen($plainToken) > 128 || ! $actor->isActive()) {
            throw $this->invalidToken();
        }

        $hash = hash('sha256', $plainToken);
        $enrollment = DB::transaction(function () use ($hash, $actor): ?DeviceEnrollment {
            $enrollment = DeviceEnrollment::query()
                ->where('token_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($enrollment
                && $enrollment->status === DeviceEnrollmentStatus::Invited
                && $enrollment->expires_at?->isPast()) {
                $enrollment->forceFill(['status' => DeviceEnrollmentStatus::Expired])->save();

                return null;
            }

            if (! $enrollment
                || $enrollment->status !== DeviceEnrollmentStatus::Invited
                || $enrollment->revoked_at !== null
                || ! $enrollment->expires_at
                || $enrollment->expires_at->isPast()
                || (int) $enrollment->user_id !== (int) $actor->getKey()
                || ! $enrollment->device_assignment_id) {
                return null;
            }

            $assignmentIsCurrent = DeviceAssignment::query()
                ->whereKey($enrollment->device_assignment_id)
                ->where('device_id', $enrollment->device_id)
                ->where('user_id', $actor->getKey())
                ->active()
                ->lockForUpdate()
                ->exists();
            if (! $assignmentIsCurrent) {
                return null;
            }

            // Rotate the stored hash in the same transaction as the state
            // transition. The clear invitation token cannot locate or claim
            // the row a second time, even for the same authenticated user.
            $enrollment->forceFill([
                'status' => DeviceEnrollmentStatus::Claimed,
                'claimed_at' => now(),
                'token_hash' => hash('sha256', random_bytes(32)),
            ])->save();

            activity('device-management')
                ->performedOn($enrollment)
                ->causedBy($actor)
                ->event('device-enrollment.claimed')
                ->withProperties([
                    'enrollment_id' => (string) $enrollment->public_id,
                    'device_id' => (string) $enrollment->device?->public_id,
                    'device_assignment_id' => (int) $enrollment->device_assignment_id,
                    'provider' => (string) $enrollment->provider,
                ])
                ->log('Geräteregistrierung angenommen');

            return $enrollment;
        });

        if (! $enrollment) {
            throw $this->invalidToken();
        }

        $enrollment->loadMissing('device');

        return new EnrollmentClaim($enrollment, $this->instructionsForClaimed($enrollment, $actor));
    }

    /**
     * Resume a successfully claimed enrollment by its non-secret public ID.
     * Callers must keep that ID in the authenticated browser session; the
     * original invitation token intentionally remains unusable.
     */
    public function resumeClaimed(string $publicId, User $actor): EnrollmentClaim
    {
        $enrollment = DeviceEnrollment::query()
            ->where('public_id', $publicId)
            ->first();
        if (! $enrollment) {
            throw $this->invalidToken();
        }

        return new EnrollmentClaim($enrollment, $this->instructionsForClaimed($enrollment, $actor));
    }

    public function instructionsForClaimed(DeviceEnrollment $enrollment, User $actor): EnrollmentResult
    {
        return DB::transaction(function () use ($enrollment, $actor): EnrollmentResult {
            // Device, enrollment and assignment remain locked across the
            // bounded connector request. Reassignment uses the same device
            // lock and therefore cannot revoke this handover between the last
            // authorization check and creation of an external invite.
            $enrollment = DeviceEnrollment::query()
                ->whereKey($enrollment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $device = Device::query()->whereKey($enrollment->device_id)->lockForUpdate()->firstOrFail();
            $assignment = DeviceAssignment::query()
                ->whereKey($enrollment->device_assignment_id)
                ->lockForUpdate()
                ->first();

            if ($enrollment->status !== DeviceEnrollmentStatus::Claimed
                || (int) $enrollment->user_id !== (int) $actor->getKey()
                || ! $assignment
                || (int) $assignment->device_id !== (int) $enrollment->device_id
                || (int) $assignment->user_id !== (int) $actor->getKey()
                || $assignment->status !== DeviceAssignment::STATUS_ACTIVE
                || $assignment->returned_at !== null
                || $enrollment->revoked_at !== null
                || ! $enrollment->expires_at
                || $enrollment->expires_at->isPast()) {
                throw $this->invalidToken();
            }

            $provider = $this->providers->get((string) $enrollment->provider, fresh: true);

            // Retrying never reuses or reveals the clear invitation token.
            // The connector de-duplicates enrollment_id while the device lock
            // keeps the assignment context stable for this bounded request.
            $result = $provider->enrollment($enrollment, $device);
            $metadata = is_array($enrollment->metadata) ? $enrollment->metadata : [];
            $enrollment->forceFill([
                'metadata' => array_merge($metadata, [
                    // A provider may narrow management, never upgrade a mode
                    // that RailTime classifies as limited.
                    'limited_management' => (bool) ($metadata['limited_management'] ?? false)
                        || $result->limitedManagement
                        || $this->modes->isLimited($device, (string) $enrollment->provider, (string) $enrollment->mode),
                ]),
            ])->save();

            return $result;
        }, 3);
    }

    private function invalidToken(): ValidationException
    {
        // Deliberately identical for missing, expired, revoked and foreign
        // tokens to avoid an enrollment-token enumeration oracle.
        return ValidationException::withMessages([
            'token' => 'Dieser Registrierungslink ist ungültig oder nicht mehr verwendbar.',
        ]);
    }
}
