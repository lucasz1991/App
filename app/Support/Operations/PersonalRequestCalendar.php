<?php

namespace App\Support\Operations;

use App\Services\Operations\StaffTimelineLayout;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/** Read-only projection of the already scoped personal calendar DTO. */
final class PersonalRequestCalendar
{
    public static function layout(?array $calendar, CarbonInterface $start, CarbonInterface $end, string $selectedLabel = 'Diese Anfrage'): array
    {
        $selectedLabel = in_array($selectedLabel, ['Diese Anfrage', 'Dieser Dienst'], true) ? $selectedLabel : 'Diese Anfrage';
        $zone = self::timezone($calendar['timezone'] ?? null, $start);
        $anchor = CarbonImmutable::instance($start)->setTimezone($zone)->locale('de');
        $until = CarbonImmutable::instance($end)->setTimezone($zone);
        $seed = TimelineMiniCalendar::forIntervalSeed($anchor, $until);
        $from = CarbonImmutable::parse($seed['gridStart'], $zone);
        $to = $from->addDays($seed['count']);
        if (($calendar['from'] ?? null) instanceof CarbonInterface && ($calendar['to'] ?? null) instanceof CarbonInterface) {
            $givenFrom = CarbonImmutable::instance($calendar['from'])->setTimezone($zone);
            $givenTo = CarbonImmutable::instance($calendar['to'])->setTimezone($zone);
            $days = $givenFrom->diffInDays($givenTo, false);
            if ($givenFrom->isStartOfDay() && $givenTo->isStartOfDay() && $givenFrom->dayOfWeekIso === 1
                && $givenTo->dayOfWeekIso === 1 && $days >= 28 && $days <= 42 && (int) $days % 7 === 0) {
                $from = $givenFrom;
                $to = $givenTo;
            }
        }
        $sourceAvailable = is_array($calendar['events'] ?? null);
        $normalized = [];
        $seen = [];
        foreach ($sourceAvailable ? $calendar['events'] : [] as $event) {
            if (! is_array($event) || ! is_scalar($event['id'] ?? null)
                || ! in_array($event['kind'] ?? null, ['shift', 'capacity', 'absence', 'training'], true)
                || ! ($event['starts_at'] ?? null) instanceof CarbonInterface || ! ($event['ends_at'] ?? null) instanceof CarbonInterface) {
                continue;
            }
            $id = (string) $event['id'];
            $eventStart = CarbonImmutable::instance($event['starts_at'])->setTimezone($zone);
            $eventEnd = CarbonImmutable::instance($event['ends_at'])->setTimezone($zone);
            if ($id === '' || isset($seen[$id]) || $eventEnd->lte($eventStart) || $eventStart->gte($to) || $eventEnd->lte($from)) {
                continue;
            }
            $seen[$id] = true;
            $normalized[] = [
                'id' => $id, 'kind' => $event['kind'],
                'title' => is_string($event['title'] ?? null) ? $event['title'] : '',
                'status' => is_string($event['status'] ?? null) ? $event['status'] : '',
                'start' => $eventStart, 'end' => $eventEnd,
                'is_request' => ($event['is_request'] ?? false) === true,
                'aria_label' => self::eventLabel($event, $eventStart, $eventEnd, $zone, $selectedLabel),
            ];
        }
        $timeline = new StaffTimelineLayout;
        $events = $timeline->assignLanes(collect($normalized));
        $weeks = [];
        $visible = [];
        $kinds = [];
        for ($weekFrom = $from; $weekFrom->lt($to); $weekFrom = $weekFrom->addWeek()) {
            $weekDays = collect(range(0, 6))->map(fn (int $offset) => $weekFrom->addDays($offset));
            $projected = $timeline->periodEvents($weekDays, $events)->all();
            $laneKeys = array_values(array_unique(array_column($projected, 'lane')));
            sort($laneKeys);
            $laneMap = array_flip($laneKeys);
            foreach ($projected as &$event) {
                $event['lane'] = $laneMap[$event['lane']];
                $visible[$event['id']] = true;
                $kinds[$event['kind']] = true;
            }
            unset($event);
            $weeks[] = [
                'week_label' => 'Kalenderwoche '.$weekFrom->format('W'),
                'days' => $weekDays->map(fn (CarbonImmutable $day) => [
                    'date' => $day->toDateString(), 'number' => $day->day,
                    'outside' => ! $day->isSameMonth($anchor),
                    'day_label' => $day->locale('de')->translatedFormat('l, j. F Y'),
                    'has_request' => $events->contains(fn (array $event) => $event['is_request']
                        && $event['start']->lt($day->addDay()) && $event['end']->gt($day)),
                ])->all(),
                'events' => $projected, 'lane_count' => count($laneKeys),
            ];
        }

        return [
            'month_label' => $anchor->startOfMonth()->translatedFormat('F Y'),
            'aria_label' => 'Kalender '.$anchor->startOfMonth()->translatedFormat('F Y').'.',
            'timezone' => $zone, 'selected_label' => $selectedLabel, 'from' => $from, 'to' => $to, 'weeks' => $weeks,
            'event_count' => count($visible), 'source_available' => $sourceAvailable,
            'kinds' => array_keys($kinds), 'has_request' => $events->contains(fn (array $event) => $event['is_request']),
        ];
    }

    private static function timezone(mixed $zone, CarbonInterface $fallback): string
    {
        if (is_string($zone) && $zone !== '') {
            try {
                return (new DateTimeZone($zone))->getName();
            } catch (Throwable) {
                // The event's own valid display zone remains the fallback.
            }
        }

        return $fallback->getTimezone()->getName();
    }

    private static function eventLabel(array $event, CarbonImmutable $start, CarbonImmutable $end, string $zone, string $selectedLabel): string
    {
        $kind = ['shift' => 'Dienst', 'capacity' => 'Verfügbarkeit', 'absence' => 'Abwesenheit', 'training' => 'Schulung'][$event['kind']];
        $title = is_string($event['title'] ?? null) ? trim($event['title']) : '';
        $location = is_string($event['location'] ?? null) ? trim($event['location']) : '';
        $status = is_string($event['status'] ?? null) && $event['status'] !== '' ? OperationsNavigation::status($event['status']) : null;

        return implode(' · ', array_filter([
            ($event['is_request'] ?? false) === true ? $selectedLabel : null,
            $kind, $title,
            $start->format('d.m.Y H:i').' (UTC'.$start->format('P').') bis '.$end->format('d.m.Y H:i').' (UTC'.$end->format('P').')',
            $zone, $location, $status,
        ]));
    }
}
