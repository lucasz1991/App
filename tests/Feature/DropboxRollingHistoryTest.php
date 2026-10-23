<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Models\Customer;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\OperationAudit;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\HistoricalImportCompletion;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\SourceSyncReport;
use App\Services\Dropbox\SyncContext;
use App\Services\Operations\OrderLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DropboxRollingHistoryTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $actor;

    private DropboxConnection $connection;

    private Order $order;

    private Shift $shift;

    private DropboxRecord $record;

    private DropboxSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables', '2026_09_22_100000_create_dropbox_sync_tables', '2026_10_11_070000_add_last_report_to_dropbox_sources'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        config(['dropbox.lock_store' => 'array']);
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-23T12:00:00+02:00'));
        $this->actor = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'refresh_token' => 'synthetic-refresh', 'settings' => [
            ...config('dropbox.defaults'), 'historical_auto_complete' => true, 'historical_completed_before' => '2026-10-09T07:03:42Z',
        ]]);
        SyncContext::import(function () {
            $customer = Customer::create(['company_name' => 'Synthetic Rail', 'is_active' => true]);
            $this->order = Order::create(['customer_id' => $customer->id, 'title' => 'Importierte Leistung', 'status' => OrderStatus::Requested,
                'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-11T08:00', 'ends_at' => '2026-10-11T10:00',
                'required_staff' => 1, 'created_by' => $this->actor->id]);
            $this->shift = Shift::create(['order_id' => $this->order->id, 'title' => 'Importierter Dienst', 'role_name' => 'WGM', 'status' => ShiftStatus::Draft,
                'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-11T08:00', 'ends_at' => '2026-10-11T10:00', 'required_staff' => 1,
                'created_by' => $this->actor->id]);
            $this->shift->forceFill(['revision' => 1, 'published_revision' => 0])->save();
        });
        $this->record = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'planning', 'model_type' => 'Shift',
            'model_id' => $this->shift->id, 'metadata' => ['imported_order' => true]]);
        $this->source = DropboxSource::create(['connection_id' => $this->connection->id, 'file_id' => 'id:synthetic-weekly', 'profile' => 'weekly',
            'path' => '/Disposition/Aufträge KW 41.xlsx', 'name' => 'Aufträge KW 41.xlsx', 'state' => 'synchronized', 'rev' => 'unchanged-1',
            'processed_rev' => 'unchanged-1', 'first_seen_at' => now(), 'last_report' => ['parser_version' => SourceSyncReport::VERSION]]);
        $this->appearance($this->record);
    }

    public function test_explicit_rolling_policy_completes_after_old_cutoff_once_without_workflow_side_effects(): void
    {
        $service = app(HistoricalImportCompletion::class);
        $this->assertTrue($service->canComplete($this->record, $this->shift, $this->connection));
        $service->apply($this->record, $this->actor);
        $service->apply($this->record, $this->actor);

        $this->assertSame(ShiftStatus::Completed, $this->shift->fresh()->status);
        $this->assertSame(2, $this->shift->fresh()->revision);
        $this->assertSame(0, $this->shift->fresh()->published_revision);
        $this->assertNull($this->shift->fresh()->published_at);
        $this->assertSame(1, OperationAudit::where('action', 'shift.import_history_completed')->count());
        $this->assertSame('automatic_import_policy', OperationAudit::where('action', 'shift.import_history_completed')->first()->data['origin']);
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, WorkTimeEntry::count());
        $this->assertSame(0, DropboxWorkItem::count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_disabled_rolling_policy_preserves_fixed_cutoff_and_does_not_complete_later_rows(): void
    {
        $this->policy(false);
        $service = app(HistoricalImportCompletion::class);
        $this->assertTrue($service->confirmedPast($this->connection, CarbonImmutable::parse('2026-10-08T10:00:00Z')));
        $this->assertFalse($service->confirmedPast($this->connection, CarbonImmutable::parse('2026-10-11T10:00:00Z')));
        $service->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);

        $this->shift->update(['starts_at' => '2026-10-08T08:00', 'ends_at' => '2026-10-08T10:00']);
        $service->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Completed, $this->shift->fresh()->status);
        $this->assertSame('user_confirmed', OperationAudit::where('action', 'shift.import_history_completed')->first()->data['origin']);
    }

    public function test_future_and_currently_running_night_shifts_remain_uncompleted(): void
    {
        $service = app(HistoricalImportCompletion::class);
        foreach ([['2026-10-24T08:00', '2026-10-24T10:00'], ['2026-10-22T23:00', '2026-10-23T13:00']] as [$start, $end]) {
            $this->shift->update(['starts_at' => $start, 'ends_at' => $end]);
            $this->assertFalse($service->canComplete($this->record, $this->shift, $this->connection));
            $service->apply($this->record, $this->actor);
            $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);
        }
    }

    public function test_publication_and_native_shift_statuses_remain_owned_by_native_workflows(): void
    {
        $service = app(HistoricalImportCompletion::class);
        foreach ([ShiftStatus::Open, ShiftStatus::Requested, ShiftStatus::Staffed, ShiftStatus::Confirmed, ShiftStatus::InProgress, ShiftStatus::Completed, ShiftStatus::Cancelled] as $status) {
            $this->shift->update(['status' => $status]);
            $service->apply($this->record, $this->actor);
            $this->assertSame($status, $this->shift->fresh()->status);
        }
        $this->shift->forceFill(['status' => ShiftStatus::Draft, 'published_revision' => 1])->save();
        $service->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);
        $this->shift->forceFill(['published_revision' => 0, 'published_at' => now()])->save();
        $service->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);
    }

    public function test_native_active_assignments_prevent_automatic_completion(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $assignment = ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $staff->id, 'assigned_by' => $this->actor->id, 'status' => 'requested']);
        app(HistoricalImportCompletion::class)->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);
        $this->assertSame('requested', $assignment->fresh()->status->value);
    }

    public function test_recorded_times_prevent_completion_even_when_assignment_no_longer_blocks(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);
        $assignment = ShiftAssignment::create(['shift_id' => $this->shift->id, 'user_id' => $staff->id, 'assigned_by' => $this->actor->id, 'status' => 'cancelled']);
        $time = WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $staff->id, 'status' => 'completed',
            'starts_at' => $this->shift->starts_at->utc(), 'ends_at' => $this->shift->ends_at->utc(), 'timezone' => 'Europe/Berlin', 'plan_snapshot' => []]);
        app(HistoricalImportCompletion::class)->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);
        $this->assertSame('completed', $time->fresh()->status);
        $this->assertNull($time->fresh()->reviewed_at);
    }

    public function test_manual_order_status_after_import_prevents_automatic_shift_completion(): void
    {
        $lifecycle = app(OrderLifecycleService::class);
        $order = $lifecycle->transition($this->order, OrderStatus::Confirmed, $this->actor);
        $order = $lifecycle->transition($order, OrderStatus::Planned, $this->actor);
        $this->record->update(['metadata' => ['imported_order' => true, 'order_status_import' => [
            'status' => 'planned', 'history_id' => $order->statusHistory()->max('id'),
        ]]]);
        $order = $lifecycle->transition($order, OrderStatus::InProgress, $this->actor, 'Manuell geprüft');
        app(HistoricalImportCompletion::class)->apply($this->record, $this->actor);
        $this->assertSame(ShiftStatus::Draft, $this->shift->fresh()->status);
        $this->assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    public function test_import_managed_order_status_allows_completion_but_app_origin_and_retired_records_do_not(): void
    {
        $order = app(OrderLifecycleService::class)->transition($this->order, OrderStatus::Confirmed, $this->actor);
        $this->record->update(['metadata' => ['imported_order' => true, 'order_status_import' => [
            'status' => 'confirmed', 'history_id' => $order->statusHistory()->max('id'),
        ]]]);
        $service = app(HistoricalImportCompletion::class);
        $this->assertTrue($service->canComplete($this->record, $this->shift, $this->connection));
        $this->record->metadata = ['imported_order' => false];
        $this->assertFalse($service->canComplete($this->record, $this->shift, $this->connection));
        $this->record->metadata = ['imported_order' => true, 'historical_values' => []];
        $this->assertFalse($service->canComplete($this->record, $this->shift, $this->connection));
    }

    public function test_new_imported_draft_is_classified_without_a_native_assignment(): void
    {
        $record = new DropboxRecord(['connection_id' => $this->connection->id, 'domain' => 'planning', 'model_type' => 'Shift', 'model_id' => 0, 'metadata' => ['imported_order' => true]]);
        $shift = new Shift(['order_id' => $this->order->id, 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-11T08:00', 'ends_at' => '2026-10-11T10:00']);
        $this->assertTrue(app(HistoricalImportCompletion::class)->canComplete($record, $shift, $this->connection));
    }

    public function test_next_due_uses_current_eligible_source_rows_and_ignores_past_conflicted_or_missing_appearances(): void
    {
        $this->shift->update(['starts_at' => '2026-10-24T08:00', 'ends_at' => '2026-10-24T10:00']);
        $service = app(HistoricalImportCompletion::class);
        $due = $service->nextDueAt($this->source);
        $this->assertNotNull($due);
        $this->assertTrue($due->equalTo($this->shift->ends_at));

        DropboxConflict::create(['connection_id' => $this->connection->id, 'source_id' => $this->source->id, 'record_id' => $this->record->id,
            'key' => 'synthetic-conflict', 'reason' => 'field_conflict', 'state' => 'open', 'snapshot' => []]);
        $this->assertNull($service->nextDueAt($this->source));
        DropboxConflict::query()->update(['state' => 'resolved']);
        DropboxAppearance::query()->update(['seen_rev' => 'old-rev']);
        $this->assertNull($service->nextDueAt($this->source));
        $this->assertTrue($service->nextDueAt($this->source, [$this->record->id])->equalTo($this->shift->ends_at));
        DropboxAppearance::query()->update(['seen_rev' => $this->source->rev, 'slot' => 'missing:1']);
        $this->assertNull($service->nextDueAt($this->source));
        DropboxAppearance::query()->update(['slot' => '2']);
        $this->shift->update(['ends_at' => '2026-10-23T10:00']);
        $this->assertNull($service->nextDueAt($this->source));
    }

    public function test_scanner_revisits_an_unchanged_source_after_the_reported_due_time(): void
    {
        $this->source->update(['last_report' => ['parser_version' => SourceSyncReport::VERSION, 'next_history_due_at' => now()->subMinute()->toIso8601String()]]);
        $client = Mockery::mock(DropboxClient::class);
        $client->shouldReceive('rpc')->withArgs(fn ($connection, $method) => in_array($method, ['files/list_folder', 'files/list_folder/continue'], true))
            ->twice()->andReturn(['entries' => [], 'cursor' => 'unchanged-cursor', 'has_more' => false]);
        $this->app->instance(DropboxClient::class, $client);

        app(SourceScanner::class)->scan($this->connection);
        $item = DropboxWorkItem::where('resource', 'file:'.$this->source->id)->firstOrFail();
        $this->assertGreaterThan($item->completed, $item->requested);
        $this->assertSame('unchanged-1', $this->source->fresh()->processed_rev);

        $item->update(['completed' => $item->requested]);
        $this->source->update(['last_report' => ['parser_version' => SourceSyncReport::VERSION, 'next_history_due_at' => null]]);
        app(SourceScanner::class)->scan($this->connection);
        $this->assertSame($item->requested, $item->fresh()->requested);
        $this->assertSame($item->requested, $item->fresh()->completed);
    }

    public function test_scanner_does_not_queue_future_due_or_disabled_policy_without_a_file_change(): void
    {
        $client = Mockery::mock(DropboxClient::class);
        $client->shouldReceive('rpc')->twice()->andReturn(['entries' => [], 'cursor' => 'unchanged-cursor', 'has_more' => false]);
        $this->app->instance(DropboxClient::class, $client);
        $this->source->update(['last_report' => ['parser_version' => SourceSyncReport::VERSION, 'next_history_due_at' => now()->addHour()->toIso8601String()]]);
        app(SourceScanner::class)->scan($this->connection);
        $this->assertSame(0, DropboxWorkItem::count());

        $this->policy(false);
        $this->source->update(['last_report' => ['parser_version' => SourceSyncReport::VERSION, 'next_history_due_at' => now()->subHour()->toIso8601String()]]);
        app(SourceScanner::class)->scan($this->connection);
        $this->assertSame(0, DropboxWorkItem::count());
    }

    private function policy(bool $enabled): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'historical_auto_complete' => $enabled]]);
    }

    private function appearance(DropboxRecord $record): void
    {
        DropboxAppearance::create(['source_id' => $this->source->id, 'record_id' => $record->id, 'sheet' => 'Disposition', 'slot' => (string) $record->id,
            'seen_rev' => $this->source->rev, 'fingerprint' => str_repeat('a', 64), 'locator' => [], 'baseline' => [], 'last_excel' => []]);
    }
}
