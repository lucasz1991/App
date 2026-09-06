<?php

namespace Tests\Feature;

use App\Livewire\Devices\DeviceManagement;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

final class MicrosoftDeviceOperationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_status_is_read_only_and_does_not_confuse_infrastructure_with_microsoft_setup(): void
    {
        $this->assertSame(0, Artisan::call('devices:microsoft-status', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($report['schema_ready']);
        $this->assertTrue($report['queue_ready']);
        $this->assertFalse($report['microsoft_configured']);
        $this->assertFalse($report['sync_enabled']);
        $this->assertSame('unknown', $report['worker']['state']);
        $this->assertNull($report['probe_queued']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('microsoft_device_runs', 0);
        Http::assertNothingSent();
    }

    public function test_cli_worker_probe_is_persisted_but_not_acknowledged_inline(): void
    {
        $this->assertSame(0, Artisan::call('devices:microsoft-status', ['--json' => true, '--probe-worker' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($report['probe_queued']);
        $this->assertFalse($report['microsoft_configured']);
        $this->assertSame('queued', $report['worker_probe']['status']);
        $this->assertNull($report['worker_probe']['acknowledged_at']);
        $this->assertDatabaseHas('jobs', ['queue' => 'microsoft_devices', 'reserved_at' => null]);
        $this->assertSame(1, DB::table('microsoft_device_runs')->where('kind', 'probe')->count());
        Http::assertNothingSent();
    }

    public function test_missing_migration_is_an_explicit_failed_status_without_writes(): void
    {
        Schema::drop('microsoft_device_runs');
        $this->assertSame(1, Artisan::call('devices:microsoft-status', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($report['schema_ready']);
        $this->assertFalse($report['queue_ready']);
        $this->assertContains('schema_missing', array_column($report['issues'], 'code'));
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_status_does_not_expose_raw_infrastructure_errors(): void
    {
        $this->mock(MicrosoftDeviceRuntime::class)->shouldReceive('status')
            ->once()->andThrow(new RuntimeException('credential-and-private-host-detail'));

        $this->assertSame(1, Artisan::call('devices:microsoft-status', ['--json' => true]));
        $this->assertSame(['error' => 'microsoft_runtime_unavailable'], json_decode(Artisan::output(), true));
        $this->assertStringNotContainsString('credential-and-private-host-detail', Artisan::output());
    }

    public function test_device_list_watches_a_real_run_and_stops_at_completion(): void
    {
        $admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $run = ['id' => 'test-run', 'status' => 'queued', 'message' => 'Wartet auf Worker.'];
        // Keep a mutable reference across the Livewire request/render cycle.
        $runtime = $this->mock(MicrosoftDeviceRuntime::class);
        $runtime->shouldReceive('status')->andReturnUsing(function () use (&$run) {
            return ['run' => $run];
        });
        $runtime->shouldReceive('queueSync')->once()->andReturnTrue();
        $this->configureMicrosoft($admin);

        $view = Livewire::actingAs($admin)->test(DeviceManagement::class)
            ->call('syncMicrosoftDevices')->assertHasNoErrors()
            ->assertSet('microsoftWatchedRunId', 'test-run')
            ->assertSet('microsoftPollsRemaining', 60)
            ->assertSee('Wartet auf Worker.');
        $view->call('refreshMicrosoftSync')->assertSet('microsoftPollsRemaining', 59);
        $run['status'] = 'completed';
        $run['message'] = 'Abgeschlossen.';
        $view->call('refreshMicrosoftSync')->assertSet('microsoftPollsRemaining', 0)
            ->assertSee('Abgeschlossen.');
        Http::assertNothingSent();
    }

    public function test_device_list_polling_is_bounded_and_does_not_follow_another_configuration(): void
    {
        $admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $run = ['id' => 'first-run', 'status' => 'queued', 'message' => 'Wartet.'];
        $runtime = $this->mock(MicrosoftDeviceRuntime::class);
        $runtime->shouldReceive('status')->andReturnUsing(function () use (&$run) {
            return ['run' => $run];
        });
        $runtime->shouldReceive('queueSync')->twice()->andReturnFalse();
        $this->configureMicrosoft($admin);
        $view = Livewire::actingAs($admin)->test(DeviceManagement::class)->call('syncMicrosoftDevices');
        $component = $view->instance();
        $component->microsoftPollsRemaining = 1;
        $component->refreshMicrosoftSync();
        $this->assertSame(0, $component->microsoftPollsRemaining);
        $component->refreshMicrosoftSync();
        $this->assertSame(0, $component->microsoftPollsRemaining);
        $component->syncMicrosoftDevices(app(MicrosoftDeviceSyncScheduler::class));
        $this->assertSame(60, $component->microsoftPollsRemaining);
        $run['id'] = 'another-run';
        $component->refreshMicrosoftSync();
        $this->assertSame(0, $component->microsoftPollsRemaining);
    }

    private function configureMicrosoft(User $admin): void
    {
        app(MicrosoftDeviceSettings::class)->save([
            'enabled' => true,
            'tenant_id' => '11111111-1111-4111-8111-111111111111',
            'client_id' => '22222222-2222-4222-8222-222222222222',
            'client_secret' => 'test-only-not-a-real-credential',
        ], $admin);
    }
}
