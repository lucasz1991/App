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
        $from = CarbonImmutable::instance($start)->locale('de');
        $until = CarbonImmutable::instance($end)->setTimezone($from->getTimezone());
        $firstDay = $from->startOfDay();
        $lastDay = $until->greaterThan($from) ? $until->subMicrosecond()->startOfDay() : null;
        $month = $from->startOfMonth();
        $gridStart = $month->startOfWeek(CarbonInterface::MONDAY);
        $cellCount = max(35, (int) ceil(($month->dayOfWeekIso - 1 + $month->daysInMonth) / 7) * 7);
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

        $monthLabel = $month->translatedFormat('F Y');
        $rangeLabel = $lastDay === null ? 'Kein gültiger Zeitraum' : $firstDay->format('d.m.Y');
        if ($lastDay !== null && ! $firstDay->isSameDay($lastDay)) {
            $rangeLabel .= ' bis '.$lastDay->format('d.m.Y');
        }

        return [
            'month_label' => $monthLabel,
            'range_label' => $rangeLabel,
            'accessible_label' => 'Kalender '.$monthLabel.'. '.($lastDay === null ? $rangeLabel : 'Markierter Zeitraum: '.$rangeLabel).'.',
            'days' => $days,
        ];
    }
}
