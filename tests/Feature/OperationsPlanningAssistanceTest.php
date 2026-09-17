<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\MyWork;
use App\Livewire\Operations\PersonnelReview;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\OperationAudit;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanChangeService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\StaffEligibilityService;
use App\Services\Operations\WorkTimeService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsPlanningAssistanceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Shift $shift;

    private QualificationType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(now()->setDate(2027, 5, 12)->setTime(7, 0)->utc());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Test', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
        $customer = Customer::create(['company_name' => 'QA Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'QA Leistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now(), 'ends_at' => now()->addDays(10), 'required_staff' => 2, 'created_by' => $this->admin->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'QA Dienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHours(8), 'required_staff' => 2, 'planned_break_minutes' => 30, 'status' => 'open', 'location_name' => 'Hamburg', 'created_by' => $this->admin->id]);
        $this->type = QualificationType::create(['name' => 'Baureihe 185', 'is_active' => true]);
        $this->shift->qualifications()->attach($this->type);
        $this->shift->refresh();
    }

    private function certificate(string $until = '2028-05-12'): EmployeeQualification
    {
        return EmployeeQualification::create(['user_id' => $this->employee->id, 'qualification_type_id' => $this->type->id, 'valid_from' => '2027-01-01', 'valid_until' => $until, 'status' => 'approved']);
    }

    private function publish(): ShiftAssignment
    {
        $assignment = app(ShiftAssignmentService::class)->assign($this->shift, $this->employee, $this->admin);
        app(PlanPublicationService::class)->publish($this->shift, $this->shift->revision, $this->admin);

        return $assignment->fresh();
    }

    public function test_candidate_preview_collects_reasons_and_write_guard_enforces_the_same_rules(): void
    {
        AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'timezone' => 'Europe/Berlin', 'status' => 'approved']);
        $issues = app(StaffEligibilityService::class)->assessMany($this->shift, collect([$this->employee]))[$this->employee->id];
        $this->assertSame(['absence', 'qualification_'.$this->type->id], array_column($issues, 'code'));
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('openDetails', $this->shift->id)
            ->assertSee('Gültiger Nachweis fehlt: Baureihe 185.')
            ->call('chooseCandidate', $this->employee->id)->assertHasErrors('workflow');
        $this->expectException(ValidationException::class);
        app(ShiftAssignmentService::class)->assign($this->shift, $this->employee, $this->admin);
    }

    public function test_revocation_persists_and_creates_actionable_conflict_and_blocks_start(): void
    {
        $certificate = $this->certificate();
        $assignment = $this->publish();
        app(PlanPublicationService::class)->respond($assignment->id, $assignment->plan_revision, true, $this->employee);
        app(PersonnelWorkflowService::class)->qualification($certificate, 1, 'revoke', 'Nachweis zurückgezogen', $this->admin);
        $this->assertSame('revoked', $certificate->fresh()->status);
        $this->assertSame('confirmed', $assignment->fresh()->status->value);
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->set('attentionFilter', 'conflicts')
            ->assertViewHas('shifts', fn ($items) => $items->count() === 1 && $items->first()->planning_conflict_count === 1);
        $this->expectException(ValidationException::class);
        app(WorkTimeService::class)->start($assignment->id, $assignment->plan_revision, (string) Str::uuid(), $this->employee);
    }

    public function test_replacement_certificate_keeps_assignment_eligible_after_revocation(): void
    {
        $old = $this->certificate();
        $this->certificate();
        $this->publish();
        app(PersonnelWorkflowService::class)->qualification($old, 1, 'revoke', 'Ersetzt', $this->admin);
        $this->assertSame([], app(StaffEligibilityService::class)->assessMany($this->shift, collect([$this->employee]))[$this->employee->id]);
    }

    public function test_expiry_filters_include_today_and_boundary_without_pending_records(): void
    {
        $expired = $this->certificate('2027-05-11');
        $today = $this->certificate('2027-05-12');
        $boundary = $this->certificate('2027-06-11');
        $later = $this->certificate('2027-06-12');
        $pending = $this->certificate('2027-05-20');
        $pending->update(['status' => 'pending']);
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications'])
            ->set('validity', '30')->assertSet('filter', 'approved')
            ->assertViewHas('records', fn ($records) => $records->pluck('id')->sort()->values()->all() === [$today->id, $boundary->id])
            ->set('validity', 'expired')->assertViewHas('records', fn ($records) => $records->pluck('id')->all() === [$expired->id])
            ->set('filter', 'pending')->assertSet('validity', 'all');
        $this->assertSame('Gültig', $later->validityLabel());
        $this->assertSame('Abgelaufen', $expired->validityLabel());
    }

    public function test_opening_is_idempotent_revision_scoped_and_not_acceptance(): void
    {
        $this->certificate();
        $assignment = $this->publish();
        $service = app(PlanChangeService::class);
        $service->opened($assignment->id, 1, $this->employee);
        $service->opened($assignment->id, 1, $this->employee);
        $this->assertSame(1, OperationAudit::where('action', 'assignment.opened')->count());
        $this->assertSame('requested', $assignment->fresh()->status->value);
        $openedAt = now()->utc()->format('Y-m-d H:i');
        config(['app.timezone' => 'Europe/Berlin']);
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->call('openDetails', $this->shift->id)
            ->assertViewHas('feedback', fn ($items) => $items->first()->plan_opened_at->format('Y-m-d H:i') === $openedAt);
        auth()->forgetGuards();
        config(['app.timezone' => 'UTC']);
        $this->shift->refresh()->forceFill(['revision' => 2, 'title' => 'Neuer Titel'])->save();
        // Unpublished changes do not invalidate opening the previous visible revision.
        $service->opened($assignment->id, 1, $this->employee);
        app(PlanPublicationService::class)->publish($this->shift->fresh(), 2, $this->admin);
        $this->assertNull($service->opening($assignment->id, 2));
        $this->assertSame('Titel', collect($service->publishedChanges($this->shift->id, 2))->firstWhere('field', 'title')['label']);
        $service->opened($assignment->id, 2, $this->employee);
        $this->assertSame(2, OperationAudit::where('action', 'assignment.opened')->count());
    }

    public function test_calendar_opening_does_not_expose_unpublished_changes(): void
    {
        $this->certificate();
        $assignment = $this->publish();
        $this->shift->refresh()->forceFill(['revision' => 2, 'title' => 'GEHEIMER ENTWURF'])->save();
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'schedule')
            ->call('openCalendarEvent', 'shift-'.$assignment->id)->assertSet('calendarEventOpen', true)
            ->assertSee('QA Dienst')->assertDontSee('GEHEIMER ENTWURF');
        $this->assertNotNull(app(PlanChangeService::class)->opening($assignment->id, 1));
    }

    public function test_foreign_employee_cannot_open_or_manage_another_plan(): void
    {
        $this->certificate();
        $assignment = $this->publish();
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($other)->test(MyWork::class)->call('openCalendarEvent', 'shift-'.$assignment->id)->assertStatus(404);
        Livewire::actingAs($other)->test(ShiftManagement::class)->assertForbidden();
        $this->assertSame(0, OperationAudit::where('action', 'assignment.opened')->count());
    }

    public function test_feedback_filter_distinguishes_published_requests_and_declines(): void
    {
        $this->certificate();
        $assignment = $this->publish();
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->set('attentionFilter', 'awaiting')
            ->assertViewHas('shifts', fn ($items) => $items->count() === 1);
        app(PlanPublicationService::class)->respond($assignment->id, 1, false, $this->employee);
        Livewire::actingAs($this->admin)->test(ShiftManagement::class)->set('attentionFilter', 'awaiting')
            ->assertViewHas('shifts', fn ($items) => $items->isEmpty())->set('attentionFilter', 'declined')
            ->assertViewHas('shifts', fn ($items) => $items->count() === 1)->call('openDetails', $this->shift->id)->assertSee($this->employee->name);
    }
}
