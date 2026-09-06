<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Jobs\SyncMicrosoftDevices;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class MicrosoftDeviceSyncTriggerTest extends TestCase
{
    use DatabaseMigrations;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const OBJECT = '22222222-2222-4222-8222-222222222222';

    private const OTHER_TENANT = '33333333-3333-4333-8333-333333333333';

    private const SECRET = 'microsoft-test-secret-never-in-job';

    private User $admin;

    private MicrosoftDeviceSettings $settings;

    private MicrosoftDeviceSyncScheduler $scheduler;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'outlook_addin.snapshots.auto_refresh' => false,
            'outlook_addin.entra.tenant_id' => self::TENANT,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->queueManager = Queue::getFacadeRoot();
        Queue::fake();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->settings = app(MicrosoftDeviceSettings::class);
        $this->settings->save([
            'enabled' => true,
            'tenant_id' => self::TENANT,
            'client_id' => '44444444-4444-4444-8444-444444444444',
            'client_secret' => self::SECRET,
        ], $this->admin);
        $this->scheduler = app(MicrosoftDeviceSyncScheduler::class);
    }

    public function test_verified_existing_identity_binds_its_tenant_and_queues_once_without_secrets(): void
    {
        [$user, $account] = $this->identityAccount(null);
        $identity = $this->verifiedIdentity();

        $this->scheduler->afterMicrosoftSignIn($identity, $user);
        $this->scheduler->afterMicrosoftSignIn($identity, $user);

        $this->assertSame(self::TENANT, $account->fresh()->tenant_id);
        Queue::assertPushed(SyncMicrosoftDevices::class, 1);
        Queue::assertPushedOn('microsoft-devices', SyncMicrosoftDevices::class, function (SyncMicrosoftDevices $job): bool {
            $this->assertSame(self::TENANT, $job->tenantId);
            $this->assertSame($this->settings->fingerprint(), $job->configurationFingerprint);
            $this->assertSame('microsoft_devices', $job->connection);
            $this->assertTrue($job->afterCommit);
            $this->assertStringNotContainsString(self::SECRET, serialize($job));
            $this->assertStringNotContainsString('member@example.test', serialize($job));

            return true;
        });
        Http::assertNothingSent();
    }

    public function test_another_tenant_or_mismatched_addin_configuration_never_rebinds_an_identity(): void
    {
        [$user, $account] = $this->identityAccount(self::OTHER_TENANT);
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $this->assertSame(self::OTHER_TENANT, $account->fresh()->tenant_id);

        $account->forceFill(['tenant_id' => null])->save();
        config(['outlook_addin.entra.tenant_id' => self::OTHER_TENANT]);
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $this->assertNull($account->fresh()->tenant_id);

        $this->scheduler->afterMicrosoftSignIn(new VerifiedEntraIdentity(
            self::OTHER_TENANT, self::OBJECT, 'member@example.test', 'Member',
        ), $user);
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
    }

    public function test_user_object_principal_and_active_verified_status_must_all_match(): void
    {
        [$user, $account] = $this->identityAccount();
        $otherUser = User::factory()->create(['status' => true, 'email_verified_at' => now()]);
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $otherUser);
        $this->scheduler->afterMicrosoftSignIn(new VerifiedEntraIdentity(
            self::TENANT, self::OTHER_TENANT, 'member@example.test', 'Member',
        ), $user);
        $this->scheduler->afterMicrosoftSignIn(new VerifiedEntraIdentity(
            self::TENANT, self::OBJECT, 'another@example.test', 'Member',
        ), $user);

        $user->forceFill(['status' => false])->save();
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $user->forceFill(['status' => true, 'email_verified_at' => null])->save();
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $user->forceFill(['email_verified_at' => now()])->save();
        $account->forceFill(['lifecycle_status' => 'inactive'])->save();
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);

        Queue::assertNotPushed(SyncMicrosoftDevices::class);
        Http::assertNothingSent();
    }

    public function test_sign_in_switch_and_disabled_connection_prevent_dispatch(): void
    {
        [$user] = $this->identityAccount();
        $this->settings->save(['sync_on_sign_in' => false], $this->admin);
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $this->settings->save(['enabled' => false], $this->admin);
        $this->assertFalse($this->scheduler->queue(force: true));
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
    }

    public function test_invalid_dedicated_queue_reports_actionable_error_but_cannot_break_microsoft_sign_in(): void
    {
        config(['queue.connections.microsoft_devices.driver' => 'null']);
        [$user] = $this->identityAccount();
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
        Http::assertNothingSent();

        $this->artisan('devices:sync-microsoft --force')
            ->expectsOutputToContain('Datenbankqueue microsoft_devices')
            ->assertFailed();
    }

    public function test_missing_jobs_table_cannot_fall_back_to_inline_work(): void
    {
        Schema::drop('jobs');
        [$user] = $this->identityAccount();
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
        Http::assertNothingSent();

        $this->artisan('devices:sync-microsoft --force')
            ->expectsOutputToContain('jobs-Tabelle')
            ->assertFailed();
    }

    public function test_dedicated_database_queue_persists_a_job_even_when_app_default_is_sync(): void
    {
        Queue::swap($this->queueManager);
        $this->assertTrue($this->scheduler->queue());

        $row = DB::table('jobs')->sole();
        $this->assertSame('microsoft-devices', $row->queue);
        $this->assertNull($row->reserved_at);
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(SyncMicrosoftDevices::class, $payload['displayName']);
        $this->assertSame(240, $payload['timeout']);
        $this->assertStringNotContainsString(self::SECRET, $row->payload);
        $this->assertSame(300, config('queue.connections.microsoft_devices.retry_after'));
        $this->assertSame('sync', config('queue.default'));
        Http::assertNothingSent();
    }

    public function test_committed_dispatch_waits_for_commit_and_rollback_leaves_no_reservation(): void
    {
        DB::beginTransaction();
        $this->assertTrue($this->scheduler->queue());
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
        DB::rollBack();

        DB::beginTransaction();
        $this->assertTrue($this->scheduler->queue());
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
        DB::commit();

        Queue::assertPushed(SyncMicrosoftDevices::class, 1);
    }

    public function test_transport_rejection_clears_reservation_and_does_not_mark_the_interval_done(): void
    {
        $dispatcher = Bus::getFacadeRoot();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('private transport failure'));
        try {
            $this->scheduler->queue();
            $this->fail('The rejected queue dispatch must not be reported as queued.');
        } catch (RuntimeException $exception) {
            $this->assertSame('private transport failure', $exception->getMessage());
        } finally {
            Bus::swap($dispatcher);
        }

        $this->assertTrue($this->scheduler->queue());
        Queue::assertPushed(SyncMicrosoftDevices::class, 1);
    }

    public function test_interval_force_and_crashed_job_lease_do_not_allow_a_permanent_block(): void
    {
        $this->assertTrue($this->scheduler->queue());
        $job = Queue::pushed(SyncMicrosoftDevices::class)->first();
        $this->assertFalse($this->scheduler->queue(force: true));
        $this->scheduler->release($job->tenantId, $job->reservation);
        $this->assertFalse($this->scheduler->queue());
        $this->assertTrue($this->scheduler->queue(force: true));

        $this->travel(16)->minutes();
        $this->assertTrue($this->scheduler->queue());
        $this->scheduler->release($job->tenantId, $job->reservation);
        $this->assertFalse($this->scheduler->queue(force: true));
        Queue::assertPushed(SyncMicrosoftDevices::class, 3);
    }

    public function test_queued_job_does_not_run_after_the_configuration_changes(): void
    {
        $this->assertTrue($this->scheduler->queue());
        $job = Queue::pushed(SyncMicrosoftDevices::class)->first();
        $this->settings->save(['client_secret' => 'rotated-secret'], $this->admin);
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldNotReceive('sync');

        $job->handle($this->settings, $sync, $this->scheduler);

        $this->assertTrue($this->scheduler->queue());
        Http::assertNothingSent();
    }

    public function test_dispatch_and_worker_take_configuration_and_fingerprint_from_one_snapshot(): void
    {
        $initial = $this->settings->snapshot();
        $this->settings->save(['client_secret' => 'new-credential-snapshot'], $this->admin);
        $changed = $this->settings->snapshot();
        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldReceive('snapshot')->twice()->andReturn($initial, $changed);
        $settings->shouldNotReceive('configuration');
        $settings->shouldNotReceive('fingerprint');

        $scheduler = new MicrosoftDeviceSyncScheduler($settings);
        $this->assertFalse($scheduler->queue());
        Queue::assertNotPushed(SyncMicrosoftDevices::class);

        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldReceive('snapshot')->once()->andReturn($changed);
        $settings->shouldNotReceive('configuration');
        $settings->shouldNotReceive('fingerprint');
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andReturn(['status' => 'success']);
        $job = new SyncMicrosoftDevices(self::TENANT, $changed['fingerprint'], 'snapshot-only-test');
        $job->handle($settings, $sync, $this->scheduler);
        Http::assertNothingSent();
    }

    public function test_legacy_identity_is_not_bound_if_settings_change_before_the_locked_recheck(): void
    {
        [$user, $account] = $this->identityAccount(null);
        $initial = $this->settings->snapshot();
        $this->settings->save([
            'tenant_id' => self::OTHER_TENANT,
            'client_secret' => 'new-tenant-credential',
        ], $this->admin);
        $changed = $this->settings->snapshot();
        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldReceive('snapshot')->twice()->andReturn($initial, $changed);

        (new MicrosoftDeviceSyncScheduler($settings))->afterMicrosoftSignIn($this->verifiedIdentity(), $user);

        $this->assertNull($account->fresh()->tenant_id);
        Queue::assertNotPushed(SyncMicrosoftDevices::class);
    }

    public function test_outlook_resolver_accepts_legacy_and_matching_tenant_but_rejects_a_foreign_tenant(): void
    {
        [$user, $account] = $this->identityAccount(null);
        $resolver = app(OutlookAddinIdentityResolver::class);
        $identity = $this->verifiedIdentity();
        $this->assertSame($user->getKey(), $resolver->resolve($identity, $identity->principal, $identity->principal)['user']->getKey());

        $account->forceFill(['tenant_id' => self::TENANT])->save();
        $this->assertSame($user->getKey(), $resolver->resolve($identity, $identity->principal, $identity->principal)['user']->getKey());

        $account->forceFill(['tenant_id' => self::OTHER_TENANT])->save();
        try {
            $resolver->resolve($identity, $identity->principal, $identity->principal);
            $this->fail('A different tenant must not be treated as the same Outlook identity.');
        } catch (OutlookAddinException $exception) {
            $this->assertSame(403, $exception->httpStatus);
            $this->assertSame('outlook_addin_identity_not_linked', $exception->errorCode);
        }
        $this->assertSame(self::OTHER_TENANT, $account->fresh()->tenant_id);
    }

    public function test_worker_uses_the_fresh_service_and_releases_failed_or_successful_reservations(): void
    {
        $this->assertTrue($this->scheduler->queue());
        $job = Queue::pushed(SyncMicrosoftDevices::class)->first();
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andReturn(['status' => 'success']);
        $job->handle($this->settings, $sync, $this->scheduler);
        $this->assertTrue($this->scheduler->queue(force: true));

        $job = Queue::pushed(SyncMicrosoftDevices::class)->last();
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andThrow(new RuntimeException('unavailable'));
        try {
            $job->handle($this->settings, $sync, $this->scheduler);
            $this->fail('Worker errors must remain visible to the queue.');
        } catch (RuntimeException) {
            $this->assertTrue($this->scheduler->queue(force: true));
        }
    }

    /** @return array{User, EmployeeIdentityAccount} */
    private function identityAccount(?string $tenant = self::TENANT): array
    {
        $user = User::factory()->create(['status' => true, 'email_verified_at' => now()]);
        $account = EmployeeIdentityAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => AccountProvider::Microsoft365,
            'tenant_id' => $tenant,
            'external_id' => self::OBJECT,
            'principal' => 'member@example.test',
            'email' => 'member@example.test',
            'lifecycle_status' => 'active',
        ]);

        return [$user, $account];
    }

    private function verifiedIdentity(): VerifiedEntraIdentity
    {
        return new VerifiedEntraIdentity(self::TENANT, self::OBJECT, 'member@example.test', 'Member');
    }
}
