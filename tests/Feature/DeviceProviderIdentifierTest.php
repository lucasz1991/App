<?php

namespace Tests\Feature;

use App\Models\DeviceProviderLink;
use App\Models\User;
use App\Services\DeviceManagement\DeviceInventoryImportService;
use App\Services\DeviceManagement\DeviceInventoryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DeviceProviderIdentifierTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('device_management.providers.simulation.enabled', true);
        config()->set('device_management.providers.simulation.webhook_secret', 'provider-identifier-secret');
    }

    public function test_native_meshcentral_node_identifier_is_accepted_without_truncation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $meshNodeId = 'node/railtime.example/AbC_0123456789@$+=.mesh-node-0001';

        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-MESH-ID-1',
            'serial_number' => 'SERIAL-MESH-ID-1',
            'display_name' => 'Mesh-ID-Testgerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'IT-Labor Berlin',
            'primary_provider' => 'meshcentral',
            'primary_provider_device_id' => $meshNodeId,
        ], $admin);

        $this->assertSame($meshNodeId, $device->fresh()->primary_provider_device_id);
        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'meshcentral',
            'external_device_id' => $meshNodeId,
            'status' => DeviceProviderLink::STATUS_ACTIVE,
        ]);
    }

    public function test_csv_provider_change_without_new_external_id_never_reuses_the_old_providers_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-PROVIDER-SWITCH-1',
            'serial_number' => 'SERIAL-PROVIDER-SWITCH-1',
            'display_name' => 'Providerwechsel-Testgerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'IT-Labor Berlin',
            'primary_provider' => 'openuem',
            'primary_provider_device_id' => 'openuem-device-42',
        ], $admin);

        $csv = implode("\n", [
            'Inventarnummer;Seriennummer;Gerätename;Plattform;Gerätetyp;Eigentum;Standort;Provider',
            'RT-PROVIDER-SWITCH-1;SERIAL-PROVIDER-SWITCH-1;Providerwechsel-Testgerät;windows;laptop;corporate;IT-Labor Berlin;meshcentral',
        ]);

        app(DeviceInventoryImportService::class)->import(
            UploadedFile::fake()->createWithContent('provider-switch.csv', $csv),
            $admin,
        );

        $device->refresh();
        $this->assertSame('meshcentral', $device->primary_provider);
        $this->assertNull($device->primary_provider_device_id);
        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'openuem',
            'external_device_id' => 'openuem-device-42',
            'role' => DeviceProviderLink::ROLE_SUPPORT,
        ]);
        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'meshcentral',
            'external_device_id' => null,
            'role' => DeviceProviderLink::ROLE_PRIMARY,
            'status' => DeviceProviderLink::STATUS_PENDING,
        ]);
    }

    public function test_signed_provider_receipt_accepts_and_mirrors_a_full_native_node_identifier(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-WEBHOOK-ID-1',
            'serial_number' => 'SERIAL-WEBHOOK-ID-1',
            'display_name' => 'Webhook-ID-Testgerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'IT-Labor Berlin',
            'primary_provider' => 'simulation',
        ], $admin);
        $nodeId = 'node/railtime.example/ZyX_0123456789@$+=.mesh-node-0002';
        $payload = [
            'event_id' => 'provider-native-node-id-1',
            'event_type' => 'device.seen',
            'device_id' => $device->public_id,
            'provider_device_id' => $nodeId,
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;

        $this->postJson(
            route('api.device-management.providers.events', ['provider' => 'simulation']),
            $payload,
            [
                'X-RailTime-Timestamp' => $timestamp,
                'X-RailTime-Signature' => 'sha256='.hash_hmac(
                    'sha256',
                    $timestamp.'.'.$raw,
                    'provider-identifier-secret',
                ),
            ],
        )->assertOk()->assertJson(['accepted' => true]);

        $this->assertSame($nodeId, $device->fresh()->primary_provider_device_id);
        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'simulation',
            'external_device_id' => $nodeId,
            'status' => DeviceProviderLink::STATUS_ACTIVE,
        ]);
    }
}
