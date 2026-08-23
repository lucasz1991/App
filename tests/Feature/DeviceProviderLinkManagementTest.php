<?php

namespace Tests\Feature;

use App\Livewire\Devices\DeviceManagement;
use App\Models\DeviceProviderLink;
use App\Models\User;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceProviderLinkService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceProviderLinkManagementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('device_management.providers.simulation.enabled', true);
        config()->set('device_management.providers.meshcentral.enabled', true);
        config()->set('device_management.providers.meshcentral.token', str_repeat('t', 32));
        config()->set('device_management.providers.meshcentral.webhook_secret', str_repeat('w', 32));
        config()->set(
            'device_management.providers.meshcentral.remote_url_template',
            'https://support.rail-time.test/device/{provider_device_id}',
        );
    }

    public function test_admin_can_add_a_secondary_mesh_support_link_but_cannot_silently_replace_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = $this->device($admin);
        $nodeId = 'node/railtime.example/AbC_0123456789@$+=.support-node-01';

        $link = app(DeviceProviderLinkService::class)->link(
            $device,
            'meshcentral',
            DeviceProviderLink::ROLE_SUPPORT,
            $nodeId,
            $admin,
        );

        $this->assertSame(DeviceProviderLink::ROLE_SUPPORT, $link->role);
        $this->assertSame(DeviceProviderLink::STATUS_ACTIVE, $link->status);
        $this->assertSame($nodeId, $device->fresh()->providerDeviceIdFor('meshcentral'));
        $this->assertSame('simulation', $device->fresh()->primary_provider);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $device->getMorphClass(),
            'subject_id' => $device->id,
            'description' => 'device_provider_linked',
        ]);

        try {
            app(DeviceProviderLinkService::class)->link(
                $device,
                'meshcentral',
                DeviceProviderLink::ROLE_SUPPORT,
                'node/railtime.example/ZyX_0123456789@$+=.different-02',
                $admin,
            );
            $this->fail('An established provider binding must not be replaced implicitly.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('external_device_id', $exception->errors());
        }
    }

    public function test_provider_link_is_managed_in_an_authorized_modal_and_unlocks_only_the_linked_support_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = $this->device($admin);
        $nodeId = 'node/railtime.example/Liv_0123456789@$+=.support-node-03';

        Livewire::actingAs($admin)
            ->test(DeviceManagement::class)
            ->call('selectDevice', $device->public_id)
            ->assertSeeHtml('id="device-provider-link-modal"')
            ->assertDontSee('MeshCentral Remote Support öffnen')
            ->call('openProviderLink')
            ->assertSet('showProviderLinkForm', true)
            ->assertSet('providerLinkForm.provider', 'meshcentral')
            ->set('providerLinkForm.role', 'support')
            ->set('providerLinkForm.external_device_id', $nodeId)
            ->call('saveProviderLink')
            ->assertSet('showProviderLinkForm', false)
            ->assertSee('MeshCentral Remote Support öffnen');

        $this->assertDatabaseHas('device_provider_links', [
            'device_id' => $device->id,
            'provider' => 'meshcentral',
            'external_device_id' => $nodeId,
            'role' => DeviceProviderLink::ROLE_SUPPORT,
            'status' => DeviceProviderLink::STATUS_ACTIVE,
        ]);
    }

    private function device(User $admin)
    {
        return app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-LINK-'.fake()->unique()->numberBetween(1000, 9999),
            'serial_number' => 'SERIAL-LINK-'.fake()->unique()->numberBetween(10000, 99999),
            'display_name' => 'Provider-Link-Verwaltung',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'IT-Labor Berlin',
            'primary_provider' => 'simulation',
        ], $admin);
    }
}
