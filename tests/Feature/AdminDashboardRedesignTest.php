<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\UserDashboard;
use App\Models\Team;
use App\Models\User;
use App\Support\Dashboard\SystemDashboardData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AdminDashboardRedesignTest extends TestCase
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

        Schema::create('staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('role')->default('staff');
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('position')->nullable();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('invited_by');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
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

    public function test_only_administrators_team_can_view_technical_system_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $administrators = $this->createTeam($owner, 'Administratoren');
        $management = $this->createTeam($owner, 'Verwaltung');
        $legacyAdministration = $this->createTeam($owner, 'Administration');
        $administrationUser = User::factory()->create([
            'role' => 'staff',
            'current_team_id' => $administrators->id,
        ]);
        $managementUser = User::factory()->create([
            'role' => 'staff',
            'current_team_id' => $management->id,
        ]);

        $owner->forceFill(['current_team_id' => $administrators->id])->save();

        $this->assertTrue($administrationUser->canViewManagementDashboard());
        $this->assertTrue($administrationUser->canViewSystemDashboard());
        $this->assertTrue($managementUser->canViewManagementDashboard());
        $this->assertFalse($managementUser->canViewSystemDashboard());
        $this->assertTrue($owner->fresh()->canViewSystemDashboard());

        $owner->forceFill(['current_team_id' => $legacyAdministration->id])->save();

        $this->assertFalse($owner->fresh()->canViewSystemDashboard());

        $owner->forceFill(['current_team_id' => $management->id])->save();

        $this->assertFalse($owner->fresh()->canViewSystemDashboard());
    }

    public function test_system_payload_replaces_laravel_with_developer_and_charts_use_real_series(): void
    {
        User::factory()->create(['role' => 'admin', 'created_at' => now()->subDays(10)]);
        User::factory()->create(['role' => 'staff', 'status' => true, 'created_at' => now()->subDays(2)]);
        User::factory()->create(['role' => 'staff', 'status' => false, 'created_at' => now()]);

        $dashboardData = app(SystemDashboardData::class);
        $system = $dashboardData->system();
        $charts = $dashboardData->charts();

        $this->assertArrayNotHasKey('laravel', $system);
        $this->assertSame('Lucas M. Zacharias', $system['developer']);
        $this->assertCount(14, $charts['userGrowth']['labels']);
        $this->assertCount(14, $charts['userGrowth']['totals']);
        $this->assertCount(14, $charts['activity']['values']);
        $this->assertSame([2, 1], $charts['status']['values']);
        $this->assertSame(3, $charts['userGrowth']['totals'][13]);
    }

    public function test_admin_render_contains_animated_charts_and_management_render_hides_system_data(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $administrators = $this->createTeam($owner, 'Administratoren');
        $management = $this->createTeam($owner, 'Verwaltung');
        $owner->forceFill(['current_team_id' => $administrators->id])->save();

        Livewire::actingAs($owner->fresh())
            ->test(AdminDashboard::class)
            ->assertSee('data-admin-dashboard', escape: false)
            ->assertSee('x-ref="growthChart"', escape: false)
            ->assertSee('x-ref="statusChart"', escape: false)
            ->assertSee('x-ref="activityChart"', escape: false)
            ->assertSee('data-system-dashboard', escape: false)
            ->assertSee('Lucas M. Zacharias')
            ->assertDontSee('Laravel');

        $managementUser = User::factory()->create([
            'role' => 'staff',
            'current_team_id' => $management->id,
        ]);

        Livewire::actingAs($managementUser)
            ->test(UserDashboard::class)
            ->assertSee(__('app.management_dashboard_description'))
            ->assertDontSee('data-system-dashboard', escape: false)
            ->assertDontSee('Lucas M. Zacharias')
            ->assertDontSee('Laravel');
    }

    public function test_admin_dashboard_uses_modular_echarts_and_solid_panels(): void
    {
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));
        $applicationScript = file_get_contents(resource_path('js/app.js'));
        $chartModule = file_get_contents(resource_path('js/admin-dashboard-echarts.js'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^6.1.0', $package['dependencies']['echarts']);
        $this->assertStringContainsString("import('./admin-dashboard-echarts')", $applicationScript);
        $this->assertStringContainsString("from 'echarts/core'", $chartModule);
        $this->assertStringContainsString("from 'echarts/renderers'", $chartModule);
        $this->assertStringContainsString("renderer: 'svg'", $chartModule);
        $this->assertStringNotContainsString('window.ApexCharts', $applicationScript);
        $this->assertStringNotContainsString('bg-white/[0.06]', $dashboard);
        $this->assertStringNotContainsString('bg-white/[0.07]', $dashboard);
        $this->assertStringContainsString('.rt-admin-panel', $styles);
        $this->assertStringContainsString('border: 1px solid #d5dee9', $styles);
        $this->assertStringContainsString('data-dashboard-progress', $dashboard);
        $this->assertStringNotContainsString('data-dashboard-delay', $dashboard);
        $this->assertStringNotContainsString('transition-[width]', $dashboard);
        $this->assertStringContainsString('window.gsap.matchMedia()', $applicationScript);
        $this->assertStringContainsString('scaleX: progressTarget', $applicationScript);
        $this->assertStringContainsString('.dark .rt-admin-hero::before', $styles);
        $this->assertStringContainsString('dark:text-white', $dashboard);
    }

    private function createTeam(User $owner, string $name): Team
    {
        return Team::forceCreate([
            'user_id' => $owner->id,
            'name' => $name,
            'personal_team' => false,
            'rbac_permissions' => [],
        ]);
    }
}
