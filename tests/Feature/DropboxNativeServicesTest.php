<?php

namespace Tests\Feature;

use App\Enums\DropboxMode;
use App\Enums\ShiftStatus;
use App\Models\DropboxAppearance;
use App\Models\DropboxConflict;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\DropboxWorkItem;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Dropbox\DomainAdapter;
use App\Services\Dropbox\DropboxClient;
use App\Services\Dropbox\FileSynchronizer;
use App\Services\Dropbox\RecordExporter;
use App\Services\Dropbox\SourceScanner;
use App\Services\Dropbox\SyncContext;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkbookWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\Support\FakeDropboxClient;
use Tests\TestCase;

class DropboxNativeServicesTest extends TestCase
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
        (require database_path('migrations/2026_10_11_070000_add_last_report_to_dropbox_sources.php'))->up();
        Queue::fake();
        Storage::fake('local');
        config(['dropbox.lock_store' => 'array']);
        User::factory()->create(['id' => 1, 'role' => 'admin', 'status' => true]);
        $this->connection = DropboxConnection::create(['mode' => DropboxMode::Bidirectional, 'app_key' => 'key', 'app_secret' => 'secret', 'refresh_token' => 'refresh', 'settings' => config('dropbox.defaults')]);
        $this->client = new FakeDropboxClient;
        $this->app->instance(DropboxClient::class, $this->client);
    }

    public function test_service_fields_roundtrip_both_directions_without_duplicate_orders_or_shifts(): void
    {
        $source = $this->source();
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $order = Order::firstOrFail();
        $this->assertSame('Bahnhof', $order->location_name);
        $this->assertSame($order->order_number, $this->row($source)['values']['service_reference']);
        $order->update(['title' => 'Neue Leistung', 'service_type' => 'Rangierbegleitung', 'location_name' => 'Leistungsbereich Nord']);
        $this->assertTrue(DropboxWorkItem::where('resource', 'Order:'.$order->id)->exists());
        app(RecordExporter::class)->export($this->connection, 'Order', $order->id);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $row = $this->row($source);
        $this->assertSame('Neue Leistung', $row['values']['service_title']);
        $this->assertSame('Rangierbegleitung', $row['values']['service_type']);
        $this->assertSame('Leistungsbereich Nord', $row['values']['service_location']);
        $this->assertSame('Bahnhof', $row['values']['location']);
        $this->edit($source, ['service_title' => 'Excel-Leistung', 'service_type' => 'Wagenprüfung', 'service_location' => 'Bereich Süd']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('Excel-Leistung', $order->fresh()->title);
        $this->assertSame('Wagenprüfung', $order->fresh()->service_type);
        $this->assertSame('Bereich Süd', $order->fresh()->location_name);
        $uploads = $this->client->uploads;
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame($uploads, $this->client->uploads);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Shift::count());
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
    }

    public function test_two_simultaneous_service_title_edits_are_not_silently_overwritten(): void
    {
        $source = $this->source();
        app(FileSynchronizer::class)->sync($this->connection, $source);
        Order::first()->update(['title' => 'App-Änderung']);
        $this->edit($source, ['service_title' => 'Excel-Änderung']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('App-Änderung', Order::first()->title);
        $this->assertSame('Excel-Änderung', $this->row($source)['values']['service_title']);
        $this->assertTrue(DropboxConflict::where('reason', 'field_conflict')->where('state', 'open')->exists());
    }

    public function test_explicit_service_reference_groups_new_shifts_but_train_reference_does_not(): void
    {
        $entry = app(WorkbookReader::class)->read($this->workbook(), 'weekly')['rows'][0];
        $entry['values']['service_reference'] = 'KUNDE-A-42';
        $one = app(DomainAdapter::class)->create($this->connection, $entry);
        $entry['values']['date'] = '2026-09-16';
        $two = app(DomainAdapter::class)->create($this->connection, $entry);
        $this->assertSame(Shift::find($one->model_id)->order_id, Shift::find($two->model_id)->order_id);
        $this->assertSame(1, Order::count());
        unset($entry['values']['service_reference']);
        app(DomainAdapter::class)->create($this->connection, $entry);
        $this->assertSame(2, Order::count());
        $this->assertSame(3, Shift::count());
    }

    public function test_reference_cannot_reassign_an_existing_shift_or_cross_customer_boundary(): void
    {
        $entry = app(WorkbookReader::class)->read($this->workbook(), 'weekly')['rows'][0];
        $entry['values']['service_reference'] = 'KUNDE-A-42';
        $record = app(DomainAdapter::class)->create($this->connection, $entry);
        foreach (['service_reference' => 'OTHER', 'customer' => 'Anderer Kunde'] as $field => $value) {
            try {
                $values = [...$entry['values'], $field => $value];
                if ($field === 'customer') {
                    app(DomainAdapter::class)->create($this->connection, [...$entry, 'values' => $values]);
                } else {
                    app(DomainAdapter::class)->apply($this->connection, $record, $values);
                }
                $this->fail('Conflicting reference must require review');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Shift::count());
    }

    public function test_new_row_blank_service_cells_do_not_erase_an_existing_service(): void
    {
        $entry = app(WorkbookReader::class)->read($this->workbook(), 'weekly')['rows'][0];
        $record = app(DomainAdapter::class)->create($this->connection, $entry);
        $order = Shift::find($record->model_id)->order;
        $entry['values'] = [...$entry['values'], 'date' => '2026-09-16', 'service_reference' => $order->order_number,
            'service_title' => '', 'service_type' => '', 'service_location' => ''];
        app(DomainAdapter::class)->create($this->connection, $entry);
        $this->assertSame('Bahnhof', $order->fresh()->location_name);
        $this->assertSame('Bahnhof', $order->fresh()->title);
        $this->assertSame('WGM', $order->fresh()->service_type);
        $this->assertSame(1, Order::count());
    }

    public function test_existing_service_place_can_be_cleared_without_being_backfilled_again(): void
    {
        $source = $this->source();
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $this->edit($source, ['service_location' => '']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('', Order::first()->location_name);
        $this->assertSame('Bahnhof', Shift::first()->location_name);
    }

    public function test_optional_native_service_type_can_be_cleared_and_continue_synchronizing(): void
    {
        $source = $this->source();
        app(FileSynchronizer::class)->sync($this->connection, $source);
        Order::first()->update(['service_type' => null]);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('', $this->row($source)['values']['service_type']);
        $this->edit($source, ['service_title' => 'Weiter bearbeitet']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame('Weiter bearbeitet', Order::first()->title);
        $this->assertNull(Order::first()->service_type);
    }

    public function test_unchanged_legacy_rows_backfill_history_and_service_place_once(): void
    {
        $person = User::factory()->create(['name' => 'Erika Muster', 'role' => 'staff', 'status' => true]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'erika muster', 'kind' => 'employee', 'user_id' => $person->id]);
        $entry = app(WorkbookReader::class)->read($this->workbook(), 'weekly')['rows'][0];
        $record = app(DomainAdapter::class)->create($this->connection, $entry);
        SyncContext::import(function () use ($record, $person) {
            $shift = Shift::find($record->model_id);
            $shift->update(['status' => ShiftStatus::Completed]);
            $shift->order->update(['location_name' => null]);
            $record->update(['metadata' => ['imported_order' => true, 'employee_label' => 'Erika Muster', 'user_id' => $person->id]]);
        });
        app(DomainAdapter::class)->reconcileNative($this->connection, $record);
        $this->assertSame('Bahnhof', Shift::find($record->model_id)->order->location_name);
        $this->assertSame('reported', ShiftAssignment::findOrFail($record->assignment_id)->status->value);
        $this->assertSame(0, ShiftAssignment::blocking()->count());
        SyncContext::import(fn () => Shift::find($record->model_id)->order->update(['location_name' => '']));
        app(DomainAdapter::class)->reconcileNative($this->connection, $record);
        $this->assertSame('', Shift::find($record->model_id)->order->location_name);
        $this->assertSame(1, ShiftAssignment::count());
    }

    public function test_service_headers_bootstrap_across_chunks_without_conflicts_for_untouched_rows(): void
    {
        $this->connection->update(['settings' => [...$this->connection->settings, 'import_chunk_size' => 50]]);
        $bytes = $this->workbook(55);
        $source = app(SourceScanner::class)->register($this->connection, $this->client->put('/Disposition/Aufträge KW 38 2026.xlsx', $bytes));
        foreach (app(WorkbookReader::class)->read($bytes, 'weekly')['rows'] as $entry) {
            $record = app(DomainAdapter::class)->create($this->connection, $entry);
            DropboxAppearance::create(['source_id' => $source->id, 'record_id' => $record->id, 'sheet' => $entry['sheet'], 'slot' => $entry['slot'],
                'locator' => $entry['locator'], 'fingerprint' => $entry['fingerprint'], 'baseline' => $entry['values'], 'last_excel' => $entry['values'], 'seen_rev' => $source->rev]);
        }
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $this->assertSame(50, $source->fresh()->progress['next']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertNull($source->fresh()->progress);
        $this->assertSame(55, Order::count());
        $this->assertSame(55, Shift::count());
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
        $rows = app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows'];
        $this->assertCount(55, array_filter($rows, fn ($row) => filled($row['values']['service_reference'] ?? null)));
    }

    public function test_service_fields_with_recorded_time_sync_without_mutating_shift_assignment_or_proof(): void
    {
        [$source, $shift, $assignment, $time] = $this->sourceWithTimeProof();
        $shiftBefore = $shift->getRawOriginal();
        $assignmentBefore = $assignment->getRawOriginal();
        $timeBefore = $time->getRawOriginal();

        $this->edit($source, ['service_title' => 'Bearbeitete Leistung', 'service_type' => 'Wagenprüfung', 'service_location' => 'Leistungsbereich Süd']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $order = $shift->order->fresh();
        $this->assertSame('Bearbeitete Leistung', $order->title);
        $this->assertSame('Wagenprüfung', $order->service_type);
        $this->assertSame('Leistungsbereich Süd', $order->location_name);
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame($timeBefore, $time->fresh()->getRawOriginal());
        $this->assertSame(1, Shift::count());
        $this->assertSame(1, ShiftAssignment::count());
        $this->assertSame(1, WorkTimeEntry::count());
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame($timeBefore, $time->fresh()->getRawOriginal());
    }

    public function test_actual_shift_schedule_change_with_recorded_time_still_blocks_the_whole_import(): void
    {
        [$source, $shift, $assignment, $time] = $this->sourceWithTimeProof();
        $orderBefore = $shift->order->getRawOriginal();
        $shiftBefore = $shift->getRawOriginal();
        $assignmentBefore = $assignment->getRawOriginal();
        $timeBefore = $time->getRawOriginal();

        $this->edit($source, ['service_title' => 'Darf nicht übernommen werden', 'starts' => '09:00']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $this->assertSame($orderBefore, $shift->order->fresh()->getRawOriginal());
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame($timeBefore, $time->fresh()->getRawOriginal());
        $conflict = DropboxConflict::where('reason', 'business_rule')->where('state', 'open')->firstOrFail();
        $this->assertSame(['Für diese Schicht wurden bereits Zeiten erfasst.'], $conflict->snapshot['messages']['workflow']);
        $this->assertSame('09:00', $this->row($source)['values']['starts']);
    }

    public function test_service_only_edit_preserves_nullable_native_shift_fields_and_recorded_time(): void
    {
        [$source, $shift, $assignment, $time] = $this->sourceWithTimeProof();
        SyncContext::import(fn () => $shift->update(['notes' => null, 'disposition_details' => null]));
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());
        $shiftBefore = $shift->fresh()->getRawOriginal();
        $assignmentBefore = $assignment->getRawOriginal();
        $timeBefore = $time->getRawOriginal();

        $this->edit($source, ['service_type' => 'Wagenprüfung']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $this->assertSame('Wagenprüfung', $shift->order->fresh()->service_type);
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame($timeBefore, $time->fresh()->getRawOriginal());
        $this->assertSame(0, DropboxConflict::where('state', 'open')->count());
    }

    public function test_actual_shift_detail_change_with_recorded_time_remains_guarded(): void
    {
        [$source, $shift, $assignment, $time] = $this->sourceWithTimeProof();
        $orderBefore = $shift->order->getRawOriginal();
        $shiftBefore = $shift->getRawOriginal();
        $assignmentBefore = $assignment->getRawOriginal();
        $timeBefore = $time->getRawOriginal();

        $this->edit($source, ['service_type' => 'Darf nicht übernommen werden', 'information' => 'Neue Einsatzinformation']);
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        $this->assertSame($orderBefore, $shift->order->fresh()->getRawOriginal());
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame($timeBefore, $time->fresh()->getRawOriginal());
        $conflict = DropboxConflict::where('reason', 'business_rule')->where('state', 'open')->firstOrFail();
        $this->assertSame(['Für diese Schicht wurden bereits Zeiten erfasst.'], $conflict->snapshot['messages']['workflow']);
    }

    public function test_employee_change_cannot_bypass_recorded_time_guards_when_shift_attributes_match(): void
    {
        [, $shift, $assignment, $time] = $this->sourceWithTimeProof();
        $record = DropboxRecord::where('model_type', 'Shift')->where('model_id', $shift->id)->firstOrFail();
        SyncContext::import(fn () => $record->update(['assignment_id' => null]));
        $person = User::factory()->create(['name' => 'Andere Person', 'role' => 'staff', 'status' => true]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'andere person', 'kind' => 'employee', 'user_id' => $person->id, 'details' => ['display_name' => 'Andere Person']]);
        $values = [...app(DomainAdapter::class)->current($record), 'employee' => 'Andere Person'];
        $orderBefore = $shift->order->getRawOriginal();
        $shiftBefore = $shift->getRawOriginal();
        $assignmentBefore = $assignment->getRawOriginal();
        $timeBefore = $time->getRawOriginal();

        try {
            DB::transaction(fn () => app(DomainAdapter::class)->apply($this->connection, $record, $values));
            $this->fail('An employee change must retain the native time-proof guard.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Für diese Schicht wurden bereits Zeiten erfasst.'], $exception->errors()['workflow']);
        }

        $this->assertSame($orderBefore, $shift->order->fresh()->getRawOriginal());
        $this->assertSame($shiftBefore, $shift->fresh()->getRawOriginal());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getRawOriginal());
        $this->assertSame($timeBefore, $time->fresh()->getRawOriginal());
        $this->assertSame(1, ShiftAssignment::count());
        $this->assertNull($record->fresh()->assignment_id);
    }

    private function sourceWithTimeProof(): array
    {
        $source = $this->source();
        app(FileSynchronizer::class)->sync($this->connection, $source);
        $record = DropboxRecord::where('model_type', 'Shift')->firstOrFail();
        $shift = Shift::findOrFail($record->model_id);
        $person = User::factory()->create(['name' => 'Erika Muster', 'role' => 'staff', 'status' => true]);
        DropboxIdentity::create(['connection_id' => $this->connection->id, 'alias' => 'erika muster', 'kind' => 'employee', 'user_id' => $person->id, 'details' => ['display_name' => 'Erika Muster']]);
        [$assignment, $time] = SyncContext::import(function () use ($record, $shift, $person) {
            $shift->update(['status' => ShiftStatus::Completed]);
            $assignment = ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'status' => 'confirmed', 'assigned_by' => 1]);
            $record->update(['assignment_id' => $assignment->id, 'metadata' => [...$record->metadata, 'employee_label' => $person->name, 'user_id' => $person->id]]);
            $time = WorkTimeEntry::create(['shift_assignment_id' => $assignment->id, 'user_id' => $person->id, 'status' => 'approved', 'timezone' => 'Europe/Berlin',
                'starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at, 'plan_snapshot' => ['proof' => 'Unverändert'], 'reviewed_by' => 1, 'reviewed_at' => now()]);

            return [$assignment, $time];
        });
        app(FileSynchronizer::class)->sync($this->connection, $source->fresh());

        return [$source->fresh(), $shift->fresh(), $assignment->fresh(), $time->fresh()];
    }

    private function source(): DropboxSource
    {
        return app(SourceScanner::class)->register($this->connection, $this->client->put('/Disposition/Aufträge KW 38 2026.xlsx', $this->workbook()));
    }

    private function row(DropboxSource $source): array
    {
        return app(WorkbookReader::class)->read($this->client->download($this->connection, $source->file_id)['bytes'], 'weekly')['rows'][0];
    }

    private function edit(DropboxSource $source, array $values): void
    {
        $bytes = $this->client->download($this->connection, $source->file_id)['bytes'];
        $entry = app(WorkbookReader::class)->read($bytes, 'weekly')['rows'][0];
        $written = app(WorkbookWriter::class)->write($bytes, [['entry' => $entry, 'values' => [...$entry['values'], ...$values]]], 'weekly');
        $this->client->put($source->path, $written);
    }

    private function workbook(int $rows = 1): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('WTU Abrechnung + Übersicht');
        foreach (WorkbookReader::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'1', $label);
        }
        for ($row = 2; $row < $rows + 2; $row++) {
            foreach (['B' => $rows === 1 ? 'Bahnhof' : 'Bahnhof '.$row, 'C' => '2026-09-15', 'D' => '08:00-12:00', 'F' => 'ZUG-17', 'H' => 'WGM', 'I' => 'Kunde'] as $column => $value) {
                $sheet->setCellValue($column.$row, $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'rt-native-');
        try {
            (new Xlsx($book))->save($path);

            return file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
