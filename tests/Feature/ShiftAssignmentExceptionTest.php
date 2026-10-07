<?php

namespace Tests\Feature;

use App\Enums\ShiftAssignmentStatus;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentExceptionService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\StaffRegionalPreferenceService;
use App\Services\Operations\WorkTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ShiftAssignmentExceptionTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private User $employee;

    private Order $order;

    private OperationsRuleProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->actor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['name' => 'Erika Muster', 'role' => 'staff', 'status' => true]);
        $this->profile = OperationsRuleProfile::create(['name' => 'Freigegebenes Testprofil', 'minimum_rest_minutes' => 660, 'maximum_shift_minutes' => 600, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->actor->id, 'approved_at' => now()->utc()]);
        $customer = Customer::create(['company_name' => 'Rail QA', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Bahnhofsleistung', 'service_type' => 'Wagenmeister', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T00:00', 'ends_at' => '2027-05-15T00:00', 'required_staff' => 3, 'created_by' => $this->actor->id]);
    }

    private function shift(string $start = '2027-05-13T11:00:00+02:00', string $end = '2027-05-13T13:00:00+02:00', array $extra = []): Shift
    {
        return Shift::create(array_merge(['order_id' => $this->order->id, 'title' => 'Zugabfertigung', 'role_name' => 'Wagenmeister', 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'required_staff' => 2, 'planned_break_minutes' => 0, 'status' => 'draft', 'location_name' => 'Bremen Hbf', 'created_by' => $this->actor->id], $extra))->fresh();
    }

    private function conflictingShift(): Shift
    {
        $other = $this->shift('2027-05-13T08:00:00+02:00', '2027-05-13T12:00:00+02:00');
        app(ShiftAssignmentService::class)->assign($other, $this->employee, $this->actor);

        return $other;
    }

    private function review(Shift $shift, ?User $actor = null): array
    {
        return app(ShiftAssignmentExceptionService::class)->review($shift, $this->employee, $actor ?? $this->actor);
    }

    private function confirmation(Shift $shift, ?User $actor = null): array
    {
        return ['fingerprint' => $this->review($shift, $actor)['fingerprint'], 'reason' => 'Beide Züge werden am selben Bahnhof betreut; Ablauf und tatsächliche Pausen wurden geprüft.', 'acknowledged' => true];
    }

    private function assign(Shift $shift, array $confirmation, ?User $actor = null): ShiftAssignment
    {
        return app(ShiftAssignmentService::class)->assign($shift, $this->employee, $actor ?? $this->actor, ShiftAssignmentStatus::Confirmed, null, $shift->revision, $confirmation);
    }

    private function invalid(callable $action, string $expected): void
    {
        try {
            $action();
            $this->fail('Expected validation failure');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($expected, $exception->validator->errors()->first());
        }
    }

    public function test_normal_assignment_remains_strict_and_exception_records_concrete_overlap_and_audit(): void
    {
        $other = $this->conflictingShift();
        $shift = $this->shift();
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->actor), 'bereits');
        $review = $this->review($shift);
        $this->assertTrue($review['can_override']);
        $this->assertSame('overlap', $review['conflicts'][0]['type']);
        $this->assertSame(60, $review['conflicts'][0]['overlap_minutes']);
        $this->assertNull($review['conflicts'][0]['gap_minutes']);
        $this->assertTrue($review['conflicts'][0]['same_location']);
        $this->assertSame($other->id, $review['conflicts'][0]['shift_id']);
        $this->assertSame('2027-05-13T08:00:00+02:00', $review['conflicts'][0]['starts_at']);
        $this->assertSame(660, $review['rules']['minimum_rest_minutes']);
        $assignment = $this->assign($shift, $this->confirmation($shift));
        $audit = OperationAudit::where('action', 'assignment.temporal_exception')->sole();
        $this->assertSame($assignment->id, $audit->subject_id);
        $this->assertSame($this->actor->id, $audit->actor_id);
        $this->assertTrue($audit->data['acknowledged']);
        $this->assertFalse($audit->data['legal_exemption']);
        $this->assertSame('rest_overlap', $audit->data['temporal_issues'][0]['code']);
        $this->assertSame($shift->revision, $audit->data['plan_revision']);
        $this->assertSame(2, ShiftAssignment::blocking()->count());
        $this->assertSame(0, $shift->fresh()->planned_break_minutes);
    }

    public function test_missing_acknowledgment_and_short_reason_never_write_an_assignment(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $valid = $this->confirmation($shift);
        foreach ([['acknowledged' => false], ['acknowledged' => 'true'], ['reason' => 'OK'], ['reason' => str_repeat(' ', 30)], ['reason' => 'a'.str_repeat(' ', 30).'b']] as $change) {
            $this->invalid(fn () => $this->assign($shift, array_replace($valid, $change)), 'ausdrücklich');
        }
        $this->assertDatabaseCount('shift_assignments', 1);
        $this->assertSame(0, OperationAudit::where('action', 'assignment.temporal_exception')->count());
        $this->invalid(fn () => $this->assign($shift, array_replace($valid, ['fingerprint' => 'tampered'])), 'geändert');
    }

    public function test_rules_and_conflict_intervals_are_bound_to_confirmation_even_when_issue_code_does_not_change(): void
    {
        $other = $this->conflictingShift();
        $shift = $this->shift();
        $confirmation = $this->confirmation($shift);
        $other->update(['ends_at' => '2027-05-13T12:30:00+02:00']);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'geändert');
        $confirmation = $this->confirmation($shift);
        $this->profile->update(['minimum_rest_minutes' => 720]);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'geändert');
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_new_conflict_and_changed_target_revision_require_new_confirmation(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $confirmation = $this->confirmation($shift);
        $other = $this->shift('2027-05-13T12:00:00+02:00', '2027-05-13T14:00:00+02:00');
        ShiftAssignment::create(['shift_id' => $other->id, 'user_id' => $this->employee->id, 'status' => 'requested', 'assigned_by' => $this->actor->id]);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'geändert');
        $confirmation = $this->confirmation($shift);
        $shift->increment('revision');
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'geändert');
    }

    public function test_a_confirmation_cannot_be_reused_for_another_actor_or_shift_or_employee(): void
    {
        $conflict = $this->conflictingShift();
        $shift = $this->shift();
        $confirmation = $this->confirmation($shift);
        $otherActor = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->invalid(fn () => $this->assign($shift, $confirmation, $otherActor), 'geändert');
        $otherShift = $this->shift();
        $this->invalid(fn () => $this->assign($otherShift, $confirmation), 'geändert');
        $otherEmployee = User::factory()->create(['role' => 'staff', 'status' => true]);
        ShiftAssignment::create(['shift_id' => $conflict->id, 'user_id' => $otherEmployee->id, 'status' => 'confirmed', 'assigned_by' => $this->actor->id]);
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $otherEmployee, $this->actor, exception: $confirmation), 'geändert');
    }

    public function test_repeating_confirmed_exception_does_not_mutate_assignment_or_add_a_second_exception_audit(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $confirmation = $this->confirmation($shift);
        $assignment = $this->assign($shift, $confirmation);
        $before = $assignment->fresh()->getAttributes();
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'bereits zugewiesen');
        $this->assertSame($before, $assignment->fresh()->getAttributes());
        $this->assertSame(1, OperationAudit::where('action', 'assignment.temporal_exception')->count());
    }

    public function test_qualifications_absences_and_missing_rules_remain_hard_blocks(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $confirmation = $this->confirmation($shift);
        $type = QualificationType::create(['name' => 'Sicherheitsnachweis', 'is_active' => true]);
        $shift->qualifications()->attach($type);
        $this->assertFalse($this->review($shift)['can_override']);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'Nachweis');
        $shift->qualifications()->detach();
        $absence = AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'status' => 'approved', 'starts_at' => '2027-05-13T00:00:00+02:00', 'ends_at' => '2027-05-14T00:00:00+02:00', 'timezone' => 'Europe/Berlin']);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'Abwesenheit');
        $absence->update(['status' => 'cancelled']);
        $this->profile->update(['is_active' => false]);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'Regelprofil');
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_unknown_incomplete_and_non_temporal_issues_are_never_classified_as_overridable(): void
    {
        $service = app(ShiftAssignmentExceptionService::class);
        foreach (['break_duration', 'training_overlap', 'training_rules_missing', 'contract_missing', 'contract_weekly_allocation_unknown', 'configured_basis_missing', 'additional_rules_incomplete', 'dependency_gap', 'dependency_resource', 'dependency_person', 'customer_capacity_reserved', 'customer_capacity_changed', 'qualification_4', 'pool', 'unknown_future_rule'] as $code) {
            $this->assertFalse($service->isTemporalIssue(['code' => $code]), $code);
        }
    }

    public function test_restricted_audience_and_current_permission_are_both_required(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $staffPlanner = User::factory()->create(['role' => 'staff', 'status' => true]);
        Gate::define('operations.manage', fn (User $actor) => $actor->status);
        $this->assertFalse(app(ShiftAssignmentExceptionService::class)->canOverride($staffPlanner));
        $this->assertFalse($this->review($shift, $staffPlanner)['can_override']);
        try {
            $this->assign($shift, $this->confirmation($shift, $staffPlanner), $staffPlanner);
            $this->fail('Expected forbidden');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $team = Team::forceCreate(['name' => 'Verwaltung', 'user_id' => $this->actor->id, 'personal_team' => false]);
        $team->users()->attach($staffPlanner->id, ['role' => 'admin']);
        $staffPlanner->update(['current_team_id' => $team->id]);
        $staffPlanner = $staffPlanner->fresh();
        $this->assertTrue(app(ShiftAssignmentExceptionService::class)->canOverride($staffPlanner));
        Gate::define('operations.manage', fn () => false);
        $this->assertFalse(app(ShiftAssignmentExceptionService::class)->canOverride($staffPlanner));
        Gate::define('operations.manage', fn () => true);
        $this->assign($shift, $this->confirmation($shift, $staffPlanner), $staffPlanner);
        $this->assertSame($staffPlanner->id, OperationAudit::where('action', 'assignment.temporal_exception')->sole()->actor_id);
    }

    public function test_disabled_administrator_and_nonblocking_status_cannot_use_exception(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $confirmation = $this->confirmation($shift);
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->actor, ShiftAssignmentStatus::Declined, exception: $confirmation), 'verbindliche');
        $this->actor->update(['status' => false]);
        $this->assertFalse(app(ShiftAssignmentExceptionService::class)->canOverride($this->actor));
        try {
            $this->assign($shift, $confirmation);
            $this->fail('Expected forbidden');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_rest_gap_and_transfer_buffer_are_distinct_from_an_actual_overlap(): void
    {
        $this->conflictingShift();
        $shift = $this->shift('2027-05-13T14:00:00+02:00', '2027-05-13T16:00:00+02:00');
        $review = $this->review($shift);
        $this->assertSame('rest', $review['conflicts'][0]['type']);
        $this->assertSame(120, $review['conflicts'][0]['gap_minutes']);
        $this->assertSame(0, $review['conflicts'][0]['overlap_minutes']);
        $this->assertSame('before', $review['conflicts'][0]['direction']);
        $this->profile->update(['minimum_rest_minutes' => 0]);
        $shift->update(['location_name' => 'Hamburg', 'disposition_details' => ['transfer_buffer_minutes' => 180]]);
        $review = $this->review($shift);
        $this->assertSame('transfer', $review['conflicts'][0]['type']);
        $this->assertFalse($review['conflicts'][0]['same_location']);
        $this->assertTrue($review['can_override']);
    }

    public function test_break_and_duration_warnings_remain_recorded_without_changing_planned_times(): void
    {
        $shift = $this->shift('2027-05-13T08:00:00+02:00', '2027-05-13T20:00:00+02:00');
        $review = $this->review($shift);
        $this->assertSame(['shift_duration', 'break_missing'], array_column($review['temporal_issues'], 'code'));
        $this->assertSame(720, $review['rules']['shift_minutes']);
        $this->assertSame(30, $review['rules']['minimum_break_minutes']);
        $this->assign($shift, $this->confirmation($shift));
        $this->assertSame(0, $shift->fresh()->planned_break_minutes);
        $this->assertSame(2, count(app(StaffEligibilityService::class)->assessMany($shift, collect([$this->employee]))[$this->employee->id]));
    }

    public function test_full_capacity_and_closed_shift_still_reject_confirmed_exception(): void
    {
        $this->conflictingShift();
        $shift = $this->shift(extra: ['required_staff' => 1]);
        $confirmation = $this->confirmation($shift);
        $otherEmployee = User::factory()->create(['role' => 'staff', 'status' => true]);
        app(ShiftAssignmentService::class)->assign($shift, $otherEmployee, $this->actor);
        $this->assertFalse($this->review($shift)['can_override']);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'reserviert');
        $shift->update(['status' => 'cancelled']);
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'abgeschlossene');
    }

    public function test_a_non_conflicting_candidate_cannot_create_an_exception_audit(): void
    {
        $shift = $this->shift();
        $review = $this->review($shift);
        $this->assertSame([], $review['issues']);
        $this->assertFalse($review['can_override']);
        $this->invalid(fn () => $this->assign($shift, $this->confirmation($shift)), 'Keine freigabefähige');
        $this->assertDatabaseCount('shift_assignments', 0);
        $this->assertDatabaseCount('operation_audits', 0);
    }

    public function test_audited_exception_survives_publication_and_employee_response_without_weakening_general_eligibility(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $assignment = $this->assign($shift, $this->confirmation($shift));
        app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->actor);
        $this->assertSame(ShiftAssignmentStatus::Requested, $assignment->fresh()->status);
        app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $this->employee);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignment->fresh()->status);
        $this->assertSame(1, OperationAudit::where('action', 'assignment.temporal_exception')->count());
        $this->invalid(fn () => app(StaffEligibilityService::class)->assertEligible($shift->fresh(), $this->employee), 'Ruhezeit');
    }

    public function test_publication_rejects_changed_rule_basis_and_retains_original_assignment(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $assignment = $this->assign($shift, $this->confirmation($shift));
        $this->profile->update(['minimum_rest_minutes' => 720]);
        $this->invalid(fn () => app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->actor), 'aktuelle');
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $assignment->fresh()->status);
        $this->assertSame(0, $shift->fresh()->published_revision);
    }

    public function test_response_rejects_changed_conflict_or_missing_qualification_after_publication(): void
    {
        $conflict = $this->conflictingShift();
        $shift = $this->shift();
        $assignment = $this->assign($shift, $this->confirmation($shift));
        app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->actor);
        $conflict->update(['location_name' => 'Hamburg']);
        $this->invalid(fn () => app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $this->employee), 'aktuelle');
        $this->assertSame(ShiftAssignmentStatus::Requested, $assignment->fresh()->status);
        $conflict->update(['location_name' => 'Bremen Hbf']);
        $type = QualificationType::create(['name' => 'Neuer Sicherheitsnachweis', 'is_active' => true]);
        $shift->qualifications()->attach($type);
        $this->invalid(fn () => app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $this->employee), 'Nachweis');
    }

    public function test_cancellation_and_later_unreviewed_reassignment_cannot_revive_an_old_exception(): void
    {
        $this->conflictingShift();
        $shift = $this->shift();
        $assignment = $this->assign($shift, $this->confirmation($shift));
        app(ShiftAssignmentService::class)->cancel($assignment, $this->actor);
        // Simulate an unrelated legacy writer: the previous audit must not grant another assignment a waiver.
        $assignment->refresh()->update(['status' => 'requested']);
        $this->invalid(fn () => app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->actor), 'aktuelle');
    }

    public function test_regional_no_go_blocks_normal_and_exception_assignment(): void
    {
        (require database_path('migrations/2026_10_06_220000_create_staff_regional_preferences_table.php'))->up();
        $this->conflictingShift();
        $shift = $this->shift(extra: ['location_name' => 'München']);
        $confirmation = $this->confirmation($shift);
        app(StaffRegionalPreferenceService::class)->save($this->employee, $this->actor, [
            'enabled' => true, 'base_location' => 'München', 'preferred_radius_km' => 50,
            'border_radius_km' => 100,
            'no_go_areas' => [['location' => 'München', 'radius_km' => 10]],
        ], 0);
        $this->invalid(fn () => app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->actor), 'No-Go');
        $this->invalid(fn () => $this->assign($shift, $confirmation), 'No-Go');
        $this->assertFalse($this->review($shift)['can_override']);
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    public function test_both_named_duties_can_be_published_and_answered_with_one_unchanged_pair_approval(): void
    {
        $original = $this->conflictingShift();
        $originalAssignment = $original->assignments()->sole();
        $second = $this->shift();
        $secondAssignment = $this->assign($second, $this->confirmation($second));
        $publication = app(PlanPublicationService::class);
        $publication->publish($original, $original->revision, $this->actor);
        $publication->publish($second, $second->revision, $this->actor);
        $publication->respond($originalAssignment->id, $original->revision, true, $this->employee);
        $publication->respond($secondAssignment->id, $second->revision, true, $this->employee);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $originalAssignment->fresh()->status);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $secondAssignment->fresh()->status);
        $this->assertSame(1, OperationAudit::where('action', 'assignment.temporal_exception')->count());
    }

    public function test_reciprocal_approval_does_not_cover_a_third_duty_or_changed_original_interval(): void
    {
        $original = $this->conflictingShift();
        $second = $this->shift();
        $this->assign($second, $this->confirmation($second));
        $third = $this->shift('2027-05-13T09:00:00+02:00', '2027-05-13T10:00:00+02:00');
        ShiftAssignment::create(['shift_id' => $third->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->actor->id]);
        $this->invalid(fn () => app(PlanPublicationService::class)->publish($original, $original->revision, $this->actor), 'aktuelle');
        $third->assignments()->update(['status' => 'cancelled']);
        $original->update(['ends_at' => '2027-05-13T12:30:00+02:00']);
        $this->invalid(fn () => app(PlanPublicationService::class)->publish($original, $original->revision, $this->actor), 'aktuelle');
        $original->update(['ends_at' => '2027-05-13T12:00:00+02:00']);
        $originalAssignment = $original->assignments()->sole();
        app(ShiftAssignmentService::class)->cancel($originalAssignment, $this->actor);
        $originalAssignment->refresh()->update(['status' => 'requested']);
        $this->invalid(fn () => app(PlanPublicationService::class)->publish($original, $original->revision, $this->actor), 'aktuelle');
    }

    private function approvedPair(): array
    {
        $first = $this->conflictingShift();
        $second = $this->shift();
        $secondAssignment = $this->assign($second, $this->confirmation($second));
        $firstAssignment = $first->assignments()->sole();
        $publication = app(PlanPublicationService::class);
        foreach ([[$first, $firstAssignment], [$second, $secondAssignment]] as [$shift, $assignment]) {
            $publication->publish($shift, $shift->revision, $this->actor);
            $publication->respond($assignment->id, $shift->revision, true, $this->employee);
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-13T11:00:00+02:00'));

        return [$first->fresh(), $firstAssignment->fresh(), $second->fresh(), $secondAssignment->fresh()];
    }

    public function test_approved_pair_can_start_one_capture_but_never_two_running_or_paused_captures(): void
    {
        [$first, $firstAssignment, $second, $secondAssignment] = $this->approvedPair();
        $service = app(WorkTimeService::class);
        $entry = $service->start($firstAssignment->id, $first->revision, (string) Str::uuid(), $this->employee);
        $this->assertSame('running', $entry->status);
        $this->assertSame($firstAssignment->id, $entry->shift_assignment_id);
        $this->invalid(fn () => $service->start($secondAssignment->id, $second->revision, (string) Str::uuid(), $this->employee), 'bereits eine Zeiterfassung');
        $service->clock($entry->id, $entry->revision, 'pause', (string) Str::uuid(), $this->employee);
        $this->assertSame('paused', $entry->fresh()->status);
        $this->invalid(fn () => $service->start($secondAssignment->id, $second->revision, (string) Str::uuid(), $this->employee), 'bereits eine Zeiterfassung');
        $this->assertDatabaseCount('work_time_entries', 1);
    }

    public function test_changed_rules_or_qualification_still_block_first_capture_of_exception_duty(): void
    {
        [, , $second, $secondAssignment] = $this->approvedPair();
        $service = app(WorkTimeService::class);
        $this->profile->update(['minimum_rest_minutes' => 720]);
        $this->invalid(fn () => $service->start($secondAssignment->id, $second->revision, (string) Str::uuid(), $this->employee), 'aktuelle');
        $this->profile->update(['minimum_rest_minutes' => 660]);
        $type = QualificationType::create(['name' => 'Fehlender Sicherheitsnachweis', 'is_active' => true]);
        $second->qualifications()->attach($type);
        $this->invalid(fn () => $service->start($secondAssignment->id, $second->revision, (string) Str::uuid(), $this->employee), 'Nachweis');
        $this->assertDatabaseCount('work_time_entries', 0);
    }

    public function test_unapproved_overlap_cannot_start_capture_and_approved_capture_does_not_allow_duplicate_actual_times(): void
    {
        [$first, $firstAssignment, $second, $secondAssignment] = $this->approvedPair();
        OperationAudit::where('action', 'assignment.temporal_exception')->delete();
        $service = app(WorkTimeService::class);
        $this->invalid(fn () => $service->start($firstAssignment->id, $first->revision, (string) Str::uuid(), $this->employee), 'aktuelle');
        $this->assertDatabaseCount('work_time_entries', 0);
        // Existing actual-time validation stays independent of a planning decision.
        $this->travelTo(CarbonImmutable::parse('2027-05-13T14:00:00+02:00'));
        $service->manual($firstAssignment->id, $first->revision, ['starts_at' => '2027-05-13T11:00', 'ends_at' => '2027-05-13T12:00', 'pause_minutes' => 0, 'note' => 'Tatsächliche gemeinsame Zugabfertigung.'], (string) Str::uuid(), $this->employee);
        $this->invalid(fn () => $service->manual($secondAssignment->id, $second->revision, ['starts_at' => '2027-05-13T11:30', 'ends_at' => '2027-05-13T12:30', 'pause_minutes' => 0, 'note' => 'Keine doppelte Zeitbuchung zulassen.'], (string) Str::uuid(), $this->employee), 'überschneid');
        $this->assertDatabaseCount('work_time_entries', 1);
        $this->assertSame(3600, WorkTimeEntry::sole()->netSeconds());
    }
}
