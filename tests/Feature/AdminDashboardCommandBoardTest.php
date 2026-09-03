<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Livewire\UserDashboard;
use App\Models\Message;
use App\Models\Team;
use App\Models\User;
use App\Services\DeviceManagement\DeviceFleetSnapshot;
use App\Services\DeviceManagement\DeviceReadinessService;
use App\Services\DeviceManagement\PersonalDeviceSnapshot;
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
            ->assertViewHas('deviceWidget', fn (array $widget): bool => $widget['scope'] === 'fleet')
            ->assertSee('data-management-dashboard', escape: false)
            ->assertSee('data-dashboard-device-scope="fleet"', escape: false)
            ->assertSee('data-dashboard-action="devices-manage"', escape: false)
            ->assertSee('href="'.route('devices.index').'"', escape: false)
            ->assertDontSee('href="'.route('admin.devices').'"', escape: false);

        Livewire::actingAs($personal)
            ->test(UserDashboard::class)
            ->assertViewHas('deviceWidget', fn (array $widget): bool => $widget['scope'] === 'personal')
            ->assertSee('data-management-dashboard', escape: false)
            ->assertSee('data-dashboard-device-scope="personal"', escape: false)
            ->assertSee('data-dashboard-action="devices-mine"', escape: false)
            ->assertDontSee('data-dashboard-action="devices-manage"', escape: false);
    }

    public function test_employee_and_guest_dashboards_keep_the_segmented_layout_with_personal_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $employeeTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            // Selbst ein versehentlich delegiertes Flottenrecht darf fuer
            // dieses fachliche Dashboard keine globalen Zahlen freigeben.
            'rbac_permissions' => ['devices.view' => true],
        ]);
        $guestTeam = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Gäste',
            'personal_team' => false,
            'rbac_permissions' => ['devices.view' => true],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $employeeTeam->id]);
        $guest = User::factory()->create(['role' => 'staff', 'current_team_id' => $guestTeam->id]);
        $employeeMessage = Message::create([
            'subject' => 'Persönliche Einsatzunterlagen',
            'message' => 'Die Unterlagen wurden aktualisiert.',
            'from_user' => $owner->id,
            'to_user' => $employee->id,
            'status' => 1,
        ]);
        $guestMessage = Message::create([
            'subject' => 'Persönliche Willkommensinformation',
            'message' => 'Die Information wurde aktualisiert.',
            'from_user' => $owner->id,
            'to_user' => $guest->id,
            'status' => 1,
        ]);

        Livewire::actingAs($employee)
            ->test(UserDashboard::class)
            ->assertViewHas('messageActivity', fn (array $activity): bool => $activity['total'] === 1)
            ->assertSee('data-dashboard-layout="segmented"', escape: false)
            ->assertSee('data-dashboard-layout-contract="role-hero-workday-device-news-profile-files-trend"', escape: false)
            ->assertSee('data-dashboard-personal-header', escape: false)
            ->assertSee('data-dashboard-role-hero-variant="personal"', escape: false)
            ->assertSee('data-dashboard-stat-variant="minimal"', escape: false)
            ->assertSee('data-dashboard-work-focus', escape: false)
            ->assertSee('data-dashboard-primary-action="wagon-list"', escape: false)
            ->assertSee('data-dashboard-focus-variant="minimal"', escape: false)
            ->assertSee('data-dashboard-data-source="live"', escape: false)
            ->assertSee('data-dashboard-data-source="preview"', escape: false)
            ->assertSee('data-dashboard-preview-label', escape: false)
            ->assertSee(__('app.planning_not_connected'))
            ->assertSee('data-dashboard-device-variant="panel"', escape: false)
            ->assertSee('data-dashboard-device-scope="personal"', escape: false)
            ->assertSee('href="'.route('devices.mine').'"', escape: false)
            ->assertDontSee('href="'.route('devices.index').'"', escape: false)
            ->assertDontSee('data-dashboard-action="devices-manage"', escape: false)
            ->assertSee('href="'.route('messages', ['open' => $employeeMessage->id]).'"', escape: false)
            ->assertSee(__('app.unread'))
            ->assertSee('data-dashboard-real-series', escape: false)
            ->assertSee('data-dashboard-chart-variant="minimal"', escape: false)
            ->assertSee('data-series-source="received-messages"', escape: false);

        Livewire::actingAs($guest)
            ->test(UserDashboard::class)
            ->assertViewHas('messageActivity', fn (array $activity): bool => $activity['total'] === 1)
            ->assertSee('data-dashboard-layout="segmented"', escape: false)
            ->assertSee('data-dashboard-layout-contract="role-hero-workday-device-news-profile-files-trend"', escape: false)
            ->assertSee('data-dashboard-role-hero-variant="personal"', escape: false)
            ->assertSee('data-dashboard-stat-variant="minimal"', escape: false)
            ->assertSee('data-dashboard-personal-summary', escape: false)
            ->assertSee('data-dashboard-message-list', escape: false)
            ->assertSee('data-dashboard-device-variant="panel"', escape: false)
            ->assertSee('data-dashboard-device-scope="personal"', escape: false)
            ->assertSee('href="'.route('devices.mine').'"', escape: false)
            ->assertDontSee('href="'.route('devices.index').'"', escape: false)
            ->assertDontSee('data-dashboard-action="devices-manage"', escape: false)
            ->assertSee('href="'.route('messages', ['open' => $guestMessage->id]).'"', escape: false)
            ->assertSee('data-dashboard-real-series', escape: false)
            ->assertSee('data-dashboard-chart-variant="minimal"', escape: false)
            ->assertSee('data-series-source="received-messages"', escape: false)
            ->assertDontSee('data-dashboard-work-focus', escape: false)
            ->assertDontSee('data-dashboard-primary-action="wagon-list"', escape: false)
            ->assertDontSee('data-dashboard-data-source="preview"', escape: false)
            ->assertDontSee(__('app.planning_not_connected'));

        $template = file_get_contents(resource_path('views/livewire/user-dashboard.blade.php'));
        $this->assertStringContainsString('<x-ui.dashboard.role-hero', $template);
        $this->assertSame(3, substr_count($template, '<x-ui.dashboard.operational-stat'));
        $this->assertSame(2, substr_count($template, '<x-ui.dashboard.focus-card'));
        $this->assertStringContainsString('<x-ui.dashboard.trend-chart', $template);
        $this->assertStringContainsString('grid gap-3 sm:gap-4 md:grid-cols-2', $template);
        $this->assertStringContainsString('lg:grid-cols-12', $template);
        $this->assertStringContainsString("'lg:col-span-7'", $template);
        $this->assertStringContainsString('lg:col-span-5', $template);
        $this->assertStringContainsString('grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 xl:grid-cols-6', $template);
        $this->assertGreaterThanOrEqual(6, substr_count($template, 'data-dashboard-segment-style="minimal"'));
        $this->assertMatchesRegularExpression(
            '/data-dashboard-personal-header.*data-dashboard-work-focus.*variant="panel".*data-dashboard-message-list.*aria-labelledby="dashboard-files".*data-dashboard-real-series/s',
            $template,
        );
    }

    public function test_personal_message_activity_is_private_and_uses_one_bounded_aggregate_query(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
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
            ->test(UserDashboard::class)
            ->assertViewHas(
                'messageActivity',
                fn (array $activity): bool => $activity['total'] === 1
                    && count($activity['labels']) === 14
                    && count($activity['values']) === 14,
            );

        $activityQueries = collect(DB::getQueryLog())
            ->filter(function (array $query): bool {
                $sql = strtolower($query['query']);

                return preg_match('/\bfrom\s+["`]?messages["`]?/', $sql) === 1
                    && str_contains($sql, 'created_at')
                    && str_contains($sql, ' between ');
            })
            ->values();
        DB::disableQueryLog();

        $this->assertCount(1, $activityQueries);
        $this->assertStringContainsString('group by', strtolower($activityQueries->first()['query']));
        $this->assertStringContainsString(' between ', strtolower($activityQueries->first()['query']));
        $this->assertContains($employee->getKey(), $activityQueries->first()['bindings']);
    }

    public function test_personal_device_widget_fails_soft_and_is_flat_in_both_languages(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $team = Team::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Mitarbeiter',
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
        $employee = User::factory()->create(['role' => 'staff', 'current_team_id' => $team->id]);

        Schema::drop('device_readiness_checks');

        Livewire::actingAs($employee)
            ->test(UserDashboard::class)
            ->assertViewHas(
                'deviceWidget',
                fn (array $widget): bool => $widget['scope'] === 'personal'
                    && $widget['stats']['available'] === false
                    && $widget['href'] === null,
            )
            ->assertSee('data-dashboard-device-available="false"', escape: false)
            ->assertSee('data-dashboard-device-unavailable', escape: false)
            ->assertDontSee('data-dashboard-action="devices-mine"', escape: false);

        $stats = ['available' => true, 'total' => 2, 'ready' => 1, 'pending' => 1, 'blocked' => 0];
        app()->setLocale('de');
        $german = Blade::render('<x-ui.dashboard.personal-device-widget :stats="$stats" href="/meine-geraete" />', compact('stats'));
        app()->setLocale('en');
        $english = Blade::render('<x-ui.dashboard.personal-device-widget :stats="$stats" href="/my-devices" />', compact('stats'));

        $this->assertStringContainsString('Meine Geräte', $german);
        $this->assertStringContainsString('My devices', $english);
        $this->assertStringContainsString('border-y', $english);
        $this->assertStringContainsString('dark:border-rt-dark-border', $english);
        $this->assertStringNotContainsString('rt-admin-panel', $english);
        $this->assertStringNotContainsString('data-feather', $english);
        $this->assertStringNotContainsString('gradient', $english);
    }

    public function test_personal_dashboard_variants_are_minimal_without_changing_component_defaults(): void
    {
        $personalHero = Blade::render('<x-ui.dashboard.role-hero title="Persoenlich" variant="personal" />');
        $defaultHero = Blade::render('<x-ui.dashboard.role-hero title="Standard" />');

        $this->assertStringContainsString('data-dashboard-role-hero-variant="personal"', $personalHero);
        $this->assertStringContainsString('bg-rt-surface', $personalHero);
        $this->assertStringContainsString('dark:bg-rt-dark-surface', $personalHero);
        $this->assertStringNotContainsString('bg-rt-text', $personalHero);
        $this->assertStringNotContainsString('data-feather', $personalHero);
        $this->assertStringContainsString('data-dashboard-role-hero-variant="default"', $defaultHero);
        $this->assertStringContainsString('bg-rt-text', $defaultHero);
        $this->assertStringContainsString('shadow-rt-md', $defaultHero);
        $this->assertStringContainsString('data-feather="briefcase"', $defaultHero);

        $minimalStat = Blade::render('<x-ui.dashboard.operational-stat label="Dateien" value="3" icon="folder" variant="minimal" />');
        $defaultStat = Blade::render('<x-ui.dashboard.operational-stat label="Dateien" value="3" icon="folder" />');

        $this->assertStringContainsString('data-dashboard-stat-variant="minimal"', $minimalStat);
        $this->assertStringContainsString('bg-transparent', $minimalStat);
        $this->assertStringContainsString('dark:ring-rt-dark-border/70', $minimalStat);
        $this->assertStringNotContainsString('data-feather', $minimalStat);
        $this->assertStringNotContainsString('shadow-rt-sm', $minimalStat);
        $this->assertStringContainsString('data-dashboard-stat-variant="default"', $defaultStat);
        $this->assertStringContainsString('bg-rt-surface', $defaultStat);
        $this->assertStringContainsString('shadow-rt-sm', $defaultStat);
        $this->assertStringContainsString('data-feather="folder"', $defaultStat);

        $minimalFocus = Blade::render('<x-ui.dashboard.focus-card title="Wagenliste" href="/wagenliste" variant="minimal" />');
        $minimalPreview = Blade::render('<x-ui.dashboard.focus-card title="Schicht" variant="minimal" preview />');
        $defaultFocus = Blade::render('<x-ui.dashboard.focus-card title="Standard" href="/standard" />');

        $this->assertStringContainsString('data-dashboard-focus-variant="minimal"', $minimalFocus);
        $this->assertStringContainsString('data-dashboard-data-source="live"', $minimalFocus);
        $this->assertStringNotContainsString('blur-3xl', $minimalFocus);
        $this->assertStringNotContainsString('hover:-translate-y-0.5', $minimalFocus);
        $this->assertStringContainsString('data-dashboard-data-source="preview"', $minimalPreview);
        $this->assertStringContainsString('data-dashboard-focus-preview="true"', $minimalPreview);
        $this->assertStringContainsString('data-dashboard-preview-label', $minimalPreview);
        $this->assertStringContainsString('data-dashboard-focus-variant="default"', $defaultFocus);
        $this->assertStringContainsString('blur-3xl', $defaultFocus);
        $this->assertStringContainsString('hover:-translate-y-0.5', $defaultFocus);

        $labels = ['01.09.', '02.09.'];
        $values = [1, 2];
        $minimalChart = Blade::render(
            '<x-ui.dashboard.trend-chart title="Verlauf" :labels="$labels" :values="$values" variant="minimal" />',
            compact('labels', 'values'),
        );
        $defaultChart = Blade::render(
            '<x-ui.dashboard.trend-chart title="Verlauf" :labels="$labels" :values="$values" />',
            compact('labels', 'values'),
        );

        $this->assertStringContainsString('data-dashboard-chart-variant="minimal"', $minimalChart);
        $this->assertStringContainsString('h-28 sm:h-32', $minimalChart);
        $this->assertStringContainsString('dark:border-rt-dark-border/80', $minimalChart);
        $this->assertStringNotContainsString('linearGradient', $minimalChart);
        $this->assertStringNotContainsString('shadow-rt-sm', $minimalChart);
        $this->assertStringContainsString('data-dashboard-chart-variant="default"', $defaultChart);
        $this->assertStringContainsString('h-36', $defaultChart);
        $this->assertStringContainsString('linearGradient', $defaultChart);
        $this->assertStringContainsString('shadow-rt-sm', $defaultChart);

        $minimalHeading = Blade::render('<x-ui.dashboard.section-heading title="Hinweise" icon="inbox" variant="minimal" />');
        $defaultHeading = Blade::render('<x-ui.dashboard.section-heading title="Hinweise" icon="inbox" />');

        $this->assertStringContainsString('data-dashboard-heading-variant="minimal"', $minimalHeading);
        $this->assertStringNotContainsString('data-feather', $minimalHeading);
        $this->assertStringContainsString('data-dashboard-heading-variant="default"', $defaultHeading);
        $this->assertStringContainsString('data-feather="inbox"', $defaultHeading);

        $stats = ['available' => true, 'total' => 1, 'ready' => 1, 'pending' => 0, 'blocked' => 0];
        $panelDevice = Blade::render(
            '<x-ui.dashboard.personal-device-widget :stats="$stats" href="/meine-geraete" variant="panel" />',
            compact('stats'),
        );
        $lineDevice = Blade::render(
            '<x-ui.dashboard.personal-device-widget :stats="$stats" href="/meine-geraete" />',
            compact('stats'),
        );

        $this->assertStringContainsString('data-dashboard-device-variant="panel"', $panelDevice);
        $this->assertStringContainsString('rounded-xl border', $panelDevice);
        $this->assertStringContainsString('bg-rt-surface', $panelDevice);
        $this->assertStringNotContainsString('border-y', $panelDevice);
        $this->assertStringContainsString('data-dashboard-device-variant="line"', $lineDevice);
        $this->assertStringContainsString('border-y', $lineDevice);
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
