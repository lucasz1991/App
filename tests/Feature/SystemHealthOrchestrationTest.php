<?php

namespace Tests\Feature;

use App\Jobs\ProbeSystemHealthWorker;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\SystemHealth\BoundedInfrastructureConnections;
use App\Services\SystemHealth\DeviceChecks;
use App\Services\SystemHealth\InfrastructureChecks;
use App\Services\SystemHealth\IntegrationChecks;
use App\Services\SystemHealth\QueueChecks;
use App\Services\SystemHealth\SystemCheckRegistry;
use App\Services\SystemHealth\SystemHealthService;
use App\Services\SystemHealth\SystemHealthStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class SystemHealthOrchestrationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private string $directory;

    private string $fingerprint = 'configuration-one';

    private SystemCheckRegistry $registry;

    private SystemHealthStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        $this->directory = storage_path('framework/testing/system-health-'.Str::uuid());
        config(['system_health.path' => $this->directory]);
        $this->store = new SystemHealthStore;
        $this->app->instance(SystemHealthStore::class, $this->store);
        $this->registry = Mockery::mock(SystemCheckRegistry::class)->makePartial();
        $this->registry->shouldReceive('fingerprint')->andReturnUsing(fn () => $this->fingerprint);
    }

    protected function tearDown(): void
    {
        if (str_starts_with($this->directory, storage_path('framework/testing/system-health-'))) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    private function service(?InfrastructureChecks $infrastructure = null, ?SystemHealthStore $store = null, ?QueueChecks $queues = null): SystemHealthService
    {
        return new SystemHealthService($this->registry, $store ?? $this->store,
            $infrastructure ?? Mockery::mock(InfrastructureChecks::class),
            Mockery::mock(IntegrationChecks::class), Mockery::mock(DeviceChecks::class),
            $queues ?? new QueueChecks($store ?? $this->store));
    }

    private function ok(): array
    {
        return ['status' => 'ok', 'evidence' => 'runtime', 'message' => 'Isolierte Probe bestätigt.', 'details' => []];
    }

    public function test_snapshot_never_runs_checks_and_first_activation_runs_once(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->once()->with('cache')->andReturn($this->ok());
        $service = $this->service($handler);
        $this->assertFalse(collect($service->snapshot())->firstWhere('id', 'cache')['fresh']);
        $first = $service->check('cache');
        $cached = $service->check('cache');
        $this->assertSame('live', $first['source']);
        $this->assertSame('cache', $cached['source']);
        $this->assertSame($first['checked_at'], $cached['checked_at']);
        $this->assertSame($first['run_id'], $cached['run_id']);
    }

    public function test_fifteen_minute_expiry_runs_only_on_next_check(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->twice()->andReturn($this->ok());
        $service = $this->service($handler);
        $first = $service->check('cache');
        $this->travel(899)->seconds();
        $this->assertTrue($service->check('cache')['fresh']);
        $this->travel(1)->seconds();
        $snapshot = collect($service->snapshot())->firstWhere('id', 'cache');
        $this->assertFalse($snapshot['fresh']);
        $this->assertSame($first['checked_at'], $snapshot['checked_at']);
        $this->assertNotSame($first['run_id'], $service->check('cache')['run_id']);
    }

    public function test_explicit_single_check_bypasses_cache(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->twice()->andReturn($this->ok());
        $service = $this->service($handler);
        $first = $service->check('cache');
        $this->assertNotSame($first['run_id'], $service->check('cache', true)['run_id']);
    }

    public function test_changed_configuration_invalidates_without_running_on_snapshot(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->twice()->andReturn($this->ok());
        $service = $this->service($handler);
        $first = $service->check('cache');
        $this->fingerprint = 'configuration-two';
        $row = collect($service->snapshot())->firstWhere('id', 'cache');
        $this->assertFalse($row['fresh']);
        $this->assertSame('not_checked', $row['status']);
        $this->assertNotSame($first['run_id'], $service->check('cache')['run_id']);
    }

    public function test_in_flight_tab_does_not_run_duplicate_or_force_overwrite(): void
    {
        $service = $this->service();
        $row = collect($service->snapshot())->firstWhere('id', 'cache');
        $row = array_replace($row, ['status' => 'running', 'pending' => true, 'run_id' => (string) Str::uuid(), 'checked_at' => now()->toIso8601String()]);
        $this->store->put('result:cache', ['row' => $row, 'fingerprint' => $this->fingerprint, 'deadline' => now()->timestamp + 120]);
        $lock = $this->store->lock('check:cache');
        $this->assertTrue($lock->get());
        try {
            $this->assertSame($row['run_id'], $service->check('cache', true)['run_id']);
        } finally {
            $lock->release();
        }
    }

    public function test_expired_run_can_recover_and_late_old_response_cannot_overwrite_new_run(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $newId = (string) Str::uuid();
        $handler->shouldReceive('run')->once()->andReturnUsing(function () use ($newId) {
            $record = $this->store->get('result:cache');
            $record['row']['run_id'] = $newId;
            $record['row']['message'] = 'Neuerer Lauf';
            $this->store->put('result:cache', $record);

            return $this->ok();
        });
        $row = $this->service($handler)->check('cache');
        $this->assertSame($newId, $row['run_id']);
        $this->assertSame('Neuerer Lauf', $row['message']);
    }

    public function test_exception_text_is_never_exposed_and_other_checks_continue(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->with('database')->andThrow(new RuntimeException('password=SENSITIVE provider response'));
        $handler->shouldReceive('run')->with('cache')->andReturn($this->ok());
        $service = $this->service($handler);
        $failure = $service->check('database');
        $this->assertSame('error', $failure['status']);
        $this->assertStringNotContainsString('SENSITIVE', json_encode($failure));
        $this->assertSame('ok', $service->check('cache')['status']);
    }

    public function test_broken_store_allows_stateless_checks_but_never_dispatches_probe_jobs(): void
    {
        $broken = Mockery::mock(SystemHealthStore::class);
        $broken->shouldReceive('assertWritable')->andThrow(new RuntimeException('secret path'));
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->once()->with('cache')->andReturn($this->ok());
        Queue::fake();
        $service = $this->service($handler, $broken);
        $result = $service->check('cache');
        $this->assertSame('warning', $result['status']);
        $this->assertFalse($result['fresh']);
        $this->assertSame('error', $service->check('queue_default')['status']);
        Queue::assertNothingPushed();
    }

    public function test_registry_rejects_arbitrary_commands_before_any_handler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->check('https://127.0.0.1/secret');
    }

    public function test_sync_driver_never_claims_worker_processing(): void
    {
        Queue::fake();
        $row = $this->service()->check('queue_default');
        $this->assertSame('warning', $row['status']);
        $this->assertFalse($row['pending']);
        $this->assertSame('configuration', $row['evidence']);
        Queue::assertNothingPushed();
    }

    private function databaseQueue(): void
    {
        config(['queue.default' => 'database']);
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function test_only_actual_database_worker_can_acknowledge_and_poll_is_read_only(): void
    {
        $this->databaseQueue();
        $service = $this->service();
        $row = $service->check('queue_default');
        $this->assertTrue($row['pending']);
        $this->assertSame('configuration', $row['evidence']);
        $this->assertSame(1, DB::table('jobs')->count());
        $record = $this->store->get('result:queue_default');
        $probe = $this->store->get($record['probe_key']);
        (new ProbeSystemHealthWorker($record['probe_key'], $probe['nonce'], 'database', 'default'))->handle($this->store);
        $this->assertTrue($service->poll('queue_default', $row['run_id'])['pending']);
        $this->assertSame(1, DB::table('jobs')->count());
        $job = Queue::connection('database')->pop('default');
        $job->fire();
        $completed = $service->poll('queue_default', $row['run_id']);
        $this->assertFalse($completed['pending']);
        $this->assertSame('ok', $completed['status']);
        $this->assertSame('runtime', $completed['evidence']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_multiple_tabs_and_same_queue_combination_share_pending_probe(): void
    {
        $this->databaseQueue();
        $service = $this->service();
        $first = $service->check('queue_default');
        $this->assertSame($first['run_id'], $service->check('queue_default', true)['run_id']);
        $this->assertTrue($service->check('queue_marketing')['pending']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_no_receipt_after_120_seconds_is_not_declared_worker_failure(): void
    {
        $this->databaseQueue();
        $service = $this->service();
        $row = $service->check('queue_default');
        $this->travel(121)->seconds();
        $timeout = $service->poll('queue_default', $row['run_id']);
        $this->assertSame('warning', $timeout['status']);
        $this->assertFalse($timeout['pending']);
        Queue::connection('database')->pop('default')->fire();
        $this->assertSame('warning', $service->poll('queue_default', $row['run_id'])['status']);
    }

    public function test_late_poll_for_old_uuid_never_updates_new_result(): void
    {
        $handler = Mockery::mock(InfrastructureChecks::class);
        $handler->shouldReceive('run')->twice()->andReturn($this->ok());
        $service = $this->service($handler);
        $old = $service->check('cache');
        $new = $service->check('cache', true);
        $this->assertSame($new['run_id'], $service->poll('cache', $old['run_id'])['run_id']);
    }

    public function test_default_application_cache_is_not_the_diagnostic_store(): void
    {
        config(['cache.default' => 'nonexistent-store']);
        $this->store->assertWritable();
        $this->store->put('preserved', ['value' => 'receipt']);
        $this->assertSame(['value' => 'receipt'], (new SystemHealthStore)->get('preserved'));
    }

    public function test_atomic_state_write_rejects_old_run_even_after_probe_lock_expired(): void
    {
        $this->store->putResult('cache', ['row' => ['run_id' => 'new'], 'message' => 'new']);
        $this->assertFalse($this->store->putResult('cache', ['row' => ['run_id' => 'old']], 'old'));
        $this->assertSame('new', $this->store->get('result:cache')['message']);
    }

    public function test_database_change_cannot_reuse_previous_queue_receipt(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $first = $queues->start('queue_default', false);
        Queue::connection('database')->pop('default')->fire();
        $this->assertSame('ok', $queues->observe($first['_probe_key'])['status']);
        config(['database.connections.sqlite.password' => 'different-private-credential']);
        $second = $queues->start('queue_default', false);
        $this->assertSame('running', $second['status']);
        $this->assertNotSame($first['_probe_key'], $second['_probe_key']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_queue_receipt_has_original_timestamp_when_another_check_reuses_it(): void
    {
        $this->databaseQueue();
        $service = $this->service();
        $first = $service->check('queue_default');
        Queue::connection('database')->pop('default')->fire();
        $ack = $service->poll('queue_default', $first['run_id']);
        $this->travel(30)->seconds();
        $reused = $service->check('queue_marketing');
        $this->assertSame('ok', $reused['status']);
        $this->assertSame($ack['checked_at'], $reused['checked_at']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_receipt_for_deleted_or_unreserved_job_is_not_accepted(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $first = $queues->start('queue_default', false);
        $job = Queue::connection('database')->pop('default');
        DB::table('jobs')->where('id', $job->getJobId())->update(['reserved_at' => null]);
        $job->fire();
        $this->assertSame('running', $queues->observe($first['_probe_key'])['status']);
    }

    public function test_receipt_at_exact_deadline_is_not_accepted(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $first = $queues->start('queue_default', false);
        $this->travel(120)->seconds();
        Queue::connection('database')->pop('default')->fire();
        $this->assertSame('warning', $queues->observe($first['_probe_key'])['status']);
    }

    public function test_credential_change_invalidates_fingerprint_without_exposing_values(): void
    {
        $registry = new SystemCheckRegistry;
        $first = $registry->fingerprint('mail');
        config(['mail.mailers.smtp.password' => 'never-public-secret']);
        $second = $registry->fingerprint('mail');
        $this->assertNotSame($first, $second);
        $this->assertSame(64, strlen($second));
        $this->assertStringNotContainsString('never-public-secret', $second);
    }

    public function test_expired_pending_probe_is_not_duplicated_even_when_forced(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $queues->start('queue_default', false);
        $this->travel(901)->seconds();
        $result = $queues->start('queue_default', true);
        $this->assertSame('warning', $result['status']);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('kein weiterer Probejob', $result['message']);
    }

    public function test_expired_worker_receipt_is_replaced_by_new_probe_on_next_activation(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $queues->start('queue_default', false);
        Queue::connection('database')->pop('default')->fire();
        $this->travel(900)->seconds();
        $result = $queues->start('queue_default', false);
        $this->assertSame('running', $result['status']);
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_unsupported_queue_database_creates_no_probe_or_job(): void
    {
        $this->databaseQueue();
        config(['queue.connections.database.connection' => 'unsupported_health', 'database.connections.unsupported_health' => ['driver' => 'pgsql']]);
        $target = ['connection' => 'database', 'queue' => 'default'];
        $configuration = config('queue.connections.database');
        $key = 'queue-probe:'.hash('sha256', serialize([$target, $configuration, config('database.connections.unsupported_health')]));
        Queue::shouldReceive('connection')->never();

        $result = (new QueueChecks($this->store))->start('queue_default', false);

        $this->assertSame('not_checked', $result['status']);
        $this->assertNull($this->store->get($key));
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_dispatch_and_receipt_use_bounded_connection_without_resolving_shared_queue(): void
    {
        $this->databaseQueue();
        $normalWorkerQueue = Queue::connection('database');
        $database = DB::connection();
        $helper = Mockery::mock(BoundedInfrastructureConnections::class);
        $helper->shouldReceive('database')->twice()->with(null, Mockery::type(\Closure::class))
            ->andReturnUsing(fn ($name, $callback) => $callback($database));
        $this->app->instance(BoundedInfrastructureConnections::class, $helper);
        Queue::shouldReceive('connection')->never();
        $queues = new QueueChecks($this->store);

        $pending = $queues->start('queue_default', false);
        $probe = $this->store->get($pending['_probe_key']);
        $this->assertSame('running', $pending['status']);
        $this->assertSame((string) DB::table('jobs')->value('id'), $probe['job_id']);
        $job = $normalWorkerQueue->pop('default');
        $this->assertSame('database', $job->getConnectionName());
        $job->fire();

        $this->assertSame('ok', $queues->observe($pending['_probe_key'])['status']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_unsupported_receipt_transport_cannot_acknowledge_real_reserved_job(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $pending = $queues->start('queue_default', false);
        $job = Queue::connection('database')->pop('default');
        $helper = Mockery::mock(BoundedInfrastructureConnections::class);
        $helper->shouldReceive('database')->once()->andReturnNull();
        $this->app->instance(BoundedInfrastructureConnections::class, $helper);

        $job->fire();

        $this->assertNull($this->store->get($pending['_probe_key'])['acknowledged_at']);
        $this->assertSame('running', $queues->observe($pending['_probe_key'])['status']);
    }

    public function test_probe_insert_rolls_back_when_receipt_cannot_be_stored(): void
    {
        $this->databaseQueue();
        $broken = Mockery::mock(SystemHealthStore::class, [])->makePartial();
        $broken->shouldReceive('put')->once()->andThrow(new RuntimeException('synthetic receipt write failure'));
        try {
            (new QueueChecks($broken))->start('queue_default', false);
            $this->fail('A receipt write failure must abort the isolated queue insertion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic receipt write failure', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_slow_reservation_check_crossing_deadline_does_not_acknowledge(): void
    {
        $this->databaseQueue();
        $queues = new QueueChecks($this->store);
        $pending = $queues->start('queue_default', false);
        $job = Queue::connection('database')->pop('default');
        $helper = Mockery::mock(BoundedInfrastructureConnections::class);
        $helper->shouldReceive('database')->once()->andReturnUsing(function () {
            $this->travel(120)->seconds();

            return true;
        });
        $this->app->instance(BoundedInfrastructureConnections::class, $helper);

        $job->fire();

        $this->assertNull($this->store->get($pending['_probe_key'])['acknowledged_at']);
        $this->assertSame('warning', $queues->observe($pending['_probe_key'])['status']);
    }

    public function test_named_microsoft_worker_consumes_probe_without_changing_after_commit_defaults(): void
    {
        $this->databaseQueue();
        $configuration = config('queue.connections.microsoft_devices');
        $settings = Mockery::mock(MicrosoftDeviceSettings::class);
        $settings->shouldReceive('configuration')->once()->andReturn(['enabled' => true]);
        $this->app->instance(MicrosoftDeviceSettings::class, $settings);
        $queues = new QueueChecks($this->store);

        $pending = $queues->start('queue_microsoft', false);
        $this->assertSame('running', $pending['status']);
        $this->assertSame('microsoft_devices', DB::table('jobs')->value('queue'));
        $job = Queue::connection('microsoft_devices')->pop('microsoft_devices');
        $this->assertSame('microsoft_devices', $job->getConnectionName());
        $job->fire();

        $this->assertSame('ok', $queues->observe($pending['_probe_key'])['status']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame($configuration, config('queue.connections.microsoft_devices'));
        $this->assertTrue(config('queue.connections.microsoft_devices.after_commit'));
    }
}
