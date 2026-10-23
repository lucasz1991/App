<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Enums\ShiftAssignmentStatus;
use App\Livewire\Operations\StaffTimeline;
use App\Models\Customer;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\OperationAudit;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Dropbox\HistoricalEmployeeAssignmentService;
use App\Services\Operations\OperationsAuditService;
use App\Services\Operations\ShiftAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DropboxHistoricalEmployeeAssignmentTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $person;

    private DropboxConnection $connection;

    private DropboxIdentity $identity;

    private Shift $shift;

    private DropboxRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        config(['dropbox.lock_store' => 'array']);
        Queue::fake();
        Notification::fake();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-23T12:00:00+02:00'));
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->person = User::factory()->create(['name' => 'Erika Muster', 'role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Historische Leistung', 'status' => 'completed', 'priority' => 'normal',
            'timezone' => 'Europe/Berlin', 'starts_at' => '2026-09-15T08:00', 'ends_at' => '2026-09-15T10:00', 'required_staff' => 1, 'created_by' => $this->admin->id]);
        $this->shift = Shift::create(['order_id' => $order->id, 'title' => 'Historischer Dienst', 'role_name' => 'WGM', 'timezone' => 'Europe/Berlin',
            'starts_at' => '2026-09-15T08:00', 'ends_at' => '2026-09-15T10:00', 'status' => 'completed', 'required_staff' => 1,
            'planned_break_minutes' => 0, 'revision' => 1, 'published_revision' => 0, 'created_by' => $this->admin->id]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'settings' => [
            ...config('dropbox.defaults'), 'historical_completed_before' => '2026-10-09T07:03:42Z',
        ]]);
        $this->identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'erika muster', 'kind' => 'employee',
            'user_id' => $this->person->id, 'details' => ['display_name' => 'Erika Muster']]);
        $this->record = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'planning', 'model_type' => 'Shift',
            'model_id' => $this->shift->id, 'metadata' => ['imported_order' => true, 'employee_label' => 'Erika Muster', 'user_id' => $this->person->id]]);
    }

    public function test_historical_employee_becomes_visible_once_without_reservation_notification_or_time_creation(): void
    {
        $assignment = $this->sync();
        $this->assertNotNull($assignment);
        $this->assertSame(ShiftAssignmentStatus::Reported, $assignment->status);
        $this->assertFalse($assignment->status->blocksAvailability());
        $this->assertNull($assignment->responded_at);
        $this->assertSame(0, ShiftAssignment::blocking()->count());
        $this->assertSame(0, WorkTimeEntry::count());
        $this->assertSame(0, $this->shift->fresh()->published_revision);
        $this->assertSame($assignment->id, $this->sync()->id);
        $this->assertSame(1, ShiftAssignment::count());
        $this->assertSame(1, OperationAudit::where('action', 'assignment.import_reported')->count());
        Notification::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_timeline_shows_historical_person_and_label_but_does_not_count_history_as_workload(): void
    {
        $this->sync();
        $this->person->update(['status' => false]);
        Livewire::actingAs($this->admin)->test(StaffTimeline::class, ['from' => '2026-09-15', 'until' => '2026-09-15'])
            ->assertViewHas('rows', function ($rows): bool {
                $row = $rows->first(fn ($row) => $row['user']->id === $this->person->id);
                $this->assertNotNull($row);
                $this->assertCount(1, $row['events']);
                $this->assertSame('Historisch gemeldet', $row['events'][0]['status']);
                $this->assertSame('reported', $row['events'][0]['status_value']);
                $this->assertSame(0.0, $row['planned_hours_by_week']['2026-38']);

                return true;
            })->assertSee('Historisch gemeldet');
    }

    public function test_future_unconfirmed_unknown_and_provider_records_never_create_historical_assignments(): void
    {
        $service = app(HistoricalEmployeeAssignmentService::class);
        $this->shift->update(['status' => 'draft']);
        $this->assertNull($service->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, false));
        $this->assertNull($service->sync($this->connection, $this->record, $this->shift, null, $this->admin, true));
        $this->identity->update(['kind' => 'provider', 'user_id' => null]);
        $this->assertNull($service->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, true));
        $this->identity->update(['kind' => 'employee', 'user_id' => $this->person->id]);
        $this->shift->update(['starts_at' => '2026-11-01T08:00', 'ends_at' => '2026-11-01T10:00']);
        $this->assertNull($service->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, true));
        $this->assertSame(0, ShiftAssignment::count());
    }

    public function test_completed_import_after_fixed_cutoff_is_visible_without_rolling_completion_or_other_app_orders(): void
    {
        $this->shift->update(['starts_at' => '2026-10-15T08:00', 'ends_at' => '2026-10-15T10:00']);
        $service = app(HistoricalEmployeeAssignmentService::class);
        $assignment = $service->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, false);
        $this->assertNotNull($assignment);
        $this->assertSame(ShiftAssignmentStatus::Reported, $assignment->status);
        $this->assertSame('completed', $this->shift->fresh()->status->value);
        $this->assertSame(0, WorkTimeEntry::count());

        $assignment->delete();
        $this->record->metadata = ['imported_order' => false];
        $this->assertNull($service->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, false));
    }

    public function test_existing_real_assignment_is_preserved_without_reclassification(): void
    {
        $existing = ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $this->person->id, 'status' => 'confirmed',
            'assigned_by' => $this->admin->id, 'responded_at' => now(), 'note' => 'Persönlich bestätigt']);
        $this->record->update(['assignment_id' => $existing->id]);
        $result = $this->sync();
        $this->assertSame($existing->id, $result->id);
        $this->assertSame(ShiftAssignmentStatus::Confirmed, $existing->fresh()->status);
        $this->assertSame('Persönlich bestätigt', $existing->fresh()->note);
        $this->assertSame(0, OperationAudit::where('action', 'assignment.import_reported')->count());
    }

    public function test_changed_historical_person_retires_only_its_own_previous_import_assertion(): void
    {
        $previous = $this->sync();
        $other = User::factory()->create(['name' => 'Alex Beispiel', 'role' => 'staff', 'status' => true]);
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'alex beispiel', 'kind' => 'employee', 'user_id' => $other->id]);
        $current = $this->sync($identity);
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $previous->fresh()->status);
        $this->assertSame(ShiftAssignmentStatus::Reported, $current->status);
        $this->assertSame($other->id, $current->user_id);
        $this->assertSame(0, ShiftAssignment::blocking()->count());
        $this->assertSame(0, WorkTimeEntry::count());
        Notification::assertNothingSent();
    }

    public function test_explicit_historical_cancellation_keeps_retired_id_without_cancelling_another_person(): void
    {
        $previous = $this->sync();
        $other = User::factory()->create(['role' => 'staff', 'status' => true]);
        $otherAssignment = ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $other->id, 'status' => 'reported', 'assigned_by' => $this->admin->id]);
        $result = app(HistoricalEmployeeAssignmentService::class)->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, true, true);
        $this->assertSame($previous->id, $result->id);
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $result->status);
        $this->assertSame(ShiftAssignmentStatus::Reported, $otherAssignment->fresh()->status);
        $this->assertSame('completed', $this->shift->fresh()->status->value);
        $this->assertSame(0, WorkTimeEntry::count());
        Notification::assertNothingSent();
    }

    public function test_changing_historical_person_back_restores_only_its_own_retired_assertion(): void
    {
        $first = $this->sync();
        $other = User::factory()->create(['name' => 'Alex Beispiel', 'role' => 'staff', 'status' => true]);
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'alex beispiel', 'kind' => 'employee', 'user_id' => $other->id]);
        $second = $this->sync($identity);

        $restored = $this->sync(allowRestore: true);

        $this->assertSame($first->id, $restored->id);
        $this->assertSame(ShiftAssignmentStatus::Reported, $restored->status);
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $second->fresh()->status);
        $this->assertSame(2, ShiftAssignment::count());
        $this->assertSame(1, OperationAudit::where('action', 'assignment.import_report_restored')->count());
        $this->assertSame(0, ShiftAssignment::blocking()->count());
        $this->assertSame(0, WorkTimeEntry::count());
        Notification::assertNothingSent();
    }

    public function test_cancelled_import_assertion_is_restored_only_by_explicit_non_cancelled_apply(): void
    {
        $first = $this->sync();
        $cancelled = $this->sync(cancelled: true);
        $this->assertSame($first->id, $cancelled->id);
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $cancelled->status);

        $reconciled = $this->sync();
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $reconciled->status);
        $this->assertSame(0, OperationAudit::where('action', 'assignment.import_report_restored')->count());

        $restored = $this->sync(allowRestore: true);
        $this->assertSame($first->id, $restored->id);
        $this->assertSame(ShiftAssignmentStatus::Reported, $restored->status);
        $this->assertSame($restored->id, $this->record->metadata['historical_assignment_id']);
        $this->assertSame(0, WorkTimeEntry::count());
        Notification::assertNothingSent();
    }

    public function test_later_native_cancellation_cannot_be_replaced_by_an_import_restore(): void
    {
        $first = $this->sync();
        $this->sync(cancelled: true);
        app(OperationsAuditService::class)->record($first, $this->admin, 'assignment.cancelled', ['reason' => 'Persönliche Entscheidung']);

        try {
            $this->sync(allowRestore: true);
            $this->fail('A later native cancellation must require review.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('außerhalb dieses Imports', $exception->getMessage());
        }
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(0, OperationAudit::where('action', 'assignment.import_report_restored')->count());
    }

    public function test_another_record_cannot_restore_the_retired_assertion(): void
    {
        $first = $this->sync();
        $this->sync(cancelled: true);
        $otherRecord = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'planning', 'model_type' => 'Shift',
            'model_id' => $this->shift->id, 'assignment_id' => $first->id, 'metadata' => ['imported_order' => true]]);
        $this->expectException(ValidationException::class);
        app(HistoricalEmployeeAssignmentService::class)->sync($this->connection, $otherRecord, $this->shift, $this->identity, $this->admin, true, false, true);
    }

    public function test_moving_import_history_to_future_retires_evidence_without_reusing_cancelled_id(): void
    {
        $previous = $this->sync();
        $this->shift->update(['starts_at' => '2026-11-01T08:00', 'ends_at' => '2026-11-01T10:00', 'status' => 'draft']);
        $result = app(HistoricalEmployeeAssignmentService::class)->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, false);
        $this->assertNull($result);
        $this->assertNull($this->record->assignment_id);
        $this->assertSame(ShiftAssignmentStatus::Cancelled, $previous->fresh()->status);
        $this->assertSame(0, ShiftAssignment::blocking()->count());
    }

    public function test_simultaneous_person_change_and_historical_cancellation_requires_review(): void
    {
        $previous = $this->sync();
        $other = User::factory()->create(['name' => 'Alex Beispiel', 'role' => 'staff', 'status' => true]);
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'alex beispiel', 'kind' => 'employee', 'user_id' => $other->id]);
        try {
            app(HistoricalEmployeeAssignmentService::class)->sync($this->connection, $this->record, $this->shift, $identity, $this->admin, true, true);
            $this->fail('Ambiguous cancellation and person change must require review.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Mitarbeiterwechsel', $exception->getMessage());
        }
        $this->assertSame(ShiftAssignmentStatus::Reported, $previous->fresh()->status);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_regular_assignment_api_cannot_create_nonblocking_import_evidence(): void
    {
        $this->expectException(ValidationException::class);
        app(ShiftAssignmentService::class)->assign($this->shift, $this->person, $this->admin, ShiftAssignmentStatus::Reported);
    }

    public function test_foreign_connection_identity_is_rejected(): void
    {
        $other = DropboxConnection::create(['mode' => DropboxMode::Off]);
        $this->identity->update(['connection_id' => $other->id]);
        $this->expectException(ValidationException::class);
        app(HistoricalEmployeeAssignmentService::class)->sync($this->connection, $this->record, $this->shift, $this->identity, $this->admin, true);
    }

    private function sync(?DropboxIdentity $identity = null, bool $cancelled = false, bool $allowRestore = false): ?ShiftAssignment
    {
        $assignment = app(HistoricalEmployeeAssignmentService::class)->sync($this->connection, $this->record, $this->shift, $identity ?? $this->identity, $this->admin, true, $cancelled, $allowRestore);
        $this->record->assignment_id = $assignment?->id;
        $this->record->save();

        return $assignment;
    }
}
