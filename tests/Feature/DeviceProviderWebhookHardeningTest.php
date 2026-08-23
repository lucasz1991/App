<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceAccountAssignment;
use App\Models\DeviceAssignment;
use App\Models\DeviceEnrollment;
use App\Models\DeviceIdentitySync;
use App\Models\DeviceProviderEvent;
use App\Models\DeviceProvisioningProfile;
use App\Models\DeviceReadinessCheck;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\DeviceEnrollmentService;
use App\Services\DeviceManagement\DeviceInventoryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Throwable;

class DeviceProviderWebhookHardeningTest extends TestCase
{
    use DatabaseMigrations;

    private const SIMULATION_SECRET = 'provider-event-test-secret';

    private const IDENTITY_SECRET = 'identity-event-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('device_management.providers.simulation.enabled', true);
        config()->set('device_management.providers.simulation.webhook_secret', self::SIMULATION_SECRET);
        config()->set('device_management.providers.identity.enabled', true);
        config()->set('device_management.providers.identity.webhook_secret', self::IDENTITY_SECRET);
    }

    public function test_identical_retry_does_not_refresh_evidence_or_audit(): void
    {
        Carbon::setTestNow('2026-08-23 10:00:00');
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $payload = [
            'event_id' => 'evt-global-retry-1',
            'event_type' => 'readiness.updated',
            'device_id' => $device->public_id,
            'checks' => [
                ['key' => 'network', 'status' => 'passed', 'evidence' => ['profile' => 'railtime-vpn']],
            ],
        ];

        $this->postSignedWebhook('simulation', self::SIMULATION_SECRET, $payload)
            ->assertOk()
            ->assertJson(['accepted' => true, 'event_id' => 'evt-global-retry-1', 'duplicate' => false]);

        $firstCheck = DeviceReadinessCheck::query()
            ->where('device_id', $device->id)
            ->where('check_key', 'network')
            ->firstOrFail();
        $firstCheckedAt = $firstCheck->checked_at?->toISOString();
        $firstAcceptedAt = DeviceProviderEvent::query()->firstOrFail()->accepted_at?->toISOString();
        $firstAuditCount = $this->identityAuditCount('device-readiness.updated');

        Carbon::setTestNow('2026-08-23 10:01:00');
        $this->postSignedWebhook('simulation', self::SIMULATION_SECRET, $payload)
            ->assertOk()
            ->assertJson(['accepted' => true, 'event_id' => 'evt-global-retry-1', 'duplicate' => true]);

        $this->assertSame($firstCheckedAt, $firstCheck->fresh()->checked_at?->toISOString());
        $this->assertSame($firstAcceptedAt, DeviceProviderEvent::query()->firstOrFail()->accepted_at?->toISOString());
        $this->assertSame($firstAuditCount, $this->identityAuditCount('device-readiness.updated'));
        $this->assertSame(1, DeviceProviderEvent::query()->count());
    }

    public function test_reused_event_id_with_different_payload_is_rejected_without_mutation(): void
    {
        Carbon::setTestNow('2026-08-23 11:00:00');
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $first = [
            'event_id' => 'evt-collision-1',
            'event_type' => 'readiness.updated',
            'device_id' => $device->public_id,
            'checks' => [['key' => 'network', 'status' => 'passed']],
        ];
        $this->postSignedWebhook('simulation', self::SIMULATION_SECRET, $first)->assertOk();
        $checkedAt = DeviceReadinessCheck::query()
            ->where('device_id', $device->id)
            ->where('check_key', 'network')
            ->firstOrFail()
            ->checked_at?->toISOString();

        Carbon::setTestNow('2026-08-23 11:01:00');
        $changed = $first;
        $changed['checks'] = [['key' => 'network', 'status' => 'blocked']];
        $this->postSignedWebhook('simulation', self::SIMULATION_SECRET, $changed)
            ->assertConflict()
            ->assertJsonPath('message', 'Die event_id wurde bereits mit einem anderen Payload verwendet.');

        $check = DeviceReadinessCheck::query()
            ->where('device_id', $device->id)
            ->where('check_key', 'network')
            ->firstOrFail();
        $this->assertSame('passed', $check->status);
        $this->assertSame($checkedAt, $check->checked_at?->toISOString());
        $this->assertSame(1, DeviceProviderEvent::query()->count());
    }

    public function test_handler_exception_rolls_back_event_claim_and_can_be_retried(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $invitation = app(DeviceEnrollmentService::class)->invite(
            $device,
            $employee,
            'simulation',
            'agent',
            $admin,
        );
        app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $employee);
        $payload = [
            'event_id' => 'evt-retry-after-exception-1',
            'event_type' => 'enrollment.completed',
            'enrollment_id' => $invitation->enrollment->public_id,
        ];

        DB::table('device_enrollments')
            ->where('id', $invitation->enrollment->id)
            ->update(['metadata' => 'temporarily-invalid-encrypted-value']);

        try {
            $this->withoutExceptionHandling()
                ->postSignedWebhook('simulation', self::SIMULATION_SECRET, $payload);
            $this->fail('The simulated handler exception must escape as a retryable server error.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('payload', strtolower($exception->getMessage()));
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertDatabaseMissing('device_provider_events', [
            'provider' => 'simulation',
            'event_id' => 'evt-retry-after-exception-1',
        ]);
        $this->assertSame('claimed', $invitation->enrollment->fresh()->status->value);

        $cleanEnrollment = new DeviceEnrollment;
        $cleanEnrollment->metadata = [];
        DB::table('device_enrollments')
            ->where('id', $invitation->enrollment->id)
            ->update(['metadata' => $cleanEnrollment->getAttributes()['metadata']]);
        $this->postSignedWebhook('simulation', self::SIMULATION_SECRET, $payload)
            ->assertOk()
            ->assertJson(['duplicate' => false]);
        $this->assertDatabaseHas('device_provider_events', [
            'provider' => 'simulation',
            'event_id' => 'evt-retry-after-exception-1',
            'status' => DeviceProviderEvent::STATUS_ACCEPTED,
        ]);
    }

    public function test_complete_identity_receipt_is_bound_to_sync_and_marks_it_completed(): void
    {
        $context = $this->identityContext(['microsoft_365', 'google_workspace']);
        $payload = $this->identityPayload($context, 'evt-identity-complete-1');

        $this->postSignedWebhook('identity', self::IDENTITY_SECRET, $payload)
            ->assertOk()
            ->assertJson(['accepted' => true, 'duplicate' => false]);

        $sync = $context['sync']->fresh();
        $this->assertSame(DeviceIdentitySync::STATUS_COMPLETED, $sync->status);
        $this->assertNotNull($sync->completed_at);
        $this->assertSame('evt-identity-complete-1', $sync->result['receipt_event_id'] ?? null);
        foreach ($context['identities'] as $identity) {
            $this->assertSame('ready', $identity->fresh()->provisioning_status);
            $this->assertSame('active', $identity->fresh()->license_status);
            $this->assertNotNull($identity->fresh()->external_id);
        }
        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $context['device']->id,
            'check_key' => 'identity',
            'status' => 'passed',
            'source' => 'identity',
        ]);
        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $context['device']->id,
            'check_key' => 'user_sign_in',
            'status' => 'passed',
            'source' => 'identity',
        ]);
    }

    public function test_identity_receipt_requires_all_sync_references_and_a_strict_boolean(): void
    {
        $context = $this->identityContext(['microsoft_365']);
        $payload = $this->identityPayload($context, 'evt-identity-schema-1');
        unset($payload['sync_id']);

        $this->postSignedWebhook('identity', self::IDENTITY_SECRET, $payload)->assertUnprocessable();
        $this->assertSame(DeviceIdentitySync::STATUS_ACCEPTED, $context['sync']->fresh()->status);
        $this->assertDatabaseMissing('device_provider_events', ['event_id' => 'evt-identity-schema-1']);

        $payload = $this->identityPayload($context, 'evt-identity-schema-2');
        $payload['accounts'][0]['signed_in'] = 'true';
        $this->postSignedWebhook('identity', self::IDENTITY_SECRET, $payload)->assertUnprocessable();

        $identity = $context['identities']['microsoft_365']->fresh();
        $this->assertSame('pending_provider', $identity->provisioning_status);
        $this->assertSame('unknown', $identity->license_status);
        $this->assertDatabaseMissing('device_provider_events', ['event_id' => 'evt-identity-schema-2']);
    }

    public function test_incomplete_identity_receipt_cannot_mutate_a_subset(): void
    {
        $context = $this->identityContext(['microsoft_365', 'google_workspace']);
        $payload = $this->identityPayload($context, 'evt-identity-incomplete-1');
        $payload['accounts'] = [$payload['accounts'][0]];

        $this->postSignedWebhook('identity', self::IDENTITY_SECRET, $payload)->assertUnprocessable();

        foreach ($context['identities'] as $identity) {
            $this->assertSame('pending_provider', $identity->fresh()->provisioning_status);
            $this->assertSame('unknown', $identity->fresh()->license_status);
        }
        $this->assertSame(DeviceIdentitySync::STATUS_ACCEPTED, $context['sync']->fresh()->status);
        $this->assertDatabaseMissing('device_provider_events', ['event_id' => 'evt-identity-incomplete-1']);
    }

    public function test_receipt_for_returned_assignment_cannot_touch_successor_context(): void
    {
        $context = $this->identityContext(['microsoft_365']);
        $successor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $context['assignment']->forceFill([
            'status' => DeviceAssignment::STATUS_RETURNED,
            'returned_at' => now(),
        ])->save();
        DeviceAssignment::query()->create([
            'device_id' => $context['device']->id,
            'user_id' => $successor->id,
            'status' => DeviceAssignment::STATUS_ACTIVE,
            'assigned_by' => $context['admin']->id,
            'assigned_at' => now(),
        ]);
        $successorIdentity = EmployeeIdentityAccount::query()->create([
            'user_id' => $successor->id,
            'provider' => 'microsoft_365',
            'principal' => 'successor.'.Str::lower(Str::random(8)).'@rail-time.test',
            'lifecycle_status' => 'active',
            'provisioning_status' => 'pending_provider',
            'license_status' => 'unknown',
        ]);

        $payload = $this->identityPayload($context, 'evt-identity-stale-1');
        $this->postSignedWebhook('identity', self::IDENTITY_SECRET, $payload)->assertUnprocessable();

        $this->assertSame('pending_provider', $context['identities']['microsoft_365']->fresh()->provisioning_status);
        $this->assertSame('pending_provider', $successorIdentity->fresh()->provisioning_status);
        $this->assertSame(DeviceIdentitySync::STATUS_ACCEPTED, $context['sync']->fresh()->status);
        $this->assertDatabaseMissing('device_provider_events', ['event_id' => 'evt-identity-stale-1']);
    }

    private function device(User $admin, User $employee): Device
    {
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-'.strtoupper(fake()->bothify('WH-####')),
            'serial_number' => strtoupper(fake()->bothify('WH-SN-########')),
            'display_name' => 'Webhook Testgerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'primary_provider' => 'simulation',
        ], $admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        return $device->fresh();
    }

    /**
     * @param  list<string>  $providers
     * @return array{admin: User, employee: User, device: Device, assignment: DeviceAssignment, sync: DeviceIdentitySync, identities: array<string, EmployeeIdentityAccount>}
     */
    private function identityContext(array $providers): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $assignment = $device->activeAssignment()->firstOrFail();
        $identities = [];
        $accountAssignmentIds = [];

        foreach ($providers as $provider) {
            $identity = EmployeeIdentityAccount::query()->create([
                'user_id' => $employee->id,
                'provider' => $provider,
                'principal' => $provider.'.'.Str::lower(Str::random(8)).'@rail-time.test',
                'lifecycle_status' => 'active',
                'provisioning_status' => 'pending_provider',
                'license_status' => 'unknown',
            ]);
            $profile = DeviceProvisioningProfile::query()->create([
                'provider' => $provider,
                'type' => 'identity_baseline',
                'name' => 'Webhook '.Str::random(8),
                'version' => 1,
                'platforms' => ['windows'],
                'configuration' => ['catalog_reference' => $provider.'-baseline'],
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
            $line = DeviceAccountAssignment::query()->create([
                'device_id' => $device->id,
                'user_id' => $employee->id,
                'employee_identity_account_id' => $identity->id,
                'device_provisioning_profile_id' => $profile->id,
                'desired_state' => 'assigned',
                'status' => 'pending',
                'requested_at' => now(),
            ]);
            $identities[$provider] = $identity;
            $accountAssignmentIds[] = $line->id;
        }

        $sync = DeviceIdentitySync::query()->create([
            'device_id' => $device->id,
            'device_assignment_id' => $assignment->id,
            'user_id' => $employee->id,
            'status' => DeviceIdentitySync::STATUS_ACCEPTED,
            'deduplication_key' => hash('sha256', (string) Str::uuid()),
            'correlation_id' => (string) Str::uuid(),
            'account_assignment_ids' => $accountAssignmentIds,
            'profile_assignment_ids' => $accountAssignmentIds,
            'attempts' => 1,
            'requested_at' => now(),
            'dispatched_at' => now(),
            'requested_by' => $admin->id,
        ]);

        return compact('admin', 'employee', 'device', 'assignment', 'sync', 'identities');
    }

    /**
     * @param  array{device: Device, assignment: DeviceAssignment, sync: DeviceIdentitySync, identities: array<string, EmployeeIdentityAccount>}  $context
     * @return array<string, mixed>
     */
    private function identityPayload(array $context, string $eventId): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => 'identity.updated',
            'sync_id' => (string) $context['sync']->public_id,
            'correlation_id' => (string) $context['sync']->correlation_id,
            'assignment_id' => (string) $context['assignment']->id,
            'device_id' => (string) $context['device']->public_id,
            'accounts' => collect($context['identities'])
                ->map(fn (EmployeeIdentityAccount $identity, string $provider): array => [
                    'provider' => $provider,
                    'external_id' => 'external-'.$identity->id,
                    'provisioning_status' => 'ready',
                    'license_status' => 'active',
                    'signed_in' => true,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postSignedWebhook(string $provider, string $secret, array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        return $this->postJson(
            route('api.device-management.providers.events', ['provider' => $provider]),
            $payload,
            [
                'X-RailTime-Timestamp' => $timestamp,
                'X-RailTime-Signature' => 'sha256='.$signature,
            ],
        );
    }

    private function identityAuditCount(string $event): int
    {
        return (int) Activity::query()->where('event', $event)->count();
    }
}
