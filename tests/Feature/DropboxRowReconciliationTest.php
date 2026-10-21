<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\OperationsRuleProfile;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dropbox\ConflictStore;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\RowMapping;
use App\Services\Dropbox\WorkbookPackage;
use App\Services\Dropbox\WorkbookReader;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxRowReconciliationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const PATH = '/Disposition/Aufträge KW 38 2026.xlsx';

    private DropboxConnection $connection;

    private FakeDropboxClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        (require database_path('migrations/2026_10_11_070000_add_last_report_to_dropbox_sources.php'))->up();
        config(['dropbox.lock_store' => 'array']);
        Queue::fake();
        Storage::fake('local');
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Testregeln', 'is_active' => true, 'maximum_shift_minutes' => 720,
            'minimum_rest_minutes' => 0, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'created_by' => 1, 'approved_at' => now()]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'app_key' => 'key',
            'app_secret' => 'secret', 'refresh_token' => 'refresh', 'account_id' => 'dbid:test', 'settings' => config('dropbox.defaults')]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'dienstleister', 'kind' => 'provider', 'details' => ['display_name' => 'Dienstleister']]);
        $this->client = new FakeDropboxClient;
        $this->app->instance(DropboxClient::class, $this->client);
    }

    public function test_inserted_similar_row_cannot_take_the_identity_of_a_later_edited_row(): void
    {
        $source = $this->source($this->workbook([['reference' => 'ALT', 'note' => 'Ursprünglich']]));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $original = Shift::firstOrFail();
        $this->replace([['reference' => 'NEU', 'note' => 'Zusätzlicher Einsatz'], ['reference' => 'ALT', 'note' => 'Excel geändert']]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(2, Shift::count());
        $this->assertSame('ALT', $original->fresh()->disposition_details['train_reference']);
        $this->assertSame('Excel geändert', $original->fresh()->notes);
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
        $this->assertSame($original->id, DropboxRecord::findOrFail(DropboxAppearance::where('source_id', $source->id)->where('slot', '3')->firstOrFail()->record_id)->model_id);
    }

    public function test_new_date_cannot_retarget_a_shift_while_the_old_row_is_edited_and_moved(): void
    {
        $source = $this->source($this->workbook([['date' => '2026-09-15', 'note' => 'Alt']]));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $original = Shift::firstOrFail();
        $this->replace([['date' => '2026-09-16', 'note' => 'Neuer Tag'], ['date' => '2026-09-15', 'note' => 'Alter Tag ergänzt']]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(2, Shift::count());
        $this->assertSame('2026-09-15', $original->fresh()->starts_at->setTimezone('Europe/Berlin')->toDateString());
        $this->assertSame('Alter Tag ergänzt', $original->fresh()->notes);
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
    }

    public function test_equal_competing_rows_remain_ambiguous_and_do_not_steal_or_duplicate_shift(): void
    {
        $source = $this->source($this->workbook([['reference' => 'ALT', 'note' => 'Alt']]));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $this->replace([['reference' => 'NEU', 'note' => 'Gleich'], ['reference' => 'NEU', 'note' => 'Gleich']]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(1, Shift::count());
        $this->assertSame('ALT', Shift::firstOrFail()->disposition_details['train_reference']);
        $this->assertSame(2, DropboxConflict::where('reason', 'ambiguous_row')->where('state', 'open')->count());
    }

    public function test_manual_mapping_follows_unique_unchanged_content_after_row_insertion(): void
    {
        $source = $this->source($this->workbook([[]]));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $record = DropboxRecord::firstOrFail();
        $changed = ['location' => 'Anderer Bahnhof', 'reference' => 'ANDERS', 'role' => 'Andere Tätigkeit', 'customer' => 'Anderer Kunde'];
        $this->replace([$changed]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $conflict = DropboxConflict::where('reason', 'mapping_required')->firstOrFail();
        $conflict->update(['decision' => ['record_id' => $record->id], 'resolved_by' => 1]);
        $this->replace([['date' => '2026-09-16', 'reference' => 'ZUSATZ'], $changed]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('resolved', $conflict->fresh()->state);
        $this->assertSame('Anderer Bahnhof', Shift::findOrFail($record->model_id)->location_name);
        $this->assertSame(2, Shift::count());
        $this->assertSame(0, DropboxConflict::where('reason', 'mapping_required')->where('state', 'open')->count());
    }

    public function test_semantically_identical_revision_and_move_preserve_reviewed_field_decision(): void
    {
        $source = $this->source($this->workbook([[]]));
        $snapshot = ['generation' => $this->connection->generation, 'rev' => '1', 'app' => ['notes' => 'App'],
            'appearances' => [['baseline' => ['notes' => 'Basis'], 'excel' => ['notes' => 'Excel'], 'origin' => 'Disposition Übersicht!2']],
            'fields' => ['notes' => [['baseline' => 'Basis', 'excel' => 'Excel', 'app' => 'App', 'origin' => 'Disposition Übersicht!2']]]];
        $store = app(ConflictStore::class);
        $conflict = $store->put($this->connection, $source, null, 'field_conflict', $snapshot);
        $conflict->update(['decision' => ['notes' => 'App'], 'resolved_by' => 1]);
        $snapshot['rev'] = '2';
        $snapshot['appearances'][0]['origin'] = 'Disposition Übersicht!3';
        $snapshot['fields']['notes'][0]['origin'] = 'Disposition Übersicht!3';
        $store->put($this->connection, $source, null, 'field_conflict', $snapshot);
        $this->assertSame(['notes' => 'App'], $conflict->fresh()->decision);
        $snapshot['app']['notes'] = 'Neue App-Änderung';
        $store->put($this->connection, $source, null, 'field_conflict', $snapshot);
        $this->assertNull($conflict->fresh()->decision);
        $this->assertNull($conflict->fresh()->resolved_by);
    }

    public function test_obsolete_moved_mapping_warning_closes_only_after_complete_revision_and_not_on_parser_failure(): void
    {
        $source = $this->source($this->workbook([[]]));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $entry = app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows'][0];
        $snapshot = app(RowMapping::class)->snapshot($this->connection, $entry);
        $snapshot['slot'] = '99';
        $conflict = app(ConflictStore::class)->put($this->connection, $source, null, 'mapping_required', $snapshot, 'old');
        app(RowMapping::class)->reconcile($source, [], [['sheet' => $entry['sheet'], 'row' => 99, 'reason' => 'invalid_date']]);
        $this->assertSame('open', $conflict->fresh()->state);
        app(RowMapping::class)->reconcile($source, [], [], [['sheet' => $entry['sheet'], 'reason' => 'unsupported_layout']]);
        $this->assertSame('open', $conflict->fresh()->state);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh(), true);
        $this->assertSame('open', $conflict->fresh()->state);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('resolved', $conflict->fresh()->state);
    }

    public function test_repeated_competency_cells_for_the_same_fact_are_safe_copies(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Freigaben-Sperrungen');
        foreach (['B2' => 'Nachname', 'C2' => 'Vorname', 'D1' => 'Kunde', 'E1' => 'Kunde', 'B3' => 'Muster', 'C3' => 'Erika', 'D3' => 'gemeldet', 'E3' => 'gemeldet'] as $address => $value) {
            $sheet->setCellValue($address, $value);
        }
        $source = $this->source($this->bytes($book));
        $source->update(['profile' => 'matrix']);
        app(FileSynchronizer::class)->sync($this->connection, $source);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(2, DropboxAppearance::where('source_id', $source->id)->count());
        $this->assertSame(1, DropboxAppearance::where('source_id', $source->id)->distinct()->count('record_id'));
        $this->assertSame(0, DropboxConflict::where('reason', 'ambiguous_row')->where('state', 'open')->count());
    }

    public function test_moving_an_unresolved_row_keeps_only_its_current_mapping_hint(): void
    {
        $package = new WorkbookPackage($this->workbook([[]]));
        $package->patch(['Disposition Übersicht' => ['D2' => ['value' => 'Zeit offen']]]);
        $source = $this->source($package->bytes());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        foreach ([5, 6] as $row) {
            $blank = [];
            foreach (array_keys(WorkbookReader::HEADERS) as $column) {
                foreach ([2, 5, 6] as $clear) {
                    $blank[$column.$clear] = ['value' => ''];
                }
            }
            foreach (['B' => 'Testbahnhof', 'C' => '2026-09-15', 'D' => 'Zeit offen', 'E' => 'Dienstleister', 'F' => 'ZUG-1', 'G' => 'Anfahrt', 'H' => 'WGM', 'I' => 'Testkunde'] as $column => $value) {
                $blank[$column.$row] = ['value' => $value];
            }
            $package->patch(['Disposition Übersicht' => $blank]);
            $this->client->put(self::PATH, $package->bytes());
            app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
            $hints = DropboxConflict::where('reason', 'mapping_required')->where('state', 'open')->get();
            $this->assertCount(1, $hints);
            $this->assertSame((string) $row, $hints->first()->snapshot['slot']);
            $this->assertSame(0, Shift::count());
        }
    }

    private function source(string $bytes): DropboxSource
    {
        $metadata = $this->client->put(self::PATH, $bytes);

        return DropboxSource::create(['connection_id' => $this->connection->id, 'file_id' => $metadata['id'], 'path' => $metadata['path_lower'],
            'name' => $metadata['name'], 'profile' => 'weekly', 'rev' => $metadata['rev'], 'first_seen_at' => now()]);
    }

    private function replace(array $rows): void
    {
        $this->client->put(self::PATH, $this->workbook($rows));
    }

    private function workbook(array $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Disposition Übersicht');
        foreach (WorkbookReader::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'1', $label);
        }
        foreach ($rows as $index => $row) {
            $values = ['B' => $row['location'] ?? 'Testbahnhof', 'C' => $row['date'] ?? '2026-09-15', 'D' => '08:00–10:00', 'E' => 'Dienstleister',
                'F' => $row['reference'] ?? 'ZUG-1', 'G' => $row['note'] ?? 'Anfahrt', 'H' => $row['role'] ?? 'WGM', 'I' => $row['customer'] ?? 'Testkunde'];
            foreach ($values as $column => $value) {
                $sheet->setCellValue($column.($index + 2), $value);
            }
        }

        return $this->bytes($book);
    }

    private function bytes(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt-row-reconcile-');
        try {
            (new Xlsx($book))->save($path);

            return file_get_contents($path);
        } finally {
            @unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
