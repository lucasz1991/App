<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Jobs\DispatchDeviceIdentitySync;
use App\Models\Device;
use App\Models\DeviceIdentitySync;
use App\Models\User;
use App\Services\DeviceManagement\ConnectorDnsResolver;
use App\Services\DeviceManagement\DeviceAccountPreparationService;
use App\Services\DeviceManagement\DeviceIdentityConnectorService;
use App\Services\DeviceManagement\DeviceIdentitySyncService;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class DeviceIdentityConnectorTest extends TestCase
{
    use DatabaseMigrations;

    private const TOKEN = 'identity-connector-token-000000000000';

    private const WEBHOOK_SECRET = 'identity-webhook-secret-00000000000';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://app.rail-time.test');
        $this->useResolvedAddresses(['93.184.216.34']);
        Http::preventStrayRequests();
    }

    public function test_disabled_connector_keeps_desired_accounts_and_persists_an_honest_blocked_sync(): void
    {
        Queue::fake();
        [$admin, $employee, $device] = $this->assignedMac();

        $assignments = app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365, AccountProvider::GoogleWorkspace, AccountProvider::AppleManaged],
        );

        $this->assertNotEmpty($assignments);
        $this->assertDatabaseCount('employee_identity_accounts', 3);
        $sync = DeviceIdentitySync::query()->firstOrFail();
        $this->assertSame(DeviceIdentitySync::STATUS_BLOCKED, $sync->status);
        $this->assertSame('identity_connector_disabled', $sync->error_code);
        $this->assertNull($sync->dispatched_at);
        $this->assertSame(0, $sync->attempts);
        Queue::assertNotPushed(DispatchDeviceIdentitySync::class);
        Http::assertNothingSent();
    }

    public function test_enabled_identity_connector_without_a_fresh_production_gate_never_dispatches(): void
    {
        Queue::fake();
        $settings = app(DeviceManagementSettings::class);
        $settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'rail-time.test',
        ]);
        $settings->saveProvider('identity', [
            'enabled' => true,
            'token' => self::TOKEN,
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        [$admin, $employee, $device] = $this->assignedMac();

        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );

        $sync = DeviceIdentitySync::query()->firstOrFail();
        $this->assertSame(DeviceIdentitySync::STATUS_BLOCKED, $sync->status);
        $this->assertSame('production_gate_closed', $sync->error_code);
        $this->assertNull($sync->dispatched_at);
        Queue::assertNotPushed(DispatchDeviceIdentitySync::class);
        Http::assertNothingSent();
    }

    public function test_enabled_connector_sends_only_the_idempotent_desired_state_and_preserves_webhook_owned_evidence(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();

        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365, AccountProvider::GoogleWorkspace, AccountProvider::AppleManaged],
        );
        $sync = DeviceIdentitySync::query()->firstOrFail();
        $correlationId = $sync->correlation_id;
        Queue::assertPushedOn('devices', DispatchDeviceIdentitySync::class, fn (DispatchDeviceIdentitySync $job): bool => $job->syncId === $sync->id);

        Http::fake([
            'https://identity.rail-time.test/v1/identity-sync' => Http::response([
                'accepted' => true,
                'completed' => false,
                'provider_job_id' => 'identity-job-100',
                'message' => 'queued',
                'details' => ['status' => 'queued'],
            ]),
        ]);
        $job = new DispatchDeviceIdentitySync($sync->id);
        $job->handle(app(DeviceIdentitySyncService::class), app(DeviceIdentityConnectorService::class));

        $sync->refresh();
        $this->assertSame(DeviceIdentitySync::STATUS_ACCEPTED, $sync->status);
        $this->assertSame($correlationId, $sync->correlation_id);
        $this->assertSame('identity-job-100', $sync->provider_job_id);
        $this->assertSame(1, $sync->attempts);

        Http::assertSent(function (Request $request) use ($device, $employee, $sync): bool {
            $payload = $request->data();
            $serialized = mb_strtolower(json_encode($payload, JSON_THROW_ON_ERROR));

            return $request->method() === 'POST'
                && $request->url() === 'https://identity.rail-time.test/v1/identity-sync'
                && array_keys($payload) === [
                    'sync_id',
                    'correlation_id',
                    'device_id',
                    'assignment_id',
                    'employee_reference',
                    'accounts',
                    'profile_assignment_ids',
                ]
                && $payload['sync_id'] === $sync->public_id
                && $payload['correlation_id'] === $sync->correlation_id
                && $payload['device_id'] === $device->public_id
                && $payload['employee_reference'] === (string) $employee->id
                && collect($payload['accounts'])->pluck('provider')->sort()->values()->all() === [
                    'apple_managed',
                    'google_workspace',
                    'microsoft_365',
                ]
                && ! str_contains($serialized, 'password')
                && ! str_contains($serialized, 'token')
                && ! str_contains($serialized, 'secret');
        });

        $this->assertDatabaseMissing('device_account_assignments', [
            'device_id' => $device->id,
            'status' => 'applied',
        ]);
        $this->assertDatabaseMissing('device_account_assignments', [
            'device_id' => $device->id,
            'status' => 'ready',
        ]);
    }

    public function test_repeated_preparation_reuses_the_same_sync_and_correlation_id(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();
        $service = app(DeviceAccountPreparationService::class);

        $service->prepare($device, $employee, $admin, [AccountProvider::Microsoft365]);
        $first = DeviceIdentitySync::query()->firstOrFail();
        $service->prepare($device->fresh(), $employee, $admin, [AccountProvider::Microsoft365]);

        $this->assertDatabaseCount('device_identity_syncs', 1);
        $this->assertSame($first->correlation_id, DeviceIdentitySync::query()->value('correlation_id'));
        Queue::assertPushed(DispatchDeviceIdentitySync::class, 1);
    }

    public function test_job_blocks_without_http_when_the_employee_assignment_is_no_longer_current(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $sync = DeviceIdentitySync::query()->firstOrFail();

        $replacement = User::factory()->create(['role' => 'staff', 'status' => true]);
        app(DeviceInventoryService::class)->assign($device->fresh(), $replacement, $admin);
        Http::fake();

        (new DispatchDeviceIdentitySync($sync->id))->handle(
            app(DeviceIdentitySyncService::class),
            app(DeviceIdentityConnectorService::class),
        );

        $sync->refresh();
        $this->assertSame(DeviceIdentitySync::STATUS_BLOCKED, $sync->status);
        $this->assertSame('assignment_not_current', $sync->error_code);
        Http::assertNothingSent();
    }

    public function test_return_persists_a_separate_revocation_outbox_without_sending_credentials(): void
    {
        Queue::fake();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );

        app(DeviceInventoryService::class)->returnToInventory($device->fresh(), $admin, 'Hamburg Lager');

        $revocation = DeviceIdentitySync::query()->where('operation', 'revoke')->firstOrFail();
        $this->assertSame($device->id, $revocation->device_id);
        $this->assertSame($employee->id, $revocation->user_id);
        $this->assertSame(DeviceIdentitySync::STATUS_BLOCKED, $revocation->status);
        $this->assertSame('identity_connector_disabled', $revocation->error_code);
        $this->assertDatabaseHas('device_account_assignments', [
            'device_id' => $device->id,
            'user_id' => $employee->id,
            'desired_state' => 'unassigned',
            'status' => 'revoked',
        ]);
        Queue::assertNotPushed(DispatchDeviceIdentitySync::class);
        Http::assertNothingSent();
    }

    public function test_same_employee_can_receive_the_device_again_after_revocation(): void
    {
        Queue::fake();
        [$admin, $employee, $device] = $this->assignedMac();
        $accounts = app(DeviceAccountPreparationService::class);
        $accounts->prepare($device, $employee, $admin, [AccountProvider::Microsoft365]);
        app(DeviceInventoryService::class)->returnToInventory($device->fresh(), $admin, 'Hamburg Lager');
        app(DeviceInventoryService::class)->assign($device->fresh(), $employee, $admin);

        $preparedAgain = $accounts->prepare(
            $device->fresh(),
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );

        $this->assertNotEmpty($preparedAgain);
        $this->assertTrue(collect($preparedAgain)->every(
            fn ($assignment): bool => $assignment->desired_state === 'assigned'
                && $assignment->status === 'pending_provider',
        ));
        $this->assertDatabaseHas('device_identity_syncs', [
            'device_id' => $device->id,
            'operation' => 'revoke',
        ]);
        $this->assertDatabaseHas('device_identity_syncs', [
            'device_id' => $device->id,
            'operation' => 'apply',
            'device_assignment_id' => $device->fresh()->activeAssignment()->value('id'),
        ]);
    }

    public function test_retry_uses_the_original_correlation_and_strict_response_types_fail_closed(): void
    {
        Queue::fake();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $sync = DeviceIdentitySync::query()->firstOrFail();
        $correlationId = $sync->correlation_id;

        $this->enableIdentityConnector();
        $this->assertTrue(app(DeviceIdentitySyncService::class)->retry($sync, $admin));
        $this->assertSame($correlationId, $sync->fresh()->correlation_id);
        Queue::assertPushed(DispatchDeviceIdentitySync::class, 1);

        Http::fake([
            'https://identity.rail-time.test/v1/identity-sync' => Http::response([
                'accepted' => 'true',
                'completed' => false,
            ]),
        ]);
        $job = new DispatchDeviceIdentitySync($sync->id);
        try {
            $job->handle(app(DeviceIdentitySyncService::class), app(DeviceIdentityConnectorService::class));
            $this->fail('String booleans must violate the strict connector response contract.');
        } catch (RuntimeException $exception) {
            $job->failed($exception);
        }

        $sync->refresh();
        $this->assertSame(DeviceIdentitySync::STATUS_FAILED, $sync->status);
        $this->assertSame('connector_failed', $sync->error_code);
        $this->assertStringNotContainsString(self::TOKEN, (string) $sync->error_message);
    }

    public function test_preparation_and_apply_outbox_roll_back_as_one_business_transaction(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();

        try {
            DB::transaction(function () use ($admin, $employee, $device): void {
                app(DeviceAccountPreparationService::class)->prepare(
                    $device,
                    $employee,
                    $admin,
                    [AccountProvider::Microsoft365],
                );

                $this->assertDatabaseCount('device_identity_syncs', 1);
                throw new RuntimeException('force outer rollback');
            });
            $this->fail('The outer transaction must be rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force outer rollback', $exception->getMessage());
        }

        $this->assertDatabaseCount('employee_identity_accounts', 0);
        $this->assertDatabaseCount('device_account_assignments', 0);
        $this->assertDatabaseCount('device_identity_syncs', 0);
        Queue::assertNothingPushed();
    }

    public function test_return_and_revoke_outbox_roll_back_as_one_business_transaction(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $applySyncId = (int) DeviceIdentitySync::query()->where('operation', 'apply')->value('id');

        try {
            DB::transaction(function () use ($admin, $device): void {
                app(DeviceInventoryService::class)->returnToInventory(
                    $device->fresh(),
                    $admin,
                    'Hamburg Lager',
                );

                $this->assertDatabaseHas('device_identity_syncs', [
                    'device_id' => $device->id,
                    'operation' => 'revoke',
                ]);
                throw new RuntimeException('force return rollback');
            });
            $this->fail('The outer return transaction must be rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force return rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('device_identity_syncs', [
            'device_id' => $device->id,
            'operation' => 'revoke',
        ]);
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('device_account_assignments', [
            'device_id' => $device->id,
            'user_id' => $employee->id,
            'desired_state' => 'assigned',
        ]);
        Queue::assertPushed(
            DispatchDeviceIdentitySync::class,
            fn (DispatchDeviceIdentitySync $job): bool => $job->syncId === $applySyncId,
        );
        Queue::assertPushed(DispatchDeviceIdentitySync::class, 1);
    }

    public function test_reassignment_and_revoke_outbox_roll_back_as_one_business_transaction(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $replacement = User::factory()->create(['role' => 'staff', 'status' => true]);

        try {
            DB::transaction(function () use ($admin, $device, $employee, $replacement): void {
                app(DeviceInventoryService::class)->assign(
                    $device->fresh(),
                    $replacement,
                    $admin,
                );

                $this->assertDatabaseHas('device_identity_syncs', [
                    'device_id' => $device->id,
                    'user_id' => $employee->id,
                    'operation' => 'revoke',
                ]);
                $this->assertDatabaseHas('device_assignments', [
                    'device_id' => $device->id,
                    'user_id' => $replacement->id,
                    'status' => 'active',
                ]);
                throw new RuntimeException('force reassignment rollback');
            });
            $this->fail('The outer reassignment transaction must be rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force reassignment rollback', $exception->getMessage());
        }

        $this->assertDatabaseMissing('device_identity_syncs', [
            'device_id' => $device->id,
            'operation' => 'revoke',
        ]);
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseMissing('device_assignments', [
            'device_id' => $device->id,
            'user_id' => $replacement->id,
        ]);
        $this->assertDatabaseHas('device_account_assignments', [
            'device_id' => $device->id,
            'user_id' => $employee->id,
            'desired_state' => 'assigned',
        ]);
        Queue::assertPushed(DispatchDeviceIdentitySync::class, 1);
    }

    public function test_recovery_releases_gate_blocked_rows_once_without_changing_correlation(): void
    {
        Queue::fake();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $sync = DeviceIdentitySync::query()->firstOrFail();
        $correlationId = $sync->correlation_id;

        $this->enableIdentityConnector();
        $this->artisan('devices:recover-identity-outbox')->assertSuccessful();
        $this->artisan('devices:recover-identity-outbox')->assertSuccessful();

        $sync->refresh();
        $this->assertSame(DeviceIdentitySync::STATUS_QUEUED, $sync->status);
        $this->assertSame($correlationId, $sync->correlation_id);
        Queue::assertPushed(
            DispatchDeviceIdentitySync::class,
            fn (DispatchDeviceIdentitySync $job): bool => $job->syncId === $sync->id,
        );
        Queue::assertPushed(DispatchDeviceIdentitySync::class, 1);
        Http::assertNothingSent();
    }

    public function test_recovery_redispatches_a_stale_queued_row_once_and_respects_a_closed_gate(): void
    {
        Queue::fake();
        $this->enableIdentityConnector();
        [$admin, $employee, $device] = $this->assignedMac();
        app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $sync = DeviceIdentitySync::query()->firstOrFail();
        DeviceIdentitySync::query()->whereKey($sync->id)->update([
            'last_enqueued_at' => now()->subMinutes(11),
        ]);

        $this->artisan('devices:recover-identity-outbox')->assertSuccessful();
        $this->artisan('devices:recover-identity-outbox')->assertSuccessful();
        Queue::assertPushed(DispatchDeviceIdentitySync::class, 2);

        app(DeviceManagementSettings::class)->setProductionCommandsEnabled(false);
        DeviceIdentitySync::query()->whereKey($sync->id)->update([
            'last_enqueued_at' => now()->subMinutes(11),
        ]);
        $this->artisan('devices:recover-identity-outbox')->assertSuccessful();

        Queue::assertPushed(DispatchDeviceIdentitySync::class, 2);
        $this->assertSame(DeviceIdentitySync::STATUS_QUEUED, $sync->fresh()->status);
        Http::assertNothingSent();
    }

    private function enableIdentityConnector(): void
    {
        $settings = app(DeviceManagementSettings::class);
        $settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'rail-time.test',
        ]);
        $settings->saveProvider('identity', [
            'enabled' => true,
            'token' => self::TOKEN,
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
        $settings->recordProviderDiagnostic('identity', [
            'healthy' => true,
            'authenticated' => true,
            'contract_valid' => true,
            'status' => 'healthy',
            'contract' => [
                'upstream_reachable' => true,
                'upstream_authenticated' => true,
            ],
        ], $settings->providerFingerprint('identity', fresh: true));
        $settings->setProductionCommandsEnabled(true);
    }

    /** @return array{User, User, Device} */
    private function assignedMac(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'startklar@rail-time.de',
        ]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-ID-CONNECTOR-'.str()->random(8),
            'display_name' => 'Startklar MacBook',
            'form_factor' => 'laptop',
            'platform' => 'macos',
            'ownership' => 'corporate',
            'primary_provider' => 'simulation',
        ], $admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        return [$admin, $employee, $device->fresh()];
    }

    /** @param list<string> $addresses */
    private function useResolvedAddresses(array $addresses): void
    {
        $this->app->instance(ConnectorDnsResolver::class, new class($addresses) extends ConnectorDnsResolver
        {
            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses) {}

            /** @return list<string> */
            public function addressesFor(string $host): array
            {
                return $this->addresses;
            }
        });
    }
}
