<?php

namespace Tests\Feature;

use App\Livewire\Operations\InquiryInbox;
use App\Livewire\Operations\MyWork;
use App\Livewire\Operations\PersonnelReview;
use App\Livewire\Operations\TimeReview;
use App\Livewire\Operations\Workspace;
use App\Models\Customer;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Services\Operations\InquiryWorkflowService;
use App\Services\Operations\PersonnelWorkflowService;
use App\Services\Operations\PlanPublicationService;
use App\Services\Operations\ShiftAssignmentService;
use App\Services\Operations\ShiftSchedulingService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class NativeOperationsWorkflowTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected User $admin;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        $this->travelTo(now()->setDate(2027, 5, 12)->setTime(7, 0)->utc());
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Testprofil', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
    }

    private function shift(array $attributes = []): Shift
    {
        $customer = Customer::create(['company_name' => 'Test Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Testleistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3), 'required_staff' => 1, 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id]);

        return app(ShiftSchedulingService::class)->save(new Shift, array_merge(['order_id' => $order->id, 'title' => 'Testdienst', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(9), 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'open', 'location_name' => 'Hamburg'], $attributes), $this->admin);
    }

    private function assignment(): ShiftAssignment
    {
        $shift = $this->shift();
        $assignment = app(ShiftAssignmentService::class)->assign($shift, $this->employee, $this->admin);
        app(PlanPublicationService::class)->publish($shift, $shift->revision, $this->admin);
        app(PlanPublicationService::class)->respond($assignment->id, $shift->revision, true, $this->employee);

        return $assignment->fresh();
    }

    private function validation(callable $work): void
    {
        try {
            $work();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_inquiry_revision_acceptance_and_exactly_once_conversion(): void
    {
        $customer = Customer::create(['company_name' => 'Kunde', 'is_active' => true]);
        $service = app(InquiryWorkflowService::class);
        $form = ['channel' => 'phone', 'title' => 'Lokdienst', 'original' => 'Kunde fragt an.', 'customer_id' => $customer->id, 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T16:00', 'location_name' => 'Hamburg', 'role_name' => 'Tf', 'required_staff' => 1, 'source_reference' => ''];
        $inquiry = $service->save(null, $form, $this->admin);
        $this->assertSame(1, $inquiry->revision);
        $service->save(null, $form, $this->admin); // Empty external references are not deduplication keys.
        $this->validation(fn () => $service->transition($inquiry, 1, 'convert', [], $this->admin));
        $service->transition($inquiry, 1, 'verify', [], $this->admin);
        $service->transition($inquiry, 1, 'offer', ['amount' => '1250.50', 'terms' => '8 Stunden Dienst'], $this->admin);
        $service->transition($inquiry, 1, 'accept', ['note' => 'Zusage durch Kundenkontakt am 12.05.2027', 'authorized' => true], $this->admin);
        $converted = $service->transition($inquiry, 1, 'convert', [], $this->admin);
        $same = $service->transition($inquiry, 1, 'convert', [], $this->admin);
        $this->assertSame($converted->order_id, $same->order_id);
        $this->assertSame(1, Order::count());
        $this->assertSame(125050, $converted->offer['amount_cents']);
        $this->validation(fn () => $service->save($converted, $form, $this->admin, 1));
        $this->assertDatabaseHas('operation_audits', ['subject_id' => $inquiry->id, 'action' => 'inquiry.convert']);
    }

    public function test_changed_demand_invalidates_the_offer_and_old_revision(): void
    {
        $service = app(InquiryWorkflowService::class);
        $form = ['channel' => 'manual', 'title' => 'A', 'original' => 'Original', 'timezone' => 'Europe/Berlin'];
        $inquiry = $service->save(null, $form, $this->admin);
        $updated = $service->save($inquiry, array_merge($form, ['title' => 'B', 'original' => 'Manipuliert']), $this->admin, 1);
        $this->assertSame('Original', $updated->original);
        $this->assertSame(2, $updated->revision);
        $this->validation(fn () => $service->save($inquiry, $form, $this->admin, 1));
    }

    public function test_dst_gaps_and_ambiguous_wall_times_are_rejected(): void
    {
        $this->validation(fn () => OperationsDateTime::local('2027-03-28T02:30', 'Europe/Berlin'));
        $this->validation(fn () => OperationsDateTime::local('2027-10-31T02:30', 'Europe/Berlin'));
        $this->assertSame('2027-05-12 06:00:00', OperationsDateTime::local('2027-05-12T08:00', 'Europe/Berlin')->format('Y-m-d H:i:s'));
    }

    public function test_qualifications_absences_and_rest_block_invalid_assignments(): void
    {
        $shift = $this->shift();
        $type = QualificationType::create(['name' => 'Baureihe 185']);
        app(PlanPublicationService::class)->requirements($shift, $shift->revision, [$type->id], $this->admin);
        $shift->refresh();
        $assign = app(ShiftAssignmentService::class);
        $this->validation(fn () => $assign->assign($shift, $this->employee, $this->admin));
        EmployeeQualification::create(['user_id' => $this->employee->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-05-01', 'valid_until' => '2027-06-01', 'status' => 'approved']);
        $assignment = $assign->assign($shift, $this->employee, $this->admin);
        $request = app(PersonnelWorkflowService::class)->requestAbsence($this->employee, ['kind' => 'vacation', 'starts_at' => $shift->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $shift->ends_at->format('Y-m-d\TH:i'), 'timezone' => 'Europe/Berlin']);
        $this->validation(fn () => app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->admin));
        $assign->cancel($assignment, $this->admin);
        app(PersonnelWorkflowService::class)->absence($request, 1, 'approve', '', $this->admin);
        $this->validation(fn () => $assign->assign($shift, $this->employee, $this->admin));
    }

    public function test_published_revision_is_required_and_plan_edits_require_new_response(): void
    {
        $assignment = $this->assignment();
        $shift = $assignment->shift;
        $changed = app(ShiftSchedulingService::class)->save($shift, ['title' => 'Neuer Dienst', 'expected_revision' => 1], $this->admin);
        $this->assertSame(2, $changed->revision);
        $this->validation(fn () => app(WorkTimeService::class)->start($assignment->id, 1, (string) Str::uuid(), $this->employee));
        app(PlanPublicationService::class)->publish($changed, 2, $this->admin);
        $this->assertSame('requested', $assignment->fresh()->status->value);
        $this->assertSame(2, $assignment->fresh()->plan_revision);
    }

    public function test_time_clock_idempotency_return_correction_approval_and_export(): void
    {
        $assignment = $this->assignment();
        $service = app(WorkTimeService::class);
        $key = (string) Str::uuid();
        $entry = $service->start($assignment->id, 1, $key, $this->employee);
        $this->assertSame($entry->id, $service->start($assignment->id, 1, $key, $this->employee)->id);
        $this->travel(240)->minutes();
        $entry = $service->clock($entry->id, $entry->revision, 'pause', (string) Str::uuid(), $this->employee);
        $this->travel(30)->minutes();
        $entry = $service->clock($entry->id, $entry->revision, 'resume', (string) Str::uuid(), $this->employee);
        $this->travel(240)->minutes();
        $entry = $service->clock($entry->id, $entry->revision, 'stop', (string) Str::uuid(), $this->employee);
        $this->assertSame(28800, $entry->netSeconds());
        $entry = $service->clock($entry->id, $entry->revision, 'submit', (string) Str::uuid(), $this->employee);
        $this->assertSame($entry->revision, $entry->revisions()->where('action', 'submitted')->latest('id')->first()->revision);
        $service->review($entry->id, $entry->revision, false, 'Beginn bitte prüfen.', $this->admin);
        $entry->refresh();
        $service->correct($entry->id, $entry->revision, ['starts_at' => $entry->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $entry->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => 30, 'note' => 'Beginn anhand Dienstbericht bestätigt.'], $this->employee);
        $entry->refresh();
        $entry = $service->clock($entry->id, $entry->revision, 'submit', (string) Str::uuid(), $this->employee);
        $this->validation(fn () => $service->review($entry->id, $entry->revision, true, '', $this->admin));
        $service->review($entry->id, $entry->revision, true, 'Abweichung geprüft und bestätigt.', $this->admin);
        $export = $service->export([$entry->id], $this->admin);
        $csv = $service->csv($export, $this->admin);
        $this->assertStringContainsString('28800', $csv);
        $this->assertStringContainsString($assignment->shift->order->order_number, $csv);
        $this->validation(fn () => $service->export([$entry->id], $this->admin));
        $this->validation(fn () => $service->correct($entry->id, $entry->fresh()->revision, [], $this->employee));
        $this->assertGreaterThanOrEqual(5, $entry->revisions()->count());
    }

    public function test_employee_cannot_access_reviews_or_other_employees_data(): void
    {
        $this->actingAs($this->employee);
        Livewire::test(Workspace::class, ['module' => 'inquiries'])->assertForbidden();
        Livewire::test(TimeReview::class)->assertForbidden();
        Livewire::test(PersonnelReview::class, ['module' => 'qualifications'])->assertForbidden();
        $other = User::factory()->create(['role' => 'staff']);
        $this->actingAs($this->admin);
        $assignment = app(ShiftAssignmentService::class)->assign($this->shift(), $other, $this->admin);
        $this->actingAs($this->employee);
        Livewire::test(MyWork::class)->assertDontSee('Testdienst');
        $this->expectException(ModelNotFoundException::class);
        app(WorkTimeService::class)->start($assignment->id, 1, (string) Str::uuid(), $this->employee);
    }

    public function test_private_evidence_is_only_available_to_owner_or_reviewer(): void
    {
        Storage::fake('local');
        $type = QualificationType::create(['name' => 'Tf-Schein']);
        $record = app(PersonnelWorkflowService::class)->submitQualification($this->employee, ['qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2028-01-01'], UploadedFile::fake()->create('nachweis.pdf', 20, 'application/pdf'));
        $this->actingAs($this->employee)->get(route('operations.evidence', $record->id))->assertOk();
        $other = User::factory()->create(['role' => 'staff']);
        $this->actingAs($other)->get(route('operations.evidence', $record->id))->assertForbidden();
        $this->actingAs($this->admin)->get(route('operations.evidence', $record->id))->assertOk();
    }

    public function test_native_screens_render_without_reference_or_concept_copy(): void
    {
        $this->actingAs($this->admin);
        foreach (['inquiries', 'qualifications', 'absences', 'rules', 'times', 'exports', 'shift-management', 'orders', 'customers', 'calendar'] as $module) {
            Livewire::test(Workspace::class, ['module' => $module])->assertOk()->assertDontSee('WILSON')->assertDontSee('kopiert');
        }
        Livewire::test(InquiryInbox::class)->call('create')->assertSee('Neue Anfrage');
        $this->actingAs($this->employee);
        foreach (['today', 'schedule', 'time', 'records'] as $tab) {
            Livewire::test(MyWork::class)->call('showTab', $tab)->assertOk()->assertDontSee('WILSON');
        }
        $this->get(route('dashboard'))->assertOk()->assertSee('data-native-dashboard', false)->assertDontSee('planning_not_connected');
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertSee('data-operations-cockpit', false);
        $this->get(route('operations.workspace', 'inquiries'))->assertOk();
    }

    public function test_missing_private_evidence_cannot_be_approved(): void
    {
        Storage::fake('local');
        $type = QualificationType::create(['name' => 'Tf-Schein']);
        $service = app(PersonnelWorkflowService::class);
        $record = $service->submitQualification($this->employee, ['qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2028-01-01'], UploadedFile::fake()->create('nachweis.pdf', 20, 'application/pdf'));
        Storage::disk('local')->delete($record->evidence_path);
        $this->validation(fn () => $service->qualification($record, 1, 'approve', '', $this->admin));
        $this->assertSame('pending', $record->fresh()->status);
        $record = $service->submitQualification($this->employee, ['qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2028-01-01'], UploadedFile::fake()->create('nachweis.pdf', 20, 'application/pdf'));
        $service->qualification($record, 1, 'approve', '', $this->admin);
        $this->assertSame('approved', $record->fresh()->status);
    }

    public function test_partial_migration_does_not_enable_operations(): void
    {
        $this->assertTrue(OperationsAccess::ready());
        Schema::drop('work_time_export_items');
        $this->assertFalse(OperationsAccess::ready());
        $this->actingAs($this->employee)->get(route('operations.mine'))->assertStatus(503);
    }

    public function test_migration_rollback_and_legacy_publication_backfill(): void
    {
        $shift = $this->shift();
        $migration = require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php');
        $migration->down();
        $this->assertFalse(OperationsAccess::ready());
        $migration->up();
        $this->assertTrue(OperationsAccess::ready());
        $this->assertSame(1, $shift->fresh()->published_revision);
        $this->assertSame('Testdienst', $shift->fresh()->title);
    }

    public function test_older_personal_qualifications_are_reachable_by_pagination(): void
    {
        for ($index = 1; $index <= 21; $index++) {
            $type = QualificationType::create(['name' => 'Nachweis '.$index]);
            EmployeeQualification::create(['user_id' => $this->employee->id, 'qualification_type_id' => $type->id, 'valid_from' => '2027-01-01', 'valid_until' => '2028-01-01', 'created_at' => now()->subMinutes($index)]);
        }
        Livewire::actingAs($this->employee)->test(MyWork::class)->call('showTab', 'records')
            ->assertViewHas('qualifications', fn ($page) => $page->count() === 20 && ! $page->contains('qualification_type_id', $type->id))
            ->call('setPage', 2, 'qualificationsPage')
            ->assertViewHas('qualifications', fn ($page) => $page->count() === 1 && $page->contains('qualification_type_id', $type->id));
    }

    public function test_published_schedule_remains_visible_without_revealing_draft_changes(): void
    {
        $assignment = $this->assignment();
        $otherShift = $this->shift();
        $otherShift->order->customer->forceFill(['company_name' => 'Unveröffentlichter Kunde'])->save();
        app(ShiftSchedulingService::class)->save($assignment->shift, ['order_id' => $otherShift->order_id, 'title' => 'Unveröffentlichter Entwurf', 'expected_revision' => 1], $this->admin);
        Livewire::actingAs($this->employee)->test(MyWork::class)->assertSee('Testdienst')->assertSee('Test Rail')->assertSee('Planänderung in Prüfung')->assertDontSee('Unveröffentlichter Entwurf')->assertDontSee('Unveröffentlichter Kunde')->assertDontSee('Dienst starten');
    }

    public function test_manual_time_requires_review_and_rejects_duplicates(): void
    {
        $assignment = $this->assignment();
        $this->travel(10)->hours();
        $data = ['starts_at' => $assignment->shift->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $assignment->shift->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => 30, 'note' => 'Dienst nachgetragen, Zeituhr vergessen.'];
        $key = (string) Str::uuid();
        $service = app(WorkTimeService::class);
        $entry = $service->manual($assignment->id, 1, $data, $key, $this->employee);
        $this->assertSame('completed', $entry->status);
        $this->assertSame($entry->id, $service->manual($assignment->id, 1, $data, $key, $this->employee)->id);
        $this->validation(fn () => $service->manual($assignment->id, 1, $data, (string) Str::uuid(), $this->employee));
        $this->validation(fn () => $service->export([$entry->id], $this->admin));
        $this->assertSame(27000, $entry->netSeconds());
    }

    public function test_self_approval_is_forbidden_even_after_promotion_to_admin(): void
    {
        $assignment = $this->assignment();
        $service = app(WorkTimeService::class);
        $entry = $service->start($assignment->id, 1, (string) Str::uuid(), $this->employee);
        $this->travel(60)->minutes();
        $entry = $service->clock($entry->id, 1, 'stop', (string) Str::uuid(), $this->employee);
        $entry = $service->clock($entry->id, $entry->revision, 'submit', (string) Str::uuid(), $this->employee);
        $this->employee->forceFill(['role' => 'admin'])->save();
        $this->expectException(HttpException::class);
        $service->review($entry->id, $entry->revision, true, 'Eigene Freigabe', $this->employee->fresh());
    }

    public function test_rules_absence_and_proof_cannot_invalidate_assignments_silently(): void
    {
        $assignment = $this->assignment();
        $service = app(PersonnelWorkflowService::class);
        $this->validation(fn () => $service->saveRules(['name' => 'Zu kurze Schicht', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 60, 'break_after_minutes' => 30, 'minimum_break_minutes' => 10, 'confirmed' => true], $this->admin));
        $this->assertSame('Testprofil', OperationsRuleProfile::where('is_active', true)->first()->name);
        $next = $this->shift(['starts_at' => $assignment->shift->ends_at->addMinutes(599), 'ends_at' => $assignment->shift->ends_at->addMinutes(659)]);
        $this->validation(fn () => app(ShiftAssignmentService::class)->assign($next, $this->employee, $this->admin));
    }

    public function test_delegated_operation_right_does_not_grant_reviews_or_other_modules(): void
    {
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Verwaltung', 'personal_team' => false, 'rbac_permissions' => ['operations.inquiries.manage' => true]]);
        $this->employee->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($this->employee->fresh());
        Livewire::test(Workspace::class, ['module' => 'inquiries'])->assertOk();
        Livewire::test(Workspace::class, ['module' => 'times'])->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-rt-welcome-intro')->assertSee('Offene Anfragen');
        $this->employee->forceFill(['status' => false])->save();
        $this->actingAs($this->employee->fresh());
        Livewire::test(MyWork::class)->assertForbidden();
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $assignment = $this->assignment();
        $this->travel(10)->hours();
        $this->employee->forceFill(['name' => '=1+1'])->save();
        $service = app(WorkTimeService::class);
        $entry = $service->manual($assignment->id, 1, ['starts_at' => $assignment->shift->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $assignment->shift->ends_at->format('Y-m-d\TH:i'), 'pause_minutes' => 30, 'note' => 'Nachtrag geprüft.'], (string) Str::uuid(), $this->employee);
        $entry = $service->clock($entry->id, $entry->revision, 'submit', (string) Str::uuid(), $this->employee);
        $service->review($entry->id, $entry->revision, true, 'Dienst geprüft.', $this->admin);
        $this->assertStringContainsString("'=1+1", $service->csv($service->export([$entry->id], $this->admin), $this->admin));
    }
}
