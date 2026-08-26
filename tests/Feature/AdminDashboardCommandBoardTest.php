<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Models\Team;
use App\Models\User;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
use App\Support\Dashboard\SystemDashboardData;
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
            $table->string('lifecycle_status', 32)->default('inventory')->index();
            $table->string('management_status', 32)->default('unmanaged')->index();
            $table->string('compliance_status', 32)->default('unknown')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('device_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id')->index();
            $table->string('status', 32)->default('active')->index();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_admin_dashboard_is_a_prioritised_four_area_command_board(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $administrators = Team::forceCreate([
            'user_id' => $admin->id,
            'name' => 'Administratoren',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $admin->forceFill(['current_team_id' => $administrators->id])->save();

        $component = Livewire::actingAs($admin->fresh())
            ->test(Dashboard::class)
            ->assertSee('data-admin-dashboard', escape: false)
            ->assertSee('data-dashboard-area="operations"', escape: false)
            ->assertSee('data-dashboard-area="workforce-activity"', escape: false)
            ->assertSee('data-dashboard-area="accounts"', escape: false)
            ->assertSee('data-dashboard-area="system"', escape: false)
            ->assertSee('data-dashboard-action="orders"', escape: false)
            ->assertSee('data-dashboard-action="shifts"', escape: false)
            ->assertSee('data-dashboard-action="wagon-list"', escape: false)
            ->assertSee('data-dashboard-action="calendar"', escape: false)
            ->assertSee('data-dashboard-action="customers"', escape: false)
            ->assertSee('data-dashboard-device-widget', escape: false)
            ->assertSee('data-dashboard-action="devices-manage"', escape: false)
            ->assertSee('data-dashboard-focus-variant="featured"', escape: false)
            ->assertSee('data-dashboard-focus-variant="compact"', escape: false)
            ->assertSee('x-ref="statusChart"', escape: false)
            ->assertSee('x-ref="activityChart"', escape: false)
            ->assertSee('x-ref="growthChart"', escape: false)
            ->assertSee('wire:ignore', escape: false)
            ->assertSee('data-dashboard-system-loading', escape: false)
            ->assertDontSee('Lucas M. Zacharias');

        $this->assertSame(
            3,
            substr_count(file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php')), 'wire:ignore'),
            'Every ECharts root must survive Livewire updates such as loading system diagnostics.',
        );

        $component
            ->call('loadSystemData')
            ->assertSet('systemLoaded', true)
            ->assertSee(__('app.system_runtime'))
            ->assertSee(__('app.system_data_jobs'))
            ->assertSee(__('app.system_infrastructure'))
            ->assertSee('Lucas M. Zacharias');
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
            ->test(Dashboard::class)
            ->assertViewHas('deviceSnapshot', fn (array $value): bool => $value === $snapshot)
            ->assertSee('data-dashboard-device-widget', escape: false)
            ->assertSee('data-dashboard-device-available="true"', escape: false)
            ->assertSee('data-dashboard-device-total="4"', escape: false)
            ->assertSee('data-dashboard-device-assigned="2"', escape: false)
            ->assertSee('data-dashboard-device-inventory="2"', escape: false)
            ->assertSee('data-dashboard-device-attention="2"', escape: false)
            ->assertSee('data-dashboard-action="devices-manage"', escape: false)
            ->assertSee('href="'.route('admin.devices').'"', escape: false)
            ->assertSee(__('app.device_management_dashboard_title'));
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

    public function test_device_widget_keeps_live_values_static_and_the_dark_action_legible(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.dashboard.device-management-widget
                :stats="$stats"
                href="/administrator/geraete"
            />
        BLADE, [
            'stats' => [
                'available' => true,
                'total' => 4,
                'assigned' => 2,
                'inventory' => 2,
                'attention' => 2,
            ],
        ]);

        $this->assertStringContainsString('data-dashboard-device-total="4"', $html);
        $this->assertStringNotContainsString('data-dashboard-count=', $html);
        $this->assertStringContainsString('dark:bg-slate-700', $html);
        $this->assertStringContainsString('dark:text-white', $html);
        $this->assertStringNotContainsString('dark:bg-white', $html);
    }

    public function test_device_widget_fails_soft_for_missing_and_partial_device_schema(): void
    {
        Schema::drop('device_assignments');
        Schema::drop('devices');

        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertViewHas(
                'deviceSnapshot',
                fn (array $snapshot): bool => $snapshot['available'] === false
                    && $snapshot['total'] === 0
                    && $snapshot['attention'] === 0,
            )
            ->assertSee('data-dashboard-device-widget', escape: false)
            ->assertSee('data-dashboard-device-available="false"', escape: false)
            ->assertSee('data-dashboard-device-unavailable', escape: false)
            ->assertDontSee('data-dashboard-action="devices-manage"', escape: false);

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
        });

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertViewHas('deviceSnapshot', fn (array $snapshot): bool => $snapshot['available'] === false)
            ->assertSee('data-dashboard-device-available="false"', escape: false)
            ->assertDontSee('data-dashboard-action="devices-manage"', escape: false);
    }

    public function test_featured_operational_card_follows_light_and_dark_surfaces(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui.dashboard.focus-card
                title="Aufträge"
                description="Offene Aufträge steuern."
                metric="5"
                metric-label="Offene Aufträge"
                href="/administrator/betrieb/orders"
                variant="featured"
            />
        BLADE);

        $this->assertStringContainsString('bg-rt-surface', $html);
        $this->assertStringContainsString('dark:bg-rt-dark-surface', $html);
        $this->assertStringContainsString('text-rt-text', $html);
        $this->assertStringContainsString('dark:text-white', $html);
        $this->assertStringContainsString('dark:text-rt-dark-accent', $html);
        $this->assertStringNotContainsString('bg-slate-950', $html);
        $this->assertStringNotContainsString('dark:text-rt-red-light', $html);
    }
}
