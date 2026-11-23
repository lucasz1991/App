<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Livewire\Dashboard\WidgetGrid;
use App\Livewire\UserDashboard;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
use App\Services\DeviceManagement\DeviceReadinessService;
use App\Services\DeviceManagement\PersonalDeviceSnapshot;
use App\Support\Dashboard\SystemDashboardData;
use App\Support\Dashboard\WidgetRegistry;
use App\Support\Operations\OperationalPreviewCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AdminDashboardCommandBoardTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();

        Schema::create('activity_log', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->string('ownership')->default('corporate');
            $table->string('lifecycle_status', 32)->default('inventory')->index();
            $table->string('management_status', 32)->default('unmanaged')->index();
            $table->string('compliance_status', 32)->default('unknown')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_readiness_checks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id')->index();
            $table->string('check_key', 80);
            $table->string('status', 32)->default('unknown')->index();
            $table->timestamps();
            $table->unique(['device_id', 'check_key']);
        });
    }

    public function test_admin_dashboard_renders_the_widget_grid_and_loads_system_status_lazily(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $administrators = Team::forceCreate([
            'user_id' => $admin->id,
            'name' => 'Administratoren',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $admin->forceFill(['current_team_id' => $administrators->id])->save();

        // Ohne Operations-Schema gibt es kein eigenes Admin-Ausweich-Dashboard
        // mehr: auch dann rendert das Widget-Raster, nur ohne Betriebs-Widgets.
        Livewire::actingAs($admin->fresh())
            ->test(Dashboard::class)
            ->assertSee('data-native-dashboard', escape: false)
            ->assertSee('data-dashboard-widget-grid', escape: false)
            ->assertDontSee('data-admin-dashboard', escape: false)
            ->assertDontSee('Lucas M. Zacharias');

        $keys = array_keys(WidgetRegistry::availableFor($admin->fresh()));
        $this->assertContains('fleet_devices', $keys);
        $this->assertContains('employees', $keys);
        $this->assertContains('system_status', $keys);
        $this->assertSame([], array_values(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'operations_'))));

        Livewire::actingAs($admin->fresh())
            ->test(WidgetGrid::class)
            ->assertSet('systemStatusLoaded', false)
            ->call('loadSystemStatus')
            ->assertSet('systemStatusLoaded', true);
    }

    public function test_dashboard_payload_uses_structured_attention_values_without_duplicate_status_queries(): void
    {
        DB::enableQueryLog();
        $definitions = app(OperationalPreviewCatalog::class)->definitions();
        $definitionQueries = DB::getQueryLog();

        $this->assertSame([], $definitionQueries);
        $this->assertSame(OperationalPreviewCatalog::slugs(), array_keys($definitions));

        DB::flushQueryLog();
        $modules = collect(app(OperationalPreviewCatalog::class)->dashboard())->keyBy('slug');

        $this->assertCount(4, DB::getQueryLog());
        $this->assertIsInt($modules['orders']['alert_count']);
        $this->assertIsInt($modules['shift-management']['alert_count']);
        $this->assertIsInt($modules['calendar']['supporting_value']);
        $this->assertIsInt($modules['customers']['supporting_value']);
        $this->assertArrayNotHasKey('status', app(SystemDashboardData::class)->charts());
    }

    public function test_device_widget_uses_the_shared_live_inventory_snapshot(): void
    {
        $timestamp = now();

        DB::table('devices')->insert([
            [
                'id' => 1,
                'lifecycle_status' => 'inventory',
                'management_status' => 'managed',
                'compliance_status' => 'compliant',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'lifecycle_status' => 'assigned',
                'management_status' => 'managed',
                'compliance_status' => 'compliant',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'lifecycle_status' => 'in_service',
                'management_status' => 'error',
                'compliance_status' => 'compliant',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'lifecycle_status' => 'inventory',
                'management_status' => 'unmanaged',
                'compliance_status' => 'warning',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ],
            [
                'id' => 5,
                'lifecycle_status' => 'retired',
                'management_status' => 'error',
                'compliance_status' => 'non_compliant',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => $timestamp,
            ],
        ]);
        DB::table('device_assignments')->insert([
            ['device_id' => 2, 'status' => 'active', 'returned_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['device_id' => 3, 'status' => 'active', 'returned_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['device_id' => 4, 'status' => 'active', 'returned_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(DeviceFleetSnapshot::class)->get();
        $deviceQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'devices'))
            ->values();
        DB::disableQueryLog();

        $this->assertSame([
            'available' => true,
            'total' => 4,
            'assigned' => 2,
            'inventory' => 2,
            'attention' => 2,
        ], $snapshot);
        $this->assertCount(1, $deviceQueries);
        $this->assertStringContainsString('device_assignments', $deviceQueries->first()['query']);
        $this->assertStringContainsString('returned_at', $deviceQueries->first()['query']);

        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(WidgetGrid::class)
            ->assertViewHas('widgetData', fn (array $data): bool => $data['fleet_devices']['stats'] === $snapshot
                && $data['fleet_devices']['tone'] === 'warn'
                && $data['fleet_devices']['href'] === route('admin.devices'))
            ->assertSeeHtml('data-widget-key="fleet_devices"')
            ->assertSee('href="'.route('admin.devices').'"', escape: false);
    }

    public function test_device_snapshot_is_zero_safe_for_an_empty_inventory(): void
    {
        $this->assertSame([
            'available' => true,
            'total' => 0,
            'assigned' => 0,
            'inventory' => 0,
            'attention' => 0,
        ], app(DeviceFleetSnapshot::class)->get());
    }

    public function test_device_widget_fails_soft_for_missing_and_partial_device_schema(): void
    {
        Schema::drop('device_assignments');
        Schema::drop('devices');

        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(WidgetGrid::class)
            ->assertViewHas(
                'widgetData',
                fn (array $data): bool => $data['fleet_devices']['stats']['available'] === false
                    && $data['fleet_devices']['stats']['total'] === 0
                    && $data['fleet_devices']['stats']['attention'] === 0
                    && $data['fleet_devices']['href'] === null,
            )
            ->assertSeeHtml('data-widget-key="fleet_devices"')
            ->assertDontSee('href="'.route('admin.devices').'"', escape: false);

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
        });

        Livewire::actingAs($admin)
            ->test(WidgetGrid::class)
            ->assertViewHas('widgetData', fn (array $data): bool => $data['fleet_devices']['stats']['available'] === false)
            ->assertDontSee('href="'.route('admin.devices').'"', escape: false);
    }

    public function test_personal_device_snapshot_is_private_disjoint_and_single_query(): void
    {
        $employee = User::factory()->create(['role' => 'staff']);
        $otherEmployee = User::factory()->create(['role' => 'staff']);
        $timestamp = now();

        foreach (range(1, 5) as $deviceId) {
            DB::table('devices')->insert([
                'id' => $deviceId,
                'lifecycle_status' => 'assigned',
                'management_status' => 'managed',
                'compliance_status' => 'compliant',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ]);
        }

        DB::table('device_assignments')->insert([
            ['device_id' => 1, 'user_id' => $employee->id, 'status' => 'active', 'returned_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['device_id' => 2, 'user_id' => $employee->id, 'status' => 'active', 'returned_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['device_id' => 3, 'user_id' => $employee->id, 'status' => 'active', 'returned_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['device_id' => 4, 'user_id' => $otherEmployee->id, 'status' => 'active', 'returned_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['device_id' => 5, 'user_id' => $employee->id, 'status' => 'returned', 'returned_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);

        $readyRows = collect(array_keys(DeviceReadinessService::REQUIRED_CHECKS))
            ->map(fn (string $key, int $index): array => [
                'device_id' => 1,
                'check_key' => $key,
                'status' => $index === 0 ? 'not_applicable' : 'passed',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();
        DB::table('device_readiness_checks')->insert($readyRows);
        DB::table('device_readiness_checks')->insert([
            'device_id' => 2,
            'check_key' => 'asset',
            'status' => 'blocked',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $snapshot = app(PersonalDeviceSnapshot::class)->get($employee);
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'device_assignments'))
            ->values();
        DB::disableQueryLog();

        $this->assertSame([
            'available' => true,
            'total' => 3,
            'ready' => 1,
            'pending' => 1,
            'blocked' => 1,
        ], $snapshot);
        $this->assertSame($snapshot['total'], $snapshot['ready'] + $snapshot['pending'] + $snapshot['blocked']);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('user_id', $queries->first()['query']);
    }

    public function test_management_dashboard_uses_fleet_only_with_permission_and_the_neutral_route(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $authorizedTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => ['devices.view' => true],
        ]);
        $personalTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Verwaltung',
            'personal_team' => false,
            'rbac_permissions' => ['devices.view' => false],
        ]);
        $authorized = User::factory()->create(['role' => 'staff', 'current_team_id' => $authorizedTeam->id]);
        $personal = User::factory()->create(['role' => 'staff', 'current_team_id' => $personalTeam->id]);

        Livewire::actingAs($authorized)
            ->test(UserDashboard::class)
            ->assertSee('data-dashboard-widget-grid', escape: false);

        Livewire::actingAs($authorized)
            ->test(WidgetGrid::class)
            ->assertSeeHtml('data-widget-key="fleet_devices"')
            ->assertViewHas('widgetData', fn (array $data): bool => $data['fleet_devices']['href'] === route('devices.index'))
            ->assertSee('href="'.route('devices.index').'"', escape: false)
            ->assertDontSee('href="'.route('admin.devices').'"', escape: false);

        Livewire::actingAs($personal)
            ->test(WidgetGrid::class)
            ->assertDontSeeHtml('data-widget-key="fleet_devices"')
            ->assertSeeHtml('data-widget-key="my_devices"')
            ->assertViewHas('widgetData', fn (array $data): bool => $data['my_devices']['href'] === route('devices.mine'));
    }

    public function test_employee_dashboard_redirects_to_workday_and_guest_sees_only_personal_widgets(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $employeeTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => ['devices.view' => true],
        ]);
        $guestTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Gäste',
            'personal_team' => false,
            // Selbst ein versehentlich delegiertes Flottenrecht darf fuer
            // Gaeste keine globalen Zahlen freigeben.
            'rbac_permissions' => ['devices.view' => true],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $employeeTeam->id]);
        $guest = User::factory()->create(['role' => 'staff', 'current_team_id' => $guestTeam->id]);
        $guestMessage = Message::create([
            'subject' => 'Persönliche Willkommensinformation',
            'message' => 'Die Information wurde aktualisiert.',
            'from_user' => $owner->id,
            'to_user' => $guest->id,
            'status' => 1,
        ]);

        Livewire::actingAs($employee)
            ->test(UserDashboard::class)
            ->assertRedirect(route('operations.mine'));

        Livewire::actingAs($guest)
            ->test(UserDashboard::class)
            ->assertSee('data-dashboard-widget-grid', escape: false)
            ->assertSee('data-rt-welcome-audience="guest"', escape: false);

        Livewire::actingAs($guest)
            ->test(WidgetGrid::class)
            ->assertSeeHtml('data-widget-key="my_devices"')
            ->assertDontSeeHtml('data-widget-key="fleet_devices"')
            ->assertDontSeeHtml('data-widget-key="wagon_list"')
            ->assertSee('href="'.route('messages', ['open' => $guestMessage->id]).'"', escape: false)
            ->assertDontSee('href="'.route('devices.index').'"', escape: false);
    }

    public function test_personal_message_activity_is_private_and_uses_one_bounded_aggregate_query(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Gäste',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);
        $otherEmployee = User::factory()->create(['role' => 'staff']);

        Message::create([
            'subject' => 'Eigene aktuelle Nachricht',
            'message' => 'Zaehlt im persoenlichen Verlauf.',
            'from_user' => $owner->id,
            'to_user' => $employee->id,
            'status' => 1,
        ]);
        $oldMessage = Message::create([
            'subject' => 'Eigene alte Nachricht',
            'message' => 'Liegt ausserhalb des Verlaufs.',
            'from_user' => $owner->id,
            'to_user' => $employee->id,
            'status' => 1,
        ]);
        $oldMessage->forceFill([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ])->save();
        Message::create([
            'subject' => 'Fremde aktuelle Nachricht',
            'message' => 'Darf nicht im persoenlichen Verlauf erscheinen.',
            'from_user' => $owner->id,
            'to_user' => $otherEmployee->id,
            'status' => 1,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($employee)
            ->test(WidgetGrid::class)
            ->assertViewHas(
                'widgetData',
                fn (array $data): bool => array_sum($data['messages']['sparkline']) === 1
                    && count($data['messages']['sparkline']) === 7
                    && count($data['messages']['sparklineLabels']) === 7,
            );

        $activityQueries = collect(DB::getQueryLog())
            ->filter(function (array $query): bool {
                $sql = strtolower($query['query']);

                return preg_match('/\bfrom\s+["`]?messages["`]?/', $sql) === 1
                    && str_contains($sql, 'created_at')
                    && str_contains($sql, 'group by');
            })
            ->values();
        DB::disableQueryLog();

        $this->assertCount(1, $activityQueries);
        $this->assertContains($employee->getKey(), $activityQueries->first()['bindings']);
    }

    public function test_personal_device_widget_fails_soft(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Gäste',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);

        Schema::drop('device_readiness_checks');

        Livewire::actingAs($employee)
            ->test(WidgetGrid::class)
            ->assertViewHas(
                'widgetData',
                fn (array $data): bool => $data['my_devices']['stats']['available'] === false
                    && $data['my_devices']['href'] === null,
            )
            ->assertSeeHtml('data-widget-key="my_devices"')
            ->assertDontSee('href="'.route('devices.mine').'"', escape: false);
    }

    public function test_stat_variants_are_minimal_without_changing_component_defaults(): void
    {
        $minimalStat = Blade::render('<x-ui.dashboard.stat label="Dateien" value="3" icon="folder" variant="minimal" />');
        $defaultStat = Blade::render('<x-ui.dashboard.stat label="Dateien" value="3" icon="folder" variant="row" />');

        $this->assertStringContainsString('data-dashboard-stat-variant="minimal"', $minimalStat);
        $this->assertStringContainsString('bg-transparent', $minimalStat);
        $this->assertStringContainsString('dark:ring-rt-dark-border/70', $minimalStat);
        $this->assertStringNotContainsString('data-feather', $minimalStat);
        $this->assertStringNotContainsString('shadow-rt-sm', $minimalStat);
        $this->assertStringContainsString('data-dashboard-stat-variant="row"', $defaultStat);
        $this->assertStringContainsString('bg-rt-surface', $defaultStat);
        $this->assertStringContainsString('shadow-rt-sm', $defaultStat);
        $this->assertStringContainsString('data-feather="folder"', $defaultStat);
    }
}
