<?php

namespace Tests\Feature;

use App\Enums\AccountProvider;
use App\Enums\DeviceCommandStatus;
use App\Enums\DeviceCommandType;
use App\Jobs\DispatchDeviceCommand;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceCommand;
use App\Models\User;
use App\Services\DeviceManagement\DeviceAccountPreparationService;
use App\Services\DeviceManagement\DeviceArtifactService;
use App\Services\DeviceManagement\DeviceCommandService;
use App\Services\DeviceManagement\DeviceInventoryService;
use App\Services\DeviceManagement\DeviceProviderRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceCommandAssignmentBindingTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('device_management.providers.simulation.enabled', true);
        Storage::fake('private');
        Queue::fake();
    }

    public function test_unsent_script_and_wipe_are_bound_and_cancelled_before_reassignment(): void
    {
        [$admin, $employee, $device] = $this->assignedDevice();
        $assignmentId = (int) $device->activeAssignment()->value('id');
        $artifact = app(DeviceArtifactService::class)->store(
            $device,
            UploadedFile::fake()->createWithContent('repair.ps1', 'Get-Service'),
            $admin,
        );
        app(DeviceArtifactService::class)->approve($artifact, $admin);

        $script = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::ExecuteScript,
            $admin,
            'Freigegebenes Reparaturskript kontrolliert auf dem Gerät ausführen.',
            [
                'artifact_public_id' => $artifact->public_id,
                'artifact_sha256' => $artifact->sha256,
                'artifact_kind' => $artifact->kind,
            ],
        );
        $wipe = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::Wipe,
            $admin,
            'Gerät nach bestätigter Rückgabe kontrolliert und vollständig löschen.',
        );

        $this->assertSame($assignmentId, (int) $script->device_assignment_id);
        $this->assertSame($assignmentId, (int) $wipe->device_assignment_id);
        $this->assertSame(2, app(DeviceCommandService::class)
            ->cancelPendingCommandsForAssignmentChange($device, $admin));

        $successor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->replaceAssignment($device, $successor, $admin);

        $this->assertSame(DeviceCommandStatus::Cancelled, $script->fresh()->status);
        $this->assertSame(DeviceCommandStatus::Cancelled, $wipe->fresh()->status);
        $this->assertNotSame($employee->id, $device->fresh()->activeAssignment()->value('user_id'));
    }

    public function test_wipe_approval_cancels_a_stale_legacy_assignment_context(): void
    {
        [$requester, , $device] = $this->assignedDevice();
        $approver = User::factory()->create(['role' => 'admin', 'status' => true]);
        $wipe = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::Wipe,
            $requester,
            'Gerät nach bestätigter Rückgabe kontrolliert und vollständig löschen.',
        );
        $this->replaceAssignment(
            $device,
            User::factory()->create(['role' => 'staff', 'status' => true]),
            $requester,
        );

        try {
            app(DeviceCommandService::class)->approveWipe($wipe, $approver);
            $this->fail('A wipe bound to the prior handover must never be approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval', $exception->errors());
        }

        $wipe->refresh();
        $this->assertSame(DeviceCommandStatus::Cancelled, $wipe->status);
        $this->assertNull($wipe->approved_by);
        $this->assertNull($wipe->dispatched_at);
    }

    public function test_dispatch_cancels_stale_assignment_context_before_provider_call(): void
    {
        [$admin, , $device] = $this->assignedDevice();
        $command = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::Sync,
            $admin,
            'Gerätebestand vor der geplanten Übergabe kontrolliert synchronisieren.',
        );
        $this->replaceAssignment(
            $device,
            User::factory()->create(['role' => 'staff', 'status' => true]),
            $admin,
        );

        (new DispatchDeviceCommand($command->id, $device->id))
            ->handle(app(DeviceProviderRegistry::class));

        $command->refresh();
        $this->assertSame(DeviceCommandStatus::Cancelled, $command->status);
        $this->assertNull($command->provider_job_id);
        $this->assertNull($command->dispatched_at);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => DeviceCommand::class,
            'subject_id' => $command->id,
            'event' => 'device-command.dispatched',
        ]);
    }

    public function test_inventory_command_uses_null_assignment_context_and_dispatches(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $device = $this->inventoryDevice($admin);

        $command = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::Sync,
            $admin,
            'Gerätebestand im virtuellen Lager kontrolliert synchronisieren.',
        );

        $this->assertNull($command->device_assignment_id);
        (new DispatchDeviceCommand($command->id, $device->id))
            ->handle(app(DeviceProviderRegistry::class));

        $command->refresh();
        $this->assertSame(DeviceCommandStatus::Succeeded, $command->status);
        $this->assertNotNull($command->provider_job_id);
    }

    public function test_assignment_change_is_blocked_while_a_command_is_externally_uncertain(): void
    {
        [$admin, , $device] = $this->assignedDevice();
        $assignmentId = (int) $device->activeAssignment()->value('id');
        $command = DeviceCommand::query()->create([
            'device_id' => $device->id,
            'device_assignment_id' => $assignmentId,
            'provider' => 'simulation',
            'type' => DeviceCommandType::Restart,
            'status' => DeviceCommandStatus::Dispatched,
            'is_destructive' => false,
            'justification' => 'Neustart wurde bereits an den Geräteprovider übergeben.',
            'correlation_id' => 'assignment-change-blocked-command',
            'requested_by' => $admin->id,
            'requested_at' => now(),
            'dispatched_at' => now(),
        ]);

        try {
            app(DeviceCommandService::class)
                ->cancelPendingCommandsForAssignmentChange($device, $admin);
            $this->fail('An externally uncertain command must block reassignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $this->assertSame(DeviceCommandStatus::Dispatched, $command->fresh()->status);
        $this->assertSame($assignmentId, (int) $device->activeAssignment()->value('id'));
    }

    public function test_apply_profile_is_revalidated_again_immediately_before_dispatch(): void
    {
        [$admin, $employee, $device] = $this->assignedDevice();
        $prepared = app(DeviceAccountPreparationService::class)->prepare(
            $device,
            $employee,
            $admin,
            [AccountProvider::Microsoft365],
        );
        $command = app(DeviceCommandService::class)->request(
            $device,
            'simulation',
            DeviceCommandType::ApplyProfile,
            $admin,
            'Microsoft-Kontenprofil kontrolliert auf dem Mitarbeitergerät anwenden.',
            ['profiles' => [['assignment_id' => $prepared[0]->id]]],
        );
        $prepared[0]->forceFill([
            'desired_state' => 'unassigned',
            'status' => 'revoked',
        ])->save();

        (new DispatchDeviceCommand($command->id, $device->id))
            ->handle(app(DeviceProviderRegistry::class));

        $command->refresh();
        $this->assertSame(DeviceCommandStatus::Cancelled, $command->status);
        $this->assertNull($command->provider_job_id);
        $this->assertNull($command->dispatched_at);
    }

    public function test_schema_owns_the_binding_in_base_and_upgrade_down_is_non_destructive(): void
    {
        $this->assertTrue(Schema::hasColumn('device_commands', 'device_assignment_id'));
        $baseSource = file_get_contents(database_path('migrations/2026_08_17_070000_create_device_management_tables.php'));
        $upgradeSource = file_get_contents(database_path('migrations/2026_08_23_000500_bind_device_commands_to_assignments.php'));

        $this->assertIsString($baseSource);
        $this->assertIsString($upgradeSource);
        $this->assertStringContainsString("'dev_cmd_assignment_fk'", $baseSource);
        $this->assertLessThanOrEqual(64, strlen('dev_cmd_assignment_fk'));
        $this->assertStringNotContainsString("dropColumn('device_assignment_id')", $upgradeSource);
    }

    public function test_upgrade_migration_binds_a_legacy_command_to_its_historical_assignment(): void
    {
        [, , $device] = $this->assignedDevice();
        $assignmentId = (int) $device->activeAssignment()->value('id');

        Schema::drop('device_commands');
        Schema::create('device_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('queued');
            $table->timestamp('requested_at');
        });
        $commandId = DB::table('device_commands')->insertGetId([
            'device_id' => $device->id,
            'status' => 'queued',
            'requested_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_23_000500_bind_device_commands_to_assignments.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('device_commands', 'device_assignment_id'));
        $this->assertSame(
            $assignmentId,
            (int) DB::table('device_commands')->where('id', $commandId)->value('device_assignment_id'),
        );

        $migration->down();
        $this->assertTrue(Schema::hasColumn('device_commands', 'device_assignment_id'));
    }

    /** @return array{User, User, Device} */
    private function assignedDevice(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $device = $this->inventoryDevice($admin);
        app(DeviceInventoryService::class)->assign($device, $employee, $admin);

        return [$admin, $employee, $device->fresh()];
    }

    private function inventoryDevice(User $admin): Device
    {
        return app(DeviceInventoryService::class)->create([
            'asset_tag' => 'RT-'.strtoupper(fake()->bothify('CMD-####')),
            'serial_number' => strtoupper(fake()->bothify('CMD-SN-########')),
            'display_name' => 'Command-Binding-Testgerät',
            'form_factor' => 'laptop',
            'platform' => 'windows',
            'ownership' => 'corporate',
            'declared_location' => 'IT-Labor',
            'primary_provider' => 'simulation',
        ], $admin);
    }

    private function replaceAssignment(Device $device, User $successor, User $admin): void
    {
        DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->active()
            ->update([
                'status' => DeviceAssignment::STATUS_RETURNED,
                'returned_by' => $admin->id,
                'returned_at' => now(),
            ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'user_id' => $successor->id,
            'status' => DeviceAssignment::STATUS_ACTIVE,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
        ]);
    }
}
