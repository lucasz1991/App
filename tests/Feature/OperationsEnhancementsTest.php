<?php

namespace Tests\Feature;

use App\Http\Middleware\LogActivity;
use App\Livewire\Operations\OperationsEnhancements;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\EmployeeWorkModel;
use App\Models\OperationInquiry;
use App\Models\OperationsMonthClosing;
use App\Models\OperationsRateRule;
use App\Models\OperationsRuleProfile;
use App\Models\OperationWorkflow;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeCaptureReceipt;
use App\Models\WorkTimeEntry;
use App\Services\Operations\CustomerProofService;
use App\Services\Operations\OperationalCostService;
use App\Services\Operations\OperationsPartnerService;
use App\Services\Operations\OperationsRuleEvaluationService;
use App\Services\Operations\OperationsTravelService;
use App\Services\Operations\PayrollClosingService;
use App\Services\Operations\ReviewedInquiryImportService;
use App\Services\Operations\WorkTimeActivityService;
use App\Services\Operations\WorkTimeCaptureService;
use App\Services\Operations\WorkTimeRemunerationService;
use App\Services\Operations\WorkTimeService;
use App\Services\Operations\WorkTimeTerminalService;
use App\Support\Operations\OperationsEnhancementsSchema;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class OperationsEnhancementsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $reviewer;

    private User $employee;

    private User $outside;

    private Order $order;

    private Shift $shift;

    private ShiftAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_180000_create_operations_planning_extensions.php', '2026_09_17_190000_create_employee_payroll_references.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_121000_create_customer_workflow_extensions.php', '2026_10_04_122000_create_workforce_planning_tables.php', '2026_10_04_123000_extend_work_time_contexts.php', '2026_10_06_102000_create_operations_enhancements.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Gate::define('operations.costs.manage', fn ($u) => $u->isAdmin());
        Gate::define('operations.terminal.manage', fn ($u) => $u->isAdmin());
        $this->travelTo(CarbonImmutable::parse('2027-06-15T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->reviewer = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->outside = User::factory()->create(['role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic Rail', 'is_active' => true]);
        $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic Service', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-13T08:00:00+02:00', 'ends_at' => '2027-07-01T18:00:00+02:00', 'required_staff' => 2, 'created_by' => $this->admin->id]);
        $this->shift = Shift::create(['order_id' => $this->order->id, 'title' => 'Synthetic Duty', 'role_name' => 'Tf', 'starts_at' => '2027-05-13T08:00:00+02:00', 'ends_at' => '2027-05-13T16:00:00+02:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 2, 'status' => 'open', 'planned_break_minutes' => 0]);
        $this->shift->forceFill(['revision' => 1, 'published_revision' => 1])->save();
        $this->assignment = ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id, 'plan_revision' => 1]);
    }

    private function entry(string $status = 'approved'): WorkTimeEntry
    {
        $entry = WorkTimeEntry::create(['user_id' => $this->employee->id, 'shift_assignment_id' => $this->assignment->id, 'order_id' => $this->order->id, 'work_context' => 'shift', 'status' => $status, 'starts_at' => $this->shift->starts_at, 'ends_at' => $this->shift->ends_at, 'timezone' => 'Europe/Berlin', 'pause_seconds' => 0, 'plan_snapshot' => ['title' => 'Synthetic Duty', 'order_number' => 'TEST', 'starts_at' => $this->shift->starts_at->toIso8601String(), 'ends_at' => $this->shift->ends_at->toIso8601String()]]);
        $entry->activities()->create(['kind' => 'work', 'starts_at' => $entry->starts_at, 'ends_at' => $entry->ends_at, 'source' => 'reported']);

        return $entry;
    }

    private function baseRule(array $override = []): void
    {
        app(OperationsRuleEvaluationService::class)->configure(['name' => 'Explicit company base', 'kind' => 'remuneration', 'user_id' => $this->employee->id, 'starts_on' => '2027-01-01', 'confirmed' => true, 'configuration' => $override + ['activity' => 'work', 'wage_code' => 'BASE', 'multiplier_bps' => 10000, 'rounding_minutes' => 0, 'rounding_mode' => 'none', 'additive' => false]], $this->admin);
    }

    private function closed(): OperationsMonthClosing
    {
        $this->baseRule();
        $this->entry();
        $prepared = app(PayrollClosingService::class)->prepare($this->employee, '2027-05', $this->admin);
        $this->assertTrue($prepared->snapshot['complete']);

        return app(PayrollClosingService::class)->close($prepared->id, $prepared->revision, $this->reviewer);
    }

    private function rejected(callable $action, int $code = 409): void
    {
        try {
            $action();
            $this->fail('Expected protected action to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame($code, $e->getStatusCode());
        } catch (ValidationException $e) {
            $this->assertContains($code, [409, 422]);
            $this->assertNotEmpty($e->errors());
        } catch (AuthorizationException $e) {
            $this->assertSame(403, $code);
        }
    }

    public function test_proof_quantities_and_customer_acceptance_are_revision_bound_not_time(): void
    {
        $service = app(CustomerProofService::class);
        $proof = $service->create(['order_id' => $this->order->id, 'shift_id' => $this->shift->id, 'title' => 'Synthetic Proof', 'rows' => [['activity' => 'Wagons checked', 'quantity' => 18, 'unit' => 'wagons']]], $this->employee);
        $this->assertSame(0, WorkTimeEntry::count());
        $this->assertStringNotContainsString('wagons', DB::table('operation_workflows')->value('payload'));
        $proof = $service->submit($proof->id, 1, $this->employee);
        $this->rejected(fn () => $service->decide($proof->id, 1, 'review', 'Checked actual proof', null, $this->admin));
        $proof = $service->decide($proof->id, 2, 'review', 'Checked actual proof', null, $this->admin);
        $contact = CustomerContact::create(['customer_id' => $this->order->customer_id, 'name' => 'Synthetic Contact', 'roles' => ['acceptance'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $proof = $service->decide($proof->id, 3, 'accept', 'Customer approval documented', $contact->id, $this->admin);
        $this->assertSame('accepted', $proof->status);
        $this->assertSame(3, $proof->payload['customer_decision']['proof_revision']);
        $this->assertSame(4, $proof->revisions()->count());
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_proof_personnel_is_private_and_wrong_customer_cannot_accept(): void
    {
        $proof = app(CustomerProofService::class)->create(['order_id' => $this->order->id, 'title' => 'Proof', 'rows' => [['activity' => 'Checked', 'quantity' => 2, 'unit' => 'wagons']]], $this->employee);
        $this->rejected(fn () => app(CustomerProofService::class)->submit($proof->id, 1, $this->outside), 403);
    }

    public function test_configured_planning_rules_have_no_implicit_defaults(): void
    {
        $s = app(OperationsRuleEvaluationService::class);
        $this->assertSame([], $s->planningIssues($this->shift, $this->employee));
        $s->configure(['name' => 'Company rolling cap', 'kind' => 'planning', 'starts_on' => '2027-05-01', 'confirmed' => true, 'configuration' => ['type' => 'rolling_minutes', 'days' => 7, 'limit' => 400, 'timezone' => 'Europe/Berlin', 'activity' => 'all']], $this->admin);
        $this->assertSame('configured_rolling_minutes', $s->planningIssues($this->shift, $this->employee)[0]['code']);
    }

    public function test_remuneration_rounding_does_not_change_raw_timestamps_or_credit(): void
    {
        $this->baseRule(['rounding_minutes' => 15, 'rounding_mode' => 'up']);
        $entry = $this->entry();
        $entry->ends_at = $entry->ends_at->addMinute();
        $entry->save();
        foreach ($entry->activities as $activity) {
            $activity->update(['ends_at' => $entry->ends_at]);
        }
        $entry->refresh();
        $raw = $entry->only(['starts_at', 'ends_at', 'revision', 'pause_seconds']);
        $result = app(WorkTimeRemunerationService::class)->evaluate($entry);
        $this->assertTrue($result['complete'], json_encode($result));
        $this->assertSame(28860, $result['quantities'][0]['raw_seconds']);
        $this->assertSame(29700, $result['quantities'][0]['quantity_seconds']);
        $this->assertEquals($raw, $entry->fresh()->only(['starts_at', 'ends_at', 'revision', 'pause_seconds']));
    }

    public function test_unknown_pause_location_and_missing_rules_block_payroll_valuation(): void
    {
        $entry = $this->entry();
        $this->assertFalse(app(WorkTimeRemunerationService::class)->evaluate($entry)['complete']);
        $this->baseRule();
        $entry->activities()->delete();
        $entry->update(['pause_seconds' => 1800]);
        $this->assertContains('Pausenlage für Vergütungsbewertung fehlt.', app(WorkTimeRemunerationService::class)->evaluate($entry)['issues']);
    }

    public function test_month_close_requires_completeness_four_eyes_and_fresh_snapshot(): void
    {
        $this->baseRule();
        $entry = $this->entry();
        $s = app(PayrollClosingService::class);
        $prepared = $s->prepare($this->employee, '2027-05', $this->admin);
        $this->rejected(fn () => $s->close($prepared->id, 1, $this->admin));
        $entry->increment('revision');
        $this->rejected(fn () => $s->close($prepared->id, 1, $this->reviewer));
        $prepared = $s->prepare($this->employee, '2027-05', $this->admin);
        $closed = $s->close($prepared->id, $prepared->revision, $this->reviewer);
        $this->assertSame('closed', $closed->status);
        $this->assertSame(3, $closed->revisions()->count());
    }

    public function test_closed_month_blocks_direct_manual_context_and_reopen_keeps_old_export(): void
    {
        $closed = $this->closed();
        $s = app(PayrollClosingService::class);
        $csv = $s->csv($closed->id, $closed->revision, $this->admin);
        try {
            app(WorkTimeService::class)->manualContext(['work_context' => 'internal', 'title' => 'Actual work', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-14T08:00', 'ends_at' => '2027-05-14T09:00', 'pause_minutes' => 0, 'note' => 'Actual work reported'], (string) Str::uuid(), $this->employee);
            $this->fail('Closed month must block new actual times.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Monat abgeschlossen', $e->errors()['workflow'][0]);
        }
        $opened = $s->reopen($closed->id, $closed->revision, 'Actual correction required', $this->admin);
        $s->assertMutable($this->employee, $this->shift->starts_at, $this->shift->ends_at);
        $this->assertSame('reopened', $opened->status);
        $this->assertSame($csv, $s->csv($closed->id, $closed->revision, $this->admin));
    }

    public function test_closed_month_blocks_sections_and_correction_even_in_partial_migration(): void
    {
        $closed = $this->closed();
        $entry = WorkTimeEntry::first();
        Schema::drop('operations_rate_rules');
        try {
            app(PayrollClosingService::class)->assertMutable($this->employee, $entry->starts_at, $entry->ends_at);
            $this->fail('Lock must survive unrelated missing optional table.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('workflow', $e->errors());
        }
    }

    public function test_reclose_delta_is_new_snapshot_not_old_export_overwrite(): void
    {
        $closed = $this->closed();
        $s = app(PayrollClosingService::class);
        $entry = WorkTimeEntry::first();
        $old = $s->csv($closed->id, $closed->revision, $this->admin);
        $s->reopen($closed->id, $closed->revision, 'Correct actual activity duration', $this->admin);
        $s->returnForCorrection($entry->id, $entry->revision, 'Correct reported actual duration', $this->admin);
        app(WorkTimeService::class)->correct($entry->id, $entry->fresh()->revision, ['starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T15:00', 'pause_minutes' => 0, 'note' => 'Correct actual reported duration'], $this->employee);
        $entry->refresh();
        app(WorkTimeActivityService::class)->replace($entry->id, $entry->revision, [['kind' => 'work', 'starts_at' => '2027-05-13T08:00', 'ends_at' => '2027-05-13T15:00']], $this->employee);
        $entry->refresh();
        app(WorkTimeService::class)->clock($entry->id, $entry->revision, 'submit', (string) Str::uuid(), $this->employee);
        $entry->refresh();
        app(WorkTimeService::class)->review($entry->id, $entry->revision, true, 'Actual correction reviewed', $this->admin);
        $prepared = $s->prepare($this->employee, '2027-05', $this->admin);
        $new = $s->close($prepared->id, $prepared->revision, $this->reviewer);
        $delta = $s->csv($new->id, $new->revision, $this->admin, true);
        $this->assertStringContainsString("'-3600", $delta);
        $this->assertSame($old, $s->csv($closed->id, $closed->revision, $this->admin));
    }

    public function test_costs_are_private_dated_and_missing_never_zero(): void
    {
        $entry = $this->entry();
        $s = app(OperationalCostService::class);
        $this->assertNull($s->order($this->order, $this->admin)['actual_cost_cents']);
        $rate = $s->configure($this->employee, ['starts_on' => '2027-05-01', 'hourly_cents' => 4200, 'currency' => 'EUR'], $this->admin);
        $this->assertSame(33600, $s->order($this->order, $this->admin)['actual_cost_cents']);
        $this->assertStringNotContainsString('4200', DB::table('operations_cost_rates')->value('hourly_cents'));
        $this->expectException(AuthorizationException::class);
        $s->order($this->order, $this->employee);
    }

    public function test_travel_booking_is_documented_and_cancellation_is_not_remote_booking(): void
    {
        $s = app(OperationsTravelService::class);
        $travel = $s->request(['shift_id' => $this->shift->id, 'title' => 'Synthetic hotel', 'travel_kind' => 'hotel', 'starts_at' => '2027-06-17T18:00:00+02:00', 'ends_at' => '2027-06-18T08:00:00+02:00', 'destination' => 'Synthetic station'], $this->employee);
        $travel = $s->decide($travel->id, 1, 'approve', ['note' => 'Necessary duty hotel'], $this->admin);
        $travel = $s->decide($travel->id, 2, 'book', ['note' => 'Booked manually by manager', 'booking_reference' => 'SYNTHETIC-ONLY', 'cancel_until' => '2027-06-16T18:00:00+02:00'], $this->admin);
        $travel = $s->decide($travel->id, 3, 'cancel_requested', ['note' => 'Duty no longer requires hotel'], $this->employee);
        $this->assertSame('cancel_requested', $travel->status);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_partner_preparation_requires_persons_explicit_consent_and_private_projection(): void
    {
        $s = app(OperationsPartnerService::class);
        $request = $s->request(['shift_id' => $this->shift->id, 'user_id' => $this->employee->id, 'partner_name' => 'Synthetic partner', 'deadline' => '2027-06-16T10:00:00Z'], $this->admin);
        $this->rejected(fn () => $s->prepare($request->id, 1, $this->admin));
        $request = $s->consent($request->id, 1, true, [], $this->employee);
        $this->assertTrue($request->payload['consent']);
        $this->assertFalse($request->payload['external_delivery']);
        $this->assertCount(0, $request->payload['sharing']);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_import_preview_and_review_never_create_time_or_automatic_customer_match(): void
    {
        $s = app(ReviewedInquiryImportService::class);
        $preview = $s->preview('text', 'Need rail service tomorrow', 'Synthetic input 1', $this->admin);
        $this->assertSame(0, OperationInquiry::count());
        $this->assertArrayNotHasKey('customer_id', $preview->payload['rows'][0]);
        $rows = [['title' => 'Reviewed service', 'customer_id' => $this->order->customer_id, 'starts_at' => '2027-06-16T08:00', 'ends_at' => '2027-06-16T16:00', 'timezone' => 'Europe/Berlin', 'role_name' => 'Tf', 'required_staff' => 1]];
        $preview = $s->review($preview->id, 1, $rows, false, $this->admin);
        $this->assertSame(0, OperationInquiry::count());
        $converted = $s->createInquiries($preview->id, 2, $this->admin);
        $this->assertSame('converted', $converted->status);
        $this->assertSame(1, OperationInquiry::count());
        $this->assertSame(0, WorkTimeEntry::count());
        $this->rejected(fn () => $s->createInquiries($preview->id, 2, $this->admin));
    }

    public function test_duplicate_import_requires_explicit_review_and_csv_schema_is_allowlisted(): void
    {
        $s = app(ReviewedInquiryImportService::class);
        $s->preview('text', 'Same text', 'Synthetic duplicate', $this->admin);
        $dup = $s->preview('text', 'Same text', 'Synthetic duplicate', $this->admin);
        $this->assertCount(1, $dup->payload['duplicate_ids']);
        $this->rejected(fn () => $s->preview('csv', "secret_field;title\nsecret;name", 'Unsafe CSV', $this->admin), 422);
    }

    public function test_terminal_pin_is_hashed_capability_short_lived_and_never_logs_in(): void
    {
        $s = app(WorkTimeTerminalService::class);
        $terminal = (string) Str::uuid();
        $profile = $s->configure($this->employee, ['pin' => '123456', 'terminal_id' => $terminal, 'location_consent' => false], $this->employee);
        $this->assertNotSame('123456', $profile->pin_hash);
        $this->assertArrayNotHasKey('pin_hash', $profile->toArray());
        $before = auth()->id();
        $cap = $s->authenticate($this->employee->id, '123456', $terminal, 'synthetic-address');
        $this->assertSame(60, $cap['expires_in']);
        $this->assertSame($before, auth()->id());
        $this->assertArrayNotHasKey('key', $cap);
        $receipt = $s->capture($cap['token'], $terminal, ['event_key' => (string) Str::uuid(), 'action' => 'start', 'revision' => 0, 'work_context' => 'internal', 'title' => 'Actual terminal work']);
        $this->assertSame('accepted', $receipt['status']);
        $this->assertSame(1, WorkTimeEntry::count());
        $this->rejected(fn () => $s->capture($cap['token'], $terminal, ['event_key' => (string) Str::uuid(), 'action' => 'stop', 'revision' => 1]), 403);
    }

    public function test_terminal_location_consent_is_optional_and_cannot_be_granted_by_manager(): void
    {
        $terminal = (string) Str::uuid();
        $s = app(WorkTimeTerminalService::class);
        $this->rejected(fn () => $s->configure($this->employee, ['pin' => '123456', 'terminal_id' => $terminal, 'location_consent' => true, 'latitude' => 50, 'longitude' => 10, 'radius_metres' => 100], $this->admin), 403);
        $s->configure($this->employee, ['pin' => '123456', 'terminal_id' => $terminal, 'location_consent' => true, 'latitude' => 50, 'longitude' => 10, 'radius_metres' => 100], $this->employee);
        $cap = $s->authenticate($this->employee->id, '123456', $terminal, 'synthetic-geotest');
        $this->rejected(fn () => $s->capture($cap['token'], $terminal, ['event_key' => (string) Str::uuid(), 'action' => 'start', 'revision' => 0, 'work_context' => 'internal', 'title' => 'Actual work'], ['latitude' => 51, 'longitude' => 10, 'accuracy' => 5]), 422);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_livewire_personal_tabs_and_selected_records_cannot_cross_private_scope(): void
    {
        $this->actingAs($this->employee);
        Livewire::test(OperationsEnhancements::class, ['personal' => true, 'tab' => 'proofs'])->assertSee('Leistungsnachweise')->assertDontSee('Auftragskosten')->call('setTab', 'costs')->assertForbidden();
    }

    public function test_management_forms_use_standard_components_and_no_manufacturer_mentions(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(OperationsEnhancements::class, ['tab' => 'rules'])->call('create')->assertSee('Fachregeln')->assertSee('Betrieblich geprüfte Regel freigeben')->assertDontSee('WILSON');
        $this->assertStringContainsString('<x-operations.modal', file_get_contents(resource_path('views/livewire/operations/operations-enhancements.blade.php')));
    }

    public function test_migration_retry_and_historical_rollback_protection(): void
    {
        $migration = require database_path('migrations/2026_10_06_102000_create_operations_enhancements.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(OperationsEnhancementsSchema::ready());
        $this->entry();
        $this->baseRule();
        try {
            $migration->down();
            $this->fail('Rollback must preserve history.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Historische Operationsdaten', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('operation_workflows'));
        $this->assertSame(1, OperationsRateRule::count());
    }

    public function test_projected_null_id_shifts_and_future_followup_are_not_collapsed(): void
    {
        $s = app(OperationsRuleEvaluationService::class);
        $s->configure(['name' => 'Explicit rolling cap', 'kind' => 'planning', 'starts_on' => '2027-01-01', 'confirmed' => true, 'configuration' => ['type' => 'rolling_minutes', 'days' => 7, 'limit' => 1100, 'timezone' => 'Europe/Berlin', 'activity' => 'all']], $this->admin);
        $make = fn ($day) => (new Shift)->forceFill(['order_id' => $this->order->id, 'title' => 'Projected', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-'.$day.'T08:00:00+02:00', 'ends_at' => '2027-05-'.$day.'T16:00:00+02:00', 'required_staff' => 1, 'status' => 'draft']);
        $candidate = $make('14');
        $others = [$make('15'), $make('16')];
        $issues = $s->planningIssues($candidate, $this->employee, ['additional_shifts' => $others, 'exclude_shift_ids' => [$this->shift->id]]);
        $this->assertSame('configured_rolling_minutes', $issues[0]['code']);
        $this->assertStringContainsString('1440 / 1100', $issues[0]['message']);
    }

    public function test_closed_month_capture_conflict_retains_receipt_and_never_inserts_ist(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-05-31T10:00:00Z'));
        $device = app(WorkTimeCaptureService::class)->bootstrap($this->employee, null);
        $this->travelTo(CarbonImmutable::parse('2027-06-01T10:00:00Z'));
        $this->closed();
        $event = ['event_key' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'start', 'entry_capture_id' => (string) Str::uuid(), 'revision' => 0, 'occurred_at' => '2027-05-31T11:00:00Z', 'timezone' => 'Europe/Berlin', 'offset_minutes' => 120, 'work_context' => 'internal', 'title' => 'Actual offline work'];
        $result = app(WorkTimeCaptureService::class)->ingest($this->employee, $device['device_id'], [$event]);
        $this->assertSame('conflict', $result['results'][0]['status']);
        $this->assertStringContainsString('Monat abgeschlossen', $result['results'][0]['reason']);
        $this->assertSame(1, WorkTimeEntry::count());
        $again = app(WorkTimeCaptureService::class)->ingest($this->employee, $device['device_id'], [$event]);
        $this->assertSame($result, $again);
        $this->assertSame(1, WorkTimeCaptureReceipt::count());
    }

    public function test_persisted_closing_timezone_is_retained_after_configuration_change(): void
    {
        $this->baseRule();
        $this->entry();
        $s = app(PayrollClosingService::class);
        $prepared = $s->prepare($this->employee, '2027-05', $this->admin);
        config(['operations.display_timezone' => 'America/New_York']);
        $closed = $s->close($prepared->id, $prepared->revision, $this->reviewer);
        $this->assertSame('Europe/Berlin', $closed->timezone);
        $this->assertSame('Europe/Berlin', $closed->snapshot['timezone']);
        $s->reopen($closed->id, $closed->revision, 'Required actual correction', $this->admin);
        $again = $s->prepare($this->employee, '2027-05', $this->admin);
        $this->assertSame('Europe/Berlin', $again->snapshot['timezone']);
    }

    public function test_employee_directories_and_proof_creation_never_use_unpublished_draft_customer(): void
    {
        $this->shift->forceFill(['revision' => 2, 'title' => 'SECRET DRAFT CUSTOMER'])->save();
        $this->actingAs($this->employee);
        Livewire::test(OperationsEnhancements::class, ['personal' => true, 'tab' => 'proofs'])->call('create')->assertDontSee('SECRET DRAFT CUSTOMER');
        $this->rejected(fn () => app(CustomerProofService::class)->create(['order_id' => $this->order->id, 'title' => 'Proof', 'rows' => [['activity' => 'Checked', 'quantity' => 1, 'unit' => 'wagons']]], $this->employee), 403);
        $this->assertSame(0, OperationWorkflow::count());
    }

    public function test_terminal_pin_change_revokes_old_capability_and_public_page_requires_explicit_activation(): void
    {
        $s = app(WorkTimeTerminalService::class);
        $terminal = (string) Str::uuid();
        $s->configure($this->employee, ['pin' => '123456', 'terminal_id' => $terminal, 'location_consent' => false], $this->employee);
        $cap = $s->authenticate($this->employee->id, '123456', $terminal, 'synthetic-revocation');
        $s->configure($this->employee, ['pin' => '654321', 'terminal_id' => $terminal, 'location_consent' => false], $this->employee);
        $this->rejected(fn () => $s->capture($cap['token'], $terminal, ['event_key' => (string) Str::uuid(), 'action' => 'start', 'revision' => 0, 'work_context' => 'internal', 'title' => 'Actual work']), 403);
        $this->rejected(fn () => $s->authenticate($this->employee->id, '123456', $terminal, 'synthetic-oldpin'), 403);
        $this->withoutMiddleware(LogActivity::class);
        config(['operations.terminal_enabled' => false]);
        $this->get('/terminal')->assertNotFound();
        config(['operations.terminal_enabled' => true]);
        $this->get('/terminal')->assertOk()->assertSee('Zeiterfassung');
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_private_attachments_are_hashed_and_travel_expenses_require_receipt_review(): void
    {
        Storage::fake('local');
        $s = app(OperationsTravelService::class);
        $travel = $s->request(['shift_id' => $this->shift->id, 'title' => 'Synthetic travel', 'travel_kind' => 'hotel', 'starts_at' => '2027-06-17T18:00:00+02:00', 'ends_at' => '2027-06-18T08:00:00+02:00', 'destination' => 'Synthetic station'], $this->employee);
        $travel = $s->decide($travel->id, 1, 'approve', ['note' => 'Required travel approved'], $this->admin);
        $travel = $s->decide($travel->id, 2, 'book', ['note' => 'Actual booking documented', 'booking_reference' => 'SYNTHETIC'], $this->admin);
        $this->rejected(fn () => $s->decide($travel->id, 3, 'expense', ['note' => 'Actual receipt expenses', 'actual_cents' => 12500], $this->employee), 422);
        $travel = $s->receipt($travel->id, 3, UploadedFile::fake()->create('Synthetic.pdf', 2, 'application/pdf'), $this->employee);
        $receipt = $travel->payload['receipts'][0];
        $this->assertNotEmpty($receipt['sha256']);
        $this->assertStringContainsString('private', $s->download($travel->id, $receipt['id'], $this->employee)->headers->get('Cache-Control'));
        $this->rejected(fn () => $s->download($travel->id, $receipt['id'], $this->outside), 403);
        $travel = $s->decide($travel->id, 4, 'expense', ['note' => 'Actual receipt expenses', 'actual_cents' => 12500], $this->employee);
        $travel = $s->decide($travel->id, 5, 'expense_approve', ['note' => 'Private receipt amount checked'], $this->admin);
        $this->assertSame('expense_approved', $travel->status);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_private_billing_fact_never_leaks_into_employee_proof_payload(): void
    {
        $s = app(CustomerProofService::class);
        $proof = $s->create(['order_id' => $this->order->id, 'title' => 'Synthetic proof', 'rows' => [['activity' => 'Checked', 'quantity' => 1, 'unit' => 'wagons']]], $this->employee);
        $proof = $s->submit($proof->id, 1, $this->employee);
        $proof = $s->decide($proof->id, 2, 'review', 'Reported quantities checked', null, $this->admin);
        $contact = CustomerContact::create(['customer_id' => $this->order->customer_id, 'name' => 'Synthetic contact', 'roles' => ['acceptance'], 'is_active' => true, 'updated_by' => $this->admin->id]);
        $proof = $s->decide($proof->id, 3, 'accept', 'Actual customer acceptance', $contact->id, $this->admin);
        app(OperationalCostService::class)->setBillingAmount($proof->id, 4, 50000, $this->admin);
        $this->assertArrayNotHasKey('billing_cents', $proof->fresh()->payload);
        $this->assertSame(50000, app(OperationalCostService::class)->order($this->order, $this->admin)['accepted_revenue_cents']);
        $this->assertNull(app(OperationalCostService::class)->order($this->order, $this->admin)['contribution_cents']);
        $this->actingAs($this->employee);
        Livewire::test(OperationsEnhancements::class, ['personal' => true, 'tab' => 'proofs'])->call('openDetails', $proof->id)->assertDontSee('50000');
    }

    public function test_standard_list_row_contract_uses_cells_not_html_table_tags(): void
    {
        $proof = app(CustomerProofService::class)->create(['order_id' => $this->order->id, 'title' => 'Actual customer proof', 'rows' => [['activity' => 'Checked wagons', 'quantity' => 2, 'unit' => 'wagons']]], $this->employee);
        $this->actingAs($this->employee);
        $html = Livewire::test(OperationsEnhancements::class, ['personal' => true, 'tab' => 'proofs'])->call('openDetails', $proof->id)->html();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $this->assertSame(0, $dom->getElementsByTagName('td')->length);
        $this->assertStringContainsString('Checked wagons', $html);
        $this->assertStringContainsString('columnsMeta', file_get_contents(resource_path('views/components/tables/rows/operations/enhancement.blade.php')));
        $this->assertStringContainsString('hideClass', file_get_contents(resource_path('views/components/tables/rows/operations/proof-quantity.blade.php')));
    }

    public function test_partner_projection_is_bounded_and_rechecks_published_revision(): void
    {
        OperationsRuleProfile::create(['name' => 'Synthetic checked company rules', 'is_active' => true, 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 1440, 'break_after_minutes' => 1440, 'minimum_break_minutes' => 0, 'approved_at' => now()->utc(), 'created_by' => $this->admin->id]);
        EmployeeWorkModel::create(['user_id' => $this->employee->id, 'name' => 'Synthetic dated model', 'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'daily_minutes' => array_fill(1, 7, 480), 'status' => 'active', 'created_by' => $this->admin->id, 'approved_by' => $this->admin->id, 'approved_at' => now()->utc()]);
        $s = app(OperationsPartnerService::class);
        $request = $s->request(['shift_id' => $this->shift->id, 'user_id' => $this->employee->id, 'partner_name' => 'Synthetic partner', 'deadline' => '2027-06-16T10:00:00Z'], $this->admin);
        $request = $s->consent($request->id, 1, true, [], $this->employee);
        $request = $s->prepare($request->id, 2, $this->admin);
        $data = $s->projection($request->id, $this->admin);
        $this->assertFalse($data['external_delivery']);
        $this->assertArrayNotHasKey('user', $data);
        $this->assertArrayNotHasKey('bank', $data);
        $this->assertSame('Tf', $data['role']);
        $this->shift->forceFill(['revision' => 2])->save();
        $this->rejected(fn () => $s->projection($request->id,$this->admin), 409);
    }
}
