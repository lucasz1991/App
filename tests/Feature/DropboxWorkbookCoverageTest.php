<?php

namespace Tests\Feature;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Dropbox\EmployeeIdentityResolver;
use App\Services\Dropbox\NativeAvailabilityWorkbook;
use App\Services\Dropbox\WorkbookPackage;
use App\Services\Dropbox\WorkbookReader;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Process\Process;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class DropboxWorkbookCoverageTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        Queue::fake();
    }

    public function test_roster_includes_people_with_empty_notes_and_excludes_region_groups_without_colons(): void
    {
        $book = new Spreadsheet;
        $availability = $book->getActiveSheet()->setTitle('Mitarbeiterverfügbarkeit');
        $availability->mergeCells('B1:C1')->setCellValue('B1', 'Osten')->setCellValue('D1', 'Wochentage');
        $availability->setCellValue('B2', 'Erika Muster');
        $availability->setCellValue('B3', 'Max Beispiel')->setCellValue('C3', 'Montag frei');
        $planning = $book->createSheet()->setTitle('WTU Abrechnung + Übersicht');
        $this->planningRow($planning, 1, 2);
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $this->assertSame(['people' => 2, 'nonempty_notes' => 1, 'blank_note_cells' => 3], $parsed['coverage']['availability']);
        $this->assertSame(['rows' => 1, 'with_employee' => 0, 'without_employee' => 1], $parsed['coverage']['planning']);
        $this->assertSame([['sheet' => 'Mitarbeiterverfügbarkeit', 'row' => 1]], $parsed['availability_groups']);
        $facts = array_values(array_filter($parsed['rows'], fn ($row) => $row['domain'] === 'competencies'));
        $this->assertCount(4, $facts);
        $this->assertSame(['Erika Muster', 'Erika Muster', 'Max Beispiel', 'Max Beispiel'], array_column(array_column($facts, 'locator'), 'subject'));
        $this->assertSame(['C2', 'D2', 'C3', 'D3'], array_column($facts, 'slot'));
        $this->assertSame('', $facts[0]['values']['value']);
    }

    public function test_late_planning_headers_are_recognized_and_unknown_sheets_are_visible(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Einsatzplanung');
        $sheet->setCellValue('A1', 'Vorwort');
        $this->planningRow($sheet, 65, 66);
        $book->createSheet()->setTitle('Unbekannt')->setCellValue('B2', 'Freitext');
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $this->assertSame(65, $parsed['sheets']['Einsatzplanung']['header']);
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame(2, $parsed['coverage']['sheets_total']);
        $this->assertSame(1, $parsed['coverage']['sheets_recognized']);
        $this->assertSame([['sheet' => 'Unbekannt', 'reason' => 'unsupported_layout']], $parsed['coverage']['ignored_sheets']);
    }

    public function test_skipped_dates_and_retained_invalid_times_are_reported_without_dropping_the_valid_date_row(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Einsatzplanung');
        $this->planningRow($sheet, 1, 2);
        $sheet->setCellValue('D2', 'ungeklärt');
        $sheet->setCellValue('B3', 'Bahnhof')->setCellValue('D3', '08:00-10:00');
        $sheet->setCellValue('B4', 'Bahnhof')->setCellValue('C4', '31.02.2026')->setCellValue('D4', '08:00-10:00');
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $this->assertCount(1, $parsed['rows']);
        $this->assertNull($parsed['rows'][0]['values']['starts']);
        $this->assertSame(['planning_time_missing', 'planning_date_missing', 'invalid_date'], array_column($parsed['issues'], 'reason'));
        $this->assertTrue($parsed['issues'][0]['retained']);
        $this->assertSame(2, $parsed['coverage']['skipped_rows']);
    }

    public function test_weekly_staff_contact_sheet_is_read_without_guessing_name_parts(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Kontaktliste RailTime');
        $sheet->setCellValue('B2', 'Name')->setCellValue('C2', 'Position - Qualifikation')->setCellValue('D2', 'E-Mail');
        $sheet->setCellValue('A3', 1)->setCellValue('B3', 'Erika Maria Muster')->setCellValue('C3', 'WGM')->setCellValue('D3', 'erika@example.test');
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $this->assertSame('employee', $parsed['rows'][0]['locator']['kind']);
        $this->assertSame('Erika Maria Muster', $parsed['rows'][0]['locator']['subject']);
        $this->assertSame('erika@example.test', $parsed['rows'][0]['values']['contact_email']);
        $this->assertArrayNotHasKey('first_name', $parsed['rows'][0]['values']);
        $this->assertSame([], $parsed['coverage']['ignored_sheets']);
    }

    public function test_typed_native_availability_sheet_supplies_explicit_week_context_without_planning_rows(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle(NativeAvailabilityWorkbook::SHEET);
        foreach (NativeAvailabilityWorkbook::HEADERS as $column => $header) {
            $sheet->setCellValue($column.'1', $header);
        }
        foreach (['B' => 'Erika Muster', 'D' => 'verfügbar', 'E' => '2026-10-01', 'F' => '2026-10-11', 'H' => 'ja', 'K' => 'Europe/Berlin'] as $column => $value) {
            $sheet->setCellValue($column.'2', $value);
        }
        $parsed = app(WorkbookReader::class)->read($this->bytes($book), 'weekly');
        $this->assertSame(['2026-W40', '2026-W41'], $parsed['weeks']);
        $this->assertSame('availability_submission', $parsed['rows'][0]['locator']['kind']);
        $this->assertSame(0, $parsed['coverage']['planning']['rows']);
        $this->assertSame(1, $parsed['coverage']['sheets_recognized']);
    }

    public function test_disposable_reader_copy_prunes_unused_named_styles_and_preserves_blank_cell_colors_with_original_indexes(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Mitarbeiterverfügbarkeit');
        $sheet->setCellValue('B2', 'Erika Muster');
        $sheet->getStyle('C2')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFF0000');
        $package = new WorkbookPackage($this->bytes($book));
        $this->inflateUnusedStyles($package, 30000);
        $originalStyles = $package->xml('xl/styles.xml')->saveXML();
        $bytes = $package->bytes();
        $readerCopy = new WorkbookPackage($package->readerBytes());
        $this->assertSame(1, $readerCopy->xml('xl/styles.xml')->getElementsByTagNameNS(WorkbookPackage::NS, 'cellStyleXfs')->item(0)->getElementsByTagNameNS(WorkbookPackage::NS, 'xf')->length);
        $this->assertSame($originalStyles, $package->xml('xl/styles.xml')->saveXML());
        $originalSheet = $package->xml($package->sheets()['Mitarbeiterverfügbarkeit'])->saveXML();
        $this->assertSame($originalSheet, $readerCopy->xml($readerCopy->sheets()['Mitarbeiterverfügbarkeit'])->saveXML());

        $path = tempnam(sys_get_temp_dir(), 'rt-reader-memory-');
        try {
            file_put_contents($path, $bytes);
            $hash = hash_file('sha256', $path);
            $code = 'require '.var_export(base_path('vendor/autoload.php'), true).'; $app=require '.var_export(base_path('bootstrap/app.php'), true).'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $parsed=$app->make(App\\Services\\Dropbox\\WorkbookReader::class)->read(file_get_contents('.var_export($path, true).'),"weekly"); echo json_encode(["rows"=>count($parsed["rows"]),"color"=>$parsed["rows"][0]["values"]["color"],"peak"=>memory_get_peak_usage(true)]);';
            $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code], base_path(), null, null, 30);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(2, $result['rows']);
            $this->assertSame('FFFF0000', $result['color']);
            $this->assertLessThan(128 * 1024 * 1024, $result['peak']);
            $this->assertSame($hash, hash_file('sha256', $path));
        } finally {
            unlink($path);
        }
    }

    public function test_unique_active_profile_names_resolve_in_both_orders_with_accents_and_punctuation(): void
    {
        $connection = DropboxConnection::create();
        $employee = User::factory()->create(['name' => 'Profilname', 'role' => 'staff', 'status' => true]);
        UserProfile::create(['user_id' => $employee->id, 'first_name' => 'Erika Maria', 'last_name' => 'Müller']);
        $identity = $this->identity($connection, 'Muller, Erika Maria');
        $result = app(EmployeeIdentityResolver::class)->resolve($connection, $identity);
        $this->assertSame($employee->id, $result->user_id);
        $this->assertSame('employee', $result->kind);
        $this->assertSame(1, User::count());
        $this->assertSame($employee->email_verified_at?->toIso8601String(), $employee->fresh()->email_verified_at?->toIso8601String());
        $this->assertSame($result->revision, app(EmployeeIdentityResolver::class)->resolve($connection, $result)->revision);
    }

    public function test_ambiguous_inactive_provider_and_compound_names_remain_unmapped(): void
    {
        $connection = DropboxConnection::create();
        User::factory()->create(['name' => 'Erika Müller', 'role' => 'staff', 'status' => true]);
        User::factory()->create(['name' => 'Erika Muller', 'role' => 'staff', 'status' => true]);
        User::factory()->create(['name' => 'Max Beispiel', 'role' => 'staff', 'status' => false]);
        User::factory()->create(['name' => 'Externe Person', 'role' => 'staff', 'status' => true]);
        $service = app(EmployeeIdentityResolver::class);
        foreach (['Erika Muller', 'Max Beispiel', 'Erika Müller / Max Beispiel', 'Erika Müller und Max Beispiel'] as $name) {
            $this->assertNull($service->resolve($connection, $this->identity($connection, $name))->user_id);
        }
        $provider = $this->identity($connection, 'Externe Person', 'provider');
        $this->assertNull($service->resolve($connection, $provider)->user_id);
        $this->assertSame('provider', $provider->fresh()->kind);
        $this->assertSame(4, User::count());
    }

    public function test_existing_manual_mapping_and_other_connection_identity_are_preserved(): void
    {
        $connection = DropboxConnection::create();
        $other = DropboxConnection::create();
        $employee = User::factory()->create(['name' => 'Erika Muster', 'role' => 'staff', 'status' => true]);
        $manual = $this->identity($connection, 'Anderer Anzeigename');
        $manual->update(['kind' => 'employee', 'user_id' => $employee->id]);
        $service = app(EmployeeIdentityResolver::class);
        $this->assertSame($employee->id, $service->resolve($connection, $manual)->user_id);
        $foreign = $this->identity($other, 'Erika Muster');
        $this->assertNull($service->resolve($connection, $foreign)->user_id);
    }

    private function identity(DropboxConnection $connection, string $name, string $kind = 'unresolved'): DropboxIdentity
    {
        return DropboxIdentity::create(['connection_id' => $connection->id, 'alias' => WorkbookReader::normalize($name), 'kind' => $kind, 'details' => ['display_name' => $name]]);
    }

    private function planningRow(Worksheet $sheet, int $header, int $row): void
    {
        foreach (WorkbookReader::HEADERS as $column => $label) {
            $sheet->setCellValue($column.$header, $label);
        }
        foreach (['B' => 'Bahnhof', 'C' => '2026-10-05', 'D' => '08:00-10:00', 'H' => 'WGM', 'I' => 'Kunde'] as $column => $value) {
            $sheet->setCellValue($column.$row, $value);
        }
    }

    private function bytes(Spreadsheet $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rt-coverage-');
        try {
            (new Xlsx($book))->save($path);

            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    private function inflateUnusedStyles(WorkbookPackage $package, int $count): void
    {
        $doc = $package->xml('xl/styles.xml');
        $bases = $doc->getElementsByTagNameNS(WorkbookPackage::NS, 'cellStyleXfs')->item(0);
        $named = $doc->getElementsByTagNameNS(WorkbookPackage::NS, 'cellStyles')->item(0);
        $template = $bases->getElementsByTagNameNS(WorkbookPackage::NS, 'xf')->item(0);
        for ($index = 1; $index < $count; $index++) {
            $bases->appendChild($template->cloneNode(true));
            $style = $doc->createElementNS(WorkbookPackage::NS, 'cellStyle');
            $style->setAttribute('name', 'Unused '.$index);
            $style->setAttribute('xfId', (string) $index);
            $named->appendChild($style);
        }
        $bases->setAttribute('count', (string) $count);
        $named->setAttribute('count', (string) $count);
        $package->setXml('xl/styles.xml', $doc);
    }
}
