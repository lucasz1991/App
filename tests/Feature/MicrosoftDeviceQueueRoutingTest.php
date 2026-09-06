<?php

namespace Tests\Feature;

use App\Console\Queue\MicrosoftDeviceWorkCommand;
use App\Jobs\SyncMicrosoftDevices;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class MicrosoftDeviceQueueRoutingTest extends TestCase
{
    public function test_framework_command_resolution_preserves_the_extender_after_deferred_provider_registration(): void
    {
        $command = $this->app->make(WorkCommand::class);

        $this->assertInstanceOf(MicrosoftDeviceWorkCommand::class, $command);
        $this->assertSame('queue:work', $command->getName());
        $this->assertSame($command, $this->app->make(WorkCommand::class));
        $this->assertSame(90, config('queue.connections.database.retry_after'));
        $this->assertSame(300, config('queue.connections.microsoft_devices.retry_after'));
    }

    #[DataProvider('validWorkers')]
    public function test_only_the_isolated_microsoft_queue_is_routed(array $arguments, string $connection, string $queue): void
    {
        config(['queue.default' => 'database']);
        $before = config('queue');
        $worker = Mockery::mock(Worker::class);
        $worker->shouldReceive('setName')->once()->with('queue-worker-pilot-0')->andReturnSelf();
        $worker->shouldReceive('setCache')->once()->with($this->app['cache.store'])->andReturnSelf();
        $worker->shouldReceive('daemon')->once()->with(
            $connection,
            $queue,
            Mockery::on(fn ($options) => $options instanceof WorkerOptions
                && $options->timeout === 240 && $options->maxTime === 3600),
        )->andReturn(0);

        $tester = $this->tester($worker);
        $exitCode = $tester->execute($arguments + [
            '--name' => 'queue-worker-pilot-0',
            '--timeout' => 240,
            '--max-time' => 3600,
        ], ['interactive' => false]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, config('queue'));
    }

    public static function validWorkers(): array
    {
        return [
            'Plesk Microsoft worker' => [['--queue' => 'microsoft-devices'], 'microsoft_devices', 'microsoft-devices'],
            'explicit Microsoft worker' => [['connection' => 'microsoft_devices', '--queue' => 'microsoft-devices'], 'microsoft_devices', 'microsoft-devices'],
            'explicit connection default' => [['connection' => 'microsoft_devices'], 'microsoft_devices', 'microsoft-devices'],
            'legacy default worker' => [[], 'database', 'default'],
            'existing combined worker' => [['--queue' => 'default,calls,devices'], 'database', 'default,calls,devices'],
            'calls worker' => [['--queue' => 'calls'], 'database', 'calls'],
            'device worker' => [['--queue' => 'devices'], 'database', 'devices'],
            'explicit other connection' => [['connection' => 'redis', '--queue' => 'default,calls'], 'redis', 'default,calls'],
        ];
    }

    #[DataProvider('invalidWorkers')]
    public function test_wrong_connections_and_mixed_microsoft_queues_fail_before_worker_start(array $arguments): void
    {
        config(['queue.default' => 'database']);
        $before = config('queue');
        $worker = Mockery::mock(Worker::class);
        $worker->shouldNotReceive('setName');
        $worker->shouldNotReceive('daemon');
        $worker->shouldNotReceive('runNextJob');

        $tester = $this->tester($worker);
        $exitCode = $tester->execute($arguments, ['interactive' => false]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Microsoft-Geraetequeue', $tester->getDisplay());
        $this->assertSame($before, config('queue'));
    }

    public static function invalidWorkers(): array
    {
        return [
            'wrong database connection' => [['connection' => 'database', '--queue' => 'microsoft-devices']],
            'wrong Redis connection' => [['connection' => 'redis', '--queue' => 'microsoft-devices']],
            'mixed default queue' => [['--queue' => 'microsoft-devices,default']],
            'mixed calls queue' => [['--queue' => 'calls,microsoft-devices']],
            'mixed and spaced' => [['--queue' => 'default, microsoft-devices']],
            'duplicate Microsoft queue' => [['--queue' => 'microsoft-devices,microsoft-devices']],
            'trailing separator' => [['--queue' => 'microsoft-devices,']],
            'ambiguous whitespace' => [['--queue' => ' microsoft-devices ']],
            'wrong queue on dedicated connection' => [['connection' => 'microsoft_devices', '--queue' => 'devices']],
        ];
    }

    public function test_job_keeps_its_dedicated_connection_and_timeout_below_retry_after(): void
    {
        $jobDefaults = (new \ReflectionClass(SyncMicrosoftDevices::class))->getDefaultProperties();

        $this->assertSame('microsoft_devices', SyncMicrosoftDevices::CONNECTION);
        $this->assertSame('microsoft-devices', SyncMicrosoftDevices::QUEUE);
        $this->assertLessThan(config('queue.connections.microsoft_devices.retry_after'), $jobDefaults['timeout']);
    }

    private function tester(Worker $worker): CommandTester
    {
        $command = new MicrosoftDeviceWorkCommand($worker, $this->app['cache.store']);
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}
