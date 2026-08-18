<?php

namespace Tests\Feature;

use App\Livewire\Admin\DeviceManagementSettings as DeviceManagementSettingsComponent;
use App\Livewire\Admin\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\DeviceManagementSettings as DeviceManagementSettingsService;
use App\Support\Ai\AssistantSettings;
use App\Support\Ai\AssistantSpeechSettings;
use App\Support\Ai\OpenRouterSettings;
use App\Support\Calls\CallSettings;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

/**
 * Einstieg und Sonderrechte der Admin-Einstellungen.
 *
 * Der erste Tab ist eine Uebersicht, die auf die uebrigen Bereiche verweist.
 * Der Superadmin-Tab existiert nur fuer Benutzer #1 — dort liegen
 * Zugangsdaten, die kein weiterer Administrator sehen oder tauschen soll.
 */
class AdminSettingsOverviewAndSuperadminTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_overview_tab_teases_and_links_every_other_tab(): void
    {
        $view = File::get(resource_path('views/livewire/admin/settings.blade.php'));
        $teaser = File::get(resource_path('views/components/admin/settings-teaser.blade.php'));

        // Der frueher 'general' benannte Tab heisst jetzt Uebersicht und ist
        // der Standardeinstieg.
        $this->assertStringContainsString("'overview' => ['label' => __('app.settings_overview')", $view);
        $this->assertStringContainsString('default="overview"', $view);
        $this->assertStringNotContainsString("'general' => ['label' => __('app.general')", $view);

        // Jeder Teaser wechselt den Tab UND klappt die gemeinte Sektion auf.
        $this->assertStringContainsString('selectTab(@js((string) $tab), true)', $teaser);
        $this->assertStringContainsString("\$dispatch('rt-settings-open-section'", $teaser);

        foreach (['company', 'users', 'system'] as $tab) {
            $this->assertStringContainsString("'tab' => '{$tab}'", $view);
            $this->assertStringContainsString(
                "\$event.detail.tab === '{$tab}' && \$event.detail.section",
                $view,
            );
        }
    }

    public function test_superadmin_tab_is_reserved_for_the_first_user(): void
    {
        // Benutzer #1 ist der Super-Admin (User::isSuperAdmin()).
        $superAdmin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($superAdmin->isSuperAdmin());

        $html = Livewire::actingAs($superAdmin)->test(Settings::class)->html();

        $this->assertStringContainsString('data-tab-panel-id="superadmin"', $html);
        $this->assertStringContainsString('data-tab-panel-id="device-management"', $html);
        $this->assertStringContainsString('data-device-management-settings', $html);
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('Gespeicherte Verbindung testen', $html);
        $this->assertStringContainsString('data-superadmin-assistant-runtime', $html);
        $this->assertStringContainsString('assistant-speech-routing', $html);
        $this->assertStringContainsString('openrouter-api-key', $html);
        $this->assertStringContainsString('data-superadmin-call-settings', $html);
        $this->assertStringContainsString('calls-ring_timeout', $html);
        $this->assertStringContainsString('data-assistant-knowledge-manager', $html);
        $this->assertStringContainsString('assistant-knowledge-intro', $html);

        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $this->assertFalse($otherAdmin->isSuperAdmin());

        $otherComponent = Livewire::actingAs($otherAdmin)
            ->test(Settings::class)
            ->assertSet('assistantSpeechRouting', '')
            ->assertSet('assistantSpeechStatus', [])
            ->assertSet('calls', [])
            ->assertSet('openRouter', []);
        $otherHtml = $otherComponent->html();

        $this->assertStringContainsString('assistant-enabled', $otherHtml);
        $this->assertStringNotContainsString('data-tab-panel-id="superadmin"', $otherHtml);
        $this->assertStringNotContainsString('data-tab-panel-id="device-management"', $otherHtml);
        $this->assertStringNotContainsString('data-device-management-settings', $otherHtml);
        $this->assertStringNotContainsString('Gespeicherte Verbindung testen', $otherHtml);
        $this->assertStringNotContainsString('data-superadmin-assistant-runtime', $otherHtml);
        $this->assertStringNotContainsString('assistant-speech-routing', $otherHtml);
        $this->assertStringNotContainsString('openrouter-api-key', $otherHtml);
        $this->assertStringNotContainsString('data-superadmin-call-settings', $otherHtml);
        $this->assertStringNotContainsString('calls-ring_timeout', $otherHtml);
        $this->assertStringNotContainsString('data-assistant-knowledge-manager', $otherHtml);
        $this->assertStringNotContainsString('assistant-knowledge-intro', $otherHtml);
    }

    public function test_a_regular_admin_cannot_mount_device_provider_settings_directly(): void
    {
        User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($otherAdmin)
            ->test(DeviceManagementSettingsComponent::class)
            ->assertForbidden();
    }

    public function test_device_provider_form_rejects_short_secrets_and_incomplete_remote_urls(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($superAdmin)
            ->test(DeviceManagementSettingsComponent::class)
            ->set('providers.meshcentral.token', 'zu-kurz')
            ->set('providers.meshcentral.remote_url_template', 'https://remote.example.test/device')
            ->call('saveProvider', 'meshcentral')
            ->assertHasErrors([
                'providers.meshcentral.token',
                'providers.meshcentral.remote_url_template',
            ]);
    }

    public function test_device_provider_service_errors_are_mapped_without_exposing_raw_details(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);
        $form = app(DeviceManagementSettingsService::class)->forForm();

        app()->instance(DeviceManagementSettingsService::class, new class($form)
        {
            /** @param array<string, mixed> $form */
            public function __construct(private readonly array $form) {}

            /** @return array<string, mixed> */
            public function forForm(): array
            {
                return $this->form;
            }

            /** @param array<string, mixed> $values */
            public function saveProvider(string $provider, array $values): void
            {
                throw new InvalidArgumentException('Interner Rohfehler mit vertraulichem Pfad C:\\server\\connector');
            }
        });

        Livewire::actingAs($superAdmin)
            ->test(DeviceManagementSettingsComponent::class)
            ->call('saveProvider', 'openuem')
            ->assertHasErrors(['providers.openuem'])
            ->assertDontSee('Interner Rohfehler')
            ->assertSee('Prüfen Sie Zugangsdaten');

        app()->instance(DeviceManagementSettingsService::class, new class($form)
        {
            /** @param array<string, mixed> $form */
            public function __construct(private readonly array $form) {}

            /** @return array<string, mixed> */
            public function forForm(): array
            {
                return $this->form;
            }

            /** @param array<string, mixed> $values */
            public function saveProvider(string $provider, array $values): void
            {
                throw new RuntimeException('Datenbank-DSN darf nicht in die UI');
            }
        });

        Livewire::actingAs($superAdmin)
            ->test(DeviceManagementSettingsComponent::class)
            ->call('saveProvider', 'openuem')
            ->assertHasErrors(['providers.openuem'])
            ->assertDontSee('Datenbank-DSN')
            ->assertSee('Bitte versuchen Sie es erneut');
    }

    public function test_production_device_setup_rejects_local_and_reserved_base_domains(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $superAdmin = User::factory()->create(['role' => 'admin']);

        foreach (['localhost', 'devices.internal', 'devices.example.com', 'devices.test'] as $domain) {
            Livewire::actingAs($superAdmin)
                ->test(DeviceManagementSettingsComponent::class)
                ->set('deployment.mode', 'subdomain')
                ->set('deployment.base_domain', $domain)
                ->call('saveDeployment')
                ->assertHasErrors(['deployment.base_domain'])
                ->assertSee('öffentlich auflösbare FQDN');
        }
    }

    public function test_provider_target_change_accepts_both_explicitly_reentered_credentials(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);
        $form = app(DeviceManagementSettingsService::class)->forForm();
        $form['providers']['openuem']['subdomain'] = 'desktop-old';
        $form['providers']['openuem']['token'] = DeviceManagementSettingsService::SECRET_MASK;
        $form['providers']['openuem']['token_configured'] = true;
        $form['providers']['openuem']['webhook_secret'] = DeviceManagementSettingsService::SECRET_MASK;
        $form['providers']['openuem']['webhook_secret_configured'] = true;
        $this->bindReadOnlyDeviceSettingsForm($form, allowSave: true);

        Livewire::actingAs($superAdmin)
            ->test(DeviceManagementSettingsComponent::class)
            ->set('providers.openuem.subdomain', 'desktop-new')
            ->set('providers.openuem.token', str_repeat('t', 32))
            ->set('providers.openuem.webhook_secret', str_repeat('w', 32))
            ->call('saveProvider', 'openuem')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast');
    }

    public function test_changing_only_the_inactive_provider_fallback_does_not_require_credentials(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);

        foreach (['subdomain' => 'adapter_port', 'port' => 'subdomain'] as $mode => $fallbackField) {
            $form = app(DeviceManagementSettingsService::class)->forForm();
            $form['deployment']['mode'] = $mode;
            $this->bindReadOnlyDeviceSettingsForm($form, allowSave: true);
            $current = $form['providers']['openuem'][$fallbackField];
            $replacement = $fallbackField === 'adapter_port' ? (int) $current + 100 : $current.'-fallback';

            Livewire::actingAs($superAdmin)
                ->test(DeviceManagementSettingsComponent::class)
                ->set("providers.openuem.{$fallbackField}", $replacement)
                ->call('saveProvider', 'openuem')
                ->assertHasNoErrors()
                ->assertDispatched('swal:toast');
        }
    }

    public function test_activating_a_provider_rejects_effective_endpoint_collisions_in_both_modes(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);

        foreach (['subdomain' => 'subdomain', 'port' => 'adapter_port'] as $mode => $expectedField) {
            $form = app(DeviceManagementSettingsService::class)->forForm();
            $form['deployment']['mode'] = $mode;
            $form['providers']['openuem']['enabled'] = true;
            $form['providers']['openuem']['subdomain'] = 'shared-target';
            $form['providers']['openuem']['adapter_port'] = 9550;
            $form['providers']['headwind']['enabled'] = false;
            $form['providers']['headwind']['subdomain'] = 'shared-target';
            $form['providers']['headwind']['adapter_port'] = 9550;
            $this->bindReadOnlyDeviceSettingsForm($form);

            Livewire::actingAs($superAdmin)
                ->test(DeviceManagementSettingsComponent::class)
                ->set('providers.headwind.enabled', true)
                ->set('providers.headwind.token', str_repeat('t', 32))
                ->set('providers.headwind.webhook_secret', str_repeat('w', 32))
                ->call('saveProvider', 'headwind')
                ->assertHasErrors(["providers.headwind.{$expectedField}"])
                ->assertSee($mode === 'port' ? 'bereits vom aktiven Provider' : 'Connector-Ziel');
        }
    }

    public function test_a_regular_admin_can_still_toggle_railtime_assist(): void
    {
        User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        AssistantSettings::setEnabled(true);

        Livewire::actingAs($otherAdmin)
            ->test(Settings::class)
            ->assertSet('assistantEnabled', true)
            ->set('assistantEnabled', false)
            ->assertDispatched('assistant-settings-saved');

        $this->assertFalse(AssistantSettings::enabled(uncached: true));
    }

    public function test_a_regular_admin_cannot_change_or_probe_call_and_speech_runtime_settings(): void
    {
        User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($otherAdmin)
            ->test(Settings::class)
            ->call('saveCalls')
            ->assertForbidden();

        $this->assertNull(Setting::getValueUncached(CallSettings::GROUP, 'livekit'));

        Livewire::actingAs($otherAdmin)
            ->test(Settings::class)
            ->call('refreshAssistantSpeechStatus')
            ->assertForbidden();
    }

    public function test_open_router_settings_are_stored_and_the_key_never_leaves_the_server(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($superAdmin)
            ->test(Settings::class)
            ->set('openRouter.api_url', 'https://openrouter.ai/api/v1/chat/completions')
            ->set('openRouter.api_key', 'sk-or-v1-geheim')
            ->set('openRouter.referer_url', 'https://app.rail-time.de')
            ->set('openRouter.model_title', 'RailTime')
            ->set('openRouter.text_model', 'openai/gpt-4o-mini')
            ->set('openRouter.tts_voice', 'alloy')
            ->set('openRouter.timeout', 90)
            ->set('openRouter.temperature', 0.6)
            ->set('openRouter.max_completion_tokens', 2000)
            ->set('openRouter.stream_enabled', false)
            ->call('saveOpenRouter')
            ->assertHasNoErrors()
            // Nach dem Speichern zeigt das Feld nur noch die Maske.
            ->assertSet('openRouter.api_key', OpenRouterSettings::SECRET_MASK);

        $stored = (array) Setting::getValueUncached(OpenRouterSettings::GROUP, OpenRouterSettings::KEY);

        $this->assertNotSame('sk-or-v1-geheim', $stored['api_key']);
        $this->assertStringStartsWith('enc:v1:', $stored['api_key']);
        $this->assertSame('sk-or-v1-geheim', OpenRouterSettings::all(uncached: true)['api_key']);
        $this->assertSame('openai/gpt-4o-mini', $stored['text_model']);
        $this->assertSame('alloy', $stored['tts_voice']);
        $this->assertSame(90, $stored['timeout']);
        $this->assertSame(0.6, $stored['temperature']);
        $this->assertFalse($stored['stream_enabled']);
    }

    public function test_saving_another_field_keeps_the_stored_key(): void
    {
        // Sonst loeschte jedes Speichern eines anderen Feldes das Geheimnis,
        // weil die Oberflaeche nur die Maske zurueckschickt.
        $superAdmin = User::factory()->create(['role' => 'admin']);

        OpenRouterSettings::save([
            'api_url' => 'https://openrouter.ai/api/v1/chat/completions',
            'api_key' => 'sk-or-v1-bestand',
        ]);

        $storedBefore = (array) Setting::getValueUncached(OpenRouterSettings::GROUP, OpenRouterSettings::KEY);

        Livewire::actingAs($superAdmin)
            ->test(Settings::class)
            ->assertSet('openRouter.api_key', OpenRouterSettings::SECRET_MASK)
            ->set('openRouter.model_title', 'RailTime Betrieb')
            ->call('saveOpenRouter')
            ->assertHasNoErrors();

        $stored = (array) Setting::getValueUncached(OpenRouterSettings::GROUP, OpenRouterSettings::KEY);

        $this->assertSame($storedBefore['api_key'], $stored['api_key']);
        $this->assertNotSame('sk-or-v1-bestand', $stored['api_key']);
        $this->assertSame('sk-or-v1-bestand', OpenRouterSettings::all(uncached: true)['api_key']);
        $this->assertSame('RailTime Betrieb', $stored['model_title']);
    }

    public function test_a_regular_admin_cannot_save_open_router_settings(): void
    {
        User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($otherAdmin)
            ->test(Settings::class)
            ->call('saveOpenRouter')
            ->assertForbidden();

        $this->assertNull(Setting::getValueUncached(OpenRouterSettings::GROUP, OpenRouterSettings::KEY));
    }

    public function test_only_the_superadmin_can_change_the_global_speech_route(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($superAdmin)
            ->test(Settings::class)
            ->assertSet('assistantSpeechRouting', AssistantSpeechSettings::DEFAULT_MODE)
            ->set('assistantSpeechRouting', AssistantSpeechSettings::EXTERNAL_ONLY)
            ->assertHasNoErrors()
            ->assertDispatched('assistant-speech-settings-saved');

        $this->assertSame(
            AssistantSpeechSettings::EXTERNAL_ONLY,
            AssistantSpeechSettings::mode(uncached: true),
        );

        Livewire::actingAs($otherAdmin)
            ->test(Settings::class)
            ->call('saveAssistantSpeechRouting')
            ->assertForbidden();
    }

    public function test_open_router_runtime_values_stay_within_their_limits(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($superAdmin)
            ->test(Settings::class)
            ->set('openRouter.api_url', 'https://openrouter.ai/api/v1/chat/completions')
            ->set('openRouter.timeout', 5000)
            ->set('openRouter.temperature', 9)
            ->call('saveOpenRouter')
            ->assertHasErrors(['openRouter.timeout', 'openRouter.temperature']);
    }

    public function test_settings_page_stays_closed_for_users_without_the_permission(): void
    {
        User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        Livewire::actingAs($staff)
            ->test(Settings::class)
            ->assertForbidden();
    }

    /** @param array<string, mixed> $form */
    private function bindReadOnlyDeviceSettingsForm(array $form, bool $allowSave = false): void
    {
        app()->instance(DeviceManagementSettingsService::class, new class($form, $allowSave)
        {
            /** @param array<string, mixed> $form */
            public function __construct(
                private readonly array $form,
                private readonly bool $allowSave,
            ) {}

            /** @return array<string, mixed> */
            public function forForm(): array
            {
                return $this->form;
            }

            /** @param array<string, mixed> $values */
            public function saveProvider(string $provider, array $values): void
            {
                if (! $this->allowSave) {
                    throw new RuntimeException('Provider-Save darf bei einer UI-Validierung nicht erreicht werden.');
                }
            }
        });
    }
}
