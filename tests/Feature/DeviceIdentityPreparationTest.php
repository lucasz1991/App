<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Models\Device;
use App\Models\DeviceReadinessCheck;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\DeviceAccountPreparationService;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProvisioningProfileCatalog;
use App\Services\DeviceManagement\DeviceReadinessService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceIdentityPreparationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_principal_already_owned_by_another_employee_fails_closed(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $firstEmployee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'same-name@first.example',
        ]);
        $secondEmployee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'same-name@second.example',
        ]);

        app(DeviceManagementSettings::class)->saveIdentityDomains([
            'microsoft_365' => 'rail-time.example',
        ]);

        $firstDevice = $this->assignedDevice($admin, $firstEmployee, 'RT-ID-001');
        app(DeviceAccountPreparationService::class)->prepare(
            $firstDevice,
            $firstEmployee,
            $admin,
            [AccountProvider::Microsoft365],
        );

        $secondDevice = $this->assignedDevice($admin, $secondEmployee, 'RT-ID-002');

        try {
            app(DeviceAccountPreparationService::class)->prepare(
                $secondDevice,
                $secondEmployee,
                $admin,
                [AccountProvider::Microsoft365],
            );
            $this->fail('An existing provider principal must never be reassigned silently.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('employee', $exception->errors());
        }

        $this->assertDatabaseHas('employee_identity_accounts', [
            'provider' => AccountProvider::Microsoft365->value,
            'principal' => 'same-name@rail-time.example',
            'user_id' => $firstEmployee->id,
        ]);
        $this->assertDatabaseMissing('employee_identity_accounts', [
            'principal' => 'same-name@rail-time.example',
            'user_id' => $secondEmployee->id,
        ]);
    }

    public function test_repeated_preparation_preserves_provider_evidence(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'employee@rail-time.de',
        ]);
        $device = $this->assignedDevice($admin, $employee, 'RT-ID-003');
        $service = app(DeviceAccountPreparationService::class);

        $service->prepare($device, $employee, $admin, [AccountProvider::Microsoft365]);
        $identity = EmployeeIdentityAccount::query()->firstOrFail();
        $identity->forceFill([
            'external_id' => 'entra-object-123',
            'provisioning_status' => 'ready',
            'license_status' => 'active',
        ])->save();
        $assignment = $identity->deviceAssignments()->firstOrFail();
        $assignment->forceFill(['status' => 'ready'])->save();

        $service->prepare($device->fresh(), $employee, $admin, [AccountProvider::Microsoft365]);

        $this->assertDatabaseHas('employee_identity_accounts', [
            'id' => $identity->id,
            'user_id' => $employee->id,
            'external_id' => 'entra-object-123',
            'provisioning_status' => 'ready',
            'license_status' => 'active',
        ]);
        $this->assertDatabaseHas('device_account_assignments', [
            'id' => $assignment->id,
            'user_id' => $employee->id,
            'status' => 'ready',
        ]);
    }

    public function test_inactive_employee_is_rejected_even_if_an_assignment_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'inactive@rail-time.de',
        ]);
        $device = $this->assignedDevice($admin, $employee, 'RT-ID-004');
        $employee->forceFill(['status' => false])->save();

        $this->expectException(ValidationException::class);

        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee->fresh(),
            $admin,
            [AccountProvider::Microsoft365],
        );
    }

    public function test_profile_catalog_v2_contains_start_ready_identity_app_network_and_certificate_targets_without_secrets(): void
    {
        $definitions = app(DeviceProvisioningProfileCatalog::class)->definitions();

        $this->assertSame(2, DeviceProvisioningProfileCatalog::VERSION);
        $this->assertArrayHasKey('microsoft-entra-device-registration', $definitions);
        $this->assertArrayHasKey('microsoft-managed-network', $definitions);
        $this->assertArrayHasKey('google-managed-app-baseline', $definitions);
        $this->assertArrayHasKey('apple-business-baseline', $definitions);
        $this->assertTrue($definitions['microsoft-managed-network']['configuration']['required']);
        $this->assertSame(
            'railtime-device-certificate',
            $definitions['microsoft-managed-network']['configuration']['scep_profile_reference'],
        );

        $serialized = mb_strtolower(json_encode($definitions, JSON_THROW_ON_ERROR));
        foreach (['password', 'refresh_token', 'private_key', 'recovery_code'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_profile_readiness_requires_every_current_required_v2_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'profiles@rail-time.de',
        ]);
        $device = $this->assignedDevice($admin, $employee, 'RT-ID-005');

        $assignments = app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $this->assertGreaterThan(1, count($assignments));

        $assignments[0]->forceFill(['status' => 'applied'])->save();
        app(DeviceReadinessService::class)->refresh($device->fresh(), $admin);
        $this->assertSame(
            'pending',
            DeviceReadinessCheck::query()
                ->where('device_id', $device->id)
                ->where('check_key', 'profiles')
                ->value('status'),
        );

        $device->accountAssignments()
            ->where('user_id', $employee->id)
            ->where('desired_state', 'assigned')
            ->update(['status' => 'applied']);
        app(DeviceReadinessService::class)->refresh($device->fresh(), $admin);
        $this->assertSame(
            'passed',
            DeviceReadinessCheck::query()
                ->where('device_id', $device->id)
                ->where('check_key', 'profiles')
                ->value('status'),
        );
    }

    public function test_fresh_signed_compliance_evidence_survives_refresh_but_expires_and_sync_is_rechecked(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->assignedDevice($admin, $employee, 'RT-ID-006');
        $maxAge = app(DeviceManagementSettings::class)->maxSyncAgeHours();

        DeviceReadinessCheck::query()->updateOrCreate(
            ['device_id' => $device->id, 'check_key' => 'compliance'],
            [
                'label' => DeviceReadinessService::REQUIRED_CHECKS['compliance'],
                'status' => 'passed',
                'source' => 'simulation',
                'evidence' => ['receipt' => 'signed-provider-event'],
                'checked_at' => now(),
            ],
        );
        $device->forceFill(['last_synced_at' => now()])->save();

        app(DeviceReadinessService::class)->refresh($device->fresh());
        $freshCompliance = DeviceReadinessCheck::query()
            ->where('device_id', $device->id)
            ->where('check_key', 'compliance')
            ->firstOrFail();
        $this->assertSame('passed', $freshCompliance->status);
        $this->assertSame('simulation', $freshCompliance->source);

        $this->travel($maxAge + 1)->hours();
        $this->assertFalse(app(DeviceReadinessService::class)->isReady($device->fresh()));

        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $device->id,
            'check_key' => 'provider_sync',
            'status' => 'stale',
        ]);
        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $device->id,
            'check_key' => 'compliance',
            'status' => 'unknown',
            'source' => 'provider',
        ]);
    }

    private function assignedDevice(User $admin, User $employee, string $assetTag): Device
    {
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => $assetTag,
            'display_name' => 'Identity test device '.$assetTag,
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'Berlin',
            'primary_provider' => 'simulation',
        ], $admin);

        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        return $device->fresh();
    }
}
