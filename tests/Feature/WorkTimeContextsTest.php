<?php

namespace Tests\Feature;

use App\Models\OperationsRuleProfile;
use App\Models\User;
use App\Models\WorkTimeCaptureReceipt;
use App\Models\WorkTimeEntry;
use App\Services\Operations\WorkTimeCaptureService;
use App\Services\Operations\WorkTimeExtensionService;
use App\Services\Operations\WorkTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkTimeContextsTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12T06:00:00Z'));
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Testprofil', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
    }

    private function general(string $key = ''): WorkTimeEntry
    {
        return app(WorkTimeService::class)->startContext(['work_context' => 'internal', 'title' => 'Dienstbesprechung', 'timezone' => 'Europe/Berlin'], $key ?: (string) Str::uuid(), $this->employee);
    }

    public function test_one_clock_actual_sections_and_no_fake_plan_time(): void
    {
        $entry = $this->general();
        $this->assertNull($entry->shift_assignment_id);
        $this->assertNull(app(WorkTimeService::class)->comparison($entry)['planned']);
        $this->assertDatabaseCount('work_time_active_sessions', 1);
        $this->travel(10)->minutes();
        $entry = app(WorkTimeService::class)->clock($entry->id, 1, 'pause', (string) Str::uuid(), $this->employee);
        $this->travel(5)->minutes();
        $entry = app(WorkTimeService::class)->clock($entry->id, 2, 'resume', (string) Str::uuid(), $this->employee);
        $this->travel(10)->minutes();
        $entry = app(WorkTimeService::class)->clock($entry->id, 3, 'stop', (string) Str::uuid(), $this->employee);
        $this->assertSame(1200, $entry->netSeconds());
        $this->assertSame(300, $entry->pause_seconds);
        $this->assertSame(['internal', 'break', 'internal'], $entry->activities()->pluck('kind')->all());
        $this->assertDatabaseCount('work_time_active_sessions', 0);
        $this->assertNull(app(WorkTimeService::class)->comparison($entry)['delta']);
    }

    public function test_second_clock_is_blocked_across_contexts(): void
    {
        $this->general();
        $this->expectException(ValidationException::class);
        $this->general();
    }

    public function test_manual_aggregate_pause_does_not_fabricate_activity_positions(): void
    {
        $entry = app(WorkTimeService::class)->manualContext(['work_context' => 'unplanned', 'title' => 'Ersatzbesprechung', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-11T08:00', 'ends_at' => '2027-05-11T09:00', 'pause_minutes' => 10, 'note' => 'Offline nachgetragen'], (string) Str::uuid(), $this->employee);
        $this->assertSame(3000, $entry->netSeconds());
        $this->assertSame(0, $entry->activities()->count());
        $this->assertSame('manual', $entry->source);
    }

    public function test_offline_replay_is_exactly_once_and_keeps_source_time(): void
    {
        $capture = app(WorkTimeCaptureService::class);
        $boot = $capture->bootstrap($this->employee, null);
        $correlation = (string) Str::uuid();
        $events = [
            ['event_key' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'start', 'entry_capture_id' => $correlation, 'revision' => 0, 'occurred_at' => '2027-05-12T06:00:00Z', 'timezone' => 'Europe/Berlin', 'offset_minutes' => 120, 'work_context' => 'internal', 'title' => 'Betriebsbesprechung'],
            ['event_key' => (string) Str::uuid(), 'sequence' => 2, 'action' => 'stop', 'entry_capture_id' => $correlation, 'revision' => 1, 'occurred_at' => '2027-05-12T06:20:00Z', 'timezone' => 'Europe/Berlin', 'offset_minutes' => 120],
        ];
        $this->travel(30)->minutes();
        $first = $capture->ingest($this->employee, $boot['device_id'], $events);
        $second = $capture->ingest($this->employee, $boot['device_id'], $events);
        $this->assertSame($first, $second);
        $this->assertSame(['accepted', 'accepted'], array_column($first['results'], 'status'));
        $this->assertDatabaseCount('work_time_entries', 1);
        $entry = WorkTimeEntry::firstOrFail();
        $this->assertSame(1200, $entry->netSeconds());
        $this->assertSame('06:20', $entry->ends_at->utc()->format('H:i'));
        $this->assertSame('06:30', $entry->events()->where('kind', 'stop')->first()->received_at->format('H:i'));
        $raw = DB::table('work_time_capture_receipts')->value('payload');
        $this->assertStringNotContainsString('Betriebsbesprechung', $raw);
        $this->assertStringNotContainsString($boot['key'], DB::table('work_time_capture_devices')->value('encryption_key'));
        $changed = $events;
        $changed[0]['title'] = 'Manipulierte Wiederholung';
        $this->assertSame('conflict', $capture->ingest($this->employee, $boot['device_id'], [$changed[0]])['results'][0]['status']);
        $this->assertSame(2, WorkTimeCaptureReceipt::count());
    }

    public function test_offline_conflicts_are_retained_without_applying_time(): void
    {
        $capture = app(WorkTimeCaptureService::class);
        $boot = $capture->bootstrap($this->employee, null);
        $event = ['event_key' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'start', 'entry_capture_id' => (string) Str::uuid(), 'revision' => 0, 'occurred_at' => '2027-05-12T06:00:00Z', 'timezone' => 'Europe/Berlin', 'offset_minutes' => 60, 'work_context' => 'internal', 'title' => 'Betriebsbesprechung'];
        $this->assertSame('conflict', $capture->ingest($this->employee, $boot['device_id'], [$event])['results'][0]['status']);
        $this->assertDatabaseCount('work_time_entries', 0);
        $this->assertDatabaseCount('work_time_capture_receipts', 1);
        $this->assertSame($event['event_key'], WorkTimeCaptureReceipt::first()->payload['event_key']);
    }

    public function test_worktime_v2_export_is_separate_and_snapshot_based(): void
    {
        $entry = $this->general();
        $this->travel(30)->minutes();
        $entry = app(WorkTimeService::class)->clock($entry->id, 1, 'stop', (string) Str::uuid(), $this->employee);
        $entry = app(WorkTimeService::class)->clock($entry->id, 2, 'submit', (string) Str::uuid(), $this->employee);
        app(WorkTimeService::class)->review($entry->id, 3, true, 'Bewertungsgrundlage separat prüfen.', $this->admin);
        $service = app(WorkTimeExtensionService::class);
        $export = $service->export([$entry->id], $this->admin);
        $before = $service->csv($export, $this->admin);
        $entry->user->update(['name' => 'Changed']);
        $this->assertSame($before, $service->csv($export->fresh(), $this->admin));
        $this->assertStringContainsString('Kontext', $before);
        $this->assertStringContainsString('internal', $before);
        $this->assertDatabaseCount('work_time_exports', 0);
    }

    public function test_general_time_cannot_leak_into_legacy_export(): void
    {
        $entry = $this->general();
        $entry->update(['status' => 'approved', 'ends_at' => now()->addMinute()]);
        $this->expectException(ValidationException::class);
        app(WorkTimeService::class)->export([$entry->id], $this->admin);
    }
}
