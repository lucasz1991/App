<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;

/** Compact, query-free facts for the one native timeline detail popover. */
final class TimelineEventDetail
{
    public static function forEvent(array $event, array $row, string $zone, bool $absencesOnly, string $state): array
    {
        $start = CarbonImmutable::instance($event['start'])->setTimezone($zone);
        $end = CarbonImmutable::instance($event['end'])->setTimezone($zone);
        $allDay = $event['kind'] === 'absence' && $start->isStartOfDay() && $end->isStartOfDay();
        $last = $allDay ? $end->subSecond() : $end;
        $dstChanged = $start->offset !== $end->offset;
        $parent = collect([$event['order_title'] ?? null, $event['order_number'] ?? null])->filter(fn ($value) => filled($value))->implode(' · ');
        $calendar = TimelineMiniCalendar::forIntervalSeed($start, $end);
        $preview = $event['location_preview'] ?? ['state' => 'unknown', 'label' => 'Standort nicht verortet'];
        $point = GermanyMap::fromPreview($preview);
        $location = $event['location_name'] ?? null;
        $name = (string) $row['user']->name;
        $precision = ($preview['confirmed'] ?? false) ? 'bestätigter Einsatzstandort.' : 'ungefähre Ortslage, keine genaue Einsatzadresse.';
        $planned = $row['planned_hours_by_week'][$event['iso_week']] ?? null;

        return [
            'kind' => $event['kind'], 'state' => $state, 'title' => $event['title'], 'status' => $event['status'],
            'parent' => $parent, 'shiftId' => $event['shift_id'] ?? null,
            'absenceId' => $absencesOnly ? ($event['absence_id'] ?? null) : null,
            'allDay' => $allDay, 'allDayLabel' => $start->format('d.m.Y').($start->isSameDay($last) ? '' : ' – '.$last->format('d.m.Y')),
            'start' => ['iso' => $start->toIso8601String(), 'time' => $start->format('H:i'), 'date' => $start->format('d.m.Y'), 'offset' => $start->format('P')],
            'end' => ['iso' => $end->toIso8601String(), 'time' => $end->format('H:i'), 'date' => $end->format('d.m.Y'), 'offset' => $end->format('P')],
            'dstChanged' => $dstChanged, 'elapsedHours' => number_format(abs($start->diffInMinutes($end)) / 60, 1, ',', '.'),
            'person' => ['name' => $name, 'avatar' => $row['user']->profile_photo_url],
            'metadata' => array_values(array_filter([
                filled($location) ? ['label' => 'Einsatzort', 'value' => $location] : null,
                filled($event['detail'] ?? null) ? ['label' => 'Kunde', 'value' => $event['detail']] : null,
                filled($event['role_name'] ?? null) ? ['label' => 'Tätigkeit', 'value' => $event['role_name']] : null,
                filled($event['shift_status_label'] ?? null) ? ['label' => 'Planstatus', 'value' => $event['shift_status_label']] : null,
                ($event['planned_break_minutes'] ?? 0) ? ['label' => 'Geplante Pause', 'value' => $event['planned_break_minutes'].' Minuten'] : null,
            ])),
            'workload' => $absencesOnly ? [] : array_values(array_filter([
                ['label' => 'Regelarbeitszeit', 'value' => $row['weekly_working_hours'] !== null ? number_format($row['weekly_working_hours'], 1, ',', '.').' h/Woche' : 'Nicht hinterlegt'],
                $planned !== null ? ['label' => 'Eingeplant · KW '.$event['visible_start']->setTimezone($zone)->isoWeek(), 'value' => number_format($planned, 1, ',', '.').' h'] : null,
            ])),
            // Only a civil-date seed travels with each badge, not 35–42 repeated calendar cells.
            'calendar' => $calendar,
            // Preserve the existing approximate/confirmed distinction without exposing coordinates.
            'map' => [
                'state' => $preview['state'], 'point' => $point ? ['left' => $point['left'], 'top' => $point['top']] : null,
                'accessibleLabel' => 'Deutschland: '.($point ? $preview['place'].' – '.$precision : $preview['label']),
                'place' => $point ? $preview['place'] : ($location ?: 'Kein Einsatzort'),
                'title' => $location ?: ($preview['place'] ?? 'Kein Einsatzort hinterlegt'), 'precision' => $preview['label'],
            ],
        ];
    }
}
