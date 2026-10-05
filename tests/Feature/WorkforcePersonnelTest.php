<?php

namespace Tests\Feature;

use App\Livewire\Operations\PersonnelProcesses;
use App\Livewire\Operations\WorkforceAccounts;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\PersonnelPlanReview;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\VacationReservation;
use App\Models\WorkforceAccountEntry;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkforcePlanReviewService;
use App\Support\Operations\OperationsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkforcePersonnelTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $author;

    private User $reviewer;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-10 07:00:00', 'UTC'));
        $this->author = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->reviewer = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Explicit fixture', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 1440, 'break_after_minutes' => 1440, 'minimum_break_minutes' => 0, 'is_active' => true, 'created_by' => $this->author->id, 'approved_at' => now()]);
    }

    private function model(array $overrides = []): EmployeeWorkModel
    {
        $service = app(WorkforceAccountService::class);
        $model = $service->createModel($this->employee, $overrides + ['name' => 'Explicit fixture', 'starts_on' => '2027-01-01', 'ends_on' => null, 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'maximum_weekly_minutes' => 3000, 'daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0]], $this->author);
        $service->activate($model, 1, $this->reviewer);

        return $model->fresh();
    }

    private function policy(array $overrides = [])
    {
        $service = app(WorkforceAccountService::class);
        $policy = $service->createPolicy($this->employee, $overrides + ['name' => 'Explicit fixture', 'starts_on' => '2027-01-01', 'ends_on' => null, 'unit' => 'days', 'non_working_dates' => [], 'calendar_version' => 'approved fixture v1'], $this->author);
        $service->activate($policy, 1, $this->reviewer);

        return $policy->fresh();
    }

    private function credit(array $overrides = [])
    {
        return app(WorkforceAccountService::class)->creditVacation($this->employee, $overrides + ['effective_on' => '2027-01-01', 'expires_on' => null, 'entitlement_year' => 2027, 'amount' => 10, 'kind' => 'grant', 'note' => 'Explicit opening credit'], $this->author);
    }

    private function request(string $start = '2027-05-12T00:00', string $end = '2027-05-13T00:00', array $extra = [])
    {
        return app(PersonnelWorkflowService::class)->requestAbsence($this->employee, $extra + ['kind' => 'vacation', 'starts_at' => $start, 'ends_at' => $end, 'timezone' => 'Europe/Berlin']);
    }

    private function shift(string $start = '2027-05-12T08:00', string $end = '2027-05-12T16:00'): Shift
    {
        $customer = Customer::create(['company_name' => 'Fixture Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Fixture', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => CarbonImmutable::parse($start, 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse($end, 'Europe/Berlin'), 'required_staff' => 1, 'created_by' => $this->author->id, 'updated_by' => $this->author->id]);

        return Shift::create(['order_id' => $order->id, 'title' => 'Fixture shift', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => CarbonImmutable::parse($start, 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse($end, 'Europe/Berlin'), 'required_staff' => 1, 'planned_break_minutes' => 0, 'status' => 'open', 'created_by' => $this->author->id, 'updated_by' => $this->author->id]);
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected business validation failure.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    public function test_missing_contract_is_unknown_not_a_zero_target_and_legacy_absence_still_works(): void
    {
        $summary = app(WorkforceAccountService::class)->summary($this->employee, '2027-05-10', '2027-05-10', $this->author);
        $this->assertFalse($summary['configured']);
        $this->assertNull($summary['target_minutes']);
        $this->assertNull($summary['balance_minutes']);
        $this->assertSame(['2027-05-10'], $summary['missing_dates']);
        $request = $this->request();
        app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame([], app(WorkforceAccountService::class)->planningIssues($this->shift(), $this->employee));
    }

    public function test_model_needs_separate_approver_and_rejects_overlaps_or_invented_distribution(): void
    {
        $service = app(WorkforceAccountService::class);
        $model = $service->createModel($this->employee, ['name' => 'Draft', 'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 60, 'daily_minutes' => [1 => 60, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0]], $this->author);
        try {
            $service->activate($model, 1, $this->author);
            $this->fail('Self-approval must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $service->activate($model, 1, $this->reviewer);
        $next = $service->createModel($this->employee, ['name' => 'Overlap', 'starts_on' => '2027-05-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 60, 'daily_minutes' => [1 => 60, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0]], $this->author);
        $this->invalid(fn () => $service->activate($next, 1, $this->reviewer));
        $this->invalid(fn () => $this->model(['weekly_target_minutes' => 2000]));
    }

    public function test_vacation_counts_only_workdays_and_configured_calendar_and_half_day(): void
    {
        $this->model(['work_windows' => [4 => [['start' => '08:00', 'end' => '16:00']]]]);
        $this->policy(['non_working_dates' => ['2027-05-17']]);
        $this->credit();
        $request = $this->request('2027-05-14T00:00', '2027-05-19T00:00');
        $quote = app(WorkforceAccountService::class)->absenceQuote($request);
        $this->assertSame(200, $quote['quantity']);
        $this->assertSame(['2027-05-14', '2027-05-18'], array_column($quote['days'], 'date'));
        $half = $this->request('2027-05-20T08:00', '2027-05-20T12:00', ['vacation_fraction' => 0.5]);
        $this->assertSame(50, app(WorkforceAccountService::class)->absenceQuote($half)['quantity']);
        $this->assertSame(250, (int) VacationReservation::sum('quantity'));
    }

    public function test_insufficient_vacation_rolls_back_request_and_recheck_reservations(): void
    {
        $this->model();
        $this->policy();
        $this->credit(['amount' => 1]);
        $this->invalid(fn () => $this->request('2027-05-12T00:00', '2027-05-14T00:00'));
        $this->assertSame(0, AbsenceRequest::count());
        $request = $this->request();
        $this->invalid(fn () => $this->request('2027-05-14T00:00', '2027-05-15T00:00'));
        app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer);
        $this->assertSame('approved', VacationReservation::first()->status);
        $this->invalid(fn () => app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer));
    }

    public function test_cancellation_does_not_restore_expired_carry_and_preserves_original_credit(): void
    {
        $this->model();
        $this->policy();
        $credit = $this->credit(['kind' => 'carry', 'entitlement_year' => 2026, 'expires_on' => '2027-05-13', 'amount' => 1]);
        $request = $this->request();
        app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer);
        app(PersonnelWorkflowService::class)->absence($request->fresh(), 2, 'cancel', 'Cancelled future leave', $this->reviewer);
        $balance = app(WorkforceAccountService::class)->vacationBalance($this->employee, '2027-05-14');
        $this->assertSame(0, $balance['available']);
        $this->assertSame(100, $balance['expired']);
        $this->assertSame(100, $credit->fresh()->quantity);
        $this->assertSame('released', VacationReservation::first()->status);
    }

    public function test_absence_approval_keeps_capacity_guard_even_with_sufficient_balance(): void
    {
        $this->model();
        $this->policy();
        $this->credit();
        $request = $this->request();
        $shift = $this->shift();
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $this->invalid(fn () => app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer));
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('reserved', VacationReservation::first()->status);
    }

    public function test_minute_unit_uses_actual_daily_target_not_calendar_duration(): void
    {
        $this->model(['weekly_target_minutes' => 1200, 'daily_minutes' => [1 => 240, 2 => 240, 3 => 240, 4 => 240, 5 => 240, 6 => 0, 7 => 0]]);
        $this->policy(['unit' => 'minutes']);
        $this->credit(['amount' => 600]);
        $request = $this->request();
        $this->assertSame(240, app(WorkforceAccountService::class)->absenceQuote($request)['quantity']);
        $this->invalid(fn () => $this->request('2027-05-14T08:00', '2027-05-14T10:00'));
    }

    public function test_sickness_is_immediate_and_does_not_require_replacement_or_consume_vacation(): void
    {
        $shift = $this->shift();
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $request = app(PersonnelWorkflowService::class)->reportSickness($this->employee, ['starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin', 'note' => 'ignored medical detail']);
        $this->assertSame('reported', $request->status);
        $this->assertSame('sick', $request->kind);
        $this->assertNull($request->note);
        $this->assertSame(0, VacationReservation::count());
        $this->assertDatabaseHas('operation_audits', ['action' => 'sickness.reported']);
    }

    public function test_prospective_week_limit_exclusions_contract_gaps_and_work_windows(): void
    {
        $model = $this->model(['weekly_target_minutes' => 600, 'maximum_weekly_minutes' => 600, 'daily_minutes' => [1 => 120, 2 => 120, 3 => 120, 4 => 120, 5 => 120, 6 => 0, 7 => 0], 'work_windows' => [3 => [['start' => '08:00', 'end' => '16:00']]]]);
        $service = app(WorkforceAccountService::class);
        $first = $this->shift();
        $second = $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $this->assertSame([], $service->planningIssues($first, $this->employee));
        $this->assertContains('contract_weekly_limit', array_column($service->planningIssues($first, $this->employee, ['additional_shifts' => [$second]]), 'code'));
        ShiftAssignment::create(['shift_id' => $second->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $this->assertSame([], $service->planningIssues($first, $this->employee, ['exclude_shift_ids' => [$second->id]]));
        $outside = $this->shift('2027-05-12T07:00', '2027-05-12T08:00');
        $this->assertContains('contract_window', array_column($service->planningIssues($outside, $this->employee), 'code'));
        $service->endVersion($model, 2, '2027-05-11', 'Explicit contract ending', $this->author);
        $this->assertContains('contract_missing', array_column($service->planningIssues($first, $this->employee), 'code'));
    }

    public function test_dated_rule_assignment_and_global_legacy_fallback(): void
    {
        $service = app(WorkforceAccountService::class);
        $legacy = OperationsRuleProfile::first();
        $specific = OperationsRuleProfile::create(['name' => 'Individual fixture', 'minimum_rest_minutes' => 30, 'maximum_shift_minutes' => 600, 'break_after_minutes' => 400, 'minimum_break_minutes' => 20, 'is_active' => false, 'created_by' => $this->author->id, 'approved_at' => now()]);
        $service->assignRules($this->employee, ['operations_rule_profile_id' => $specific->id, 'starts_on' => '2027-05-12', 'ends_on' => '2027-05-13'], $this->author);
        $this->assertSame($legacy->id, $service->effectiveRules($this->employee, '2027-05-11')->id);
        $this->assertSame($specific->id, $service->effectiveRules($this->employee, '2027-05-12')->id);
        $this->assertSame($legacy->id, $service->effectiveRules($this->employee, '2027-05-14')->id);
    }

    public function test_summary_reads_approved_actual_once_and_adjustments_are_audited(): void
    {
        $this->model();
        $shift = $this->shift();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $this->employee->id, 'status' => 'approved', 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'timezone' => 'Europe/Berlin', 'pause_seconds' => 1800, 'plan_snapshot' => []]);
        app(WorkforceAccountService::class)->adjustTime($this->employee, ['effective_on' => '2027-05-12', 'quantity' => 30, 'note' => 'Explicit approved correction'], $this->author);
        $summary = app(WorkforceAccountService::class)->summary($this->employee, '2027-05-12', '2027-05-12', $this->author);
        $this->assertSame(450, $summary['actual_minutes']);
        $this->assertSame(480, $summary['target_minutes']);
        $this->assertSame(0, $summary['balance_minutes']);
        $this->assertDatabaseHas('operation_audits', ['action' => 'time_account.adjusted']);
    }

    public function test_training_capacity_conflict_attendance_and_clock_contract(): void
    {
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Fixture training', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $participant = $service->enroll($training, $this->employee, 1, $this->author);
        $this->assertContains('training_overlap', array_column($service->planningIssues($this->shift(), $this->employee), 'code'));
        $another = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->invalid(fn () => $service->enroll($training, $another, 1, $this->author));
        app(WorkforceAccountService::class)->assertTrainingClock($this->employee, $training->id, CarbonImmutable::parse('2027-05-12T08:00', 'Europe/Berlin'));
        $this->invalid(fn () => app(WorkforceAccountService::class)->assertTrainingClock($this->employee, $training->id, CarbonImmutable::parse('2027-05-12T07:00', 'Europe/Berlin')));
        $this->invalid(fn () => $service->participation($participant, 1, 'attend', 'Confirmed attendance', $this->author));
        $this->travelTo(CarbonImmutable::parse('2027-05-12T17:00', 'Europe/Berlin'));
        $service->participation($participant, 1, 'attend', 'Confirmed attendance', $this->author);
        $this->assertSame('attended', $participant->fresh()->status);
        $this->assertSame(0, WorkTimeEntry::count());
        $this->assertSame(0, WorkforceAccountEntry::count());
    }

    public function test_training_cancellation_cannot_remove_started_or_past_confirmed_work(): void
    {
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Retained actual training', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $participant = $service->enroll($training, $this->employee, 1, $this->author);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T08:00', 'Europe/Berlin'));
        $this->invalid(fn () => $service->cancelTraining($training, 1, 'Cannot erase started work', $this->author));
        $this->assertSame('scheduled', $training->fresh()->status);
        $this->assertSame('confirmed', $participant->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T17:00', 'Europe/Berlin'));
        $this->invalid(fn () => $service->cancelTraining($training, 1, 'Cannot erase past work', $this->author));
        $this->assertSame(1, $training->fresh()->revision);
        $this->assertSame(1, $participant->fresh()->revision);
        $this->assertContains('training_overlap', array_column($service->planningIssues($this->shift(), $this->employee), 'code'));
        $this->assertDatabaseMissing('operation_audits', ['action' => 'training.cancelled']);
        $this->assertDatabaseMissing('operation_audits', ['action' => 'training.cancel']);
    }

    public function test_training_cancellation_preserves_attended_participants_even_in_an_inconsistent_future_record(): void
    {
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Imported attendance retained', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $participant = PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $this->employee->id, 'status' => 'attended', 'created_by' => $this->author->id, 'reviewed_by' => $this->reviewer->id, 'reviewed_at' => now()->utc(), 'note' => 'Imported attendance evidence']);
        $this->invalid(fn () => $service->cancelTraining($training, 1, 'Cannot erase attendance', $this->author));
        $this->assertSame('scheduled', $training->fresh()->status);
        $this->assertSame(1, $training->fresh()->revision);
        $this->assertSame('attended', $participant->fresh()->status);
        $this->assertSame(1, $participant->fresh()->revision);
        $this->assertSame('Imported attendance evidence', $participant->fresh()->note);
        $this->assertDatabaseMissing('operation_audits', ['action' => 'training.cancelled']);
    }

    public function test_future_training_cancellation_retains_scope_revision_and_audit_guards(): void
    {
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Cancelled future appointment', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $participant = $service->enroll($training, $this->employee, 1, $this->author);
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Training reviewers', 'personal_team' => false, 'user_id' => $this->author->id, 'rbac_permissions' => ['operations.qualifications.manage' => true]]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->reviewer, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-05-01', 'abilities' => ['operations.qualifications.manage']], $this->author);
        try {
            $service->cancelTraining($training, 1, 'Unauthorized target cancellation', $manager->fresh());
            $this->fail('A scoped reviewer must not cancel another employee appointment.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('scheduled', $training->fresh()->status);
        $this->assertSame('confirmed', $participant->fresh()->status);
        $this->invalid(fn () => $service->cancelTraining($training, 2, 'Stale revision cancellation', $this->author));
        $service->cancelTraining($training, 1, 'Approved future cancellation', $this->author);
        $this->assertSame('cancelled', $training->fresh()->status);
        $this->assertSame(2, $training->fresh()->revision);
        $this->assertSame('cancelled', $participant->fresh()->status);
        $this->assertSame(2, $participant->fresh()->revision);
        $this->assertSame($this->author->id, $participant->fresh()->reviewed_by);
        $this->assertSame('Approved future cancellation', $participant->fresh()->note);
        $this->assertDatabaseHas('operation_audits', ['action' => 'training.cancelled']);
        $this->assertDatabaseHas('operation_audits', ['action' => 'training.cancel']);
        $this->invalid(fn () => $service->cancelTraining($training, 1, 'Repeated future cancellation', $this->author));
    }

    public function test_separate_participant_cancellation_preserves_started_past_and_attended_history(): void
    {
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Actual participant history', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $participant = $service->enroll($training, $this->employee, 1, $this->author);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T08:00', 'Europe/Berlin'));
        $this->invalid(fn () => $service->participation($participant, 1, 'cancel', 'Cannot erase started participation', $this->author));
        $this->assertSame('confirmed', $participant->fresh()->status);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T17:00', 'Europe/Berlin'));
        $this->invalid(fn () => $service->participation($participant, 1, 'cancel', 'Cannot erase past participation', $this->author));
        $this->assertSame(1, $participant->fresh()->revision);
        $service->participation($participant, 1, 'attend', 'Retained actual attendance', $this->author);
        $this->invalid(fn () => $service->participation($participant, 2, 'cancel', 'Cannot erase attended participation', $this->author));
        $this->assertSame('attended', $participant->fresh()->status);
        $this->assertSame(2, $participant->fresh()->revision);
        $this->assertSame('Retained actual attendance', $participant->fresh()->note);
        $this->assertSame('scheduled', $training->fresh()->status);
        $this->assertContains('training_overlap', array_column($service->planningIssues($this->shift(), $this->employee), 'code'));
        $this->assertDatabaseMissing('operation_audits', ['action' => 'training.cancel']);
    }

    public function test_separate_future_participant_cancellation_uses_actual_subject_scope_and_revision(): void
    {
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Future participant cancellation', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T16:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $participant = $service->enroll($training, $this->employee, 1, $this->author);
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Scoped participation reviewers', 'personal_team' => false, 'user_id' => $this->author->id, 'rbac_permissions' => ['operations.qualifications.manage' => true]]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->reviewer, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-05-01', 'abilities' => ['operations.qualifications.manage']], $this->author);
        $participant->user_id = $this->reviewer->id;
        try {
            $service->participation($participant, 1, 'cancel', 'Forged target cancellation', $manager->fresh());
            $this->fail('A scoped reviewer must not substitute the participation subject.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('confirmed', $participant->fresh()->status);
        $this->invalid(fn () => $service->participation($participant, 2, 'cancel', 'Stale future participation', $this->author));
        $service->participation($participant, 1, 'cancel', 'Approved future participation', $this->author);
        $this->assertSame('cancelled', $participant->fresh()->status);
        $this->assertSame(2, $participant->fresh()->revision);
        $this->assertSame('scheduled', $training->fresh()->status);
        $this->assertSame(1, $training->fresh()->revision);
        $this->assertSame('Approved future participation', $participant->fresh()->note);
        $this->assertDatabaseHas('operation_audits', ['action' => 'training.cancel']);
    }

    public function test_personnel_task_completion_revision_does_not_provision_or_deactivate_accounts(): void
    {
        $service = app(PersonnelProcessService::class);
        $task = $service->createTask($this->employee, ['assigned_to' => $this->author->id, 'type' => 'offboarding', 'title' => 'Confirm equipment return', 'note' => 'Explicit local task'], $this->author);
        $service->completeTask($task, 1, '', $this->author);
        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('Explicit local task', $task->fresh()->note);
        $this->assertTrue($this->employee->fresh()->status);
        $this->invalid(fn () => $service->completeTask($task, 1, '', $this->author));
    }

    public function test_scope_never_configured_retains_legacy_but_expiry_does_not_expand_access(): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Verwaltung', 'personal_team' => false, 'user_id' => $this->author->id, 'rbac_permissions' => ['employees.master-data.view' => true, 'employees.master-data.edit' => true]]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        $scope = app(PersonnelScopeService::class);
        $this->assertNull($scope->visibleUserIds($manager, 'employees.master-data.view'));
        $scope->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-05-01', 'ends_on' => '2027-05-09', 'abilities' => ['employees.master-data.view']], $this->author);
        $this->assertSame([], $scope->visibleUserIds($manager->fresh(), 'employees.master-data.view'));
        try {
            app(WorkforceAccountService::class)->createModel($this->employee, [], $manager->fresh());
            $this->fail('Expired scope must not fall back globally.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_standard_management_personal_modals_and_process_page_render(): void
    {
        $this->model();
        $this->policy();
        $this->credit();
        Livewire::actingAs($this->author)->test(WorkforceAccounts::class)->assertSee('Kontogutschrift')->assertSeeHtml('data-rt-premium-table')->call('showTab', 'models')->assertSee('Explicit fixture')->call('openForm', 'model')->assertSet('formOpen', true)->assertSeeHtml('role="dialog"');
        Livewire::actingAs($this->employee)->test(WorkforceAccounts::class, ['personal' => true])->call('showTab', 'absences')->call('openForm', 'vacation')->set('form.starts_on', '2027-05-12')->set('form.ends_on', '2027-05-12')->call('save')->assertHasNoErrors()->assertSet('formOpen', false)->assertSee('Urlaub');
        Livewire::actingAs($this->author)->test(PersonnelProcesses::class)->assertSet('tab', 'tasks')->call('showTab', 'training')->call('openForm', 'training')->assertSet('formOpen', true);
    }

    public function test_personal_mode_cannot_change_subject_or_open_management_forms(): void
    {
        Livewire::actingAs($this->employee)->test(WorkforceAccounts::class, ['personal' => true])->call('openForm', 'model')->assertForbidden();
        Livewire::actingAs($this->employee)->test(WorkforceAccounts::class, ['personal' => true])->set('userId', $this->author->id)->assertForbidden();
    }

    public function test_schema_guard_preserves_unmigrated_legacy_operations_and_migration_is_resumable(): void
    {
        $migration = require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php');
        $migration->up();
        $this->assertTrue(app(WorkforceAccountService::class)->ready());
        Schema::drop('vacation_reservations');
        $this->assertFalse(app(WorkforceAccountService::class)->ready());
        $this->assertTrue(OperationsAccess::ready());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_crossing_period_pause_without_locations_is_unknown_not_proportional(): void
    {
        $this->model();
        $shift = $this->shift('2027-05-31T22:00', '2027-06-01T02:00');
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $entry = WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $this->employee->id, 'status' => 'approved', 'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'timezone' => 'Europe/Berlin', 'pause_seconds' => 1800, 'plan_snapshot' => []]);
        $summary = app(WorkforceAccountService::class)->summary($this->employee, '2027-05-31', '2027-05-31', $this->author);
        $this->assertNull($summary['actual_minutes']);
        $this->assertNull($summary['balance_minutes']);
        $this->assertSame([$entry->id], $summary['missing_time_allocations']);
        $whole = app(WorkforceAccountService::class)->summary($this->employee, '2027-05-31', '2027-06-01', $this->author);
        $this->assertSame(210, $whole['actual_minutes']);
    }

    public function test_activity_period_cut_keeps_real_pause_location_and_paid_break_separate(): void
    {
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        $this->model(['valuation_rules' => ['work' => 10000, 'break' => 10000]]);
        $start = CarbonImmutable::parse('2027-05-31T22:00', 'Europe/Berlin');
        $boundary = CarbonImmutable::parse('2027-06-01T00:00', 'Europe/Berlin');
        $pauseEnd = CarbonImmutable::parse('2027-06-01T00:30', 'Europe/Berlin');
        $end = CarbonImmutable::parse('2027-06-01T02:00', 'Europe/Berlin');
        $entry = WorkTimeEntry::create(['shift_assignment_id' => null, 'work_context' => 'internal', 'user_id' => $this->employee->id, 'status' => 'approved', 'starts_at' => $start, 'ends_at' => $end, 'timezone' => 'Europe/Berlin', 'pause_seconds' => 1800, 'plan_snapshot' => ['valuation' => ['internal' => 10000, 'break' => 10000]]]);
        $entry->activities()->createMany([
            ['kind' => 'internal', 'starts_at' => $start->utc(), 'ends_at' => $boundary->utc()],
            ['kind' => 'break', 'starts_at' => $boundary->utc(), 'ends_at' => $pauseEnd->utc(), 'is_paid' => true],
            ['kind' => 'internal', 'starts_at' => $pauseEnd->utc(), 'ends_at' => $end->utc()],
        ]);
        $service = app(WorkforceAccountService::class);
        $first = $service->summary($this->employee, '2027-05-31', '2027-05-31', $this->author);
        $second = $service->summary($this->employee, '2027-06-01', '2027-06-01', $this->author);
        $this->assertSame(120, $first['actual_minutes']);
        $this->assertSame(90, $second['actual_minutes']);
        $this->assertSame(120, $second['credited_minutes']);
        $this->assertSame(240 * 60, $entry->creditedSeconds());
        $this->assertSame(210 * 60, $entry->netSeconds());
        $this->assertSame([], $second['missing_time_allocations']);
    }

    public function test_new_internal_time_without_valuation_keeps_raw_time_but_requires_review(): void
    {
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        $this->model();
        $entry = WorkTimeEntry::create(['shift_assignment_id' => null, 'work_context' => 'internal', 'user_id' => $this->employee->id, 'status' => 'approved', 'starts_at' => CarbonImmutable::parse('2027-05-12T08:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse('2027-05-12T09:00', 'Europe/Berlin'), 'timezone' => 'Europe/Berlin', 'plan_snapshot' => []]);
        $summary = app(WorkforceAccountService::class)->summary($this->employee, '2027-05-12', '2027-05-12', $this->author);
        $this->assertSame(60, $summary['actual_minutes']);
        $this->assertNull($summary['credited_minutes']);
        $this->assertNull($summary['balance_minutes']);
        $this->assertSame([$entry->id], $summary['missing_valuations']);
    }

    public function test_scoped_actor_cannot_review_foreign_absence_even_with_gate_permission(): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Verwaltung', 'personal_team' => false, 'user_id' => $this->author->id, 'rbac_permissions' => ['operations.absences.review' => true]]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($other, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => ['operations.absences.review']], $this->author);
        $request = $this->request();
        try {
            app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $manager);
            $this->fail('Foreign absence must remain private.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_many_credit_lots_do_not_round_each_piece_of_vacation_time(): void
    {
        $this->model(['weekly_target_minutes' => 2255, 'daily_minutes' => [1 => 451, 2 => 451, 3 => 451, 4 => 451, 5 => 451, 6 => 0, 7 => 0]]);
        $this->policy();
        foreach (range(1, 100) as $number) {
            $this->credit(['amount' => 0.01, 'note' => 'Explicit lot '.$number]);
        }
        $request = $this->request();
        app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer);
        $summary = app(WorkforceAccountService::class)->summary($this->employee, '2027-05-12', '2027-05-12', $this->author);
        $this->assertSame(451, $summary['absence_credit_minutes']);
        $this->assertSame(0, $summary['balance_minutes']);
    }

    public function test_explicit_legacy_adoption_blocks_hidden_double_spending_and_keeps_original_approval(): void
    {
        $this->model();
        $legacy = $this->request();
        app(PersonnelWorkflowService::class)->absence($legacy, 1, 'approve', '', $this->reviewer);
        $this->policy();
        $this->credit(['amount' => 2]);
        $service = app(WorkforceAccountService::class);
        $this->assertSame([$legacy->id], $service->unallocatedAbsences($this->employee));
        $this->assertNull($service->vacationBalance($this->employee, '2027-05-10')['available']);
        $this->invalid(fn () => $this->request('2027-05-14T00:00', '2027-05-15T00:00'));
        $service->adoptApprovedAbsence($legacy->fresh(), 2, 'Explicit opening migration approval', $this->author);
        $this->assertSame('approved', $legacy->fresh()->status);
        $this->assertSame(3, $legacy->fresh()->revision);
        $this->assertSame(100, $service->vacationBalance($this->employee, '2027-05-10')['available']);
        $this->assertSame([], $service->unallocatedAbsences($this->employee));
        $this->assertDatabaseHas('operation_audits', ['action' => 'vacation.legacy_adopted']);
        $this->invalid(fn () => $service->adoptApprovedAbsence($legacy->fresh(), 3, 'Duplicate opening migration', $this->author));
    }

    public function test_scoped_rule_manager_cannot_change_global_rules(): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Verwaltung', 'personal_team' => false, 'user_id' => $this->author->id, 'rbac_permissions' => ['operations.rules.manage' => true]]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => ['operations.rules.manage']], $this->author);
        try {
            app(PersonnelWorkflowService::class)->saveRules(['name' => 'Global mutation', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 600, 'break_after_minutes' => 600, 'minimum_break_minutes' => 0, 'confirmed' => true], $manager);
            $this->fail('Scoped rule manager cannot modify global rules.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, OperationsRuleProfile::count());
    }

    public function test_effective_contract_and_rule_instant_use_employee_timezone_not_utc_day(): void
    {
        $model = $this->model(['starts_on' => '2027-05-12']);
        $profile = OperationsRuleProfile::first();
        app(WorkforceAccountService::class)->assignRules($this->employee, ['operations_rule_profile_id' => $profile->id, 'starts_on' => '2027-05-12'], $this->author);
        $at = CarbonImmutable::parse('2027-05-11T22:30', 'UTC');
        $this->assertSame($model->id, app(WorkforceAccountService::class)->effectiveModel($this->employee, $at)->id);
        $this->assertNull(app(WorkforceAccountService::class)->effectiveModel($this->employee, '2027-05-11'));
        $this->assertSame($profile->id, app(WorkforceAccountService::class)->effectiveRules($this->employee, $at)->id);
    }

    public function test_contract_timezone_is_required_for_day_based_vacation(): void
    {
        $this->model();
        $this->policy();
        $this->credit();
        $this->invalid(fn () => $this->request('2027-05-12T00:00', '2027-05-13T00:00', ['timezone' => 'America/New_York']));
        $this->assertSame(0, AbsenceRequest::count());
    }

    public function test_account_credit_and_time_adjustment_retry_are_idempotent_not_double_booked(): void
    {
        $this->model();
        $this->policy();
        $key = (string) Str::uuid();
        $first = $this->credit(['idempotency_key' => $key]);
        $again = $this->credit(['idempotency_key' => $key]);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, WorkforceAccountEntry::count());
        $this->invalid(fn () => $this->credit(['idempotency_key' => $key, 'amount' => 20]));
        $data = ['effective_on' => '2027-05-12', 'quantity' => 30, 'note' => 'Explicit correction retry', 'idempotency_key' => (string) Str::uuid()];
        $service = app(WorkforceAccountService::class);
        $this->assertSame($service->adjustTime($this->employee, $data, $this->author)->id, $service->adjustTime($this->employee, $data, $this->author)->id);
        $this->assertSame(2, WorkforceAccountEntry::count());
    }

    public function test_half_day_blocks_only_the_real_window_and_requires_explicit_net_work_windows(): void
    {
        $this->model(['work_windows' => [3 => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']]]]);
        $this->policy();
        $this->credit();
        $request = $this->request('2027-05-12T08:00', '2027-05-12T12:00', ['vacation_fraction' => 0.5]);
        app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer);
        $afternoon = $this->shift('2027-05-12T13:00', '2027-05-12T17:00');
        $this->assertFalse(AbsenceRequest::where('status', 'approved')->where('starts_at', '<', $afternoon->ends_at->utc())->where('ends_at', '>', $afternoon->starts_at->utc())->exists());
        $this->assertSame(50, app(WorkforceAccountService::class)->absenceQuote($request)['quantity']);
        $this->invalid(fn () => $this->request('2027-05-14T08:00', '2027-05-14T12:00', ['vacation_fraction' => 0.5]));
        $this->invalid(fn () => $this->request('2027-05-13T00:00', '2027-05-14T00:00', ['vacation_fraction' => 0.5]));
    }

    public function test_hour_vacation_charges_only_real_work_window_minutes(): void
    {
        $this->model(['work_windows' => [3 => [['start' => '08:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '17:00']]]]);
        $this->policy(['unit' => 'minutes']);
        $this->credit(['amount' => 240]);
        $request = $this->request('2027-05-12T10:00', '2027-05-12T11:00');
        $this->assertSame(60, app(WorkforceAccountService::class)->absenceQuote($request)['quantity']);
        $this->assertSame(60, (int) VacationReservation::sum('quantity'));
    }

    public function test_reported_sickness_prevents_overlapping_new_vacation_request(): void
    {
        app(PersonnelWorkflowService::class)->reportSickness($this->employee, ['starts_at' => '2027-05-12T00:00', 'ends_at' => '2027-05-13T00:00', 'timezone' => 'Europe/Berlin']);
        $this->invalid(fn () => $this->request());
        $this->assertSame(1, AbsenceRequest::count());
    }

    public function test_credit_reduction_uses_visible_policy_units_and_idempotent_retries(): void
    {
        $this->model();
        $this->policy();
        $credit = $this->credit();
        $key = (string) Str::uuid();
        $service = app(WorkforceAccountService::class);
        $first = $service->reduceCreditAmount($credit, 1.25, 'Explicit source correction', $this->author, $key);
        $again = $service->reduceCreditAmount($credit, 1.25, 'Explicit source correction', $this->author, $key);
        $this->assertSame(-125, $first->quantity);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(2, WorkforceAccountEntry::count());
        $this->invalid(fn () => $service->reduceCreditAmount($credit, 2, 'Explicit source correction', $this->author, $key));
        $this->invalid(fn () => $service->reduceCreditAmount($credit, 0.001, 'Invalid sub-unit precision', $this->author));
    }

    public function test_vacation_modal_can_clear_date_without_render_failure(): void
    {
        $this->model();
        $this->policy(['unit' => 'minutes']);
        Livewire::actingAs($this->employee)->test(WorkforceAccounts::class, ['personal' => true])
            ->call('showTab', 'absences')->call('openForm', 'vacation')
            ->set('form.starts_on', '')->assertStatus(200)
            ->set('form.starts_on', '2027-02-30')->assertStatus(200)
            ->call('save')->assertHasErrors('form.starts_on');
    }

    public function test_absence_approval_preserves_confirmed_training_capacity_and_quota_reservation(): void
    {
        $this->model();
        $this->policy();
        $this->credit();
        $service = app(PersonnelProcessService::class);
        $training = $service->createTraining(['title' => 'Explicit booking', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T12:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1], $this->author);
        $service->enroll($training, $this->employee, 1, $this->author);
        $request = $this->request();
        $this->invalid(fn () => app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->reviewer));
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('reserved', VacationReservation::first()->status);
        $this->assertSame(1, $request->fresh()->revision);
    }

    public function test_migration_restores_named_uniques_after_partial_index_installation(): void
    {
        Schema::table('vacation_reservations', fn ($table) => $table->dropUnique('vacation_absence_credit_unique'));
        Schema::table('personnel_training_participants', fn ($table) => $table->dropUnique('training_participant_user_unique'));
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        $this->assertTrue(Schema::hasIndex('vacation_reservations', ['absence_request_id', 'workforce_account_entry_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('personnel_training_participants', ['personnel_training_id', 'user_id'], 'unique'));
    }

    public function test_confirmed_training_counts_toward_explicit_weekly_limit_and_enrollment_rechecks_contract(): void
    {
        $this->model(['weekly_target_minutes' => 600, 'maximum_weekly_minutes' => 600, 'daily_minutes' => [1 => 120, 2 => 120, 3 => 120, 4 => 120, 5 => 120, 6 => 0, 7 => 0]]);
        $process = app(PersonnelProcessService::class);
        $training = $process->createTraining(['title' => 'Explicit planned training', 'starts_at' => '2027-05-12T08:00', 'ends_at' => '2027-05-12T12:00', 'timezone' => 'Europe/Berlin', 'capacity' => 2], $this->author);
        $process->enroll($training, $this->employee, 1, $this->author);
        $shift = $this->shift('2027-05-13T08:00', '2027-05-13T16:00');
        $this->assertContains('contract_weekly_limit', array_column(app(WorkforceAccountService::class)->planningIssues($shift, $this->employee), 'code'));
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $second = $process->createTraining(['title' => 'Second planned training', 'starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T09:00', 'timezone' => 'Europe/Berlin', 'capacity' => 2], $this->author);
        $this->invalid(fn () => $process->enroll($second, $this->employee, 1, $this->author));
        $this->assertSame(1, PersonnelTrainingParticipant::count());
        $this->assertSame(0, WorkforceAccountEntry::count());
    }

    public function test_planpause_crossing_week_boundary_is_not_proportionally_invented(): void
    {
        $this->model(['daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 0, 6 => 0, 7 => 480]]);
        $shift = $this->shift('2027-05-16T23:00', '2027-05-17T03:00');
        $shift->forceFill(['planned_break_minutes' => 30])->save();
        $this->assertContains('contract_weekly_allocation_unknown', array_column(app(WorkforceAccountService::class)->planningIssues($shift, $this->employee), 'code'));
    }

    public function test_migration_rollback_refuses_personnel_history_before_any_schema_change(): void
    {
        $this->model();
        $migration = require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php');
        try {
            $migration->down();
            $this->fail('Historical work models must not be silently dropped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Personalhistorie', $exception->getMessage());
        }
        $this->assertSame(1, EmployeeWorkModel::count());
        $this->assertTrue(Schema::hasTable('personnel_training_participants'));
        $this->assertTrue(Schema::hasColumn('absence_requests', 'vacation_fraction'));
    }

    private function publishedAssignment(?Shift $shift = null): ShiftAssignment
    {
        $shift ??= $this->shift();
        $shift->forceFill(['revision' => 1, 'published_revision' => 1, 'published_at' => now()->utc()])->save();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $assignment->forceFill(['plan_revision' => 1])->save();

        return $assignment->fresh();
    }

    public function test_contract_activation_creates_concrete_published_future_conflicts_without_rewriting_plan(): void
    {
        $assignment = $this->publishedAssignment();
        $past = $this->publishedAssignment($this->shift('2027-05-09T08:00', '2027-05-09T16:00'));
        $draft = $this->publishedAssignment($this->shift('2027-05-14T08:00', '2027-05-14T10:00'));
        $draft->shift->forceFill(['published_revision' => 0, 'published_at' => null])->save();
        $cancelled = $this->publishedAssignment($this->shift('2027-05-15T08:00', '2027-05-15T10:00'));
        $cancelled->shift->forceFill(['status' => 'cancelled'])->save();
        $model = $this->model(['weekly_target_minutes' => 1920, 'daily_minutes' => [1 => 480, 2 => 480, 3 => 0, 4 => 480, 5 => 480, 6 => 0, 7 => 0]]);
        $case = PersonnelPlanReview::sole();
        $this->assertSame($assignment->id, $case->shift_assignment_id);
        $this->assertSame('conflict', $case->status);
        $this->assertContains('contract_workday', array_column($case->latest_snapshot['issues'], 'code'));
        $this->assertSame($model->id, $case->origin_id);
        $this->assertSame(1, $assignment->shift->fresh()->revision);
        $this->assertSame('confirmed', $assignment->fresh()->status->value);
        app(WorkforcePlanReviewService::class)->recheckForEmployee($this->employee, $model, $this->reviewer);
        $this->assertSame(1, PersonnelPlanReview::count());
        $this->assertDatabaseHas('operation_audits', ['action' => 'personnel_plan_review.opened', 'subject_id' => $case->id]);
    }

    public function test_ending_contract_opens_unknown_review_and_explicit_four_eye_reassessment_keeps_initial_snapshot(): void
    {
        $model = $this->model();
        $assignment = $this->publishedAssignment();
        $accounts = app(WorkforceAccountService::class);
        $accounts->endVersion($model, 2, '2027-05-11', 'Explicit future contract end', $this->author);
        $case = PersonnelPlanReview::sole();
        $initial = $case->initial_snapshot;
        $this->assertSame('review', $case->status);
        $this->assertContains('contract_missing', array_column($initial['issues'], 'code'));
        $this->model(['starts_on' => '2027-05-12']);
        $service = app(WorkforcePlanReviewService::class);
        try {
            $service->reassess($case, 1, 'Creator attempts own clearance', $this->author);
            $this->fail('A different person must clear a review.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $service->reassess($case, 1, 'Effective replacement contract checked', $this->reviewer);
        $this->assertSame('resolved', $case->fresh()->status);
        $this->assertSame(2, $case->fresh()->revision);
        $this->assertTrue($case->fresh()->reviewed_at->equalTo(now()->utc()));
        $this->assertSame($initial, $case->fresh()->initial_snapshot);
        $this->assertSame([], $case->fresh()->latest_snapshot['issues']);
        $this->assertSame('confirmed', $assignment->fresh()->status->value);
        $this->invalid(fn () => $service->reassess($case, 1, 'Stale review must not overwrite', $this->reviewer));
    }

    public function test_dated_and_global_rule_changes_record_conflicts_instead_of_rewriting_or_cancelling_assignments(): void
    {
        $assignment = $this->publishedAssignment();
        $profile = OperationsRuleProfile::create(['name' => 'Explicit shorter model', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 240, 'break_after_minutes' => 1440, 'minimum_break_minutes' => 0, 'is_active' => false, 'created_by' => $this->author->id, 'approved_at' => now()->utc()]);
        app(WorkforceAccountService::class)->assignRules($this->employee, ['operations_rule_profile_id' => $profile->id, 'starts_on' => '2027-05-12', 'ends_on' => '2027-05-13'], $this->author);
        $case = PersonnelPlanReview::sole();
        $this->assertSame('rule_assignment', $case->origin_type);
        $this->assertContains('shift_duration', array_column($case->latest_snapshot['issues'], 'code'));
        $this->assertSame('confirmed', $assignment->fresh()->status->value);
        $global = app(PersonnelWorkflowService::class)->saveRules(['name' => 'Explicit global short limit', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 180, 'break_after_minutes' => 1440, 'minimum_break_minutes' => 0, 'confirmed' => true], $this->author);
        $this->assertTrue($global->is_active);
        $this->assertSame(2, PersonnelPlanReview::count());
        $this->assertSame(1, $assignment->shift->fresh()->published_revision);
        $this->assertSame('confirmed', $assignment->fresh()->status->value);
    }

    public function test_plan_review_never_clears_an_unpublished_draft_and_personal_tab_is_private(): void
    {
        $model = $this->model();
        $assignment = $this->publishedAssignment();
        app(WorkforceAccountService::class)->endVersion($model, 2, '2027-05-11', 'Explicit future end for review', $this->author);
        $case = PersonnelPlanReview::sole();
        $assignment->shift->forceFill(['revision' => 2])->save();
        app(WorkforcePlanReviewService::class)->reassess($case, 1, 'Current draft is not published', $this->reviewer);
        $this->assertSame('stale', $case->fresh()->status);
        $this->assertContains('plan_revision_changed', array_column($case->fresh()->latest_snapshot['issues'], 'code'));
        Livewire::actingAs($this->author)->test(WorkforceAccounts::class)->call('showTab', 'checks')->assertSee('Plan geändert')->assertSee('Neu prüfen')->call('prepareRecord', 'plan_review', $case->id, 2)->assertSet('formOpen', true)->assertSee('Dienst neu beurteilen');
        Livewire::actingAs($this->employee)->test(WorkforceAccounts::class, ['personal' => true])->set('tab', 'checks')->assertForbidden();
    }

    public function test_unavailable_review_queue_does_not_silently_change_a_published_employees_contract(): void
    {
        $model = $this->model();
        $this->publishedAssignment();
        Schema::drop('personnel_plan_reviews');
        $this->invalid(fn () => app(WorkforceAccountService::class)->endVersion($model, 2, '2027-05-11', 'Missing queue must stay safe', $this->author));
        $this->assertNull($model->fresh()->ends_on);
        $this->assertSame(2, $model->fresh()->revision);
    }

    public function test_plan_reviews_and_change_source_are_authorized_against_fresh_stored_subjects(): void
    {
        $manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $team = Team::forceCreate(['name' => 'Scoped Personnel', 'personal_team' => false, 'user_id' => $this->author->id, 'rbac_permissions' => ['employees.master-data.view' => true, 'employees.master-data.edit' => true]]);
        $manager->teams()->attach($team->id);
        $manager->forceFill(['current_team_id' => $team->id])->save();
        app(PersonnelScopeService::class)->assign($this->employee, ['responsible_user_id' => $manager->id, 'starts_on' => '2027-01-01', 'abilities' => ['employees.master-data.view', 'employees.master-data.edit']], $this->author);
        $foreign = User::factory()->create(['role' => 'staff', 'status' => true]);
        $service = app(WorkforceAccountService::class);
        $model = $service->createModel($foreign, ['name' => 'Private foreign contract', 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-11', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0]], $this->author);
        $service->activate($model, 1, $this->reviewer);
        $shift = $this->shift();
        $shift->forceFill(['title' => 'Private foreign duty', 'revision' => 1, 'published_revision' => 1, 'published_at' => now()->utc()])->save();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $foreign->id, 'status' => 'confirmed', 'assigned_by' => $this->author->id]);
        $assignment->forceFill(['plan_revision' => 1])->save();
        $reviews = app(WorkforcePlanReviewService::class);
        $reviews->recheckForEmployee($foreign, $model, $this->author);
        $case = PersonnelPlanReview::sole();
        $forgedCase = clone $case;
        $forgedCase->user_id = $this->employee->id;
        try {
            $reviews->reassess($forgedCase, 1, 'Attempt foreign reassessment', $manager);
            $this->fail('Fresh persisted review subject must be authorized.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $forgedModel = clone $model;
        $forgedModel->user_id = $this->employee->id;
        try {
            $reviews->recheckForEmployee($this->employee, $forgedModel, $manager);
            $this->fail('Fresh persisted change source must match its employee.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, PersonnelPlanReview::count());
        $this->assertSame(1, $case->fresh()->revision);
        Livewire::actingAs($manager)->test(WorkforceAccounts::class)->call('showTab', 'checks')->assertDontSee('Private foreign duty')->set('userId', $foreign->id)->assertForbidden();
    }

    public function test_contract_end_cannot_shorten_historical_employee_local_days_at_timezone_boundary(): void
    {
        $model = $this->model(['timezone' => 'Asia/Tokyo']);
        $this->travelTo(CarbonImmutable::parse('2027-05-10T15:30', 'UTC'));
        $this->invalid(fn () => app(WorkforceAccountService::class)->endVersion($model, 2, '2027-05-10', 'Already past in employee timezone', $this->author));
        $this->assertNull($model->fresh()->ends_on);
        $this->assertSame(2, $model->fresh()->revision);
    }
}
