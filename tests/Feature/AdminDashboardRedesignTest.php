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

    public function test_recent_users_card_excludes_admin_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'created_at' => now()]);
        $employee = User::factory()->create(['role' => 'staff', 'created_at' => now()->subMinute()]);

        $recentUsers = app(SystemDashboardData::class)->recentUsers();

        $this->assertNotContains($admin->id, $recentUsers->pluck('id'));
        $this->assertContains($employee->id, $recentUsers->pluck('id'));
        $this->assertTrue($recentUsers->every(fn (User $user): bool => $user->role !== 'admin'));
    }

    public function test_admin_render_contains_animated_charts_and_management_render_shows_context_without_system_data(): void
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
        $this->assertStringContainsString('background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%)', $styles);
        $this->assertStringContainsString('border: 1px solid rgba(203, 213, 225, 0.78)', $styles);
        $this->assertStringContainsString('data-dashboard-progress', $dashboard);
        $this->assertStringNotContainsString('data-dashboard-delay', $dashboard);
        $this->assertStringNotContainsString('transition-[width]', $dashboard);
        $this->assertStringContainsString('window.gsap.matchMedia()', $applicationScript);
        $this->assertStringContainsString('scaleX: progressTarget', $applicationScript);
        $this->assertStringContainsString('.dark .rt-admin-hero::before', $styles);
        $this->assertStringContainsString('dark:text-white', $dashboard);
    }

    public function test_every_dashboard_section_has_a_named_timeline_segment_and_dark_safe_secondary_action(): void
    {
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));
        $revealScript = file_get_contents(resource_path('js/gsap.js'));
        $styles = file_get_contents(resource_path('css/app.css'));

        preg_match_all('/data-dashboard-segment="([^"]+)"/', $dashboard, $segments);

        $this->assertSame(
            ['hero', 'operations', 'kpis', 'charts', 'accounts', 'system'],
            $segments[1],
        );
        $this->assertSame(substr_count($dashboard, '<section'), count($segments[1]));
        $this->assertGreaterThanOrEqual(6, substr_count($dashboard, 'data-dashboard-items'));
        $this->assertSame(1, substr_count($dashboard, 'data-dashboard-hero-secondary'));
        $this->assertStringContainsString('rt-ui-button-secondary rt-admin-hero-secondary', $dashboard);
        $this->assertStringNotContainsString('rt-admin-hero-secondary inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white', $dashboard);
        $this->assertStringNotContainsString('rt-admin-demo-badge', $dashboard);
        $this->assertSame(1, substr_count($dashboard, 'data-preview-tone='));

        $this->assertGreaterThanOrEqual(2, substr_count($revealScript, 'gsap.timeline('));
        $this->assertStringContainsString('timeline.addLabel(label, position)', $revealScript);
        $this->assertStringContainsString('appendDashboardSegment(introTimeline, prepared, index * 0.08)', $revealScript);
        $this->assertStringContainsString('setupDashboardSegments(dashboardSegments)', $revealScript);
        $this->assertStringContainsString('scheduleDashboardVisibilitySafety(root)', $revealScript);
        $this->assertStringContainsString('[data-dashboard-segment][data-anim-pending]', $revealScript);
        $this->assertStringContainsString('window.clearTimeout(dashboardSafetyTimer)', $revealScript);
        $this->assertStringContainsString('html.dark [data-admin-dashboard] .rt-admin-hero-secondary', $styles);
        $this->assertStringContainsString('body[data-mode="dark"] [data-admin-dashboard] .rt-admin-hero-secondary', $styles);
    }

    public function test_admin_dashboard_is_compact_theme_aware_and_visually_prioritises_kpis_before_operations(): void
    {
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));
        $chartModule = file_get_contents(resource_path('js/admin-dashboard-echarts.js'));
        $styles = file_get_contents(resource_path('css/app.css'));
        $growthChartPosition = strpos($dashboard, 'x-ref="growthChart"');
        $operationalPreviewPosition = strpos($dashboard, 'operational-preview-heading');
        $kpiPosition = strpos($dashboard, 'data-dashboard-kpis');

        $this->assertIsInt($growthChartPosition);
        $this->assertIsInt($operationalPreviewPosition);
        $this->assertIsInt($kpiPosition);
        // Die DOM-Reihenfolge bleibt fuer die bestehende Segment-Timeline stabil.
        // CSS Grid priorisiert die Kennzahlen visuell direkt nach dem Hero.
        $this->assertLessThan($kpiPosition, $operationalPreviewPosition);
        $this->assertLessThan($growthChartPosition, $operationalPreviewPosition);
        $this->assertStringContainsString('order-2 grid grid-cols-2', $dashboard);
        $this->assertStringContainsString('order-3 relative', $dashboard);
        // Zwei-Panel-Wachstumschart (Linie + Registrierungs-Balken) braucht
        // etwas mehr Hoehe als das fruehere Einzel-Panel.
        $this->assertStringContainsString('h-[236px] sm:h-[252px]', $dashboard);
        $this->assertStringContainsString('grid gap-3 md:grid-cols-12', $dashboard);
        $this->assertStringContainsString('md:col-span-8 xl:col-span-6', $dashboard);
        $this->assertStringContainsString('md:col-span-4 xl:col-span-3', $dashboard);
        $this->assertStringContainsString('rt-admin-operations-stage', $dashboard);
        $this->assertStringContainsString('dark:bg-slate-900', $dashboard);
        $this->assertStringContainsString('dark:bg-slate-900/95', $dashboard);
        $this->assertStringContainsString('rt-admin-live-card', $dashboard);
        $this->assertSame(4, substr_count($dashboard, 'rt-admin-quick-link'));
        // Der zeigergefuehrte rote Glow sitzt nicht mehr auf den vier KPI-
        // Karten, sondern als ruhige Ambient-Bewegung im globalen Content.
        $this->assertSame(6, substr_count($dashboard, 'data-rt-glow'));
        $kpiSection = str($dashboard)->between(
            'data-dashboard-kpis data-dashboard-items>',
            '{{-- Feine SVG-Diagramme',
        )->toString();
        $this->assertStringNotContainsString('data-rt-glow', $kpiSection);
        $this->assertStringContainsString('.dark .rt-admin-live-card', $styles);
        $this->assertStringContainsString('.dark .rt-admin-operations-card', $styles);
        $this->assertStringContainsString('.dark .rt-admin-operations-stage', $styles);
        $this->assertStringContainsString('.dark .rt-admin-quick-link', $styles);
        $this->assertStringContainsString('.dark [data-admin-dashboard] .text-rt-red', $styles);
        $this->assertStringContainsString('html.dark [data-admin-dashboard] .rt-admin-live-card', $styles);
        $this->assertStringContainsString('body[data-mode="dark"] [data-admin-dashboard] .rt-admin-live-card', $styles);
        $this->assertStringContainsString('html.dark [data-admin-dashboard] .rt-admin-operations-card', $styles);
        $this->assertStringContainsString('html.dark [data-admin-dashboard] .rt-admin-quick-link', $styles);
        $this->assertStringContainsString('body[data-mode="dark"] [data-admin-dashboard] .rt-admin-quick-link', $styles);
        $this->assertStringContainsString('background-color: #111a27 !important', $styles);
        $this->assertStringContainsString('background-color: #182435 !important', $styles);
        $this->assertStringNotContainsString('lg:min-h-[25rem]', $dashboard);
        $this->assertStringNotContainsString('h-[270px] sm:h-[300px]', $dashboard);
        $this->assertStringContainsString('dark:bg-rt-dark-surface', $dashboard);
        $this->assertStringNotContainsString('dark:bg-[#111827]', $dashboard);
        $this->assertStringContainsString("const activityPointBorder = dark ? '#111a27' : '#ffffff'", $chartModule);
        $this->assertStringContainsString('activityAreaStart', $chartModule);
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
