<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Jobs\Dropbox\ProcessDropboxWork;
use App\Livewire\Admin\DropboxSettings;
use App\Models\Customer;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\EmployeeCompetencyFact;
use App\Models\EmployeeQualification;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\QualificationType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\WorkTimeEntry;
use App\Services\Dropbox\ClosingService;
use App\Services\Dropbox\CompetencyRestrictions;
use App\Services\Dropbox\ConnectionManager;
use App\Services\Dropbox\DomainAdapter;
use App\Services\Dropbox\DropboxApiException;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\RecordExporter;
use App\Services\Dropbox\RevisionUploader;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\TemplateService;
use App\Services\Dropbox\ThreeWayMerge;
use App\Services\Dropbox\WeekFileMatcher;
use App\Services\Dropbox\WorkbookPackage;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxSyncTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private DropboxConnection $connection;

    private FakeDropboxClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_07_18_000001_create_activity_log_table.php'))->up();
        (require database_path('migrations/2026_09_22_100000_create_dropbox_sync_tables.php'))->up();
        config(['dropbox.lock_store' => 'array']);
        Queue::fake();
        Storage::fake('local');
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Testregeln', 'is_active' => true, 'maximum_shift_minutes' => 720, 'minimum_rest_minutes' => 0, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'created_by' => 1, 'approved_at' => now()]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'app_key' => 'key', 'app_secret' => 'secret', 'refresh_token' => 'refresh', 'account_id' => 'dbid:test', 'settings' => config('dropbox.defaults')]);
        $this->client = new FakeDropboxClient;
        $this->app->instance(DropboxClient::class, $this->client);
    }

    private function workbook(string $notes = 'Anfahrt', string $employee = '', bool $personal = false, ?string $headerColor = null): string
    {
        $book = new Spreadsheet;
        $main = $book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht');
        $names = [$main];
        if ($personal) {
            $names[] = $book->createSheet()->setTitle($employee);
        }
        foreach ($names as $sheet) {
            foreach (WorkbookReader::HEADERS as $col => $label) {
                $sheet->setCellValue($col.'1', $label);
            }
            $values = ['B' => 'Testbahnhof', 'C' => '2026-09-15', 'D' => '08:00–10:00/–11:30', 'E' => $employee, 'F' => 'ZUG-42', 'G' => $notes, 'H' => 'WGM', 'I' => 'Testkunde'];
            foreach ($values as $col => $value) {
                $sheet->setCellValue($col.'2', $value);
            }
            $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            if ($headerColor) {
                $sheet->getStyle('B1')->getFill()->setFillType('solid')->getStartColor()->setARGB($headerColor);
            }
            $sheet->getComment('G2')->getText()->createTextRun('Kommentar bleibt erhalten');
        }
        $file = tempnam(sys_get_temp_dir(), 'rt-test-');
        try {
            (new Xlsx($book))->save($file);

            return file_get_contents($file);
        } finally {
            unlink($file);
            $book->disconnectWorksheets();
        }
    }

    private function source(string $bytes): DropboxSource
    {
        $meta = $this->client->put('/Disposition/Aufträge KW 38 aktuell.xlsx', $bytes);

        return DropboxSource::create(['connection_id' => $this->connection->id, 'file_id' => $meta['id'], 'path' => $meta['path_lower'], 'name' => $meta['name'], 'profile' => 'weekly', 'rev' => $meta['rev'], 'first_seen_at' => now()]);
    }

    public function test_three_way_merge_respects_each_copy_baseline_and_field_conflicts(): void
    {
        $merge = app(ThreeWayMerge::class);
        $result = $merge->merge(['note' => 'lokal', 'end' => '10:00'], [
            ['baseline' => ['note' => 'alt', 'end' => '10:00'], 'excel' => ['note' => 'alt', 'end' => '11:00']],
            ['baseline' => ['note' => 'alt', 'end' => '10:00'], 'excel' => ['note' => 'alt', 'end' => '10:00']],
        ]);
        $this->assertSame(['note' => 'lokal', 'end' => '11:00'], $result['values']);
        $this->assertEmpty($result['conflicts']);
        $this->assertArrayHasKey('note', $merge->merge(['note' => 'lokal'], [['baseline' => ['note' => 'alt'], 'excel' => ['note' => 'excel']]])['conflicts']);
    }

    public function test_week_detection_uses_iso_year_not_old_creation_dates(): void
    {
        $matcher = app(WeekFileMatcher::class);
        $this->assertSame(['week' => 3, 'year' => 2027], $matcher->match('Auftraege KW 03 2027 aktuell.xlsx'));
        $this->assertNull($matcher->match('Aufträge KW 38 (conflicted copy).xlsx'));
        $this->assertSame('2020-W53', $matcher->week('2021-01-01'));
        $this->assertNull(app(WorkbookReader::class)->date('18.05.20260'));
        $rule = 'Aufträge KW {KW} {YYYY} [aktuell].xlsx';
        $this->assertSame('Aufträge KW 03 2027.xlsx', $matcher->filename('2027-W03', $rule));
        $this->assertSame(['week' => 3, 'year' => 2027], $matcher->match('Aufträge KW 03 2027 aktuell.xlsx', $rule));
        $this->assertSame(['week' => 3, 'year' => 2027], $matcher->match($matcher->filename('2027-W03', $rule), $rule));
    }

    public function test_webhook_requires_signature_and_commits_sql_even_when_queue_is_unavailable(): void
    {
        $this->get('/api/webhooks/dropbox?challenge=abc-123')->assertOk()->assertSee('abc-123')->assertHeader('X-Content-Type-Options', 'nosniff');
        $body = '{"list_folder":{"accounts":["dbid:test"]}}';
        $this->call('POST', '/api/webhooks/dropbox', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertForbidden();
        $this->call('POST', '/api/webhooks/dropbox', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_DROPBOX_SIGNATURE' => hash_hmac('sha256', $body, 'secret')], $body)->assertOk();
        $this->assertSame(1, DropboxWorkItem::where('kind', 'scan')->count());
        $this->assertStringNotContainsString('secret', $this->connection->toJson());
        $this->assertNotSame('secret', DB::table('dropbox_connections')->value('app_secret'));
    }

    public function test_work_ledger_coalesces_without_postponing_or_losing_later_requests(): void
    {
        $ledger = app(WorkLedger::class);
        $first = $ledger->enqueue($this->connection, 'scan', 'scan');
        $this->travel(2)->seconds();
        $again = $ledger->enqueue($this->connection, 'scan', 'scan');
        $this->assertTrue($first->available_at->equalTo($again->available_at));
        $this->assertSame(2, $again->requested);
    }

    public function test_targeted_writer_preserves_all_untouched_package_parts_and_comments(): void
    {
        $bytes = $this->workbook();
        $package = new WorkbookPackage($bytes);
        $before = $package->partHashes();
        $package->patch(['WTU Abrechnung + Übersicht' => ['G2' => ['value' => '=Keine Formel']]]);
        $output = $package->bytes();
        $after = (new WorkbookPackage($output))->partHashes();
        $sheet = $package->sheets()['WTU Abrechnung + Übersicht'];
        foreach ($before as $part => $hash) {
            if ($part !== $sheet) {
                $this->assertSame($hash, $after[$part], $part);
            }
        }
        $this->assertSame('=Keine Formel', app(WorkbookReader::class)->read($output, 'weekly')['rows'][0]['values']['notes']);
    }

    public function test_bidirectional_import_and_export_updates_both_appearances_without_publishing_or_echo(): void
    {
        $employee = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'test person', 'kind' => 'employee', 'user_id' => $employee->id, 'details' => ['display_name' => 'Test Person']]);
        $source = $this->source($this->workbook(employee: 'Test Person', personal: true));
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source);
        $this->assertSame(1, Shift::count());
        $this->assertSame(2, DropboxAppearance::count());
        $shift = Shift::first();
        $this->assertSame(0, $shift->published_revision);
        $this->assertSame('11:30', $shift->disposition_details['actual_end']);
        $shift->notes = 'App-Änderung';
        $shift->save();
        $this->assertTrue(DropboxWorkItem::where('resource', 'Shift:'.$shift->id)->exists());
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $bytes = $this->client->download($this->connection, $source->file_id)['bytes'];
        foreach (app(WorkbookReader::class)->read($bytes, 'weekly')['rows'] as $row) {
            $this->assertSame('App-Änderung', $row['values']['notes']);
            $this->assertTrue($row['values']['draft']);
        }
        $uploads = $this->client->uploads;
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $this->assertSame($uploads, $this->client->uploads);
    }

    public function test_preview_does_not_mutate_business_data_or_write_dropbox(): void
    {
        $source = $this->source($this->workbook());
        $result = app(FileSynchronizer::class)->sync($this->connection->fresh(), $source, true);
        $this->assertSame(1, $result['new']);
        $this->assertSame(0, Shift::count());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_transaction_rollback_removes_business_change_and_export_intent(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source);
        $shift = Shift::first();
        $before = DropboxWorkItem::count();
        try {
            DB::transaction(function () use ($shift) {
                $shift->update(['notes' => 'rollback']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('Anfahrt', $shift->fresh()->notes);
        $this->assertSame($before, DropboxWorkItem::count());
    }

    public function test_new_app_shift_creates_clean_week_and_employee_sheet(): void
    {
        $template = app(TemplateService::class)->install($this->workbook());
        $this->connection->settings = [...$this->connection->settings, 'week_template' => $template, 'employee_template' => $template];
        $this->connection->save();
        $employee = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Testkunde', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Testauftrag', 'service_type' => 'WGM', 'status' => 'requested', 'priority' => 'normal', 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => '2026-10-02 00:00:00', 'timezone' => 'Europe/Berlin', 'required_staff' => 1]);
        $shift = Shift::create(['order_id' => $order->id, 'title' => 'Testdienst', 'role_name' => 'WGM', 'status' => 'draft', 'required_staff' => 1, 'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-01 10:00:00', 'timezone' => 'Europe/Berlin', 'location_name' => 'Testbahnhof']);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $employee->id, 'status' => 'requested', 'assigned_by' => 1]);
        app(RecordExporter::class)->export($this->connection->fresh(), 'Shift', $shift->id);
        $this->assertSame(1, $this->client->uploads);
        $this->assertCount(1, $this->client->files);
        $source = DropboxSource::first();
        $this->assertStringContainsString('40 2026', $source->name);
        $parsed = app(WorkbookReader::class)->read(array_values($this->client->files)[0]['bytes'], 'weekly');
        $this->assertCount(2, $parsed['rows']);
        $this->assertCount(2, $parsed['sheets']);
        $this->assertSame(2, DropboxAppearance::count());
    }

    public function test_settings_render_and_every_action_rejects_an_ordinary_admin(): void
    {
        $super = User::find(1);
        $other = User::factory()->create(['role' => 'admin', 'status' => true]);
        $component = Livewire::actingAs($super)->test(DropboxSettings::class)
            ->assertSee('Dropbox / Excel-Synchronisierung')->assertSee('OAuth-Rücksprungadresse')->assertDontSeeHtml('@js(')->assertSet('form.app_secret', '')
            ->set('panel', 'sources')->assertSee('Bereinigte Vorlagen')->set('panel', 'activity')->assertSee('Hintergrundverarbeitung');
        $this->actingAs($other);
        $component->call('stop')->assertForbidden();
        Livewire::actingAs($other)->test(DropboxSettings::class)->assertForbidden();
        $this->actingAs($other)->get(route('dropbox.connect'))->assertForbidden();
    }

    public function test_configuration_save_stops_sync_and_invalidates_old_jobs_without_exposing_secrets(): void
    {
        $item = app(WorkLedger::class)->enqueue($this->connection, 'scan', 'scan');
        $form = [...config('dropbox.defaults'), 'app_key' => 'key', 'app_secret' => ''];
        $saved = app(ConnectionManager::class)->save($this->connection, 1, $form, User::find(1));
        $this->assertSame(DropboxMode::Off, $saved->mode);
        $this->assertSame(2, $saved->generation);
        (new ProcessDropboxWork($item->id))->handle();
        $this->assertSame(0, $this->client->downloads);
        $this->assertSame('secret', $saved->app_secret);
    }

    public function test_same_field_conflict_holds_both_values_and_rechecks_resolution(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source);
        Shift::first()->update(['notes' => 'App-Version']);
        $file = $this->client->download($this->connection, $source->file_id);
        $package = new WorkbookPackage($file['bytes']);
        $package->patch(['WTU Abrechnung + Übersicht' => ['G2' => ['value' => '[ENTWURF] Excel-Version']]]);
        $this->client->put($source->path, $package->bytes());
        $before = $this->client->uploads;
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $conflict = DropboxConflict::where('reason', 'field_conflict')->firstOrFail();
        $this->assertSame('App-Version', Shift::first()->notes);
        $this->assertSame($before, $this->client->uploads);
        $component = Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->call('selectConflict', $conflict->id)
            ->set('choices.notes', 'app')->call('reviewDecision')->assertSeeHtml('wire:click="applyDecision"')->call('applyDecision')->assertHasNoErrors();
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $this->assertSame('resolved', $conflict->fresh()->state);
        $rows = app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows'];
        $this->assertSame('App-Version', $rows[0]['values']['notes']);
    }

    public function test_timeout_after_successful_upload_recovers_without_duplicate_rows_or_overwrite(): void
    {
        $template = app(TemplateService::class)->install($this->workbook());
        $this->connection->settings = [...$this->connection->settings, 'week_template' => $template, 'employee_template' => $template];
        $this->connection->save();
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source);
        Shift::first()->update(['notes' => 'Unklarer Upload']);
        $this->client->failAfterUpload = true;
        try {
            app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
            $this->fail('Timeout expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('network_timeout', $e->getMessage());
        }
        $uploaded = $this->client->uploads;
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $this->assertSame($uploaded, $this->client->uploads);
        $this->assertSame(1, Shift::count());
        $this->assertSame(1, DropboxAppearance::count());
    }

    public function test_preview_cursor_is_separate_from_automatic_import_and_off_never_dispatches_regular_work(): void
    {
        $this->client->put('/Disposition/Aufträge KW 38 aktuell.xlsx', $this->workbook());
        $this->connection->mode = DropboxMode::Off;
        $this->connection->save();
        app(SourceScanner::class)->scan($this->connection->fresh(), true);
        $this->assertSame(1, DB::table('dropbox_folders')->where('preview', true)->count());
        $this->assertSame(0, DB::table('dropbox_folders')->where('preview', false)->count());
        $ordinary = app(WorkLedger::class)->enqueue($this->connection, 'scan', 'scan', [], 0);
        app(WorkLedger::class)->dispatch();
        $this->assertNull($ordinary->fresh()->queued_until);
    }

    public function test_missing_row_never_deletes_app_data(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source);
        $template = app(TemplateService::class)->install($this->workbook());
        $this->client->put($source->path, app(TemplateService::class)->load($template));
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $this->assertSame(1, Shift::count());
        $this->assertTrue(DropboxConflict::where('reason', 'row_missing')->exists());
    }

    private function matrixWorkbook(): string
    {
        $book = new Spreadsheet;
        $contacts = $book->getActiveSheet()->setTitle('Mitarbeiter Übersicht');
        foreach (['B1' => 'Mitarbeiter', 'B2' => 'Nachname', 'C2' => 'Vorname', 'A3' => 1, 'B3' => 'Person', 'C3' => 'Test', 'D3' => 'WGM', 'E3' => 'test@example.invalid', 'F3' => '0123456'] as $cell => $value) {
            $contacts->setCellValue($cell, $value);
        }
        $matrix = $book->createSheet()->setTitle('Freigaben-Sperrungen');
        foreach (['B1' => 'Mitarbeiter', 'D1' => 'Testkunde', 'B2' => 'Nachname', 'C2' => 'Vorname', 'B3' => 'Person', 'C3' => 'Test'] as $cell => $value) {
            $matrix->setCellValue($cell, $value);
        }
        $matrix->getStyle('D3')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFF0000');
        $file = tempnam(sys_get_temp_dir(), 'rt-matrix-');
        try {
            (new Xlsx($book))->save($file);

            return file_get_contents($file);
        } finally {
            unlink($file);
            $book->disconnectWorksheets();
        }
    }

    public function test_contacts_and_color_only_competency_cells_sync_both_ways_without_qualification_approval(): void
    {
        $employee = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'test person', 'kind' => 'employee', 'user_id' => $employee->id, 'details' => ['display_name' => 'Test Person']]);
        $this->connection->settings = [...$this->connection->settings, 'matrix_path' => '/Disposition/Kompetenzmatrix.xlsx'];
        $this->connection->save();
        $metadata = $this->client->put('/Disposition/Kompetenzmatrix.xlsx', $this->matrixWorkbook());
        $source = app(SourceScanner::class)->register($this->connection, $metadata);
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source);
        $this->assertSame(1, EmployeeCompetencyFact::count());
        $fact = EmployeeCompetencyFact::first();
        $this->assertSame('FFFF0000', $fact->value['color']);
        $this->assertSame(0, EmployeeQualification::count());
        $profile = UserProfile::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame('0123456', $profile->phone);
        $profile->update(['phone' => '0987654']);
        $fact->update(['value' => ['value' => 'Freigabe gemeldet', 'color' => 'FF00B050']]);
        app(FileSynchronizer::class)->sync($this->connection->fresh(), $source->fresh());
        $rows = app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'matrix')['rows'];
        $this->assertSame('0987654', collect($rows)->firstWhere('domain', 'contacts')['values']['phone']);
        $this->assertSame('FF00B050', collect($rows)->firstWhere('domain', 'competencies')['values']['color']);
        $this->assertSame(0, EmployeeQualification::count());
    }

    public function test_selected_employee_template_really_supplies_styles_and_clean_sheet_structure(): void
    {
        $template = app(TemplateService::class)->install($this->workbook(headerColor: 'FF123456'));
        $package = new WorkbookPackage($this->workbook());
        $before = $package->partHashes();
        $package->addEmployeeSheet('Neu', 'WTU Abrechnung + Übersicht', [], app(TemplateService::class)->load($template));
        $bytes = $package->bytes();
        $after = (new WorkbookPackage($bytes))->partHashes();
        $this->assertSame($before['xl/worksheets/sheet1.xml'], $after['xl/worksheets/sheet1.xml']);
        $file = tempnam(sys_get_temp_dir(), 'rt-style-');
        file_put_contents($file, $bytes);
        try {
            $book = IOFactory::load($file);
            $this->assertSame('FF123456', $book->getSheetByName('Neu')->getStyle('B1')->getFill()->getStartColor()->getARGB());
            $this->assertNull($book->getSheetByName('Neu')->getCell('B2')->getValue());
            $this->assertCount(0, $book->getSheetByName('Neu')->getComments());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    public function test_manual_mapping_rechecks_business_contents_and_does_not_create_a_duplicate(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $package = new WorkbookPackage($this->client->download($this->connection, $source->file_id)['bytes']);
        $package->patch(['WTU Abrechnung + Übersicht' => ['B2' => ['value' => 'Neuer Bahnhof'], 'F2' => ['value' => 'NEU'], 'H2' => ['value' => 'Neue Tätigkeit'], 'I2' => ['value' => 'Neuer Kunde']]]);
        $this->client->put($source->path, $package->bytes());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $conflict = DropboxConflict::where('reason', 'mapping_required')->firstOrFail();
        $this->assertSame(1, Shift::count());
        Livewire::actingAs(User::find(1))->test(DropboxSettings::class)->call('selectConflict', $conflict->id)->set('targetRecord', (string) DropboxRecord::first()->id)->call('linkRow')->assertHasNoErrors();
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(1, Shift::count());
        $this->assertSame('Neuer Bahnhof', Shift::first()->location_name, DropboxConflict::all()->toJson());
        $this->assertSame('resolved', $conflict->fresh()->state);
    }

    public function test_large_matrix_resumes_after_chunk_without_duplicate_contacts(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Mitarbeiter Übersicht');
        for ($i = 1; $i <= 205; $i++) {
            $r = $i + 2;
            $sheet->setCellValue('A'.$r, $i);
            $sheet->setCellValue('B'.$r, 'Person'.$i);
            $sheet->setCellValue('C'.$r, 'Test');
        }
        $file = tempnam(sys_get_temp_dir(), 'rt-chunk-');
        try {
            (new Xlsx($book))->save($file);
            $bytes = file_get_contents($file);
        } finally {
            unlink($file);
            $book->disconnectWorksheets();
        }
        $this->connection->settings = [...$this->connection->settings, 'matrix_path' => '/Disposition/Matrix.xlsx'];
        $this->connection->save();
        $metadata = $this->client->put('/Disposition/Matrix.xlsx', $bytes);
        $source = app(SourceScanner::class)->register($this->connection, $metadata);
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $this->assertSame(200, DropboxRecord::count());
        $this->assertNull($source->fresh()->processed_rev);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(205, DropboxRecord::count());
        $this->assertSame($metadata['rev'], $source->fresh()->processed_rev);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame(205, DropboxRecord::count());
        $this->assertSame(0, $this->client->uploads);
    }

    public function test_pending_work_is_delayed_five_seconds_and_can_recover_from_redis_failure(): void
    {
        $item = app(WorkLedger::class)->enqueue($this->connection, 'scan', 'scan');
        app(WorkLedger::class)->dispatch();
        Queue::assertPushed(ProcessDropboxWork::class, fn ($job) => $job->workId === $item->id && $job->delay->equalTo($item->available_at) && $job->queue === 'dropbox-events');
        $item->update(['queued_until' => null]);
        Queue::swap(\Mockery::mock(Factory::class)->shouldReceive('connection')->andThrow(new \RuntimeException('Redis down'))->getMock());
        app(WorkLedger::class)->dispatch();
        $this->assertSame('queue_unavailable', $item->fresh()->error_code);
        $this->assertSame(0, $item->fresh()->completed);
        Queue::fake();
        app(WorkLedger::class)->dispatch();
        $this->assertNotNull($item->fresh()->queued_until);
    }

    public function test_import_only_preserves_app_edits_and_publication_removes_only_draft_marker(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $this->connection->update(['mode' => DropboxMode::Import]);
        Shift::first()->update(['notes' => 'Lokale Notiz']);
        $uploads = $this->client->uploads;
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame($uploads, $this->client->uploads);
        $this->assertSame('Lokale Notiz', Shift::first()->notes);
        $this->assertSame('pending_export', $source->fresh()->state);
        $this->connection->update(['mode' => DropboxMode::Bidirectional]);
        $shift = Shift::first();
        $shift->forceFill(['published_revision' => $shift->revision])->save();
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $row = app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows'][0];
        $this->assertFalse($row['values']['draft']);
        $this->assertSame('Lokale Notiz', $row['values']['notes']);
    }

    public function test_revision_conflict_keeps_durable_work_and_reloads_human_changes(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $old = $source->fresh()->rev;
        $human = new WorkbookPackage($this->client->download($this->connection, $source->file_id)['bytes']);
        $human->patch(['WTU Abrechnung + Übersicht' => ['G2' => ['value' => '[ENTWURF] Mensch']]]);
        $this->client->put($source->path, $human->bytes());
        try {
            app(RevisionUploader::class)->upload($this->connection, $source->path, $this->workbook('App'), $old);
            $this->fail('CAS must reject');
        } catch (DropboxApiException $e) {
            $this->assertSame('revision_conflict', $e->reason);
        }
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('Mensch', Shift::first()->notes, DropboxConflict::all()->toJson());
    }

    public function test_connection_change_cannot_resurrect_work_from_a_previous_generation(): void
    {
        $old = clone $this->connection;
        $this->connection->update(['generation' => 2, 'mode' => DropboxMode::Off]);
        $this->expectException(DropboxApiException::class);
        app(WorkLedger::class)->enqueue($old, 'scan', 'scan');
    }

    public function test_more_than_one_batch_of_obsolete_jobs_cannot_starve_current_work(): void
    {
        for ($i = 0; $i < 110; $i++) {
            app(WorkLedger::class)->enqueue($this->connection, 'export', 'Old:'.$i);
        }
        $this->connection->update(['generation' => 2]);
        $current = app(WorkLedger::class)->enqueue($this->connection, 'scan', 'scan');
        $this->assertSame(1, app(WorkLedger::class)->dispatch());
        Queue::assertPushed(ProcessDropboxWork::class, fn ($job) => $job->workId === $current->id);
    }

    public function test_closing_archives_verified_revisions_before_detaching(): void
    {
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $closing = app(ClosingService::class);
        $closing->start($this->connection, User::find(1));
        DropboxWorkItem::query()->update(['completed' => DB::raw('requested')]);
        $this->connection->update(['checked_at' => now()->addSecond()]);
        $closing->archive($this->connection->fresh(), $source->fresh());
        $archive = DB::table('dropbox_archives')->first();
        $this->assertNotNull($archive);
        $this->assertSame($this->client->download($this->connection, $source->file_id)['bytes'], $this->client->download($this->connection, $archive->archive_path)['bytes']);
        $closing->finish($this->connection->fresh());
        $this->assertSame(DropboxMode::AppOnly, $this->connection->fresh()->mode);
        $this->assertNull($this->connection->fresh()->refresh_token);
    }

    public function test_real_client_refreshes_expired_access_and_preserves_upload_cas_contract(): void
    {
        Http::preventStrayRequests();
        $this->connection->update(['access_token' => 'expired-access', 'expires_at' => now()->addHour(), 'namespace_id' => 'team-root']);
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'renewed-access', 'expires_in' => 14400]),
            'content.dropboxapi.com/2/files/upload' => Http::sequence()->push(['error_summary' => 'expired_access_token'], 401)->push(['rev' => 'new-rev']),
        ]);
        $result = (new DropboxClient)->upload($this->connection, '/Disposition/Test.xlsx', 'XLSX', 'old-rev');
        $this->assertSame('new-rev', $result['rev']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/files/upload')) {
                return false;
            }
            $arg = json_decode($request->header('Dropbox-API-Arg')[0], true);

            return $arg['mode'] === ['.tag' => 'update', 'update' => 'old-rev'] && $arg['strict_conflict'] === true && $arg['autorename'] === false && $request->hasHeader('Dropbox-API-Path-Root');
        });
        $this->assertSame('renewed-access', $this->connection->fresh()->access_token);
    }

    public function test_oauth_state_is_single_use_and_account_switch_starts_disabled(): void
    {
        Http::preventStrayRequests();
        $this->app->instance(DropboxClient::class, new DropboxClient);
        $response = $this->actingAs(User::find(1))->get(route('dropbox.connect'));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['token_access_type']);
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 14400, 'scope' => implode(' ', config('dropbox.scopes'))]),
            'api.dropboxapi.com/2/users/get_current_account' => Http::response(['account_id' => 'dbid:different', 'name' => ['display_name' => 'Neu'], 'root_info' => ['root_namespace_id' => 'new-root']]),
        ]);
        $url = route('dropbox.callback').'?'.http_build_query(['state' => $query['state'], 'code' => 'once']);
        $this->get($url)->assertRedirect('/administrator/settings');
        $this->get($url)->assertForbidden();
        $this->assertSame(2, DropboxConnection::count());
        $this->assertSame(DropboxMode::Off, DropboxConnection::latest('id')->first()->mode);
        $this->assertNull($this->connection->fresh()->refresh_token);
    }

    public function test_color_block_is_used_in_eligibility_but_green_never_creates_an_approved_proof(): void
    {
        $employee = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'test person', 'kind' => 'employee', 'user_id' => $employee->id, 'details' => ['display_name' => 'Test Person']]);
        $source = $this->source($this->workbook());
        app(FileSynchronizer::class)->sync($this->connection, $source);
        EmployeeCompetencyFact::create(['identity_id' => $identity->id, 'kind' => 'customer_authorization', 'name' => 'Testkunde', 'scope' => 'Testkunde', 'value' => ['value' => '', 'color' => 'FFFF0000']]);
        $issues = app(CompetencyRestrictions::class)->forShift(Shift::first(), collect([$employee]));
        $this->assertSame('external_customer_block', $issues[$employee->id][0]['code']);
        $this->assertSame(0, EmployeeQualification::count());
    }

    public function test_new_app_contact_is_appended_and_unmapped_customer_is_visible(): void
    {
        $this->connection->settings = [...$this->connection->settings, 'matrix_path' => '/Disposition/Matrix.xlsx'];
        $this->connection->save();
        $source = app(SourceScanner::class)->register($this->connection, $this->client->put('/Disposition/Matrix.xlsx', $this->matrixWorkbook()));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $person = User::factory()->create(['role' => 'staff', 'status' => true]);
        $profile = UserProfile::create(['user_id' => $person->id, 'first_name' => 'Neu', 'last_name' => 'Mitarbeiter', 'phone' => '1234']);
        app(RecordExporter::class)->export($this->connection, 'UserProfile', $profile->id);
        $rows = app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'matrix')['rows'];
        $this->assertCount(2, array_filter($rows, fn ($r) => $r['domain'] === 'contacts'));
        $new = collect($rows)->first(fn ($r) => ($r['values']['first_name'] ?? '') === 'Neu');
        $this->assertSame('1234', $new['values']['phone']);
        $customer = Customer::create(['company_name' => 'Ohne Einsatz', 'is_active' => true]);
        app(RecordExporter::class)->export($this->connection, 'Customer', $customer->id);
        $this->assertTrue(DropboxConflict::where('reason', 'not_representable')->exists());
    }

    public function test_app_proof_projection_and_excel_report_never_approve_or_rewrite_proof(): void
    {
        $person = User::factory()->create(['role' => 'staff', 'status' => true]);
        $identity = DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'test person', 'kind' => 'employee', 'user_id' => $person->id]);
        $type = QualificationType::create(['name' => 'Dokument', 'is_active' => true]);
        $proof = EmployeeQualification::create(['user_id' => $person->id, 'qualification_type_id' => $type->id, 'status' => 'approved', 'valid_from' => '2026-01-01', 'valid_until' => '2026-12-31']);
        $fact = EmployeeCompetencyFact::create(['identity_id' => $identity->id, 'kind' => 'document', 'name' => 'Dokument / gültig bis', 'scope' => 'Dokument', 'value' => ['value' => '', 'color' => null], 'qualification_type_id' => $type->id, 'qualification_field' => 'valid_until']);
        app(RecordExporter::class)->export($this->connection, 'EmployeeQualification', $proof->id, true);
        $this->assertSame('', $fact->fresh()->value['value']);
        $this->assertSame('2026-12-31', json_decode(DB::table('dropbox_runs')->latest('id')->value('summary'), true)['preview_values']['value']);
        $this->assertSame(0, $this->client->uploads);
        $this->assertFalse(DropboxConflict::where('reason', 'not_representable')->exists());
        $records = app(DomainAdapter::class)->recordsFor($this->connection, 'EmployeeQualification', $proof->id);
        $this->assertSame('2026-12-31', $fact->fresh()->value['value']);
        $this->assertCount(1, $records);
        app(DomainAdapter::class)->apply($this->connection, $records->first(), ['value' => '2026-10-01', 'color' => 'FFFF0000']);
        $this->assertSame('2026-12-31', $proof->fresh()->valid_until->format('Y-m-d'));
        $this->assertSame('approved', $proof->fresh()->status);
        $proof->update(['status' => 'revoked']);
        app(DomainAdapter::class)->recordsFor($this->connection, 'EmployeeQualification', $proof->id);
        $this->assertSame('FFFF0000', $fact->fresh()->value['color']);
        $this->assertSame('', $fact->fresh()->value['value']);
    }

    public function test_app_time_end_exports_and_excel_correction_stays_an_unapproved_report(): void
    {
        $person = User::factory()->create(['name' => 'Test Person', 'role' => 'staff', 'status' => true]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'test person', 'kind' => 'employee', 'user_id' => $person->id, 'details' => ['display_name' => 'Test Person']]);
        $source = $this->source($this->workbook(employee: 'Test Person', personal: true));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $assignment = ShiftAssignment::first();
        $time = WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $person->id, 'status' => 'submitted', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-09-15 08:00:00', 'ends_at' => '2026-09-15 12:00:00', 'plan_snapshot' => []]);
        $this->assertTrue(DropboxWorkItem::where('resource', 'WorkTimeEntry:'.$time->id)->exists());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $package = new WorkbookPackage($this->client->download($this->connection, $source->file_id)['bytes']);
        $package->patch(['WTU Abrechnung + Übersicht' => ['D2' => ['value' => '08:00–10:00/–12:30']]]);
        $this->client->put($source->path, $package->bytes());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('submitted', $time->fresh()->status);
        $this->assertSame('12:00', $time->fresh()->ends_at->format('H:i'));
        $record = DropboxRecord::where('domain', 'planning')->first();
        $this->assertSame('12:30', app(DomainAdapter::class)->current($record)['actual_end']);
    }

    public function test_http_rate_limit_and_cursor_reset_have_recoverable_codes(): void
    {
        $this->connection->update(['access_token' => 'valid', 'expires_at' => now()->addHour()]);
        Http::fake(['api.dropboxapi.com/2/files/list_folder/continue' => Http::sequence()->push(['error' => ['.tag' => 'reset']], 409)->push([], 429, ['Retry-After' => '120'])]);
        foreach (['cursor_reset' => 30, 'rate_limit' => 120] as $code => $delay) {
            try {
                (new DropboxClient)->rpc($this->connection, 'files/list_folder/continue', ['cursor' => 'expired']);
                $this->fail('Exception expected');
            } catch (DropboxApiException $e) {
                $this->assertSame($code, $e->reason);
                $this->assertSame($delay, $e->retryAfter);
            }
        }
    }

    public function test_scanner_recovers_invalid_cursor_and_tracks_renamed_file_by_id(): void
    {
        $meta = $this->client->put('/Disposition/Aufträge KW 38 aktuell.xlsx', $this->workbook());
        $scanner = app(SourceScanner::class);
        $scanner->scan($this->connection);
        $source = DropboxSource::first();
        $meta['name'] = 'Auftraege KW 38 2026.xlsx';
        $meta['path_lower'] = '/disposition/auftraege kw 38 2026.xlsx';
        $scanner->register($this->connection, $meta);
        $this->assertSame(1, DropboxSource::count());
        $this->assertSame($meta['path_lower'], $source->fresh()->path);
        $this->connection->update(['access_token' => 'valid', 'expires_at' => now()->addHour()]);
        Http::fake([
            'api.dropboxapi.com/2/files/list_folder/continue' => Http::response(['error' => ['.tag' => 'reset']], 409),
            'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => [$meta], 'cursor' => 'fresh-cursor', 'has_more' => false]),
        ]);
        $this->app->instance(DropboxClient::class, new DropboxClient);
        app(SourceScanner::class)->scan($this->connection);
        $this->assertSame('fresh-cursor', DB::table('dropbox_folders')->value('cursor'));
        $this->assertTrue(DropboxWorkItem::where('kind', 'file')->exists());
    }

    public function test_excel_employee_change_retires_old_copy_and_creates_the_correct_new_personal_sheet(): void
    {
        foreach (['Alte Person', 'Neue Person'] as $name) {
            $person = User::factory()->create(['name' => $name, 'role' => 'staff', 'status' => true]);
            DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => mb_strtolower($name), 'kind' => 'employee', 'user_id' => $person->id, 'details' => ['display_name' => $name]]);
        }
        $template = app(TemplateService::class)->install($this->workbook());
        $this->connection->update(['settings' => [...$this->connection->settings, 'employee_template' => $template]]);
        $source = $this->source($this->workbook(employee: 'Alte Person', personal: true));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $package = new WorkbookPackage($this->client->download($this->connection, $source->file_id)['bytes']);
        $package->patch(['WTU Abrechnung + Übersicht' => ['E2' => ['value' => 'Neue Person']]]);
        $this->client->put($source->path, $package->bytes());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        app(RecordExporter::class)->export($this->connection, 'Shift', Shift::first()->id);
        $rows = collect(app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows']);
        $this->assertTrue($rows->firstWhere('sheet', 'Alte Person')['values']['cancelled']);
        $this->assertSame('Neue Person', $rows->firstWhere('sheet', 'Neue Person')['values']['employee']);
        $this->assertSame('Neue Person', $rows->firstWhere('sheet', 'WTU Abrechnung + Übersicht')['values']['employee']);
        $this->assertSame(1, Shift::count());
    }

    public function test_excel_cancellation_of_one_assignment_does_not_cancel_the_other_employee(): void
    {
        $people = [];
        foreach (['Erste Person', 'Zweite Person'] as $name) {
            $person = User::factory()->create(['name' => $name, 'role' => 'staff', 'status' => true]);
            $people[] = $person;
            DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => mb_strtolower($name), 'kind' => 'employee', 'user_id' => $person->id, 'details' => ['display_name' => $name]]);
        }
        $template = app(TemplateService::class)->install($this->workbook());
        $this->connection->update(['settings' => [...$this->connection->settings, 'employee_template' => $template]]);
        $source = $this->source($this->workbook(employee: 'Erste Person', personal: true));
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $shift = Shift::first();
        $second = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $people[1]->id, 'status' => 'requested', 'assigned_by' => 1]);
        app(RecordExporter::class)->export($this->connection, 'Shift', $shift->id);
        $package = new WorkbookPackage($this->client->download($this->connection, $source->file_id)['bytes']);
        $package->patch(['WTU Abrechnung + Übersicht' => ['K2' => ['value' => 'Storno']]]);
        $this->client->put($source->path, $package->bytes());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('requested', $second->fresh()->status->value);
        $this->assertNotSame('cancelled', $shift->fresh()->status->value);
        $rows = collect(app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows']);
        $this->assertTrue($rows->firstWhere('sheet', 'Erste Person')['values']['cancelled'], DropboxConflict::all()->toJson());
        $this->assertFalse($rows->firstWhere('sheet', 'Zweite Person')['values']['cancelled']);
    }
}
