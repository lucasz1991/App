<?php

namespace Tests\Feature;

use App\Livewire\Operations\MyWork;
use App\Livewire\Operations\WorkTimeCaptureReview;
use App\Livewire\Operations\WorkTimeSections;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\User;
use App\Models\WorkTimeCaptureDevice;
use App\Models\WorkTimeCaptureReceipt;
use App\Models\WorkTimeEntry;
use App\Models\WorkTimeEvent;
use App\Services\Operations\WorkTimeActivityService;
use App\Services\Operations\WorkTimeCaptureService;
use App\Services\Operations\WorkTimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class WorkTimeExtensionUiTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $employee;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_123000_extend_work_time_contexts.php'))->up();
        $this->travelTo(CarbonImmutable::parse('2027-05-12T06:00:00Z'));
        $this->employee = User::factory()->create(['role' => 'staff', 'status' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        OperationsRuleProfile::create(['name' => 'Fixture', 'minimum_rest_minutes' => 600, 'maximum_shift_minutes' => 720, 'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true, 'created_by' => $this->admin->id, 'approved_at' => now()]);
        EmployeeWorkModel::create(['user_id' => $this->employee->id, 'name' => 'Explicit fixture', 'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400, 'daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0], 'valuation_rules' => ['internal' => 10000, 'work' => 10000, 'driving' => 10000, 'break' => 10000], 'status' => 'active', 'created_by' => $this->admin->id, 'approved_by' => $this->admin->id, 'approved_at' => now()]);
    }

    private function event(int $sequence, string $action, string $captureId, string $at, array $extra = []): array
    {
        return $extra + ['event_key' => (string) Str::uuid(), 'sequence' => $sequence, 'action' => $action, 'entry_capture_id' => $captureId, 'revision' => $sequence - 1, 'occurred_at' => $at, 'timezone' => 'Europe/Berlin', 'offset_minutes' => 120];
    }

    public function test_personal_clock_shared_modals_and_actual_sections_render(): void
    {
        $component = Livewire::actingAs($this->employee)->test(MyWork::class)->assertSeeHtml('data-worktime-capture')->call('openGeneralManual')->assertSet('generalManualOpen', true)->assertSeeHtml('role="dialog"');
        $component->set('generalManual', ['work_context' => 'internal', 'title' => 'Tatsächliche Besprechung', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-05-11T08:00', 'ends_at' => '2027-05-11T09:00', 'pause_minutes' => 0, 'note' => 'Tatsächlicher Nachtrag'])->call('saveGeneralManual')->assertHasNoErrors()->assertSet('generalManualOpen', false)->call('showTab', 'time')->assertSee('Arbeitszeiten CSV v2')->assertSee('Arbeitsabschnitte')->assertSee('Offene Zeitmeldungen')->assertDontSeeHtml('@js(');
        $entry = WorkTimeEntry::firstOrFail();
        $component->call('openSections', $entry->id)->assertSet('sectionsOpen', true)->assertSee('Tatsächliche Arbeitsabschnitte');
        Livewire::actingAs($this->employee)->test(WorkTimeSections::class, ['entryId' => $entry->id])->call('add')->set('sections.0', ['kind' => 'internal', 'starts_at' => '2027-05-11T08:00', 'ends_at' => '2027-05-11T09:00', 'note' => 'Bestätigter tatsächlicher Abschnitt'])->call('save')->assertHasNoErrors()->assertDispatched('time-sections-saved');
        $this->assertSame(3600, $entry->fresh()->creditedSeconds());
    }

    public function test_v2_general_replay_rejects_changed_data_not_a_second_time(): void
    {
        $service = app(WorkTimeService::class);
        $data = ['work_context' => 'internal', 'title' => 'Besprechung', 'timezone' => 'Europe/Berlin'];
        $key = (string) Str::uuid();
        $entry = $service->startContext($data, $key, $this->employee);
        $this->assertSame($entry->id, $service->startContext($data, $key, $this->employee)->id);
        try {
            $service->startContext(['title' => 'Geändert'] + $data, $key, $this->employee);
            $this->fail('Changed replay must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('work_time_entries', 1);
    }

    public function test_many_offline_events_and_activity_changes_apply_once_in_batches(): void
    {
        $service = app(WorkTimeCaptureService::class);
        $boot = $service->bootstrap($this->employee, null);
        $id = (string) Str::uuid();
        $events = [$this->event(1, 'start', $id, '2027-05-12T06:00:00Z', ['work_context' => 'internal', 'title' => 'Besprechung'])];
        foreach (range(2, 52) as $sequence) {
            $events[] = $this->event($sequence, 'activity', $id, CarbonImmutable::parse('2027-05-12T06:00:00Z')->addMinutes($sequence - 1)->toIso8601String(), ['kind' => $sequence % 2 ? 'internal' : 'driving']);
        }
        $events[] = $this->event(53, 'stop', $id, '2027-05-12T06:52:00Z');
        $this->travel(60)->minutes();
        foreach (array_chunk($events, 50) as $batch) {
            $this->assertSame(array_fill(0, count($batch), 'accepted'), array_column($service->ingest($this->employee, $boot['device_id'], $batch)['results'], 'status'));
        }
        $entry = WorkTimeEntry::firstOrFail();
        $this->assertSame(3120, $entry->netSeconds());
        $this->assertSame(3120, $entry->creditedSeconds());
        $this->assertDatabaseCount('work_time_capture_receipts', 53);
        $service->ingest($this->employee, $boot['device_id'], array_slice($events, 0, 50));
        $this->assertDatabaseCount('work_time_capture_receipts', 53);
        $state = $service->bootstrap($this->employee, $boot['device_id'], array_column($events, 'event_key'));
        $this->assertSame(53, count($state['receipts']));
        $this->assertNull($state['active']);
    }

    public function test_revoked_device_does_not_replace_key_or_orphan_saved_capture(): void
    {
        $service = app(WorkTimeCaptureService::class);
        $boot = $service->bootstrap($this->employee, null);
        WorkTimeCaptureDevice::where('public_id', $boot['device_id'])->update(['revoked_at' => now()]);
        try {
            $service->bootstrap($this->employee, $boot['device_id']);
            $this->fail('Revoked device must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('work_time_capture_devices', 1);
    }

    public function test_capture_cannot_rebind_existing_direct_api_event_or_historical_entry(): void
    {
        $key = (string) Str::uuid();
        $entry = app(WorkTimeService::class)->startContext(['work_context' => 'internal', 'title' => 'Original', 'timezone' => 'Europe/Berlin'], $key, $this->employee);
        $oldCapture = $entry->capture_id;
        $oldSnapshot = $entry->plan_snapshot;
        $service = app(WorkTimeCaptureService::class);
        $boot = $service->bootstrap($this->employee, null);
        $event = $this->event(1, 'start', (string) Str::uuid(), '2027-05-12T06:00:00Z', ['event_key' => $key, 'work_context' => 'internal', 'title' => 'Original']);
        $this->assertSame('conflict', $service->ingest($this->employee, $boot['device_id'], [$event])['results'][0]['status']);
        $this->assertSame($oldCapture, $entry->fresh()->capture_id);
        $this->assertSame($oldSnapshot, $entry->fresh()->plan_snapshot);
        $this->assertDatabaseCount('work_time_entries', 1);
    }

    public function test_activity_event_collision_on_another_entry_is_a_conflict_without_mutation(): void
    {
        $service = app(WorkTimeService::class);
        $oldKey = (string) Str::uuid();
        $old = $service->startContext(['work_context' => 'internal', 'title' => 'Erste Arbeit', 'timezone' => 'Europe/Berlin'], $oldKey, $this->employee);
        $this->travel(1)->minutes();
        $service->clock($old->id, 1, 'stop', (string) Str::uuid(), $this->employee);
        $current = $service->startContext(['work_context' => 'internal', 'title' => 'Zweite Arbeit', 'timezone' => 'Europe/Berlin'], (string) Str::uuid(), $this->employee);
        $before = $current->activities()->get()->toArray();
        try {
            app(WorkTimeActivityService::class)->change($current->id, 1, 'driving', $oldKey, $this->employee);
            $this->fail('A key on another entry must produce a domain conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, $current->fresh()->revision);
        $this->assertSame($before, $current->activities()->get()->toArray());
        $this->assertSame($old->id, WorkTimeEvent::where('event_key', $oldKey)->firstOrFail()->work_time_entry_id);
    }

    public function test_conflict_review_retains_evidence_and_releases_client_queue_without_creating_time(): void
    {
        $service = app(WorkTimeCaptureService::class);
        $boot = $service->bootstrap($this->employee, null);
        $event = $this->event(1, 'start', (string) Str::uuid(), '2027-05-12T06:00:00Z', ['offset_minutes' => 60, 'work_context' => 'internal', 'title' => 'Besprechung']);
        $service->ingest($this->employee, $boot['device_id'], [$event]);
        $receipt = WorkTimeCaptureReceipt::firstOrFail();
        $original = $receipt->payload;
        Livewire::actingAs($this->employee)->test(WorkTimeCaptureReview::class, ['personal' => true])->call('open', $receipt->id)->set('note', 'Prüfen')->call('reviewed')->assertForbidden();
        Livewire::actingAs($this->admin)->test(WorkTimeCaptureReview::class)->call('open', $receipt->id)->set('note', 'Zeitzonenfehler geprüft; keine Istzeit freigegeben.')->call('reviewed')->assertHasNoErrors();
        $this->assertSame('reviewed', $receipt->fresh()->status);
        $this->assertSame($original, $receipt->fresh()->payload);
        $this->assertDatabaseCount('work_time_entries', 0);
        $this->assertSame('reviewed', $service->bootstrap($this->employee, $boot['device_id'], [$event['event_key']])['receipts'][0]['status']);
    }

    public function test_utc_sections_and_dst_source_offsets_are_not_reinterpreted_as_local_instants(): void
    {
        config(['app.timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-10-31T00:00:00Z'));
        $service = app(WorkTimeCaptureService::class);
        $boot = $service->bootstrap($this->employee, null);
        $this->assertSame('2027-10-31T00:00:00+00:00', WorkTimeCaptureDevice::where('public_id', $boot['device_id'])->first()->created_at->utc()->toIso8601String());
        $id = (string) Str::uuid();
        $events = [$this->event(1, 'start', $id, '2027-10-31T00:10:00Z', ['work_context' => 'internal', 'title' => 'Nachtbesprechung']), $this->event(2, 'pause', $id, '2027-10-31T00:20:00Z'), $this->event(3, 'resume', $id, '2027-10-31T01:20:00Z', ['offset_minutes' => 60]), $this->event(4, 'stop', $id, '2027-10-31T01:40:00Z', ['offset_minutes' => 60])];
        $this->travel(120)->minutes();
        $result = $service->ingest($this->employee, $boot['device_id'], $events);
        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted'],array_column($result['results'],'status'),json_encode($result));
        $entry = WorkTimeEntry::firstOrFail();
        $this->assertSame(1800,$entry->netSeconds());
        $this->assertSame(5400,$entry->creditedSeconds());
        $this->assertSame('00:10',$entry->activities()->first()->starts_at->utc()->format('H:i'));
    }
}
