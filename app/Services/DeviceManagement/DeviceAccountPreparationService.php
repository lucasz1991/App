<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeviceAccountPreparationService
{
    public function __construct(
        private readonly DeviceProvisioningProfileCatalog $profiles,
        private readonly DeviceReadinessService $readiness,
        private readonly DeviceManagementSettings $settings,
        private readonly DeviceIdentitySyncService $identitySyncs,
    ) {}

    /**
     * Bereitet Soll-Zuordnungen vor. Es werden keine externen Konten angelegt
     * und keine Anmeldedaten gespeichert oder an ein Geraet uebertragen.
     *
     * @param  array<int, AccountProvider|string>  $providers
     * @return array<int, DeviceAccountAssignment>
     *
     * @throws AuthorizationException
     */
    public function prepare(
        Device $device,
        User $employee,
        User $actor,
        array $providers = [AccountProvider::Microsoft365, AccountProvider::GoogleWorkspace],
    ): array {
        Gate::forUser($actor)->authorize('devices.accounts.manage');

        if (! $employee->isActive()) {
            throw ValidationException::withMessages([
                'employee' => 'Konten koennen nur fuer aktive Mitarbeiter vorbereitet werden.',
            ]);
        }

        $activeUserId = $device->activeAssignment()->value('user_id');
        if ((int) $activeUserId !== (int) $employee->id) {
            throw ValidationException::withMessages([
                'employee' => 'Konten koennen nur fuer den aktuell zugewiesenen Mitarbeiter vorbereitet werden.',
            ]);
        }

        $normalized = collect($providers)
            ->map(fn (AccountProvider|string $provider): AccountProvider => $provider instanceof AccountProvider
                ? $provider
                : AccountProvider::from($provider))
            ->unique(fn (AccountProvider $provider): string => $provider->value)
            ->values();

        $assignments = DB::transaction(function () use ($device, $employee, $actor, $normalized): array {
            $lockedDevice = Device::query()->lockForUpdate()->findOrFail($device->getKey());
            $activeUserId = $lockedDevice->activeAssignment()->value('user_id');
            if ((int) $activeUserId !== (int) $employee->id || ! $employee->fresh()?->isActive()) {
                throw ValidationException::withMessages([
                    'employee' => 'Die aktive Mitarbeiterzuweisung hat sich geaendert. Bitte die Ansicht neu laden.',
                ]);
            }

            $catalog = $this->profiles->ensurePersisted($actor);
            $result = [];

            foreach ($normalized as $provider) {
                $principal = $this->principalFor($employee, $provider);
                $identity = EmployeeIdentityAccount::query()
                    ->where('provider', $provider->value)
                    ->where('principal', $principal)
                    ->lockForUpdate()
                    ->first();

                if ($identity && (int) $identity->user_id !== (int) $employee->id) {
                    throw ValidationException::withMessages([
                        'employee' => "Die Identitaet {$principal} ist bereits einem anderen Mitarbeiter zugeordnet.",
                    ]);
                }

                if (! $identity) {
                    $identity = EmployeeIdentityAccount::query()->create([
                        'user_id' => $employee->id,
                        'provider' => $provider->value,
                        'principal' => $principal,
                        'email' => $principal,
                        'lifecycle_status' => 'active',
                        // Der echte Provider muss Existenz und Lizenz belegen.
                        'provisioning_status' => 'pending_provider',
                        'license_status' => 'unknown',
                        'metadata' => [
                            'source' => 'railtime_desired_state',
                        ],
                    ]);
                } else {
                    // A repeated preparation must not erase evidence already
                    // returned by the identity provider (external id, license
                    // and provisioning state). Only the local desired-state
                    // ownership fields are refreshed.
                    $identity->forceFill([
                        'email' => $principal,
                        'lifecycle_status' => 'active',
                    ])->save();
                }

                foreach ($catalog as $profile) {
                    if ($profile->provider !== $provider->value
                        || ! in_array($device->platform->value, $profile->platforms, true)) {
                        continue;
                    }

                    $assignment = DeviceAccountAssignment::query()->firstOrCreate(
                        [
                            'device_id' => $device->id,
                            'employee_identity_account_id' => $identity->id,
                            'device_provisioning_profile_id' => $profile->id,
                        ],
                        [
                            'user_id' => $employee->id,
                            'desired_state' => 'assigned',
                            'status' => 'pending_provider',
                            'requested_at' => now(),
                            'configured_at' => null,
                            'last_attempted_at' => null,
                            'error_code' => null,
                            'error_message' => null,
                        ],
                    );

                    if ((int) $assignment->user_id !== (int) $employee->id) {
                        throw ValidationException::withMessages([
                            'employee' => 'Ein vorhandenes Geraeteprofil gehoert noch zu einer anderen Mitarbeiterzuweisung.',
                        ]);
                    }

                    // The unique row is historical across handovers. If this
                    // employee receives the same device again, explicitly
                    // reactivate a previously revoked desired state instead of
                    // leaving firstOrCreate's old values inert. Current
                    // applied/ready evidence is preserved on ordinary repeats.
                    if ($assignment->desired_state !== 'assigned' || $assignment->status === 'revoked') {
                        $assignment->forceFill([
                            'user_id' => $employee->id,
                            'desired_state' => 'assigned',
                            'status' => 'pending_provider',
                            'requested_at' => now(),
                            'configured_at' => null,
                            'last_attempted_at' => null,
                            'error_code' => null,
                            'error_message' => null,
                        ])->save();
                    }

                    $result[] = $assignment;
                }
            }

            $accountAssignmentIds = collect($result)
                ->map(fn (DeviceAccountAssignment $assignment): int => (int) $assignment->getKey())
                ->unique()
                ->values()
                ->all();

            // The desired-state rows and their durable outbox entry are one
            // business transaction. Only the queue transport is deferred by
            // DeviceIdentitySyncService until the outermost commit succeeds.
            $this->identitySyncs->queuePrepared(
                (int) $lockedDevice->getKey(),
                (int) $employee->getKey(),
                (int) $actor->getKey(),
                $accountAssignmentIds,
            );

            return $result;
        });

        activity('device-management')
            ->causedBy($actor)
            ->performedOn($device)
            ->withProperties([
                'device_public_id' => $device->public_id,
                'employee_id' => $employee->id,
                'providers' => $normalized->map->value->all(),
                'assignment_count' => count($assignments),
            ])
            ->log('device_account_preparation_requested');

        $this->readiness->refresh($device->fresh(), $actor);

        return $assignments;
    }

    private function principalFor(User $employee, AccountProvider $provider): string
    {
        $configuredDomain = trim($this->settings->identityDomain($provider->value));
        if ($configuredDomain === '') {
            return mb_strtolower((string) $employee->email);
        }

        $localPart = str($employee->email)->before('@')->lower()->toString();

        return $localPart.'@'.mb_strtolower($configuredDomain);
    }
}
