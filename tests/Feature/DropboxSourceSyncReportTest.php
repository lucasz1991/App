<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\OperationsRuleProfile;
use App\Models\User;
use App\Services\Dropbox\DomainAdapter;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\SourceSyncReport;
use App\Services\Dropbox\WorkbookReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxSourceSyncReportTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables', '2026_07_18_000001_create_activity_log_table', '2026_09_22_100000_create_dropbox_sync_tables', '2026_10_11_070000_add_last_report_to_dropbox_sources'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        Queue::fake();
        config(['dropbox.lock_store' => 'array']);
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Regeln', 'is_active' => true, 'maximum_shift_minutes' => 720, 'minimum_rest_minutes' => 0, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'created_by' => 1, 'approved_at' => now()]);
    }

    public function test_source_results_count_distinct_native_data_instead_of_processing_operations(): void
    {
        $connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'generation' => 1, 'refresh_token' => 'refresh', 'settings' => config('dropbox.defaults')]);
        $source = DropboxSource::create(['connection_id' => $connection->id, 'file_id' => 'id:report', 'path' => '/Disposition/Aufträge KW 41.xlsx', 'name' => 'Aufträge KW 41.xlsx', 'profile' => 'weekly', 'rev' => 'r1', 'first_seen_at' => now()]);
        $values = ['location' => 'Bahnhof', 'date' => '2026-10-05', 'starts' => '08:00', 'ends' => '10:00', 'employee' => '', 'role' => 'WGM', 'customer' => 'Kunde', 'draft' => false, 'cancelled' => false];
        $entry = ['domain' => 'planning', 'sheet' => 'Übersicht', 'slot' => '2', 'values' => $values, 'fingerprint' => WorkbookReader::fingerprint($values), 'locator' => ['row' => 2, 'master' => true]];
        $record = app(DomainAdapter::class)->create($connection, $entry);
        $copy = [...$entry, 'sheet' => 'Kopie'];
        foreach ([$entry, $copy] as $row) {
            DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $row['sheet'], 'slot' => $row['slot'], 'locator' => $row['locator'], 'fingerprint' => $row['fingerprint'], 'baseline' => $values, 'last_excel' => $values, 'seen_rev' => 'r1']);
        }
        $report = app(SourceSyncReport::class)->build($source, ['rows' => [$entry, $copy], 'issues' => [], 'coverage' => ['ignored_sheets' => [['sheet' => 'Andere Daten', 'reason' => 'unsupported_sheet']]]], ['imported' => 777, 'exported' => 3, 'outgoing' => 5], 2, 2);

        $this->assertSame(2, $report['detected']['planning']);
        $this->assertSame(1, $report['stored']['planning']);
        $this->assertSame(1, $report['stored']['orders']);
        $this->assertSame(0, $report['stored']['assigned_shifts']);
        $this->assertSame(1, $report['stored']['unassigned_shifts']);
        $this->assertSame(2, $report['missing']['employee_not_in_source']);
        $this->assertSame(2, $report['pending_export']);
        $this->assertSame('Andere Daten', $report['ignored_sheets'][0]['sheet']);
        $this->assertTrue($report['complete']);
    }

    public function test_failed_first_run_is_visible_and_never_marked_complete(): void
    {
        $connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional]);
        $source = DropboxSource::create(['connection_id' => $connection->id, 'file_id' => 'id:error', 'path' => '/Disposition/Aufträge KW 41.xlsx', 'name' => 'Aufträge KW 41.xlsx', 'profile' => 'weekly', 'rev' => 'r2', 'first_seen_at' => now()]);

        app(SourceSyncReport::class)->failed($source, 'revision_conflict');

        $report = $source->fresh()->last_report;
        $this->assertSame(1, $report['schema']);
        $this->assertFalse($report['complete']);
        $this->assertSame('revision_conflict', $report['error_code']);
        $this->assertSame('r2', $report['rev']);
        $this->assertNull($source->fresh()->processed_rev);
    }

    public function test_source_results_distinguish_historical_evidence_from_native_history_and_active_staffing(): void
    {
        $connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional]);
        $source = DropboxSource::create(['connection_id' => $connection->id, 'file_id' => 'id:history', 'path' => '/Disposition/Aufträge KW 39.xlsx', 'name' => 'Aufträge KW 39.xlsx', 'profile' => 'weekly', 'rev' => 'r1', 'first_seen_at' => now()]);
        $employee = User::factory()->create(['role' => 'user', 'status' => true]);
        $customer = DB::table('customers')->insertGetId(['public_id' => 'report-customer', 'company_name' => 'Kunde']);
        $order = DB::table('orders')->insertGetId(['public_id' => 'report-order', 'customer_id' => $customer, 'title' => 'Leistung', 'service_type' => 'WGM', 'status' => 'requested', 'starts_at' => '2026-09-21 06:00:00', 'ends_at' => '2026-09-28 18:00:00']);
        DropboxIdentity::create(['connection_id' => $connection->id, 'alias' => WorkbookReader::normalize('Historische Person'), 'kind' => 'employee', 'user_id' => $employee->id]);
        DropboxIdentity::create(['connection_id' => $connection->id, 'alias' => WorkbookReader::normalize('Externer Dienstleister'), 'kind' => 'provider']);
        $rows = [];
        foreach ([['completed', 'reported'], ['completed', null], ['open', 'requested'], ['draft', null], ['cancelled', 'reported']] as $index => [$status, $assignmentStatus]) {
            $shift = DB::table('shifts')->insertGetId(['public_id' => 'report-shift-'.$index, 'order_id' => $order, 'title' => 'Schicht', 'role_name' => 'WGM', 'starts_at' => '2026-09-21 06:00:00', 'ends_at' => '2026-09-21 08:00:00', 'status' => $status]);
            $metadata = ['user_id' => $index === 3 ? 999999 : $employee->id];
            $record = DropboxRecord::create(['connection_id' => $connection->id, 'model_type' => 'Shift', 'model_id' => $shift, 'domain' => 'planning', 'metadata' => $metadata]);
            if ($assignmentStatus) {
                DB::table('shift_assignments')->insert(['shift_id' => $shift, 'user_id' => $employee->id, 'status' => $assignmentStatus, 'assigned_by' => 1]);
            }
            $values = ['employee' => $index === 3 ? 'Externer Dienstleister' : 'Historische Person'];
            $entry = ['domain' => 'planning', 'sheet' => 'Übersicht', 'slot' => (string) ($index + 2), 'values' => $values, 'fingerprint' => WorkbookReader::fingerprint($values), 'locator' => ['row' => $index + 2]];
            $rows[] = $entry;
            DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot'], 'fingerprint' => $entry['fingerprint'], 'locator' => $entry['locator'], 'last_excel' => $values, 'baseline' => $values, 'seen_rev' => 'r1']);
            if ($index === 0) {
                $copy = [...$entry, 'sheet' => 'Mitarbeiterblatt'];
                $rows[] = $copy;
                DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $copy['sheet'], 'slot' => $copy['slot'], 'fingerprint' => $copy['fingerprint'], 'locator' => $copy['locator'], 'last_excel' => $values, 'baseline' => $values, 'seen_rev' => 'r1']);
            }
        }

        $report = app(SourceSyncReport::class)->build($source, ['rows' => $rows], [], count($rows), count($rows));

        $this->assertSame(6, $report['detected']['planning']);
        $this->assertSame(5, $report['stored']['planning']);
        $this->assertSame(1, $report['stored']['orders']);
        $this->assertSame(2, $report['stored']['active_shifts']);
        $this->assertSame(2, $report['stored']['completed_shifts']);
        $this->assertSame(1, $report['stored']['cancelled_shifts']);
        $this->assertSame(1, $report['stored']['active_assigned_shifts']);
        $this->assertSame(1, $report['stored']['unassigned_active_shifts']);
        $this->assertSame(1, $report['stored']['historical_assigned_shifts']);
        $this->assertSame(1, $report['stored']['assigned_shifts']);
        $this->assertSame(4, $report['stored']['recognized_employee_shifts']);
        $this->assertSame(2, $report['stored']['historical_recognized_shifts']);
        $this->assertSame(2, $report['planning_employee_references']);
        $this->assertSame(1, $report['planning_matched_employees']);
        $this->assertSame(1, $report['planning_matched_providers']);
        $this->assertSame(0, $report['employee_references']);
        $this->assertSame(0, $report['matched_employees']);
        $this->assertSame([], $report['missing']);
    }

    public function test_parser_upgrade_reprocesses_unchanged_file_even_when_dropbox_cursor_returns_nothing(): void
    {
        $connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'generation' => 1, 'refresh_token' => 'refresh', 'settings' => config('dropbox.defaults')]);
        $source = DropboxSource::create(['connection_id' => $connection->id, 'file_id' => 'id:unchanged', 'path' => '/Disposition/Aufträge KW 41.xlsx', 'name' => 'Aufträge KW 41.xlsx', 'profile' => 'weekly', 'rev' => 'same', 'processed_rev' => 'same', 'state' => 'synchronized', 'first_seen_at' => now()]);
        $client = new class extends FakeDropboxClient
        {
            public function rpc(DropboxConnection $connection, string $method, ?array $arguments = null): array
            {
                return ['entries' => [], 'cursor' => 'unchanged', 'has_more' => false];
            }
        };
        $this->app->instance(DropboxClient::class, $client);

        app(SourceScanner::class)->scan($connection);
        $item = DropboxWorkItem::where('connection_id', $connection->id)->where('resource', 'file:'.$source->id)->firstOrFail();
        $sequence = $item->requested;
        $source->update(['last_report' => ['schema' => 1, 'parser_version' => SourceSyncReport::VERSION, 'complete' => true, 'rev' => 'same']]);
        app(SourceScanner::class)->scan($connection);

        $this->assertSame($sequence, $item->fresh()->requested);
    }
}
