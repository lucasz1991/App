<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Jobs\ProbeMicrosoftDeviceWorker;
use App\Jobs\SyncMicrosoftDevices;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use App\Support\OutlookAddin\OutlookAddinException;
use App\Support\OutlookAddin\OutlookAddinIdentityResolver;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\TimeoutExceededException;
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

    private MicrosoftDeviceRuntime $runtime;

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
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->settings = app(MicrosoftDeviceSettings::class);
        $this->settings->save([
            'enabled' => true,
            'tenant_id' => self::TENANT,
            'client_id' => '44444444-4444-4444-8444-444444444444',
            'client_secret' => self::SECRET,
        ], $this->admin);
        $this->scheduler = app(MicrosoftDeviceSyncScheduler::class);
        $this->runtime = app(MicrosoftDeviceRuntime::class);
    }

    public function test_verified_existing_identity_binds_its_tenant_and_queues_once_without_secrets(): void
    {
        [$user, $account] = $this->identityAccount(null);
        $identity = $this->verifiedIdentity();

        $this->scheduler->afterMicrosoftSignIn($identity, $user);
        $this->scheduler->afterMicrosoftSignIn($identity, $user);

        $this->assertSame(self::TENANT, $account->fresh()->tenant_id);
        $this->assertDatabaseCount('jobs', 1);
        $row = DB::table('jobs')->sole();
        $job = unserialize(json_decode($row->payload, true)['data']['command']);
        $this->assertSame(self::TENANT, $job->tenantId);
        $this->assertSame($this->settings->fingerprint(), $job->configurationFingerprint);
        $this->assertSame('microsoft_devices', $job->connection);
        $this->assertFalse($job->afterCommit); // Queue insert shares the enclosing transaction.
        $this->assertStringNotContainsString(self::SECRET, $row->payload);
        $this->assertStringNotContainsString('member@example.test', $row->payload);
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
        $this->assertDatabaseCount('jobs', 0);
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

        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_sign_in_switch_and_disabled_connection_prevent_dispatch(): void
    {
        [$user] = $this->identityAccount();
        $this->settings->save(['sync_on_sign_in' => false], $this->admin);
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $this->settings->save(['enabled' => false], $this->admin);
        $this->assertFalse($this->scheduler->queue(force: true));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_invalid_dedicated_queue_reports_actionable_error_but_cannot_break_microsoft_sign_in(): void
    {
        config(['queue.connections.microsoft_devices.driver' => 'null']);
        [$user] = $this->identityAccount();
        $this->scheduler->afterMicrosoftSignIn($this->verifiedIdentity(), $user);
        $this->assertDatabaseCount('jobs', 0);
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
        $this->assertSame(0, DB::table('microsoft_device_runs')->where('kind', 'sync')->count());
        Http::assertNothingSent();

        $this->artisan('devices:sync-microsoft --force')
            ->expectsOutputToContain('jobs-Tabelle')
            ->assertFailed();
    }

    public function test_dedicated_database_queue_persists_a_job_even_when_app_default_is_sync(): void
    {
        $this->assertTrue($this->scheduler->queue());

        $row = DB::table('jobs')->sole();
        $this->assertSame('microsoft_devices', $row->queue);
        $this->assertNull($row->reserved_at);
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(SyncMicrosoftDevices::class, $payload['displayName']);
        $this->assertSame(240, $payload['timeout']);
        $this->assertStringNotContainsString(self::SECRET, $row->payload);
        $this->assertSame(300, config('queue.connections.microsoft_devices.retry_after'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertDatabaseHas('microsoft_device_runs', ['kind' => 'sync', 'queue_job_id' => $row->id, 'status' => 'queued']);
        Http::assertNothingSent();
    }

    public function test_queue_and_run_rollback_together_and_commit_has_a_real_job_binding(): void
    {
        DB::beginTransaction();
        $this->assertTrue($this->scheduler->queue());
        $this->assertDatabaseCount('jobs', 1);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, DB::table('microsoft_device_runs')->where('kind', 'sync')->count());

        DB::beginTransaction();
        $this->assertTrue($this->scheduler->queue());
        $this->assertDatabaseCount('jobs', 1);
        DB::commit();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotNull(DB::table('microsoft_device_runs')->where('kind', 'sync')->sole()->queue_job_id);
    }

    public function test_transport_rejection_clears_reservation_and_does_not_mark_the_interval_done(): void
    {
        $manager = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->once()->with('microsoft_devices')->andThrow(new RuntimeException('private transport failure'));
        try {
            $this->scheduler->queue();
            $this->fail('The rejected queue dispatch must not be reported as queued.');
        } catch (RuntimeException $exception) {
            $this->assertSame('private transport failure', $exception->getMessage());
        } finally {
            Queue::swap($manager);
        }

        $this->assertSame(0, DB::table('microsoft_device_runs')->where('kind', 'sync')->count());
        $this->assertTrue($this->scheduler->queue());
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_cache_flush_and_stopped_worker_cannot_duplicate_an_active_job(): void
    {
        $this->assertTrue($this->scheduler->queue());
        $this->assertFalse($this->scheduler->queue(force: true));
        $this->travel(3)->hours();
        Cache::flush();
        $this->assertFalse($this->scheduler->queue(force: true));
        $this->assertFalse($this->scheduler->queue());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertTrue($this->runtime->status()['overdue']);
        $this->assertSame('unknown', $this->runtime->status()['worker']['state']);
    }

    public function test_queued_job_does_not_run_after_the_configuration_changes(): void
    {
        $this->assertTrue($this->scheduler->queue());
        [$job, $databaseJob] = $this->reserveSync();
        $this->settings->save(['client_secret' => 'rotated-secret'], $this->admin);
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldNotReceive('sync');

        $job->handle($this->settings, $sync, $this->runtime);
        $databaseJob->delete();

        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $job->reservation, 'outcome' => 'stale_configuration', 'status' => 'failed']);
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

        $scheduler = new MicrosoftDeviceSyncScheduler($settings, $this->runtime);
        $this->assertFalse($scheduler->queue());
        $this->assertDatabaseCount('jobs', 0);

        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldReceive('snapshot')->once()->andReturn($changed);
        $settings->shouldNotReceive('configuration');
        $settings->shouldNotReceive('fingerprint');
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andReturn(['status' => 'success']);
        $this->assertTrue($this->scheduler->queue());
        [$job] = $this->reserveSync();
        $job->handle($settings, $sync, $this->runtime);
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

        (new MicrosoftDeviceSyncScheduler($settings, $this->runtime))->afterMicrosoftSignIn($this->verifiedIdentity(), $user);

        $this->assertNull($account->fresh()->tenant_id);
        $this->assertDatabaseCount('jobs', 0);
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

    public function test_worker_records_success_and_failure_without_exposing_exception_contents(): void
    {
        $this->assertTrue($this->scheduler->queue());
        [$job, $databaseJob] = $this->reserveSync();
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andReturn(['status' => 'success']);
        $job->handle($this->settings, $sync, $this->runtime);
        $databaseJob->delete();
        $this->assertSame('completed', $this->runtime->status()['run']['status']);
        $this->assertNotNull($this->runtime->status()['run']['finished_at']);
        $this->assertFalse($this->scheduler->queue());
        $this->assertTrue($this->scheduler->queue(force: true));

        [$job, $databaseJob] = $this->reserveSync();
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andThrow(new RuntimeException('private-password-must-not-be-displayed'));
        try {
            $job->handle($this->settings, $sync, $this->runtime);
            $this->fail('Worker errors must remain visible to the queue.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $this->runtime->status()['run']['status']);
            $this->assertSame('failed', $this->runtime->status()['worker']['state']);
            $this->assertStringNotContainsString('private-password', json_encode($this->runtime->status()));
            $databaseJob->delete();
            $this->assertTrue($this->scheduler->queue(force: true));
        }
    }

    public function test_missing_import_schema_blocks_sync_but_not_the_pure_worker_probe(): void
    {
        Schema::drop('microsoft_device_links');
        $status = $this->runtime->status();
        $this->assertFalse($status['schema_ready']);
        $this->assertTrue($status['queue_ready']);
        $this->assertSame('schema_missing', $status['issues'][0]['code']);
        $this->artisan('devices:sync-microsoft --force')->expectsOutputToContain('Importmigration')->assertFailed();
        $this->assertTrue($this->runtime->queueWorkerProbe());
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public function test_a_separate_queue_database_or_short_reservation_cannot_break_atomicity(): void
    {
        config(['queue.connections.microsoft_devices.connection' => 'a-separate-database']);
        $this->assertFalse($this->runtime->status()['queue_ready']);
        $this->artisan('devices:sync-microsoft --force')->assertFailed();
        config(['queue.connections.microsoft_devices.connection' => null, 'queue.connections.microsoft_devices.retry_after' => 240]);
        $this->assertFalse($this->runtime->status()['queue_ready']);
        $this->artisan('devices:sync-microsoft --force')->assertFailed();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_fake_transport_cannot_report_a_durable_job_that_does_not_exist(): void
    {
        $manager = Queue::getFacadeRoot();
        Queue::fake();
        try {
            $this->runtime->queueWorkerProbe();
            $this->fail('No actual database job must mean no successful enqueue.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dauerhaft gespeichert', $exception->getMessage());
        } finally {
            Queue::swap($manager);
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(0, DB::table('microsoft_device_runs')->where('kind', 'probe')->count());
        $this->assertTrue($this->runtime->queueWorkerProbe());
    }

    public function test_removed_jobs_can_be_recovered_without_leaving_an_active_reservation(): void
    {
        $this->assertTrue($this->scheduler->queue());
        $original = DB::table('microsoft_device_runs')->where('kind', 'sync')->sole();
        DB::table('jobs')->where('id', $original->queue_job_id)->delete();
        $this->assertTrue($this->scheduler->queue(force: true));
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $original->id, 'status' => 'failed', 'outcome' => 'queue_lost', 'active_key' => null]);
        $this->assertSame(1, DB::table('microsoft_device_runs')->where('kind', 'sync')->whereNotNull('active_key')->count());
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_rotated_queued_payload_is_invalidated_and_only_its_replacement_can_call_graph(): void
    {
        $this->assertTrue($this->scheduler->queue());
        $original = DB::table('microsoft_device_runs')->where('kind', 'sync')->sole();
        $this->settings->save(['client_secret' => 'rotation-for-stale-queue-test'], $this->admin);
        $this->assertTrue($this->scheduler->queue());
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $original->id, 'status' => 'failed', 'outcome' => 'stale_configuration']);
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('sync')->once()->andReturn(['status' => 'success']);
        $this->app->instance(MicrosoftDeviceSyncService::class, $sync);
        Queue::connection('microsoft_devices')->pop('microsoft_devices')->fire();
        $this->assertSame('queued', $this->runtime->status()['run']['status']);
        Queue::connection('microsoft_devices')->pop('microsoft_devices')->fire();
        $this->assertSame('completed', $this->runtime->status()['run']['status']);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_running_job_is_not_duplicated_after_rotation_or_a_cache_flush(): void
    {
        $this->assertTrue($this->scheduler->queue());
        [$job, $databaseJob] = $this->reserveSync();
        $this->assertTrue($this->runtime->claim($job->reservation, (string) $databaseJob->getJobId(), 'sync', $databaseJob));
        $this->assertSame('busy', $this->runtime->status()['worker']['state']);
        $this->settings->save(['client_secret' => 'rotation-while-running'], $this->admin);
        Cache::flush();
        $this->travel(6)->minutes();
        $this->assertFalse($this->scheduler->queue(force: true));
        $this->assertTrue($this->runtime->status()['overdue']);
        $job->failed(TimeoutExceededException::forJob($databaseJob));
        $databaseJob->delete();
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $job->reservation, 'status' => 'failed', 'outcome' => 'timeout']);
        $this->assertNotNull(DB::table('microsoft_device_runs')->where('id', $job->reservation)->value('finished_at'));
        $this->assertTrue($this->scheduler->queue(force: true));
    }

    public function test_old_unbound_jobs_and_duplicate_claims_cannot_execute_graph(): void
    {
        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldNotReceive('snapshot');
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldNotReceive('sync');
        (new SyncMicrosoftDevices(self::TENANT, 'old-fingerprint', 'old-cache-reservation'))->handle($settings, $sync, $this->runtime);

        $this->assertTrue($this->scheduler->queue());
        [$job, $databaseJob] = $this->reserveSync();
        $this->assertTrue($this->runtime->claim($job->reservation, (string) $databaseJob->getJobId(), 'sync', $databaseJob));
        $job->handle($settings, $sync, $this->runtime);
        $this->assertFalse($this->runtime->claim($job->reservation, (string) $databaseJob->getJobId(), 'sync', $databaseJob));
        Http::assertNothingSent();
    }

    public function test_worker_probe_is_durable_without_microsoft_configuration_and_only_worker_acknowledges_it(): void
    {
        DB::table('settings')->where('type', MicrosoftDeviceSettings::GROUP)->where('key', MicrosoftDeviceSettings::KEY)->delete();
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldNotReceive('sync');
        $sync->shouldNotReceive('probe');
        $this->app->instance(MicrosoftDeviceSyncService::class, $sync);

        $this->assertTrue($this->runtime->queueWorkerProbe());
        $this->assertFalse($this->runtime->queueWorkerProbe());
        $run = DB::table('microsoft_device_runs')->where('kind', 'probe')->sole();
        $this->assertNull($run->tenant_id);
        $this->assertNull($run->configuration_fingerprint);
        (new ProbeMicrosoftDeviceWorker($run->id))->handle($this->runtime);
        $this->assertFalse($this->runtime->claim($run->id, (string) $run->queue_job_id, 'probe'));
        $this->assertSame('queued', $this->runtime->status()['worker_probe']['status']);
        $this->assertNull($this->runtime->status()['worker_probe']['acknowledged_at']);
        $this->assertSame('unknown', $this->runtime->status()['worker']['state']);

        $this->artisan('queue:work microsoft_devices --queue=microsoft_devices --once --sleep=0 --timeout=30 --tries=1')->assertSuccessful();
        $status = $this->runtime->status();
        $this->assertSame('completed', $status['worker_probe']['status']);
        $this->assertNotNull($status['worker_probe']['acknowledged_at']);
        $this->assertSame('seen', $status['worker']['state']);
        $this->assertSame('unknown', $status['scheduler']['state']);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_scheduler_evidence_is_separate_from_manual_dispatch_and_expires(): void
    {
        $this->artisan('devices:sync-microsoft --force')->assertSuccessful();
        $this->assertSame('unknown', $this->runtime->status()['scheduler']['state']);
        $this->settings->save(['enabled' => false], $this->admin);
        $this->artisan('devices:sync-microsoft --scheduled')->assertSuccessful();
        $this->assertSame('fresh', $this->runtime->status()['scheduler']['state']);
        $this->assertNotNull($this->runtime->status()['scheduler']['checked_at']);
        $this->travel(11)->minutes();
        $this->assertSame('stale', $this->runtime->status()['scheduler']['state']);
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'devices:sync-microsoft'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--scheduled', $event->command);
        $this->assertSame(5, $event->expiresAt);
    }

    public function test_historical_run_is_not_presented_as_success_for_a_changed_configuration(): void
    {
        $this->assertTrue($this->scheduler->queue());
        [$job, $databaseJob] = $this->reserveSync();
        $this->assertTrue($this->runtime->claim($job->reservation, (string) $databaseJob->getJobId(), 'sync', $databaseJob));
        $this->runtime->finish($job->reservation);
        $databaseJob->delete();
        $this->assertSame('completed', $this->runtime->status()['run']['status']);
        $snapshot = $this->settings->snapshot();
        $this->settings->save(['tenant_id' => self::OTHER_TENANT, 'client_secret' => 'another-tenant-secret'], $this->admin);
        $this->assertSame([], $this->runtime->status()['run']);
        $this->assertFalse($this->runtime->queueSync($snapshot, force: true));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_an_existing_default_database_worker_cannot_acknowledge_or_run_microsoft_jobs(): void
    {
        $this->assertTrue($this->runtime->queueWorkerProbe());
        $wrongWorkerJob = Queue::connection('database')->pop('microsoft_devices');
        $this->assertInstanceOf(DatabaseJob::class, $wrongWorkerJob);
        $this->assertSame('database', $wrongWorkerJob->getConnectionName());
        $probe = unserialize($wrongWorkerJob->payload()['data']['command']);
        $probe->setJob($wrongWorkerJob);
        $probe->handle($this->runtime);
        $this->assertFalse($this->runtime->claim($probe->runId, (string) $wrongWorkerJob->getJobId(), 'probe', $wrongWorkerJob));
        $this->assertSame('queued', $this->runtime->status()['worker_probe']['status']);
        $this->assertNull($this->runtime->status()['worker_probe']['acknowledged_at']);
        $this->assertSame('unknown', $this->runtime->status()['worker']['state']);
        $wrongWorkerJob->delete();

        $this->assertTrue($this->scheduler->queue());
        $wrongWorkerJob = Queue::connection('database')->pop('microsoft_devices');
        $job = unserialize($wrongWorkerJob->payload()['data']['command']);
        $job->setJob($wrongWorkerJob);
        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldNotReceive('snapshot');
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldNotReceive('sync');
        $job->handle($settings, $sync, $this->runtime);
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $job->reservation, 'status' => 'queued', 'started_at' => null]);
        Http::assertNothingSent();
    }

    public function test_lost_probe_is_read_only_projected_as_failed_but_an_overdue_real_job_stays_deduplicated(): void
    {
        $this->assertTrue($this->runtime->queueWorkerProbe());
        $original = DB::table('microsoft_device_runs')->where('kind', 'probe')->sole();
        $this->travel(3)->minutes();
        $this->assertSame('queued', $this->runtime->status()['worker_probe']['status']);
        $this->assertTrue($this->runtime->status()['overdue']);
        $this->assertFalse($this->runtime->queueWorkerProbe());
        $this->assertDatabaseCount('jobs', 1);

        DB::table('jobs')->where('id', $original->queue_job_id)->delete();
        $status = $this->runtime->status();
        $this->assertSame('failed', $status['worker_probe']['status']);
        $this->assertSame('queue_lost', $status['worker_probe']['outcome']);
        $this->assertContains('queue_lost', array_column($status['issues'], 'code'));
        $this->assertNull($status['worker_probe']['acknowledged_at']);
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $original->id, 'status' => 'queued', 'finished_at' => null]);
        $this->assertTrue($this->runtime->queueWorkerProbe());
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $original->id, 'status' => 'failed', 'outcome' => 'queue_lost']);
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    /** @return array{SyncMicrosoftDevices, DatabaseJob} */
    private function reserveSync(): array
    {
        $databaseJob = Queue::connection('microsoft_devices')->pop('microsoft_devices');
        $this->assertInstanceOf(DatabaseJob::class, $databaseJob);
        $job = unserialize($databaseJob->payload()['data']['command']);
        $this->assertInstanceOf(SyncMicrosoftDevices::class, $job);
        $job->setJob($databaseJob);

        return [$job, $databaseJob];
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
