<?php

namespace Tests\Feature;

use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceProviderLink;
use App\Models\User;
use App\Services\DeviceManagement\ConnectorHttpClient;
use App\Services\DeviceManagement\DeviceEnrollmentService;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\Providers\ConnectorDeviceProvider;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceProviderLinkTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('device_management.providers.simulation.enabled', true);
        config()->set('device_management.providers.simulation.webhook_secret', 'provider-link-test-secret');
    }

    public function test_one_device_keeps_separate_primary_and_support_provider_identifiers(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = $this->createDevice($admin, 'openuem', 'open-node-17');
        $meshNodeId = 'node//'.str_repeat('B', 64);
        $supportLink = $device->ensureProviderLink(
            'meshcentral',
            DeviceProviderLink::ROLE_SUPPORT,
            $meshNodeId,
        );

        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'openuem',
            'external_device_id' => 'open-node-17',
            'role' => DeviceProviderLink::ROLE_PRIMARY,
        ]);
        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'meshcentral',
            'external_device_id' => $meshNodeId,
            'role' => DeviceProviderLink::ROLE_SUPPORT,
        ]);
        $this->assertSame('open-node-17', $device->fresh()->providerDeviceIdFor('openuem'));
        $this->assertSame($meshNodeId, $device->fresh()->providerDeviceIdFor('meshcentral'));

        $configuration = config('device_management.providers.meshcentral');
        $configuration['enabled'] = true;
        $configuration['remote_url_template'] = 'https://support.rail-time.test/device/{provider_device_id}';
        $provider = new ConnectorDeviceProvider(
            'meshcentral',
            $configuration,
            app(ConnectorHttpClient::class),
            app(DeviceManagementSettings::class),
        );

        $this->assertFalse($provider->capabilities()['enrollment']);
        $this->assertSame(
            ['execute_script', 'collect_diagnostics'],
            $provider->capabilities()['commands'],
        );

        $this->assertSame(
            'https://support.rail-time.test/device/'.rawurlencode($meshNodeId),
            $provider->remoteSupportUrl($supportLink->device),
        );
    }

    public function test_connector_command_uses_the_selected_providers_external_identifier(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = $this->createDevice($admin, 'openuem', 'open-node-command');
        $meshNodeId = 'node//'.str_repeat('C', 64);
        $device->ensureProviderLink('meshcentral', DeviceProviderLink::ROLE_SUPPORT, $meshNodeId);
        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'provider' => 'meshcentral',
            'type' => DeviceCommandType::CollectDiagnostics,
            'status' => DeviceCommandStatus::Dispatched,
            'justification' => 'Providerspezifischen Identifier im Connector-Vertrag prüfen.',
            'correlation_id' => 'provider-link-command-1',
            'requested_by' => $admin->id,
            'requested_at' => now(),
        ]);

        $settings = app(DeviceManagementSettings::class);
        $settings->saveDeployment([
            'mode' => 'port',
            'base_domain' => 'rail-time.test',
        ]);
        $settings->saveProvider('meshcentral', [
            'enabled' => true,
            'adapter_port' => 9442,
            'token' => str_repeat('t', 32),
            'webhook_secret' => str_repeat('w', 32),
        ]);
        $settings->recordProviderDiagnostic('meshcentral', [
            'healthy' => true,
            'authenticated' => true,
            'contract_valid' => true,
            'status' => 'healthy',
            'contract' => [
                'upstream_reachable' => true,
                'upstream_authenticated' => true,
            ],
        ], $settings->providerFingerprint('meshcentral', fresh: true));
        $settings->setProductionCommandsEnabled(true);
        Http::fake([
            'http://127.0.0.1:9442/v1/commands' => Http::response([
                'accepted' => true,
                'completed' => true,
                'provider_job_id' => 'mesh-job-1',
            ]),
        ]);
        $configuration = config('device_management.providers.meshcentral');
        $configuration = array_replace($configuration, [
            'enabled' => true,
            'mode' => 'port',
            'same_server' => true,
            'adapter_port' => 9442,
            'connector_base_url' => 'http://127.0.0.1:9442',
            'token' => str_repeat('t', 32),
        ]);
        $provider = new ConnectorDeviceProvider(
            'meshcentral',
            $configuration,
            app(ConnectorHttpClient::class),
            $settings,
        );

        $provider->dispatch($command, $device->fresh());

        Http::assertSent(function (Request $request) use ($device, $meshNodeId): bool {
            $decoded = json_decode($request->body());

            return $request->url() === 'http://127.0.0.1:9442/v1/commands'
                && $request->data()['provider_device_id'] === $meshNodeId
                && $request->data()['device_id'] === $device->public_id
                && is_object($decoded?->options)
                && get_object_vars($decoded->options) === [];
        });
    }

    public function test_signed_webhooks_bind_only_predeclared_provider_links(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->createDevice($admin, 'simulation');
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);
        $invitation = app(DeviceEnrollmentService::class)->invite(
            $device,
            $employee,
            'simulation',
            'agent',
            $admin,
        );
        app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $employee);

        $this->postSignedWebhook('simulation', 'provider-link-test-secret', [
            'event_id' => 'provider-link-enrollment-1',
            'event_type' => 'enrollment.completed',
            'enrollment_id' => $invitation->enrollment->public_id,
            'provider_device_id' => 'simulation-node-1',
        ])->assertOk();

        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'simulation',
            'external_device_id' => 'simulation-node-1',
            'status' => DeviceProviderLink::STATUS_ACTIVE,
        ]);
        $this->assertSame('simulation-node-1', $device->fresh()->primary_provider_device_id);

        $unlinked = $this->createDevice($admin, 'openuem', 'open-only-node');
        $this->postSignedWebhook('simulation', 'provider-link-test-secret', [
            'event_id' => 'provider-link-unknown-1',
            'event_type' => 'device.seen',
            'device_id' => $unlinked->public_id,
            'provider_device_id' => 'must-not-bind',
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('device_provider_links', [
            'device_id' => $unlinked->id,
            'provider' => 'simulation',
        ]);
    }

    public function test_handover_is_fail_closed_until_readiness_is_fully_proven(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->createDevice($admin, 'simulation');
        $assignment = app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        try {
            app(DeviceInventoryService::class)->confirmHandover($device, $admin);
            $this->fail('An incomplete readiness matrix must block handover.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('handover', $exception->errors());
        }

        $this->assertNull($assignment->fresh()->handover_at);
        $this->assertSame('assigned', $device->fresh()->lifecycle_status->value);
    }

    private function createDevice(User $admin, string $provider, ?string $providerDeviceId = null): Device
    {
        return app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-'.strtoupper(fake()->bothify('??-#####')),
            'serial_number' => strtoupper(fake()->bothify('SN-########')),
            'display_name' => 'Provider Link Testgerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'primary_provider' => $provider,
            'primary_provider_device_id' => $providerDeviceId,
        ], $admin);
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
}
