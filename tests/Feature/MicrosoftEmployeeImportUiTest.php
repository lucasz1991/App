<?php

namespace Tests\Feature;

use App\Livewire\Admin\MicrosoftEmployeeImport;
use App\Models\User;
use App\Services\DeviceManagement\MicrosoftDeviceSettings;
use App\Services\DeviceManagement\MicrosoftEmployeeImportService;
use App\Services\DeviceManagement\MicrosoftEmployeeImportSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MicrosoftEmployeeImportUiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '12345678-1234-4234-8234-123456789012';

    private const CLIENT = '22345678-1234-4234-8234-123456789012';

    private const OBJECT_ID = '33333333-3333-4333-8333-333333333333';

    private const SECOND_OBJECT_ID = '44444444-4444-4444-8444-444444444444';

    private const SECRET = 'synthetic-ui-only-secret-never-render';

    private User $administrator;

    private MicrosoftEmployeeImportSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->administrator = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->assertTrue($this->administrator->isSuperAdmin());
        $this->actingAs($this->administrator);
        $this->settings = app(MicrosoftEmployeeImportSettings::class);
    }

    public function test_only_the_active_superadministrator_can_mount_the_pilot_form(): void
    {
        Livewire::test(MicrosoftEmployeeImport::class)
            ->assertOk()
            ->assertSee('Entra-Mitarbeiter')
            ->assertSee('User.Read.All')
            ->assertSee('Inaktiv importieren')
            ->assertSee('noch kein automatischer Import');

        foreach (['admin', 'staff', 'guest'] as $role) {
            $other = User::factory()->create(['role' => $role, 'status' => true]);
            Livewire::actingAs($other)->test(MicrosoftEmployeeImport::class)->assertForbidden();
        }

        $this->administrator->forceFill(['status' => false])->save();
        Livewire::actingAs($this->administrator->fresh())->test(MicrosoftEmployeeImport::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_saving_the_pilot_only_persists_scope_without_graph_import_or_messages(): void
    {
        $this->configureMicrosoft();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        Livewire::test(MicrosoftEmployeeImport::class)
            ->set('enabled', true)
            ->set('pilotObjectIds', self::SECOND_OBJECT_ID.";\n".self::OBJECT_ID)
            ->set('confirmInactiveImport', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('pilotObjectIds', self::OBJECT_ID."\n".self::SECOND_OBJECT_ID)
            ->assertSet('loadedFingerprint', $this->settings->fingerprint())
            ->assertSet('confirmInactiveImport', false)
            ->assertDispatched('swal:toast', type: 'success')
            ->assertDontSee(self::SECRET);

        $this->assertSame([
            'enabled' => true,
            'pilot_object_ids' => [self::OBJECT_ID, self::SECOND_OBJECT_ID],
        ], $this->settings->forForm());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_invalid_pilot_ids_remain_field_errors_without_starting_graph_work(): void
    {
        $this->configureMicrosoft();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        Livewire::test(MicrosoftEmployeeImport::class)
            ->set('enabled', true)
            ->set('pilotObjectIds', 'not-an-object-id')
            ->call('save')
            ->assertHasErrors(['pilotObjectIds']);

        $this->assertFalse($this->settings->forForm()['enabled']);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_preview_passes_the_saved_fingerprint_and_current_actor_and_resets_confirmation(): void
    {
        $this->configurePilot();
        $fingerprint = $this->settings->fingerprint();
        $service = $this->mockService();
        $service->shouldReceive('preview')->once()
            ->with($fingerprint, Mockery::on(fn (User $actor): bool => $actor->is($this->administrator) && $actor->isActive()))
            ->andReturn(['status' => 'preview', 'would_create' => 1]);
        $service->shouldNotReceive('import');

        Livewire::test(MicrosoftEmployeeImport::class)
            ->set('confirmInactiveImport', true)
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSet('confirmInactiveImport', false);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_import_requires_explicit_confirmation_before_any_service_call(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $service->shouldNotReceive('import');
        $service->shouldNotReceive('preview');

        Livewire::test(MicrosoftEmployeeImport::class)
            ->call('importInactive')
            ->assertHasErrors(['operation'])
            ->assertSee('Bestätigen Sie zuerst')
            ->assertSet('confirmInactiveImport', false);

        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_import_binds_fingerprint_and_actor_and_consumes_confirmation_once(): void
    {
        $this->configurePilot();
        $fingerprint = $this->settings->fingerprint();
        $service = $this->mockService();
        $service->shouldReceive('import')->once()
            ->with($fingerprint, Mockery::on(fn (User $actor): bool => $actor->is($this->administrator) && $actor->isActive()))
            ->andReturn(['status' => 'success', 'created' => 0]);
        $service->shouldNotReceive('preview');

        Livewire::test(MicrosoftEmployeeImport::class)
            ->set('confirmInactiveImport', true)
            ->call('importInactive')
            ->assertHasNoErrors()
            ->assertSet('confirmInactiveImport', false)
            ->call('importInactive')
            ->assertHasErrors(['operation']);

        Http::assertNothingSent();
    }

    public function test_unsaved_ids_block_preview_and_import_without_user_mutations(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        foreach (['preview', 'importInactive'] as $action) {
            Livewire::test(MicrosoftEmployeeImport::class)
                ->set('pilotObjectIds', self::SECOND_OBJECT_ID)
                ->set('confirmInactiveImport', true)
                ->call($action)
                ->assertHasErrors(['operation'])
                ->assertSee('Konfiguration wurde geändert')
                ->assertSet('confirmInactiveImport', false);
        }

        $this->assertSame([self::OBJECT_ID], $this->settings->forForm()['pilot_object_ids']);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_unsaved_activation_change_blocks_both_actions(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        foreach (['preview', 'importInactive'] as $action) {
            Livewire::test(MicrosoftEmployeeImport::class)
                ->set('enabled', false)
                ->set('confirmInactiveImport', true)
                ->call($action)
                ->assertHasErrors(['operation']);
        }

        $this->assertTrue($this->settings->forForm()['enabled']);
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_disabled_saved_pilot_never_invokes_preview_or_import(): void
    {
        $this->configurePilot(false);
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        foreach (['preview', 'importInactive'] as $action) {
            Livewire::test(MicrosoftEmployeeImport::class)
                ->set('confirmInactiveImport', true)
                ->call($action)
                ->assertHasErrors(['operation'])
                ->assertSet('confirmInactiveImport', false);
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('employee_identity_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_persisted_scope_changes_after_mount_block_both_actions(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        foreach (['preview', 'importInactive'] as $action) {
            $this->settings->save(['enabled' => true, 'pilot_object_ids' => [self::OBJECT_ID]], $this->administrator);
            $component = Livewire::test(MicrosoftEmployeeImport::class)->set('confirmInactiveImport', true);
            $this->settings->save(['enabled' => true, 'pilot_object_ids' => [self::SECOND_OBJECT_ID]], $this->administrator);

            $component->call($action)
                ->assertHasErrors(['operation'])
                ->assertSee('Konfiguration wurde geändert')
                ->assertSet('confirmInactiveImport', false);
        }

        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_credential_rotation_after_mount_invalidates_the_saved_ui_fingerprint(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');

        foreach (['preview', 'importInactive'] as $action) {
            $component = Livewire::test(MicrosoftEmployeeImport::class)->set('confirmInactiveImport', true);
            app(MicrosoftDeviceSettings::class)->save([
                'client_secret' => 'synthetic-rotated-'.$action,
            ], $this->administrator);

            $component->call($action)
                ->assertHasErrors(['operation'])
                ->assertSee('Konfiguration wurde geändert')
                ->assertDontSee('synthetic-rotated-'.$action);
        }

        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_actions_reauthorize_after_the_authenticated_user_changes(): void
    {
        $this->configurePilot();
        $otherAdministrator = User::factory()->create(['role' => 'admin', 'status' => true]);
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');
        $configuration = $this->settings->forForm();

        foreach (['save', 'preview', 'importInactive', '$refresh'] as $action) {
            $this->actingAs($this->administrator);
            $component = Livewire::test(MicrosoftEmployeeImport::class)->set('confirmInactiveImport', true);
            $this->actingAs($otherAdministrator);
            $component->call($action)->assertForbidden();
        }

        $this->assertSame($configuration, $this->settings->forForm());
        $this->assertDatabaseCount('users', 2);
        Http::assertNothingSent();
    }

    public function test_actions_reauthorize_after_superadministrator_deactivation_or_role_loss(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $service->shouldNotReceive('preview');
        $service->shouldNotReceive('import');
        $configuration = $this->settings->forForm();

        foreach ([['status' => false], ['role' => 'staff']] as $revocation) {
            foreach (['save', 'preview', 'importInactive', '$refresh'] as $action) {
                $this->administrator->forceFill(['role' => 'admin', 'status' => true])->save();
                $this->actingAs($this->administrator->fresh());
                $component = Livewire::test(MicrosoftEmployeeImport::class)->set('confirmInactiveImport', true);
                $this->administrator->forceFill($revocation)->save();
                $this->actingAs($this->administrator->fresh());
                $component->call($action)->assertForbidden();
            }
        }

        $this->assertSame($configuration, $this->settings->forForm());
        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_service_exceptions_are_redacted_and_import_confirmation_is_consumed(): void
    {
        $this->configurePilot();
        $service = $this->mockService();
        $privateFailure = 'synthetic-private-token-and-raw-response';
        $service->shouldReceive('preview')->once()->andThrow(new RuntimeException($privateFailure));
        $service->shouldReceive('import')->once()->andThrow(new RuntimeException($privateFailure));

        foreach (['preview', 'importInactive'] as $action) {
            Livewire::test(MicrosoftEmployeeImport::class)
                ->set('confirmInactiveImport', true)
                ->call($action)
                ->assertHasErrors(['operation'])
                ->assertSet('confirmInactiveImport', false)
                ->assertDontSee($privateFailure)
                ->assertDontSee(self::SECRET);
        }

        $this->assertDatabaseCount('users', 1);
        Http::assertNothingSent();
    }

    public function test_save_exception_is_redacted_without_mutating_pilot_scope(): void
    {
        $this->configurePilot();
        $fingerprint = $this->settings->fingerprint();
        $settings = Mockery::mock($this->settings);
        $settings->shouldReceive('save')->once()->andThrow(new RuntimeException('synthetic-private-database-error'));
        app()->instance(MicrosoftEmployeeImportSettings::class, $settings);

        Livewire::test(MicrosoftEmployeeImport::class)
            ->set('pilotObjectIds', self::SECOND_OBJECT_ID)
            ->call('save')
            ->assertHasErrors(['operation'])
            ->assertDontSee('synthetic-private-database-error');

        $this->assertSame($fingerprint, $this->settings->fingerprint());
        Http::assertNothingSent();
    }

    public function test_result_ui_renders_allowlisted_counters_not_raw_provider_details(): void
    {
        $this->configurePilot();
        $this->settings->recordResult([
            'status' => 'partial', 'mode' => 'import', 'requested' => 1,
            'created' => 0, 'existing' => 0, 'would_create' => 0, 'conflicts' => 1,
            'skipped' => 0, 'message' => 'private-provider-message',
            'access_token' => 'private-provider-token',
            'raw_response' => '<script>private-provider-markup</script>',
        ], $this->settings->fingerprint());

        Livewire::test(MicrosoftEmployeeImport::class)
            ->assertSee('Zuordnungskonflikten')
            ->assertSee('Neu und inaktiv')
            ->assertSee('Konflikte')
            ->assertDontSee('private-provider-message')
            ->assertDontSee('private-provider-token')
            ->assertDontSee('private-provider-markup')
            ->assertDontSee(self::SECRET);

        Http::assertNothingSent();
    }

    public function test_loaded_configuration_fingerprint_cannot_be_changed_from_the_browser(): void
    {
        $this->configurePilot();
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(MicrosoftEmployeeImport::class)->set('loadedFingerprint', str_repeat('a', 64));
    }

    private function configureMicrosoft(): void
    {
        app(MicrosoftDeviceSettings::class)->save([
            'enabled' => true,
            'tenant_id' => self::TENANT,
            'client_id' => self::CLIENT,
            'client_secret' => self::SECRET,
        ], $this->administrator);
    }

    private function configurePilot(bool $enabled = true): void
    {
        $this->configureMicrosoft();
        $this->settings->save([
            'enabled' => $enabled,
            'pilot_object_ids' => [self::OBJECT_ID],
        ], $this->administrator);
    }

    private function mockService(): MockInterface
    {
        // A proxy supports a final service class without mocking the settings
        // or bypassing Livewire authorization/configuration checks.
        $service = Mockery::mock(app(MicrosoftEmployeeImportService::class));
        app()->instance(MicrosoftEmployeeImportService::class, $service);

        return $service;
    }
}
