<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Models\Team;
use App\Models\User;
use App\Support\Dashboard\SystemDashboardData;
use App\Support\Operations\OperationalPreviewCatalog;
use Illuminate\Database\Schema\Blueprint;
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
}
