<?php

namespace Tests\Feature;

use App\Livewire\Operations\DutyActivity;
use App\Livewire\Operations\OrderDemands;
use App\Livewire\Operations\PersonnelReview;
use App\Livewire\Operations\ShiftSeriesPlanner;
use App\Livewire\Operations\StaffTimeline;
use App\Livewire\Operations\TimeReview;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\DutyReport;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftSection;
use App\Models\ShiftSeries;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Operations\DutyActivityService;
use App\Services\Operations\OrderDemandService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\ShiftSeriesService;
use App\Services\Operations\WorkTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsRoadmapTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12T07:00:00+02:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'QA', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
        $customer = Customer::create(['company_name' => 'Test Rail', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Jahresleistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'required_staff' => 2, 'created_by' => $this->admin->id]);
    }

    private function shift(): Shift
    {
        return app(ShiftSchedulingService::class)->save(new Shift, ['order_id' => $this->order->id, 'title' => 'Testdienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => CarbonImmutable::parse('2027-05-13T08:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-05-13T16:00:00+02:00'), 'required_staff' => 2, 'planned_break_minutes' => 30, 'status' => 'open'], $this->admin);
    }

    private function template(array $overrides = [])
    {
        return app(ShiftSeriesService::class)->saveTemplate(null, null, array_merge(['name' => 'Frühdienst', 'title' => 'Seriendienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'start_time' => '08:00', 'end_time' => '16:00', 'end_next_day' => false, 'required_staff' => 2, 'planned_break_minutes' => 30, 'qualification_ids' => []], $overrides), $this->admin);
    }

    private function request(int $templateId, array $overrides = []): array
    {
        return array_merge(['template_id' => $templateId, 'order_id' => $this->order->id, 'from' => '2027-05-13', 'until' => '2027-05-15', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'exceptions' => ['2027-05-14']], $overrides);
    }

    public function test_series_preview_exceptions_generate_only_drafts_and_retry_without_duplicates(): void
    {
        $service = app(ShiftSeriesService::class);
        $request = $this->request($this->template()->id);
        $preview = $service->preview($request, $this->admin);
        $this->assertSame(['2027-05-13', '2027-05-15'], array_column($preview['rows'], 'date'));
        $key = (string) Str::uuid();
        $series = $service->generate($request, $preview['fingerprint'], $key, $this->admin);
        $again = $service->generate($request, $preview['fingerprint'], $key, $this->admin);
        $this->assertSame($series->id, $again->id);
        $this->assertSame(2, Shift::count());
        $this->assertSame(2, Shift::where('status', 'draft')->where('published_revision', 0)->count());
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_changed_template_invalidates_preview_atomically(): void
    {
        $template = $this->template();
        $service = app(ShiftSeriesService::class);
        $request = $this->request($template->id);
        $preview = $service->preview($request, $this->admin);
        $service->saveTemplate($template->id, 1, $template->definition + ['name' => 'Geändert'], $this->admin);
        try {
            $service->generate($request, $preview['fingerprint'], (string) Str::uuid(), $this->admin);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, ShiftSeries::count());
    }

    public function test_dst_ambiguity_is_visible_and_never_silently_generated(): void
    {
        $request = $this->request($this->template(['start_time' => '02:30', 'end_time' => '08:00'])->id, ['from' => '2027-10-31', 'until' => '2027-10-31', 'exceptions' => []]);
        $service = app(ShiftSeriesService::class);
        $preview = $service->preview($request, $this->admin);
        $this->assertNotNull($preview['rows'][0]['error']);
        $this->expectException(ValidationException::class);
        $service->generate($request, $preview['fingerprint'], (string) Str::uuid(), $this->admin);
    }

    public function test_night_series_uses_next_local_day(): void
    {
        $request = $this->request($this->template(['start_time' => '22:00', 'end_time' => '06:00', 'end_next_day' => true])->id);
        $preview = app(ShiftSeriesService::class)->preview($request, $this->admin);
        $this->assertNull($preview['rows'][0]['error']);
        $this->assertSame('2027-05-14T04:00:00+00:00', $preview['rows'][0]['ends_at']);
    }

    public function test_demand_exists_without_shift_and_generation_is_capacity_guarded(): void
    {
        $service = app(OrderDemandService::class);
        $demand = $service->save($this->order->id, null, null, ['role_name' => 'Tf', 'required_staff' => 2, 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'timezone' => 'Europe/Berlin'], $this->admin);
        $this->assertSame(0, Shift::count());
        $this->assertSame(2, $service->coverage($demand)['open']);
        $shift = $service->generate($demand->id, 1, 30, $this->admin);
        $this->assertSame('draft', $shift->status->value);
        $this->assertSame(['planned' => 2, 'confirmed' => 0, 'open' => 0], $service->coverage($demand));
        try {
            $service->generate($demand->id, 1, 30, $this->admin);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame(1, Shift::count());
    }

    public function test_published_sections_are_isolated_from_later_drafts_and_foreign_employees(): void
    {
        $shift = $this->shift();
        $service = app(DutyActivityService::class);
        $service->section($shift->id, 1, null, ['kind' => 'preparation', 'label' => 'Sichtprüfung', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T08:30'], $this->admin);
        $assignment = app(ShiftAssignmentService::class)->assign($shift->fresh(), $this->employee, $this->admin);
        app(PlanPublicationService::class)->publish($shift->fresh(), 2, $this->admin);
        $section = ShiftSection::first();
        $service->section($shift->id, 2, $section->id, ['kind' => 'preparation', 'label' => 'GEHEIMER ENTWURF', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T08:30'], $this->admin);
        Livewire::actingAs($this->employee)->test(DutyActivity::class, ['shiftId' => $shift->id, 'employeeMode' => true])->assertSee('Sichtprüfung')->assertDontSee('GEHEIMER ENTWURF')->call('editSection')->assertForbidden();
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        Livewire::actingAs($other)->test(DutyActivity::class, ['shiftId' => $shift->id, 'employeeMode' => true])->assertStatus(404);
        $this->assertSame(2, $assignment->fresh()->plan_revision);
    }

    public function test_overlapping_sections_and_plan_shrinking_are_rejected(): void
    {
        $shift = $this->shift();
        $service = app(DutyActivityService::class);
        $section = ['kind' => 'travel', 'label' => 'Fahrt', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T12:00'];
        $service->section($shift->id, 1, null, $section, $this->admin);
        try {
            $service->section($shift->id, 2, null, $section, $this->admin);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame(1, ShiftSection::count());
        $this->expectException(ValidationException::class);
        app(ShiftSchedulingService::class)->save($shift->fresh(), ['starts_at' => CarbonImmutable::parse('2027-05-13T09:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-05-13T16:00:00+02:00'), 'expected_revision' => 2], $this->admin);
    }

    public function test_employee_report_is_idempotent_and_only_management_can_resolve(): void
    {
        $shift = $this->shift();
        app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->admin);
        app(PlanPublicationService::class)->publish($shift, 1, $this->admin);
        $service = app(DutyActivityService::class);
        $data = ['kind' => 'delay', 'message' => 'Signalstörung im Abschnitt', 'delay_minutes' => 12];
        $key = (string) Str::uuid();
        $report = $service->report($shift->id, 1, $data, $key, $this->employee);
        $service->report($shift->id, 1, $data, $key, $this->employee);
        $this->assertSame(1, DutyReport::count());
        Livewire::actingAs($this->employee)->test(DutyActivity::class, ['shiftId' => $shift->id, 'employeeMode' => true])->call('openResolution', $report->id)->assertForbidden();
        $service->resolve($report->id, 1, 'Disposition hat Ablösung organisiert.', $this->admin);
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    private function timeEntry(int $offset = 0): WorkTimeEntry
    {
        $shift = $this->shift();
        $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);

        return WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $this->employee->id, 'status' => 'submitted', 'timezone' => 'Europe/Berlin', 'starts_at' => CarbonImmutable::parse('2027-05-13T08:00:00+02:00')->addDays($offset), 'ends_at' => CarbonImmutable::parse('2027-05-13T16:00:00+02:00')->addDays($offset), 'pause_seconds' => 1800, 'plan_snapshot' => ['title' => 'Zeitprüfung QA', 'starts_at' => CarbonImmutable::parse('2027-05-13T06:00:00Z')->addDays($offset)->toIso8601String(), 'ends_at' => CarbonImmutable::parse('2027-05-13T14:00:00Z')->addDays($offset)->toIso8601String(), 'planned_break_minutes' => 30]]);
    }

    public function test_time_comparison_uses_instants_not_timezone_string_and_batch_is_atomic(): void
    {
        $one = $this->timeEntry();
        $two = $this->timeEntry(1);
        $service = app(WorkTimeService::class);
        $this->assertSame([], $service->warnings($one));
        $this->assertSame(0, $service->comparison($one)['delta']);
        try {
            $service->reviewBatch([['id' => $one->id, 'revision' => 1], ['id' => $two->id, 'revision' => 99]], true, '', $this->admin);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame('submitted', $one->fresh()->status);
        $service->reviewBatch([['id' => $one->id, 'revision' => 1], ['id' => $two->id, 'revision' => 1]], true, '', $this->admin);
        $this->assertSame(2, WorkTimeEntry::where('status', 'approved')->count());
    }

    public function test_batch_cannot_auto_approve_deviations_or_own_entries(): void
    {
        $entry = $this->timeEntry();
        $entry->update(['pause_seconds' => 1200]);
        $this->assertSame(600, app(WorkTimeService::class)->comparison($entry)['delta']);
        try {
            app(WorkTimeService::class)->reviewBatch([['id' => $entry->id, 'revision' => 1]], true, 'Begründung', $this->admin);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame('submitted', $entry->fresh()->status);
        app(WorkTimeService::class)->reviewBatch([['id' => $entry->id, 'revision' => 1]], false, 'Pause bitte nachprüfen.', $this->admin);
        $this->assertSame('returned', $entry->fresh()->status);
    }

    public function test_expiring_certificate_lists_only_future_uncovered_services(): void
    {
        $shift = $this->shift();
        $type = QualificationType::create(['name' => 'Baureihe', 'is_active' => true]);
        $shift->qualifications()->attach($type);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);
        $certificate = EmployeeQualification::create(['user_id' => $this->employee->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2027-05-12', 'status' => 'approved']);
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications'])->call('openDetails', $certificate->id)->assertViewHas('affectedShifts', fn ($items) => $items->count() === 1);
        EmployeeQualification::create(['user_id' => $this->employee->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-05-13', 'valid_until' => '2028-01-01', 'status' => 'approved']);
        Livewire::actingAs($this->admin)->test(PersonnelReview::class, ['module' => 'qualifications'])->call('openDetails', $certificate->id)->assertViewHas('affectedShifts', fn ($items) => $items->isEmpty());
    }

    public function test_timeline_contains_shifts_absences_and_unoccupied_intervals(): void
    {
        $shift = $this->shift();
        app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->admin);
        AbsenceRequest::create(['user_id' => $this->employee->id, 'kind' => 'vacation', 'starts_at' => CarbonImmutable::parse('2027-05-14T00:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-05-15T00:00:00+02:00'), 'timezone' => 'Europe/Berlin', 'status' => 'approved']);
        Livewire::actingAs($this->admin)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-14'])->assertSee('Testdienst')->assertSee('Urlaub')->assertSee('Unbelegt 00:00 – 08:00');
        Livewire::actingAs($this->employee)->test(StaffTimeline::class, ['from' => '2027-05-13', 'until' => '2027-05-14'])->assertForbidden();
    }

    public function test_new_management_components_render_and_enforce_roles(): void
    {
        $this->template();
        Livewire::actingAs($this->admin)->test(ShiftSeriesPlanner::class)->call('newSeries')->assertSet('seriesOpen', true)->assertSee('Vorschau prüfen');
        Livewire::actingAs($this->admin)->test(OrderDemands::class, ['orderId' => $this->order->id])->call('edit')->assertSet('formOpen', true);
        $entry = $this->timeEntry();
        Livewire::actingAs($this->admin)->test(TimeReview::class)->set('selected', [$entry->id])->call('prepareBatch')->assertSet('batchOpen', true)->assertSee('Auswahl freigeben')->call('reviewBatch', true)->assertSet('batchOpen', false);
        Livewire::actingAs($this->employee)->test(ShiftSeriesPlanner::class)->assertForbidden();
        Livewire::actingAs($this->employee)->test(OrderDemands::class,['orderId' => $this->order->id])->assertForbidden();
    }

    public function test_additive_migration_rolls_back_without_removing_original_data(): void
    {
        $shift = $this->shift();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->down();
        $this->assertFalse(Schema::hasTable('shift_templates'));
        $this->assertFalse(Schema::hasColumn('shifts','order_demand_id'));
        $this->assertSame($shift->id,Shift::first()->id);
        $this->assertSame(1,Order::count());
    }
}
