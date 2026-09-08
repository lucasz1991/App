<?php

namespace Tests\Feature;

use App\Livewire\Admin\DeviceManagementSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DeviceProviderSetupClarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app()->setLocale('de');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => true]));
    }

    public function test_provider_switch_is_a_setting_and_does_not_claim_operational_readiness(): void
    {
        Livewire::test(DeviceManagementSettings::class)
            ->assertSee('Provider verwenden')
            ->assertSee('kein Betriebsnachweis')
            ->assertSee('prüft die gespeicherten Werte')
            ->assertSee('bestätigt keine Erreichbarkeit')
            ->assertSet('providers.identity.enabled', false)
            ->assertSet('runtime.production_commands_enabled', false);

        Http::assertNothingSent();
    }

    public function test_unsaved_enabled_switch_does_not_hide_missing_connection_evidence(): void
    {
        Livewire::test(DeviceManagementSettings::class)
            ->set('providers.identity.enabled', true)
            ->assertSee('In dieser Ansicht noch kein Verbindungstest durchgeführt.')
            ->assertSeeHtml('data-device-provider-unchecked="identity"')
            ->assertSet('runtime.production_commands_enabled', false);

        Livewire::test(DeviceManagementSettings::class)->assertSet('providers.identity.enabled', false);
        Http::assertNothingSent();
    }

    public function test_english_setup_explains_saved_values_and_the_execution_boundary(): void
    {
        app()->setLocale('en');

        Livewire::test(DeviceManagementSettings::class)
            ->assertSee('Use provider')
            ->assertSee('not evidence of readiness')
            ->assertSee('does not install a service or enable device actions');

        Http::assertNothingSent();
    }
}
