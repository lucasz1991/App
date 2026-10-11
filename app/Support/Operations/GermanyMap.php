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
            $place = $assignment->shift instanceof Shift ? self::locate($assignment->shift, true) : null;
            if ($place === null) {
                $unplaced++;

                continue;
            }
            $key = $place['left'].'|'.$place['top'];
            $points[$key] ??= ['left' => $place['left'], 'top' => $place['top'], 'label' => $place['label'], 'count' => 0, 'next' => false, 'date' => $assignment->shift->starts_at->setTimezone($timezone)->locale('de')->isoFormat('dd, D. MMM')];
            $points[$key]['count']++;
            $points[$key]['next'] = $points[$key]['next'] || $index === 0;
        }

        return ['points' => array_values($points), 'unplaced' => $unplaced, 'total' => $upcoming->count()];
    }

    /**
     * Schichtplanung: Schichten je Einsatzort, eingefärbt nach dem dringlichsten Besetzungsstand.
     *
     * @return array{points: list<array{left: float, top: float, label: string, count: int, open: int, tone: string}>, unplaced: int, total: int}
     */
    public static function planning(Collection $shifts): array
    {
        $points = [];
        $unplaced = 0;
        foreach ($shifts as $shift) {
            $place = self::locate($shift);
            if ($place === null) {
                $unplaced++;

                continue;
            }
            [$tone, $open] = self::staffing($shift);
            $key = $place['left'].'|'.$place['top'];
            $points[$key] ??= ['left' => $place['left'], 'top' => $place['top'], 'label' => $place['place'], 'count' => 0, 'open' => 0, 'tone' => 'closed'];
            $points[$key]['count']++;
            $points[$key]['open'] += $open;
            if (self::TONE_RANK[$tone] > self::TONE_RANK[$points[$key]['tone']]) {
                $points[$key]['tone'] = $tone;
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
        $mercator = fn (float $lat): float => log(tan(M_PI / 4 + deg2rad($lat) / 2));
        $x = $b['x0'] + ($longitude - $b['west']) / ($b['east'] - $b['west']) * ($b['x1'] - $b['x0']);
        $y = $b['y0'] + ($mercator($b['north']) - $mercator($latitude)) / ($mercator($b['north']) - $mercator($b['south'])) * ($b['y1'] - $b['y0']);

        return [round($x / self::VIEW_WIDTH * 100, 1), round($y / self::VIEW_HEIGHT * 100, 1)];
    }
}
