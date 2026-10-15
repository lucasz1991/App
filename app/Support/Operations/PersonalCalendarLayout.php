<?php

namespace App\Support\Operations;

use App\Services\Operations\StaffTimelineLayout;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Read-only layout of the personal calendar's already authorized event DTOs. */
final class PersonalCalendarLayout
{
    public static function make(Collection $days, CarbonImmutable $anchor): array
    {
        $anchor = $anchor->startOfDay();
        $timeline = new StaffTimelineLayout;
        $projected = $days->map(function (array $day) use ($anchor, $timeline): array {
            $date = CarbonImmutable::instance($day['date'])->setTimezone($anchor->timezone)->startOfDay();
            $until = $date->addDay();
            $seen = [];
            $segments = [];

            foreach ($day['events'] ?? [] as $event) {
                if (! is_object($event) || ! is_scalar($event->id ?? null)
                    || ! in_array($event->kind ?? null, ['shift', 'training', 'absence'], true)
                    || ! ($event->starts ?? null) instanceof CarbonInterface || ! ($event->ends ?? null) instanceof CarbonInterface) {
                    continue;
                }
                $id = (string) $event->id;
                $start = CarbonImmutable::instance($event->starts)->setTimezone($anchor->timezone);
                $end = CarbonImmutable::instance($event->ends)->setTimezone($anchor->timezone);
                if ($id === '' || isset($seen[$id]) || $end->lte($start) || $start->gte($until) || $end->lte($date)) {
                    continue;
                }
                // Native schedule records have second precision. Subsecond DTOs
                // still receive a positive civil-clock projection without asking
                // the shared integer-second timeline to project an empty span.
                $visibleStart = $start->max($date);
                $visibleEnd = $end->min($until);
                if ($visibleStart->timestamp === $visibleEnd->timestamp) {
                    $left = self::clockMinute($visibleStart) / 1440 * 100;
                    $width = ($visibleStart->diffInSeconds($visibleEnd) / 60) / 1440 * 100;
                    $cell = ['left_percent' => $left, 'width_percent' => $width,
                        'time_segments' => [['left_percent' => $left, 'width_percent' => $width]], 'dst_changed' => false];
                } else {
                    $cell = $timeline->cell($date, collect([['start' => $start, 'end' => $end, 'kind' => $event->kind]]))['events']->first();
                }
                $seen[$id] = true;
                $allDay = $event->kind === 'absence' && $start->format('H:i:s.u') === '00:00:00.000000' && $end->format('H:i:s.u') === '00:00:00.000000';
                $before = $start->lt($date);
                $after = $end->gt($until);
                $time = $allDay ? 'Ganztägig' : ($before ? '← ' : '').$visibleStart->format('H:i').' – '
                    .($visibleEnd->eq($until) ? '24:00' : $visibleEnd->format('H:i')).($after ? ' →' : '');
                $place = self::place($event);
                $segments[] = [
                    'event' => $event,
                    'start_minute' => $cell['left_percent'] * 14.4,
                    'end_minute' => ($cell['left_percent'] + $cell['width_percent']) * 14.4,
                    'left' => $cell['left_percent'], 'width' => $cell['width_percent'],
                    'visible_start' => $visibleStart, 'visible_end' => $visibleEnd,
                    'time_segments' => array_map(fn (array $part): array => ['left' => $part['left_percent'], 'width' => $part['width_percent']], $cell['time_segments']),
                    'lane' => 0, 'lanes' => 1, 'display_lane' => 0, 'display_lanes' => 1, 'all_day' => $allDay,
                    'continues_before' => $before, 'continues_after' => $after,
                    'dst_changed' => $cell['dst_changed'],
                    'tone' => self::tone($event), 'time_label' => $time, 'place' => $place,
                    'accessible_label' => self::label($event, $start, $end, $place, $allDay),
                ];
            }

            usort($segments, fn (array $a, array $b): int => $a['visible_start'] <=> $b['visible_start']);
            $segments = self::lanes(self::lanes($segments), true);

            return array_replace($day, ['segments' => $segments, 'selected' => $date->isSameDay($anchor),
                'summary' => self::summary($segments)]);
        });

        $axis = self::axis($projected);
        $projected = $projected->map(function (array $day) use ($axis): array {
            $range = $axis['end'] - $axis['start'];
            $day['segments'] = array_map(function (array $segment) use ($axis, $range): array {
                $segment['top'] = max(0, min(100, ($segment['start_minute'] - $axis['start']) / $range * 100));
                $segment['height'] = max(0, min(100 - $segment['top'], ($segment['end_minute'] - $segment['start_minute']) / $range * 100));
                $segment['time_segments'] = array_map(function (array $part) use ($axis, $range): array {
                    $part['top'] = max(0, min(100, ($part['left'] * 14.4 - $axis['start']) / $range * 100));
                    $part['height'] = max(0, min(100 - $part['top'], $part['width'] * 14.4 / $range * 100));

                    return $part;
                }, $segment['time_segments']);

                return $segment;
            }, $day['segments']);
            $day['all_day'] = array_values(array_filter($day['segments'], fn (array $segment): bool => $segment['all_day']));

            return $day;
        });

        return ['days' => $projected, 'axis' => $axis,
            'selected_day' => $projected->firstWhere('selected', true) ?? [
                'date' => $anchor, 'events' => collect(), 'is_today' => $anchor->isToday(), 'in_month' => true,
                'segments' => [], 'all_day' => [], 'selected' => true, 'summary' => 'Keine Termine',
            ]];
    }

    /**
     * Keep real overlaps distinct from visual collisions during a repeated hour.
     * Civil-clock lanes prevent two non-concurrent 02:15 events hiding each other.
     */
    private static function lanes(array $segments, bool $civil = false): array
    {
        $laneKey = $civil ? 'display_lane' : 'lane';
        $countKey = $civil ? 'display_lanes' : 'lanes';
        $start = fn (array $segment): float => $civil ? $segment['start_minute'] : (float) $segment['visible_start']->format('U.u');
        $end = fn (array $segment): float => $civil ? $segment['end_minute'] : (float) $segment['visible_end']->format('U.u');
        // Shared civil-clock percentages are rounded to six decimal places.
        $epsilon = $civil ? 0.0001 : 0;
        $indexes = array_keys($segments);
        usort($indexes, fn (int $a, int $b): int => $start($segments[$a]) <=> $start($segments[$b]));
        $cluster = [];
        $ends = [];
        $clusterEnd = null;
        $finish = function () use (&$segments, &$cluster, &$ends, $countKey): void {
            foreach ($cluster as $index) {
                $segments[$index][$countKey] = count($ends);
            }
            $cluster = [];
            $ends = [];
        };
        foreach ($indexes as $index) {
            $segment = $segments[$index];
            if ($segment['all_day']) {
                continue;
            }
            $from = $start($segment);
            $until = $end($segment);
            if ($clusterEnd !== null && $from >= $clusterEnd - $epsilon) {
                $finish();
                $clusterEnd = null;
            }
            $lane = 0;
            while (isset($ends[$lane]) && $ends[$lane] > $from + $epsilon) {
                $lane++;
            }
            $ends[$lane] = $until;
            $cluster[] = $index;
            $segments[$index][$laneKey] = $lane;
            $clusterEnd = $clusterEnd === null ? $until : max($clusterEnd, $until);
        }
        $finish();

        return $segments;
    }

    private static function axis(Collection $days): array
    {
        $timed = $days->flatMap(fn (array $day): array => $day['segments'])->reject(fn (array $segment): bool => $segment['all_day']);
        $start = $timed->isEmpty() ? 360 : max(0, (int) floor(round($timed->min('start_minute'), 4) / 60) * 60 - 60);
        $end = $timed->isEmpty() ? 1080 : min(1440, (int) ceil(round($timed->max('end_minute'), 4) / 60) * 60 + 60);
        $ticks = [];
        for ($minute = $start; $minute <= $end; $minute += 60) {
            $ticks[] = ['minute' => $minute, 'label' => sprintf('%02d:00', intdiv($minute, 60)),
                'percent' => ($minute - $start) / ($end - $start) * 100];
        }

        return ['start' => $start, 'end' => $end, 'ticks' => $ticks];
    }

    private static function clockMinute(CarbonImmutable $date): float
    {
        return $date->hour * 60 + $date->minute + ($date->second + $date->micro / 1000000) / 60;
    }

    private static function tone(object $event): string
    {
        return match ($event->kind) {
            'absence' => ($event->status ?? '') === 'pending' ? 'amber' : 'green',
            'training' => 'blue',
            default => ($event->status ?? '') === 'requested' ? 'amber' : 'burgundy',
        };
    }

    /** Never cause relationship loading while formatting a calendar card. */
    private static function place(object $event): string
    {
        $record = $event->record ?? null;
        if ($event->kind === 'shift') {
            $record = $record instanceof Model ? ($record->getRelations()['shift'] ?? null) : ($record->shift ?? null);
        }
        $attributes = $record instanceof Model ? $record->getAttributes() : (is_object($record) ? get_object_vars($record) : []);

        return is_string($attributes['location_name'] ?? null) ? trim($attributes['location_name']) : '';
    }

    private static function summary(array $segments): string
    {
        $duties = count(array_filter($segments, fn (array $segment): bool => $segment['event']->kind === 'shift'));
        $appointments = count($segments) - $duties;
        $labels = [];
        if ($duties > 0) {
            $labels[] = $duties.($duties === 1 ? ' Dienst' : ' Dienste');
        }
        if ($appointments > 0) {
            $labels[] = $appointments.($appointments === 1 ? ' Termin' : ' Termine');
        }

        return $labels === [] ? 'Keine Termine' : implode(' · ', $labels);
    }

    private static function label(object $event, CarbonImmutable $start, CarbonImmutable $end, string $place, bool $allDay): string
    {
        $status = is_string($event->status ?? null) ? OperationsNavigation::status($event->status) : '';

        return implode(' · ', array_filter([
            ['shift' => 'Dienst', 'absence' => 'Abwesenheit', 'training' => 'Schulung'][$event->kind],
            is_string($event->title ?? null) ? trim($event->title) : '',
            $allDay ? 'Ganztägig' : '',
            $start->format('d.m.Y H:i').' (UTC'.$start->format('P').') bis '.$end->format('d.m.Y H:i').' (UTC'.$end->format('P').')',
            $start->timezone->getName(), $place, $status,
        ]));
    }
}
