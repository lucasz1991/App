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
use App\Services\Dropbox\WorkbookRevisionCache;
use App\Services\Dropbox\WorkbookWriter;
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

    public function test_identical_plan_rows_keep_their_accepted_positions_only_within_the_same_revision(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $bytes = $this->workbook([[], []]);
        $source = $this->source($bytes);
        $sync = app(FileSynchronizer::class);
        $sync->sync($this->connection, $source);
        $appearances = DropboxAppearance::where('source_id', $source->id)->orderBy('slot')->get();
        $this->assertCount(2, $appearances);
        $before = $appearances->pluck('record_id', 'slot')->all();
        $parsed = app(WorkbookReader::class)->read($bytes, 'weekly');
        foreach ($parsed['rows'] as $index => $entry) {
            app(ConflictStore::class)->put($this->connection, $source, null, 'ambiguous_row', app(RowMapping::class)->snapshot($this->connection, $entry), $entry['sheet'].'!'.$entry['slot']);
            $appearance = $appearances[$index];
            app(ConflictStore::class)->put($this->connection, $source, DropboxRecord::findOrFail($appearance->record_id), 'row_missing',
                ['sheet' => $appearance->sheet, 'previous_slot' => $appearance->slot], (string) $appearance->id);
        }

        $sync->sync($this->connection, $source->fresh());
        $this->assertSame($before, DropboxAppearance::where('source_id', $source->id)->orderBy('slot')->pluck('record_id', 'slot')->all());
        $this->assertSame(2, Shift::count());
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());

        $review = DropboxConflict::where('reason', 'ambiguous_row')->orderBy('id')->firstOrFail();
        $reviewed = ['record_id' => $appearances[1]->record_id];
        $review->update(['state' => 'rechecking', 'decision' => $reviewed, 'resolved_by' => 1]);
        $sync->sync($this->connection, $source->fresh());
        $this->assertSame('open', $review->fresh()->state);
        $this->assertSame($reviewed, $review->fresh()->decision);

        $this->client->put(self::PATH, $bytes);
        $sync->sync($this->connection, $source->fresh());
        $this->assertSame(2, Shift::count());
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

    public function test_unchanged_revision_backfills_an_explicit_midnight_end_and_resolves_old_time_hints(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $package = new WorkbookPackage($this->workbook([[]]));
        $package->patch(['Disposition Übersicht' => ['D2' => ['value' => '15:00–24:00']]]);
        $source = $this->source($package->bytes());
        $revision = $source->rev;
        $legacyReader = new class extends WorkbookReader
        {
            public function times(string $value): array
            {
                return $value === '15:00–24:00' ? ['15:00', null, null] : parent::times($value);
            }
        };
        $this->app->instance(WorkbookReader::class, $legacyReader);
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $this->assertSame(0, Shift::count());
        $this->assertSame(2, DropboxConflict::where('state', 'open')->count());

        $this->app->forgetInstance(WorkbookReader::class);
        app(WorkbookRevisionCache::class)->forgetConnection($this->connection);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $shift = Shift::sole();
        $this->assertSame('2026-09-15 15:00', $shift->starts_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-16 00:00', $shift->ends_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
        $this->assertSame($revision, $source->fresh()->processed_rev);
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_explicit_unknown_actual_time_keeps_the_existing_app_time_and_the_excel_marker(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $package = new WorkbookPackage($this->workbook([[]]));
        $package->patch(['Disposition Übersicht' => ['D2' => ['value' => '08:00–10:00/–10:30']]]);
        $source = $this->source($package->bytes());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $record = DropboxRecord::sole();
        $this->assertSame('10:30', $record->metadata['actual_end']);

        $package->patch(['Disposition Übersicht' => ['D2' => ['value' => '08:00–10:00/–??']]]);
        $bytes = $package->bytes();
        $this->client->put(self::PATH, $bytes);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('10:30', $record->fresh()->metadata['actual_end']);
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
        $entry = app(WorkbookReader::class)->read($bytes, 'weekly')['rows'][0];
        $this->assertArrayNotHasKey('actual_end', $entry['values']);
        $updated = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], 'starts' => '08:15']]], 'weekly');
        $readback = app(WorkbookReader::class)->read($updated, 'weekly')['rows'][0];
        $this->assertSame('08:15', $readback['values']['starts']);
        $this->assertArrayNotHasKey('actual_end', $readback['values']);
        $this->assertSame('/–??', $readback['locator']['unreported_actual_suffix']);
    }

    public function test_recovered_interval_with_an_overlapping_disagreement_cannot_create_a_second_shift(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $source = $this->source($this->planningCopies(['15:00–17:00/–??'], ['15:30–17:30']));
        $sync = app(FileSynchronizer::class);
        $sync->sync($this->connection, $source);
        $sync->sync($this->connection, $source->fresh());

        $this->assertSame('15:30', Shift::sole()->starts_at->setTimezone('Europe/Berlin')->format('H:i'));
        $appearance = DropboxAppearance::sole();
        $this->assertSame('Personalblatt', $appearance->sheet);
        $conflict = DropboxConflict::where('reason', 'planning_copy_time_disagreement')->where('state', 'open')->sole();
        $this->assertSame('D2', $conflict->snapshot['cell']);
        $this->assertSame('15:30', $conflict->snapshot['counterparts'][0]['starts']);
        $this->assertSame(1, $source->fresh()->last_report['missing']['planning_copy_time_disagreement']);

        $this->client->put(self::PATH, $this->planningCopies(['15:30–17:30/–??'], ['15:30–17:30']));
        $sync->sync($this->connection, $source->fresh());
        $this->assertSame(1, Shift::count());
        $this->assertSame(2, DropboxAppearance::count());
        $this->assertSame('resolved', $conflict->fresh()->state);
    }

    public function test_existing_plan_associations_survive_a_recovered_interval_disagreement(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $source = $this->source($this->planningCopies(['15:00–17:00'], ['15:30–17:30']));
        $sync = app(FileSynchronizer::class);
        $sync->sync($this->connection, $source);
        $before = DropboxAppearance::orderBy('id')->pluck('record_id', 'id')->all();
        $this->assertSame(2, Shift::count());

        $this->client->put(self::PATH, $this->planningCopies(['15:05–17:00/–??'], ['15:30–17:30']));
        $sync->sync($this->connection, $source->fresh());
        $this->assertSame($before, DropboxAppearance::orderBy('id')->pluck('record_id', 'id')->all());
        $this->assertSame(['15:00', '15:30'], Shift::orderBy('starts_at')->get()->map(fn ($shift) => $shift->starts_at->setTimezone('Europe/Berlin')->format('H:i'))->all());
        $this->assertSame(1, DropboxConflict::where('reason', 'planning_copy_time_disagreement')->where('state', 'open')->count());
    }

    public function test_exact_recovered_copy_can_link_while_the_other_service_interval_stays_separate(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $source = $this->source($this->planningCopies(['12:00–14:00', '18:30–20:30'], ['12:00–14:00/–?']));
        $sync = app(FileSynchronizer::class);
        $sync->sync($this->connection, $source);
        $sync->sync($this->connection, $source->fresh());

        $this->assertSame(2, Shift::count());
        $this->assertSame(3, DropboxAppearance::count());
        $personal = DropboxAppearance::where('sheet', 'Personalblatt')->sole();
        $this->assertSame(DropboxAppearance::where('sheet', 'Disposition Übersicht')->where('slot', '2')->sole()->record_id, $personal->record_id);
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
    }

    public function test_explicitly_distinct_service_references_preserve_two_overlapping_plan_intervals(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $source = $this->source($this->planningCopies(['15:00–17:00/–?'], ['15:30–17:30'], ['LEISTUNG-A', 'LEISTUNG-B']));
        app(FileSynchronizer::class)->sync($this->connection, $source);

        $this->assertSame(2, Shift::count());
        $this->assertSame(0, DropboxConflict::where('reason', 'planning_copy_time_disagreement')->where('state', 'open')->count());
    }

    public function test_multiple_contact_emails_keep_other_fields_and_preserve_the_saved_contact_email(): void
    {
        $this->connection->update(['mode' => DropboxMode::Import]);
        $employee = User::factory()->create(['name' => 'Mara Prüfer', 'role' => 'staff', 'status' => true]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Kontaktliste Rail Time');
        $sheet->fromArray([['Name', 'E-Mail', 'Telefon'], ['Mara Prüfer', 'original@example.test', '1234']]);
        $source = $this->source($this->bytes($book));
        $sync = app(FileSynchronizer::class);
        $sync->sync($this->connection, $source);
        $identity = DropboxIdentity::where('user_id', $employee->id)->sole();

        $package = new WorkbookPackage($this->client->files[mb_strtolower(self::PATH)]['bytes']);
        $package->patch(['Kontaktliste Rail Time' => ['B2' => ['value' => 'eins@example.test / zwei@example.test'], 'C2' => ['value' => '5678']]]);
        $this->client->put(self::PATH, $package->bytes());
        $sync->sync($this->connection, $source->fresh());
        $sync->sync($this->connection, $source->fresh());

        $this->assertSame('original@example.test', $identity->fresh()->details['contact_email']);
        $this->assertSame('5678', $identity->fresh()->details['phone']);
        $this->assertSame(1, DropboxConflict::where('reason', 'contact_email_multiple')->where('state', 'open')->count());
        $this->assertSame(0, DropboxConflict::where('reason', 'mapping_required')->where('state', 'open')->count());
        $this->assertSame(0, $this->client->uploads);

        $package->patch(['Kontaktliste Rail Time' => ['B2' => ['value' => 'eins@example.test']]]);
        $this->client->put(self::PATH, $package->bytes());
        $sync->sync($this->connection, $source->fresh());
        $this->assertSame('eins@example.test', $identity->fresh()->details['contact_email']);
        $this->assertSame(0, DropboxConflict::where('reason', 'contact_email_multiple')->where('state', 'open')->count());
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

    private function planningCopies(array $masterTimes, array $personalTimes, array $serviceReferences = []): string
    {
        $book = new Spreadsheet;
        $sheets = [$book->getActiveSheet()->setTitle('Disposition Übersicht'), $book->createSheet()->setTitle('Personalblatt')];
        foreach ([$masterTimes, $personalTimes] as $index => $times) {
            $sheet = $sheets[$index];
            foreach (WorkbookReader::HEADERS as $column => $label) {
                $sheet->setCellValue($column.'1', $label);
            }
            if (isset($serviceReferences[$index])) {
                $sheet->setCellValue('O1', 'Leistungs-ID');
            }
            foreach ($times as $row => $time) {
                $values = ['B' => 'Testbahnhof', 'C' => '2026-09-15', 'D' => $time, 'E' => 'Dienstleister', 'F' => 'ZUG-1',
                    'H' => 'WGM', 'I' => 'Testkunde', 'O' => $serviceReferences[$index] ?? ''];
                foreach ($values as $column => $value) {
                    $sheet->setCellValue($column.($row + 2), $value);
                }
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
