<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Enums\OrderStatus;
use App\Enums\ShiftStatus;
use App\Livewire\Admin\DropboxSettings;
use App\Livewire\Admin\LocalExcelImport;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxWorkItem;
use App\Models\EmployeeCompetencyFact;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\SyncGuard;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Excel\LocalExcelImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class LocalExcelImportTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        (require database_path('migrations/2026_09_23_090000_create_local_excel_imports_table.php'))->up();
        Storage::fake('local');
        config(['cache.stores.file' => ['driver' => 'array']]);
        Cache::forgetDriver('file');
        Queue::fake();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Prüfregeln', 'is_active' => true, 'maximum_shift_minutes' => 720, 'minimum_rest_minutes' => 0, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'created_by' => 1, 'approved_at' => now()]);
    }

    private function workbook(string $employee = '', int $rows = 1, string $notes = 'Original'): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht');
        foreach (WorkbookReader::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'1', $label);
        }
        for ($i = 2; $i < $rows + 2; $i++) {
            foreach (['B' => 'Prüfbahnhof '.$i, 'C' => '2026-09-15', 'D' => '08:00–10:00', 'E' => $employee, 'F' => 'ZUG-'.$i, 'G' => $notes, 'H' => 'WGM', 'I' => 'Testkunde'] as $column => $value) {
                $sheet->setCellValue($column.$i, $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'local-xlsx-');
        try {
            (new Xlsx($book))->save($path);

            return file_get_contents($path);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_local_import_creates_real_drafts_and_repeat_is_idempotent_without_remote_work(): void
    {
        $service = app(LocalExcelImporter::class);
        $bytes = $this->workbook();
        $import = $service->prepare($bytes, 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $this->assertSame(0, Shift::count());
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame('done', $import->fresh()->status);
        $this->assertSame(1, Shift::count());
        $this->assertSame(ShiftStatus::Draft, Shift::first()->status);
        $this->assertSame(OrderStatus::Planned, Shift::first()->order->status);
        $historyCount = Shift::first()->order->statusHistory()->count();
        $this->assertSame(0, Shift::first()->published_revision);
        $this->assertSame($bytes, Storage::disk('local')->get($import->disk_path));
        $same = $service->prepare($bytes, 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $this->assertSame($import->id, $same->id);
        $service->start($same->id, $this->admin);
        $service->advance($same->id, $this->admin);
        $this->assertSame(1, Shift::count());
        $this->assertSame(1, DropboxAppearance::count());
        $this->assertSame($historyCount, Shift::first()->order->statusHistory()->count());
        $this->assertSame(0, DropboxWorkItem::count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_local_storno_import_also_cancels_the_order_without_publishing(): void
    {
        $service = app(LocalExcelImporter::class);
        $import = $service->prepare($this->workbook(notes: 'Storno'), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame('done', $import->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, Shift::first()->order->status);
        $this->assertSame(ShiftStatus::Cancelled, Shift::first()->status);
        $this->assertSame(0, Shift::first()->published_revision);
        $this->assertSame(0, DropboxWorkItem::count());
    }

    public function test_unmapped_person_is_held_until_explicit_assignment_then_real_staff_assignment_is_created(): void
    {
        $service = app(LocalExcelImporter::class);
        $staff = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        $import = $service->prepare($this->workbook('Test Person'), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame(0, Shift::count());
        $this->assertTrue(DropboxConflict::where('state', 'open')->exists());
        $service->assign(DropboxIdentity::first()->id, (string) $staff->id, $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame($staff->id, ShiftAssignment::first()->user_id);
        $this->assertFalse(DropboxConflict::where('state', 'open')->exists());
    }

    public function test_upload_preview_and_start_are_superadmin_only_and_render_actual_import_result(): void
    {
        $file = UploadedFile::fake()->createWithContent('Aufträge KW 38.xlsx', $this->workbook());
        $component = Livewire::actingAs($this->admin)->test(LocalExcelImport::class)
            ->set('upload', $file)->call('inspect')->assertHasNoErrors()->assertSee('Vorschau – noch nicht übernommen')
            ->call('start')->call('advance')->assertSee('Importdurchlauf abgeschlossen')->assertHasNoErrors();
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($other);
        $component->call('advance')->assertForbidden();
        Livewire::actingAs($other)->test(LocalExcelImport::class)->assertForbidden();
    }

    public function test_local_connection_never_replaces_dropbox_oauth_or_app_change_capture(): void
    {
        $remote = DropboxConnection::create(['mode' => DropboxMode::Off, 'settings' => config('dropbox.defaults')]);
        $service = app(LocalExcelImporter::class);
        $import = $service->prepare($this->workbook(), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame(0, DropboxWorkItem::count());
        Shift::first()->update(['notes' => 'In der App geändert']);
        $this->assertSame($remote->id, DropboxWorkItem::first()->connection_id);
        Livewire::actingAs($this->admin)->test(DropboxSettings::class)->assertSet('connectionId', $remote->id);
        $this->expectException(DropboxApiException::class);
        app(SyncGuard::class)->current($service->connection());
    }

    public function test_large_import_pauses_and_resumes_without_duplicate_records(): void
    {
        $service = app(LocalExcelImporter::class);
        $import = $service->prepare($this->workbook(rows: 205), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame(200, $import->fresh()->processed);
        $service->pause($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame(200, Shift::count());
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame(205, Shift::count());
        $this->assertSame('done', $import->fresh()->status);
    }

    public function test_changed_private_upload_is_rejected_without_importing_data(): void
    {
        $service = app(LocalExcelImporter::class);
        $import = $service->prepare($this->workbook(), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        Storage::disk('local')->put($import->disk_path, 'changed');
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame('failed', $import->fresh()->status);
        $this->assertSame(0, Shift::count());
    }

    public function test_local_test_account_is_explicit_and_does_not_modify_existing_accounts(): void
    {
        $service = app(LocalExcelImporter::class);
        $service->prepare($this->workbook('Neue Person'), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $identity = DropboxIdentity::first();
        $this->assertSame(1, User::count());
        $user = $service->assign($identity->id, 'new', $this->admin, 'Pruefpasswort-2026');
        $this->assertSame('staff', $user->role);
        $this->assertTrue(Hash::check('Pruefpasswort-2026', $user->password));
        $this->assertStringEndsWith('@railtime.invalid', $user->email);
        $this->assertSame($user->id, $identity->fresh()->user_id);
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_reimport_protects_conflicting_app_edits(): void
    {
        $service = app(LocalExcelImporter::class);
        $import = $service->prepare($this->workbook(), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        Shift::first()->update(['notes' => 'App-Wert']);
        $import = $service->prepare($this->workbook(notes: 'Excel-Wert'), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        $this->assertSame('App-Wert', Shift::first()->notes);
        $this->assertTrue(DropboxConflict::where('reason', 'field_conflict')->where('state', 'open')->exists());
    }

    public function test_matrix_import_updates_mapped_profile_and_preserves_reported_status(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Mitarbeiter Übersicht');
        foreach (['B1' => 'Mitarbeiter', 'B2' => 'Nachname', 'C2' => 'Vorname', 'A3' => 1, 'B3' => 'Person', 'C3' => 'Test', 'D3' => 'WGM', 'F3' => '0123456'] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $sheet = $book->createSheet()->setTitle('Freigaben-Sperrungen');
        foreach (['B1' => 'Mitarbeiter', 'D1' => 'Testkunde', 'B2' => 'Nachname', 'C2' => 'Vorname', 'B3' => 'Person', 'C3' => 'Test'] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $sheet->getStyle('D3')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFF0000');
        $path = tempnam(sys_get_temp_dir(), 'local-matrix-');
        try {
            (new Xlsx($book))->save($path);
            $service = app(LocalExcelImporter::class);
            $import = $service->prepare(file_get_contents($path), 'Kompetenzmatrix.xlsx', 'matrix', $this->admin);
            $staff = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
            $service->assign(DropboxIdentity::first()->id, (string) $staff->id, $this->admin);
            $service->start($import->id, $this->admin);
            $service->advance($import->id, $this->admin);
            $this->assertSame('done', $import->fresh()->status);
            $this->assertSame('0123456', $staff->profile()->first()->phone);
            $this->assertSame('FFFF0000', EmployeeCompetencyFact::first()->value['color']);
            $this->assertSame(0, EmployeeQualification::count());
            Http::assertNothingSent();
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_workflow_migration_can_resume_without_resetting_existing_revisions(): void
    {
        $service = app(LocalExcelImporter::class);
        $import = $service->prepare($this->workbook(), 'Aufträge KW 38.xlsx', 'weekly', $this->admin);
        $service->start($import->id, $this->admin);
        $service->advance($import->id, $this->admin);
        Shift::first()->forceFill(['published_revision' => 3])->save();
        $this->assertSame(3, Shift::first()->published_revision);
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $this->assertSame(3, Shift::first()->published_revision);
        $this->assertSame(1, Shift::count());
    }

    public function test_matrix_headers_extend_only_across_their_actual_merged_cells(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Einweisung');
        foreach (['B2' => 'Nachname', 'C2' => 'Vorname', 'B3' => 'Test', 'C3' => 'Person', 'D1' => 'Standort', 'D2' => 'Nord', 'E2' => 'Süd'] as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        $sheet->mergeCells('D1:E1');
        $sheet->getStyle('F3:H3')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF00FF00');
        $path = tempnam(sys_get_temp_dir(), 'local-matrix-');
        try {
            (new Xlsx($book))->save($path);
            $parsed = app(WorkbookReader::class)->read(file_get_contents($path), 'matrix');
            $this->assertSame(['Standort / Nord', 'Standort / Süd'], array_column(array_column($parsed['rows'], 'locator'), 'name'));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_inserting_an_equal_color_matrix_row_does_not_move_another_persons_fact(): void
    {
        $service = app(LocalExcelImporter::class);
        foreach ([['Alt'], ['Neu', 'Alt']] as $names) {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet()->setTitle('Freigaben-Sperrungen');
            foreach (['B1' => 'Mitarbeiter', 'D1' => 'Testkunde', 'B2' => 'Nachname', 'C2' => 'Vorname'] as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }
            foreach ($names as $index => $name) {
                $row = $index + 3;
                $sheet->setCellValue('B'.$row, $name)->setCellValue('C'.$row, 'Test');
                $sheet->getStyle('D'.$row)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFF0000');
            }
            $path = tempnam(sys_get_temp_dir(), 'local-matrix-');
            try {
                (new Xlsx($book))->save($path);
                $import = $service->prepare(file_get_contents($path), 'Kompetenzmatrix.xlsx', 'matrix', $this->admin);
                $service->start($import->id, $this->admin);
                $service->advance($import->id, $this->admin);
                $this->assertSame('done', $import->fresh()->status);
            } finally {
                unlink($path);
                $book->disconnectWorksheets();
            }
        }
        $this->assertSame(2, EmployeeCompetencyFact::count());
        foreach (DropboxAppearance::all() as $appearance) {
            $record = DropboxRecord::findOrFail($appearance->record_id);
            $fact = EmployeeCompetencyFact::findOrFail($record->model_id);
            $identity = DropboxIdentity::findOrFail($fact->identity_id);
            $this->assertSame(WorkbookReader::normalize($appearance->locator['subject']), $identity->alias);
        }
    }
}
