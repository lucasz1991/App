<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Jobs\Dropbox\ProcessDropboxWork;
use App\Models\DropboxAppearance;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\OperationsRuleProfile;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\RecordExporter;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\SourceSyncReport;
use App\Services\Dropbox\UploadRecovery;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxSourceReplacementTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private DropboxConnection $connection;

    private FakeDropboxClient $client;

    private const PATH = '/Disposition/Aufträge KW 38 2026.xlsx';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables', '2026_07_18_000001_create_activity_log_table', '2026_09_22_100000_create_dropbox_sync_tables', '2026_10_11_070000_add_last_report_to_dropbox_sources'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        config(['dropbox.lock_store' => 'array']);
        Queue::fake();
        Storage::fake('local');
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Regeln', 'is_active' => true, 'maximum_shift_minutes' => 720, 'minimum_rest_minutes' => 0, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'created_by' => 1, 'approved_at' => now()]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'generation' => 1, 'refresh_token' => 'refresh', 'settings' => config('dropbox.defaults')]);
        $this->client = new FakeDropboxClient;
        $this->app->instance(DropboxClient::class, $this->client);
    }

    public function test_reuploaded_file_keeps_the_unique_paths_source_and_common_baselines(): void
    {
        $source = $this->source();
        $appearance = $this->appearance($source);
        $source->update(['state' => 'missing', 'own_rev' => 'old', 'processed_rev' => 'old', 'progress' => ['next' => 50, 'rev' => 'old']]);
        $entry = $this->replacementMetadata();

        $registered = app(SourceScanner::class)->register($this->connection, $entry);

        $this->assertSame($source->id, $registered->id);
        $this->assertSame(1, DropboxSource::count());
        $this->assertSame('id:replacement', $registered->file_id);
        $this->assertNull($registered->own_rev);
        $this->assertNull($registered->processed_rev);
        $this->assertNull($registered->progress);
        $this->assertSame(['name' => 'Ursprünglicher Stand'], $appearance->fresh()->baseline);
        $this->assertSame($source->id, $appearance->fresh()->source_id);
        $this->assertTrue(DropboxWorkItem::where('resource', 'file:'.$source->id)->whereColumn('requested', '>', 'completed')->exists());
    }

    public function test_a_late_deleted_cursor_entry_cannot_mark_the_current_replacement_missing(): void
    {
        $source = $this->source();
        $this->replacementMetadata();

        $registered = app(SourceScanner::class)->register($this->connection, ['.tag' => 'deleted', 'path_lower' => mb_strtolower(self::PATH)]);

        $this->assertSame($source->id, $registered->id);
        $this->assertSame('id:replacement', $source->fresh()->file_id);
        $this->assertNotSame('missing', $source->fresh()->state);
        $this->assertSame(0, DB::table('dropbox_conflicts')->where('reason', 'source_missing')->where('state', 'open')->count());
    }

    public function test_an_old_id_that_is_still_live_is_never_merged_with_a_new_file_at_its_previous_path(): void
    {
        $source = $this->source();
        $old = $this->client->files[mb_strtolower(self::PATH)];
        $old['metadata']['path_lower'] = '/disposition/verschoben.xlsx';
        $this->client->files['/disposition/verschoben.xlsx'] = $old;
        $entry = $this->replacementMetadata();

        $registered = app(SourceScanner::class)->register($this->connection, $entry);

        $this->assertNotSame($source->id, $registered->id);
        $this->assertSame(2, DropboxSource::count());
        $this->assertSame($old['metadata']['id'], $source->fresh()->file_id);
    }

    public function test_same_path_with_a_different_profile_or_multiple_sources_is_never_reused(): void
    {
        $source = $this->source();
        $source->update(['profile' => 'matrix', 'state' => 'missing']);
        $entry = $this->replacementMetadata();
        $registered = app(SourceScanner::class)->register($this->connection, $entry);
        $this->assertNotSame($source->id, $registered->id);

        $registered->update(['state' => 'missing']);
        $duplicate = $source->replicate();
        $duplicate->fill(['file_id' => 'id:second-missing', 'profile' => 'weekly'])->save();
        $this->client->files[mb_strtolower(self::PATH)]['metadata']['id'] = 'id:third-file';
        $again = app(SourceScanner::class)->register($this->connection, $this->client->files[mb_strtolower(self::PATH)]['metadata']);
        $this->assertNotContains($again->id, [$registered->id, $duplicate->id]);
    }

    public function test_missing_source_work_is_completed_without_download_or_an_endless_retry(): void
    {
        $source = $this->source();
        $source->update(['state' => 'missing']);
        $item = app(WorkLedger::class)->enqueue($this->connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);

        (new ProcessDropboxWork($item->id))->handle();

        $this->assertSame($item->requested, $item->fresh()->completed);
        $this->assertNull($item->fresh()->error_code);
        $this->assertSame(0, $this->client->downloads);
        $this->assertSame('missing', $source->fresh()->state);
    }

    public function test_a_provably_superseded_source_does_not_block_replacing_the_current_paths_source(): void
    {
        $old = $this->source();
        $entry = $this->replacementMetadata();
        $current = $old->replicate();
        $current->fill(['file_id' => $entry['id'], 'state' => 'missing'])->save();
        $appearance = $this->appearance($current);
        $old->update(['state' => 'excluded', 'last_report' => ['replacement_source_id' => $current->id]]);
        unset($this->client->files[mb_strtolower(self::PATH)]);
        app(SourceScanner::class)->register($this->connection, ['.tag' => 'deleted', 'path_lower' => mb_strtolower(self::PATH)]);
        $this->assertSame('excluded', $old->fresh()->state);
        $this->assertSame('missing', $current->fresh()->state);
        $entry['id'] = 'id:next-replacement';
        $this->client->files[mb_strtolower(self::PATH)] = ['metadata' => $entry, 'bytes' => 'next-replacement'];

        $registered = app(SourceScanner::class)->register($this->connection, $entry);

        $this->assertSame($current->id, $registered->id);
        $this->assertSame('id:next-replacement', $registered->file_id);
        $this->assertSame(2, DropboxSource::count());
        $this->assertSame('excluded', $old->fresh()->state);
        $this->assertSame($current->id, $appearance->fresh()->source_id);
        $this->assertSame(['name' => 'Ursprünglicher Stand'], $appearance->fresh()->baseline);
        $this->assertNull(app(SourceScanner::class)->register($this->connection, [...$entry, 'id' => $old->file_id]));
        $this->assertSame('excluded', $old->fresh()->state);
    }

    public function test_recovery_rejects_an_appearance_from_another_source_even_if_hash_and_path_match(): void
    {
        $source = $this->source();
        $other = $source->replicate();
        $other->fill(['file_id' => 'id:other', 'path' => '/disposition/andere.xlsx'])->save();
        $appearance = $this->appearance($other);
        $metadata = $this->client->files[mb_strtolower(self::PATH)]['metadata'];
        DB::table('dropbox_uploads')->insert([
            'connection_id' => $this->connection->id, 'generation' => $this->connection->generation,
            'path' => mb_strtolower(self::PATH), 'content_hash' => $metadata['content_hash'], 'status' => 'uncertain',
            'manifest' => json_encode([['appearance_id' => $appearance->id, 'entry' => ['sheet' => 'Kontakte', 'slot' => '3', 'locator' => []], 'values' => ['name' => 'Fremder Wert']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            app(UploadRecovery::class)->recover($this->connection, $source, $metadata);
            $this->fail('A foreign source appearance must not be rewritten.');
        } catch (DropboxApiException $e) {
            $this->assertSame('upload_manifest_mismatch', $e->reason);
        }
        $this->assertSame(['name' => 'Ursprünglicher Stand'], $appearance->fresh()->baseline);
        $this->assertNull($source->fresh()->own_rev);
        $this->assertSame('uncertain', DB::table('dropbox_uploads')->value('status'));
    }

    public function test_work_outside_the_current_configured_paths_is_excluded_without_retrying(): void
    {
        $source = $this->source();
        $source->update(['path' => '/Alte-Konfiguration/Aufträge KW 38 2026.xlsx']);
        $item = app(WorkLedger::class)->enqueue($this->connection, 'file', 'file:'.$source->id, ['source_id' => $source->id], 0);

        (new ProcessDropboxWork($item->id))->handle();

        $this->assertSame($item->requested, $item->fresh()->completed);
        $this->assertNull($item->fresh()->error_code);
        $this->assertSame(0, $this->client->downloads);
        $this->assertSame('excluded', $source->fresh()->state);
    }

    public function test_a_confirmed_old_upload_cannot_reset_the_baseline_when_its_bytes_are_uploaded_again_as_a_new_revision(): void
    {
        $source = $this->source();
        $appearance = $this->appearance($source);
        $oldMetadata = $this->client->files[mb_strtolower(self::PATH)]['metadata'];
        $currentMetadata = $this->client->put(self::PATH, 'test-workbook');
        $source->update(['rev' => $currentMetadata['rev']]);
        DB::table('dropbox_uploads')->insert([
            'connection_id' => $this->connection->id, 'generation' => $this->connection->generation,
            'path' => mb_strtolower(self::PATH), 'content_hash' => $oldMetadata['content_hash'],
            'status' => 'confirmed', 'result_rev' => $oldMetadata['rev'],
            'manifest' => json_encode([['appearance_id' => $appearance->id, 'entry' => ['sheet' => 'Kontakte', 'slot' => '3', 'locator' => []], 'values' => ['name' => 'Veraltete Basis']]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(UploadRecovery::class)->recover($this->connection, $source->fresh(), $currentMetadata);

        $this->assertSame($oldMetadata['content_hash'], $currentMetadata['content_hash']);
        $this->assertNotSame($oldMetadata['rev'], $currentMetadata['rev']);
        $this->assertSame(['name' => 'Ursprünglicher Stand'], $appearance->fresh()->baseline);
        $this->assertSame(['name' => 'Ursprünglicher Stand'], $appearance->fresh()->last_excel);
        $this->assertNull($source->fresh()->own_rev);
        $this->assertSame('confirmed', DB::table('dropbox_uploads')->value('status'));
    }

    public function test_app_append_queues_one_report_refresh_without_creating_an_export_loop(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $record = DropboxRecord::where('domain', 'planning')->firstOrFail();
        $newShift = Shift::findOrFail($record->model_id)->replicate();
        $newShift->forceFill(['public_id' => null, 'title' => 'Weiterer Einsatz', 'location_name' => 'Anderer Bahnhof', 'notes' => 'Neu'])->save();
        DB::table('dropbox_work_items')->update(['completed' => DB::raw('requested')]);

        app(RecordExporter::class)->export($this->connection, 'Shift', $newShift->id);
        $item = DropboxWorkItem::where('resource', 'file:'.$source->id)->firstOrFail();
        $this->assertGreaterThan($item->completed, $item->requested);
        $this->assertSame(2, DropboxAppearance::where('source_id', $source->id)->count());
        $uploads = $this->client->uploads;
        $sequence = $item->requested;

        (new ProcessDropboxWork($item->id))->handle();
        $this->assertSame($sequence, $item->fresh()->completed);
        $this->assertSame($sequence, $item->fresh()->requested);
        $this->assertSame($uploads, $this->client->uploads);
        $this->assertSame(2, $source->fresh()->last_report['stored']['planning']);
        $this->assertSame(SourceSyncReport::VERSION, $source->fresh()->last_report['parser_version']);
    }

    private function source(string $bytes = 'test-workbook'): DropboxSource
    {
        $metadata = $this->client->put(self::PATH, $bytes);

        return DropboxSource::create(['connection_id' => $this->connection->id, 'file_id' => $metadata['id'], 'path' => $metadata['path_lower'], 'name' => $metadata['name'], 'profile' => 'weekly', 'rev' => $metadata['rev'], 'first_seen_at' => now()]);
    }

    private function replacementMetadata(): array
    {
        $metadata = $this->client->put(self::PATH, 'replacement-workbook');
        $metadata['id'] = 'id:replacement';
        $this->client->files[mb_strtolower(self::PATH)]['metadata'] = $metadata;

        return $metadata;
    }

    private function appearance(DropboxSource $source): DropboxAppearance
    {
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'kontakt-'.DropboxIdentity::count(), 'kind' => 'provider']);
        $record = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'contacts', 'model_type' => 'DropboxIdentity', 'model_id' => $identity->id]);
        $values = ['name' => 'Ursprünglicher Stand'];

        return DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => 'Kontakte', 'slot' => '3', 'locator' => ['subject' => 'Kontakt'], 'baseline' => $values, 'last_excel' => $values, 'fingerprint' => WorkbookReader::fingerprint($values), 'seen_rev' => 'old']);
    }

    private function workbook(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Disposition Übersicht');
        foreach (WorkbookReader::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'1', $label);
        }
        foreach (['B' => 'Testbahnhof', 'C' => '2026-09-15', 'D' => '08:00–10:00', 'G' => '[ENTWURF] Notiz', 'H' => 'WGM', 'I' => 'Testkunde'] as $column => $value) {
            $sheet->setCellValue($column.'2', $value);
        }
        $path = tempnam(sys_get_temp_dir(), 'rt-replacement-');
        try {
            (new Xlsx($book))->save($path);

            return file_get_contents($path);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }
}
