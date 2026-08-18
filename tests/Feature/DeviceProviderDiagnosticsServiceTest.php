<?php

namespace Tests\Feature;

use App\Services\DeviceManagement\ConnectorDnsResolver;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderDiagnosticsService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceProviderDiagnosticsServiceTest extends TestCase
{
    use DatabaseMigrations;

    private const TOKEN = 'diagnostic-token-must-stay-secret';

    private const WEBHOOK_SECRET = 'diagnostic-webhook-secret-must-stay-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://app.rail-time.test');
        $this->useResolvedAddresses(['93.184.216.34']);
        Http::preventStrayRequests();
    }

    public function test_disabled_provider_can_be_probed_with_one_authenticated_health_get(): void
    {
        $this->configureProvider('subdomain', false);
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response($this->healthResponse([
                'status' => 'remote-status-with-response-secret',
                'details' => ['token' => 'response-secret'],
                'upstream' => [
                    'reachable' => true,
                    'authenticated' => true,
                    'status' => 'upstream-status-with-response-secret',
                ],
            ])),
        ]);

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertTrue($result['configured']);
        $this->assertFalse($result['config']['enabled']);
        $this->assertSame('https://openuem.rail-time.test/v1/health', $result['url']);
        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertTrue($result['contract_valid']);
        $this->assertTrue($result['healthy']);
        $this->assertSame('healthy', $result['status']);
        $this->assertSame('1.0.0', $result['details']['reported_contract']);
        $this->assertSame('2.3.1', $result['details']['connector_version']);
        $this->assertTrue($result['details']['upstream_reachable']);
        $this->assertTrue($result['details']['upstream_authenticated']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertNotSame('', $result['checked_at']);

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::TOKEN, $serialized);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $serialized);
        $this->assertStringNotContainsString('response-secret', $serialized);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://openuem.rail-time.test/v1/health'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_port_mode_uses_only_the_exact_loopback_adapter_address(): void
    {
        $this->configureProvider('port', true);
        Http::fake([
            'http://127.0.0.1:9441/v1/health' => Http::response($this->healthResponse()),
        ]);

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('http://127.0.0.1:9441/v1/health', $result['url']);
        $this->assertSame('port', $result['details']['mode']);
        $this->assertSame('healthy', $result['status']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://127.0.0.1:9441/v1/health');
    }

    public function test_missing_credentials_do_not_send_any_request(): void
    {
        $settings = app(DeviceManagementSettings::class);
        $settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'rail-time.test',
        ]);
        $settings->saveProvider('openuem', [
            'enabled' => false,
            'clear_token' => true,
        ]);

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertFalse($result['configured']);
        $this->assertFalse($result['reachable']);
        $this->assertFalse($result['authenticated']);
        $this->assertSame('missing_credentials', $result['status']);
        Http::assertNothingSent();
    }

    public function test_subdomain_resolving_to_a_private_address_is_rejected_before_token_send(): void
    {
        $this->configureProvider('subdomain', true);
        $this->useResolvedAddresses(['127.0.0.1']);
        Http::fake();

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('invalid_configuration', $result['status']);
        $this->assertFalse($result['reachable']);
        $this->assertFalse($result['authenticated']);
        Http::assertNothingSent();
    }

    public function test_provider_mismatch_never_becomes_healthy(): void
    {
        $this->configureProvider('subdomain', true);
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response($this->healthResponse([
                'provider' => 'meshcentral',
            ])),
        ]);

        $mismatch = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('provider_mismatch', $mismatch['status']);
        $this->assertTrue($mismatch['authenticated']);
        $this->assertFalse($mismatch['contract_valid']);
        $this->assertFalse($mismatch['healthy']);
    }

    public function test_invalid_contract_never_becomes_healthy(): void
    {
        $this->configureProvider('subdomain', true);
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response([
                'healthy' => true,
                'status' => 'ok',
                'contract_version' => '1.0.0',
                'connector_version' => '2.3.1',
                'provider' => 'openuem',
                'capabilities' => [],
            ]),
        ]);

        $invalid = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('contract_invalid', $invalid['status']);
        $this->assertFalse($invalid['contract_valid']);
        $this->assertFalse($invalid['healthy']);
    }

    public function test_capabilities_must_be_an_object_map_instead_of_a_json_list(): void
    {
        $this->configureProvider('subdomain', true);
        $payload = $this->healthResponse();
        $payload['capabilities'] = ['inventory', 'commands'];
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response($payload),
        ]);

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('contract_invalid', $result['status']);
        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertFalse($result['contract_valid']);
        $this->assertFalse($result['healthy']);
    }

    public function test_missing_code_owned_capability_never_becomes_healthy(): void
    {
        $this->configureProvider('subdomain', true);
        $payload = $this->healthResponse();
        $payload['capabilities']['platforms'] = ['windows', 'macos'];
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response($payload),
        ]);

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('contract_invalid', $result['status']);
        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertFalse($result['contract_valid']);
        $this->assertFalse($result['healthy']);
    }

    public function test_connector_is_not_healthy_when_its_upstream_is_not_authenticated(): void
    {
        $this->configureProvider('subdomain', true);
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response($this->healthResponse([
                'upstream' => [
                    'reachable' => true,
                    'authenticated' => false,
                    'status' => 'credentials rejected with private-response-secret',
                ],
            ])),
        ]);

        $result = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('unhealthy', $result['status']);
        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['authenticated']);
        $this->assertTrue($result['contract_valid']);
        $this->assertFalse($result['healthy']);
        $this->assertFalse($result['details']['upstream_authenticated']);
        $this->assertStringNotContainsString(
            'private-response-secret',
            json_encode($result, JSON_THROW_ON_ERROR),
        );
    }

    public function test_connection_errors_are_redacted(): void
    {
        $this->configureProvider('subdomain', true);
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::failedConnection(
                'connection-error-with-private-secret',
            ),
        ]);

        $failed = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('unreachable', $failed['status']);
        $this->assertFalse($failed['reachable']);
        $this->assertStringNotContainsString(
            'private-secret',
            json_encode($failed, JSON_THROW_ON_ERROR),
        );
    }

    public function test_oversized_responses_are_rejected_and_redacted(): void
    {
        $this->configureProvider('subdomain', true);
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response(
                str_repeat('x', 65537).'oversized-private-secret',
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $oversized = app(DeviceProviderDiagnosticsService::class)->probe('openuem');

        $this->assertSame('response_too_large', $oversized['status']);
        $this->assertTrue($oversized['reachable']);
        $this->assertTrue($oversized['authenticated']);
        $this->assertFalse($oversized['healthy']);
        $this->assertStringNotContainsString(
            'oversized-private-secret',
            json_encode($oversized, JSON_THROW_ON_ERROR),
        );
    }

    private function configureProvider(string $mode, bool $enabled): void
    {
        $settings = app(DeviceManagementSettings::class);
        $settings->saveDeployment([
            'mode' => $mode,
            'base_domain' => 'rail-time.test',
        ]);
        $settings->saveProvider('openuem', [
            'enabled' => $enabled,
            'token' => self::TOKEN,
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
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

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function healthResponse(array $overrides = []): array
    {
        return array_replace_recursive([
            'healthy' => true,
            'status' => 'ok',
            'contract_version' => '1.0.0',
            'connector_version' => '2.3.1',
            'provider' => 'openuem',
            'capabilities' => [
                'platforms' => ['windows', 'macos', 'linux'],
                'inventory' => true,
                'enrollment' => true,
                'remote_support' => false,
                'unattended_remote_support' => false,
                'commands' => [
                    'sync',
                    'restart',
                    'execute_script',
                    'install_software',
                    'uninstall_software',
                    'collect_diagnostics',
                    'apply_profile',
                ],
                'readiness_checks' => ['provider_sync', 'profiles', 'network', 'certificate', 'compliance'],
            ],
            'upstream' => [
                'reachable' => true,
                'authenticated' => true,
                'status' => 'ok',
            ],
        ], $overrides);
    }
}
