<?php

namespace Tests\Feature;

use App\Livewire\Operations\AttentionCenter;
use App\Livewire\Operations\OperationsEnhancements;
use App\Livewire\Operations\PersonalWorkspace;
use App\Livewire\Operations\PersonnelEnhancements;
use App\Livewire\Operations\PlanningEnhancements;
use App\Livewire\Operations\Workspace;
use App\Models\Customer;
use App\Models\OperationsAttentionItem;
use App\Models\OperationsMonitorProfile;
use App\Models\OperationsMonthClosing;
use App\Models\OperationsReminderPreference;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\StaffingCase;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OperationsDutyMonitorService;
use App\Services\Operations\OperationsReminderService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\UnifiedOperationsInboxService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkTimeTerminalService;
use App\Support\Operations\OperationsNavigation;
use App\Support\Rbac\RbacCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsAttentionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $employee;

    private User $other;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_06_103000_create_operations_attention_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        Schema::table('shifts', fn (Blueprint $t) => $t->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T10:00:00+02:00'));
        $this->manager = User::factory()->create(['name' => 'Disposition QA', 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['name' => 'Anna QA', 'role' => 'staff', 'status' => true]);
        $this->other = User::factory()->create(['name' => 'Ben QA', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Gepflegt', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Testbahn', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'QA Auftrag', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-01T00:00', 'ends_at' => '2027-06-01T00:00', 'required_staff' => 1, 'created_by' => $this->manager->id]);
    }

    private function assignment(string $start = '2027-05-12T10:20', string $end = '2027-05-12T18:20', string $status = 'confirmed', int $published = 1): ShiftAssignment
    {
        $shift = Shift::create(['order_id' => $this->order->id, 'title' => 'QA Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => $published ? 'confirmed' : 'draft', 'created_by' => $this->manager->id, 'revision' => 1, 'published_revision' => $published]);
        $shift->forceFill(['revision' => 1, 'published_revision' => $published])->save();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => $status, 'assigned_by' => $this->manager->id]);
        $assignment->forceFill(['plan_revision' => 1])->save();

        return $assignment->fresh();
    }

    private function preference(array $extra = []): OperationsReminderPreference
    {
        return app(OperationsReminderService::class)->savePreference($this->employee, array_merge(['kind' => 'punch_start', 'lead_minutes' => 30, 'quiet_from' => null, 'quiet_until' => null, 'timezone' => 'Europe/Berlin', 'is_active' => true], $extra), $this->employee);
    }

    private function profile(array $extra = []): OperationsMonitorProfile
    {
        return app(OperationsDutyMonitorService::class)->saveProfile(array_merge(['name' => 'QA Toleranz', 'start_grace_minutes' => 5, 'end_grace_minutes' => 15, 'responsible_user_id' => $this->manager->id, 'auto_cases' => false, 'is_active' => true], $extra), $this->manager);
    }

    public function test_reminders_are_opt_in_and_idempotent_without_time_mutation(): void
    {
        $this->assignment();
        $service = app(OperationsReminderService::class);
        $this->assertSame(0, $service->runScheduled());
        $this->preference();
        $this->assertSame(1, $service->runScheduled());
        $this->assertSame(0, $service->runScheduled());
        $this->assertSame(1, OperationsAttentionItem::count());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_quiet_window_defers_delivery_and_inactive_policy_resolves_notice(): void
    {
        $this->assignment();
        $policy = $this->preference(['quiet_from' => '09:00', 'quiet_until' => '11:00']);
        $service = app(OperationsReminderService::class);
        $this->assertSame(0, $service->syncUser($this->employee));
        $this->travelTo(CarbonImmutable::parse('2027-05-12T11:00:00+02:00'));
        $this->assertSame(1, $service->syncUser($this->employee));
        $policy->forceFill(['is_active' => false])->save();
        $service->syncUser($this->employee);
        $this->assertNotNull(OperationsAttentionItem::first()->resolved_at);
    }

    public function test_unpublished_and_outdated_assignments_do_not_create_offers_or_alerts(): void
    {
        $this->assignment(published: 0);
        $this->assignment(published: 2);
        $this->preference();
        $this->assertSame(0, app(OperationsReminderService::class)->runScheduled());
        $this->assertCount(0, app(OperationsDutyMonitorService::class)->board($this->manager, '2027-05-12', '2027-05-12'));
    }

    public function test_employee_cannot_read_someone_elses_alert(): void
    {
        $this->assignment();
        $this->preference();
        app(OperationsReminderService::class)->runScheduled();
        try {
            app(OperationsReminderService::class)->markRead(OperationsAttentionItem::first()->id, 1, $this->other);
            $this->fail('Fremder Hinweis darf nicht gelesen werden.');
        } catch (ModelNotFoundException $error) {
            $this->assertSame(OperationsAttentionItem::class, $error->getModel());
        }
        $this->assertNull(OperationsAttentionItem::first()->read_at);
    }

    public function test_read_revision_is_checked(): void
    {
        $this->assignment();
        $this->preference();
        app(OperationsReminderService::class)->runScheduled();
        $item = OperationsAttentionItem::first();
        $service = app(OperationsReminderService::class);
        $service->markRead($item->id, 1, $this->employee);
        $this->assertNotNull($item->fresh()->read_at);
        $this->expectException(ValidationException::class);
        $service->markRead($item->id, 1, $this->employee);
    }

    public function test_monitor_requires_explicit_tolerance_and_never_invents_actual_times(): void
    {
        $this->assignment('2027-05-12T09:00', '2027-05-12T17:00');
        $service = app(OperationsDutyMonitorService::class);
        $this->assertSame('unconfigured', $service->board($this->manager, '2027-05-12', '2027-05-12')->first()['state']);
        $this->profile();
        $this->assertSame('start_missing', $service->board($this->manager, '2027-05-12', '2027-05-12')->first()['state']);
        $this->assertSame(0, $service->runScheduled());
        $this->assertSame(0, StaffingCase::count());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_explicit_escalation_is_revision_bound_and_idempotent(): void
    {
        $assignment = $this->assignment('2027-05-12T09:00', '2027-05-12T17:00');
        $this->profile();
        $service = app(OperationsDutyMonitorService::class);
        $case = $service->escalate($assignment->id, 1, $this->manager);
        $this->assertSame($case->id, $service->escalate($assignment->id, 1, $this->manager)->id);
        $this->assertSame(1, StaffingCase::count());
        $this->assertSame(0, WorkTimeEntry::count());
        $this->expectException(ValidationException::class);
        $service->escalate($assignment->id, 2, $this->manager);
    }

    public function test_profiles_switch_atomically_and_reject_stale_changes(): void
    {
        $old = $this->profile();
        $new = $this->profile(['name' => 'Neue Toleranz']);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertSame(1, OperationsMonitorProfile::where('is_active', true)->count());
        $this->expectException(ValidationException::class);
        app(OperationsDutyMonitorService::class)->saveProfile($old->toArray(), $this->manager, $old->id, 1);
    }

    public function test_attention_views_use_standard_rows_and_modals(): void
    {
        $this->assignment();
        $this->preference();
        app(OperationsReminderService::class)->runScheduled();
        $this->profile();
        Livewire::actingAs($this->employee)->test(AttentionCenter::class, ['personal' => true])->assertSee('QA Dienst')->call('setTab', 'reminders')->assertSee('Dienstbeginn')->call('edit')->assertSet('formOpen', true);
        Livewire::actingAs($this->manager)->test(AttentionCenter::class, ['mode' => 'monitor'])->assertSee('QA Dienst')->call('setTab', 'profiles')->assertSee('QA Toleranz')->call('edit')->assertSet('formOpen', true);
    }

    public function test_personal_and_manager_work_lists_do_not_cross_private_rows(): void
    {
        $this->assignment();
        $this->preference();
        app(OperationsReminderService::class)->runScheduled();
        $service = app(UnifiedOperationsInboxService::class);
        $this->assertCount(1, $service->items($this->employee, true));
        $this->assertCount(0, $service->items($this->other, true));
        $this->assertCount(0, $service->items($this->manager));
        Livewire::actingAs($this->employee)->test(AttentionCenter::class)->assertForbidden();
        Livewire::actingAs($this->employee)->test(AttentionCenter::class, ['personal' => true])->call('setTab', 'profiles')->assertStatus(422);
    }

    public function test_incomplete_schema_hides_only_new_modules(): void
    {
        Schema::drop('operations_attention_locks');
        $modules = OperationsNavigation::forUser($this->manager);
        $this->assertArrayNotHasKey('duty-monitor', $modules);
        $this->assertArrayHasKey('shift-management', $modules);
        $this->assertArrayHasKey('times', $modules);
        $this->assertSame(0, app(OperationsDutyMonitorService::class)->runScheduled());
    }

    public function test_private_permissions_are_never_granted_by_default(): void
    {
        foreach (['employees.emergency.access', 'employees.recruiting.manage', 'employees.development.manage', 'operations.costs.manage', 'operations.terminal.manage'] as $ability) {
            $this->assertContains($ability, RbacCatalog::allPermissions());
            $this->assertNotContains($ability, RbacCatalog::defaultRolePermissions()['team_access']);
            $this->assertFalse(RbacCatalog::defaultTeamPermissions()[$ability]);
        }
    }

    public function test_personal_area_defaults_to_existing_clock_and_switch_is_server_checked(): void
    {
        Livewire::actingAs($this->employee)->test(PersonalWorkspace::class)->assertSet('area', 'work')->call('setArea', 'inbox')->assertSee('Arbeitsliste')->call('setArea', 'personnel')->assertNotFound();
    }

    public function test_terminal_http_is_disabled_without_explicit_activation(): void
    {
        $this->postJson(route('api.operations.terminal.authenticate'), ['user_id' => $this->employee->id, 'pin' => '123456', 'terminal_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson(route('api.operations.terminal.capture'))->assertNotFound();
    }

    public function test_activated_terminal_http_uses_one_clock_action_without_login_or_offline_keys(): void
    {
        $this->allEnhancements();
        config(['operations.terminal_enabled' => true]);
        $terminal = (string) Str::uuid();
        app(WorkTimeTerminalService::class)->configure($this->employee, ['pin' => '729184', 'terminal_id' => $terminal, 'location_consent' => false], $this->employee);
        $response = $this->postJson(route('api.operations.terminal.authenticate'), ['user_id' => $this->employee->id, 'pin' => '729184', 'terminal_id' => $terminal])->assertOk();
        $this->assertGuest();
        $this->assertArrayNotHasKey('encryption_key', $response->json());
        $this->assertSame(60, $response->json('expires_in'));
        $token = $response->json('token');
        $body = ['terminal_id' => $terminal, 'event' => ['event_key' => (string) Str::uuid(), 'action' => 'start', 'revision' => 0, 'work_context' => 'internal', 'title' => 'Interne QA Tätigkeit']];
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson(route('api.operations.terminal.capture'), $body)->assertOk()->assertJsonPath('status', 'accepted');
        $this->assertGuest();
        $this->assertSame(1, WorkTimeEntry::count());
        $this->assertSame('internal', WorkTimeEntry::first()->work_context);
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson(route('api.operations.terminal.capture'), $body)->assertForbidden();
        $this->assertSame(1, WorkTimeEntry::count());
    }

    public function test_migration_is_repeatable_and_scheduler_safe_without_optional_tables(): void
    {
        (require database_path('migrations/2026_10_06_103000_create_operations_attention_tables.php'))->up();
        $this->assertTrue(app(OperationsReminderService::class)->ready());
        $this->artisan('operations:attention-run')->assertExitCode(0);
        Schema::drop('operations_attention_items');
        $this->assertFalse(app(OperationsReminderService::class)->ready());
        $this->artisan('operations:attention-run')->assertExitCode(0);
    }

    private function allEnhancements(): void
    {
        foreach (['2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_100000_create_planning_enhancements.php', '2026_10_06_101000_create_personnel_enhancements.php', '2026_10_06_102000_create_operations_enhancements.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    public function test_unpublished_draft_does_not_move_released_monitor_or_reminder_times(): void
    {
        $assignment = $this->assignment('2027-05-12T09:00', '2027-05-12T17:00');
        $shift = $assignment->shift;
        $snapshot = $shift->only(['order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'planned_break_minutes']);
        $shift->forceFill(['published_snapshot' => $snapshot, 'revision' => 2, 'starts_at' => '2027-06-20T12:00', 'ends_at' => '2027-06-20T20:00', 'title' => 'Unveröffentlichter Entwurf'])->save();
        $this->profile();
        $this->preference();
        $row = app(OperationsDutyMonitorService::class)->board($this->manager, '2027-05-12', '2027-05-12')->first();
        $this->assertSame('QA Dienst', $row['title']);
        $this->assertSame('start_missing', $row['state']);
        $this->assertTrue($row['starts_at']->equalTo($snapshot['starts_at']));
        $this->assertSame(1, app(OperationsReminderService::class)->syncUser($this->employee));
        $notice = OperationsAttentionItem::firstOrFail();
        $this->assertTrue($notice->due_at->equalTo($snapshot['starts_at']));
        $this->assertStringNotContainsString('Entwurf', $notice->headline);
        $case = app(OperationsDutyMonitorService::class)->escalate($assignment->id, 1, $this->manager);
        $this->assertSame($assignment->id, $case->shift_assignment_id);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_invalid_released_snapshot_cannot_use_current_draft_as_operational_truth(): void
    {
        $assignment = $this->assignment('2027-05-12T09:00', '2027-05-12T17:00');
        $assignment->shift->forceFill(['revision' => 2, 'published_snapshot' => ['title' => 'Unvollständig']])->save();
        $this->profile();
        $this->preference();
        $rows = app(OperationsDutyMonitorService::class)->board($this->manager, '2027-05-12', '2027-05-12');
        $this->assertCount(1, $rows);
        $this->assertSame('needs_review', $rows->first()['state']);
        $this->assertNull($rows->first()['starts_at']);
        $this->assertSame(0, app(OperationsReminderService::class)->syncUser($this->employee));
        $this->expectException(ValidationException::class);
        app(OperationsDutyMonitorService::class)->escalate($assignment->id, 1, $this->manager);
    }

    public function test_all_new_workspaces_are_reachable_without_replacing_the_shift_plan(): void
    {
        $this->allEnhancements();
        $modules = OperationsNavigation::forUser($this->manager);
        foreach (['shift-management', 'planning-enhancements', 'personnel-enhancements', 'operations-enhancements', 'attention-center', 'duty-monitor'] as $module) {
            $this->assertArrayHasKey($module, $modules);
            Livewire::actingAs($this->manager)->test(Workspace::class, ['module' => $module])->assertOk();
        }
        foreach (['personnel', 'operations', 'inbox', 'work'] as $area) {
            Livewire::actingAs($this->employee)->test(PersonalWorkspace::class)->call('setArea', $area)->assertOk();
        }
    }

    public function test_closed_payroll_month_also_protects_existing_time_account_adjustments(): void
    {
        $this->allEnhancements();
        $service = app(WorkforceAccountService::class);
        $data = ['effective_on' => '2027-05-12', 'quantity' => 30, 'note' => 'QA Korrektur', 'idempotency_key' => (string) Str::uuid()];
        $original = $service->adjustTime($this->employee, $data, $this->manager);
        OperationsMonthClosing::create(['user_id' => $this->employee->id, 'month' => '2027-05', 'timezone' => 'Europe/Berlin', 'status' => 'closed', 'snapshot' => [], 'prepared_by' => $this->manager->id, 'closed_by' => $this->manager->id]);
        // A replay is not a mutation; a distinct new adjustment must fail.
        $this->assertSame($original->id, $service->adjustTime($this->employee, $data, $this->manager)->id);
        $this->expectException(ValidationException::class);
        $service->adjustTime($this->employee, array_merge($data, ['idempotency_key' => (string) Str::uuid()]), $this->manager);
    }

    public function test_partial_additional_rules_cannot_silently_disable_central_assignment_checks(): void
    {
        $this->allEnhancements();
        $assignment = $this->assignment();
        Schema::table('operations_rate_rules', fn (Blueprint $table) => $table->dropColumn('configuration'));
        $issues = app(StaffEligibilityService::class)->assessMany($assignment->shift, collect([$this->other]))[$this->other->id];
        $this->assertContains('additional_rules_incomplete', array_column($issues, 'code'));
        Schema::drop('operations_rate_rules');
        $issues = app(StaffEligibilityService::class)->assessMany($assignment->shift, collect([$this->other]))[$this->other->id];
        $this->assertNotContains('additional_rules_incomplete', array_column($issues, 'code'));
        $this->assertArrayHasKey('shift-management', OperationsNavigation::forUser($this->manager));
    }

    public function test_all_private_new_tabs_enforce_employee_and_manager_boundaries(): void
    {
        $this->allEnhancements();
        foreach (['reports', 'approvals', 'calendars', 'recruiting'] as $tab) {
            Livewire::actingAs($this->employee)->test(PersonnelEnhancements::class, ['personal' => true, 'tab' => $tab])->assertForbidden();
        }
        foreach (['rules', 'payroll', 'costs', 'imports'] as $tab) {
            Livewire::actingAs($this->employee)->test(OperationsEnhancements::class, ['personal' => true])->call('setTab', $tab)->assertForbidden();
        }
        Livewire::actingAs($this->employee)->test(PlanningEnhancements::class)->assertForbidden();
    }
}
