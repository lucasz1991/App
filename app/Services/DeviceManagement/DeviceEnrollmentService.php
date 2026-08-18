<?php

namespace App\Services\DeviceManagement;

use App\Enums\DeviceEnrollmentStatus;
use App\Models\Device;
use App\Models\DeviceEnrollment;
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

        $ttl = min(10080, max(15, $ttlMinutes ?? $this->settings->enrollmentTtlMinutes()));
        $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $enrollment = DB::transaction(function () use ($device, $assignee, $providerKey, $mode, $creator, $ttl, $plainToken): DeviceEnrollment {
            Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

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
                'provider' => $providerKey,
                'mode' => $mode,
                'status' => DeviceEnrollmentStatus::Invited,
                'invited_at' => now(),
                'expires_at' => now()->addMinutes($ttl),
                'created_by' => $creator->getKey(),
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
                    'provider' => $providerKey,
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
                || ! in_array($enrollment->status, [DeviceEnrollmentStatus::Invited, DeviceEnrollmentStatus::Claimed], true)
                || $enrollment->revoked_at !== null
                || ! $enrollment->expires_at
                || $enrollment->expires_at->isPast()
                || (int) $enrollment->user_id !== (int) $actor->getKey()) {
                return null;
            }

            // Nach dem ersten Claim ist der Vorgang fest an diesen
            // authentifizierten Mitarbeiter gebunden. Ein Reload darf die
            // idempotente Connector-Anforderung wiederholen, ohne ein neues
            // Enrollment zu erzeugen oder den Token fuer Dritte zu oeffnen.
            if ($enrollment->status === DeviceEnrollmentStatus::Invited) {
                $enrollment->forceFill([
                    'status' => DeviceEnrollmentStatus::Claimed,
                    'claimed_at' => now(),
                ])->save();

                activity('device-management')
                    ->performedOn($enrollment)
                    ->causedBy($actor)
                    ->event('device-enrollment.claimed')
                    ->withProperties([
                        'enrollment_id' => (string) $enrollment->public_id,
                        'device_id' => (string) $enrollment->device?->public_id,
                        'provider' => (string) $enrollment->provider,
                    ])
                    ->log('Geräteregistrierung angenommen');
            }

            return $enrollment;
        });

        if (! $enrollment) {
            throw $this->invalidToken();
        }

        $enrollment->loadMissing('device');

        return new EnrollmentClaim($enrollment, $this->instructionsForClaimed($enrollment, $actor));
    }

    public function instructionsForClaimed(DeviceEnrollment $enrollment, User $actor): EnrollmentResult
    {
        $enrollment = DeviceEnrollment::query()->with('device')->findOrFail($enrollment->getKey());
        if ($enrollment->status !== DeviceEnrollmentStatus::Claimed
            || (int) $enrollment->user_id !== (int) $actor->getKey()
            || $enrollment->revoked_at !== null
            || ! $enrollment->expires_at
            || $enrollment->expires_at->isPast()) {
            throw $this->invalidToken();
        }

        $provider = $this->providers->get((string) $enrollment->provider);

        // Retrying this authenticated method never reuses or reveals the clear
        // invitation token. The connector must de-duplicate enrollment_id.
        return $provider->enrollment($enrollment, $enrollment->device);
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
