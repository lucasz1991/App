<?php

namespace App\Support\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Enums\ShiftStatus;
use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * Punkte für die Deutschlandkarte (<x-ui.germany-map>).
 *
 * Die Ortsauflösung übernimmt ausschließlich TimelineLocationPreview (bestätigte
 * Einsatzpunkte, sonst geprüfte GeoNames-Ortslagen) – dieselbe Quelle wie alle
 * anderen Karten. Diese Klasse projiziert nur und bündelt; ausgegeben werden
 * Prozentpositionen auf der Karte, nie Koordinaten oder Adressen.
 */
final class GermanyMap
{
    /** Ausdehnung der Umrissgrafik (germany-outline.svg, Mercator, viewBox 0 0 1154 1363). */
    private const VIEW_WIDTH = 1154.0;

    private const VIEW_HEIGHT = 1363.0;

    private const BOUNDS = ['west' => 5.866, 'east' => 15.042, 'south' => 47.270, 'north' => 55.058, 'x0' => 96.1, 'x1' => 1057.9, 'y0' => 28.0, 'y1' => 1335.0];

    /** Rangfolge der Besetzungstöne: der dringlichste Zustand prägt einen Ort. */
    private const TONE_RANK = ['open' => 3, 'awaiting' => 2, 'staffed' => 1, 'closed' => 0];

    /**
     * Full-precision Mercator point on the existing map; never clipped to Germany.
     * Used by state lookup and overlays. Existing pin APIs retain their rounding.
     *
     * @return array{x: float, y: float, left: float, top: float}
     */
    public static function projectCoordinates(float $latitude, float $longitude): array
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || abs($latitude) >= 90 || abs($longitude) > 180) {
            throw new \InvalidArgumentException('Ungültige Kartenkoordinaten.');
        }

        $b = self::BOUNDS;
        $mercator = fn (float $lat): float => log(tan(M_PI / 4 + deg2rad($lat) / 2));
        $x = $b['x0'] + ($longitude - $b['west']) / ($b['east'] - $b['west']) * ($b['x1'] - $b['x0']);
        $y = $b['y0'] + ($mercator($b['north']) - $mercator($latitude)) / ($mercator($b['north']) - $mercator($b['south'])) * ($b['y1'] - $b['y0']);

        return ['x' => $x, 'y' => $y, 'left' => $x / self::VIEW_WIDTH * 100, 'top' => $y / self::VIEW_HEIGHT * 100];
    }

    /** Geodesic Luftlinie ring, sampled before projection; no road-distance claim. */
    public static function radiusPath(float $latitude, float $longitude, float $radiusKm): string
    {
        self::projectCoordinates($latitude, $longitude);
        if (! is_finite($radiusKm) || $radiusKm <= 0 || $radiusKm > 2000) {
            throw new \InvalidArgumentException('Der Kartenradius muss zwischen 0 und 2000 km liegen.');
        }

        $lat = deg2rad($latitude);
        $lon = deg2rad($longitude);
        $distance = $radiusKm / 6371.0088;
        $path = [];
        for ($step = 0; $step < 120; $step++) {
            $bearing = 2 * M_PI * $step / 120;
            $destinationLat = asin(sin($lat) * cos($distance) + cos($lat) * sin($distance) * cos($bearing));
            $destinationLon = $lon + atan2(sin($bearing) * sin($distance) * cos($lat), cos($distance) - sin($lat) * sin($destinationLat));
            $point = self::projectCoordinates(rad2deg($destinationLat), fmod(rad2deg($destinationLon) + 540, 360) - 180);
            $path[] = ($step === 0 ? 'M ' : 'L ').number_format($point['x'], 2, '.', '').' '.number_format($point['y'], 2, '.', '');
        }

        return implode(' ', $path).' Z';
    }

    /**
     * Kartenpunkt aus einer TimelineLocationPreview; null, wenn nicht verortet.
     * $coarse rundet auf ~10 km (Mitarbeiteransichten zeigen eine Gegend, keinen Standort).
     *
     * @return array{left: float, top: float, label: string, precision: string}|null
     */
    public static function fromPreview(array $preview, bool $coarse = false): ?array
    {
        if (($preview['state'] ?? null) !== 'located' || ! is_numeric($preview['latitude'] ?? null) || ! is_numeric($preview['longitude'] ?? null)) {
            return null;
        }
        $latitude = (float) $preview['latitude'];
        $longitude = (float) $preview['longitude'];
        if ($coarse) {
            [$latitude, $longitude] = [round($latitude, 1), round($longitude, 1)];
        }
        [$left, $top] = self::project($latitude, $longitude);

        return ['left' => $left, 'top' => $top, 'label' => (string) ($preview['place'] ?? ''), 'precision' => (string) ($preview['label'] ?? '')];
    }

    /** Display all named places without inventing a midpoint or route between them. */
    public static function pointsFromPreview(array $preview, bool $coarse = false): array
    {
        $locations = ($preview['state'] ?? null) === 'multiple' ? ($preview['points'] ?? []) : [$preview];
        $points = [];
        foreach ($locations as $location) {
            $point = is_array($location) ? self::fromPreview($location, $coarse) : null;
            if ($point !== null) {
                $key = $coarse ? $point['left'].'|'.$point['top']
                    : number_format((float) $location['latitude'], 6, '.', '').','.number_format((float) $location['longitude'], 6, '.', '');
                $points[$key] ??= $point;
            }
        }

        return array_values($points);
    }

    /** Query-free display points; employee maps continue to use coarse coordinates. */
    public static function locateAll(Shift $shift, bool $coarse = false): array
    {
        $preview = TimelineLocationPreview::fromShift($shift);

        return array_map(fn (array $point): array => [
            'label' => ($preview['state'] ?? null) === 'multiple' ? $point['label'] : self::label($shift),
            'place' => $point['label'],
        ] + $point, self::pointsFromPreview($preview, $coarse));
    }

    /**
     * label = Einsatzort der Schicht, place = aufgelöster Ort (z. B. „Hamburg“ für „Hamburg-Billwerder“).
     *
     * @return array{label: string, place: string, left: float, top: float, precision: string}|null
     */
    public static function locate(Shift $shift, bool $coarse = false): ?array
    {
        $point = self::fromPreview(TimelineLocationPreview::fromShift($shift), $coarse);

        return $point === null ? null : ['label' => self::label($shift), 'place' => $point['label']] + $point;
    }

    /**
     * Eigene Dienste (Mein Arbeitstag): Orte der kommenden Dienste, der nächste hervorgehoben.
     *
     * @return array{points: list<array{left: float, top: float, label: string, count: int, next: bool, date: string}>, unplaced: int, total: int}
     */
    public static function personal(Collection $assignments, string $timezone): array
    {
        $points = [];
        $unplaced = 0;
        $upcoming = $assignments->filter(fn ($assignment) => $assignment->shift?->ends_at?->isFuture())->values();
        foreach ($upcoming as $index => $assignment) {
            $places = $assignment->shift instanceof Shift ? self::locateAll($assignment->shift, true) : [];
            if ($places === []) {
                $unplaced++;

                continue;
            }
            $seen = [];
            foreach ($places as $place) {
                $key = $place['left'].'|'.$place['top'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $points[$key] ??= ['left' => $place['left'], 'top' => $place['top'], 'label' => $place['label'], 'count' => 0, 'next' => false, 'date' => $assignment->shift->starts_at->setTimezone($timezone)->locale('de')->isoFormat('dd, D. MMM')];
                $points[$key]['count']++;
                $points[$key]['next'] = $points[$key]['next'] || $index === 0;
            }
        }

        return ['points' => array_values($points), 'unplaced' => $unplaced, 'total' => $upcoming->count()];
    }

    /**
     * Schichtplanung: Schichten je Einsatzort, eingefärbt nach dem dringlichsten Besetzungsstand.
     *
     * @return array{points: list<array{key: string, left: float, top: float, label: string, count: int, open: int, tone: string}>, unplaced: int, total: int}
     */
    public static function planning(Collection $shifts): array
    {
        $points = [];
        $unplaced = 0;
        foreach ($shifts as $shift) {
            $places = self::locateAll($shift);
            if ($places === []) {
                $unplaced++;

                continue;
            }
            [$tone, $open] = self::staffing($shift);
            $seen = [];
            foreach ($places as $place) {
                $key = $place['left'].'|'.$place['top'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $points[$key] ??= ['key' => $key, 'left' => $place['left'], 'top' => $place['top'], 'label' => $place['place'], 'count' => 0, 'open' => 0, 'tone' => 'closed'];
                $points[$key]['count']++;
                $points[$key]['open'] += $open;
                if (self::TONE_RANK[$tone] > self::TONE_RANK[$points[$key]['tone']]) {
                    $points[$key]['tone'] = $tone;
                }
            }
        }
        $points = array_values($points);
        usort($points, fn (array $a, array $b): int => [$b['open'], $b['count']] <=> [$a['open'], $a['count']]);

        return ['points' => $points, 'unplaced' => $unplaced, 'total' => $shifts->count()];
    }

    /** @return array{0: string, 1: int} Ton und offene Plätze einer Schicht */
    private static function staffing(Shift $shift): array
    {
        $status = $shift->status instanceof \BackedEnum ? $shift->status : ShiftStatus::tryFrom((string) $shift->status);
        if (in_array($status, [ShiftStatus::Cancelled, ShiftStatus::Completed], true)) {
            return ['closed', 0];
        }
        $assignments = collect($shift->assignments ?? []);
        $reserved = $assignments->filter(fn ($assignment) => $assignment->status instanceof ShiftAssignmentStatus && $assignment->status->blocksAvailability())->count();
        $confirmed = $assignments->filter(fn ($assignment) => $assignment->status === ShiftAssignmentStatus::Confirmed)->count();
        $required = (int) $shift->required_staff;
        $open = max(0, $required - $reserved);

        return [$open > 0 ? 'open' : ($confirmed < $required ? 'awaiting' : 'staffed'), $open];
    }

    private static function label(Shift $shift): string
    {
        $snapshot = is_array($shift->location_snapshot ?? null) ? $shift->location_snapshot : [];
        $label = trim((string) ($snapshot['label'] ?? $snapshot['name'] ?? $shift->location_name ?? ''));

        return $label !== '' ? $label : 'Einsatzort';
    }

    /** Mercator-Projektion auf die Umrissgrafik, als Prozent der Kartenfläche. */
    private static function project(float $latitude, float $longitude): array
    {
        $b = self::BOUNDS;
        $latitude = max($b['south'], min($b['north'], $latitude));
        $longitude = max($b['west'], min($b['east'], $longitude));
        $point = self::projectCoordinates($latitude, $longitude);

        return [round($point['left'], 1), round($point['top'], 1)];
    }
}
