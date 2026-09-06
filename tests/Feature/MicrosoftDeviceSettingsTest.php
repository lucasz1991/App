<?php

namespace Tests\Feature;

use App\Jobs\SyncMicrosoftDevices;
use App\Livewire\Admin\MicrosoftDeviceSettings as MicrosoftDeviceSettingsComponent;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MicrosoftDeviceSettingsTest extends TestCase
{
    use DatabaseMigrations;

    private MicrosoftDeviceSettings $settings;

    private User $administrator;

    private const TENANT = '12345678-1234-4234-8234-123456789012';

    private const CLIENT = '22345678-1234-4234-8234-123456789012';

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($this->administrator);
        $this->settings = app(MicrosoftDeviceSettings::class);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_configuration_defaults_are_disabled_and_do_not_read_environment_credentials(): void
    {
        $configuration = $this->settings->configuration();

        $this->assertFalse($configuration['enabled']);
        $this->assertFalse($configuration['intune_enabled']);
        $this->assertTrue($configuration['auto_assign']);
        $this->assertTrue($configuration['sync_on_sign_in']);
        $this->assertSame(15, $configuration['sync_interval_minutes']);
        $this->assertSame('', $configuration['client_secret']);
        $this->assertFalse($this->settings->status()['configured']);
        $this->assertSame([], $this->settings->status()['last_run']);
    }

    public function test_secret_is_encrypted_masked_and_retained_for_empty_or_masked_submission(): void
    {
        $secret = 'new-secret-that-must-never-leak';
        $this->configure(['client_secret' => $secret]);
        $stored = Setting::getValueUncached(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY);

        $this->assertStringStartsWith('enc:v1:', $stored['client_secret']);
        $this->assertStringNotContainsString($secret, json_encode($stored));
        $this->assertSame($secret, $this->settings->configuration()['client_secret']);
        $this->assertSame(MicrosoftDeviceSettings::SECRET_MASK, $this->settings->forForm()['client_secret']);
        $this->assertTrue($this->settings->forForm()['secret_configured']);

        $this->settings->save(['client_secret' => '', 'sync_interval_minutes' => 20], $this->administrator);
        $this->settings->save(['client_secret' => MicrosoftDeviceSettings::SECRET_MASK], $this->administrator);
        $retained = Setting::getValueUncached(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY);
        $this->assertSame($stored['client_secret'], $retained['client_secret']);
        $this->assertSame(20, $this->settings->configuration()['sync_interval_minutes']);
        $this->assertStringNotContainsString($secret, DB::table('activity_log')->pluck('properties')->implode(' '));
    }

    public function test_snapshot_keeps_credentials_and_fingerprint_together_when_configuration_changes_after_its_read(): void
    {
        $this->configure();
        $originalConfiguration = $this->settings->configuration();
        $originalFingerprint = $this->settings->fingerprint();
        $changed = Setting::getValueUncached(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY);
        $changed['tenant_id'] = '32345678-1234-4234-8234-123456789012';
        $selects = 0;
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, &$selects, $changed): void {
            if (! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'settings')) {
                return;
            }
            $selects++;
            if (! $armed) {
                return;
            }
            // Simulate a concurrent administrator update after the result was
            // fetched but before any hypothetical second configuration read.
            $armed = false;
            DB::table('settings')->where('type', MicrosoftDeviceSettings::GROUP)
                ->where('key', MicrosoftDeviceSettings::KEY)
                ->update(['value' => json_encode($changed)]);
        });

        $snapshot = $this->settings->snapshot();

        $this->assertSame(1, $selects);
        $this->assertSame($originalConfiguration, $snapshot['configuration']);
        $this->assertSame($originalFingerprint, $snapshot['fingerprint']);
        $this->assertNotSame($snapshot['fingerprint'], $this->settings->fingerprint());
        $this->assertSame($changed['tenant_id'], $this->settings->configuration()['tenant_id']);
    }

    public function test_explicit_clear_and_changed_tenant_without_new_secret_disable_the_connection(): void
    {
        $this->configure();
        $this->settings->save(['tenant_id' => '32345678-1234-4234-8234-123456789012'], $this->administrator);
        $this->assertFalse($this->settings->configuration()['enabled']);
        $this->assertSame('', $this->settings->configuration()['client_secret']);

        $this->configure();
        $this->settings->save(['client_id' => '42345678-1234-4234-8234-123456789012', 'client_secret' => MicrosoftDeviceSettings::SECRET_MASK], $this->administrator);
        $this->assertFalse($this->settings->configuration()['enabled']);
        $this->assertSame('', $this->settings->configuration()['client_secret']);

        $this->configure();
        $this->settings->save(['clear_client_secret' => true, 'client_secret' => 'ignored-new-secret'], $this->administrator);
        $this->assertFalse($this->settings->configuration()['enabled']);
        $this->assertSame('', $this->settings->configuration()['client_secret']);
    }

    public function test_new_target_can_remain_enabled_only_with_an_explicit_fresh_secret(): void
    {
        $this->configure();
        $this->settings->save([
            'tenant_id' => '32345678-1234-4234-8234-123456789012',
            'client_secret' => 'explicitly-new-target-secret',
        ], $this->administrator);

        $this->assertTrue($this->settings->configuration()['enabled']);
        $this->assertSame('explicitly-new-target-secret', $this->settings->configuration()['client_secret']);
    }

    public function test_configuration_reads_are_uncached_and_plaintext_or_corrupt_secrets_are_not_used(): void
    {
        $this->configure();
        Setting::getValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY);
        $stored = Setting::getValueUncached(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY);
        $stored['client_secret'] = 'plaintext-secret';
        DB::table('settings')->where('type', MicrosoftDeviceSettings::GROUP)->where('key', MicrosoftDeviceSettings::KEY)->update(['value' => json_encode($stored)]);

        $this->assertSame('', $this->settings->configuration()['client_secret']);
        $this->assertFalse($this->settings->configuration()['enabled']);

        $stored['client_secret'] = 'enc:v1:broken';
        Setting::setValue(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY, $stored);
        $this->assertSame('', $this->settings->configuration()['client_secret']);
        $this->assertFalse($this->settings->configuration()['enabled']);
    }

    public function test_invalid_ids_intervals_and_incomplete_activation_cannot_be_saved(): void
    {
        foreach ([['tenant_id' => 'common'], ['client_id' => 'not-a-uuid'], ['sync_interval_minutes' => 4], ['sync_interval_minutes' => 1441], ['enabled' => true]] as $values) {
            try {
                $this->settings->save($values, $this->administrator);
                $this->fail('Invalid Microsoft settings must fail validation.');
            } catch (ValidationException) {
                $this->assertFalse($this->settings->configuration()['enabled']);
            }
        }
    }

    public function test_only_the_superadministrator_can_change_credentials_or_mount_the_form(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => true]);
        Livewire::actingAs($otherAdmin)->test(MicrosoftDeviceSettingsComponent::class)->assertForbidden();

        try {
            $this->settings->save(['tenant_id' => self::TENANT], $otherAdmin);
            $this->fail('A regular administrator must not change credentials.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('', $this->settings->configuration()['tenant_id']);
    }

    public function test_run_and_diagnostic_only_persist_allowlisted_status_and_counters(): void
    {
        $this->configure();
        $fingerprint = $this->settings->fingerprint();
        $this->settings->recordRun([
            'status' => 'success', 'message' => 'secret raw Microsoft response', 'discovered' => 3,
            'created' => 2, 'assigned' => 1, 'conflicts' => -1, 'updated' => 'invalid',
            'access_token' => 'do-not-save', 'raw_response' => ['customer' => 'private'],
        ], $fingerprint);
        $this->settings->recordDiagnostic(['status' => 'partial', 'intune_status' => 'forbidden', 'message' => 'private response', 'raw_error' => 'secret'], $fingerprint);

        $status = $this->settings->status();
        $this->assertSame('success', $status['last_run']['status']);
        $this->assertSame(3, $status['last_run']['discovered']);
        $this->assertSame(1, $status['last_run']['assigned']);
        $this->assertArrayNotHasKey('conflicts', $status['last_run']);
        $this->assertArrayNotHasKey('updated', $status['last_run']);
        $this->assertNotNull($status['last_sync_at']);
        $this->assertSame('partial', $status['diagnostic']['status']);
        $this->assertSame('forbidden', $status['diagnostic']['intune_status']);
        $this->assertStringContainsString('DeviceManagementManagedDevices.Read.All', $status['diagnostic']['intune_message']);
        $raw = json_encode(Setting::getValueUncached(MicrosoftDeviceSettings::GROUP, MicrosoftDeviceSettings::KEY));
        $this->assertStringNotContainsString('private', $raw);
        $this->assertStringNotContainsString('do-not-save', $raw);
        $this->assertStringNotContainsString('raw Microsoft', $raw);
    }

    public function test_config_changes_clear_old_status_and_ignore_late_results_for_previous_configuration(): void
    {
        $this->configure();
        $oldFingerprint = $this->settings->fingerprint();
        $this->settings->recordRun(['status' => 'success', 'created' => 3], $oldFingerprint);
        $this->settings->save(['intune_enabled' => true], $this->administrator);
        $this->assertSame([], $this->settings->status()['last_run']);
        $this->assertNotSame($oldFingerprint, $this->settings->fingerprint());
        $this->settings->recordRun(['status' => 'success', 'created' => 99], $oldFingerprint);
        $this->settings->recordDiagnostic(['status' => 'success'], $oldFingerprint);
        $this->assertSame([], $this->settings->status()['last_run']);
        $this->assertSame([], $this->settings->status()['diagnostic']);

        $this->settings->recordRun(['status' => 'partial', 'conflicts' => 2], $this->settings->fingerprint());
        $this->assertSame(2, $this->settings->status()['last_run']['conflicts']);
    }

    public function test_form_saves_and_masks_secret_and_renders_the_registered_ui_components(): void
    {
        $component = Livewire::actingAs($this->administrator)
            ->test(MicrosoftDeviceSettingsComponent::class)
            ->set('form.tenant_id', self::TENANT)
            ->set('form.client_id', self::CLIENT)
            ->set('form.client_secret', 'ui-secret-not-for-rendering')
            ->set('form.enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.client_secret', MicrosoftDeviceSettings::SECRET_MASK)
            ->assertSee('Windows-Geräte aus Microsoft Entra übernehmen')
            ->assertSee('Device.Read.All')
            ->assertSee('Jetzt synchronisieren')
            ->assertDontSee('ui-secret-not-for-rendering');

        $this->assertTrue($this->settings->configuration()['enabled']);
        $component->set('form.tenant_id', 'invalid')->call('save')->assertHasErrors(['form.tenant_id']);
    }

    public function test_manual_sync_uses_the_queue_and_does_not_call_graph_in_the_ui_request(): void
    {
        $this->configure();
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 300);
        Bus::fake([SyncMicrosoftDevices::class]);

        Livewire::actingAs($this->administrator)
            ->test(MicrosoftDeviceSettingsComponent::class)
            ->call('syncNow')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast', type: 'success');

        Bus::assertDispatched(SyncMicrosoftDevices::class);
    }

    public function test_probe_failures_do_not_expose_transport_details_or_secrets_in_the_form(): void
    {
        $sync = Mockery::mock(MicrosoftDeviceSyncService::class);
        $sync->shouldReceive('probe')->once()->andThrow(new RuntimeException('private-client-secret-and-response'));
        app()->instance(MicrosoftDeviceSyncService::class, $sync);

        Livewire::actingAs($this->administrator)
            ->test(MicrosoftDeviceSettingsComponent::class)
            ->call('testConnection')
            ->assertHasErrors(['connection'])
            ->assertDontSee('private-client-secret-and-response')
            ->assertSee('Der Microsoft-Verbindungstest konnte nicht abgeschlossen werden.');
    }

    /** @param array<string, mixed> $overrides */
    private function configure(array $overrides = []): void
    {
        $this->settings->save(array_merge([
            'enabled' => true,
            'tenant_id' => self::TENANT,
            'client_id' => self::CLIENT,
            'client_secret' => 'a-test-only-client-secret',
        ], $overrides), $this->administrator);
    }
}
