<?php

namespace Tests\Feature;

use App\Livewire\Admin\MicrosoftDeviceSettings as MicrosoftDeviceSettingsComponent;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftDeviceSyncScheduler;
use App\Services\DeviceManagement\MicrosoftDeviceSyncService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
        $this->fakeRuntime();
        $scheduler = Mockery::mock(app(MicrosoftDeviceSyncScheduler::class));
        $scheduler->shouldReceive('queue')->once()->with(true)->andReturn(true);
        app()->instance(MicrosoftDeviceSyncScheduler::class, $scheduler);

        Livewire::actingAs($this->administrator)
            ->test(MicrosoftDeviceSettingsComponent::class)
            ->call('syncNow')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast', type: 'success');

        Http::assertNothingSent();
    }

    public function test_missing_schema_blocks_sync_even_when_microsoft_connection_was_successful(): void
    {
        $this->configure();
        $this->settings->recordDiagnostic(['status' => 'success'], $this->settings->fingerprint());
        $this->fakeRuntime([
            'schema_ready' => false,
            'issues' => [['code' => 'schema_missing', 'message' => 'Die Microsoft-Gerätetabellen fehlen. Führen Sie php artisan migrate aus.']],
        ]);
        $scheduler = Mockery::mock(app(MicrosoftDeviceSyncScheduler::class));
        $scheduler->shouldNotReceive('queue');
        app()->instance(MicrosoftDeviceSyncScheduler::class, $scheduler);

        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class)
            ->assertSee('Führen Sie php artisan migrate aus.')
            ->assertSee('Microsoft-Verbindung und Graph-Rechte')
            ->assertSee('bestätigt noch keine laufende Hintergrundverarbeitung')
            ->call('syncNow')
            ->assertHasErrors(['runtime']);

        $this->assertActionDisabled($component->html(), 'Jetzt synchronisieren', true);
        Http::assertNothingSent();
    }

    public function test_worker_probe_can_run_without_microsoft_configuration_or_device_import_schema(): void
    {
        $runtime = $this->fakeRuntime(['schema_ready' => false]);
        $runtime->shouldReceive('queueWorkerProbe')->once()->andReturn(true);
        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class);
        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', false);
        $this->assertActionDisabled($component->html(), 'Jetzt synchronisieren', true);

        $component->call('testBackgroundProcessing')
            ->assertHasNoErrors()
            ->assertDispatched('swal:toast', type: 'success')
            ->assertSee('ohne Microsoft-Zugriff und ohne Geräteänderung');

        Http::assertNothingSent();
        $this->assertSame('', $this->settings->configuration()['tenant_id']);
    }

    public function test_missing_queue_blocks_worker_probe_and_sync_both_in_ui_and_server_action(): void
    {
        $this->configure();
        $runtime = $this->fakeRuntime(['queue_ready' => false]);
        $runtime->shouldNotReceive('queueWorkerProbe');
        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class);
        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', true);
        $this->assertActionDisabled($component->html(), 'Jetzt synchronisieren', true);

        $component->call('testBackgroundProcessing')->assertHasErrors(['runtime']);
        Http::assertNothingSent();
    }

    public function test_ready_runtime_keeps_real_sync_and_probe_buttons_enabled(): void
    {
        $this->configure();
        $this->fakeRuntime();
        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class);

        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', false);
        $this->assertActionDisabled($component->html(), 'Jetzt synchronisieren', false);
    }

    public function test_running_device_job_disables_duplicate_sync_and_shows_worker_evidence(): void
    {
        $this->configure();
        $this->fakeRuntime([
            'scheduler' => ['state' => 'fresh', 'checked_at' => now()->toIso8601String()],
            'worker' => ['state' => 'busy', 'checked_at' => now()->toIso8601String()],
            'run' => ['id' => 'synthetic-running-job', 'status' => 'running', 'queued_at' => now()->subMinute()->toIso8601String(), 'started_at' => now()->toIso8601String(), 'finished_at' => null, 'message' => 'Der Microsoft-Queue-Worker verarbeitet den Auftrag.'],
        ]);
        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class)
            ->assertSee('Aktueller Kontakt')
            ->assertSee('Verarbeitet einen Auftrag')
            ->assertSee('Geräteauftrag: Geräteabgleich läuft')
            ->assertViewHas('runtimePolling', true);

        $this->assertActionDisabled($component->html(), 'Jetzt synchronisieren', true);
    }

    public function test_runtime_actions_recheck_superadministrator_permissions_after_mount(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $runtime = $this->fakeRuntime();
        $runtime->shouldNotReceive('queueWorkerProbe');
        $scheduler = Mockery::mock(app(MicrosoftDeviceSyncScheduler::class));
        $scheduler->shouldNotReceive('queue');
        app()->instance(MicrosoftDeviceSyncScheduler::class, $scheduler);

        foreach (['testBackgroundProcessing', 'syncNow', 'refreshRuntime'] as $action) {
            $this->actingAs($this->administrator);
            $component = Livewire::test(MicrosoftDeviceSettingsComponent::class);
            $this->actingAs($otherAdmin);
            $component->call($action)->assertForbidden();
        }
    }

    public function test_pending_worker_probe_is_not_a_worker_acknowledgement_and_polling_is_bounded(): void
    {
        $this->freezeTime();
        $this->fakeRuntime([
            'worker_probe' => ['status' => 'queued', 'queued_at' => now()->toIso8601String(), 'acknowledged_at' => null],
        ]);
        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class)
            ->assertSee('Test wartet auf Worker')
            ->assertSee('Kein aktueller Ausführungsnachweis')
            ->assertDontSee('Vom Worker bestätigt:')
            ->assertViewHas('runtimePolling', true);
        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', true);

        $this->travel(121)->seconds();
        $component->call('$refresh')
            ->assertViewHas('runtimePolling', false)
            ->assertSee('automatische Statusabfrage ist pausiert');
        $this->assertStringNotContainsString('wire:poll', $component->html());
        $component->call('refreshRuntime')->assertViewHas('runtimePolling', true);

        $this->fakeRuntime([
            'worker' => ['state' => 'seen', 'checked_at' => now()->toIso8601String()],
            'worker_probe' => ['status' => 'completed', 'queued_at' => now()->subMinute()->toIso8601String(), 'acknowledged_at' => now()->toIso8601String()],
        ]);
        $component->call('$refresh')
            ->assertViewHas('runtimePolling', false)
            ->assertSee('Worker hat den Test verarbeitet')
            ->assertSee('Vom Worker bestätigt:');
        $this->assertStringNotContainsString('wire:poll', $component->html());
    }

    public function test_failed_worker_and_overdue_job_remain_distinct_from_old_successful_import(): void
    {
        $this->configure();
        $this->settings->recordRun(['status' => 'success', 'discovered' => 4], $this->settings->fingerprint());
        $this->fakeRuntime([
            'scheduler' => ['state' => 'stale', 'checked_at' => now()->subHour()->toIso8601String()],
            'worker' => ['state' => 'failed', 'checked_at' => now()->subMinutes(5)->toIso8601String()],
            'run' => ['id' => 'synthetic-run', 'status' => 'failed', 'message' => 'Der Worker hat das Zeitlimit überschritten.', 'queued_at' => now()->subMinutes(10)->toIso8601String(), 'started_at' => now()->subMinutes(5)->toIso8601String(), 'finished_at' => now()->toIso8601String()],
            'overdue' => true,
        ]);

        Livewire::test(MicrosoftDeviceSettingsComponent::class)
            ->assertSee('Kontakt überfällig')
            ->assertSee('Letzte Ausführung fehlgeschlagen')
            ->assertSee('Geräteauftrag: Abgebrochen oder fehlgeschlagen')
            ->assertSee('Der Worker hat das Zeitlimit überschritten.')
            ->assertSee('Ein Hintergrundauftrag ist überfällig.')
            ->assertSee('Letztes gespeichertes Importergebnis')
            ->assertViewHas('runtimePolling', false);
    }

    public function test_runtime_errors_are_fail_closed_and_never_expose_transport_details(): void
    {
        $this->configure();
        $runtime = Mockery::mock(MicrosoftDeviceRuntime::class);
        $runtime->shouldReceive('status')->andThrow(new RuntimeException('private-db-credentials'));
        $runtime->shouldNotReceive('queueWorkerProbe');
        app()->instance(MicrosoftDeviceRuntime::class, $runtime);

        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class)
            ->assertSee('Der Betriebsstatus konnte nicht gelesen werden.')
            ->assertDontSee('private-db-credentials')
            ->call('testBackgroundProcessing')->assertHasErrors(['runtime']);
        $this->assertActionDisabled($component->html(), 'Jetzt synchronisieren', true);

        $runtime = $this->fakeRuntime();
        $runtime->shouldReceive('queueWorkerProbe')->once()->andThrow(new RuntimeException('private-queue-transport'));
        $component->call('testBackgroundProcessing')
            ->assertHasErrors(['runtime'])
            ->assertDontSee('private-queue-transport');
        Http::assertNothingSent();
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

    public function test_lost_worker_probe_becomes_retryable_but_an_overdue_real_queue_job_does_not(): void
    {
        $runtime = app(MicrosoftDeviceRuntime::class);
        $this->assertTrue($runtime->queueWorkerProbe());
        $original = DB::table('microsoft_device_runs')->where('kind', 'probe')->sole();
        $this->travel(3)->minutes();
        $component = Livewire::test(MicrosoftDeviceSettingsComponent::class);
        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', true);
        $this->assertFalse($runtime->queueWorkerProbe());
        $this->assertDatabaseCount('jobs', 1);

        DB::table('jobs')->where('id', $original->queue_job_id)->delete();
        $component->call('refreshRuntime')->assertSee('Ein Hintergrundauftrag ist nicht mehr in der Datenbankqueue vorhanden.');
        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', false);
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $original->id, 'status' => 'queued']);
        $component->call('testBackgroundProcessing')->assertHasNoErrors();
        $this->assertActionDisabled($component->html(), 'Hintergrundverarbeitung testen', true);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('microsoft_device_runs', ['id' => $original->id, 'status' => 'failed', 'outcome' => 'queue_lost']);
        Http::assertNothingSent();
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

    /** @param array<string, mixed> $overrides */
    private function fakeRuntime(array $overrides = []): Mockery\MockInterface
    {
        $runtime = Mockery::mock(MicrosoftDeviceRuntime::class);
        $runtime->shouldReceive('status')->andReturn(array_replace([
            'schema_ready' => true,
            'queue_ready' => true,
            'issues' => [],
            'scheduler' => ['state' => 'unknown', 'checked_at' => null],
            'worker' => ['state' => 'unknown', 'checked_at' => null],
            'run' => [],
            'overdue' => false,
            'worker_probe' => ['status' => 'unknown', 'queued_at' => null, 'acknowledged_at' => null],
        ], $overrides));
        app()->instance(MicrosoftDeviceRuntime::class, $runtime);

        return $runtime;
    }

    private function assertActionDisabled(string $html, string $label, bool $disabled): void
    {
        $document = new \DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $button = (new \DOMXPath($document))->query('//button[contains(normalize-space(.), "'.$label.'")]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $button, 'Missing action button: '.$label);
        $this->assertSame($disabled, $button->hasAttribute('disabled'), $label.' disabled state');
        $this->assertSame(! $disabled, $button->hasAttribute('wire:click'), $label.' handler state');
    }
}
