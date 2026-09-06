<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Jobs\DispatchDeviceCommand;
use App\Livewire\Devices\DeviceManagement;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceEnrollment;
use App\Models\DeviceProviderLink;
use App\Models\User;
use App\Services\DeviceManagement\DeviceAccountPreparationService;
use App\Services\DeviceManagement\DeviceArtifactService;
use App\Services\DeviceManagement\DeviceCommandService;
use App\Services\DeviceManagement\DeviceEnrollmentService;
use App\Services\DeviceManagement\DeviceInventoryImportService;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceManagementSettings;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use App\Services\DeviceManagement\DeviceProvisioningProfileCatalog;
use App\Support\PageHelpCatalog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('device_management.providers.simulation.enabled', true);
        config()->set('device_management.providers.simulation.webhook_secret', 'test-device-webhook-secret');
        config()->set('device_management.production_commands_enabled', false);
        config()->set('device_management.artifacts.disk', 'private');
        Storage::fake('private');
    }

    public function test_device_migration_uses_mysql_safe_constraint_names_and_recovers_an_empty_interruption(): void
    {
        $migrationPath = database_path('migrations/2026_08_17_070000_create_device_management_tables.php');
        $source = file_get_contents($migrationPath);

        $this->assertIsString($source);
        $this->assertMatchesRegularExpression(
            "/foreignId\\('employee_identity_account_id'\\).*?constrained\\('employee_identity_accounts', 'id', 'dev_acc_identity_fk'\\)/s",
            $source,
        );
        $this->assertMatchesRegularExpression(
            "/foreignId\\('device_provisioning_profile_id'\\).*?constrained\\('device_provisioning_profiles', 'id', 'dev_acc_profile_fk'\\)/s",
            $source,
        );
        $this->assertLessThanOrEqual(64, strlen('dev_acc_identity_fk'));
        $this->assertLessThanOrEqual(64, strlen('dev_acc_profile_fk'));

        $migration = require $migrationPath;
        $migration->up();

        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('device_account_assignments'));
        $this->assertTrue(Schema::hasTable('device_commands'));
        $this->assertFalse(Schema::hasTable('device_provider_links'));
        $this->assertFalse(Schema::hasTable('device_identity_syncs'));
    }

    public function test_device_migration_never_deletes_an_interrupted_installation_with_data(): void
    {
        DB::table('devices')->insert([
            'public_id' => '8beef463-b375-4bca-8dd5-5cc46a6cbab4',
        ]);

        $migration = require database_path('migrations/2026_08_17_070000_create_device_management_tables.php');

        try {
            $migration->up();
            $this->fail('A populated interrupted installation must never be dropped automatically.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('enthält bereits Daten', $exception->getMessage());
        }

        $this->assertDatabaseHas('devices', [
            'public_id' => '8beef463-b375-4bca-8dd5-5cc46a6cbab4',
        ]);
    }

    public function test_device_migration_never_deletes_a_populated_later_table_during_recovery(): void
    {
        $deviceId = DB::table('devices')->insertGetId([
            'public_id' => '1ee06bb4-b9b6-4be7-8e1d-41e0c92e2934',
        ]);

        DB::table('device_provider_links')->insert([
            'device_id' => $deviceId,
            'provider' => 'meshcentral',
            'external_device_id' => 'node-recovery-guard',
            'role' => 'support',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_17_070000_create_device_management_tables.php');

        try {
            $migration->up();
            $this->fail('A populated later migration table must never be dropped during recovery.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('device_provider_links', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('device_provider_links'));
        $this->assertDatabaseHas('device_provider_links', [
            'provider' => 'meshcentral',
            'external_device_id' => 'node-recovery-guard',
        ]);
    }

    public function test_targeted_base_rollback_fails_closed_while_later_tables_exist(): void
    {
        $deviceId = DB::table('devices')->insertGetId([
            'public_id' => 'e081cf59-54ca-471a-b5c0-bf803a3f7929',
        ]);

        DB::table('device_provider_links')->insert([
            'device_id' => $deviceId,
            'provider' => 'meshcentral',
            'external_device_id' => 'node-rollback-guard',
            'role' => 'support',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_17_070000_create_device_management_tables.php');

        try {
            $migration->down();
            $this->fail('The base migration must not remove tables owned by later migrations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nachgelagerte Tabellen', $exception->getMessage());
            $this->assertStringContainsString('device_provider_links', $exception->getMessage());
            $this->assertStringContainsString('device_identity_syncs', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('device_provider_links'));
        $this->assertTrue(Schema::hasTable('device_identity_syncs'));
        $this->assertDatabaseHas('devices', [
            'public_id' => 'e081cf59-54ca-471a-b5c0-bf803a3f7929',
        ]);
        $this->assertDatabaseHas('device_provider_links', [
            'external_device_id' => 'node-rollback-guard',
        ]);
    }

    public function test_base_migration_rolls_back_after_later_migrations_are_reversed(): void
    {
        $microsoftMigration = require database_path('migrations/2026_09_06_020000_create_microsoft_device_links.php');
        $microsoftMigration->down();
        $identityLeaseMigration = require database_path('migrations/2026_08_23_000600_add_queue_lease_to_device_identity_syncs_table.php');
        $commandBindingMigration = require database_path('migrations/2026_08_23_000500_bind_device_commands_to_assignments.php');
        $providerEventMigration = require database_path('migrations/2026_08_23_000400_create_device_provider_events.php');
        $identityMigration = require database_path('migrations/2026_08_23_000300_create_device_identity_syncs_table.php');
        $providerLinkMigration = require database_path('migrations/2026_08_23_000200_create_device_provider_links.php');
        $enrollmentBindingMigration = require database_path('migrations/2026_08_23_000100_bind_device_enrollments_to_assignments.php');
        $baseMigration = require database_path('migrations/2026_08_17_070000_create_device_management_tables.php');

        $identityLeaseMigration->down();
        $commandBindingMigration->down();
        $providerEventMigration->down();
        $identityMigration->down();
        $providerLinkMigration->down();
        $enrollmentBindingMigration->down();
        $baseMigration->down();

        $this->assertFalse(Schema::hasTable('device_identity_syncs'));
        $this->assertFalse(Schema::hasTable('device_provider_events'));
        $this->assertFalse(Schema::hasTable('device_provider_links'));
        $this->assertFalse(Schema::hasTable('device_commands'));
        $this->assertFalse(Schema::hasTable('device_assignments'));
        $this->assertFalse(Schema::hasTable('devices'));
        $this->assertTrue(Schema::hasTable('users'));
    }

    public function test_device_permissions_are_fail_closed_for_normal_staff_and_available_to_admin(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'status' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);

        $this->assertFalse(Gate::forUser($staff)->allows('devices.view'));
        $this->assertFalse(Gate::forUser($staff)->allows('devices.wipe'));
        $this->assertTrue(Gate::forUser($admin)->allows('devices.view'));
        $this->assertTrue(Gate::forUser($admin)->allows('devices.wipe'));

        $this->actingAs($staff)->get(route('devices.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.devices'))->assertOk()->assertSee('Geräte');
    }

    public function test_manual_capture_and_csv_import_share_one_managed_modal(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $csv = implode("\n", [
            'Inventarnummer;Seriennummer;Gerätename;Hostname;Plattform;Gerätetyp;Eigentum;Hersteller;Modell;Betriebssystem;Standort;Mitarbeiter_E-Mail',
            'RT-MODAL-001;;Modal Notebook;RT-MODAL-NB-1;windows;laptop;corporate;Dell;Latitude;Windows 11;Hamburg;',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(DeviceManagement::class)
            ->assertSeeHtml('id="device-capture-modal"')
            ->assertSee('Einzelgerät')
            ->assertSee('CSV-Bestand')
            ->assertSet('showCreateForm', false)
            ->assertSet('captureMode', 'manual')
            ->call('openCreate')
            ->assertSet('showCreateForm', true)
            ->set('deviceForm.display_name', 'Gerät ohne Inventarkennung')
            ->set('deviceForm.asset_tag', '')
            ->set('deviceForm.serial_number', '')
            ->call('createDevice')
            ->assertHasErrors(['deviceForm.asset_tag'])
            ->assertSet('showCreateForm', true)
            ->call('setCaptureMode', 'import')
            ->assertHasNoErrors()
            ->assertSet('captureMode', 'import')
            ->assertSee('Bestehende Geräteflotte importieren')
            ->set('inventoryImport', UploadedFile::fake()->createWithContent('devices.csv', $csv))
            ->call('importInventory')
            ->assertHasNoErrors()
            ->assertSet('showCreateForm', true)
            ->assertSet('lastImportSummary.rows', 1)
            ->assertSee('Import erfolgreich abgeschlossen')
            ->call('closeCreate')
            ->assertSet('showCreateForm', false)
            ->assertSet('captureMode', 'manual')
            ->assertSet('inventoryImport', null)
            ->assertSet('lastImportSummary', []);

        $this->assertDatabaseHas('devices', [
            'asset_tag' => 'RT-MODAL-001',
            'display_name' => 'Modal Notebook',
        ]);
    }

    public function test_inventory_assignment_identity_profiles_and_sensitive_fields_are_persisted_safely(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'employee@rail-time.de',
        ]);

        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-WIN-0001',
            'serial_number' => 'SERIAL-0001',
            'display_name' => 'Dienstlaptop Windows',
            'hostname' => 'RT-NB-0001',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'manufacturer' => 'Dell',
            'model' => 'Latitude',
            'os_version' => 'Windows 11',
            'declared_location' => 'Köln Hbf',
            'primary_provider' => 'simulation',
        ], $admin);

        $assignment = app(DeviceInventoryService::class)->assign($device, $employee, $admin);
        $profiles = app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365, AccountProvider::GoogleWorkspace],
        );

        $this->assertSame($employee->id, $assignment->user_id);
        $this->assertNotEmpty($profiles);
        $this->assertDatabaseHas('employee_identity_accounts', [
            'user_id' => $employee->id,
            'provider' => 'microsoft_365',
            'principal' => 'employee@rail-time.de',
            'provisioning_status' => 'pending_provider',
        ]);
        $this->assertDatabaseMissing('employee_identity_accounts', ['metadata' => 'employee@rail-time.de']);

        $rawMetadata = DB::table('employee_identity_accounts')
            ->where('user_id', $employee->id)
            ->value('metadata');
        $this->assertIsString($rawMetadata);
        $this->assertStringNotContainsString('password_stored', $rawMetadata);
        $this->assertStringNotContainsString('employee@rail-time.de', $rawMetadata);

        $configuration = DB::table('device_provisioning_profiles')->value('configuration');
        $this->assertIsString($configuration);
        $this->assertStringNotContainsString('oauth_mfa_once', $configuration);
        $this->assertStringNotContainsString(
            'password',
            json_encode(app(DeviceProvisioningProfileCatalog::class)->definitions(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_enrollment_uses_only_a_hash_and_is_bound_to_the_assigned_employee(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);

        $invitation = app(DeviceEnrollmentService::class)->invite(
            $device,
            $employee,
            'simulation',
            'agent',
            $admin,
            60,
        );

        $raw = DB::table('device_enrollments')->where('id', $invitation->enrollment->id)->first();
        $this->assertSame(hash('sha256', $invitation->plainToken), $raw->token_hash);
        $this->assertSame($device->activeAssignment()->value('id'), $raw->device_assignment_id);
        $this->assertStringNotContainsString($invitation->plainToken, json_encode($raw, JSON_THROW_ON_ERROR));

        try {
            app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $other);
            $this->fail('Foreign employee must not claim an enrollment.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $claim = app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $employee);
        try {
            app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $employee);
            $this->fail('A clear enrollment token must be unusable after its first atomic claim.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $reload = app(DeviceEnrollmentService::class)->resumeClaimed($claim->enrollment->public_id, $employee);
        $this->assertSame($invitation->enrollment->public_id, $claim->enrollment->public_id);
        $this->assertSame($claim->enrollment->public_id, $reload->enrollment->public_id);
        $this->assertSame('claimed', DeviceEnrollment::find($invitation->enrollment->id)->status->value);
        $this->assertNotSame(hash('sha256', $invitation->plainToken), DB::table('device_enrollments')->where('id', $invitation->enrollment->id)->value('token_hash'));
    }

    public function test_claimed_enrollment_setup_refreshes_via_session_without_reusing_the_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $invitation = app(DeviceEnrollmentService::class)->invite(
            $device,
            $employee,
            'simulation',
            'agent',
            $admin,
            60,
        );
        $url = route('devices.enrollment', ['token' => $invitation->plainToken]);

        $this->actingAs($employee)->get($url)->assertOk()->assertSee('Einrichtungsschritte');
        $this->get($url)->assertOk()->assertSee('Einrichtungsschritte');

        $this->assertSame('claimed', $invitation->enrollment->fresh()->status->value);
        $this->assertNotSame(
            hash('sha256', $invitation->plainToken),
            DB::table('device_enrollments')->where('id', $invitation->enrollment->id)->value('token_hash'),
        );
    }

    public function test_reassignment_revokes_user_context_and_rejects_stale_profile_commands(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $successor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $profiles = app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $invitation = app(DeviceEnrollmentService::class)->invite(
            $device,
            $employee,
            'simulation',
            'agent',
            $admin,
            60,
        );
        $queued = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'provider' => 'simulation',
            'type' => DeviceCommandType::ApplyProfile,
            'status' => DeviceCommandStatus::Queued,
            'is_destructive' => false,
            'justification' => 'Altes Mitarbeiterprofil wartet vor der Neuzuweisung.',
            'payload' => ['profiles' => [['assignment_id' => $profiles[0]->id]]],
            'correlation_id' => 'stale-profile-before-reassignment',
            'requested_by' => $admin->id,
            'requested_at' => now(),
        ]);

        app(DeviceInventoryService::class)->assign($device, $successor, $admin);

        $this->assertSame($successor->id, $device->fresh()->activeAssignment()->value('user_id'));
        $this->assertDatabaseHas('device_enrollments', [
            'id' => $invitation->enrollment->id,
            'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('device_account_assignments', [
            'id' => $profiles[0]->id,
            'desired_state' => 'unassigned',
            'status' => 'revoked',
        ]);
        $this->assertSame(DeviceCommandStatus::Cancelled, $queued->fresh()->status);

        try {
            app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $employee);
            $this->fail('A revoked handover invitation must not remain claimable.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        app(DeviceCommandService::class)->request(
            $device->fresh(),
            'simulation',
            DeviceCommandType::ApplyProfile,
            $admin,
            'Das alte Profil darf nach Mitarbeiterwechsel nicht mehr laufen.',
            ['profiles' => [['assignment_id' => $profiles[0]->id, 'principal' => $employee->email]]],
        );
    }

    public function test_enrollment_mode_matrix_rejects_incompatible_ownership_and_drives_the_ui(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-BYOD-ANDROID-1',
            'serial_number' => 'BYOD-ANDROID-1',
            'display_name' => 'Android BYOD',
            'form_factor' => 'phone',
            'platform' => 'android',
            'ownership' => 'byod',
            'primary_provider' => 'simulation',
        ], $admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        try {
            app(DeviceEnrollmentService::class)->invite(
                $device,
                $employee,
                'simulation',
                'fully_managed',
                $admin,
            );
            $this->fail('BYOD must never be offered a fully managed reset enrollment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mode', $exception->errors());
        }

        Livewire::actingAs($admin)
            ->test(DeviceManagement::class)
            ->call('selectDevice', $device->public_id)
            ->assertSet('enrollmentProvider', 'simulation')
            ->assertSet('enrollmentMode', 'agent')
            ->assertSee('Agent installieren')
            ->assertSee('Android-Arbeitsprofil')
            ->assertDontSee('Android vollständig verwaltet');
    }

    public function test_limited_enrollment_and_heartbeat_update_truthful_readiness(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-LIMITED-ANDROID-1',
            'serial_number' => 'LIMITED-ANDROID-1',
            'display_name' => 'Android Arbeitsprofil',
            'form_factor' => 'phone',
            'platform' => 'android',
            'ownership' => 'byod',
            'primary_provider' => 'simulation',
        ], $admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);
        $invitation = app(DeviceEnrollmentService::class)->invite(
            $device,
            $employee,
            'simulation',
            'work_profile',
            $admin,
        );
        app(DeviceEnrollmentService::class)->claim($invitation->plainToken, $employee);

        $this->postSignedDeviceWebhook([
            'event_id' => 'evt-enrollment-limited-1',
            'event_type' => 'enrollment.completed',
            'enrollment_id' => $invitation->enrollment->public_id,
        ])->assertOk();

        $this->assertSame('limited', $device->fresh()->management_status->value);
        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $device->id,
            'check_key' => 'enrollment',
            'status' => 'passed',
            'source' => 'provider_receipt',
        ]);

        $this->postSignedDeviceWebhook([
            'event_id' => 'evt-device-seen-1',
            'event_type' => 'device.seen',
            'device_id' => $device->public_id,
        ])->assertOk();

        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $device->id,
            'check_key' => 'provider_sync',
            'status' => 'passed',
            'source' => 'simulation',
        ]);
    }

    public function test_commands_are_capability_checked_and_wipe_requires_a_different_admin(): void
    {
        $requester = User::factory()->create(['role' => 'admin', 'status' => true]);
        $approver = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($requester, $employee);

        $command = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::Wipe,
            $requester,
            'Laborgerät nach dokumentiertem Rückgabe-Test sicher löschen.',
        );
        $this->assertSame(DeviceCommandStatus::PendingApproval, $command->status);

        try {
            app(DeviceCommandService::class)->approveWipe($command, $requester);
            $this->fail('Requester must not approve own wipe.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        app(DeviceCommandService::class)->approveWipe($command, $approver);
        $this->assertSame(DeviceCommandStatus::Succeeded, $command->fresh()->status);
        $this->assertSame($approver->id, $command->fresh()->approved_by);
    }

    public function test_csv_inventory_import_normalizes_blank_unique_fields_and_assigns_existing_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create([
            'role' => 'staff',
            'status' => true,
            'email' => 'import.employee@rail-time.de',
        ]);
        $csv = implode("\n", [
            'Inventarnummer;Seriennummer;Gerätename;Hostname;Plattform;Gerätetyp;Eigentum;Hersteller;Modell;Betriebssystem;Standort;Mitarbeiter_E-Mail',
            ';SERIAL-ONLY-001;Notebook 1;RT-IMPORT-1;windows;laptop;corporate;Dell;Latitude;Windows 11;Berlin;import.employee@rail-time.de',
            ';SERIAL-ONLY-002;Notebook 2;RT-IMPORT-2;windows;laptop;corporate;Dell;Latitude;Windows 11;Hamburg;',
        ]);

        $summary = app(DeviceInventoryImportService::class)->import(
            UploadedFile::fake()->createWithContent('devices.csv', $csv),
            $admin,
        );

        $this->assertSame(['created' => 2, 'updated' => 0, 'assigned' => 1, 'rows' => 2], $summary);
        $this->assertSame(2, Device::query()->whereNull('asset_tag')->count());
        $this->assertDatabaseHas('device_assignments', [
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => Device::query()->where('serial_number', 'SERIAL-ONLY-002')->value('id'),
            'check_key' => 'enrollment',
            'status' => 'pending',
        ]);
    }

    public function test_approved_private_artifact_can_be_referenced_but_secret_payload_keys_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $artifact = app(DeviceArtifactService::class)->store(
            $device,
            UploadedFile::fake()->createWithContent('diagnose.ps1', 'Get-ComputerInfo'),
            $admin,
        );
        app(DeviceArtifactService::class)->approve($artifact, $admin);

        $this->assertNotNull($artifact->fresh()->approved_at);
        $this->assertTrue(Storage::disk('private')->exists($artifact->storage_path));
        $this->assertSame(hash('sha256', 'Get-ComputerInfo'), $artifact->sha256);

        $command = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::ExecuteScript,
            $admin,
            'Diagnose auf dem ausgewiesenen Laborgerät ausführen.',
            [
                'artifact_public_id' => $artifact->public_id,
                'artifact_sha256' => $artifact->sha256,
                'artifact_kind' => $artifact->kind,
            ],
        );
        $this->assertSame(DeviceCommandStatus::Succeeded, $command->fresh()->status);

        try {
            app(DeviceCommandService::class)->request(
                $device,
                'simulation',
                DeviceCommandType::InstallSoftware,
                $admin,
                'Ein Skript darf nicht als Softwarepaket ausgeführt werden.',
                [
                    'artifact_public_id' => $artifact->public_id,
                    'artifact_sha256' => $artifact->sha256,
                    'artifact_kind' => $artifact->kind,
                ],
            );
            $this->fail('Artifact kind must be enforced in the service layer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }

        try {
            app(DeviceCommandService::class)->request(
                $device,
                'simulation',
                DeviceCommandType::ExecuteScript,
                $admin,
                'Ein veränderter Hash darf nicht in die Queue gelangen.',
                [
                    'artifact_public_id' => $artifact->public_id,
                    'artifact_sha256' => str_repeat('0', 64),
                    'artifact_kind' => $artifact->kind,
                ],
            );
            $this->fail('Artifact integrity must be enforced before queueing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payload', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::Sync,
            $admin,
            'Dieser Test muss das geheime Feld serverseitig ablehnen.',
            ['password' => 'must-never-be-stored'],
        );
    }

    public function test_global_kill_switch_stops_an_artifact_that_was_not_downloaded_yet(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $artifact = app(DeviceArtifactService::class)->store(
            $device,
            UploadedFile::fake()->createWithContent('diagnose.ps1', 'Get-ComputerInfo'),
            $admin,
        );
        app(DeviceArtifactService::class)->approve($artifact, $admin);

        $webhookSecret = str_repeat('w', 32);
        $settings = app(DeviceManagementSettings::class);
        $settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('t', 32),
            'webhook_secret' => $webhookSecret,
        ]);
        $settings->recordProviderDiagnostic('openuem', [
            'status' => 'healthy',
            'healthy' => true,
            'authenticated' => true,
            'contract_valid' => true,
            'contract' => [
                'upstream_reachable' => true,
                'upstream_authenticated' => true,
            ],
        ], $settings->providerFingerprint('openuem', fresh: true));
        $settings->setProductionCommandsEnabled(true);

        DeviceCommand::query()->create([
            'device_id' => $device->id,
            'provider' => 'openuem',
            'type' => DeviceCommandType::ExecuteScript,
            'status' => DeviceCommandStatus::Dispatched,
            'is_destructive' => false,
            'justification' => 'Freigegebenes Diagnoseskript kontrolliert ausführen.',
            'payload' => [
                'artifact_public_id' => $artifact->public_id,
                'artifact_sha256' => $artifact->sha256,
            ],
            'correlation_id' => 'artifact-kill-switch-test',
            'requested_by' => $admin->id,
            'requested_at' => now(),
            'dispatched_at' => now(),
        ]);

        $url = route('api.device-management.artifacts.show', [
            'provider' => 'openuem',
            'artifact' => $artifact,
        ]);
        $timestamp = (string) now()->timestamp;
        $path = (string) parse_url($url, PHP_URL_PATH);
        $signature = hash_hmac('sha256', $timestamp.'.GET.'.$path, $webhookSecret);
        $headers = [
            'X-RailTime-Timestamp' => $timestamp,
            'X-RailTime-Signature' => 'sha256='.$signature,
        ];

        $this->withHeaders($headers)->get($url)->assertOk();

        $settings->setProductionCommandsEnabled(false);

        $this->withHeaders($headers)->get($url)->assertNotFound();
    }

    public function test_dispatch_job_does_not_mark_a_command_dispatched_when_kill_switch_is_already_off(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        $settings = app(DeviceManagementSettings::class);
        $settings->saveProvider('openuem', [
            'enabled' => true,
            'token' => str_repeat('t', 32),
            'webhook_secret' => str_repeat('w', 32),
        ]);
        $settings->setProductionCommandsEnabled(false);

        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'provider' => 'openuem',
            'type' => DeviceCommandType::Sync,
            'status' => DeviceCommandStatus::Queued,
            'is_destructive' => false,
            'justification' => 'Synchronisierung bleibt bei aktivem Not-Aus sicher liegen.',
            'correlation_id' => 'dispatch-kill-switch-test',
            'requested_by' => $admin->id,
            'requested_at' => now(),
        ]);

        try {
            (new DispatchDeviceCommand($command->id, $device->id))
                ->handle(app(DeviceProviderRegistry::class));
            $this->fail('A disabled production command switch must stop the job.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Kill-Switch', $exception->getMessage());
        }

        $command->refresh();
        $this->assertSame(DeviceCommandStatus::Queued, $command->status);
        $this->assertNull($command->dispatched_at);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => DeviceCommand::class,
            'subject_id' => $command->id,
            'event' => 'device-command.dispatched',
        ]);
    }

    public function test_signed_provider_webhook_updates_only_allowed_readiness_checks(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);

        $payload = json_encode([
            'event_id' => 'evt-readiness-1',
            'event_type' => 'readiness.updated',
            'device_id' => $device->public_id,
            'checks' => [
                ['key' => 'network', 'status' => 'passed', 'evidence' => ['profile' => 'railtime-vpn']],
                ['key' => 'certificate', 'status' => 'passed', 'evidence' => ['valid' => true]],
            ],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'test-device-webhook-secret');

        $this->postJson(
            route('api.device-management.providers.events', ['provider' => 'simulation']),
            json_decode($payload, true, 16, JSON_THROW_ON_ERROR),
            ['X-RailTime-Timestamp' => $timestamp, 'X-RailTime-Signature' => 'sha256='.$signature],
        )->assertOk()->assertJson(['accepted' => true]);

        $this->assertDatabaseHas('device_readiness_checks', [
            'device_id' => $device->id,
            'check_key' => 'network',
            'status' => 'passed',
            'source' => 'simulation',
        ]);

        $this->postJson(
            route('api.device-management.providers.events', ['provider' => 'simulation']),
            ['event_id' => 'evt-invalid', 'event_type' => 'readiness.updated'],
            ['X-RailTime-Timestamp' => $timestamp, 'X-RailTime-Signature' => 'sha256='.str_repeat('0', 64)],
        )->assertUnauthorized();
    }

    public function test_admin_livewire_screen_renders_inventory_and_provider_readiness(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(DeviceManagement::class)
            ->assertOk()
            ->assertSee('Virtuelles Lager')
            ->assertSee('Gerätestandorte')
            ->assertSeeHtml('data-rt-premium-filter')
            ->assertSeeHtml('id="device-inventory-filters-title"')
            ->assertSeeHtml('aria-controls="device-inventory-filters-mobile"')
            ->assertSeeHtml('aria-label="Suche löschen"')
            ->assertSeeHtml('id="device-provider-readiness-modal"')
            ->assertSee('Provider- und Produktionsbereitschaft')
            ->assertSee('Microsoft 365')
            ->assertSet('showProviderReadiness', false)
            ->call('openProviderReadiness')
            ->assertSet('showProviderReadiness', true)
            ->call('closeProviderReadiness')
            ->assertSet('showProviderReadiness', false)
            ->set('search', '   ')
            ->assertSee('0 aktiv')
            ->assertDontSeeHtml('data-rt-filter-chip')
            ->set('search', 'Notebook')
            ->set('platformFilter', 'windows')
            ->set('formFactorFilter', 'laptop')
            ->set('lifecycleFilter', 'inventory')
            ->set('complianceFilter', 'warning')
            ->set('locationFilter', 'Köln Hbf')
            ->assertSeeHtml('data-rt-filter-chip')
            ->assertSee('Gerätetyp')
            ->assertSee('Compliance')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('platformFilter', '')
            ->assertSet('formFactorFilter', '')
            ->assertSet('lifecycleFilter', '')
            ->assertSet('complianceFilter', '')
            ->assertSet('locationFilter', '')
            ->assertDontSeeHtml('data-rt-filter-chip');

        $this->assertSame('Suche löschen', trans('app.clear_search', [], 'de'));
        $this->assertSame('Clear search', trans('app.clear_search', [], 'en'));
    }

    public function test_device_inventory_uses_the_shared_responsive_table_component(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);

        Livewire::actingAs($admin)
            ->test(DeviceManagement::class)
            ->assertSeeHtml('class="rt-ui-surface rt-ui-table')
            ->assertSeeHtml('rt-device-table')
            ->assertSeeHtml('data-table-row-interactive="true"')
            ->assertSeeHtml('data-device-row')
            ->assertSee($device->display_name)
            ->assertSee($employee->name)
            ->call('selectDeviceById', $device->id)
            ->assertSet('selectedDevicePublicId', $device->public_id)
            ->assertSeeHtml('aria-selected="true"');
    }

    public function test_device_detail_tabs_expose_their_accessible_relationships(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);

        Livewire::actingAs($admin)
            ->test(DeviceManagement::class)
            ->call('selectDevice', $device->public_id)
            ->assertSeeHtml('role="tablist"')
            ->assertSeeHtml('id="device-tab-'.$device->public_id.'-overview"')
            ->assertSeeHtml('aria-controls="device-panel-'.$device->public_id.'-overview"')
            ->assertSeeHtml('id="device-panel-'.$device->public_id.'-overview"')
            ->assertSeeHtml('role="tabpanel"')
            ->assertSeeHtml('aria-labelledby="device-tab-'.$device->public_id.'-overview"');
    }

    public function test_authorized_remote_support_opens_only_the_configured_https_console(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->device($admin, $employee);
        config()->set('device_management.providers.meshcentral.enabled', true);
        config()->set(
            'device_management.providers.meshcentral.remote_url_template',
            'https://support.rail-time.test/device/{device_id}',
        );
        $device->ensureProviderLink(
            'meshcentral',
            DeviceProviderLink::ROLE_SUPPORT,
            'node/railtime.example/AbC_0123456789@$+=.remote-support-01',
        );

        Livewire::actingAs($admin)
            ->test(DeviceManagement::class)
            ->call('selectDevice', $device->public_id)
            ->call('openRemoteSupport', 'meshcentral')
            ->assertRedirect('https://support.rail-time.test/device/'.$device->public_id);
    }

    public function test_device_workspaces_have_specific_contextual_help(): void
    {
        $catalog = app(PageHelpCatalog::class);

        foreach (['devices.index', 'admin.devices', 'devices.mine', 'devices.enrollment'] as $route) {
            $help = $catalog->forRoute($route);
            $this->assertNotSame('Diese Seite', $help['title']);
            $this->assertGreaterThanOrEqual(4, count($help['points']));
        }
    }

    private function device(User $admin, User $employee): Device
    {
        $device = app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-'.strtoupper(fake()->bothify('??-####')),
            'serial_number' => strtoupper(fake()->bothify('SN-########')),
            'display_name' => 'RailTime Testgerät',
            'hostname' => 'RT-TEST-'.fake()->numberBetween(1000, 9999),
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'manufacturer' => 'Test',
            'model' => 'Lab',
            'os_version' => '1.0',
            'declared_location' => 'IT-Labor',
            'primary_provider' => 'simulation',
        ], $admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        return $device->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function postSignedDeviceWebhook(array $payload): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, 'test-device-webhook-secret');

        return $this->postJson(
            route('api.device-management.providers.events', ['provider' => 'simulation']),
            $payload,
            [
                'X-RailTime-Timestamp' => $timestamp,
                'X-RailTime-Signature' => 'sha256='.$signature,
            ],
        );
    }
}
