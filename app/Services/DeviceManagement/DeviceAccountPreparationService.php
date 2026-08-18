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
            $catalog = $this->profiles->ensurePersisted($actor);
            $result = [];

            foreach ($normalized as $provider) {
                $principal = $this->principalFor($employee, $provider);
                $identity = EmployeeIdentityAccount::query()->updateOrCreate(
                    [
                        'provider' => $provider->value,
                        'principal' => $principal,
                    ],
                    [
                        'user_id' => $employee->id,
                        'email' => $principal,
                        'lifecycle_status' => 'active',
                        // Der echte Provider muss Existenz und Lizenz belegen.
                        'provisioning_status' => 'pending_provider',
                        'license_status' => 'unknown',
                        'metadata' => [
                            'source' => 'railtime_desired_state',
                        ],
                    ],
                );

                foreach ($catalog as $profile) {
                    if ($profile->provider !== $provider->value
                        || ! in_array($device->platform->value, $profile->platforms, true)) {
                        continue;
                    }

                    $result[] = DeviceAccountAssignment::query()->updateOrCreate(
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
                }
            }

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
