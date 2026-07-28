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

    public function test_administrator_can_open_every_preview_without_a_demo_notice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (OperationalPreviewCatalog::slugs() as $slug) {
            $module = app(OperationalPreviewCatalog::class)->find($slug);
            $component = Livewire::actingAs($admin)
                ->test(OperationalPreview::class, ['module' => $slug])
                ->assertOk()
                ->assertSee('data-operational-module="' . $slug . '"', escape: false)
                ->assertSee($module['title'])
                ->assertSee($module['metric'])
                ->assertSee($module['badge'])
                ->assertDontSee('data-preview-notice', escape: false)
                ->assertDontSee(__('app.preview_not_productive'))
                ->assertDontSee(__('app.preview_no_database'));

            $html = $component->html();
            $this->assertSame(3, substr_count($html, 'data-operational-stat'));
            $this->assertSame(3, substr_count($html, 'data-operational-item'));
            $this->assertSame(4, substr_count($html, 'data-operational-nav-link'));
            $this->assertSame(1, substr_count($html, 'aria-current="page"'));
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

    public function test_sidebar_splits_company_management_operations_and_content_sections(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/admin-sidebar.blade.php'));

        $companyPosition = strpos($sidebar, ":label=\"__('app.company')\"");
        $communicationPosition = strpos($sidebar, ":label=\"__('app.communication')\"");
        $managementPosition = strpos($sidebar, ":label=\"__('app.management')\"");
        $operationsPosition = strpos($sidebar, ":label=\"__('app.operations')\"");
        $contentPosition = strpos($sidebar, ":label=\"__('app.content_and_files')\"");
        $myAreaPosition = strpos($sidebar, ":label=\"__('app.my_area')\"");

        $settingsPosition = strpos($sidebar, "route('admin.settings')");
        $chatPosition = strpos($sidebar, "route('chat')");
        $employeesPosition = strpos($sidebar, "route('admin.employees')");
        $customersPosition = strpos($sidebar, "'module' => 'customers'");
        $mailPosition = strpos($sidebar, "route('admin.mail-management')");
        $wagonPosition = strpos($sidebar, "route('admin.operations.wagon-list')");
        $emailTemplatesPosition = strpos($sidebar, "route('email-templates.index')");
        $profileSupportPosition = strpos($sidebar, "<x-slot:label>{{ __('app.profile_and_support') }}</x-slot:label>");

        $this->assertNotFalse($companyPosition);
        $this->assertNotFalse($communicationPosition);
        $this->assertNotFalse($managementPosition);
        $this->assertNotFalse($operationsPosition);
        $this->assertNotFalse($contentPosition);
        $this->assertNotFalse($myAreaPosition);
        $this->assertNotFalse($settingsPosition);
        $this->assertNotFalse($chatPosition);
        $this->assertNotFalse($employeesPosition);
        $this->assertNotFalse($customersPosition);
        $this->assertNotFalse($mailPosition);
        $this->assertNotFalse($wagonPosition);
        $this->assertNotFalse($emailTemplatesPosition);

        // Firma traegt nur die Einstellungen; Kommunikation folgt direkt
        // danach und bleibt vor dem Management.
        $this->assertLessThan($communicationPosition, $companyPosition);
        $this->assertLessThan($communicationPosition, $settingsPosition);
        $this->assertGreaterThan($communicationPosition, $chatPosition);
        $this->assertLessThan($managementPosition, $communicationPosition);
        $this->assertLessThan($managementPosition, $chatPosition);

        // Management bündelt Mitarbeiter, Kunden und Mailverwaltung vor dem Betrieb.
        $this->assertGreaterThan($managementPosition, $employeesPosition);
        $this->assertGreaterThan($managementPosition, $customersPosition);
        $this->assertGreaterThan($managementPosition, $mailPosition);
        $this->assertLessThan($operationsPosition, $customersPosition);
        $this->assertLessThan($operationsPosition, $mailPosition);

        // Die Wagenliste ist ein eigenständiger Betriebslink, nicht mehr in der Betriebssteuerungs-Gruppe.
        $this->assertLessThan($contentPosition, $operationsPosition);
        $this->assertGreaterThan($operationsPosition, $wagonPosition);
        $this->assertStringNotContainsString("__('app.operational_control')", $sidebar);
        $this->assertStringNotContainsString("__('app.organization')", $sidebar);

        // E-Mail-Vorlagen liegen im persönlichen Bereich über Profil & Support.
        $this->assertGreaterThan($myAreaPosition, $emailTemplatesPosition);
        $this->assertLessThan($profileSupportPosition, $emailTemplatesPosition);

        // Management bündelt seine Einträge in zwei Untergruppen: Verwaltung und Disposition.
        $this->assertStringContainsString("<x-slot:label>{{ __('app.management_administration') }}</x-slot:label>", $sidebar);
        $this->assertStringContainsString("<x-slot:label>{{ __('app.management_dispatching') }}</x-slot:label>", $sidebar);

        // Weitere aufklappbare Gruppen: Dateien, Chat & Nachrichten, Profil & Support.
        $this->assertStringContainsString("<x-slot:label>{{ __('app.sidebar_files') }}</x-slot:label>", $sidebar);
        $this->assertStringContainsString("<x-slot:label>{{ __('app.chat_and_messages') }}</x-slot:label>", $sidebar);
        $this->assertStringContainsString("<x-slot:label>{{ __('app.profile_and_support') }}</x-slot:label>", $sidebar);
        $this->assertSame(5, substr_count($sidebar, '<x-menu.sidebar-nav-group'));
        $this->assertStringContainsString('class="!pl-8"', $sidebar);
        $this->assertStringContainsString("{{ __('app.sidebar_work_resources') }}", $sidebar);
    }

    public function test_dashboard_animation_waits_for_visible_kpis_and_does_not_replay_on_theme_changes(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));
        $chartModule = file_get_contents(resource_path('js/admin-dashboard-echarts.js'));
        $revealScript = file_get_contents(resource_path('js/gsap.js'));
        $dashboard = file_get_contents(resource_path('views/livewire/admin/dashboard.blade.php'));

        $this->assertStringContainsString('data-dashboard-kpis', $dashboard);
        preg_match('/<section[^>]*data-dashboard-kpis.*?<\/section>/s', $dashboard, $kpiSection);
        $this->assertNotEmpty($kpiSection);
        $this->assertStringNotContainsString('data-rt-glow', $kpiSection[0]);
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
        $this->assertStringContainsString("root.querySelectorAll('[data-admin-dashboard] [data-dashboard-segment]')", $revealScript);
        $this->assertStringContainsString("!element.closest('[data-dashboard-segment]')", $revealScript);
        $this->assertGreaterThanOrEqual(2, substr_count($revealScript, 'gsap.timeline('));
        $this->assertStringContainsString('timeline.addLabel(label, position)', $revealScript);
        $this->assertStringContainsString('timeline.call(() => markComplete(targets))', $revealScript);
        $this->assertMatchesRegularExpression('/if \(conditions\.reduceMotion\) \{\s*showImmediately\(allTargets\);\s*return;\s*\}/s', $revealScript);
        $this->assertSame(1, substr_count($revealScript, "document.addEventListener('livewire:navigating'"));
        $this->assertSame(1, substr_count($revealScript, "document.addEventListener('livewire:navigated'"));
        $this->assertStringContainsString('activeMedia?.revert()', $revealScript);
        $this->assertStringContainsString('activeAmbientMedia?.revert()', $revealScript);
        $this->assertStringContainsString('setupShellAmbient(root)', $revealScript);
        $this->assertStringContainsString('revealGeneration += 1', $revealScript);
        $this->assertStringContainsString('generation === revealGeneration', $revealScript);
        $this->assertStringNotContainsString("querySelectorAll('[data-anim][data-anim-done]')", $revealScript);
    }

    public function test_operational_modules_use_strong_shared_dark_mode_surfaces(): void
    {
        $preview = file_get_contents(resource_path('views/livewire/admin/operational-preview.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('rt-operational-page', $preview);
        $this->assertStringNotContainsString('rt-operational-notice', $preview);
        $this->assertStringContainsString('rt-operational-stats', $preview);
        $this->assertStringContainsString('rt-operational-stat', $preview);
        $this->assertStringContainsString('rt-operational-nav-link-active', $preview);
        $this->assertStringNotContainsString('rt-operational-notice-copy', $preview);
        $this->assertStringContainsString('data-operational-tone=', $preview);
        $this->assertStringContainsString('.dark .rt-operational-stat', $styles);
        $this->assertStringContainsString('.dark .rt-operational-nav-link-active', $styles);
        foreach ([
            'rt-admin-panel',
            'rt-operational-tone',
            'rt-operational-stat',
            'rt-operational-item-status',
            'rt-operational-schema',
            'rt-operational-nav-link',
            'rt-operational-nav-link-active',
            'rt-operational-nav-icon',
        ] as $hook) {
            $this->assertStringContainsString("html.dark .rt-operational-page .{$hook}", $styles);
            $this->assertStringContainsString("body[data-mode=\"dark\"] .rt-operational-page .{$hook}", $styles);
        }
        $this->assertStringContainsString('background-color: #182435 !important', $styles);
        $this->assertStringContainsString('dark:bg-rt-dark-surface', $preview);
        $this->assertStringNotContainsString('dark:bg-[#111827]', $preview);
        $this->assertStringContainsString('html.dark body[data-sidebar-collapsible="true"]', $styles);
    }
}
