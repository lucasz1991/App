<?php

namespace Tests\Feature;

use App\Livewire\Operations\MyWork;
use App\Livewire\Operations\PayrollReferences;
use App\Livewire\Operations\TimeReview;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PersonnelResponsibility;
use App\Models\PersonnelTraining;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkTimeBasicExport;
use App\Models\WorkTimeCaptureDevice;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeExport;
use App\Services\Operations\OperationsReportService;
use App\Services\Operations\PayrollReferenceService;
use App\Services\Operations\WorkTimeExportAccessService;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use App\Support\Operations\WorkTimeSchema;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkTimeExportReviewTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private User $outside;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables.php', '2026_09_17_190000_create_employee_payroll_references.php', '2019_12_14_000001_create_personal_access_tokens_table.php', '2026_10_04_120000_create_workforce_personnel_foundations.php', '2026_10_04_123000_extend_work_time_contexts.php'] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        $this->travelTo(CarbonImmutable::parse('2027-05-15T10:00:00Z'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => 'In Scope']);
        $this->outside = User::factory()->create(['role' => 'staff', 'status' => true, 'name' => 'Outside Scope']);
        $this->manager = User::factory()->create(['role' => 'staff', 'status' => true]);
        $abilities = ['operations.time.review', 'operations.time.export', 'operations.absences.review'];
        $team = Team::forceCreate(['user_id' => $this->admin->id, 'name' => 'Scoped review', 'personal_team' => false, 'rbac_permissions' => array_fill_keys($abilities, true)]);
        $this->manager->teams()->attach($team);
        $this->manager->forceFill(['current_team_id' => $team->id])->save();
        PersonnelResponsibility::create(['responsible_user_id' => $this->manager->id, 'user_id' => $this->employee->id, 'abilities' => $abilities, 'starts_on' => '2027-05-01', 'created_by' => $this->admin->id]);
    }

    private function entry(User $user, bool $shift = true, string $status = 'approved', string $title = 'Fixture'): WorkTimeEntry
    {
        $start = CarbonImmutable::parse('2027-05-13T08:00:00+02:00');
        $end = $start->addHours(8);
        $assignment = null;
        if ($shift) {
            $customer = Customer::create(['company_name' => 'Test Rail', 'is_active' => true]);
            $order = Order::create(['customer_id' => $customer->id, 'title' => 'Fixture', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'required_staff' => 1, 'created_by' => $this->admin->id]);
            $record = Shift::create(['order_id' => $order->id, 'title' => $title, 'role_name' => 'Tf', 'starts_at' => $start, 'ends_at' => $end, 'timezone' => 'Europe/Berlin', 'required_staff' => 1, 'status' => 'open', 'planned_break_minutes' => 30]);
            $assignment = ShiftAssignment::create(['shift_id' => $record->id, 'user_id' => $user->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);
        }

        return WorkTimeEntry::create(['shift_assignment_id' => $assignment?->id, 'work_context' => $shift ? 'shift' : 'internal', 'user_id' => $user->id, 'status' => $status, 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'pause_seconds' => 1800,
            'plan_snapshot' => $shift ? ['title' => $title, 'order_number' => 'RT-TEST', 'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(), 'planned_break_minutes' => 30] : ['title' => $title, 'valuation' => ['internal' => 10000, 'break' => 0]]]);
    }

    private function legacyExport(array $entries): WorkTimeExport
    {
        return app(WorkTimeService::class)->export(array_map(fn ($e) => $e->id, $entries), $this->admin);
    }

    private function forbidden(callable $work): void
    {
        try {
            $work();
            $this->fail('Foreign personnel access must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function notFound(callable $work): void
    {
        try {
            $work();
            $this->fail('Out-of-scope records must not be returned.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_review_list_detail_and_batch_apply_the_same_scope(): void
    {
        $inside = $this->entry($this->employee, false, 'submitted', 'Inside Time');
        $outside = $this->entry($this->outside, false, 'submitted', 'Foreign Time');
        Livewire::actingAs($this->manager)->test(TimeReview::class)->assertSee('Inside Time')->assertDontSee('Foreign Time')
            ->set('selected', [$inside->id, $outside->id])->call('prepareBatch')->assertHasErrors('workflow')->assertSet('batchOpen', false);
        $this->notFound(fn () => Livewire::actingAs($this->manager)->test(TimeReview::class)->call('openDetails', $outside->id));
        Livewire::actingAs($this->manager)->test(TimeReview::class)->call('openDetails', $inside->id)->assertSet('detailOpen', true)->assertSee('Kontogutschrift');
    }

    public function test_general_time_detail_has_no_fake_plan_and_displays_activity_timezone(): void
    {
        $entry = $this->entry($this->employee, false, 'submitted', 'Internal Fixture');
        $entry->activities()->create(['kind' => 'internal', 'starts_at' => $entry->starts_at, 'ends_at' => $entry->ends_at->subMinutes(30), 'source' => 'reported']);
        $entry->activities()->create(['kind' => 'break', 'starts_at' => $entry->ends_at->subMinutes(30), 'ends_at' => $entry->ends_at, 'source' => 'reported']);
        Livewire::actingAs($this->admin)->test(TimeReview::class)->call('openDetails', $entry->id)->assertSee('Internal Fixture')
            ->assertDontSee('Plan · Europe/Berlin')->assertSee('13.05. 08:00:00')->assertSee('Interne Arbeit')->assertHasNoErrors();
    }

    public function test_export_profiles_keep_v1_shift_only_and_v2_history_separate(): void
    {
        $shift = $this->entry($this->employee, true, 'approved', 'Shift Only');
        $general = $this->entry($this->employee, false, 'approved', 'General Only');
        $legacy = $this->legacyExport([$shift]);
        $basic = app(WorkTimeExtensionService::class)->export([$general->id], $this->admin);
        Livewire::actingAs($this->manager)->test(TimeReview::class, ['exports' => true])->assertSet('exportProfile', 'v1')
            ->assertDontSee('General Only')->assertViewHas('history', fn ($records) => $records->pluck('id')->all() === [$legacy->id])
            ->set('selected', [$shift->id])->set('exportProfile', 'v2')->assertSet('selected', [])->assertSee('General Only')
            ->assertViewHas('history', fn ($records) => $records->first() instanceof WorkTimeBasicExport && $records->first()->id === $basic->id)
            ->assertDontSee('Lohnübergabe CSV')->call('download', $basic->id)->assertFileDownloaded('RailTime-Arbeitszeiten-v2-'.$basic->public_id.'.csv');
    }

    public function test_v2_selection_creates_its_own_immutable_export_not_a_payroll_handoff(): void
    {
        $entry = $this->entry($this->employee, false);
        Livewire::actingAs($this->manager)->test(TimeReview::class, ['exports' => true])->set('exportProfile', 'v2')->set('selected', [$entry->id])
            ->call('export')->assertHasNoErrors()->assertSet('selected', []);
        $this->assertSame(0, WorkTimeExport::count());
        $this->assertSame(1, WorkTimeBasicExport::count());
        $export = WorkTimeBasicExport::first();
        $entry->update(['note' => 'Changed after export']);
        $this->assertSame('internal', $export->snapshot[0]['work_context']);
        $this->assertStringNotContainsString('Changed after export', app(WorkTimeExtensionService::class)->csv($export, $this->manager));
    }

    public function test_legacy_history_uses_historical_scope_and_rejects_mixed_exports(): void
    {
        $inside = $this->entry($this->employee);
        $outside = $this->entry($this->outside);
        $allowed = $this->legacyExport([$inside]);
        $foreign = $this->legacyExport([$outside]);
        $mixed = $this->legacyExport([$this->entry($this->employee), $this->entry($this->outside)]);
        $inside->update(['user_id' => $this->outside->id]);
        $outside->update(['user_id' => $this->employee->id]);
        $query = app(WorkTimeExportAccessService::class)->legacyQuery($this->manager);
        $this->assertSame([$allowed->id], $query->pluck('id')->all());
        $this->forbidden(fn () => app(WorkTimeService::class)->csv($foreign, $this->manager));
        $this->forbidden(fn () => app(WorkTimeService::class)->csv($mixed, $this->manager));
        $this->notFound(fn () => Livewire::actingAs($this->manager)->test(TimeReview::class, ['exports' => true])->call('download', $foreign->id));
    }

    public function test_legacy_snapshot_without_employee_id_falls_back_only_to_its_scoped_entry(): void
    {
        $allowed = $this->legacyExport([$this->entry($this->employee)]);
        $denied = $this->legacyExport([$this->entry($this->outside)]);
        foreach ([$allowed, $denied] as $export) {
            $item = $export->items()->first();
            $snapshot = $item->snapshot;
            unset($snapshot['employee_id']);
            $item->update(['snapshot' => $snapshot]);
        }
        $this->assertSame([$allowed->id], app(WorkTimeExportAccessService::class)->legacyQuery($this->manager)->pluck('id')->all());
    }

    public function test_encrypted_basic_history_filters_all_subjects_not_just_creator_or_first_row(): void
    {
        $allowed = app(WorkTimeExtensionService::class)->export([$this->entry($this->employee, false)->id], $this->admin);
        $mixed = app(WorkTimeExtensionService::class)->export([$this->entry($this->employee, false)->id, $this->entry($this->outside, false)->id], $this->admin);
        $foreign = app(WorkTimeExtensionService::class)->export([$this->entry($this->outside, false)->id], $this->admin);
        $this->assertSame([$allowed->id], app(WorkTimeExportAccessService::class)->basicHistory($this->manager)->pluck('id')->all());
        $this->forbidden(fn () => app(WorkTimeExtensionService::class)->csv($mixed, $this->manager));
        $this->forbidden(fn () => app(WorkTimeExtensionService::class)->csv($foreign, $this->manager));
        $this->assertStringNotContainsString('employee_id', $allowed->getRawOriginal('snapshot'));
    }

    public function test_payroll_mapping_list_save_and_historical_csv_are_scoped(): void
    {
        $service = app(PayrollReferenceService::class);
        $reference = ['employer_reference' => 'RT', 'personnel_number' => '001', 'external_employee_reference' => null];
        $service->save($this->employee->id, null, $reference, $this->manager);
        $service->save($this->outside->id, null, array_replace($reference, ['personnel_number' => '002']), $this->admin);
        Livewire::actingAs($this->manager)->test(PayrollReferences::class)->assertViewHas('users', fn ($users) => $users->pluck('id')->all() === [$this->employee->id])
            ->call('edit', $this->outside->id)->assertForbidden();
        $this->forbidden(fn () => $service->save($this->outside->id, 1, $reference, $this->manager));
        $allowed = $this->legacyExport([$this->entry($this->employee)]);
        $foreign = $this->legacyExport([$this->entry($this->outside)]);
        $service->save($this->employee->id, 1, array_replace($reference, ['personnel_number' => 'NEW']), $this->admin);
        $this->assertStringContainsString(';001;', $service->csv($allowed, $this->manager));
        $this->assertStringNotContainsString(';NEW;', $service->csv($allowed, $this->manager));
        $this->forbidden(fn () => $service->csv($foreign, $this->manager));
    }

    public function test_api_v1_export_listing_is_scoped_and_shape_is_unchanged(): void
    {
        $allowed = $this->legacyExport([$this->entry($this->employee)]);
        $foreign = $this->legacyExport([$this->entry($this->outside)]);
        config(['operations.api_enabled' => true]);
        $token = $this->manager->createToken('Fixture', ['operations:times:export'], now()->addDay());
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson('/api/v1/operations/exports')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->public_id)->assertJsonPath('data.0.schema_version', 1)->assertDontSee($foreign->public_id);
    }

    public function test_personal_exports_are_owner_scoped_v1_shift_only_and_v2_includes_general_times(): void
    {
        $this->entry($this->employee, true, 'approved', '=SHIFT()');
        $this->entry($this->employee, false, 'approved', '=GENERAL()');
        $this->entry($this->outside, false, 'approved', 'Foreign Time');
        $reports = app(OperationsReportService::class);
        $v1 = $reports->ownTimes($this->employee, '2027-05-01', '2027-05-31');
        $v2 = $reports->ownTimesV2($this->employee, '2027-05-01', '2027-05-31');
        $this->assertStringContainsString("'=SHIFT()", $v1);
        $this->assertStringNotContainsString('GENERAL', $v1);
        $this->assertStringContainsString("'=GENERAL()", $v2);
        $this->assertStringContainsString('Kontogutschrift', $v2);
        $this->assertStringNotContainsString('Foreign Time', $v2);
        $this->assertStringNotContainsString('GENERAL', $reports->ownTimesV2($this->employee, '2027-06-01', '2027-06-30'));
    }

    public function test_absence_export_obeys_responsibility_scope_without_private_notes(): void
    {
        foreach ([$this->employee, $this->outside] as $subject) {
            AbsenceRequest::create(['user_id' => $subject->id, 'kind' => 'vacation', 'starts_at' => CarbonImmutable::parse('2027-05-13T00:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2027-05-14T00:00:00+02:00'), 'timezone' => 'Europe/Berlin', 'status' => 'approved', 'note' => 'PRIVATE']);
        }
        $csv = app(OperationsReportService::class)->absences($this->manager, '2027-05-01', '2027-05-31');
        $this->assertStringContainsString('In Scope', $csv);
        $this->assertStringNotContainsString('Outside Scope', $csv);
        $this->assertStringNotContainsString('PRIVATE', $csv);
    }

    public function test_missing_extension_schema_keeps_v1_available_but_does_not_expose_v2(): void
    {
        Schema::drop('work_time_basic_exports');
        Livewire::actingAs($this->admin)->test(TimeReview::class, ['exports' => true])->assertSee('Schichtzeiten')->assertDontSee('Alle Arbeitszeiten');
        Livewire::actingAs($this->admin)->test(TimeReview::class, ['exports' => true])->set('exportProfile', 'v2')->assertStatus(503);
    }

    public function test_context_migration_resumes_each_missing_entry_column_without_replacing_history(): void
    {
        $entry = $this->entry($this->employee);
        $legacy = $this->legacyExport([$entry]);
        $snapshot = $entry->getRawOriginal('plan_snapshot');
        Schema::table('work_time_entries', function (Blueprint $table): void {
            $table->dropUnique(['capture_id']);
            $table->dropIndex(['order_id']);
            $table->dropIndex(['training_session_id']);
        });
        Schema::table('work_time_entries', fn (Blueprint $table) => $table->dropColumn(['capture_id', 'order_id', 'training_session_id', 'source']));
        $this->assertTrue(Schema::hasColumn('work_time_entries', 'work_context'));
        $this->assertFalse(WorkTimeSchema::ready());
        $migration = require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(WorkTimeSchema::ready());
        $this->assertSame($snapshot, $entry->fresh()->getRawOriginal('plan_snapshot'));
        $this->assertSame($entry->shift_assignment_id, $entry->fresh()->shift_assignment_id);
        $this->assertSame('shift', $entry->fresh()->work_context);
        $this->assertSame('online', $entry->fresh()->source);
        $this->assertNull($entry->fresh()->capture_id);
        $this->assertSame(1, WorkTimeExport::count());
        $this->assertSame($legacy->public_id, WorkTimeExport::first()->public_id);
    }

    public function test_context_migration_repairs_empty_partial_tables_and_constraints_idempotently(): void
    {
        foreach (['work_time_capture_receipts', 'work_time_active_sessions', 'work_time_activities', 'work_time_basic_exports', 'work_time_capture_devices'] as $table) {
            Schema::drop($table);
        }
        foreach (['work_time_activities', 'work_time_capture_receipts', 'work_time_basic_exports'] as $table) {
            Schema::create($table, fn (Blueprint $blueprint) => $blueprint->id());
        }
        Schema::create('work_time_capture_devices', fn (Blueprint $table) => $table->unsignedBigInteger('user_id'));
        Schema::create('work_time_active_sessions', fn (Blueprint $table) => $table->unsignedBigInteger('user_id'));
        $this->assertFalse(WorkTimeSchema::ready());
        $migration = require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php');
        $migration->up();
        $migration->up();
        $this->assertTrue(WorkTimeSchema::ready());
        $this->assertTrue(Schema::hasIndex('work_time_active_sessions', ['user_id'], 'primary'));
        $this->assertTrue(Schema::hasIndex('work_time_capture_receipts', ['work_time_capture_device_id', 'sequence'], 'unique'));
        $this->assertCount(3, Schema::getForeignKeys('work_time_capture_receipts'));
        $this->assertSame(0, DB::table('work_time_capture_devices')->count());
    }

    public function test_readiness_requires_capture_unique_indices_and_migration_restores_each(): void
    {
        foreach ([
            ['work_time_entries', 'work_time_entries_capture_id_unique'],
            ['work_time_active_sessions', 'work_time_active_sessions_work_time_entry_id_unique'],
            ['work_time_capture_devices', 'work_time_capture_devices_public_id_unique'],
            ['work_time_capture_receipts', 'work_time_capture_receipts_event_key_unique'],
            ['work_time_capture_receipts', 'capture_device_sequence'],
            ['work_time_basic_exports', 'work_time_basic_exports_public_id_unique'],
        ] as [$table, $index]) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($index));
            $this->assertFalse(WorkTimeSchema::ready(), $table.'.'.$index.' must gate the new capture.');
            (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
            $this->assertTrue(WorkTimeSchema::ready());
        }
        Schema::table('work_time_events', fn (Blueprint $table) => $table->dropUnique(['event_key']));
        $this->assertFalse(WorkTimeSchema::ready(), 'The existing global UUID constraint also protects new capture.');
    }

    public function test_missing_historical_device_sequence_requires_review_without_resetting_identity(): void
    {
        $device = WorkTimeCaptureDevice::create(['user_id' => $this->employee->id, 'public_id' => 'b281ca4c-0959-4e50-8c8b-96d5e3ff0710', 'encryption_key' => base64_encode(random_bytes(32)), 'last_sequence' => 27]);
        $ciphertext = $device->getRawOriginal('encryption_key');
        Schema::table('work_time_capture_devices', fn (Blueprint $table) => $table->dropColumn('last_sequence'));
        $this->assertFalse(WorkTimeSchema::ready());
        try {
            (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
            $this->fail('A missing historical sequence must not be reset to zero.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('requires a reviewed repair', $exception->getMessage());
            $this->assertStringContainsString('last_sequence', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('work_time_capture_devices', 'last_sequence'));
        $this->assertSame($ciphertext, DB::table('work_time_capture_devices')->where('id', $device->id)->value('encryption_key'));
        $this->assertSame(1, DB::table('work_time_capture_devices')->count());
    }

    public function test_duplicate_historical_device_ids_fail_unique_repair_without_deleting_records(): void
    {
        Schema::table('work_time_capture_devices', fn (Blueprint $table) => $table->dropUnique(['public_id']));
        foreach ([1, 2] as $sequence) {
            WorkTimeCaptureDevice::create(['user_id' => $this->employee->id, 'public_id' => 'b281ca4c-0959-4e50-8c8b-96d5e3ff0710', 'encryption_key' => base64_encode(random_bytes(32)), 'last_sequence' => $sequence]);
        }
        try {
            (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
            $this->fail('Duplicate identities require a reviewed repair.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }
        $this->assertSame(2, DB::table('work_time_capture_devices')->count());
        $this->assertSame([1, 2], DB::table('work_time_capture_devices')->orderBy('id')->pluck('last_sequence')->all());
        $this->assertFalse(WorkTimeSchema::ready());
    }

    public function test_missing_general_context_cannot_be_reconstructed_as_a_shift(): void
    {
        $entry = $this->entry($this->employee, false);
        Schema::table('work_time_entries', fn (Blueprint $table) => $table->dropIndex(['work_context']));
        Schema::table('work_time_entries', fn (Blueprint $table) => $table->dropColumn('work_context'));
        try {
            (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
            $this->fail('General actual times must not be converted into shift times by a default.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('historical contexts must not be invented', $exception->getMessage());
        }
        $this->assertFalse(WorkTimeSchema::ready());
        $this->assertNull($entry->fresh()->shift_assignment_id);
        $this->assertSame(1, WorkTimeEntry::count());
    }

    public function test_extension_rollback_preserves_actual_times_and_export_history(): void
    {
        $entry = $this->entry($this->employee, false);
        $export = app(WorkTimeExtensionService::class)->export([$entry->id], $this->admin);
        try {
            (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->down();
            $this->fail('Historical actual times must not be discarded by rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reviewed forward migration', $exception->getMessage());
        }
        $this->assertTrue(WorkTimeSchema::ready());
        $this->assertSame($export->getRawOriginal('snapshot'), $export->fresh()->getRawOriginal('snapshot'));
        $this->assertSame(1, WorkTimeEntry::count());
    }

    private function training(User $subject, string $participation = 'attended', array $overrides = []): PersonnelTraining
    {
        $training = PersonnelTraining::create($overrides + ['title' => 'Absolvierte Tf-Unterweisung', 'starts_at' => CarbonImmutable::parse('2027-05-13T08:00:00+02:00'),
            'ends_at' => CarbonImmutable::parse('2027-05-13T10:00:00+02:00'), 'timezone' => 'Europe/Berlin', 'capacity' => 10, 'status' => 'scheduled', 'created_by' => $this->admin->id]);
        $training->participants()->create(['user_id' => $subject->id, 'status' => $participation, 'created_by' => $this->admin->id,
            'reviewed_by' => $participation === 'attended' ? $this->admin->id : null, 'reviewed_at' => $participation === 'attended' ? now()->utc() : null]);

        return $training;
    }

    public function test_attended_past_training_without_actual_time_makes_completeness_false_without_creating_hours(): void
    {
        $training = $this->training($this->employee);
        $customer = Customer::create(['company_name' => 'Schulungsprüfung Rail', 'is_active' => true]);
        $start = CarbonImmutable::parse('2027-05-14T06:00:00Z');
        $end = $start->addHours(8);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Vergangener Einsatz', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => $start, 'ends_at' => $end, 'required_staff' => 1, 'created_by' => $this->admin->id]);
        $shift = Shift::forceCreate(['order_id' => $order->id, 'title' => 'Dienst ohne erfasste Zeit', 'role_name' => 'Tf', 'starts_at' => $start, 'ends_at' => $end, 'timezone' => 'Europe/Berlin', 'required_staff' => 1, 'status' => 'open', 'revision' => 1, 'published_revision' => 1, 'planned_break_minutes' => 30]);
        $assignment = ShiftAssignment::forceCreate(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id, 'plan_revision' => 1]);
        $result = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->employee);
        $this->assertFalse($result['complete']);
        $this->assertSame([['training_session_id' => $training->id, 'title' => $training->title, 'starts_at' => $training->starts_at->toIso8601String()]], $result['missing_training_times']);
        $this->assertSame([$assignment->id], array_column($result['missing_shift_times'], 'assignment_id'));
        $this->assertSame([], $result['unfinished_times']);
        $this->assertSame(0, WorkTimeEntry::count());
        Livewire::actingAs($this->employee)->test(MyWork::class)->set('tab', 'time')->set('timeFrom', '2027-05-01')->set('timeUntil', '2027-05-31')
            ->assertSee('Offene Zeitmeldungen')->assertSee('Dienst ohne Zeitmeldung')->assertSee('Schulung ohne Zeitmeldung')->assertSee($shift->title)->assertSee($training->title)
            ->assertViewHas('timeCompleteness', fn ($value) => count($value['missing_training_times']) + count($value['missing_shift_times']) + count($value['unfinished_times']) === 2);
    }

    public function test_training_completeness_does_not_infer_attendance_or_include_cancelled_future_other_person_or_period(): void
    {
        $this->training($this->employee, 'confirmed');
        $this->training($this->employee, 'cancelled');
        $this->training($this->employee, 'attended', ['status' => 'cancelled']);
        $this->training($this->employee, 'attended', ['starts_at' => CarbonImmutable::parse('2027-05-16T06:00:00Z'), 'ends_at' => CarbonImmutable::parse('2027-05-16T08:00:00Z')]);
        $this->training($this->employee, 'attended', ['starts_at' => CarbonImmutable::parse('2027-04-13T06:00:00Z'), 'ends_at' => CarbonImmutable::parse('2027-04-13T08:00:00Z')]);
        $this->training($this->outside);
        $result = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->employee);
        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['missing_training_times']);
        $this->assertSame(0, WorkTimeEntry::count());
    }

    public function test_existing_training_time_is_only_unfinished_once_and_unknown_valuation_is_not_invented(): void
    {
        $training = $this->training($this->employee);
        $entry = $this->entry($this->employee, false, 'completed');
        $entry->update(['work_context' => 'training', 'training_session_id' => $training->id, 'plan_snapshot' => ['title' => $training->title, 'valuation' => []]]);
        $this->assertNull($entry->fresh()->creditedSeconds());
        $result = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->employee);
        $this->assertFalse($result['complete']);
        $this->assertSame([], $result['missing_training_times']);
        $this->assertSame([$entry->id], array_column($result['unfinished_times'], 'id'));
        $entry->update(['status' => 'submitted']);
        $submitted = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->employee);
        $this->assertTrue($submitted['complete']);
        $this->assertSame([], $submitted['missing_training_times']);
        $this->assertSame(1, WorkTimeEntry::count());
    }

    public function test_null_references_foreign_training_times_and_other_contexts_do_not_hide_missing_own_training(): void
    {
        $training = $this->training($this->employee);
        $this->entry($this->employee, false);
        $this->entry($this->outside, false)->update(['work_context' => 'training', 'training_session_id' => $training->id]);
        $this->entry($this->employee, false)->update(['training_session_id' => $training->id]);
        $result = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->employee);
        $this->assertFalse($result['complete']);
        $this->assertSame([$training->id], array_column($result['missing_training_times'], 'training_session_id'));
        $this->assertSame([], $result['unfinished_times']);
    }

    public function test_training_completeness_retains_personnel_scope(): void
    {
        $training = $this->training($this->employee);
        $this->training($this->outside);
        $result = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->manager);
        $this->assertSame([$training->id], array_column($result['missing_training_times'], 'training_session_id'));
        $this->forbidden(fn () => app(WorkTimeExtensionService::class)->completeness($this->outside, '2027-05-01', '2027-05-31', $this->manager));
        $this->expectException(AuthorizationException::class);
        app(WorkTimeExtensionService::class)->completeness($this->outside, '2027-05-01', '2027-05-31', $this->employee);
    }

    public function test_missing_training_schema_keeps_existing_completeness_and_open_times_available(): void
    {
        Schema::drop('personnel_training_participants');
        Schema::drop('personnel_trainings');
        $entry = $this->entry($this->employee, false, 'completed');
        $result = app(WorkTimeExtensionService::class)->completeness($this->employee, '2027-05-01', '2027-05-31', $this->employee);
        $this->assertSame([], $result['missing_training_times']);
        $this->assertSame([$entry->id], array_column($result['unfinished_times'], 'id'));
        $this->assertFalse($result['complete']);
    }
}
