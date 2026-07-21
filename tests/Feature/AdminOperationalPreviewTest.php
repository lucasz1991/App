<?php

namespace Tests\Feature;

use App\Livewire\Admin\OperationalPreview;
use App\Models\User;
use App\Support\Operations\OperationalPreviewCatalog;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class AdminOperationalPreviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildMinimalRailTimeSchema();
    }

    public function test_catalog_contains_the_four_static_operational_modules_without_persistence(): void
    {
        $catalog = app(OperationalPreviewCatalog::class);
        $source = file_get_contents(app_path('Support/Operations/OperationalPreviewCatalog.php'));

        $this->assertSame(['orders', 'shift-management', 'calendar', 'customers'], OperationalPreviewCatalog::slugs());
        $this->assertCount(4, $catalog->dashboard());
        $this->assertSame('12', $catalog->find('orders')['metric']);
        $this->assertSame('248', $catalog->find('customers')['metric']);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('Facades\\DB', $source);
        $this->assertStringNotContainsString('Schema::', $source);
    }

    public function test_administrator_can_open_every_preview_and_sees_the_non_productive_notice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (OperationalPreviewCatalog::slugs() as $slug) {
            Livewire::actingAs($admin)
                ->test(OperationalPreview::class, ['module' => $slug])
                ->assertOk()
                ->assertSee('data-preview-notice', escape: false)
                ->assertSee(__('app.preview_not_productive'))
                ->assertSee(__('app.preview_no_database'));
        }
    }

    public function test_operational_preview_routes_are_admin_only_and_reject_unknown_modules(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);

        $this->actingAs($admin)
            ->get(route('admin.operations.preview', ['module' => 'orders']))
            ->assertOk()
            ->assertSee(__('app.operational_orders'))
            ->assertSee(__('app.shift_management'))
            ->assertSee(__('app.operational_calendar'))
            ->assertSee(__('app.customer_database'));

        $this->actingAs($staff)
            ->get(route('admin.operations.preview', ['module' => 'orders']))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->get('/administrator/betrieb/not-a-module')
            ->assertNotFound();
    }

    public function test_sidebar_has_its_own_operational_heading_below_all_administration_links(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));
        $settingsPosition = strpos($sidebar, "route('admin.settings')");
        $employeesPosition = strpos($sidebar, "route('admin.employees')");
        $mailPosition = strpos($sidebar, "route('admin.mail-management')");
        $previewHeadingPosition = strpos($sidebar, ":label=\"__('app.operations_preview')\"");

        $this->assertNotFalse($settingsPosition);
        $this->assertNotFalse($employeesPosition);
        $this->assertNotFalse($mailPosition);
        $this->assertNotFalse($previewHeadingPosition);
        $this->assertLessThan($previewHeadingPosition, $settingsPosition);
        $this->assertLessThan($previewHeadingPosition, $employeesPosition);
        $this->assertLessThan($previewHeadingPosition, $mailPosition);
        $this->assertStringContainsString('<x-menu.sidebar-nav-group', $sidebar);
        $this->assertStringContainsString("<x-slot:label>{{ __('app.operational_control') }}</x-slot:label>", $sidebar);
        $this->assertStringContainsString('class="!pl-12"', $sidebar);
    }

    public function test_dashboard_animation_waits_for_visible_kpis_and_does_not_replay_on_theme_changes(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $chartModule = file_get_contents(resource_path('js/admin-dashboard-echarts.js'));
        $revealScript = file_get_contents(resource_path('js/gsap.js'));
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));

        $this->assertStringContainsString('data-dashboard-kpis', $dashboard);
        $this->assertStringContainsString('new IntersectionObserver(', $script);
        $this->assertStringContainsString("kpiGrid.querySelectorAll('[data-dashboard-count]')", $script);
        $this->assertStringContainsString('renderCharts(!this.chartsRendered)', $script);
        $this->assertStringContainsString('this.counterTween?.kill()', $script);
        $this->assertStringContainsString('animate = true', $chartModule);
        $this->assertStringContainsString('echarts.getInstanceByDom(element)?.dispose()', $chartModule);
        $this->assertStringContainsString('if (!root || root === activePageRoot) return;', $revealScript);
        $this->assertStringContainsString("start: 'clamp(top 90%)'", $revealScript);
        $this->assertStringContainsString("clearProps: 'transform,opacity,visibility'", $revealScript);
        $this->assertStringContainsString('isInitiallyVisible(trigger)', $revealScript);
        $this->assertStringNotContainsString("querySelectorAll('[data-anim][data-anim-done]')", $revealScript);
    }
}
