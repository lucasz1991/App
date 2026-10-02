<?php

namespace Tests\Feature;

use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use App\Models\LocalExcelImport;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkTimeEntry;
use App\Services\Dropbox\SyncContext;
use App\Services\Operations\LocalDemoDataService;
use App\Services\Operations\ShiftSchedulingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class LocalOperationsDemoTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach (['2026_09_15_190000_create_operations_workflow_tables', '2026_09_17_180000_create_operations_planning_extensions', '2026_09_22_100000_create_dropbox_sync_tables', '2026_09_23_090000_create_local_excel_imports_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
        config(['app.url' => 'http://127.0.0.1:5555']);
        $this->travelTo(CarbonImmutable::parse('2026-10-02T11:00:00+02:00'));
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        SyncContext::import(function () {
            $connection = DropboxConnection::create(['mode' => 'import', 'settings' => ['source' => 'local']]);
            $source = DropboxSource::create(['connection_id' => $connection->id, 'file_id' => 'local:fixture', 'path' => 'fixture.xlsx', 'name' => 'fixture.xlsx', 'profile' => 'weekly', 'first_seen_at' => now()]);
            LocalExcelImport::create(['source_id' => $source->id, 'created_by' => $this->admin->id, 'file_hash' => str_repeat('a', 64), 'parsed_hash' => str_repeat('b', 64), 'disk_path' => 'fixture.xlsx', 'parsed_path' => 'fixture.json', 'status' => 'done', 'summary' => []]);
            for ($i = 0; $i < 12; $i++) {
                $user = User::factory()->create(['role' => 'staff', 'status' => true, 'email' => 'excel-test-'.($i + 1).'@railtime.invalid']);
                DropboxIdentity::create(['connection_id' => $connection->id, 'alias' => 'fixture-'.$i, 'kind' => 'employee', 'user_id' => $user->id]);
            }
            $customer = Customer::create(['company_name' => 'Excel-Bahn', 'is_active' => true]);
            for ($i = 0; $i < 8; $i++) {
                $order = Order::create(['customer_id' => $customer->id, 'title' => 'Original '.$i, 'service_type' => ['WGM', 'RB', 'Azf', 'QP'][$i % 4], 'status' => 'confirmed', 'timezone' => 'Europe/Berlin', 'starts_at' => CarbonImmutable::parse('2026-09-14T00:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2026-09-21T00:00:00+02:00'), 'required_staff' => 1]);
                $shift = app(ShiftSchedulingService::class)->save(new Shift, ['order_id' => $order->id, 'title' => 'Original '.$i, 'role_name' => $order->service_type, 'location_name' => 'Bahnhof '.$i, 'starts_at' => CarbonImmutable::parse('2026-09-15T08:00:00+02:00'), 'ends_at' => CarbonImmutable::parse('2026-09-15T16:00:00+02:00'), 'status' => 'draft', 'timezone' => 'Europe/Berlin'], $this->admin);
                DropboxRecord::create(['connection_id' => $connection->id, 'model_type' => 'Shift', 'model_id' => $shift->id, 'domain' => 'planning']);
            }
        });
    }

    public function test_preview_changes_nothing_and_reports_current_window(): void
    {
        $this->artisan('operations:seed-local-demo --dry-run --templates=8 --weeks-after=0 --actor='.$this->admin->id)->assertSuccessful();
        $this->assertSame(8, Order::count());
        $this->assertSame(8, Shift::count());
        $this->assertSame(0, ShiftAssignment::count());
        $this->assertSame(0, AbsenceRequest::count());
        $this->assertSame(0, OperationsRuleProfile::count());
    }

    public function test_generates_native_current_plans_and_is_idempotent_without_changing_originals(): void
    {
        $originalOrders = Order::get()->map->getRawOriginal()->all();
        $originalShifts = Shift::get()->map->getRawOriginal()->all();
        $now = CarbonImmutable::now();
        $service = app(LocalDemoDataService::class);
        $result = $service->generate($now, $this->admin, 1, 0, 8);
        $this->assertSame('2026-09-21', $result['from']);
        $this->assertSame('2026-10-04', $result['until']);
        $this->assertSame(16, $result['orders_created']);
        $this->assertSame(96, $result['shifts_created']);
        $this->assertGreaterThan(50, $result['assignments_created']);
        $this->assertSame(4, $result['absences_created']);
        $this->assertGreaterThan(0, $result['time_entries_created']);
        $this->assertTrue($now->eq(CarbonImmutable::now()));
        $this->assertSame($originalOrders, Order::where('order_number', 'not like', 'TEST-%')->get()->map->getRawOriginal()->all());
        $this->assertSame($originalShifts, Shift::where('notes', '!=', LocalDemoDataService::MARKER)->orWhereNull('notes')->get()->map->getRawOriginal()->all());
        $published = Shift::where('notes', LocalDemoDataService::MARKER)->where('published_revision', '>', 0)->get();
        foreach ($published as $shift) {
            $this->assertSame($shift->revision, $shift->published_revision);
            $this->assertNotEmpty($shift->published_snapshot);
        }
        $this->assertNotEmpty(Shift::where('status', 'completed')->get());
        $this->assertNotEmpty(Shift::where('status', 'draft')->get());
        $this->assertNotEmpty(Shift::where('status', 'cancelled')->get());
        $this->assertNotEmpty(ShiftAssignment::where('status', 'requested')->get());
        $this->assertNotEmpty(ShiftAssignment::where('status', 'declined')->get());
        foreach (ShiftAssignment::blocking()->with('shift')->get() as $assignment) {
            $shift = $assignment->shift;
            $this->assertFalse(ShiftAssignment::blocking()->where('user_id', $assignment->user_id)->where('id', '!=', $assignment->id)
                ->whereHas('shift', fn ($q) => $q->notCancelled()->during($shift->starts_at->subMinutes(660), $shift->ends_at->addMinutes(660)))->exists());
        }
        $counts = [Order::count(), Shift::count(), ShiftAssignment::count(), AbsenceRequest::count(), WorkTimeEntry::count()];
        $again = $service->generate($now, $this->admin, 1, 0, 8);
        $this->assertSame(0, $again['orders_created']);
        $this->assertSame(16, $again['orders_skipped']);
        $this->assertSame($counts, [Order::count(), Shift::count(), ShiftAssignment::count(), AbsenceRequest::count(), WorkTimeEntry::count()]);
        $this->assertSame(8, DropboxRecord::count());
        $this->assertDatabaseCount('dropbox_work_items', 0);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_imported_expired_documents_remain_blocked_and_existing_rules_are_kept(): void
    {
        $identity = DropboxIdentity::firstOrFail();
        EmployeeCompetencyFact::create(['identity_id' => $identity->id, 'kind' => 'document', 'name' => 'Nachweis gültig bis', 'value' => ['value' => '2026-01-01']]);
        $profile = OperationsRuleProfile::create(['name' => 'Bestehende Regeln', 'is_active' => true, 'minimum_rest_minutes' => 720, 'maximum_shift_minutes' => 600, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'created_by' => $this->admin->id, 'approved_at' => now()]);
        $original = $profile->fresh()->getRawOriginal();
        $result = app(LocalDemoDataService::class)->generate(CarbonImmutable::now(), $this->admin, 0, 0, 8);
        $this->assertFalse($result['rule_profile_created']);
        $this->assertSame($original, $profile->fresh()->getRawOriginal());
        $this->assertSame(1, OperationsRuleProfile::count());
        $this->assertSame(0, ShiftAssignment::where('user_id', $identity->user_id)->count());
        $this->assertGreaterThan(0, $result['assignment_issues']['external_document_expired']);
    }

    public function test_overnight_duration_survives_the_berlin_dst_change(): void
    {
        app(LocalDemoDataService::class)->generate(CarbonImmutable::parse('2026-10-26', 'Europe/Berlin'), $this->admin, 1, 0, 8);
        $night = Shift::where('notes', LocalDemoDataService::MARKER)->get()->first(fn ($s) => $s->starts_at->format('Y-m-d H:i') === '2026-10-24 22:00');
        $this->assertNotNull($night);
        $this->assertSame('2026-10-25 05:00', $night->ends_at->format('Y-m-d H:i'));
        $this->assertEquals(480, $night->starts_at->diffInMinutes($night->ends_at));
    }

    public function test_rejects_a_production_environment_before_any_write(): void
    {
        app()->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        app(LocalDemoDataService::class)->generate(CarbonImmutable::now(), $this->admin);
    }

    public function test_rejects_remote_app_urls_and_invalid_dates(): void
    {
        $this->artisan('operations:seed-local-demo --date=2026-02-30 --dry-run')->assertFailed();
        config(['app.url' => 'https://example.com']);
        $this->artisan('operations:seed-local-demo --dry-run')->assertFailed();
        $this->assertSame(8, Order::count());
        $this->assertSame(0, ShiftAssignment::count());
    }
}
