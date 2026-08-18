<?php

namespace Tests\Feature;

use App\Enums\DeviceCommandType;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\ConnectorDnsResolver;
use App\Services\DeviceManagement\ConnectorHttpClient;
use App\Services\DeviceManagement\DeviceManagementSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class DeviceManagementSettingsTest extends TestCase
{
    use DatabaseMigrations;

    private DeviceManagementSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://app.rail-time.test');
        $this->useResolvedAddresses(['93.184.216.34']);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]));
        $this->settings = app(DeviceManagementSettings::class);
    }

    public function test_defaults_are_closed_and_urls_are_derived_from_trusted_app_configuration(): void
    {
        $form = $this->settings->forForm();

        $this->assertFalse($form['runtime']['production_commands_enabled']);
        $this->assertSame('subdomain', $form['deployment']['mode']);
        $this->assertSame('app.rail-time.test', $form['deployment']['base_domain']);
        $this->assertSame('https://openuem.app.rail-time.test', $form['providers']['openuem']['effective_url']);
        $this->assertFalse($form['providers']['openuem']['enabled']);

        $this->settings->saveDeployment(
            ['mode' => 'port', 'base_domain' => 'devices.rail-time.test'],
            ['enrollment_ttl_minutes' => 60, 'max_sync_age_hours' => 12, 'artifact_max_kilobytes' => 2048],
        );

        $runtime = $this->settings->providerRuntime('openuem', fresh: true);
        $this->assertSame('http://127.0.0.1:9441', $runtime['effective_url']);
        $this->assertSame('https://openuem.devices.rail-time.test', $runtime['public_url']);
        $this->assertTrue($runtime['same_server']);
        $this->assertSame(60, $this->settings->enrollmentTtlMinutes());
    }

    public function test_provider_secrets_are_encrypted_masked_retained_and_explicit_clear_disables_provider(): void
    {
        $token = str_repeat('t', 32);
        $webhookSecret = str_repeat('w', 32);

        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => $token,
            'webhook_secret' => $webhookSecret,
            'timeout' => 8,
        ]);

        $stored = (array) Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY);
        $raw = (string) DB::table('settings')
            ->where('type', DeviceManagementSettings::GROUP)
            ->where('key', DeviceManagementSettings::KEY)
            ->value('value');
        $this->assertStringStartsWith('enc:v1:', $stored['providers']['openuem']['token']);
        $this->assertStringStartsWith('enc:v1:', $stored['providers']['openuem']['webhook_secret']);
        $this->assertStringNotContainsString($token, $raw);
        $this->assertStringNotContainsString($webhookSecret, $raw);
        $auditProperties = DB::table('activity_log')
            ->where('log_name', 'device-management')
            ->pluck('properties')
            ->implode('\n');
        $this->assertStringNotContainsString($token, $auditProperties);
        $this->assertStringNotContainsString($webhookSecret, $auditProperties);

        $form = $this->settings->forForm();
        $this->assertSame(DeviceManagementSettings::SECRET_MASK, $form['providers']['openuem']['token']);
        $this->assertSame(DeviceManagementSettings::SECRET_MASK, $form['providers']['openuem']['webhook_secret']);

        $ciphertext = $stored['providers']['openuem']['token'];
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'timeout' => 9,
        ]);
        $retained = (array) Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY);
        $this->assertSame($ciphertext, $retained['providers']['openuem']['token']);

        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'clear_token' => true,
        ]);
        $cleared = $this->settings->forForm();
        $this->assertFalse($cleared['providers']['openuem']['enabled']);
        $this->assertFalse($cleared['providers']['openuem']['token_configured']);
    }

    public function test_corrupt_ciphertext_disables_provider_and_returns_no_secret(): void
    {
        $this->settings->saveProvider('headwind', [
            'enabled' => true,
            'token' => str_repeat('t', 32),
            'webhook_secret' => str_repeat('w', 32),
        ]);

        $stored = (array) Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY);
        $stored['providers']['headwind']['token'] = 'enc:v1:corrupt';
        Setting::setValue(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY, $stored);

        $runtime = app(DeviceManagementSettings::class)->providerRuntime('headwind', fresh: true);
        $this->assertFalse($runtime['enabled']);
        $this->assertSame('', $runtime['token']);
        $this->assertTrue($runtime['configuration_error']);
    }

    public function test_short_secrets_and_duplicate_active_adapter_ports_are_rejected_in_port_mode(): void
    {
        try {
            $this->settings->saveProvider('openuem', [
                'token' => 'too-short',
                'webhook_secret' => str_repeat('w', 32),
            ]);
            $this->fail('Short secrets must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('32', $exception->getMessage());
        }

        $this->settings->saveDeployment([
            'mode' => 'port',
            'base_domain' => 'devices.rail-time.test',
        ]);
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('a', 32),
            'webhook_secret' => str_repeat('b', 32),
            'adapter_port' => 9550,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unterschiedliche lokale Adapter-Ports');
        $this->settings->saveProvider('meshcentral', [
            'enabled' => true,
            'token' => str_repeat('c', 32),
            'webhook_secret' => str_repeat('d', 32),
            'adapter_port' => 9550,
        ]);
    }

    public function test_kill_switch_fresh_read_observes_an_emergency_change_without_restart(): void
    {
        $firstReader = app(DeviceManagementSettings::class);
        $this->assertFalse($firstReader->productionCommandsEnabled());

        $secondWriter = new DeviceManagementSettings;
        $secondWriter->setProductionCommandsEnabled(true);

        $this->assertFalse($firstReader->productionCommandsEnabled());
        $this->assertTrue($firstReader->productionCommandsEnabled(fresh: true));

        $secondWriter->setProductionCommandsEnabled(false);
        $this->assertFalse($firstReader->productionCommandsEnabled(fresh: true));
    }

    public function test_stale_service_snapshot_cannot_reenable_emergency_kill_switch_during_an_unrelated_save(): void
    {
        $this->settings->setProductionCommandsEnabled(true);

        $staleWriter = new DeviceManagementSettings;
        $this->assertTrue($staleWriter->productionCommandsEnabled());

        (new DeviceManagementSettings)->setProductionCommandsEnabled(false);
        $staleWriter->saveIdentityDomains(['microsoft_365' => 'rail-time.de']);

        $fresh = new DeviceManagementSettings;
        $this->assertFalse($fresh->productionCommandsEnabled(fresh: true));
        $this->assertSame('rail-time.de', $fresh->identityDomain('microsoft_365'));
    }

    public function test_provider_target_change_requires_reentry_but_accepts_two_fresh_credentials_in_same_save(): void
    {
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('a', 32),
            'webhook_secret' => str_repeat('b', 32),
        ]);

        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'subdomain' => 'desktop',
            'token' => DeviceManagementSettings::SECRET_MASK,
            'webhook_secret' => '',
        ]);

        $invalidated = $this->settings->forForm()['providers']['openuem'];
        $this->assertFalse($invalidated['enabled']);
        $this->assertFalse($invalidated['token_configured']);
        $this->assertFalse($invalidated['webhook_secret_configured']);

        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'subdomain' => 'desktop-reauthorized',
            'token' => str_repeat('c', 32),
            'webhook_secret' => str_repeat('d', 32),
        ]);

        $reauthorized = $this->settings->forForm()['providers']['openuem'];
        $this->assertTrue($reauthorized['enabled']);
        $this->assertTrue($reauthorized['token_configured']);
        $this->assertTrue($reauthorized['webhook_secret_configured']);
        $this->assertSame('desktop-reauthorized', $reauthorized['subdomain']);
    }

    public function test_changing_an_inactive_fallback_target_does_not_invalidate_credentials(): void
    {
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('a', 32),
            'webhook_secret' => str_repeat('b', 32),
        ]);

        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'adapter_port' => 9552,
            'token' => DeviceManagementSettings::SECRET_MASK,
            'webhook_secret' => DeviceManagementSettings::SECRET_MASK,
        ]);

        $provider = $this->settings->forForm()['providers']['openuem'];
        $this->assertTrue($provider['enabled']);
        $this->assertTrue($provider['token_configured']);
        $this->assertTrue($provider['webhook_secret_configured']);
        $this->assertSame(9552, $provider['adapter_port']);

        $this->settings->saveDeployment([
            'mode' => 'port',
            'base_domain' => 'devices.rail-time.test',
        ]);
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('c', 32),
            'webhook_secret' => str_repeat('d', 32),
        ]);
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'subdomain' => 'unused-in-port-mode',
            'token' => DeviceManagementSettings::SECRET_MASK,
            'webhook_secret' => DeviceManagementSettings::SECRET_MASK,
        ]);

        $portProvider = $this->settings->forForm()['providers']['openuem'];
        $this->assertTrue($portProvider['enabled']);
        $this->assertTrue($portProvider['token_configured']);
        $this->assertTrue($portProvider['webhook_secret_configured']);
        $this->assertSame('unused-in-port-mode', $portProvider['subdomain']);
    }

    public function test_deployment_topology_change_clears_active_and_inactive_external_provider_credentials(): void
    {
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('a', 32),
            'webhook_secret' => str_repeat('b', 32),
        ]);
        $this->settings->saveProvider('headwind', [
            'enabled' => false,
            'token' => str_repeat('c', 32),
            'webhook_secret' => str_repeat('d', 32),
        ]);

        $this->settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'mdm.rail-time.test',
        ]);

        $providers = $this->settings->forForm()['providers'];
        foreach (['openuem', 'headwind'] as $provider) {
            $this->assertFalse($providers[$provider]['enabled']);
            $this->assertFalse($providers[$provider]['token_configured']);
            $this->assertFalse($providers[$provider]['webhook_secret_configured']);
        }
    }

    public function test_subdomain_endpoint_collisions_are_rejected_on_provider_and_deployment_saves(): void
    {
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('a', 32),
            'webhook_secret' => str_repeat('b', 32),
        ]);

        try {
            $this->settings->saveProvider('meshcentral', [
                'enabled' => true,
                'subdomain' => 'openuem',
                'token' => str_repeat('c', 32),
                'webhook_secret' => str_repeat('d', 32),
            ]);
            $this->fail('Duplicate active subdomain endpoints must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('HTTPS-Subdomains', $exception->getMessage());
        }

        $stored = (array) Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY);
        $stored['providers']['meshcentral'] = [
            'enabled' => true,
            'subdomain' => 'openuem',
            'adapter_port' => 9442,
            'token' => $stored['providers']['openuem']['token'],
            'webhook_secret' => $stored['providers']['openuem']['webhook_secret'],
        ];
        Setting::setValue(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY, $stored);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS-Subdomains');
        $this->settings->saveDeployment(
            ['mode' => 'subdomain', 'base_domain' => 'app.rail-time.test'],
            ['max_sync_age_hours' => 48],
        );
    }

    public function test_production_subdomain_mode_rejects_local_private_and_reserved_targets_while_testing_allows_them(): void
    {
        $this->settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'localhost',
        ]);
        $this->assertSame('localhost', $this->settings->forForm()['deployment']['base_domain']);

        $this->app->detectEnvironment(static fn (): string => 'production');
        try {
            foreach (['localhost', 'devices.test', 'example.com', '10.0.0.1', '127.0.0.1', '169.254.10.20'] as $target) {
                try {
                    $this->settings->saveDeployment([
                        'mode' => 'subdomain',
                        'base_domain' => $target,
                    ]);
                    $this->fail("Unsafe production target {$target} must be rejected.");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }

            $this->settings->saveDeployment([
                'mode' => 'subdomain',
                'base_domain' => 'devices.rail-time.de',
            ]);
            $this->assertSame('devices.rail-time.de', $this->settings->forForm()['deployment']['base_domain']);
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }

    public function test_unknown_nonempty_schema_version_disables_providers_and_commands_and_blocks_writes(): void
    {
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('a', 32),
            'webhook_secret' => str_repeat('b', 32),
        ]);
        $this->settings->setProductionCommandsEnabled(true);

        $stored = (array) Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY);
        $stored['schema_version'] = 999;
        Setting::setValue(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY, $stored);

        $futureSchema = new DeviceManagementSettings;
        $this->assertFalse($futureSchema->productionCommandsEnabled(fresh: true));
        $this->assertFalse($futureSchema->providerRuntime('openuem')['enabled']);
        $this->assertTrue($futureSchema->providerRuntime('openuem')['configuration_error']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Version');
        $futureSchema->saveIdentityDomains(['microsoft_365' => 'rail-time.de']);
    }

    public function test_nanomdm_mobile_commands_are_a_default_closed_code_owned_ceiling(): void
    {
        $this->assertSame([], $this->settings->providerRuntime('nanomdm')['capabilities']['commands']);

        $this->settings->saveProvider('nanomdm', [
            'enabled' => true,
            'mobile_commands_enabled' => true,
            'token' => str_repeat('n', 32),
            'webhook_secret' => str_repeat('m', 32),
        ]);

        $commands = $this->settings->providerRuntime('nanomdm', fresh: true)['capabilities']['commands'];
        $this->assertEqualsCanonicalizing([
            DeviceCommandType::Sync->value,
            DeviceCommandType::Lock->value,
            DeviceCommandType::Wipe->value,
            DeviceCommandType::Restart->value,
            DeviceCommandType::InstallSoftware->value,
            DeviceCommandType::ApplyProfile->value,
        ], $commands);

        $stored = (array) Setting::getValueUncached(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY);
        $stored['providers']['nanomdm']['capabilities'] = ['commands' => ['arbitrary-root-command']];
        Setting::setValue(DeviceManagementSettings::GROUP, DeviceManagementSettings::KEY, $stored);
        $this->assertNotContains(
            'arbitrary-root-command',
            app(DeviceManagementSettings::class)->providerRuntime('nanomdm', fresh: true)['capabilities']['commands'],
        );
    }

    public function test_device_environment_variables_are_no_longer_part_of_configuration(): void
    {
        $config = file_get_contents(config_path('device_management.php'));
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($config);
        $this->assertIsString($example);
        $this->assertStringNotContainsString("env('DEVICE_", $config);
        $this->assertDoesNotMatchRegularExpression('/^DEVICE_(?:MANAGEMENT|OPENUEM|MESHCENTRAL|HEADWIND|NANOMDM|IDENTITY|SIMULATION)/m', $example);
    }

    public function test_connector_client_rejects_an_oversized_streamed_response(): void
    {
        $this->settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'rail-time.test',
        ]);
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('t', 32),
            'webhook_secret' => str_repeat('w', 32),
        ]);
        $provider = $this->settings->providerRuntime('openuem', fresh: true);

        Http::preventStrayRequests();
        Http::fake([
            'https://openuem.rail-time.test/v1/health' => Http::response(
                str_repeat('x', 65537),
                200,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Antwort des Connectors');
        app(ConnectorHttpClient::class)->request('openuem', $provider, 'GET', '/v1/health');
    }

    public function test_connector_client_never_sends_a_token_to_private_or_reserved_dns_targets(): void
    {
        $this->settings->saveDeployment([
            'mode' => 'subdomain',
            'base_domain' => 'rail-time.test',
        ]);
        $this->settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('t', 32),
            'webhook_secret' => str_repeat('w', 32),
        ]);
        $provider = $this->settings->providerRuntime('openuem', fresh: true);
        Http::fake();

        foreach (['10.10.10.10', '127.0.0.1', '169.254.169.254', '192.0.2.1', '::1', 'fe80::1', 'fc00::1'] as $address) {
            $this->useResolvedAddresses([$address]);

            try {
                app(ConnectorHttpClient::class)->request('openuem', $provider, 'GET', '/v1/health');
                $this->fail("DNS target {$address} must be rejected before the request is sent.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('nicht zulässige Adresse', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
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
