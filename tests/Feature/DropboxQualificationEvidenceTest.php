<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Jobs\Dropbox\ProcessDropboxWork;
use App\Models\Customer;
use App\Models\DropboxAppearance;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\EmployeeCompetencyFact;
use App\Models\EmployeeQualification;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dropbox\CompetencyRestrictions;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\ReportedQualificationEvidence;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\SourceSyncReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxQualificationEvidenceTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private const WEEKLY = '/Disposition/Aufträge KW 45 2026.xlsx';

    private const MATRIX = '/Disposition/Kompetenzmatrix.xlsx';

    private DropboxConnection $connection;

    private FakeDropboxClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables', '2026_07_18_000001_create_activity_log_table', '2026_09_22_100000_create_dropbox_sync_tables', '2026_10_11_070000_add_last_report_to_dropbox_sources'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        config(['dropbox.lock_store' => 'array']);
        Storage::fake('local');
        Queue::fake();
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Import, 'refresh_token' => 'fake', 'settings' => ['matrix_path' => self::MATRIX]]);
        $this->client = new FakeDropboxClient;
        $this->app->instance(DropboxClient::class, $this->client);
    }

    public function test_explicit_employee_cell_records_exact_catalog_matches_and_origin_without_granting_or_changing_proof(): void
    {
        $employee = $this->employee();
        $wagon = QualificationType::create(['name' => 'Wagenmeister', 'is_active' => true]);
        $shunting = QualificationType::create(['name' => 'Rangierbegleiter', 'is_active' => true]);
        QualificationType::create(['name' => 'Triebfahrzeugführer', 'is_active' => true]);
        QualificationType::create(['name' => 'Inaktiver Typ', 'is_active' => false]);
        $approved = EmployeeQualification::create(['user_id' => $employee->id, 'qualification_type_id' => $wagon->id,
            'status' => 'approved', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
        $beforeProof = $approved->fresh()->getAttributes();
        $reported = 'Wagenmeister, Rangierbegleiter; Triebfahrzeugführer nicht geprüft; Inaktiver Typ';
        $bytes = $this->contactWorkbook($reported);
        $source = $this->register(self::WEEKLY, $bytes);
        $initialRevision = $source->rev;

        app(FileSynchronizer::class)->sync($this->connection, $source, true);
        $this->assertSame(0, EmployeeCompetencyFact::count());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $fact = EmployeeCompetencyFact::where('kind', ReportedQualificationEvidence::KIND)->sole();
        $this->assertSame($employee->id, DropboxIdentity::findOrFail($fact->identity_id)->user_id);
        $appearance = DropboxAppearance::where('source_id', $source->id)->where('sheet', 'Kontaktliste Rail Time')->sole();
        $this->assertSame(['value' => $reported, 'color' => null, 'status' => 'reported', 'qualification_type_ids' => [$wagon->id, $shunting->id], 'origin' => [
            'source_id' => $source->id, 'file_id' => $source->file_id, 'appearance_id' => $appearance->id, 'sheet' => 'Kontaktliste Rail Time', 'cell' => 'C2', 'revision' => $initialRevision,
        ]], $fact->value);
        $this->assertSame($beforeProof, $approved->fresh()->getAttributes());
        $this->assertSame(1, EmployeeQualification::count());
        $this->assertDatabaseMissing('dropbox_work_items', ['resource' => 'EmployeeCompetencyFact:'.$fact->id]);
        $this->assertSame(1, $source->fresh()->last_report['detected']['qualification_reports']);
        $this->assertSame(1, $source->fresh()->last_report['stored']['qualification_reports']);
        $this->assertSame(1, $source->fresh()->last_report['stored']['qualification_accounts']);
        $this->assertSame(1, $source->fresh()->last_report['stored']['qualification_reports_with_type']);

        // MySQL JSON may return members in a different order at both levels.
        $reordered = array_reverse($fact->value, true);
        $reordered['origin'] = array_reverse($reordered['origin'], true);
        DB::table('employee_competency_facts')->where('id', $fact->id)->update(['value' => json_encode($reordered, JSON_THROW_ON_ERROR)]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->client->put(self::WEEKLY, $bytes);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame($fact->revision, $fact->fresh()->revision);
        $this->assertSame($initialRevision, $fact->fresh()->value['origin']['revision']);

        $cleared = $this->client->put(self::WEEKLY, $this->contactWorkbook(''));
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame($fact->revision + 1, $fact->fresh()->revision);
        $this->assertSame('withdrawn', $fact->fresh()->value['status']);
        $this->assertSame([], $fact->fresh()->value['qualification_type_ids']);
        $this->assertSame($cleared['rev'], $fact->fresh()->value['origin']['revision']);
        $this->assertSame(0, $source->fresh()->last_report['stored']['qualification_reports']);
        $this->assertSame($beforeProof, $approved->fresh()->getAttributes());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_unchanged_source_is_revisited_and_exact_employee_mapping_can_be_completed_later(): void
    {
        $source = $this->register(self::WEEKLY, $this->contactWorkbook('WGM'));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $source->refresh();
        $original = $source->processed_rev;
        $this->assertSame(0, EmployeeCompetencyFact::count());
        $this->assertSame(1, $source->last_report['detected']['qualification_reports']);
        $this->assertSame(0, $source->last_report['stored']['qualification_reports']);
        $source->update(['last_report' => [...$source->last_report, 'parser_version' => 'old-report']]);
        $employee = $this->employee();

        app(SourceScanner::class)->scan($this->connection);
        $work = DropboxWorkItem::where('resource', 'file:'.$source->id)->sole();
        $this->travelTo($work->available_at);
        (new ProcessDropboxWork($work->id))->handle();

        $fact = EmployeeCompetencyFact::where('kind', ReportedQualificationEvidence::KIND)->sole();
        $this->assertSame($original, $source->fresh()->processed_rev);
        $this->assertSame(SourceSyncReport::VERSION, $source->fresh()->last_report['parser_version']);
        $this->assertSame($employee->id, DropboxIdentity::findOrFail($fact->identity_id)->user_id);
        $this->assertSame('reported', $fact->value['status']);
        $this->assertSame([], $fact->value['qualification_type_ids']);
        $this->assertSame(0, EmployeeQualification::count());
    }

    public function test_active_and_inactive_namesakes_keep_the_employee_qualification_unassigned(): void
    {
        $this->employee();
        $this->employee(false);
        QualificationType::create(['name' => 'WGM', 'is_active' => true]);
        $source = $this->register(self::WEEKLY, $this->contactWorkbook('WGM'));

        app(FileSynchronizer::class)->sync($this->connection, $source);

        $identity = DropboxIdentity::where('alias', 'mara muster')->sole();
        $this->assertNull($identity->user_id);
        $this->assertSame('WGM', $identity->details[ReportedQualificationEvidence::FIELD]);
        $this->assertSame(0, EmployeeCompetencyFact::count());
        $this->assertSame(0, EmployeeQualification::count());
    }

    public function test_unchanged_matrix_binds_reported_customer_suspension_without_creating_a_positive_proof(): void
    {
        $source = $this->register(self::MATRIX, $this->matrixWorkbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $identity = DropboxIdentity::where('alias', 'mara muster')->sole();
        $this->assertNull($identity->user_id);
        $employee = $this->employee();

        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $this->assertSame($employee->id, $identity->fresh()->user_id);
        $fact = EmployeeCompetencyFact::where('scope', 'Prüfkunde')->sole();
        $this->assertSame(['value' => 'gesperrt', 'color' => null], $fact->value);
        $positive = EmployeeCompetencyFact::where('scope', 'Anderer Kunde')->sole();
        $this->assertSame('Freigabe gemeldet', $positive->value['value']);
        $this->assertSame(0, EmployeeQualification::count());
        $this->assertSame(0, QualificationType::count());
        $order = (new Order)->setRelation('customer', new Customer(['company_name' => 'Prüfkunde']));
        $shift = (new Shift)->setRelation('order', $order);
        $issues = app(CompetencyRestrictions::class)->forShift($shift, collect([$employee]));
        $this->assertSame('external_customer_block', $issues[$employee->id][0]['code']);
    }

    private function employee(bool $active = true): User
    {
        return User::factory()->create(['name' => 'Mara Muster', 'role' => 'staff', 'status' => $active]);
    }

    private function register(string $path, string $bytes): DropboxSource
    {
        return app(SourceScanner::class)->register($this->connection, $this->client->put($path, $bytes));
    }

    private function contactWorkbook(string $reported): string
    {
        $book = new Spreadsheet;
        $employees = $book->getActiveSheet()->setTitle('Kontaktliste Rail Time');
        $employees->fromArray([['Name', 'E-Mail', 'Position - Qualifikation'], ['Mara Muster', 'mara@example.test', $reported]]);
        $providers = $book->createSheet()->setTitle('Kontaktliste extern DL');
        $providers->setCellValue('A3', 1)->setCellValue('B3', 'Firma Muster GmbH')->setCellValue('C3', 'Wagenmeister');

        return $this->workbookBytes($book);
    }

    private function matrixWorkbook(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Freigaben-Sperrungen');
        $sheet->setCellValue('D1', 'Prüfkunde')->setCellValue('E1', 'Anderer Kunde');
        $sheet->setCellValue('B3', 'Muster')->setCellValue('C3', 'Mara')->setCellValue('D3', 'gesperrt')->setCellValue('E3', 'Freigabe gemeldet');

        return $this->workbookBytes($book);
    }

    private function workbookBytes(Spreadsheet $book): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rt-qualification-');
        try {
            (new Xlsx($book))->save($file);

            return file_get_contents($file);
        } finally {
            $book->disconnectWorksheets();
            unlink($file);
        }
    }
}
