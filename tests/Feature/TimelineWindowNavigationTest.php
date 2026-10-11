<?php

namespace Tests\Feature;

use App\Livewire\Admin\Operations\ShiftManagement;
use App\Livewire\Operations\StaffTimeline;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeWorkModel;
use App\Models\OperationsRuleProfile;
use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class TimelineWindowNavigationTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    private User $admin;

    private User $employee;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildMinimalRailTimeSchema();
        foreach ([
            '2026_09_15_190000_create_operations_workflow_tables.php',
            '2026_09_17_180000_create_operations_planning_extensions.php',
            '2026_10_04_120000_create_workforce_personnel_foundations.php',
            '2026_10_04_122000_create_workforce_planning_tables.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
        Schema::table('shifts', fn (Blueprint $table) => $table->json('disposition_details')->nullable());
        Http::preventStrayRequests();
        config(['app.timezone' => 'UTC', 'operations.display_timezone' => 'Europe/Berlin']);
        $this->travelTo(CarbonImmutable::parse('2027-10-20T07:00:00+02:00'));
        $this->admin = User::factory()->create(['name' => 'Navigation QA', 'role' => 'admin', 'status' => true]);
        $this->employee = User::factory()->create(['name' => 'Window Employee', 'role' => 'staff', 'status' => true]);
        OperationsRuleProfile::create([
            'name' => 'Window rules', 'minimum_rest_minutes' => 0, 'maximum_shift_minutes' => 720,
            'break_after_minutes' => 360, 'minimum_break_minutes' => 30, 'is_active' => true,
            'created_by' => $this->admin->id, 'approved_at' => now()->utc(),
        ]);
        $customer = Customer::create(['company_name' => 'Window Customer', 'is_active' => true]);
        $this->order = Order::create([
            'customer_id' => $customer->id, 'title' => 'Window service', 'status' => 'confirmed',
            'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2027-01-01T00:00',
            'ends_at' => '2029-01-01T00:00', 'required_staff' => 2, 'created_by' => $this->admin->id,
        ]);
    }

    public function test_embedded_timeline_has_reactive_dates_and_a_stable_parent_owned_navigation_identity(): void
    {
        $parent = $this->parent();
        $child = $this->childSnapshot($parent->html());
        $this->assertSame($parent->id(), $child['data']['periodNavigationOwner']);
        $this->assertContains('from', $child['memo']['props']);
        $this->assertContains('until', $child['memo']['props']);
        $this->assertSame('2027-10-25', $child['data']['from']);
        $this->assertSame('2027-10-31', $child['data']['until']);
        $this->assertSame($child['memo']['id'], $parent->snapshot['memo']['children']['shift-plan-staff-timeline'][1]);
        $this->assertStringContainsString('data-timeline-window-navigation="true"', $parent->html());
        $this->assertStringContainsString('data-timeline-axis-from="2027-10-22"', $parent->html());
        $this->assertStringContainsString('data-timeline-axis-until="2027-11-03"', $parent->html());
        $this->assertStringContainsString('data-timeline-selected-days="7"', $parent->html());
        $this->assertStringContainsString('data-timeline-buffer-before="3"', $parent->html());
        $this->assertStringContainsString('data-timeline-buffer-after="3"', $parent->html());
        $this->assertSame(13, $this->confirmedAxisDayCount($parent->html()));

        $standalone = Livewire::actingAs($this->admin)->test(StaffTimeline::class, [
            'from' => '2027-10-25', 'until' => '2027-10-31', 'planningEnabled' => true,
        ])->assertSeeHtml('data-timeline-window-navigation="false"')->assertSet('periodNavigationOwner', null);
        $this->assertNotSame($child['memo']['id'], $standalone->id());
        $this->assertSame(7, $this->confirmedAxisDayCount($standalone->html()));
        $absences = Livewire::actingAs($this->admin)->test(StaffTimeline::class, [
            'from' => '2027-10-25', 'until' => '2027-10-31', 'planningEnabled' => true,
            'absencesOnly' => true, 'periodNavigationOwner' => $parent->id(),
        ])->assertSeeHtml('data-timeline-window-navigation="false"');
        $this->assertSame(7, $this->confirmedAxisDayCount($absences->html()));
    }

    public function test_browser_owned_date_scaffold_keeps_navigation_available_and_planning_guarded_while_contents_load(): void
    {
        $this->shift('Loaded window duty', '2027-10-28T08:00:00+02:00', '2027-10-28T10:00:00+02:00', true);
        $parent = $this->parent();
        $html = $parent->html();
        $xpath = $this->xpath($html);
        $child = $this->childSnapshot($html);
        $this->assertSame('2027-10-25', $child['data']['from']);
        $this->assertSame('2027-10-31', $child['data']['until']);
        $this->assertSame(13, $this->confirmedAxisDayCount($html));

        foreach (['header', 'body'] as $part) {
            $nodes = $xpath->query('//*[@data-timeline-scaffold-'.$part.']');
            $this->assertSame(1, $nodes->length);
            $this->assertTrue($nodes->item(0)->hasAttribute('wire:ignore'));
            $templates = $xpath->query('./template[@*[name()="x-for"]="day in windowScaffoldDays"]', $nodes->item(0));
            $this->assertSame(1, $templates->length);
            $this->assertSame('day.date', $templates->item(0)->getAttribute(':key'));
        }
        $header = $xpath->query('//*[@data-timeline-scaffold-header]')->item(0);
        $this->assertSame(1, $xpath->query('.//*[@*[name()="x-show"]="day.pending && !windowError"]', $header)->length);
        $this->assertSame(1, $xpath->query('.//*[@*[name()="x-bind:role"]="day.pending ? \'status\' : null"]', $header)->length);
        $arrows = $xpath->query('//button[contains(@class,"rt-personnel-timeline-direction")]');
        $this->assertSame(2, $arrows->length);
        foreach ($arrows as $arrow) {
            $this->assertFalse($arrow->hasAttribute('disabled'));
            $this->assertFalse($arrow->hasAttribute('x-bind:disabled'));
            $this->assertFalse($arrow->hasAttribute('wire:loading.attr'));
            $this->assertStringStartsWith('scrollDay(', $arrow->getAttribute('x-on:click'));
        }
        $grid = $xpath->query('//*[@*[name()="x-ref"]="timelineGrid"]')->item(0);
        $this->assertSame('if (!windowContentLoading) openPlanner($event)', $grid->getAttribute('x-on:click'));
        $this->assertSame('if (!windowContentLoading) hoverCell($event)', $grid->getAttribute('x-on:pointerover'));
        $this->assertSame('if (!windowContentLoading) hoverCell($event)', $grid->getAttribute('x-on:focusin'));
        $this->assertStringContainsString('x-on:operations-timeline-window-pending.window=', $html);
        $this->assertStringContainsString('x-on:operations-timeline-period-request.window="requestWindowPeriod($event)"', $html);
        $this->assertStringContainsString('--timeline-display-days', $html);
        $this->assertStringContainsString('--timeline-content-offset', $html);
        $this->assertStringContainsString('if (request.handled) { await request.promise; return; }', $html);

        // Confirmed event content remains Livewire-owned inside its own positioned layer.
        $layers = $xpath->query('//*[contains(@class,"rt-timeline-loaded-track")]');
        $this->assertSame(1, $layers->length);
        $this->assertFalse($layers->item(0)->hasAttribute('wire:ignore'));
        $this->assertSame(13, $xpath->query('./div[contains(@class,"rt-personnel-timeline-day")]', $layers->item(0))->length);
        $this->assertSame(1, $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," rt-personnel-timeline-event ")]', $layers->item(0))->length);
        $this->assertStringContainsString('Loaded window duty', $html);
        $this->assertSame(1, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_real_bundled_reactive_updates_roll_both_directions_across_dst_month_and_year_without_remounting(): void
    {
        $dst = $this->shift('DST night', '2027-10-30T22:00:00+02:00', '2027-10-31T06:00:00+01:00', true);
        $this->shift('November duty', '2027-11-02T08:00:00+01:00', '2027-11-02T10:00:00+01:00', true);
        $year = $this->shift('Year boundary duty', '2027-12-31T22:00:00+01:00', '2028-01-01T06:00:00+01:00', true);
        $this->shift('January duty', '2028-01-04T08:00:00+01:00', '2028-01-04T10:00:00+01:00', true);
        $this->shift('October 25 duty', '2027-10-25T08:00:00+02:00', '2027-10-25T10:00:00+02:00', true);
        $this->shift('November 10 duty', '2027-11-10T08:00:00+01:00', '2027-11-10T10:00:00+01:00', true);
        $this->shift('December 25 duty', '2027-12-25T08:00:00+01:00', '2027-12-25T10:00:00+01:00', true);
        $this->shift('January 8 duty', '2028-01-08T08:00:00+01:00', '2028-01-08T10:00:00+01:00', true);
        $before = [$dst->fresh()->getAttributes(), Shift::count(), ShiftAssignment::count()];
        $parent = $this->parent();
        $snapshots = [$parent->snapshot, $this->childSnapshot($parent->html())];
        $childId = $snapshots[1]['memo']['id'];

        foreach ([
            ['2027-11-01', '2027-11-07', 'November duty', 'October 25 duty'],
            ['2027-10-25', '2027-10-31', 'DST night', 'November 10 duty'],
            ['2027-12-27', '2028-01-02', 'Year boundary duty', 'January 8 duty'],
            ['2028-01-03', '2028-01-09', 'January duty', 'December 25 duty'],
            ['2027-12-27', '2028-01-02', 'Year boundary duty', 'January 8 duty'],
        ] as [$from, $until, $visible, $absent]) {
            [$snapshots, $effects] = $this->bundle($snapshots, [0 => [['method' => 'applyPeriod', 'params' => [$from, $until], 'path' => '']]]);
            $this->assertSame($from, $snapshots[0]['data']['rangeFrom']);
            $this->assertSame($until, $snapshots[0]['data']['rangeTo']);
            $this->assertSame($from, $snapshots[1]['data']['from']);
            $this->assertSame($until, $snapshots[1]['data']['until']);
            $this->assertSame($childId, $snapshots[1]['memo']['id']);
            $this->assertSame($childId, $snapshots[0]['memo']['children']['shift-plan-staff-timeline'][1]);
            $html = $effects[1]['html'];
            $this->assertStringContainsString('data-timeline-from="'.$from.'"', $html);
            $this->assertStringContainsString('data-timeline-until="'.$until.'"', $html);
            $this->assertStringContainsString('staff-day-'.$this->employee->id.'-'.$from, $html);
            $this->assertStringContainsString('staff-day-'.$this->employee->id.'-'.$until, $html);
            $this->assertSame(13, $this->confirmedAxisDayCount($html));
            $this->assertStringContainsString('data-timeline-axis-from="'.CarbonImmutable::parse($from)->subDays(3)->toDateString().'"', $html);
            $this->assertStringContainsString('data-timeline-axis-until="'.CarbonImmutable::parse($until)->addDays(3)->toDateString().'"', $html);
            $this->assertStringContainsString($visible, $html);
            $this->assertStringNotContainsString($absent, $html);
            $this->assertStringContainsString(CarbonImmutable::parse($from)->format('d.m.'), $effects[0]['html']);
            $this->assertStringContainsString(CarbonImmutable::parse($until)->format('d.m.Y'), $effects[0]['html']);
            $periodEvent = collect($effects[0]['dispatches'])->firstWhere('name', 'operations-period-changed');
            $this->assertSame(['from' => $from, 'until' => $until], $periodEvent['params']);
        }

        $this->assertSame($before, [$dst->fresh()->getAttributes(), Shift::count(), ShiftAssignment::count()]);
        $this->assertSame(9.0, $dst->starts_at->diffInHours($dst->ends_at));
        $this->assertSame('2028-01-01', $year->ends_at->setTimezone('Europe/Berlin')->toDateString());
        Http::assertNothingSent();
    }

    public function test_period_update_preserves_search_and_loaded_staff_but_clears_old_selection_focus_and_preview(): void
    {
        foreach (range(1, 29) as $number) {
            User::factory()->create(['name' => 'Window Staff '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'role' => 'staff', 'status' => true]);
        }
        $shift = $this->shift('Open old-window duty', '2027-10-28T08:00:00+02:00', '2027-10-28T16:00:00+02:00');
        $parent = $this->parent(true);
        // Capture the real child before changing parent state: later parent renders contain only a keyed stub.
        $snapshots = [$this->childSnapshot($parent->html())];
        $parent->call('selectDistributionShift', $shift->id);
        [$snapshots] = $this->bundle($snapshots, [], [0 => ['search' => 'Window']]);
        [$snapshots] = $this->bundle($snapshots, [0 => [
            ['method' => 'loadMore', 'params' => [], 'path' => ''],
            ['method' => 'syncDistribution', 'params' => [true, $shift->id], 'path' => ''],
            ['method' => 'openCell', 'params' => [$this->employee->id, '2027-10-28'], 'path' => ''],
            ['method' => 'selectCellShift', 'params' => [$shift->id, (int) $shift->fresh()->revision], 'path' => ''],
        ]]);
        $child = $snapshots[0];
        $this->assertTrue($child['data']['assignmentOpen']);
        $this->assertSame($shift->id, $child['data']['planningShiftId']);
        $this->assertSame($shift->id, $child['data']['focusShiftId']);
        $this->assertSame(2, $child['data']['paginators'][0]['staffPage']);
        $version = $child['data']['previewVersion'];
        $snapshots = [$parent->snapshot, $child];
        [$snapshots, $effects] = $this->bundle($snapshots, [0 => [['method' => 'applyPeriod', 'params' => ['2027-11-01', '2027-11-07'], 'path' => '']]]);
        $this->assertSame('Window', $snapshots[1]['data']['search']);
        $this->assertSame(2, $snapshots[1]['data']['paginators'][0]['staffPage']);
        $this->assertFalse($snapshots[1]['data']['assignmentOpen']);
        foreach (['planningUserId', 'planningDate', 'planningShiftId', 'planningRevision', 'focusShiftId'] as $property) {
            $this->assertNull($snapshots[1]['data'][$property], $property);
        }
        $this->assertNull($snapshots[0]['data']['distributionShiftId']);
        $this->assertSame($version + 1, $snapshots[1]['data']['previewVersion']);
        $this->assertTrue($snapshots[1]['data']['showSuggestions']);
        $this->assertFalse($snapshots[1]['data']['suggestionsReady']);
        $this->assertStringContainsString('data-timeline-suggestions-pending', $effects[1]['html']);
        $this->assertSame(0, $this->xpath($effects[1]['html'])->query('//form[@*[name()="wire:submit"]="confirmAssignment"]')->length);
        $this->assertStringNotContainsString('Open old-window duty', $effects[1]['html']);
        $this->assertSame(30, $this->xpath($effects[1]['html'])->query('//*[starts-with(@*[name()="wire:key"],"staff-person-")]')->length);
        $reset = collect($effects[1]['dispatches'])->firstWhere('name', 'operations-timeline-window-reset');
        $this->assertSame(['timelineId' => $child['memo']['id']], $reset['params']);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_server_period_guards_reject_invalid_reversed_or_oversized_ranges_without_changing_context(): void
    {
        foreach ([
            ['not-a-date', '2027-11-07', 'rangeFrom'],
            ['2027-02-30', '2027-03-07', 'rangeFrom'],
            ['2027-11-07', '2027-11-01', 'rangeTo'],
            ['2027-01-01', '2027-04-05', 'rangeTo'],
        ] as [$from, $until, $error]) {
            $this->parent()->call('applyPeriod', $from, $until)->assertHasErrors($error)
                ->assertSet('rangeFrom', '2027-10-25')->assertSet('rangeTo', '2027-10-31');
        }
        $parent = $this->parent();
        [$snapshots, $effects] = $this->bundle([$parent->snapshot, $this->childSnapshot($parent->html())], [
            0 => [['method' => 'applyPeriod', 'params' => ['2027-01-01', '2027-04-04'], 'path' => '']],
        ]);
        $this->assertSame('2027-01-01', $snapshots[0]['data']['rangeFrom']);
        $this->assertSame('2027-04-04', $snapshots[0]['data']['rangeTo']);
        $this->assertSame('2027-01-01', $snapshots[1]['data']['from']);
        $this->assertSame('2027-04-04', $snapshots[1]['data']['until']);
        $this->assertSame(100, $this->confirmedAxisDayCount($effects[1]['html']));
        $this->assertStringContainsString('data-timeline-selected-days="94"', $effects[1]['html']);
        $this->assertSame(0, Shift::count());
    }

    public function test_three_day_reserves_load_native_events_but_do_not_expand_selected_workload_or_staff_scope(): void
    {
        EmployeeWorkModel::create([
            'user_id' => $this->employee->id, 'name' => 'Selected weekly target', 'status' => 'active',
            'starts_on' => '2027-01-01', 'timezone' => 'Europe/Berlin', 'weekly_target_minutes' => 2400,
            'daily_minutes' => [1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0],
            'created_by' => $this->admin->id,
        ]);
        $selected = $this->shift('Selected duty', '2027-10-28T08:00:00+02:00', '2027-10-28T16:00:00+02:00', true);
        $past = $this->shift('Past reserve duty', '2027-10-22T08:00:00+02:00', '2027-10-22T12:00:00+02:00', true);
        $future = $this->shift('Future reserve duty', '2027-11-03T08:00:00+01:00', '2027-11-03T12:00:00+01:00', true);
        $outsidePast = $this->shift('Outside past reserve', '2027-10-21T08:00:00+02:00', '2027-10-21T10:00:00+02:00', true);
        $outsideFuture = $this->shift('Outside future reserve', '2027-11-04T08:00:00+01:00', '2027-11-04T10:00:00+01:00', true);
        $absence = fn (string $day) => AbsenceRequest::create([
            'user_id' => $this->employee->id, 'kind' => 'vacation', 'status' => 'approved',
            'starts_at' => CarbonImmutable::parse($day, 'Europe/Berlin')->startOfDay(),
            'ends_at' => CarbonImmutable::parse($day, 'Europe/Berlin')->addDay()->startOfDay(),
            'timezone' => 'Europe/Berlin',
        ]);
        $selectedAbsence = $absence('2027-10-27');
        $pastAbsence = $absence('2027-10-24');
        $futureAbsence = $absence('2027-11-02');
        $outsideAbsence = $absence('2027-11-04');
        // A former employee with only a buffered assignment must not widen the selected staff scope.
        $former = User::factory()->create(['name' => 'Window Former', 'role' => 'staff', 'status' => false]);
        ShiftAssignment::create(['shift_id' => $future->id, 'user_id' => $former->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);
        $count = [Shift::count(), ShiftAssignment::count(), AbsenceRequest::count()];
        $owner = $this->parent()->id();
        $timeline = Livewire::actingAs($this->admin)->test(StaffTimeline::class, [
            'from' => '2027-10-25', 'until' => '2027-10-31', 'planningEnabled' => true, 'periodNavigationOwner' => $owner,
        ]);
        $timeline->assertSet('from', '2027-10-25')->assertSet('until', '2027-10-31');
        $this->assertSame(['2027-10-22', '2027-11-03'], [$timeline->viewData('days')->first()->toDateString(), $timeline->viewData('days')->last()->toDateString()]);
        $this->assertSame(['2027-10-25', '2027-10-31'], [$timeline->viewData('selectedDays')->first()->toDateString(), $timeline->viewData('selectedDays')->last()->toDateString()]);
        $row = $timeline->viewData('rows')->firstWhere('user.id', $this->employee->id);
        $this->assertSame([$selected->id, $past->id, $future->id], $row['events']->where('kind', 'shift')->pluck('shift_id')->sort()->values()->all());
        $this->assertSame([$selectedAbsence->id, $pastAbsence->id, $futureAbsence->id], $row['events']->where('kind', 'absence')->pluck('absence_id')->sort()->values()->all());
        $this->assertSame(['2027-43'], array_keys($row['planned_hours_by_week']));
        $workload = $timeline->viewData('workloads')->get($this->employee->id);
        $this->assertSame(450.0, $workload['confirmed']);
        $this->assertSame(450.0, $workload['planned']);
        $this->assertSame(2400, $workload['target']);
        $this->assertSame(1, $workload['shift_count']);
        $this->assertSame(1, $workload['absence_count']);
        $this->assertFalse($timeline->viewData('users')->contains('id', $former->id));
        $this->assertStringNotContainsString($outsidePast->title, $timeline->html());
        $this->assertStringNotContainsString($outsideFuture->title, $timeline->html());
        $this->assertFalse($row['events']->contains('absence_id', $outsideAbsence->id));
        $this->assertSame($count, [Shift::count(), ShiftAssignment::count(), AbsenceRequest::count()]);
        Http::assertNothingSent();
    }

    public function test_range_action_rechecks_authority_after_mount(): void
    {
        $parent = $this->parent();
        Livewire::actingAs($this->employee);
        $parent->call('applyPeriod', '2027-11-01', '2027-11-07')->assertForbidden();
        $this->assertSame(0, Shift::count());
    }

    public function test_preloaded_days_do_not_expand_distribution_or_enable_assignment_before_selected_period_changes(): void
    {
        $selected = $this->shift('Selected open duty', '2027-10-28T08:00:00+02:00', '2027-10-28T16:00:00+02:00');
        $future = $this->shift('Buffered open duty', '2027-11-02T08:00:00+01:00', '2027-11-02T16:00:00+01:00');
        $parent = $this->parent(true)->assertViewHas('pendingShifts', fn ($shifts) => $shifts->total() === 1 && $shifts->sole()->id === $selected->id);
        $timeline = Livewire::actingAs($this->admin)->test(StaffTimeline::class, [
            'from' => '2027-10-25', 'until' => '2027-10-31', 'planningEnabled' => true,
            'periodNavigationOwner' => $parent->id(), 'showSuggestions' => true,
        ])->call('loadSuggestions')->assertViewHas('planningPreview', fn ($preview) => $preview['open_total'] === 1
            && $preview['proposals']->isNotEmpty() && $preview['proposals']->every(fn ($proposal) => $proposal['shift']->id === $selected->id));
        $timeline->call('openCell', $this->employee->id, '2027-11-02')->assertStatus(422);
        $parent->call('applyPeriod', '2027-11-01', '2027-11-07')
            ->assertViewHas('pendingShifts', fn ($shifts) => $shifts->total() === 1 && $shifts->sole()->id === $future->id);
        $this->assertSame(0, ShiftAssignment::count());
        Http::assertNothingSent();
    }

    public function test_client_cannot_replace_the_locked_parent_navigation_owner(): void
    {
        $timeline = Livewire::actingAs($this->admin)->test(StaffTimeline::class, [
            'from' => '2027-10-25', 'until' => '2027-10-31', 'planningEnabled' => true,
        ]);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $timeline->set('periodNavigationOwner', 'arbitrary-component-id');
    }

    private function parent(bool $distribution = false): Testable
    {
        return Livewire::actingAs($this->admin)->withQueryParams([
            'from' => '2027-10-25', 'until' => '2027-10-31', 'distribution' => $distribution ? 'true' : 'false',
        ])->test(ShiftManagement::class);
    }

    private function shift(string $title, string $from, string $until, bool $assigned = false): Shift
    {
        $shift = Shift::create([
            'order_id' => $this->order->id, 'title' => $title, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin',
            'starts_at' => CarbonImmutable::parse($from), 'ends_at' => CarbonImmutable::parse($until),
            'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'draft', 'created_by' => $this->admin->id,
        ]);
        if ($assigned) {
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $this->employee->id, 'status' => 'confirmed', 'assigned_by' => $this->admin->id]);
        }

        return $shift;
    }

    private function childSnapshot(string $html): array
    {
        $children = [];
        foreach ($this->xpath($html)->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = json_decode($element->getAttribute('wire:snapshot'), true, flags: JSON_THROW_ON_ERROR);
            if ($snapshot['memo']['name'] === 'operations.staff-timeline') {
                $children[] = $snapshot;
            }
        }
        $this->assertCount(1, $children);

        return $children[0];
    }

    /** Send the same parent-first bundled update used by Livewire's reactive props transport. */
    private function bundle(array $snapshots, array $calls = [], array $updates = []): array
    {
        $components = [];
        foreach ($snapshots as $index => $snapshot) {
            $components[] = ['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'calls' => $calls[$index] ?? [], 'updates' => $updates[$index] ?? []];
        }
        $response = $this->actingAs($this->admin)->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), ['components' => $components])->assertOk();
        $snapshots = [];
        $effects = [];
        foreach ($response->json('components') as $component) {
            $snapshots[] = json_decode($component['snapshot'], true, flags: JSON_THROW_ON_ERROR);
            $effects[] = $component['effects'];
        }
        app('livewire')->flushState();

        return [$snapshots, $effects];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function confirmedAxisDayCount(string $html): int
    {
        $xpath = $this->xpath($html);
        if ($xpath->query('//*[@data-timeline-scaffold-header]')->length === 0) {
            return $xpath->query('//*[contains(@class,"rt-personnel-timeline-header-days")]/*')->length;
        }

        // Embedded headers are client-created. Assert their confirmed server axis,
        // declared geometry and actual loaded cells instead of counting the template.
        $root = $xpath->query('//*[@data-timeline-window-navigation="true"]')->item(0);
        $this->assertNotNull($root);
        $from = CarbonImmutable::parse($root->getAttribute('data-timeline-axis-from'));
        $until = CarbonImmutable::parse($root->getAttribute('data-timeline-axis-until'));
        $days = (int) $from->diffInDays($until) + 1;
        $this->assertSame(1, preg_match('/(?:^|;)--timeline-days:(\d+)(?:;|$)/', $root->getAttribute('style'), $matches));
        $this->assertSame($days, (int) $matches[1]);
        foreach ($xpath->query('//*[contains(@class,"rt-timeline-loaded-track")]') as $layer) {
            $this->assertSame($days, $xpath->query('./div[contains(@class,"rt-personnel-timeline-day")]', $layer)->length);
        }

        return $days;
    }
}
