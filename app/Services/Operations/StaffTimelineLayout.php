<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class StaffTimelineLayout
{
    /**
     * The axis always shows the local civil clock from 00:00 to 24:00.
     * An elapsed-seconds/day-length ratio would move an 08:00 shift on DST days.
     */
    public function cell(CarbonImmutable $day, Collection $events, ?int $rowLaneCount = null): array
    {
        $end = $day->addDay();
        $lanes = [];
        $items = $events->sortBy(fn (array $event) => $event['start']->timestamp)->values()->map(function (array $event) use ($day, $end, &$lanes): array {
            $start = $event['start']->max($day);
            $finish = $event['end']->min($end);
            $lane = $event['lane'] ?? 0;
            if (! array_key_exists('lane', $event)) {
                while (isset($lanes[$lane]) && $lanes[$lane] > $start->timestamp) {
                    $lane++;
                }
            }
            $lanes[$lane] = max($lanes[$lane] ?? 0, $finish->timestamp);
            $segments = $this->clockSegments($day, $start, $finish);
            $left = min(array_column($segments, 'left_percent'));
            $right = max(array_map(fn (array $segment) => $segment['left_percent'] + $segment['width_percent'], $segments));
            $before = $event['start']->lt($day);
            $after = $event['end']->gt($end);
            $allDay = $event['start']->lte($day) && $event['end']->gte($end);
            $startLabel = $start->setTimezone($day->timezone)->format('H:i');
            $endLabel = $finish->eq($end) ? '24:00' : $finish->setTimezone($day->timezone)->format('H:i');
            $localLabel = $allDay ? 'Ganztägig' : ($before ? '← ' : '').$startLabel.' – '.$endLabel.($after ? ' →' : '');
            $timelineLabel = ($event['kind'] ?? null) === 'shift'
                ? ($before ? '' : $startLabel.' – '.($after ? $event['end']->setTimezone($day->timezone)->format('H:i') : $endLabel))
                : $localLabel;

            return $event + [
                'visible_start' => $start,
                'visible_end' => $finish,
                'left_percent' => round($left, 6),
                'width_percent' => round($right - $left, 6),
                'time_segments' => $segments,
                'lane' => $lane,
                'continues_before' => $before,
                'continues_after' => $after,
                'all_day' => $allDay,
                'local_label' => $localLabel,
                'timeline_label' => $timelineLabel,
                'elapsed_minutes' => $start->diffInSeconds($finish) / 60,
                'dst_changed' => $start->setTimezone($day->timezone)->offset !== $finish->setTimezone($day->timezone)->offset,
                'iso_week' => $day->format('o-W'),
            ];
        });

        return [
            'date' => $day,
            'events' => $items,
            'lane_count' => max($rowLaneCount ?? 1, $items->isEmpty() ? 1 : (int) $items->max('lane') + 1),
            // Weekly contract totals and imported prose do not define daily windows.
            'working_windows' => [],
            'day_elapsed_minutes' => $day->diffInSeconds($end) / 60,
        ];
    }

    /** Assign one stable vertical lane to each event across the visible timeline. */
    public function assignLanes(Collection $events): Collection
    {
        $lanes = [];

        return $events->sortBy(fn (array $event) => $event['start']->timestamp)->values()->map(function (array $event) use (&$lanes): array {
            $lane = 0;
            while (isset($lanes[$lane]) && $lanes[$lane] > $event['start']->timestamp) {
                $lane++;
            }
            $lanes[$lane] = $event['end']->timestamp;

            return $event + ['lane' => $lane];
        });
    }

    /** Project one event across the entire period using local civil-clock days. */
    public function periodEvents(Collection $days, Collection $events): Collection
    {
        if ($days->isEmpty()) {
            return collect();
        }

        $days = $days->values();
        $from = $days->first();
        $until = $days->last()->addDay();
        $dayCount = $days->count();

        return $events->map(function (array $event) use ($days, $from, $until, $dayCount): ?array {
            $segments = [];
            foreach ($days as $index => $day) {
                $start = $event['start']->max($day);
                $end = $event['end']->min($day->addDay());
                if ($start->gte($end)) {
                    continue;
                }
                foreach ($this->clockSegments($day, $start, $end) as $segment) {
                    $segments[] = [
                        'left_percent' => ($index * 100 + $segment['left_percent']) / $dayCount,
                        'width_percent' => $segment['width_percent'] / $dayCount,
                    ];
                }
            }
            if ($segments === []) {
                return null;
            }

            $left = min(array_column($segments, 'left_percent'));
            $right = max(array_map(fn (array $segment) => $segment['left_percent'] + $segment['width_percent'], $segments));
            $start = $event['start']->setTimezone($from->timezone);
            $end = $event['end']->setTimezone($from->timezone);
            $before = $event['start']->lt($from);
            $after = $event['end']->gt($until);
            $allDay = ($event['kind'] ?? null) === 'absence' && $start->isStartOfDay() && $end->isStartOfDay();
            $label = $allDay ? 'Ganztägig' : $start->format('H:i').' – '.$end->format('H:i');
            $label = ($before ? '← ' : '').$label.($after ? ' →' : '');

            return $event + [
                'visible_start' => $event['start']->max($from),
                'visible_end' => $event['end']->min($until),
                'left_percent' => round($left, 6),
                'width_percent' => round($right - $left, 6),
                'time_segments' => $segments,
                'continues_before' => $before,
                'continues_after' => $after,
                'timeline_label' => $label,
                'local_label' => $label,
                'iso_week' => $event['start']->max($from)->setTimezone($from->timezone)->format('o-W'),
            ];
        })->filter()->values();
    }

    /** Actual elapsed time, with breaks allocated proportionally at week boundaries. */
    public function plannedHoursByWeek(Collection $assignments, Collection $days): array
    {
        return $days->unique(fn (CarbonImmutable $day) => $day->format('o-W'))->mapWithKeys(function (CarbonImmutable $day) use ($assignments): array {
            $start = $day->startOfWeek(CarbonImmutable::MONDAY);
            $end = $start->addWeek();
            $minutes = $assignments->sum(function ($assignment) use ($start, $end): float {
                $shift = $assignment->shift;
                $clipStart = $shift->starts_at->max($start);
                $clipEnd = $shift->ends_at->min($end);
                if ($clipStart->gte($clipEnd)) {
                    return 0;
                }
                $grossMinutes = $shift->starts_at->diffInSeconds($shift->ends_at) / 60;
                $netRatio = $grossMinutes > 0 ? max(0, $grossMinutes - $shift->planned_break_minutes) / $grossMinutes : 0;

                return $clipStart->diffInSeconds($clipEnd) / 60 * $netRatio;
            });

            return [$day->format('o-W') => round($minutes / 60, 3)];
        })->all();
    }

    /**
     * Split at offset transitions before projecting onto a civil clock. This
     * also keeps a repeated-hour interval positive when its end reads earlier.
     *
     * @return list<array{left_percent: float, width_percent: float}>
     */
    private function clockSegments(CarbonImmutable $day, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $zone = $day->timezone;
        $transitions = $zone->getTransitions($start->timestamp, $end->timestamp);
        $offset = $start->setTimezone($zone)->offset;
        $cursor = $start->timestamp;
        $civilMidnight = CarbonImmutable::parse($day->toDateString(), 'UTC')->timestamp;
        $segments = [];
        $append = function (int $finish) use (&$segments, &$cursor, &$offset, $civilMidnight): void {
            if ($finish <= $cursor) {
                return;
            }
            $left = max(0, min(1440, ($cursor + $offset - $civilMidnight) / 60));
            $right = max(0, min(1440, ($finish + $offset - $civilMidnight) / 60));
            if ($right > $left) {
                $segments[] = ['left_percent' => round($left / 1440 * 100, 6), 'width_percent' => round(($right - $left) / 1440 * 100, 6)];
            }
            $cursor = $finish;
        };
        foreach ($transitions ?: [] as $transition) {
            $append($transition['ts']);
            $offset = $transition['offset'];
        }
        $append($end->timestamp);

        return $segments;
    }
}
