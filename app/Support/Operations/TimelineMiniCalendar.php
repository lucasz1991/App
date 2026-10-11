<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TimelineMiniCalendar
{
    /**
     * Present the occupied calendar dates of [start, end) in the start's display zone.
     *
     * @return array{month_label: string, range_label: string, accessible_label: string, days: list<array{date: string, number: int, outside: bool, selected: bool, start: bool, end: bool}>}
     */
    public static function forInterval(CarbonInterface $start, CarbonInterface $end): array
    {
        [$firstDay, $lastDay, $month, $gridStart, $cellCount] = self::intervalContext($start, $end);
        $seed = self::seed($firstDay, $lastDay, $month, $gridStart, $cellCount);
        $days = [];

        for ($index = 0; $index < $cellCount; $index++) {
            $day = $gridStart->addDays($index);
            $selected = $lastDay !== null && $day->greaterThanOrEqualTo($firstDay) && $day->lessThanOrEqualTo($lastDay);
            $days[] = [
                'date' => $day->toDateString(),
                'number' => $day->day,
                'outside' => ! $day->isSameMonth($month),
                'selected' => $selected,
                'start' => $selected && $day->isSameDay($firstDay),
                'end' => $selected && $day->isSameDay($lastDay),
            ];
        }

        return [
            'month_label' => $seed['monthLabel'], 'range_label' => $seed['rangeLabel'], 'accessible_label' => $seed['accessibleLabel'],
            'days' => $days,
        ];
    }

    /** Same calendar facts, without constructing unused cells for every closed badge. */
    public static function forIntervalSeed(CarbonInterface $start, CarbonInterface $end): array
    {
        return self::seed(...self::intervalContext($start, $end));
    }

    private static function intervalContext(CarbonInterface $start, CarbonInterface $end): array
    {
        $from = CarbonImmutable::instance($start)->locale('de');
        $until = CarbonImmutable::instance($end)->setTimezone($from->getTimezone());
        $firstDay = $from->startOfDay();
        $lastDay = $until->greaterThan($from) ? $until->subMicrosecond()->startOfDay() : null;
        $month = $from->startOfMonth();
        $gridStart = $month->startOfWeek(CarbonInterface::MONDAY);
        $cellCount = max(35, (int) ceil(($month->dayOfWeekIso - 1 + $month->daysInMonth) / 7) * 7);

        return [$firstDay, $lastDay, $month, $gridStart, $cellCount];
    }

    private static function seed(CarbonImmutable $firstDay, ?CarbonImmutable $lastDay, CarbonImmutable $month, CarbonImmutable $gridStart, int $cellCount): array
    {
        $monthLabel = $month->translatedFormat('F Y');
        $rangeLabel = $lastDay === null ? 'Kein gültiger Zeitraum' : $firstDay->format('d.m.Y');
        if ($lastDay !== null && ! $firstDay->isSameDay($lastDay)) {
            $rangeLabel .= ' bis '.$lastDay->format('d.m.Y');
        }

        return [
            'monthLabel' => $monthLabel, 'rangeLabel' => $rangeLabel,
            'accessibleLabel' => 'Kalender '.$monthLabel.'. '.($lastDay === null ? $rangeLabel : 'Markierter Zeitraum: '.$rangeLabel).'.',
            'gridStart' => $gridStart->toDateString(), 'count' => $cellCount, 'month' => $month->format('Y-m'),
            'first' => $firstDay->toDateString(), 'last' => $lastDay?->toDateString(),
        ];
    }
}
