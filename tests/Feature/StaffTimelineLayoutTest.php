<?php

namespace Tests\Feature;

use App\Livewire\Operations\StaffTimeline;
use App\Models\AbsenceRequest;
use App\Models\Customer;
use App\Models\EmployeeAvailability;
use App\Models\Order;
use App\Models\PersonnelTraining;
use App\Models\PersonnelTrainingParticipant;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Operations\StaffTimelineLayout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use Tests\Support\BuildsMinimalRailTimeSchema;
use Tests\TestCase;

class StaffTimelineLayoutTest extends TestCase
{
    use BuildsMinimalRailTimeSchema;

    public function test_multi_lane_rows_opt_into_compact_presentation_without_losing_any_duty_or_clock_position(): void
    {
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Synthetic overlap plan', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-05T00:00', 'ends_at' => '2026-10-07T00:00', 'required_staff' => 1, 'created_by' => $admin->id]);
        $people = [];
        foreach ([1, 2, 3] as $laneCount) {
            $person = User::factory()->create(['name' => 'Synthetic Lane '.$laneCount, 'role' => 'staff', 'status' => true]);
            $people[$laneCount] = $person->id;
            for ($lane = 0; $lane < $laneCount; $lane++) {
                $shift = Shift::create(['order_id' => $order->id, 'title' => 'Synthetic duty '.$laneCount.'-'.$lane, 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T12:00', 'required_staff' => 1, 'planned_break_minutes' => 0, 'status' => 'confirmed', 'created_by' => $admin->id]);
                ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'status' => 'confirmed', 'assigned_by' => $admin->id]);
            }
        }
        $timeline = Livewire::actingAs($admin)->test(StaffTimeline::class, ['from' => '2026-10-05', 'until' => '2026-10-06'])
            ->assertViewHas('rows', function ($rows) use ($people): bool {
                foreach ($people as $laneCount => $userId) {
                    $row = $rows->first(fn ($row) => $row['user']->id === $userId);
                    $this->assertSame($laneCount, $row['lane_count']);
                    $this->assertCount($laneCount, $row['events']);
                    $this->assertSame(range(0, $laneCount - 1), $row['events']->pluck('lane')->all());
                    foreach ($row['events'] as $event) {
                        $this->assertEqualsWithDelta(100 / 6, $event['left_percent'], 0.00001);
                        $this->assertEqualsWithDelta(100 / 12, $event['width_percent'], 0.00001);
                        $this->assertSame('08:00 – 12:00', $event['timeline_label']);
                    }
                }

                return true;
            });
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$timeline->html());
        $xpath = new \DOMXPath($dom);
        foreach ([1, 2, 3] as $laneCount) {
            $track = $xpath->query('//div[@data-timeline-lanes="'.$laneCount.'"]')->item(0);
            $this->assertNotNull($track);
            $this->assertSame($laneCount > 1 ? 'compact' : 'normal', $track->getAttribute('data-timeline-density'));
            $this->assertSame(2, $xpath->query('./div[contains(@class, "rt-personnel-timeline-day")]', $track)->length);
            $this->assertSame($laneCount, $xpath->query('./div[contains(@class, "rt-personnel-timeline-events")]/div[contains(@class, "rt-personnel-timeline-event")]', $track)->length);
            $this->assertSame($laneCount, $xpath->query('.//button[contains(@class, "rt-personnel-timeline-bar")][@aria-haspopup="dialog"]', $track)->length);
            $this->assertSame(0, $xpath->query('./div[contains(@class, "rt-personnel-timeline-day")][contains(@style, "--timeline-lanes")]', $track)->length);
        }
    }

    public function test_additional_workforce_data_never_changes_default_timeline_geometry_or_events(): void
    {
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        (require database_path('migrations/2026_09_17_180000_create_operations_planning_extensions.php'))->up();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $person = User::factory()->create(['name' => 'Alex Sommer', 'role' => 'staff', 'status' => true]);
        $customer = Customer::create(['company_name' => 'Synthetic Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Existing night', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-24T00:00', 'ends_at' => '2026-10-26T00:00', 'required_staff' => 1, 'created_by' => $admin->id]);
        $shift = Shift::create(['order_id' => $order->id, 'title' => 'Existing night', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-24T22:00:00+02:00', 'ends_at' => '2026-10-25T06:00:00+01:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'confirmed', 'created_by' => $admin->id]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'status' => 'confirmed', 'assigned_by' => $admin->id]);
        $geometry = fn ($rows) => json_encode($rows->map(fn ($row) => Arr::only($row, ['weekly_working_hours', 'planned_hours_by_week', 'events', 'lane_count', 'days']))->all());
        $before = '';
        $timeline = Livewire::actingAs($admin)->test(StaffTimeline::class, ['from' => '2026-10-24', 'until' => '2026-10-25'])->assertViewHas('rows', function ($rows) use (&$before, $geometry) {
            $before = $geometry($rows);

            return true;
        });
        (require database_path('migrations/2026_10_04_120000_create_workforce_personnel_foundations.php'))->up();
        (require database_path('migrations/2026_10_04_122000_create_workforce_planning_tables.php'))->up();
        EmployeeAvailability::create(['user_id' => $person->id, 'kind' => 'prefer_off', 'from' => '2026-10-24', 'until' => '2026-10-25', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'whole_day' => true, 'timezone' => 'Europe/Berlin', 'note' => 'PRIVATE WISH']);
        $training = PersonnelTraining::create(['title' => 'PRIVATE TRAINING', 'starts_at' => '2026-10-25T08:00', 'ends_at' => '2026-10-25T10:00', 'timezone' => 'Europe/Berlin', 'capacity' => 1, 'status' => 'scheduled', 'created_by' => $admin->id]);
        PersonnelTrainingParticipant::create(['personnel_training_id' => $training->id, 'user_id' => $person->id, 'status' => 'confirmed', 'created_by' => $admin->id]);
        $timeline->call('$refresh')->assertViewHas('rows', function ($rows) use ($before, $geometry) {
            $this->assertSame($before, $geometry($rows));

            return true;
        })->assertDontSee('PRIVATE WISH')->assertDontSee('PRIVATE TRAINING');
    }

    private function event(string $start, string $end, string $id = 'shift-1'): array
    {
        return ['id' => $id, 'start' => CarbonImmutable::parse($start), 'end' => CarbonImmutable::parse($end)];
    }

    public function test_clock_position_uses_local_hours_and_only_overlapping_intervals_add_lanes(): void
    {
        $day = CarbonImmutable::parse('2026-10-02', 'Europe/Berlin');
        $cell = app(StaffTimelineLayout::class)->cell($day, collect([
            $this->event('2026-10-02T08:00:00Z', '2026-10-02T10:00:00Z', 'late'),
            $this->event('2026-10-02T08:00:00+02:00', '2026-10-02T10:00:00+02:00', 'first'),
            $this->event('2026-10-02T09:30:00+02:00', '2026-10-02T10:30:00+02:00', 'overlap'),
        ]));

        $this->assertSame(['first', 'overlap', 'late'], $cell['events']->pluck('id')->all());
        $this->assertSame([0, 1, 0], $cell['events']->pluck('lane')->all());
        $this->assertSame(2, $cell['lane_count']);
        $this->assertSame('10:00 – 12:00', $cell['events'][2]['local_label']);
        $this->assertEqualsWithDelta(100 / 3, $cell['events'][0]['left_percent'], 0.00001);
        $this->assertEqualsWithDelta(100 / 12, $cell['events'][0]['width_percent'], 0.00001);
        $this->assertArrayNotHasKey('free', $cell);
        $this->assertSame([], $cell['working_windows']);
    }

    public function test_overnight_segments_keep_original_interval_and_clip_only_the_visible_clock(): void
    {
        $event = $this->event('2026-10-02T22:00:00+02:00', '2026-10-03T06:00:00+02:00');
        $layout = app(StaffTimelineLayout::class);
        $first = $layout->cell(CarbonImmutable::parse('2026-10-02', 'Europe/Berlin'), collect([$event]))['events'][0];
        $next = $layout->cell(CarbonImmutable::parse('2026-10-03', 'Europe/Berlin'), collect([$event]))['events'][0];

        $this->assertSame('22:00 – 24:00 →', $first['local_label']);
        $this->assertSame('← 00:00 – 06:00', $next['local_label']);
        $this->assertTrue($first['continues_after']);
        $this->assertTrue($next['continues_before']);
        $this->assertFalse($first['continues_before']);
        $this->assertFalse($next['continues_after']);
        $this->assertEqualsWithDelta(100 * 22 / 24, $first['left_percent'], 0.00001);
        $this->assertSame(25.0, $next['width_percent']);
        $this->assertSame($event['start'], $next['start']);
        $this->assertSame($event['end'], $first['end']);
    }

    public function test_23_and_25_hour_days_keep_an_08_clock_at_one_third_of_the_axis(): void
    {
        foreach (['2026-03-29' => [1380, '+02:00'], '2026-10-25' => [1500, '+01:00']] as $date => [$elapsedMinutes, $offset]) {
            $cell = app(StaffTimelineLayout::class)->cell(CarbonImmutable::parse($date, 'Europe/Berlin'), collect([
                $this->event($date.'T08:00:00'.$offset, $date.'T10:00:00'.$offset),
            ]));
            $this->assertEquals($elapsedMinutes, $cell['day_elapsed_minutes']);
            $this->assertEqualsWithDelta(100 / 3, $cell['events'][0]['left_percent'], 0.00001);
            $this->assertEqualsWithDelta(100 / 12, $cell['events'][0]['width_percent'], 0.00001);
        }
    }

    public function test_transition_nights_show_six_clock_hours_with_five_or_seven_elapsed_hours(): void
    {
        foreach ([['2026-03-29', '+01:00', '+02:00', 300], ['2026-10-25', '+02:00', '+01:00', 420]] as [$date, $startOffset, $endOffset, $minutes]) {
            $event = app(StaffTimelineLayout::class)->cell(CarbonImmutable::parse($date, 'Europe/Berlin'), collect([
                $this->event($date.'T00:00:00'.$startOffset, $date.'T06:00:00'.$endOffset),
            ]))['events'][0];
            $this->assertSame('00:00 – 06:00', $event['local_label']);
            $this->assertSame(0.0, $event['left_percent']);
            $this->assertSame(25.0, $event['width_percent']);
            $this->assertEquals($minutes, $event['elapsed_minutes']);
            $this->assertTrue($event['dst_changed']);
        }
    }

    public function test_repeated_hour_interval_does_not_get_negative_width_or_lose_exact_instants(): void
    {
        $source = $this->event('2026-10-25T02:45:00+02:00', '2026-10-25T02:15:00+01:00');
        $event = app(StaffTimelineLayout::class)->cell(CarbonImmutable::parse('2026-10-25', 'Europe/Berlin'), collect([$source]))['events'][0];

        $this->assertCount(2, $event['time_segments']);
        $this->assertGreaterThan(0, $event['width_percent']);
        $this->assertEquals(30, $event['elapsed_minutes']);
        $this->assertSame('02:45 – 02:15', $event['local_label']);
        $this->assertSame($source['start']->timestamp, $event['start']->timestamp);
        $this->assertSame($source['end']->timestamp, $event['end']->timestamp);
        foreach ($event['time_segments'] as $segment) {
            $this->assertGreaterThan(0, $segment['width_percent']);
        }
    }

    public function test_full_day_absence_covers_the_complete_civil_axis_even_on_a_transition_day(): void
    {
        $cell = app(StaffTimelineLayout::class)->cell(CarbonImmutable::parse('2026-10-25', 'Europe/Berlin'), collect([
            $this->event('2026-10-25T00:00:00+02:00', '2026-10-26T00:00:00+01:00'),
        ]));
        $this->assertSame('Ganztägig', $cell['events'][0]['local_label']);
        $this->assertSame(0.0, $cell['events'][0]['left_percent']);
        $this->assertSame(100.0, $cell['events'][0]['width_percent']);
        $this->assertEquals(1500, $cell['events'][0]['elapsed_minutes']);
    }

    public function test_iso_week_totals_split_once_at_midnight_and_deduct_the_planned_break(): void
    {
        $shift = (object) [
            'starts_at' => CarbonImmutable::parse('2026-10-04T22:00:00+02:00'),
            'ends_at' => CarbonImmutable::parse('2026-10-05T06:00:00+02:00'),
            'planned_break_minutes' => 30,
        ];
        $days = collect(['2026-10-04', '2026-10-05', '2026-10-06'])->map(fn ($date) => CarbonImmutable::parse($date, 'Europe/Berlin'));
        $hours = app(StaffTimelineLayout::class)->plannedHoursByWeek(collect([(object) ['shift' => $shift]]), $days);

        $this->assertSame(['2026-40' => 1.875, '2026-41' => 5.625], $hours);
        $this->assertSame(7.5, array_sum($hours));
    }

    public function test_timeline_keeps_recorded_weekly_hours_and_uses_full_edge_week_without_inventing_daily_windows(): void
    {
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $person = User::factory()->create(['role' => 'staff', 'status' => true]);
        $person->profile()->create(['weekly_working_hours' => '38.50', 'additional_information' => 'Keine bestätigten täglichen Zeitfenster']);
        $customer = Customer::create(['company_name' => 'Test Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Wochenplan', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-09-28T00:00', 'ends_at' => '2026-10-05T00:00', 'required_staff' => 1, 'created_by' => $admin->id]);
        foreach (['2026-09-28', '2026-10-02'] as $date) {
            $shift = Shift::create(['order_id' => $order->id, 'title' => 'Frühdienst', 'role_name' => 'Tf', 'location_name' => 'Bremen', 'timezone' => 'Europe/Berlin', 'starts_at' => $date.'T08:00', 'ends_at' => $date.'T16:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'confirmed', 'created_by' => $admin->id]);
            ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'status' => 'confirmed', 'assigned_by' => $admin->id]);
        }
        AbsenceRequest::create(['user_id' => $person->id, 'kind' => 'vacation', 'status' => 'approved', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-02T18:00', 'ends_at' => '2026-10-02T20:00', 'note' => 'PRIVATE-NOTE']);

        Livewire::actingAs($admin)->test(StaffTimeline::class, ['from' => '2026-10-02', 'until' => '2026-10-02'])
            ->assertViewHas('rows', function ($rows): bool {
                $this->assertCount(1, $rows);
                $this->assertSame('38.50', $rows[0]['weekly_working_hours']);
                $this->assertSame(['2026-40' => 15.0], $rows[0]['planned_hours_by_week']);
                $cell = $rows[0]['days'][0];
                $this->assertCount(2, $cell['events']);
                $this->assertSame([], $cell['working_windows']);
                $this->assertSame('confirmed', $cell['events'][0]['status_value']);
                $this->assertSame('Tf', $cell['events'][0]['role_name']);
                $this->assertSame('Bremen', $cell['events'][0]['location_name']);
                $this->assertSame('approved', $cell['events'][1]['status_value']);

                return true;
            })->assertDontSee('PRIVATE-NOTE')->assertDontSee('Unbelegt');
    }

    public function test_rendered_dst_night_keeps_full_details_split_marks_and_no_false_hours_in_absence_mode(): void
    {
        $this->buildMinimalRailTimeSchema();
        (require database_path('migrations/2026_09_15_190000_create_operations_workflow_tables.php'))->up();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $person = User::factory()->create(['role' => 'staff', 'status' => true]);
        $person->profile()->create(['weekly_working_hours' => '38.50']);
        $customer = Customer::create(['company_name' => 'Test Rail', 'is_active' => true]);
        $order = Order::create(['customer_id' => $customer->id, 'title' => 'Nachtleistung', 'service_type' => 'Tf', 'status' => 'confirmed', 'priority' => 'normal', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-24T00:00', 'ends_at' => '2026-10-26T00:00', 'required_staff' => 1, 'created_by' => $admin->id]);
        $shift = Shift::create(['order_id' => $order->id, 'title' => 'Nacht der Zeitumstellung', 'role_name' => 'Tf', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-24T22:00:00+02:00', 'ends_at' => '2026-10-25T06:00:00+01:00', 'required_staff' => 1, 'planned_break_minutes' => 30, 'status' => 'confirmed', 'created_by' => $admin->id]);
        ShiftAssignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'status' => 'confirmed', 'assigned_by' => $admin->id]);

        $timeline = Livewire::actingAs($admin)->test(StaffTimeline::class, ['from' => '2026-10-25', 'until' => '2026-10-25'])
            ->assertViewHas('rows', function ($rows): bool {
                $event = $rows[0]['days'][0]['events'][0];
                $this->assertSame('← 00:00 – 06:00', $event['local_label']);
                $this->assertEquals(420, $event['elapsed_minutes']);
                $this->assertCount(2, $event['time_segments']);

                return true;
            })
            ->assertSee('datetime="2026-10-24T22:00:00+02:00"', false)
            ->assertSee('datetime="2026-10-25T06:00:00+01:00"', false)
            ->assertSee('UTC +02:00')->assertSee('UTC +01:00')
            ->assertSee('24.10.2026')->assertSee('25.10.2026')
            ->assertSee('9,0 h tatsächliche Dauer')
            ->assertDontSee('7,0 h tatsächliche Dauer')
            ->assertSee('38,5 h/Woche')->assertSee('8,5 h');

        preg_match_all('/<span class="rt-personnel-timeline-mark" style="left:([^%]+)%;width:([^%]+)%" aria-hidden="true"><\/span>/', $timeline->html(), $marks);
        $this->assertCount(2, $marks[0]);
        $this->assertEqualsWithDelta(0, (float) $marks[1][0], 0.0001);
        $this->assertEqualsWithDelta(50, (float) $marks[2][0], 0.0001);
        $this->assertEqualsWithDelta(100 / 3, (float) $marks[1][1], 0.0001);
        $this->assertEqualsWithDelta(200 / 3, (float) $marks[2][1], 0.0001);

        AbsenceRequest::create(['user_id' => $person->id, 'kind' => 'vacation', 'status' => 'approved', 'timezone' => 'Europe/Berlin', 'starts_at' => '2026-10-25T12:00', 'ends_at' => '2026-10-25T14:00']);
        Livewire::actingAs($admin)->test(StaffTimeline::class, ['from' => '2026-10-25', 'until' => '2026-10-25', 'absencesOnly' => true])
            ->assertSee('Urlaub')->assertDontSee('Nacht der Zeitumstellung')
            ->assertDontSee('Eingeplant')->assertDontSee('Regelarbeitszeit')->assertDontSee('h/Woche');
    }
}
