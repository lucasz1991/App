<?php

namespace Tests\Feature;

use App\Enums\ShiftAssignmentStatus;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\EmployeeQualification;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\OrderDemand;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkforcePool;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\TimelinePlanningSuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelinePlanningSuggestionServiceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $manager;

    private User $anna;

    private User $ben;

    private Order $order;

    private TimelinePlanningSuggestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->manager = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->anna = User::factory()->create(['name' => 'Anna Alpha', 'role' => 'staff', 'status' => true]);
        $this->ben = User::factory()->create(['name' => 'Ben Beta', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Freigegeben', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->manager->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Jahresleistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00', 'ends_at' => '2028-01-01T00:00', 'required_staff' => 3, 'created_by' => $this->manager->id]);
        $this->service = app(TimelinePlanningSuggestionService::class);
    }

    private function shift(array $extra = []): Shift
    {
        return Shift::create(array_merge(['order_id' => $this->order->id, 'title' => 'Offener Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'location_name' => 'Bremen', 'created_by' => $this->manager->id], $extra));
    }

    private function assignment(Shift $shift, User $user, string $status = 'confirmed'): ShiftAssignment
    {
        return ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $user->id, 'status' => $status, 'assigned_by' => $this->manager->id]);
    }

    private function workModel(User $user, int $target, array $extra = []): EmployeeWorkModel
    {
        return EmployeeWorkModel::create(array_merge(['user_id' => $user->id, 'name' => 'Gepflegtes Modell', 'starts_on' => '2027-01-01', 'ends_on' => null, 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => $target, 'daily_minutes' => array_fill(1, 7, max(1, intdiv($target, 7))), 'status' => 'active', 'created_by' => $this->manager->id, 'approved_by' => $this->manager->id, 'approved_at' => now()->utc()], $extra));
    }

    private function wish(User $user, string $kind): EmployeeAvailability
    {
        return EmployeeAvailability::create(['user_id' => $user->id, 'kind' => $kind, 'from' => '2027-05-13', 'until' => '2027-05-13', 'weekdays' => [4], 'whole_day' => true, 'timezone' => 'Europe/Berlin', 'revision' => 1]);
    }

    private function invalid(callable $action, string $message): void
    {
        try {
            $action();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, collect($exception->errors())->flatten()->implode(' '));
        }
    }

    public function test_open_query_counts_only_future_unreserved_active_places_and_keeps_draft_distinct(): void
    {
        $open = $this->shift(['required_staff' => 3]);
        $this->assignment($open, $this->anna, 'requested');
        $this->assignment($open, $this->ben, 'declined');
        $full = $this->shift(['title' => 'Reserviert']);
        $this->assignment($full, $this->ben, 'confirmed');
        $this->shift(['status' => 'cancelled']);
        $this->shift(['status' => 'completed']);
        $this->shift(['starts_at' => '2027-05-12T06:00', 'ends_at' => '2027-05-12T14:00']);
        $this->shift(['starts_at' => '2027-05-20T08:00', 'ends_at' => '2027-05-20T16:00']);
        $deleted = $this->shift();
        $deleted->delete();
        foreach (['completed', 'invoiced', 'cancelled'] as $status) {
            $order = $this->order->replicate(['public_id', 'order_number']);
            $order->status = $status;
            $order->save();
            $this->shift(['order_id' => $order->id]);
        }
        $shifts = $this->service->openShifts('2027-05-12', '2027-05-14', $this->manager)->get();
        $this->assertSame([$open->id], $shifts->modelKeys());
        $this->assertSame(1, $shifts->first()->reserved_count);
        $this->assertSame('draft', $shifts->first()->status->value);
    }

    public function test_cell_choices_use_local_half_open_days_include_nights_and_return_concrete_conflicts(): void
    {
        $night = $this->shift(['starts_at' => '2027-05-12T22:00', 'ends_at' => '2027-05-13T06:00']);
        $this->shift(['starts_at' => '2027-05-12T16:00', 'ends_at' => '2027-05-13T00:00']);
        $this->shift(['starts_at' => '2027-05-14T00:00', 'ends_at' => '2027-05-14T08:00']);
        AbsenceRequest::create(['user_id' => $this->anna->id, 'kind' => 'vacation', 'status' => 'approved', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T01:00', 'ends_at' => '2027-05-13T03:00']);
        $choices = $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-12', '2027-05-14', $this->manager);
        $this->assertCount(1, $choices);
        $this->assertSame($night->id, $choices->first()['shift']->id);
        $this->assertFalse($choices->first()['eligible']);
        $this->assertContains('absence', array_column($choices->first()['issues'], 'code'));
        $this->assertSame($night->fresh()->revision, $choices->first()['revision']);
    }

    public function test_future_in_progress_shift_is_not_suggested_even_with_inconsistent_start_time(): void
    {
        $shift = $this->shift(['status' => 'in_progress']);
        $this->assertSame(0, $this->service->openShifts('2027-05-13', '2027-05-13', $this->manager)->count());
        $this->assertCount(0, $this->service->rankedCandidates($shift, $this->manager));
        $this->assertCount(0, $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager));
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame(0, $preview['open_total']);
        $this->assertCount(0, $preview['proposals']);
    }

    public function test_rank_uses_central_qualification_guard_and_never_changes_the_database(): void
    {
        $shift = $this->shift();
        $type = QualificationType::create(['name' => 'Tf', 'is_active' => true]);
        $shift->qualifications()->attach($type->id);
        EmployeeQualification::create(['user_id' => $this->ben->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31', 'status' => 'approved']);
        $before = [ShiftAssignment::count(), DB::table('operation_audits')->count(), $shift->fresh()->getAttributes()];
        $preview = $this->service->preview('2027-05-12', '2027-05-14', $this->manager);
        $this->assertCount(1, $preview['proposals']);
        $this->assertSame($this->ben->id, $preview['proposals']->first()['user']->id);
        $this->assertContains('Keine Planungskonflikte', $preview['proposals']->first()['reasons']);
        $this->assertSame($before, [ShiftAssignment::count(), DB::table('operation_audits')->count(), $shift->fresh()->getAttributes()]);
        $choices = $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-12', '2027-05-14', $this->manager);
        $this->assertContains('qualification_'.$type->id, array_column($choices->first()['issues'], 'code'));
    }

    public function test_wishes_are_soft_and_rank_does_not_replace_the_global_best_by_a_visible_row(): void
    {
        $shift = $this->shift();
        $this->wish($this->ben, 'preferred');
        $ranked = $this->service->rankedCandidates($shift, $this->manager);
        $this->assertSame([$this->ben->id, $this->anna->id], $ranked->pluck('user.id')->all());
        $this->assertSame('preferred', $ranked->first()['wish']);
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame($this->ben->id, $preview['proposals']->first()['user']->id);
        EmployeeAvailability::query()->delete();
        $this->wish($this->anna, 'unavailable');
        $ranked = $this->service->rankedCandidates($shift, $this->manager);
        $this->assertCount(2, $ranked);
        $this->assertSame('free_requested', $ranked->last()['wish']);
        $this->assertSame([], $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager)->first()['issues']);
    }

    public function test_planned_load_uses_elapsed_time_training_and_real_targets_without_profile_text_fallback(): void
    {
        $target = $this->shift();
        $existingAnna = $this->shift(['starts_at' => '2027-05-11T08:00', 'ends_at' => '2027-05-11T12:00', 'planned_break_minutes' => 0]);
        $existingBen = $this->shift(['starts_at' => '2027-05-11T08:00', 'ends_at' => '2027-05-11T11:00', 'planned_break_minutes' => 0]);
        $this->assignment($existingAnna, $this->anna);
        $this->assignment($existingBen, $this->ben);
        $this->workModel($this->anna, 2400);
        $this->workModel($this->ben, 1200);
        $ranked = $this->service->rankedCandidates($target, $this->manager);
        $this->assertSame($this->anna->id, $ranked->first()['user']->id); // 10% vs 15%, not raw 4 vs 3 hours.
        $this->assertSame(240.0, $ranked->first()['planned_minutes']);
        $this->assertSame(2400, $ranked->first()['target_minutes']);
        EmployeeWorkModel::where('user_id', $this->ben->id)->update(['ends_on' => '2027-05-01']);
        $this->assertSame([$this->anna->id], $this->service->rankedCandidates($target, $this->manager)->pluck('user.id')->all());
        EmployeeWorkModel::where('user_id', $this->ben->id)->update(['status' => 'draft']);
        $this->ben->profile()->create(['weekly_working_hours' => '999 h / imported prose']);
        $ranked = $this->service->rankedCandidates($target, $this->manager);
        $this->assertSame($this->ben->id, $ranked->first()['user']->id);
        $this->assertNull($ranked->first()['target_minutes']);
        $training = PersonnelTraining::create(['title' => 'Qualifizierung', 'starts_at' => '2027-05-12T10:00', 'ends_at' => '2027-05-12T12:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1, 'created_by' => $this->manager->id]);
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->ben->id, 'status' => 'confirmed', 'created_by' => $this->manager->id]);
        $ranked = $this->service->rankedCandidates($target, $this->manager);
        $this->assertSame($this->anna->id, $ranked->first()['user']->id);
        $this->assertSame(300.0, $ranked->last()['planned_minutes']);
    }

    public function test_existing_assignment_and_training_are_hard_conflicts_even_with_a_positive_wish(): void
    {
        $target = $this->shift(['required_staff' => 2]);
        $this->assignment($target, $this->anna, 'requested');
        $this->wish($this->ben, 'preferred');
        $training = PersonnelTraining::create(['title' => 'Lehrgang', 'starts_at' => '2027-05-13T10:00', 'ends_at' => '2027-05-13T12:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1, 'created_by' => $this->manager->id]);
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->ben->id, 'status' => 'confirmed', 'created_by' => $this->manager->id]);
        $this->assertCount(0, $this->service->rankedCandidates($target, $this->manager));
        $choices = $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager);
        $this->assertContains('already_assigned', array_column($choices->first()['issues'], 'code'));
        $benChoices = $this->service->cellChoices($this->ben->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager);
        $this->assertFalse($benChoices->first()['eligible']);
    }

    public function test_preview_prospective_shifts_do_not_double_book_the_best_person(): void
    {
        $first = $this->shift();
        $second = $this->shift(['title' => 'Parallel', 'starts_at' => '2027-05-13T09:00', 'ends_at' => '2027-05-13T17:00']);
        $this->wish($this->anna, 'preferred');
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertCount(2, $preview['proposals']);
        $this->assertSame([$first->id, $second->id], $preview['proposals']->pluck('shift.id')->all());
        $this->assertSame([$this->anna->id, $this->ben->id], $preview['proposals']->pluck('user.id')->all());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_preview_uses_current_rest_and_weekly_capacity_with_virtual_assignments(): void
    {
        OperationsRuleProfile::query()->update(['minimum_rest_minutes' => 600]);
        $first = $this->shift();
        $this->shift(['title' => 'Zu kurze Ablöse', 'starts_at' => '2027-05-13T17:00', 'ends_at' => '2027-05-13T22:00', 'planned_break_minutes' => 0]);
        $this->wish($this->anna, 'preferred');
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame([$this->anna->id, $this->ben->id], $preview['proposals']->pluck('user.id')->all());
        OperationsRuleProfile::query()->update(['minimum_rest_minutes' => 0]);
        $this->workModel($this->anna, 450, ['maximum_weekly_minutes' => 450]);
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame($first->id, $preview['proposals']->first()['shift']->id);
        $this->assertSame([$this->anna->id, $this->ben->id], $preview['proposals']->pluck('user.id')->all());
    }

    public function test_pool_and_exact_demand_capacity_are_not_bypassed_by_suggestions(): void
    {
        $pool = WorkforcePool::create(['name' => 'Bereitschaft', 'kind' => 'reserve', 'is_active' => true, 'created_by' => $this->manager->id]);
        $pool->users()->attach($this->anna->id);
        $demand = OrderDemand::create(['order_id' => $this->order->id, 'role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'exact', 'maximum_staff' => 1, 'workforce_pool_id' => $pool->id, 'qualification_ids' => [], 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'status' => 'active', 'created_by' => $this->manager->id]);
        $first = $this->shift();
        $first->forceFill(['order_demand_id' => $demand->id])->save();
        $second = $this->shift(['title' => 'Historischer zusätzlicher Dienst']);
        $second->forceFill(['order_demand_id' => $demand->id])->save();
        $this->assertSame([$this->anna->id], $this->service->rankedCandidates($second, $this->manager)->pluck('user.id')->all());
        $this->assignment($first, $this->ben);
        $this->assertCount(0, $this->service->rankedCandidates($second, $this->manager));
        $choice = $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager)->first();
        $this->assertContains('demand_capacity', array_column($choice['issues'], 'code'));
    }

    public function test_preview_limit_is_explicit_and_cell_choices_are_bounded_chronologically(): void
    {
        foreach (range(1, 31) as $index) {
            $this->shift(['title' => 'Offen '.$index, 'required_staff' => 8]);
        }
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame(31, $preview['open_total']);
        $this->assertTrue($preview['limited']);
        $this->assertLessThanOrEqual(60, $preview['proposals']->count());
        $choices = $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager);
        $this->assertCount(30, $choices);
        $this->assertSame('Offen 1', $choices->first()['shift']->title);
    }

    public function test_virtual_reservations_share_exact_demand_capacity_across_historical_parallel_shifts(): void
    {
        $demand = OrderDemand::create(['order_id' => $this->order->id, 'role_name' => 'Tf', 'required_staff' => 1, 'staffing_mode' => 'exact', 'maximum_staff' => 1, 'qualification_ids' => [], 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin', 'status' => 'active', 'created_by' => $this->manager->id]);
        foreach (range(1, 3) as $index) {
            $shift = $this->shift(['title' => 'Alte Parallelplanung '.$index, 'required_staff' => 2]);
            $shift->forceFill(['order_demand_id' => $demand->id])->save();
        }
        $before = [ShiftAssignment::count(), DB::table('operation_audits')->count()];
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertSame(3, $preview['open_total']);
        $this->assertCount(1, $preview['proposals']);
        $this->assertSame($before, [ShiftAssignment::count(), DB::table('operation_audits')->count()]);
    }

    public function test_five_place_preview_limit_never_creates_more_ghosts_than_open_capacity(): void
    {
        foreach (range(1, 6) as $index) {
            User::factory()->create(['name' => 'Reserve '.$index, 'role' => 'staff', 'status' => true]);
        }
        $this->shift(['required_staff' => 8]);
        $preview = $this->service->preview('2027-05-13', '2027-05-13', $this->manager);
        $this->assertCount(5, $preview['proposals']);
        $this->assertTrue($preview['limited']);
        $this->assertCount(5, $preview['proposals']->pluck('user.id')->unique());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_missing_optional_planning_table_does_not_disable_core_suggestions_or_weaken_hard_guards(): void
    {
        $shift = $this->shift();
        $this->wish($this->ben, 'preferred');
        Schema::drop('shift_offer_responses');
        $ranked = $this->service->rankedCandidates($shift, $this->manager);
        $this->assertSame([$this->anna->id, $this->ben->id], $ranked->pluck('user.id')->all());
        $this->assertSame('unknown', $ranked->first()['wish']);
        OperationsRuleProfile::query()->update(['is_active' => false]);
        $this->assertCount(0, $this->service->rankedCandidates($shift, $this->manager));
        $choice = $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager)->first();
        $this->assertFalse($choice['eligible']);
        $this->assertContains('rules_missing', array_column($choice['issues'], 'code'));
    }

    public function test_day_choices_and_planned_load_keep_true_dst_elapsed_duration(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-10-28T00:00:00+02:00'));
        $night = $this->shift(['starts_at' => CarbonImmutable::parse('2027-10-30T22:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-10-31T06:00:00+01:00')]);
        $ranked = $this->service->rankedCandidates($night, $this->manager);
        $this->assertCount(2, $ranked);
        $choices = $this->service->cellChoices($this->anna->id, '2027-10-31', '2027-10-30', '2027-10-31', $this->manager);
        $this->assertCount(1, $choices);
        $this->assertEquals(540, $choices->first()['shift']->starts_at->diffInMinutes($choices->first()['shift']->ends_at));
        $target = $this->shift(['starts_at' => '2027-10-31T18:00', 'ends_at' => '2027-10-31T20:00', 'planned_break_minutes' => 0]);
        $this->assignment($night, $this->anna);
        $ranked = $this->service->rankedCandidates($target, $this->manager);
        $this->assertSame($this->ben->id, $ranked->first()['user']->id);
        $this->assertSame(510.0, $ranked->last()['planned_minutes']);
    }

    public function test_confirmation_still_rechecks_revision_capacity_and_new_absence(): void
    {
        $shift = $this->shift();
        $suggestion = $this->service->preview('2027-05-13', '2027-05-13', $this->manager)['proposals']->first();
        $shift->forceFill(['revision' => 2])->save();
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $suggestion['user'], $this->manager, ShiftAssignmentStatus::Requested, null, $suggestion['revision']), 'Schicht wurde geändert');
        $this->assignment($shift, $this->ben, 'requested');
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager, ShiftAssignmentStatus::Requested, null, 2), 'reserviert');
        ShiftAssignment::query()->delete();
        AbsenceRequest::create(['user_id' => $this->anna->id, 'kind' => 'sick', 'status' => 'reported', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-14T00:00']);
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->anna, $this->manager, ShiftAssignmentStatus::Requested, null, 2), 'Abwesenheit');
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_invalid_range_outside_cell_and_inactive_person_are_rejected(): void
    {
        foreach ([['2027-05-12', '2027-05-11'], ['2027-02-30', '2027-03-01']] as [$from, $until]) {
            try {
                $this->service->openShifts($from, $until, $this->manager);
                $this->fail('Expected invalid range.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        foreach ([fn () => $this->service->openShifts('2027-01-01', '2027-05-01', $this->manager), fn () => $this->service->cellChoices($this->anna->id, '2027-05-14', '2027-05-12', '2027-05-13', $this->manager)] as $action) {
            try {
                $action();
                $this->fail('Expected invalid range.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
        $this->anna->update(['status' => false]);
        $this->expectException(ModelNotFoundException::class);
        $this->service->cellChoices($this->anna->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->manager);
    }

    public function test_read_actions_require_current_operations_manage_permission(): void
    {
        $this->shift();
        foreach ([fn () => $this->service->preview('2027-05-13', '2027-05-13', $this->anna), fn () => $this->service->cellChoices($this->ben->id, '2027-05-13', '2027-05-13', '2027-05-13', $this->anna)] as $action) {
            try {
                $action();
                $this->fail('Expected denied preview.');
            } catch (AuthorizationException $exception) {
                $this->assertSame(403, $exception->status() ?? 403);
            }
        }
        User::whereKey($this->manager->id)->update(['status' => false]);
        $this->expectException(HttpException::class);
        $this->service->preview('2027-05-13', '2027-05-13', $this->manager); // Stale actor cannot retain read access.
    }
}
