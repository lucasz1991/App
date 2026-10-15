<?php

namespace Tests\Unit;

use App\Support\Operations\PersonalCalendarLayout;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure layout tests; no application, database or external services are booted. */
final class PersonalCalendarLayoutTest extends TestCase
{
    private function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'Europe/Berlin')->setTimezone('Europe/Berlin');
    }

    private function event(string $id, string $start, string $end, array $values = []): object
    {
        return (object) array_replace([
            'id' => $id, 'kind' => 'shift', 'starts' => $this->date($start), 'ends' => $this->date($end),
            'title' => 'Synthetic '.$id, 'status' => 'confirmed',
            'record' => (object) ['shift' => (object) ['location_name' => 'Synthetic yard']],
        ], $values);
    }

    private function days(array $events, string $start = '2026-10-15', int $count = 1): Collection
    {
        return collect(range(0, $count - 1))->map(fn (int $offset): array => [
            'date' => $this->date($start)->addDays($offset), 'is_today' => false, 'in_month' => true,
            // Deliberately pass every event so the projection itself must clip.
            'events' => collect($events), 'custom_contract' => 'preserved',
        ]);
    }

    private function layout(array $events, string $start = '2026-10-15', int $count = 1, ?string $selected = null): array
    {
        return PersonalCalendarLayout::make($this->days($events, $start, $count), $this->date($selected ?? $start));
    }

    public function test_empty_calendar_keeps_days_and_uses_a_sensible_axis_without_inventing_availability(): void
    {
        $layout = $this->layout([], count: 3, selected: '2026-10-16');
        $this->assertCount(3, $layout['days']);
        $this->assertSame(360, $layout['axis']['start']);
        $this->assertSame(1080, $layout['axis']['end']);
        $this->assertCount(13, $layout['axis']['ticks']);
        $this->assertSame('06:00', $layout['axis']['ticks'][0]['label']);
        $this->assertSame(0, $layout['axis']['ticks'][0]['percent']);
        $this->assertSame(100, $layout['axis']['ticks'][12]['percent']);
        $this->assertSame('2026-10-16', $layout['selected_day']['date']->toDateString());
        $this->assertSame('Keine Termine', $layout['selected_day']['summary']);
        $this->assertSame([], $layout['selected_day']['segments']);
        $this->assertSame([], $layout['selected_day']['all_day']);
        $this->assertSame('preserved', $layout['selected_day']['custom_contract']);
        $this->assertSame([false, true, false], $layout['days']->pluck('selected')->all());
        $empty = PersonalCalendarLayout::make(collect(), $this->date('2026-10-15'));
        $this->assertTrue($empty['days']->isEmpty());
        $this->assertSame('2026-10-15', $empty['selected_day']['date']->toDateString());
        $this->assertSame([], $empty['selected_day']['segments']);
    }

    public function test_overlap_clusters_receive_local_lanes_and_touching_events_share_a_lane(): void
    {
        $layout = $this->layout([
            $this->event('a', '2026-10-15 05:30', '2026-10-15 08:00'),
            $this->event('b', '2026-10-15 08:30', '2026-10-15 11:00'),
            $this->event('c', '2026-10-15 10:00', '2026-10-15 10:30', ['kind' => 'training']),
            $this->event('d', '2026-10-15 10:30', '2026-10-15 12:00'),
            $this->event('e', '2026-10-15 12:00', '2026-10-15 13:30'),
        ]);
        $items = collect($layout['selected_day']['segments'])->keyBy(fn (array $segment): string => $segment['event']->id);
        $this->assertSame([0, 1], [$items['a']['lane'], $items['a']['lanes']]);
        $this->assertSame([0, 2], [$items['b']['lane'], $items['b']['lanes']]);
        $this->assertSame([1, 2], [$items['c']['lane'], $items['c']['lanes']]);
        $this->assertSame([1, 2], [$items['d']['lane'], $items['d']['lanes']]);
        $this->assertSame([0, 1], [$items['e']['lane'], $items['e']['lanes']]);
        $this->assertSame('4 Dienste · 1 Termin', $layout['selected_day']['summary']);
        $this->assertSame(240, $layout['axis']['start']);
        $this->assertSame(900, $layout['axis']['end']);
        $this->assertEqualsWithDelta(330 / 1440 * 100, $items['a']['left'], 0.00001);
        $this->assertEqualsWithDelta(150 / 1440 * 100, $items['a']['width'], 0.00001);
        $this->assertEqualsWithDelta((330 - 240) / 660 * 100, $items['a']['top'], 0.00001);
        $this->assertEqualsWithDelta(150 / 660 * 100, $items['a']['height'], 0.00001);
        $this->assertSame('Synthetic yard', $items['a']['place']);
    }

    public function test_nested_and_chained_overlaps_keep_the_peak_lane_count_for_their_entire_cluster(): void
    {
        $layout = $this->layout([
            $this->event('long', '2026-10-15 08:00', '2026-10-15 14:00'),
            $this->event('short', '2026-10-15 09:00', '2026-10-15 10:00'),
            $this->event('third', '2026-10-15 09:30', '2026-10-15 10:30'),
            $this->event('bridge', '2026-10-15 13:30', '2026-10-15 15:00'),
            $this->event('later', '2026-10-15 16:00', '2026-10-15 17:00'),
        ]);
        $items = $layout['selected_day']['segments'];
        $this->assertSame([3, 3, 3, 3, 1], array_column($items, 'lanes'));
        $this->assertSame([0, 1, 2, 1, 0], array_column($items, 'lane'));
    }

    public function test_night_shifts_are_clipped_at_midnight_without_duplicate_end_day(): void
    {
        $night = $this->event('night', '2026-10-15 22:00', '2026-10-16 06:00');
        $midnight = $this->event('midnight', '2026-10-15 18:00', '2026-10-16 00:00');
        $layout = $this->layout([$night, $midnight], count: 3);
        $first = $layout['days'][0]['segments'];
        $second = $layout['days'][1]['segments'];
        $this->assertCount(2, $first);
        $this->assertCount(1, $second);
        $this->assertSame([], $layout['days'][2]['segments']);
        $this->assertSame($night, $second[0]['event']);
        $this->assertSame('18:00 – 24:00', $first[0]['time_label']);
        $this->assertSame('22:00 – 24:00 →', $first[1]['time_label']);
        $this->assertSame('← 00:00 – 06:00', $second[0]['time_label']);
        $this->assertFalse($first[1]['continues_before']);
        $this->assertTrue($first[1]['continues_after']);
        $this->assertTrue($second[0]['continues_before']);
        $this->assertFalse($second[0]['continues_after']);
        $this->assertEqualsWithDelta(1440, $first[1]['end_minute'], 0.00001);
        $this->assertEqualsWithDelta(0, $second[0]['start_minute'], 0.00001);
        $this->assertSame(0, $layout['axis']['start']);
        $this->assertSame(1440, $layout['axis']['end']);
    }

    public function test_only_whole_day_absences_leave_the_timed_grid_and_do_not_expand_its_axis(): void
    {
        $absence = $this->event('leave', '2026-10-14 00:00', '2026-10-17 00:00', ['kind' => 'absence', 'status' => 'approved']);
        $layout = $this->layout([$absence], count: 3);
        $this->assertSame([1, 1, 0], $layout['days']->map(fn (array $day): int => count($day['all_day']))->all());
        $this->assertSame(360, $layout['axis']['start']);
        $this->assertSame(1080, $layout['axis']['end']);
        $this->assertTrue($layout['selected_day']['segments'][0]['all_day']);
        $this->assertSame('Ganztägig', $layout['selected_day']['segments'][0]['time_label']);
        $this->assertSame('green', $layout['selected_day']['segments'][0]['tone']);
        $this->assertEqualsWithDelta(100, $layout['selected_day']['segments'][0]['width'], 0.00001);

        $partial = $this->layout([
            $this->event('partial', '2026-10-15 08:00', '2026-10-15 12:00', ['kind' => 'absence', 'status' => 'pending']),
            $this->event('full-shift', '2026-10-15 00:00', '2026-10-16 00:00'),
        ]);
        $this->assertSame([], $partial['selected_day']['all_day']);
        $this->assertSame(0, $partial['axis']['start']);
        $this->assertSame(1440, $partial['axis']['end']);
        $this->assertSame('amber', $partial['selected_day']['segments'][1]['tone']);
        $this->assertSame(2, $partial['selected_day']['segments'][1]['lanes']);
    }

    public function test_multiday_timed_events_keep_their_true_extents_and_continue_through_the_middle_day(): void
    {
        $event = $this->event('long', '2026-10-14 22:00', '2026-10-17 06:00');
        $layout = $this->layout([$event], '2026-10-14', 4);
        $this->assertSame([1, 1, 1, 1], $layout['days']->map(fn (array $day): int => count($day['segments']))->all());
        $middle = $layout['days'][1]['segments'][0];
        $this->assertTrue($middle['continues_before']);
        $this->assertTrue($middle['continues_after']);
        $this->assertFalse($middle['all_day']);
        $this->assertEqualsWithDelta(100, $middle['height'], 0.00001);
        $this->assertSame($event, $middle['event']);
        $this->assertSame('← 00:00 – 24:00 →', $middle['time_label']);
    }

    #[DataProvider('dstCases')]
    public function test_dst_uses_civil_clock_positions_and_positive_transition_segments(string $start, string $end, string $day, float $left, float $width, int $elapsed): void
    {
        $event = $this->event('dst', $start, $end);
        $layout = $this->layout([$event], $day);
        $segment = $layout['selected_day']['segments'][0];
        $this->assertEqualsWithDelta($left / 1440 * 100, $segment['left'], 0.00001);
        $this->assertEqualsWithDelta($width / 1440 * 100, $segment['width'], 0.00001);
        $this->assertSame($elapsed, $event->ends->timestamp - $event->starts->timestamp);
        $this->assertCount(2, $segment['time_segments']);
        $this->assertTrue($segment['dst_changed']);
        foreach ($segment['time_segments'] as $part) {
            $this->assertGreaterThan(0, $part['width']);
            $this->assertGreaterThan(0, $part['height']);
            $this->assertGreaterThanOrEqual(0, $part['top']);
            $this->assertLessThanOrEqual(100.00001, $part['top'] + $part['height']);
        }
        $this->assertStringContainsString('(UTC+02:00)', $segment['accessible_label']);
        $this->assertStringContainsString('(UTC+01:00)', $segment['accessible_label']);
    }

    public static function dstCases(): array
    {
        return [
            'spring jump' => ['2026-03-29T01:30:00+01:00', '2026-03-29T03:30:00+02:00', '2026-03-29', 90.0, 120.0, 3600],
            'fall repeat' => ['2026-10-25T01:30:00+02:00', '2026-10-25T03:30:00+01:00', '2026-10-25', 90.0, 120.0, 10800],
            'fall earlier clock end' => ['2026-10-25T02:45:00+02:00', '2026-10-25T02:15:00+01:00', '2026-10-25', 120.0, 60.0, 1800],
        ];
    }

    public function test_repeated_clock_hours_receive_visual_lanes_without_claiming_a_real_overlap(): void
    {
        $layout = $this->layout([
            $this->event('summer', '2026-10-25T02:10:00+02:00', '2026-10-25T02:30:00+02:00'),
            $this->event('winter', '2026-10-25T02:15:00+01:00', '2026-10-25T02:45:00+01:00'),
            $this->event('after', '2026-10-25T03:00:00+01:00', '2026-10-25T04:00:00+01:00'),
        ], '2026-10-25');
        $segments = $layout['selected_day']['segments'];
        $this->assertSame([0, 0, 0], array_column($segments, 'lane'));
        $this->assertSame([1, 1, 1], array_column($segments, 'lanes'));
        $this->assertSame([0, 1, 0], array_column($segments, 'display_lane'));
        $this->assertSame([2, 2, 1], array_column($segments, 'display_lanes'));
        $this->assertStringContainsString('UTC+02:00', $segments[0]['accessible_label']);
        $this->assertStringContainsString('UTC+01:00', $segments[1]['accessible_label']);
    }

    public function test_invalid_outside_and_duplicate_events_are_filtered_without_replacing_the_original_collection(): void
    {
        $valid = $this->event('same', '2026-10-15 08:00', '2026-10-15 09:00');
        $events = [
            'malformed', (object) ['id' => 'broken', 'kind' => 'shift', 'starts' => '2026-10-15'],
            $this->event('same', '2026-10-15 09:00', '2026-10-15 08:00'), $valid,
            $this->event('same', '2026-10-15 10:00', '2026-10-15 11:00'),
            $this->event('zero', '2026-10-15 09:00', '2026-10-15 09:00'),
            $this->event('before', '2026-10-14 08:00', '2026-10-15 00:00'),
            $this->event('after', '2026-10-16 00:00', '2026-10-16 01:00'),
            $this->event('kind', '2026-10-15 08:00', '2026-10-15 09:00', ['kind' => 'unknown']),
        ];
        $days = $this->days($events);
        $layout = PersonalCalendarLayout::make($days, $this->date('2026-10-15'));
        $this->assertSame($days[0]['events'], $layout['days'][0]['events']);
        $this->assertCount(1, $layout['selected_day']['segments']);
        $this->assertSame($valid, $layout['selected_day']['segments'][0]['event']);
        $this->assertSame('1 Dienst', $layout['selected_day']['summary']);
    }

    public function test_utc_and_mutable_source_dates_are_not_changed_and_short_events_keep_a_positive_position(): void
    {
        $start = Carbon::parse('2026-10-14T22:30:00Z')->locale('en');
        $end = Carbon::parse('2026-10-15T03:30:00Z')->locale('en');
        $event = $this->event('utc', '2026-10-15', '2026-10-16', ['starts' => $start, 'ends' => $end, 'status' => 'requested']);
        $before = [$start->format('c.u e'), $start->locale(), $end->format('c.u e'), $end->locale()];
        $layout = $this->layout([$event]);
        $segment = $layout['selected_day']['segments'][0];
        $this->assertSame('00:30 – 05:30', $segment['time_label']);
        $this->assertSame('amber', $segment['tone']);
        $this->assertStringContainsString('Antwort offen', $segment['accessible_label']);
        $this->assertSame($before, [$start->format('c.u e'), $start->locale(), $end->format('c.u e'), $end->locale()]);
        $this->assertInstanceOf(CarbonImmutable::class, $segment['visible_start']);
        $this->assertSame($event, $segment['event']);

        foreach ([['2026-10-15 23:59:59', '2026-10-16 00:00:00'], ['2026-10-15 08:00:00.100000', '2026-10-15 08:00:00.900000']] as [$from, $until]) {
            $short = $this->layout([$this->event('short', $from, $until)])['selected_day']['segments'][0];
            $this->assertGreaterThan(0, $short['width']);
            $this->assertGreaterThan(0, $short['height']);
            $this->assertLessThanOrEqual(100.00001, $short['left'] + $short['width']);
        }
    }

    public function test_place_reads_only_existing_attributes_and_loaded_relations_without_queries(): void
    {
        $record = new class extends Model
        {
            public function shift(): never
            {
                throw new \LogicException('A layout must never load a relation.');
            }
        };
        $event = $this->event('safe', '2026-10-15 08:00', '2026-10-15 09:00', ['record' => $record]);
        $this->assertSame('', $this->layout([$event])['selected_day']['segments'][0]['place']);
        $shift = new class extends Model {};
        $shift->setRawAttributes(['location_name' => 'Published location']);
        $record->setRelation('shift', $shift);
        $this->assertSame('Published location', $this->layout([$event])['selected_day']['segments'][0]['place']);
        $this->assertSame(['location_name' => 'Published location'], $shift->getAttributes());
        $training = $this->event('training', '2026-10-15 09:00', '2026-10-15 10:00', ['kind' => 'training', 'record' => (object) ['location_name' => 'Training room']]);
        $part = $this->layout([$training])['selected_day']['segments'][0];
        $this->assertSame('Training room', $part['place']);
        $this->assertSame('blue', $part['tone']);
    }
}
