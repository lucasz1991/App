<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Query-free presentation of already authorized, published personal assignments. */
final class WorkdayOverview
{
    public static function date(string $value, string $timezone): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $date && $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function stripStart(CarbonImmutable $selected): CarbonImmutable
    {
        return $selected->startOfWeek()->subWeek();
    }

    /**
     * The list and map share the selected 14-day interval. The next marker is
     * derived from the full personal schedule, not from whichever day is selected.
     * Missing map locations therefore never promote a later duty to "next".
     */
    public static function make(Collection $assignments, CarbonImmutable $selected, CarbonImmutable $now): array
    {
        $timezone = $selected->getTimezone()->getName();
        $selected = $selected->startOfDay();
        $until = $selected->addDays(14);
        $ordered = $assignments->sortBy(fn ($assignment) => $assignment->shift->starts_at->getTimestamp())->values();
        $nextId = $ordered->first(fn ($assignment) => $assignment->shift->ends_at->gt($now))?->id;
        $visible = $ordered->filter(fn ($assignment) => $assignment->shift->ends_at->gt($selected) && $assignment->shift->starts_at->lt($until))->values();
        $days = [];
        $stripStart = self::stripStart($selected);
        for ($offset = 0; $offset < 21; $offset++) {
            $date = $stripStart->addDays($offset);
            $duties = $ordered->filter(fn ($assignment) => $assignment->shift->starts_at->lt($date->addDay()) && $assignment->shift->ends_at->gt($date));
            $days[] = ['date' => $date, 'key' => $date->toDateString(), 'is_today' => $date->isSameDay($now), 'is_selected' => $date->isSameDay($selected),
                'duties' => $duties->count(), 'open' => $duties->contains(fn ($assignment) => $assignment->status->value === 'requested' && ! $assignment->plan_is_stale && $assignment->shift->ends_at->gt($now))];
        }

        $points = [];
        $pointKeys = [];
        $unplaced = 0;
        foreach ($visible as $assignment) {
            $places = GermanyMap::locateAll($assignment->shift, true);
            $pointKeys[$assignment->id] = [];
            if ($places === []) {
                $unplaced++;
            }
            foreach ($places as $place) {
                $key = $place['left'].'|'.$place['top'];
                if (in_array($key, $pointKeys[$assignment->id], true)) {
                    continue;
                }
                $pointKeys[$assignment->id][] = $key;
                $points[$key] ??= ['key' => $key, 'left' => $place['left'], 'top' => $place['top'], 'label' => $place['label'], 'count' => 0,
                    'next' => false, 'date' => $assignment->shift->starts_at->setTimezone($timezone)->locale('de')->isoFormat('dd, D. MMM'),
                    'assignment_ids' => [], 'event_id' => 'shift-'.$assignment->id];
                $points[$key]['count']++;
                $points[$key]['assignment_ids'][] = $assignment->id;
                if ($assignment->id === $nextId) {
                    $points[$key]['next'] = true;
                    $points[$key]['event_id'] = 'shift-'.$assignment->id;
                    $points[$key]['label'] = $place['label'];
                    $points[$key]['date'] = $assignment->shift->starts_at->setTimezone($timezone)->locale('de')->isoFormat('dd, D. MMM');
                }
            }
        }

        return ['selected_date' => $selected, 'label' => $selected->locale('de')->isoFormat('dddd, D. MMMM'), 'days' => $days,
            'from' => $selected, 'to' => $until, 'assignments' => $visible, 'next_id' => $nextId,
            'map' => ['points' => array_values($points), 'unplaced' => $unplaced, 'total' => $visible->count()], 'point_keys' => $pointKeys];
    }
}
