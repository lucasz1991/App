<?php

namespace Tests\Feature;

use App\Livewire\Operations\PageWorkspace;
use App\Livewire\Operations\PlanningPageWorkspace;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsDutyMonitorService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\PlanningEnhancementSchema;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class NavigationHubRecoveryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private array $permissions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        $this->migrations(['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php']);
        $this->withoutVite();
        Mail::fake();
        Bus::fake();
        config(['operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T12:00:00+02:00'));
        foreach (['operations.manage', 'operations.rules.manage', 'employees.master-data.edit'] as $ability) {
            Gate::define($ability, fn (User $user) => in_array($ability, $this->permissions[$user->id] ?? [], true));
        }
    }

    private function migrations(array $files): void
    {
        foreach ($files as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    private function actor(array $abilities = ['operations.manage']): User
    {
        $actor = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->permissions[$actor->id] = $abilities;

        return $actor;
    }

    public function test_core_hubs_remain_discoverable_without_optional_extension_schemas(): void
    {
        $actor = $this->actor();
        $this->assertTrue(OperationsAccess::ready());
        $this->assertFalse(PlanningEnhancementSchema::ready());
        $this->assertFalse(WorkforcePlanningSchema::ready());
        $this->assertFalse(app(OperationsDutyMonitorService::class)->ready());
        $this->assertSame(['overview' => 'Übersicht'], OperationsPages::views($actor, 'planning'));
        $this->assertSame(['board' => 'Dienststand'], OperationsPages::views($actor, 'duty'));
        $this->assertArrayHasKey('planning', OperationsPages::availableFor($actor));
        $this->assertArrayHasKey('duty', OperationsPages::availableFor($actor));
        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'planning'])
            ->assertSee('Bedarf im Auftrag planen')
            ->assertSee('Noch nicht eingerichtet')
            ->assertSeeHtml('href="'.e(OperationsPages::url('cases', ['view' => 'orders'])).'"')
            ->assertSeeHtml('href="'.e(OperationsPages::url('shifts', ['view' => 'plan'])).'"')
            ->assertSeeHtml('href="'.e(OperationsPages::url('shifts', ['view' => 'calendar'])).'"')
            ->assertDontSeeHtml('wire:click="selectView(\'capacity\')"');
        $this->assertFalse(Schema::hasTable('workforce_positions'));
        Mail::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_installed_optional_views_remain_available_beside_the_core_overview(): void
    {
        $this->migrations([
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
            '2026_10_04_123000_extend_work_time_contexts.php',
            '2026_10_06_100000_create_planning_enhancements.php',
            '2026_10_06_102000_create_operations_enhancements.php',
            '2026_10_06_103000_create_operations_attention_tables.php',
        ]);
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        $actor = $this->actor(['operations.manage', 'operations.rules.manage']);
        $this->assertSame(['overview', 'capacity', 'staff', 'tools', 'logistics'], array_keys(OperationsPages::views($actor, 'planning')));
        $this->assertSame(['board', 'cases', 'transfers'], array_keys(OperationsPages::views($actor, 'duty')));
        Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'planning'])
            ->assertSet('view', 'overview')
            ->assertDontSeeHtml('<span class="ops-muted">Noch nicht eingerichtet</span>')
            ->assertSeeHtml('wire:click="selectView(\'capacity\')"')
            ->assertSeeHtml('wire:click="selectView(\'staff\')"')
            ->assertSeeHtml('wire:click="selectView(\'tools\')"')
            ->assertSeeHtml('wire:click="selectView(\'logistics\')"');
        Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'duty'])
            ->call('selectSection', 'profiles')->assertSet('section', 'profiles')->assertSee('Profil anlegen');
        $limited = $this->actor();
        Livewire::actingAs($limited)->test(PlanningPageWorkspace::class, ['page' => 'duty'])
            ->call('selectSection', 'profiles')->assertForbidden();
    }

    public function test_overview_does_not_claim_order_demand_setup_when_only_core_operations_are_installed(): void
    {
        Schema::drop('order_demands');
        $actor = $this->actor();
        $this->assertTrue(OperationsAccess::ready());
        Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'planning'])
            ->assertSee('Die Erweiterung für auftragsbezogene Bedarfe ist noch nicht eingerichtet.')
            ->assertDontSee('Auftrag auswählen und dort')
            ->assertSee('Aufträge öffnen');
    }

    public function test_core_duty_board_renders_real_published_duties_without_inventing_tolerances(): void
    {
        $actor = $this->actor();
        $employee = User::factory()->create(['name' => 'Dienst QA', 'role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Testbahn', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'QA Auftrag', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T06:00', 'ends_at' => '2027-05-12T20:00', 'required_staff' => 1, 'created_by' => $actor->id]);
        $shift = Shift::create(['order_id' => $order->id, 'title' => 'Veröffentlichter QA Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-12T10:00', 'ends_at' => '2027-05-12T18:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'confirmed', 'created_by' => $actor->id]);
        $shift->forceFill(['revision' => 1, 'published_revision' => 1])->save();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id, 'status' => 'confirmed', 'assigned_by' => $actor->id]);
        $assignment->forceFill(['plan_revision' => 1])->save();

        Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => 'duty'])
            ->assertSee('Veröffentlichter QA Dienst')
            ->assertSee('Dienst QA')
            ->assertSee('Toleranz offen')
            ->assertSee('es werden keine Toleranzen angenommen')
            ->assertDontSee('Meldung prüfen')
            ->assertDontSeeHtml('value="profiles"')
            ->assertDontSee('Profil anlegen');
        $this->assertSame(0, WorkTimeEntry::count());
        $this->assertFalse(Schema::hasTable('operations_monitor_profiles'));
        $this->assertFalse(Schema::hasTable('staffing_cases'));
    }

    public function test_absent_optional_profiles_and_planning_views_are_not_exposed_by_the_hub(): void
    {
        $actor = $this->actor(['operations.manage', 'operations.rules.manage']);
        Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'duty', 'initialSection' => 'profiles'])->assertForbidden();
        Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'duty'])
            ->call('selectSection', 'profiles')->assertForbidden();
        foreach (['capacity', 'staff', 'tools', 'logistics'] as $view) {
            Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'planning'])
                ->call('selectView', $view)->assertForbidden();
        }
        foreach (['cases', 'transfers'] as $view) {
            Livewire::actingAs($actor)->test(PlanningPageWorkspace::class, ['page' => 'duty'])
                ->call('selectView', $view)->assertForbidden();
        }
    }

    public function test_legacy_duty_board_bookmarks_work_but_absent_profiles_remain_blocked(): void
    {
        $actor = $this->actor(['operations.manage', 'operations.rules.manage']);
        $this->assertSame(OperationsPages::url('duty', ['view' => 'board']), OperationsPages::legacyUrlFor($actor, 'duty-monitor'));
        try {
            OperationsPages::legacyUrlFor($actor, 'duty-monitor', ['tab' => 'profiles']);
            $this->fail('Optional profiles must remain unavailable.');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        foreach (['planning-enhancements', 'workforce-planning', 'operations-enhancements'] as $module) {
            try {
                OperationsPages::authorizeLegacy($actor, $module);
                $this->fail('Optional module must not bypass setup.');
            } catch (HttpException $error) {
                $this->assertContains($error->getStatusCode(), [403, 503]);
            }
        }
    }

    public function test_hubs_preserve_actor_and_core_schema_requirements(): void
    {
        $actor = $this->actor([]);
        foreach (['planning', 'duty'] as $page) {
            $this->assertSame([], OperationsPages::views($actor, $page));
            Livewire::actingAs($actor)->test(PageWorkspace::class, ['page' => $page])->assertForbidden();
        }
        $allowed = $this->actor();
        $component = Livewire::actingAs($allowed)->test(PlanningPageWorkspace::class, ['page' => 'planning']);
        $this->permissions[$allowed->id] = [];
        $component->call('$refresh')->assertForbidden();
        $this->permissions[$allowed->id] = ['operations.manage'];
        $allowed->status = false;
        $this->assertSame([], OperationsPages::views($allowed, 'planning'));
        $this->assertSame([], OperationsPages::views($allowed, 'duty'));
        $allowed->status = true;
        Schema::drop('operations_rule_profiles');
        $this->assertSame([], OperationsPages::views($allowed, 'planning'));
        $this->assertSame([], OperationsPages::views($allowed, 'duty'));
    }
}
