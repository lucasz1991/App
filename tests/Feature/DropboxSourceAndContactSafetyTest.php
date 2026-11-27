<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Dropbox\ClosingService;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\WorkbookReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxSourceAndContactSafetyTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const MATRIX_PATH = '/disposition/matrix.xlsx';

    private const RENAMED_MATRIX_PATH = '/disposition/andere-matrix.xlsx';

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
        $this->connection = DropboxConnection::create([
            'mode' => DropboxMode::Bidirectional, 'app_key' => 'fake', 'app_secret' => 'fake', 'refresh_token' => 'fake',
            'settings' => [...config('dropbox.defaults'), 'matrix_path' => self::MATRIX_PATH,
                'additional_sources' => [['path' => self::RENAMED_MATRIX_PATH, 'profile' => 'matrix']]],
        ]);
        $this->client = new FakeDropboxClient;
        $this->app->instance(DropboxClient::class, $this->client);
    }

    public function test_same_name_provider_cannot_overwrite_employee_and_an_invalid_manual_link_cannot_bypass_the_conflict(): void
    {
        [$identity, $profile] = $this->employee();
        $source = $this->register($this->providerWorkbook());

        $summary = app(FileSynchronizer::class)->sync($this->connection, $source);

        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame('0123456', $profile->fresh()->phone);
        $this->assertSame(0, DropboxRecord::count());
        $conflict = DropboxConflict::where('reason', 'mapping_required')->where('state', 'open')->firstOrFail();
        $this->assertSame('Dienstleister Übersicht', $conflict->snapshot['sheet']);
        $record = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'contacts', 'model_type' => 'DropboxIdentity', 'model_id' => $identity->id, 'metadata' => ['kind' => 'employee']]);
        $conflict->update(['decision' => ['record_id' => $record->id]]);

        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $this->assertSame('0123456', $profile->fresh()->phone);
        $this->assertSame('open', $conflict->fresh()->state);
        $this->assertSame(0, DropboxAppearance::count());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_preexisting_incompatible_appearance_cannot_export_employee_phone_to_provider_sheet(): void
    {
        [$identity, $profile] = $this->employee();
        $bytes = $this->providerWorkbook();
        $source = $this->register($bytes);
        $entry = app(WorkbookReader::class)->read($bytes, 'matrix')['rows'][0];
        $record = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'contacts', 'model_type' => 'DropboxIdentity', 'model_id' => $identity->id, 'metadata' => ['kind' => 'provider']]);
        DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot'], 'locator' => $entry['locator'], 'fingerprint' => $entry['fingerprint'], 'baseline' => $entry['values'], 'last_excel' => $entry['values']]);

        $summary = app(FileSynchronizer::class)->sync($this->connection, $source);

        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame('0123456', $profile->fresh()->phone);
        $this->assertSame(0, $this->client->uploads);
        $this->assertDatabaseHas('dropbox_conflicts', ['source_id' => $source->id, 'record_id' => $record->id, 'reason' => 'business_rule', 'state' => 'open']);
    }

    public function test_moved_outside_file_is_not_imported_or_archived_and_prevents_detachment(): void
    {
        $source = $this->register($this->providerWorkbook());
        $source->update(['processed_rev' => $source->rev, 'state' => 'synchronized']);
        $this->move('/ausserhalb/matrix.xlsx');
        $this->connection->update(['closing' => true, 'archive_path' => '/RailTime-Archiv-test']);

        $this->assertSourceUnavailable(fn () => app(FileSynchronizer::class)->sync($this->connection, $source));
        $this->assertSourceUnavailable(fn () => app(ClosingService::class)->archive($this->connection, $source));
        $this->assertSourceUnavailable(fn () => app(ClosingService::class)->finish($this->connection));

        $this->assertSame(DropboxMode::Bidirectional, $this->connection->fresh()->mode);
        $this->assertSame(0, DropboxRecord::count());
        $this->assertSame(0, DB::table('dropbox_archives')->count());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_allowed_rename_resumes_after_the_scanner_registers_the_path_and_keeps_the_source_id(): void
    {
        $source = $this->register($this->providerWorkbook());
        $metadata = $this->move(self::RENAMED_MATRIX_PATH);
        $this->assertSourceUnavailable(fn () => app(FileSynchronizer::class)->sync($this->connection, $source));

        $registered = app(SourceScanner::class)->register($this->connection, $metadata);

        $this->assertSame($source->id, $registered->id);
        $this->assertSame(self::RENAMED_MATRIX_PATH, $registered->path);

        $summary = app(FileSynchronizer::class)->sync($this->connection, $registered);

        $this->assertSame(0, $summary['conflicts']);
        $this->assertDatabaseHas('dropbox_identities', ['alias' => 'test person', 'kind' => 'provider', 'user_id' => null]);
    }

    public function test_new_file_at_the_old_path_requires_registration_before_import(): void
    {
        $source = $this->register($this->providerWorkbook());
        $this->move('/ausserhalb/matrix.xlsx');
        $replacement = $this->client->put(self::MATRIX_PATH, $this->providerWorkbook());
        $this->assertNotSame($source->file_id, $replacement['id']);

        $this->assertSourceUnavailable(fn () => app(FileSynchronizer::class)->sync($this->connection, $source));

        $this->assertSame(0, DropboxRecord::count());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_moved_subfolder_marks_only_sources_under_its_literal_path_as_missing(): void
    {
        $scanner = app(SourceScanner::class);
        $path = '/disposition/plan%_!';
        $nested = $scanner->register($this->connection, $this->client->put($path.'/unterordner/Aufträge KW 38 2026.xlsx', 'nested'));
        $direct = $scanner->register($this->connection, $this->client->put($path.'/Aufträge KW 39 2026.xlsx', 'direct'));
        $neighbor = $scanner->register($this->connection, $this->client->put($path.'-archiv/Aufträge KW 38 2026.xlsx', 'neighbor'));
        $wildcardNeighbor = $scanner->register($this->connection, $this->client->put('/disposition/plan-altx!/Aufträge KW 38 2026.xlsx', 'literal'));
        $neighbor->update(['state' => 'synchronized']);
        $wildcardNeighbor->update(['state' => 'synchronized']);
        $record = DropboxRecord::create(['connection_id' => $this->connection->id, 'domain' => 'planning', 'model_type' => 'Shift', 'model_id' => 1]);
        $appearance = DropboxAppearance::create(['source_id' => $nested->id, 'record_id' => $record->id, 'sheet' => 'Übersicht', 'slot' => '2',
            'locator' => [], 'fingerprint' => 'fingerprint', 'baseline' => ['notes' => 'Unverändert'], 'last_excel' => ['notes' => 'Unverändert']]);
        foreach ([$direct, $nested] as $source) {
            $source->update(['processed_rev' => $source->rev, 'state' => 'synchronized']);
            $file = $this->client->files[$source->path];
            unset($this->client->files[$source->path]);
            $file['metadata']['path_lower'] = str_replace('/disposition/', '/ausserhalb/', $source->path);
            $this->client->files[$file['metadata']['path_lower']] = $file;
        }

        $scanner->register($this->connection, ['.tag' => 'deleted', 'path_display' => '/Disposition/Plan%_!']);

        $this->assertSame('missing', $nested->fresh()->state);
        $this->assertSame('missing', $direct->fresh()->state);
        $this->assertSame('synchronized', $neighbor->fresh()->state);
        $this->assertSame('synchronized', $wildcardNeighbor->fresh()->state);
        $this->assertEqualsCanonicalizing([$direct->id, $nested->id], DropboxConflict::where('reason', 'source_missing')->pluck('source_id')->all());
        $this->assertSame($record->id, $appearance->fresh()->record_id);
        $this->assertSame(['notes' => 'Unverändert'], $appearance->fresh()->baseline);
    }

    public function test_late_folder_deletion_revalidates_sources_where_the_folder_exists_again(): void
    {
        $scanner = app(SourceScanner::class);
        $path = '/disposition/wochen';
        $kept = $scanner->register($this->connection, $this->client->put($path.'/Aufträge KW 38 2026.xlsx', 'first'));
        $removed = $scanner->register($this->connection, $this->client->put($path.'/Aufträge KW 39 2026.xlsx', 'removed'));
        $kept->update(['processed_rev' => $kept->rev, 'state' => 'synchronized']);
        unset($this->client->files[$removed->path]);
        $this->client->files[$path] = ['metadata' => ['.tag' => 'folder', 'id' => 'id:folder', 'path_lower' => $path]];
        $metadata = $this->client->put($kept->path, 'updated');

        $scanner->register($this->connection, ['.tag' => 'deleted', 'path_lower' => $path]);

        $this->assertSame('synchronized', $kept->fresh()->state);
        $this->assertSame($metadata['rev'], $kept->fresh()->rev);
        $this->assertSame('missing', $removed->fresh()->state);
        $this->assertSame($removed->id, DropboxConflict::where('reason', 'source_missing')->sole()->source_id);
    }

    private function employee(): array
    {
        $employee = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        $profile = UserProfile::create(['user_id' => $employee->id, 'phone' => '0123456']);
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'test person', 'kind' => 'employee', 'user_id' => $employee->id, 'details' => ['display_name' => 'Test Person', 'phone' => '0123456']]);

        return [$identity, $profile];
    }

    private function register(string $bytes): DropboxSource
    {
        return app(SourceScanner::class)->register($this->connection, $this->client->put(self::MATRIX_PATH, $bytes));
    }

    private function move(string $path): array
    {
        $file = $this->client->files[self::MATRIX_PATH];
        unset($this->client->files[self::MATRIX_PATH]);
        $file['metadata']['path_lower'] = $path;
        $file['metadata']['name'] = basename($path);
        $this->client->files[$path] = $file;

        return $file['metadata'];
    }

    private function providerWorkbook(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Dienstleister Übersicht');
        $sheet->setCellValue('A3', 1)->setCellValue('B3', 'Test Person')->setCellValueExplicit('E3', '0998765', DataType::TYPE_STRING);
        $file = tempnam(sys_get_temp_dir(), 'rt-dropbox-contact-');
        try {
            (new Xlsx($book))->save($file);

            return file_get_contents($file);
        } finally {
            $book->disconnectWorksheets();
            unlink($file);
        }
    }

    private function assertSourceUnavailable(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected stale source to be rejected.');
        } catch (DropboxApiException $e) {
            $this->assertSame('source_unavailable', $e->reason);
        }
    }
}
