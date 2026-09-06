<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftGraphDeviceException;
use App\Services\SystemHealth\DeviceChecks;
use App\Services\SystemHealth\SystemCheckRegistry;
use App\Services\SystemHealth\SystemHealthService;
use App\Services\SystemHealth\SystemHealthStore;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SystemHealthDeviceChecksTest extends TestCase
{
    use DatabaseMigrations;

    private const TENANT = '11111111-1111-4111-8111-111111111111';

    private const CLIENT = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    #[DataProvider('intuneModes')]
    public function test_graph_probe_is_top_one_bounded_read_only_and_does_not_follow_paging(bool $intune): void
    {
        $this->microsoftConfiguration(intune: $intune);
        $before = $this->businessSnapshot();
        $this->mock(MicrosoftDeviceRuntime::class)->shouldNotReceive('queueSync', 'queueWorkerProbe', 'recordSchedulerTick', 'finish');
        Http::fake(function (Request $request, array $options) {
            $this->assertLessThanOrEqual(5, $options['timeout']);
            $this->assertLessThanOrEqual(2, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame('', $options['proxy']);
            $this->assertIsCallable($options['progress']);
            $options['progress'](65536, 65536);
            try {
                $options['progress'](65537, 65537);
                $this->fail('The short probe must stop oversized transfers.');
            } catch (MicrosoftGraphDeviceException $exception) {
                $this->assertSame('response_limit', $exception->reason);
            }
            if (str_starts_with($request->url(), 'https://login.microsoftonline.com/')) {
                $this->assertSame('POST', $request->method());
                $this->assertSame('https://graph.microsoft.com/.default', $request['scope']);

                return Http::response(['access_token' => 'fixture-access-token', 'token_type' => 'Bearer']);
            }
            $this->assertSame('GET', $request->method());
            $this->assertSame('graph.microsoft.com', parse_url($request->url(), PHP_URL_HOST));
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->assertSame('1', $query['$top']);
            $this->assertContains(parse_url($request->url(), PHP_URL_PATH), ['/v1.0/devices', '/v1.0/deviceManagement/managedDevices']);

            return Http::response([
                'value' => [['id' => '33333333-3333-4333-8333-333333333333', 'displayName' => 'PRIVATE DEVICE NAME']],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/devices?$skiptoken=never-follow',
            ]);
        });

        $result = app(DeviceChecks::class)->run('microsoft');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        Http::assertSentCount($intune ? 3 : 2);
        $this->assertSame($before, $this->businessSnapshot());
        foreach (['PRIVATE DEVICE NAME', 'fixture-access-token', 'fixture-client-secret', self::TENANT] as $private) {
            $this->assertStringNotContainsString($private, json_encode($result));
        }
    }

    public static function intuneModes(): array
    {
        return [[false], [true]];
    }

    public function test_disabled_microsoft_and_unconfigured_microsoft_never_request_tokens(): void
    {
        $this->microsoftConfiguration(enabled: false);
        $before = $this->businessSnapshot();
        $this->assertSame('disabled', app(DeviceChecks::class)->run('microsoft')['status']);
        $this->assertSame($before, $this->businessSnapshot());
        Setting::setValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY, ['enabled' => true, 'tenant_id' => self::TENANT, 'client_id' => self::CLIENT, 'client_secret' => 'enc:v1:broken']);
        $this->assertSame('disabled', app(DeviceChecks::class)->run('microsoft')['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_short_graph_probe_rejects_an_oversized_body_even_when_http_fake_skips_progress_callback(): void
    {
        $this->microsoftConfiguration();
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'fixture-access-token', 'token_type' => 'Bearer']),
            'https://graph.microsoft.com/*' => Http::response(['value' => [['displayName' => str_repeat('x', 65537)]]]),
        ]);
        $before = $this->businessSnapshot();
        $this->assertSame('error', app(DeviceChecks::class)->run('microsoft')['status']);
        $this->assertSame($before, $this->businessSnapshot());
    }

    #[DataProvider('graphFailures')]
    public function test_graph_failures_are_safe_fixed_messages_without_upstream_data(int $status, string $message): void
    {
        $this->microsoftConfiguration();
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'fixture-access-token', 'token_type' => 'Bearer']),
            'https://graph.microsoft.com/*' => Http::response(['error' => ['message' => 'PRIVATE DEVICE credential=fixture-client-secret internal://database']], $status),
        ]);
        $before = $this->businessSnapshot();
        $result = app(DeviceChecks::class)->run('microsoft');
        $this->assertSame('error', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        $this->assertStringContainsString($message, $result['message']);
        foreach (['PRIVATE DEVICE', 'fixture-client-secret', 'internal://database'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($result));
        }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public static function graphFailures(): array
    {
        return [[401, 'Anmeldung abgelehnt'], [403, 'Leserechte'], [429, 'begrenzt'], [503, 'Leseprobe']];
    }

    #[DataProvider('runtimeStates')]
    public function test_only_a_recent_fully_successful_runtime_is_green(string $status, string $outcome, int $minutes, bool $overdue, array $issues, string $expected): void
    {
        $this->microsoftConfiguration();
        $runtime = $this->mock(MicrosoftDeviceRuntime::class);
        $runtime->shouldReceive('status')->once()->andReturn([
            'run' => ['status' => $status, 'outcome' => $outcome, 'finished_at' => now()->subMinutes($minutes)->toIso8601String()],
            'overdue' => $overdue, 'issues' => $issues,
        ]);
        $runtime->shouldNotReceive('queueSync', 'queueWorkerProbe', 'recordSchedulerTick', 'finish');
        $before = $this->businessSnapshot();
        $result = app(DeviceChecks::class)->run('microsoft_runtime');
        $this->assertSame($expected, $result['status']);
        if ($expected === 'ok') {
            $this->assertSame('runtime', $result['evidence']);
        }
        $this->assertSame($before, $this->businessSnapshot());
        Http::assertNothingSent();
    }

    public static function runtimeStates(): array
    {
        return [
            ['completed', 'success', 1, false, [], 'ok'],
            ['completed', 'partial', 1, false, [], 'warning'],
            ['completed', 'success', 31, false, [], 'warning'],
            ['failed', 'success', 1, false, [], 'warning'],
            ['running', 'success', 1, false, [], 'warning'],
            ['completed', 'success', 1, true, [], 'warning'],
            ['completed', 'success', 1, false, [['code' => 'runtime_unavailable']], 'warning'],
        ];
    }

    public function test_existing_scheduler_receipt_is_read_without_writing_another_receipt(): void
    {
        $id = (string) Str::uuid();
        DB::table('microsoft_device_runs')->insert([
            'id' => $id, 'kind' => 'scheduler', 'active_key' => 'scheduler', 'status' => 'completed',
            'finished_at' => now()->subMinute(), 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute(),
        ]);
        $before = DB::table('microsoft_device_runs')->orderBy('id')->get()->toJson();
        $result = app(DeviceChecks::class)->run('scheduler');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('runtime', $result['evidence']);
        $this->assertSame($before, DB::table('microsoft_device_runs')->orderBy('id')->get()->toJson());
        Http::assertNothingSent();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_missing_scheduler_receipt_is_warning_and_is_not_manufactured_by_check(): void
    {
        $result = app(DeviceChecks::class)->run('scheduler');
        $this->assertSame('warning', $result['status']);
        $this->assertDatabaseCount('microsoft_device_runs', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('healthModes')]
    public function test_connector_health_leaves_production_switch_and_existing_approvals_unchanged(bool $healthy): void
    {
        $this->connectorConfiguration();
        Http::fake(function (Request $request, array $options) use ($healthy) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('http://127.0.0.1:9441/v1/health', $request->url());
            $this->assertSame(5, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response($this->connectorHealth($healthy));
        });
        $before = $this->businessSnapshot();
        $result = app(DeviceChecks::class)->run('device_openuem');
        $this->assertSame($healthy ? 'ok' : 'warning', $result['status']);
        $this->assertSame('connection', $result['evidence']);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertTrue(data_get(Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY), 'runtime.production_commands_enabled'));
        $this->assertSame('unchanged-fixture', data_get(Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY), 'provider_diagnostics.openuem.marker'));
        foreach (['fixture-connector-token', 'private-upstream-details', 'private-status'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($result));
        }
        Http::assertSentCount(1);
    }

    public static function healthModes(): array
    {
        return [[true], [false]];
    }

    public function test_invalid_connector_credentials_are_reported_before_disabled_fallback(): void
    {
        $this->connectorConfiguration(enabled: false, corruptToken: true);
        $configuration = app(DeviceManagementSettings::class)->providerRuntime('openuem', fresh: true);
        $this->assertFalse($configuration['enabled']);
        $this->assertTrue($configuration['configuration_error']);
        $before = $this->businessSnapshot();
        $result = app(DeviceChecks::class)->run('device_openuem');
        $this->assertSame('error', $result['status']);
        $this->assertSame('configuration', $result['evidence']);
        $this->assertSame($before, $this->businessSnapshot());
        Http::assertNothingSent();
    }

    public function test_intentionally_disabled_connector_never_calls_health(): void
    {
        $this->connectorConfiguration(enabled: false);
        $this->assertSame('disabled', app(DeviceChecks::class)->run('device_openuem')['status']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('device_commands', 0);
    }

    public function test_connector_exceptions_are_redacted_at_the_public_orchestrator_boundary(): void
    {
        $this->connectorConfiguration();
        Http::fake(fn () => throw new RuntimeException('credential=fixture-connector-token http://private-upstream-details'));
        $directory = storage_path('framework/testing/system-health-device-'.Str::uuid());
        config(['system_health.path' => $directory]);
        $this->app->forgetInstance(SystemHealthStore::class);
        $before = $this->businessSnapshot();
        try {
            $result = app(SystemHealthService::class)->check('device_openuem', force: true);
            $this->assertSame('error', $result['status']);
            $this->assertStringNotContainsString('fixture-connector-token', json_encode($result));
            $this->assertStringNotContainsString('private-upstream-details', json_encode($result));
            $this->assertSame($before, $this->businessSnapshot());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_simulation_is_not_production_and_unknown_check_ids_are_rejected_before_transport(): void
    {
        $this->assertSame('not_checked', app(DeviceChecks::class)->run('device_simulation')['status']);
        try {
            app(SystemCheckRegistry::class)->get('device_https://arbitrary.example.test');
            $this->fail('Unregistered browser check identifiers must be rejected.');
        } catch (\InvalidArgumentException) {
            Http::assertNothingSent();
        }
        $this->assertDatabaseCount('jobs', 0);
    }

    private function microsoftConfiguration(bool $enabled = true, bool $intune = false): void
    {
        Setting::setValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY, [
            'enabled' => $enabled, 'tenant_id' => self::TENANT, 'client_id' => self::CLIENT,
            'client_secret' => 'enc:v1:'.Crypt::encryptString('fixture-client-secret'), 'intune_enabled' => $intune,
        ]);
    }

    private function connectorConfiguration(bool $enabled = true, bool $corruptToken = false): void
    {
        Setting::setValue(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY, [
            'deployment' => ['mode' => 'port', 'base_domain' => 'example.test'],
            'runtime' => ['production_commands_enabled' => true],
            'providers' => ['openuem' => [
                'enabled' => $enabled, 'subdomain' => 'openuem', 'adapter_port' => 9441,
                'token' => $corruptToken ? 'enc:v1:broken' : 'enc:v1:'.Crypt::encryptString(str_repeat('fixture-connector-token', 2)),
                'webhook_secret' => 'enc:v1:'.Crypt::encryptString(str_repeat('fixture-webhook-secret', 2)),
            ]],
            'provider_diagnostics' => ['openuem' => ['marker' => 'unchanged-fixture']],
        ]);
        $this->app->forgetInstance(DeviceManagementSettings::class);
    }

    private function connectorHealth(bool $healthy): array
    {
        return [
            'healthy' => $healthy, 'status' => 'private-status', 'contract_version' => '1.0.0',
            'connector_version' => 'fixture-1', 'provider' => 'openuem',
            'capabilities' => config('device_management.providers.openuem.capabilities'),
            'upstream' => ['reachable' => true, 'authenticated' => $healthy, 'status' => 'private-status'],
            'details' => ['message' => 'private-upstream-details'],
        ];
    }

    private function businessSnapshot(): array
    {
        $snapshot = ['settings' => Setting::query()->orderBy('id')->get()->toJson()];
        foreach (['devices', 'device_commands', 'device_assignments', 'device_provider_links', 'device_enrollments', 'jobs', 'microsoft_device_runs'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
