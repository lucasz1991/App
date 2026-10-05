<?php

namespace Tests\Feature;

use App\Enums\ShiftAssignmentStatus;
use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\PersonnelReview;
use App\Livewire\Operations\PlanVariants;
use App\Livewire\Operations\StaffTimeline;
use App\Livewire\Operations\WorkforcePlanning;
use App\Livewire\Operations\Workspace;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeRuleAssignment;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Models\WorkTimeEntry;
use App\Services\Operations\OrderDemandService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\PlanVariantService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\WorkforcePlanningService;
use App\Support\Operations\WorkforcePlanningSchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkforcePlanningWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $anna;

    private User $ben;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        Schema::table('shifts', fn (Blueprint $t) => $t->json('disposition_details')->nullable());
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Freigegeben', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Jahresleistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-01-01T00:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
    }

    private function shift(string $date = '2027-05-13', array $extra = []): Shift
    {
        return app(ShiftSchedulingService::class)->save(new Shift, array_merge(['order_id' => $this->order->id, 'title' => 'Dienst '.$date, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => CarbonImmutable::parse($date.'T08:00:00+02:00'), 'ends_at' => CarbonImmutable::parse($date.'T16:00:00+02:00'), 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'location_name' => 'Bremen'], $extra), $this->manager);
    }

    private function confirmed(Shift $shift, User $user): ShiftAssignment
    {
        $assignment = app(ShiftAssignmentService::class)->assign($shift, $user, $this->manager, ShiftAssignmentStatus::Requested);
        app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->manager);
        app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $user);

        return $assignment->fresh();
    }

    private function invalid(callable $work, string $text): void
    {
        try {
            $work();
            $this->fail('Expected validation failure');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($text, implode(' ', array_merge(...array_values($exception->errors()))));
        }
    }

    private function wish(array $extra = []): array
    {
        return array_merge(['kind' => 'unavailable', 'from' => '2027-05-13', 'until' => '2027-05-27', 'weekdays' => [4], 'whole_day' => false, 'start_time' => '08:00', 'end_time' => '10:00', 'timezone' => 'Europe/Berlin'], $extra);
    }

    public function test_wishes_repeat_without_creating_absences_or_binding_availability(): void
    {
        $shift = $this->shift();
        $service = app(WorkforcePlanningService::class);
        $this->assertSame('unknown', $service->wishSummary($shift, $this->anna)['state']);
        $service->saveWish(null, null, $this->wish(), $this->anna);
        $this->assertSame('free_requested', $service->wishSummary($shift, $this->anna)['state']);
        $this->assertSame('free_requested', $service->wishSummary($this->shift('2027-05-20'), $this->anna)['state']);
        $this->assertSame('unknown', $service->wishSummary($this->shift('2027-05-14'), $this->anna)['state']);
        $this->assertSame(0, AbsenceRequest::count());
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($shift, collect([$this->anna]))[$this->anna->id]);
    }

    public function test_migration_resumes_when_process_tables_exist_and_demand_columns_are_missing(): void
    {
        $pool = WorkforcePool::create(['name' => 'Erhaltener Pool', 'kind' => 'reserve']);
        $demand = app(OrderDemandService::class)->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 2, 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $before = $demand->only(['id', 'order_id', 'role_name', 'required_staff', 'status', 'revision', 'created_by']);
        Schema::table('order_demands', fn (Blueprint $table) => $table->dropConstrainedForeignId('workforce_pool_id'));
        Schema::table('order_demands', fn (Blueprint $table) => $table->dropColumn(['staffing_mode', 'maximum_staff', 'qualification_ids']));
        $this->assertFalse(WorkforcePlanningSchema::ready());
        $migration = require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(WorkforcePlanningSchema::ready());
        $this->assertSame($before, $demand->fresh()->only(array_keys($before)));
        $this->assertSame('minimum', $demand->fresh()->staffing_mode);
        $this->assertNull($demand->fresh()->maximum_staff);
        $this->assertSame('Erhaltener Pool', $pool->fresh()->name);
        $this->assertSame(1, WorkforcePool::count());
    }

    public function test_partial_migration_repairs_missing_tables_columns_and_foreign_key_without_overwriting_values(): void
    {
        $pool = WorkforcePool::create(['name' => 'Stammpool behalten', 'kind' => 'regular']);
        $wish = app(WorkforcePlanningService::class)->saveWish(null, null, $this->wish(), $this->anna);
        $demand = app(OrderDemandService::class)->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'range', 'maximum_staff' => 3, 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        Schema::drop('staffing_cases');
        Schema::drop('plan_variants');
        Schema::table('order_demands', fn (Blueprint $table) => $table->dropColumn(['maximum_staff', 'qualification_ids']));
        // Simulate interruption between ADD COLUMN and ADD CONSTRAINT: keep the pool column.
        Schema::table('order_demands', fn (Blueprint $table) => $table->dropForeign(['workforce_pool_id']));
        $demand->forceFill(['workforce_pool_id' => $pool->id])->save();
        $migration = require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(WorkforcePlanningSchema::ready());
        $this->assertSame('range', $demand->fresh()->staffing_mode);
        $this->assertSame($pool->id, $demand->fresh()->workforce_pool_id);
        $this->assertSame('Stammpool behalten', $pool->fresh()->name);
        $this->assertSame($this->anna->id, $wish->fresh()->user_id);
        $this->assertCount(1, collect(Schema::getForeignKeys('order_demands'))->filter(fn ($key) => $key['columns'] === ['workforce_pool_id'] && $key['foreign_table'] === 'workforce_pools'));
    }

    public function test_wish_deadline_requires_reason_and_preserves_period_and_person_scope(): void
    {
        $service = app(WorkforcePlanningService::class);
        $period = $service->savePeriod(null, null, ['name' => 'Mai', 'from' => '2027-05-13', 'until' => '2027-05-31', 'due_at' => '2027-05-11T12:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $this->invalid(fn () => $service->saveWish(null, null, $this->wish(['availability_period_id' => $period->id]), $this->anna), 'Begründung');
        $wish = $service->saveWish(null, null, $this->wish(['availability_period_id' => $period->id, 'late_reason' => 'Termin wurde erst heute bekannt']), $this->anna);
        $this->assertSame('submitted', $service->periodStatus($period, $this->anna));
        $this->assertSame('overdue', $service->periodStatus($period, $this->ben));
        $this->invalid(fn () => $service->saveWish($wish->id, 1, $this->wish(['late_reason' => 'Geändert wegen Termin']), $this->anna), 'nicht entfernt');
        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->ben)->test(WorkforcePlanning::class, ['personal' => true])->call('edit', 'wish', $wish->id);
    }

    public function test_invalid_dst_wish_and_empty_weekday_occurrence_are_rejected(): void
    {
        $service = app(WorkforcePlanningService::class);
        $this->invalid(fn () => $service->saveWish(null, null, $this->wish(['from' => '2027-03-28', 'until' => '2027-03-28', 'weekdays' => [7], 'start_time' => '02:30', 'end_time' => '04:00']), $this->anna), 'Zeitumstellung');
        $this->invalid(fn () => $service->saveWish(null, null, $this->wish(['from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [1]]), $this->anna), 'kein Zeitfenster');
        $this->assertSame(0, EmployeeAvailability::count());
    }

    public function test_offers_require_current_publication_and_employee_interest_never_assigns(): void
    {
        $service = app(WorkforcePlanningService::class);
        $shift = $this->shift();
        $this->invalid(fn () => $service->offer($shift->id, 1, [$this->anna->id], '2027-05-12T18:00', 'Europe/Berlin', $this->manager), 'veröffentlichte');
        app(PlanPublicationService::class)->publish($shift, 1, $this->manager);
        $offer = $service->offer($shift->id, 1, [$this->anna->id], '2027-05-12T18:00', 'Europe/Berlin', $this->manager);
        $response = $service->respondOffer($offer->id, 1, 'interested', $this->anna);
        $this->assertSame(0, ShiftAssignment::count());
        $service->reviewOffer($response->id, 1, true, $this->manager);
        $this->assertSame('requested', ShiftAssignment::first()->status->value);
        $this->assertSame('filled', $offer->fresh()->status);
    }

    public function test_offer_permission_capacity_and_stale_publication_are_rechecked_on_review(): void
    {
        $service = app(WorkforcePlanningService::class);
        $shift = $this->shift();
        app(PlanPublicationService::class)->publish($shift, 1, $this->manager);
        $offer = $service->offer($shift->id, 1, [$this->anna->id, $this->ben->id], '2027-05-12T18:00', 'Europe/Berlin', $this->manager);
        $response = $service->respondOffer($offer->id, 1, 'interested', $this->anna);
        app(ShiftAssignmentService::class)->assign($shift, $this->ben, $this->manager);
        $this->invalid(fn () => $service->reviewOffer($response->id, 1, true, $this->manager), 'reserviert');
        $this->assertSame('interested', $response->fresh()->status);
        $shift->forceFill(['revision' => 2])->save();
        $this->invalid(fn () => $service->reviewOffer($response->id, 1, true, $this->manager), 'veröffentlichte');
        $stranger = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($stranger)->test(WorkforcePlanning::class, ['personal' => true])->call('offerResponse', $offer->id, 1, 'interested')->assertForbidden();
    }

    public function test_capacity_is_a_central_guard_not_only_an_offer_ui_rule(): void
    {
        $shift = $this->shift();
        $service = app(ShiftAssignmentService::class);
        $service->assign($shift, $this->anna, $this->manager);
        $this->invalid(fn () => $service->assign($shift, $this->ben, $this->manager), 'reserviert');
        $service->assign($shift, $this->anna, $this->manager, ShiftAssignmentStatus::Requested);
        $this->assertSame(1, $shift->assignments()->blocking()->count());
        $shift->forceFill(['revision' => 2])->save();
        $this->invalid(fn () => $service->assign($shift, $this->anna, $this->manager, ShiftAssignmentStatus::Requested, null, 1), 'geändert');
    }

    public function test_swap_requires_both_employee_consent_and_manager_approval_then_new_responses(): void
    {
        $first = $this->confirmed($this->shift(), $this->anna);
        $second = $this->confirmed($this->shift('2027-05-14'), $this->ben);
        $service = app(WorkforcePlanningService::class);
        $request = $service->requestTransfer($first->id, 1, $this->ben->id, $second->id, 1, 'Tausch gewünscht', $this->anna);
        $this->invalid(fn () => $service->reviewTransfer($request->id, 1, true, '', $this->manager), 'bereits bearbeitet');
        $service->respondTransfer($request->id, 1, true, $this->ben);
        $service->reviewTransfer($request->id, 2, true, '', $this->manager);
        $this->assertSame('cancelled', $first->fresh()->status->value);
        $this->assertSame('cancelled', $second->fresh()->status->value);
        $this->assertSame('requested', ShiftAssignment::where('shift_id', $first->shift_id)->where('user_id', $this->ben->id)->first()->status->value);
        $this->assertSame('requested', ShiftAssignment::where('shift_id', $second->shift_id)->where('user_id', $this->anna->id)->first()->status->value);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_swap_failure_rolls_back_both_sides_when_a_new_absence_appears(): void
    {
        $first = $this->confirmed($this->shift(), $this->anna);
        $second = $this->confirmed($this->shift('2027-05-14'), $this->ben);
        $service = app(WorkforcePlanningService::class);
        $request = $service->requestTransfer($first->id, 1, $this->ben->id, $second->id, 1, '', $this->anna);
        $service->respondTransfer($request->id, 1, true, $this->ben);
        AbsenceRequest::create(['user_id' => $this->anna->id, 'kind' => 'sick', 'status' => 'reported', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T00:00', 'ends_at' => '2027-05-15T00:00']);
        $this->invalid(fn () => $service->reviewTransfer($request->id, 2, true, '', $this->manager), 'Abwesenheit');
        $this->assertSame('confirmed', $first->fresh()->status->value);
        $this->assertSame('confirmed', $second->fresh()->status->value);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(2, ShiftAssignment::count());
    }

    public function test_invalid_self_transfer_and_stale_second_revision_leave_assignments_untouched(): void
    {
        $first = $this->confirmed($this->shift(), $this->anna);
        $second = $this->confirmed($this->shift('2027-05-14'), $this->ben);
        $service = app(WorkforcePlanningService::class);
        $this->invalid(fn () => $service->requestTransfer($first->id, 1, $this->anna->id, null, null, '', $this->anna), 'sich selbst');
        $request = $service->requestTransfer($first->id, 1, $this->ben->id, $second->id, 1, '', $this->anna);
        $service->respondTransfer($request->id, 1, true, $this->ben);
        $second->shift->forceFill(['revision' => 2])->save();
        $this->invalid(fn () => $service->reviewTransfer($request->id, 2, true, '', $this->manager), 'veröffentlichte');
        $this->assertSame('confirmed', $first->fresh()->status->value);
        $this->assertSame('confirmed', $second->fresh()->status->value);
    }

    public function test_pool_and_exact_demand_requirements_are_shared_by_preview_and_assignment(): void
    {
        $planning = app(WorkforcePlanningService::class);
        $pool = $planning->savePool(null, null, ['name' => 'Bremen', 'kind' => 'reserve', 'is_active' => true, 'user_ids' => [$this->anna->id]], $this->manager);
        $demand = app(OrderDemandService::class)->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'exact', 'workforce_pool_id' => $pool->id, 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $shift = app(OrderDemandService::class)->generate($demand->id, 1, 30, $this->manager);
        $this->assertSame(1, $demand->maximum_staff);
        $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$this->ben]))[$this->ben->id];
        $this->assertContains('pool', array_column($issues, 'code'));
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->ben, $this->manager), 'Bedarfspool');
        app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager, ShiftAssignmentStatus::Requested);
        $coverage = app(OrderDemandService::class)->coverage($demand);
        $this->assertSame(1, $coverage['reserved']);
        $this->assertSame(1, $coverage['requested']);
        $this->assertSame(1, $coverage['missing']);
        $this->assertCount(1, $coverage['segments']);
    }

    public function test_minimum_demand_has_no_silent_upper_cap_but_explicit_exact_caps_are_guarded(): void
    {
        $service = app(OrderDemandService::class);
        $demand = $service->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 1, 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $this->assertNull($demand->maximum_staff);
        $shift = $service->generate($demand->id, 1, 30, $this->manager);
        $extra = $this->shift();
        $extra->forceFill(['order_demand_id' => $demand->id])->save();
        app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager);
        app(ShiftAssignmentService::class)->assign($extra, $this->ben, $this->manager);
        $this->assertSame(2, $service->coverage($demand)['reserved']);
        $this->invalid(fn () => $service->save($this->order->id, $demand->id, 1, ['role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'exact', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager), 'Höchstbedarf');
        $this->assertNull($demand->fresh()->maximum_staff);
    }

    public function test_variant_preview_is_non_mutating_and_checks_proposed_services_against_each_other(): void
    {
        $one = $this->shift();
        $two = $this->shift('2027-05-13');
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$one->id, $two->id], 'Vergleich', 0, $this->manager);
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-13', 'until' => '2027-05-13'];
        foreach ($data['entries'] as &$entry) {
            $entry['user_ids'] = [$this->anna->id];
        }
        unset($entry);
        $variant = $service->save($variant->id, 1, $data, $this->manager);
        $before = Shift::get()->map(fn ($s) => $s->getRawOriginal())->all();
        $preview = $service->preview($variant, $this->manager);
        $this->assertFalse($preview['valid']);
        $this->assertContains('rest_overlap', array_column($preview['rows'][0]['issues'][$this->anna->id], 'code'));
        $this->assertSame($before, Shift::get()->map(fn ($s) => $s->getRawOriginal())->all());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_variant_demand_capacity_checks_final_distribution_not_intermediate_updates(): void
    {
        $demand = app(OrderDemandService::class)->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 3, 'staffing_mode' => 'exact', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $one = $this->shift();
        $two = $this->shift(extra: ['required_staff' => 2]);
        foreach ([$one, $two] as $shift) {
            $shift->forceFill(['order_demand_id' => $demand->id])->save();
        }
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$one->id, $two->id], 'Bedarfsverteilung', 0, $this->manager);
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-13', 'until' => '2027-05-13'];
        $data['entries'][0]['required_staff'] = 2;
        $data['entries'][1]['required_staff'] = 1;
        $variant = $service->save($variant->id, 1, $data, $this->manager);
        $preview = $service->preview($variant, $this->manager);
        $this->assertTrue($preview['valid']);
        $service->approve($variant->id, 2, $preview['fingerprint'], $this->manager);
        $service->apply($variant->id, 3, $this->manager);
        $this->assertSame(2, $one->fresh()->required_staff);
        $this->assertSame(1, $two->fresh()->required_staff);
        $this->assertSame(0, $one->fresh()->published_revision);

        $variant = $service->capture([$one->id, $two->id], 'Überplanung', 0, $this->manager);
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-13', 'until' => '2027-05-13'];
        $data['entries'][1]['required_staff'] = 2;
        $variant = $service->save($variant->id, 1, $data, $this->manager);
        $preview = $service->preview($variant, $this->manager);
        $this->assertFalse($preview['valid']);
        $this->assertStringContainsString('Höchstbedarf', implode(' ', $preview['rows'][0]['schedule_issues']));
    }

    public function test_demand_generation_cannot_overplan_already_covered_partial_intervals(): void
    {
        $service = app(OrderDemandService::class);
        $demand = $service->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'exact', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $partial = $this->shift(extra: ['ends_at' => CarbonImmutable::parse('2027-05-13T12:00:00+02:00')]);
        $partial->forceFill(['order_demand_id' => $demand->id])->save();
        $this->assertSame(1, $service->coverage($demand)['open']);
        $this->invalid(fn () => $service->generate($demand->id, 1, 30, $this->manager), 'Höchstbedarf');
        $this->assertSame(1, Shift::count());
    }

    public function test_personnel_scope_applies_to_lists_details_calendar_and_sickness_correction(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $reviewer = User::factory()->create(['name' => 'Zuständiger Prüfer', 'role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Personal', 'personal_team' => false, 'user_id' => $this->manager->id, 'rbac_permissions' => ['operations.absences.review' => true, 'operations.qualifications.manage' => true]]);
        $reviewer->teams()->attach($team->id);
        $reviewer->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->anna, ['responsible_user_id' => $reviewer->id, 'starts_on' => '2027-01-01', 'abilities' => ['operations.absences.review', 'operations.qualifications.manage']], $this->manager);
        $service = app(PersonnelWorkflowService::class);
        $own = $service->reportSickness($this->anna, ['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin']);
        $foreign = $service->reportSickness($this->ben, ['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin']);
        $view = Livewire::actingAs($reviewer)->test(PersonnelReview::class, ['module' => 'absences'])->set('filter', 'reported')->set('absenceKind', 'sick');
        $view->assertSee('Anna')->assertDontSee('Ben')->assertSee('Gemeldet')->call('openDetails', $own->id)->assertSee('Zeitraum korrigieren')->set('sicknessCorrection.ends_at', '2027-05-13T18:00')->set('sicknessCorrection.note', 'Zeitraum sachlich berichtigt')->call('correctSickness')->assertHasNoErrors();
        $this->assertSame(2, $own->fresh()->revision);
        $this->assertSame('reported', $own->fresh()->status);
        try {
            $view->call('openDetails', $foreign->id);
            $this->fail('Foreign detail must not be visible');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(AbsenceRequest::class, $exception->getModel());
        }
        Livewire::actingAs($reviewer)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-13', 'absencesOnly' => true, 'absenceKind' => 'sick', 'absenceStatus' => 'reported'])->assertSee('Anna')->assertDontSee('Ben')->assertSee('Krankmeldung')->assertDontSee('Zeitraum sachlich berichtigt');
        Livewire::actingAs($this->manager)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-13'])->assertSee('Abwesenheit')->assertDontSee('Krankmeldung');
    }

    public function test_direct_new_module_access_requires_its_schema_without_disabling_existing_modules(): void
    {
        Schema::drop('staffing_cases');
        Livewire::actingAs($this->manager)->test(Workspace::class, ['module' => 'workforce-planning'])->assertStatus(503);
        Livewire::actingAs($this->manager)->test(Workspace::class, ['module' => 'plan-variants'])->assertStatus(503);
        Livewire::actingAs($this->manager)->test(Workspace::class, ['module' => 'orders'])->assertOk();
    }

    public function test_safe_copy_approval_applies_only_drafts_and_stale_baselines_block(): void
    {
        $source = $this->shift();
        $this->confirmed($source, $this->anna);
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$source->id], 'Folgewoche', 7, $this->manager);
        $this->assertSame(1, Shift::count());
        $preview = $service->preview($variant, $this->manager);
        $this->assertTrue($preview['valid']);
        $service->approve($variant->id, 1, $preview['fingerprint'], $this->manager);
        $ids = $service->apply($variant->id, 2, $this->manager);
        $copy = Shift::findOrFail($ids[0]);
        $this->assertSame('draft', $copy->status->value);
        $this->assertSame(0, $copy->published_revision);
        $this->assertSame('requested', $copy->assignments()->first()->status->value);
        $this->assertSame(1, $source->fresh()->published_revision);
        $this->assertSame('confirmed', $source->assignments()->first()->status->value);
        $stale = $service->capture([$source->id], 'Weitere Woche', 14, $this->manager);
        $source->forceFill(['title' => 'Geänderter Stand'])->save();
        $this->invalid(fn () => $service->preview($stale, $this->manager), 'Ausgangsplan');
        $this->invalid(fn () => $service->capture([$source->id], 'Überschreiben', 0, $this->manager), 'nicht überschrieben');
    }

    public function test_failure_case_records_contacts_and_escalation_without_inventing_actual_time(): void
    {
        $shift = $this->shift();
        $service = app(WorkforcePlanningService::class);
        $case = $service->openCase(['shift_id' => $shift->id, 'kind' => 'failure', 'responsible_id' => $this->manager->id, 'due_at' => '2027-05-12T18:00', 'timezone' => 'Europe/Berlin', 'note' => 'Personal fehlt'], $this->manager);
        $service->updateCase($case->id, 1, 'contact', ['note' => 'Telefonisch nicht erreicht', 'user_id' => $this->anna->id, 'channel' => 'phone'], $this->manager);
        $service->updateCase($case->id, 2, 'escalate', ['note' => 'Frist läuft aus'], $this->manager);
        $this->assertSame('escalated', $case->fresh()->status);
        $this->assertCount(1, $case->fresh()->contacts);
        $this->assertNull($case->fresh()->handed_over_at);
        $this->invalid(fn () => $service->updateCase($case->id, 3, 'resolve', ['note' => 'Fall schließen'], $this->manager), 'bestätigte');
        $this->confirmed($shift, $this->anna);
        $service->updateCase($case->id, 3, 'resolve', ['note' => 'Ersatz bestätigt', 'user_id' => $this->anna->id], $this->manager);
        $this->assertSame('resolved', $case->fresh()->status);
    }

    public function test_livewire_uses_standard_lists_modals_numbers_dates_and_owns_personal_data(): void
    {
        $wish = app(WorkforcePlanningService::class)->saveWish(null, null, $this->wish(), $this->anna);
        Livewire::actingAs($this->anna)->test(WorkforcePlanning::class, ['personal' => true])->assertSee('Freiwunsch')->call('edit', 'wish', $wish->id)->assertSeeHtml('data-rt-modal-shell')->call('setTab', 'transfers')->call('edit', 'transfer')->assertSee('Tauschdienst-Nr.');
        Livewire::actingAs($this->ben)->test(WorkforcePlanning::class, ['personal' => true])->assertDontSee('Freiwunsch')->call('setTab', 'pools')->assertStatus(422);
        Livewire::actingAs($this->anna)->test(WorkforcePlanning::class)->assertForbidden();
        Livewire::actingAs($this->manager)->test(WorkforcePlanning::class)->call('setTab', 'pools')->call('edit', 'pool')->assertSee('Stammpool');
        Livewire::actingAs($this->manager)->test(PlanVariants::class)->assertSee('Planvarianten');
        $this->assertTrue(WorkforcePlanningSchema::ready());
        Schema::drop('staffing_cases');
        $this->assertFalse(WorkforcePlanningSchema::ready());
    }

    public function test_planning_action_buttons_cannot_trigger_a_native_form_submission(): void
    {
        foreach (['livewire/operations/workforce-planning.blade.php', 'livewire/operations/plan-variants.blade.php', 'components/tables/rows/operations/workforce-record.blade.php', 'livewire/operations/order-demands.blade.php', 'livewire/admin/operations/shift-management.blade.php'] as $file) {
            $source = file_get_contents(resource_path('views/'.$file));
            preg_match_all('/<x-ui\.buttons\.button-basic\b([^>]+)>/s', $source, $buttons);
            $this->assertNotEmpty($buttons[1], $file);
            foreach ($buttons[1] as $attributes) {
                if (str_contains($attributes, 'wire:click=') || str_contains($attributes, 'x-on:click=')) {
                    $this->assertStringContainsString('type="button"', $attributes, $file);
                }
            }
        }
    }

    public function test_partial_optional_schema_does_not_disable_an_existing_exact_demand_cap(): void
    {
        $service = app(OrderDemandService::class);
        $demand = $service->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'exact', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->manager);
        $first = $service->generate($demand->id, 1, 30, $this->manager);
        $extra = $this->shift();
        $extra->forceFill(['order_demand_id' => $demand->id])->save();
        app(ShiftAssignmentService::class)->assign($first, $this->anna, $this->manager);
        Schema::drop('staffing_cases');
        $this->assertFalse(WorkforcePlanningSchema::ready());
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($extra, $this->ben, $this->manager), 'Höchstbedarf');
        $this->assertSame(0, $extra->assignments()->count());
    }

    public function test_transfer_cannot_collapse_two_reserved_places_into_an_existing_recipient_assignment(): void
    {
        $shift = $this->shift('2027-05-13', ['required_staff' => 2]);
        $first = $this->confirmed($shift, $this->anna);
        $this->confirmed($shift, $this->ben);
        $this->invalid(fn () => app(WorkforcePlanningService::class)->requestTransfer($first->id, 1, $this->ben->id, null, null, '', $this->anna), 'bereits');
        $this->assertSame(2, $shift->assignments()->blocking()->count());
    }

    public function test_variant_saving_cannot_rebase_silently_over_a_changed_real_shift(): void
    {
        $shift = $this->shift();
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$shift->id], 'Entwurf', 0, $this->manager);
        $shift->forceFill(['title' => 'Parallel geändert', 'revision' => 2])->save();
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-13', 'until' => '2027-05-13'];
        $this->invalid(fn () => $service->save($variant->id, 1, $data, $this->manager), 'Ausgangsplan');
        $this->assertSame('Parallel geändert', $shift->fresh()->title);
        $this->assertSame(1, $variant->fresh()->revision);
    }

    public function test_variant_apply_rolls_back_when_eligibility_changes_after_approval(): void
    {
        $shift = $this->shift();
        app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager);
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$shift->id], 'Nächste Woche', 7, $this->manager);
        $preview = $service->preview($variant, $this->manager);
        $service->approve($variant->id, 1, $preview['fingerprint'], $this->manager);
        AbsenceRequest::create(['user_id' => $this->anna->id, 'kind' => 'unavailable', 'status' => 'approved', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-20T00:00', 'ends_at' => '2027-05-21T00:00']);
        $this->invalid(fn () => $service->apply($variant->id, 2, $this->manager), 'Konflikte');
        $this->assertSame(1, Shift::count());
        $this->assertSame('approved', $variant->fresh()->status);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_accounts_week_limits_count_all_proposed_variant_duties_together(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        EmployeeWorkModel::create(['user_id' => $this->anna->id, 'name' => 'Begrenzte Woche', 'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 420, 'maximum_weekly_minutes' => 600, 'daily_minutes' => array_fill(1, 7, 60), 'status' => 'active', 'created_by' => $this->manager->id]);
        $first = $this->shift();
        $second = $this->shift('2027-05-14');
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$first->id, $second->id], 'Wochenprüfung', 0, $this->manager);
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-13', 'until' => '2027-05-14'];
        foreach ($data['entries'] as &$entry) {
            $entry['user_ids'] = [$this->anna->id];
        }
        unset($entry);
        $variant = $service->save($variant->id, 1, $data, $this->manager);
        $preview = $service->preview($variant, $this->manager);
        $this->assertFalse($preview['valid']);
        $this->assertContains('contract_weekly_limit', array_column($preview['rows'][0]['issues'][$this->anna->id], 'code'));
    }

    private function plannedTraining(string $from, string $until, string $title = 'Fortbildung'): PersonnelTraining
    {
        return app(PersonnelProcessService::class)->createTraining(['title' => $title, 'starts_at' => $from, 'ends_at' => $until, 'timezone' => 'Europe/Berlin', 'capacity' => 4], $this->manager);
    }

    public function test_shift_assignment_checks_rest_before_and_after_confirmed_training(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        foreach ([['2027-05-12T22:00', '2027-05-12T23:00'], ['2027-05-13T18:00', '2027-05-13T19:00']] as [$from, $until]) {
            $training = $this->plannedTraining($from, $until);
            PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'status' => 'confirmed', 'created_by' => $this->manager->id]);
        }
        $shift = $this->shift();
        $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$this->anna]))[$this->anna->id];
        $this->assertContains('training_rest_before', array_column($issues, 'code'));
        $this->assertContains('training_rest_after', array_column($issues, 'code'));
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager), 'Ruhezeit');
        $this->assertSame(0, ShiftAssignment::count());
        PersonnelTrainingParticipant::query()->update(['status' => 'cancelled']);
        app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_marking_recent_training_attended_does_not_remove_the_configured_rest_guard(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $training = $this->plannedTraining('2027-05-12T22:00', '2027-05-12T23:00');
        $participant = PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'created_by' => $this->manager->id]);
        $this->travelTo(CarbonImmutable::parse('2027-05-13T07:00', 'Europe/Berlin'));
        app(PersonnelProcessService::class)->participation($participant, 1, 'attend', 'Teilnahme bestätigt', $this->manager);
        $this->assertSame('attended', $participant->fresh()->status);
        $overlapping = new Shift(['title' => 'Rückwirkender Konflikt', 'starts_at' => $training->starts_at->addMinutes(30), 'ends_at' => $training->ends_at->addMinutes(30), 'timezone' => 'Europe/Berlin', 'planned_break_minutes' => 30]);
        $this->assertContains('training_overlap', array_column(app(StaffEligibilityService::class)->assessMany($overlapping, collect([$this->anna]))[$this->anna->id], 'code'));
        $shift = $this->shift();
        $this->assertContains('training_rest_before', array_column(app(StaffEligibilityService::class)->assessMany($shift, collect([$this->anna]))[$this->anna->id], 'code'));
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager), 'Ruhezeit');
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_exact_configured_training_rest_boundary_does_not_invent_additional_time(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        foreach ([['2027-05-12T21:00', '2027-05-12T22:00'], ['2027-05-14T02:00', '2027-05-14T03:00']] as [$from, $until]) {
            $training = $this->plannedTraining($from, $until);
            PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'created_by' => $this->manager->id]);
        }
        $shift = $this->shift();
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($shift, collect([$this->anna]))[$this->anna->id]);
        app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_enrollment_checks_both_directions_and_accepts_exact_rest_after_a_duty(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        app(ShiftAssignmentService::class)->assign($this->shift(), $this->anna, $this->manager);
        $service = app(PersonnelProcessService::class);
        foreach ([['2027-05-13T05:00', '2027-05-13T06:00'], ['2027-05-13T18:00', '2027-05-13T19:00']] as [$from, $until]) {
            $training = $this->plannedTraining($from, $until);
            $this->invalid(fn () => $service->enroll($training, $this->anna, 1, $this->manager), 'Ruhezeit');
        }
        $this->assertSame(0, PersonnelTrainingParticipant::count());
        $exact = $this->plannedTraining('2027-05-14T02:00', '2027-05-14T03:00');
        $participant = $service->enroll($exact, $this->anna, 1, $this->manager);
        $this->assertSame('confirmed', $participant->fresh()->status);
    }

    public function test_training_rest_across_dst_uses_elapsed_time_not_wall_clock_hours(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-03-27T07:00', 'Europe/Berlin'));
        $training = $this->plannedTraining('2027-03-28T00:00', '2027-03-28T01:00');
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'created_by' => $this->manager->id]);
        $short = $this->shift('2027-03-28', ['starts_at' => CarbonImmutable::parse('2027-03-28T11:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2027-03-28T19:00', 'Europe/Berlin')]);
        $this->assertSame(9.0, $training->ends_at->diffInHours($short->starts_at));
        $this->assertContains('training_rest_before', array_column(app(StaffEligibilityService::class)->assessMany($short, collect([$this->anna]))[$this->anna->id], 'code'));
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($short, $this->anna, $this->manager), 'Ruhezeit');

        $exact = $this->shift('2027-03-28', ['starts_at' => CarbonImmutable::parse('2027-03-28T12:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2027-03-28T20:00', 'Europe/Berlin')]);
        $this->assertSame(10.0, $training->ends_at->diffInHours($exact->starts_at));
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($exact, collect([$this->anna]))[$this->anna->id]);
        app(ShiftAssignmentService::class)->assign($exact, $this->anna, $this->manager);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_training_rest_uses_effective_profiles_of_both_events_not_only_the_shift_date(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        OperationsRuleProfile::first()->update(['minimum_rest_minutes' => 120]);
        $earlier = OperationsRuleProfile::create(['name' => 'Früheres datiertes Profil', 'minimum_rest_minutes' => 720, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => false, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        EmployeeRuleAssignment::create(['user_id' => $this->anna->id, 'operations_rule_profile_id' => $earlier->id, 'starts_on' => '2027-05-12', 'ends_on' => '2027-05-12', 'created_by' => $this->manager->id]);
        $training = $this->plannedTraining('2027-05-12T21:00', '2027-05-12T22:00');
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'created_by' => $this->manager->id]);
        $issues = app(StaffEligibilityService::class)->assessMany($this->shift(), collect([$this->anna]))[$this->anna->id];
        $this->assertContains('training_rest_before', array_column($issues, 'code'));
        $this->assertStringContainsString('720 min', implode(' ', array_column($issues, 'message')));
        $later = $this->shift(extra: ['starts_at' => CarbonImmutable::parse('2027-05-13T10:00', 'Europe/Berlin')]);
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($later, collect([$this->anna]))[$this->anna->id]);
    }

    public function test_missing_training_rules_are_review_required_and_never_defaulted(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $shift = $this->shift();
        $training = $this->plannedTraining('2027-05-14T08:00', '2027-05-14T09:00');
        OperationsRuleProfile::query()->update(['is_active' => false]);
        $issues = app(StaffEligibilityService::class)->trainingRestIssues($training, $this->anna);
        $this->assertSame('rules_missing', $issues[0]['code']);
        $this->assertContains('rules_missing', array_column(app(StaffEligibilityService::class)->assessMany($shift, collect([$this->anna]))[$this->anna->id], 'code'));
        $this->invalid(fn () => app(PersonnelProcessService::class)->enroll($training, $this->anna, 1, $this->manager), 'Regelprofil');
        $this->assertSame(0, PersonnelTrainingParticipant::count());
    }

    public function test_training_rest_guard_is_reused_by_variant_preview_and_enrollment_scope_revision_checks(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $training = $this->plannedTraining('2027-05-13T18:00', '2027-05-13T19:00');
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'created_by' => $this->manager->id]);
        $service = app(PlanVariantService::class);
        $variant = $service->capture([$this->shift()->id], 'Schulungsruhe', 0, $this->manager);
        $data = $variant->only(['name', 'timezone', 'entries']) + ['from' => '2027-05-13', 'until' => '2027-05-13'];
        $data['entries'][0]['user_ids'] = [$this->anna->id];
        $variant = $service->save($variant->id, 1, $data, $this->manager);
        $preview = $service->preview($variant, $this->manager);
        $this->assertFalse($preview['valid']);
        $this->assertContains('training_rest_after', array_column($preview['rows'][0]['issues'][$this->anna->id], 'code'));
        $reviewer = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Verwaltung', 'personal_team' => false, 'user_id' => $this->manager->id, 'rbac_permissions' => ['operations.qualifications.manage' => true]]);
        $reviewer->teams()->attach($team->id);
        $reviewer->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->anna, ['responsible_user_id' => $reviewer->id, 'starts_on' => '2027-01-01', 'abilities' => ['operations.qualifications.manage']], $this->manager);
        try {
            app(PersonnelProcessService::class)->enroll($training, $this->ben, 1, $reviewer);
            $this->fail('Foreign enrollment target must stay denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $training->forceFill(['revision' => 2])->save();
        $this->invalid(fn () => app(PersonnelProcessService::class)->enroll($training, $this->ben, 1, $this->manager), 'Schulung wurde geändert');
        User::whereKey($this->ben->id)->update(['status' => false]);
        $this->assertTrue($this->ben->status); // The component's earlier employee instance is intentionally stale.
        $this->invalid(fn () => app(PersonnelProcessService::class)->enroll($training, $this->ben, 2, $this->manager), 'Mitarbeiter ist nicht aktiv');
        $this->assertSame(1, PersonnelTrainingParticipant::count());
    }

    public function test_confirmed_training_is_a_hard_assignment_and_variant_conflict(): void
    {
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $training = PersonnelTraining::create(['title' => 'Fortbildung', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T10:00', 'ends_at' => '2027-05-13T12:00', 'capacity' => 3, 'created_by' => $this->manager->id]);
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->anna->id, 'created_by' => $this->manager->id]);
        $shift = $this->shift();
        $issues = app(StaffEligibilityService::class)->assessMany($shift, collect([$this->anna]))[$this->anna->id];
        $this->assertContains('training_overlap', array_column($issues, 'code'));
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager), 'Schulung');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_configured_transfer_buffer_checks_both_previous_and_following_duties(): void
    {
        OperationsRuleProfile::first()->update(['minimum_rest_minutes' => 0]);
        $morning = $this->shift('2027-05-13', ['ends_at' => CarbonImmutable::parse('2027-05-13T09:00:00+02:00'), 'planned_break_minutes' => 0]);
        app(ShiftAssignmentService::class)->assign($morning, $this->anna, $this->manager);
        $next = $this->shift('2027-05-13', ['starts_at' => CarbonImmutable::parse('2027-05-13T09:15:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-05-13T10:00:00+02:00'), 'planned_break_minutes' => 0, 'location_name' => 'Hamburg', 'disposition_details' => ['transfer_buffer_minutes' => 60]]);
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($next, $this->anna, $this->manager), 'puffer');
        app(ShiftAssignmentService::class)->cancel($morning->assignments()->first(), $this->manager);
        app(ShiftAssignmentService::class)->assign($next, $this->anna, $this->manager);
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($morning, $this->anna, $this->manager), 'puffer');
    }

    public function test_management_selection_keeps_expected_revision_for_later_assignment(): void
    {
        $shift = $this->shift();
        $component = Livewire::actingAs($this->manager)->test(ShiftManagement::class)->call('openDetails', $shift->id)->assertSet('selectedPlanRevision', 1);
        $shift->forceFill(['revision' => 2])->save();
        $component->set('employeeId', $this->anna->id)->call('assignEmployee')->assertHasErrors('assignment');
        $this->assertSame(0, ShiftAssignment::count());
    }
}
