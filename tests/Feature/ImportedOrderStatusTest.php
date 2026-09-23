<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Enums\OrderStatus;
use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\DropboxAppearance;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Dropbox\ImportedOrderStatus;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Operations\OrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class ImportedOrderStatusTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private DropboxConnection $connection;

    private DropboxSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2026-09-23 12:00:00', 'Europe/Berlin'));
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->actor = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->connection = DropboxConnection::create([
            'mode' => DropboxMode::Import,
            'settings' => array_merge(config('dropbox.defaults'), ['source' => 'local']),
        ]);
        $this->source = DropboxSource::create([
            'connection_id' => $this->connection->id, 'file_id' => 'local:weekly',
            'path' => '/Aufträge KW 38.xlsx', 'name' => 'Aufträge KW 38.xlsx',
            'profile' => 'weekly', 'rev' => 'test-rev', 'first_seen_at' => now(),
        ]);
    }

    public function test_cancelled_import_also_cancels_the_order_with_audited_origin(): void
    {
        [$record, $order, $shift] = $this->importRow(['cancelled' => true, 'cancellation' => 'Storno'], shiftStatus: ShiftStatus::Cancelled);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(ShiftStatus::Cancelled, $shift->fresh()->status);
        $this->assertSame(1, $order->statusHistory()->count());
        $this->assertStringContainsString('Excel', $order->statusHistory()->first()->note);
    }

    public function test_past_plan_without_completion_evidence_is_planned_and_stays_unpublished(): void
    {
        [$record, $order, $shift] = $this->importRow();

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Planned, $order->fresh()->status);
        $this->assertSame(ShiftStatus::Draft, $shift->fresh()->status);
        $this->assertSame(0, $shift->fresh()->published_revision);
        $this->assertNull($shift->fresh()->published_at);
    }

    public function test_reported_past_actual_end_closes_order_without_approving_times_or_assignments(): void
    {
        [$record, $order, $shift] = $this->importRow(['actual_end' => '11:30']);
        $employee = User::factory()->create(['role' => 'staff']);
        $assignment = ShiftAssignment::create([
            'shift_id' => $shift->id, 'user_id' => $employee->id,
            'status' => ShiftAssignmentStatus::Requested, 'assigned_by' => $this->actor->id,
        ]);
        $record->update(['assignment_id' => $assignment->id]);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(ShiftStatus::Draft, $shift->fresh()->status);
        $this->assertSame(0, $shift->fresh()->published_revision);
        $this->assertNull($shift->fresh()->published_at);
        $this->assertSame(ShiftAssignmentStatus::Requested, $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->responded_at);
        $this->assertDatabaseCount('work_time_entries', 0);
        $this->assertDatabaseCount('work_time_exports', 0);
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_explicit_completion_marker_in_notes_or_information_closes_order(): void
    {
        foreach (['notes', 'information'] as $field) {
            [$record, $order] = $this->importRow([$field => 'Status: abgeschlossen']);

            app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

            $this->assertSame(OrderStatus::Completed, $order->fresh()->status, $field);
        }
    }

    public function test_negated_completion_text_does_not_complete_a_past_plan(): void
    {
        [$record, $order] = $this->importRow(['notes' => 'Status: nicht abgeschlossen']);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Planned, $order->fresh()->status);
    }

    public function test_explicit_source_draft_takes_priority_over_reported_actual_end(): void
    {
        [$record, $order] = $this->importRow(['draft' => true, 'actual_end' => '11:30']);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    public function test_actual_end_on_future_service_does_not_complete_order(): void
    {
        [$record, $order] = $this->importRow(['date' => '2026-10-01', 'actual_end' => '11:30']);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Planned, $order->fresh()->status);
    }

    public function test_untracked_active_shift_prevents_completing_whole_order(): void
    {
        [$record, $order] = $this->importRow(['actual_end' => '11:30']);
        $this->shift($order, ShiftStatus::Open);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertNotSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_another_imported_unfinished_shift_prevents_completing_whole_order(): void
    {
        [$record, $order] = $this->importRow(['actual_end' => '11:30']);
        $this->importRow(order: $order);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertNotSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_untracked_active_shift_prevents_cancelling_whole_order(): void
    {
        [$record, $order] = $this->importRow(['cancelled' => true, 'cancellation' => 'Storno'], shiftStatus: ShiftStatus::Cancelled);
        $this->shift($order, ShiftStatus::Open);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_order_can_cancel_when_all_actual_shifts_are_cancelled(): void
    {
        [$record, $order] = $this->importRow(['cancelled' => true, 'cancellation' => 'Storno'], shiftStatus: ShiftStatus::Cancelled);
        $this->shift($order, ShiftStatus::Cancelled);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_cancellation_of_one_assignment_does_not_cancel_still_active_service(): void
    {
        [$record, $order, $shift] = $this->importRow(['cancelled' => true, 'cancellation' => 'Storno'], shiftStatus: ShiftStatus::Open);
        $cancelled = ShiftAssignment::create([
            'shift_id' => $shift->id, 'user_id' => User::factory()->create(['role' => 'staff'])->id,
            'status' => ShiftAssignmentStatus::Cancelled, 'assigned_by' => $this->actor->id,
        ]);
        $active = ShiftAssignment::create([
            'shift_id' => $shift->id, 'user_id' => User::factory()->create(['role' => 'staff'])->id,
            'status' => ShiftAssignmentStatus::Requested, 'assigned_by' => $this->actor->id,
        ]);
        $record->update(['assignment_id' => $cancelled->id]);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertNotSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(ShiftStatus::Open, $shift->fresh()->status);
        $this->assertSame(ShiftAssignmentStatus::Requested, $active->fresh()->status);
    }

    public function test_unchanged_reimport_does_not_duplicate_status_history(): void
    {
        [$record, $order] = $this->importRow();
        $service = app(ImportedOrderStatus::class);
        $service->reconcile($record, $this->actor);
        $historyIds = $order->statusHistory()->pluck('id')->all();

        $service->reconcile($record->fresh(), $this->actor);

        $this->assertSame(OrderStatus::Planned, $order->fresh()->status);
        $this->assertNotEmpty($historyIds);
        $this->assertSame($historyIds, $order->statusHistory()->pluck('id')->all());
    }

    public function test_unchanged_older_copy_does_not_veto_an_accepted_actual_end(): void
    {
        [$record, $order, , $appearance] = $this->importRow(['actual_end' => '11:30']);
        $unchangedValues = array_merge($appearance->last_excel, ['actual_end' => null]);
        DropboxAppearance::create([
            'source_id' => $this->source->id, 'record_id' => $record->id,
            'sheet' => 'Mitarbeiterkopie', 'slot' => '2',
            'fingerprint' => WorkbookReader::fingerprint($unchangedValues),
            'locator' => ['master' => false], 'baseline' => $unchangedValues,
            'last_excel' => $unchangedValues, 'seen_rev' => 'test-rev',
        ]);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_actual_end_without_matching_accepted_source_evidence_does_not_complete(): void
    {
        foreach ([null, '11:30'] as $sourceActualEnd) {
            [$record, $order] = $this->importRow(['actual_end' => $sourceActualEnd]);
            $record->update(['metadata' => array_merge($record->metadata, ['actual_end' => '12:30'])]);

            app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

            $this->assertSame(OrderStatus::Planned, $order->fresh()->status);
        }
    }

    public function test_manual_status_after_import_is_not_overridden_by_later_source_evidence(): void
    {
        [$record, $order, , $appearance] = $this->importRow();
        $service = app(ImportedOrderStatus::class);
        $service->reconcile($record, $this->actor);
        app(OrderLifecycleService::class)->transition($order->fresh(), OrderStatus::InProgress, $this->actor, 'Manuell geprüft.');
        $historyIds = $order->statusHistory()->pluck('id')->all();
        $appearance->update(['last_excel' => array_merge($appearance->last_excel, ['actual_end' => '11:30'])]);

        $service->reconcile($record->fresh(), $this->actor);

        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
        $this->assertSame($historyIds, $order->statusHistory()->pluck('id')->all());
    }

    public function test_final_order_status_is_never_downgraded_by_ordinary_source_plan(): void
    {
        foreach ([OrderStatus::Completed, OrderStatus::Invoiced, OrderStatus::Cancelled] as $status) {
            [$record, $order] = $this->importRow();
            $order->update(['status' => $status]);

            app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

            $this->assertSame($status, $order->fresh()->status);
            $this->assertSame(0, $order->statusHistory()->count());
        }
    }

    public function test_app_origin_record_without_import_ownership_is_untouched(): void
    {
        [$record, $order] = $this->importRow(['actual_end' => '11:30'], imported: false);

        app(ImportedOrderStatus::class)->reconcile($record, $this->actor);

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
        $this->assertSame(0, $order->statusHistory()->count());
    }

    private function importRow(array $overrides = [], ?Order $order = null, ShiftStatus $shiftStatus = ShiftStatus::Draft, bool $imported = true): array
    {
        $values = array_merge([
            'location' => 'Testbahnhof', 'customer' => 'Testkunde', 'date' => '2026-09-15',
            'starts' => '08:00', 'ends' => '10:00', 'actual_end' => null,
            'employee' => '', 'role' => 'WGM', 'notes' => '', 'information' => '',
            'draft' => false, 'cancelled' => false, 'cancellation' => '',
        ], $overrides);
        if (! $order) {
            $order = Order::create([
                'customer_id' => Customer::create(['company_name' => 'Testkunde', 'is_active' => true])->id,
                'title' => 'Importierte Leistung', 'service_type' => 'WGM', 'status' => OrderStatus::Requested,
                'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'required_staff' => 1,
                'starts_at' => CarbonImmutable::parse($values['date'].' 08:00:00', 'Europe/Berlin')->utc(),
                'ends_at' => CarbonImmutable::parse($values['date'].' 10:00:00', 'Europe/Berlin')->utc(),
                'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
            ]);
        }
        $shift = $this->shift($order, $shiftStatus);
        $shift->update([
            'notes' => $values['notes'],
            'disposition_details' => ['information' => $values['information'], 'actual_end' => $values['actual_end']],
        ]);
        $record = DropboxRecord::create([
            'connection_id' => $this->connection->id, 'domain' => 'planning',
            'model_type' => 'Shift', 'model_id' => $shift->id,
            'metadata' => array_merge(['actual_end' => $values['actual_end']], $imported ? ['imported_order' => true] : []),
        ]);
        $appearance = DropboxAppearance::create([
            'source_id' => $this->source->id, 'record_id' => $record->id,
            'sheet' => 'WTU Abrechnung + Übersicht', 'slot' => (string) ($record->id + 1),
            'fingerprint' => WorkbookReader::fingerprint($values),
            'locator' => ['master' => true], 'baseline' => $values, 'last_excel' => $values,
            'seen_rev' => 'test-rev',
        ]);

        return [$record, $order, $shift, $appearance];
    }

    private function shift(Order $order, ShiftStatus $status): Shift
    {
        $shift = Shift::create([
            'order_id' => $order->id, 'title' => 'Testdienst', 'role_name' => 'WGM',
            'status' => $status, 'required_staff' => 1, 'timezone' => 'Europe/Berlin',
            'starts_at' => $order->starts_at->utc(), 'ends_at' => $order->ends_at->utc(),
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
        ]);
        $shift->forceFill(['revision' => 1, 'published_revision' => 0])->save();

        return $shift;
    }
}
