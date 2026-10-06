<?php

namespace App\Support\Operations;

use App\Models\Order;
use App\Models\Shift;

final class TimelineLocationPreview
{
    /** @var array<string, array{int, string, float, float}|null>|null */
    private static ?array $places = null;

    private static ?array $outline = null;

    private static ?array $cityCentres = null;

    private static ?string $path = null;

    /**
     * Only a locality estimate, never an address or a verified worksite position.
     * The caller already loads order; preview rendering must not trigger queries.
     *
     * @return array{state: string, label: string, place?: string, x?: float, y?: float}
     */
    public static function fromShift(Shift $shift): array
    {
        $location = self::normalize($shift->getAttribute('location_name'));
        if ($location === null) {
            return self::unlocated('unknown');
        }

        $order = $shift->relationLoaded('order') ? $shift->getRelation('order') : null;

        // A shift can override its order's location. Never mix the override with
        // an unrelated order city/country, and never inspect customer addresses.
        if ($order instanceof Order && ($location === '' || $location === self::normalize($order->getAttribute('location_name')))) {
            $country = self::normalize($order->getAttribute('country'));
            if ($country === null) {
                return self::unlocated('unknown');
            }

            if ($country !== '' && ! in_array($country, ['de', 'deu', 'deutschland', 'germany'], true)) {
                return self::unlocated('outside');
            }

            $city = self::normalize($order->getAttribute('city'));
            if ($city === null) {
                return self::unlocated('unknown');
            }

            $location = $city !== '' ? $city : self::normalize($order->getAttribute('location_name'));
        }

        if ($location === null || $location === '') {
            return self::unlocated('unknown');
        }

        $centres = self::cityCentres();
        if (isset($centres['names'][$location])) {
            return self::located($centres['names'][$location]['point'], true);
        }

        $places = self::places();
        $locality = $centres['localityAliases'][$location] ?? $location;
        if (array_key_exists($locality, $places)) {
            return $places[$locality] === null
                ? self::unlocated('ambiguous')
                : self::located($places[$locality]);
        }

        // A named city is a geographic anchor; an arbitrary unknown name is not.
        // Try longest bounded prefixes only, with known districts/station terms.
        // Never pick a geographically "nearest" place without coordinates.
        preg_match_all('/[\s,–—-]+/u', $location, $separators, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($separators[0]) as [$separator, $offset]) {
            $prefix = substr($location, 0, $offset);
            $suffix = substr($location, $offset + strlen($separator));
            $centre = $centres['names'][$prefix] ?? null;
            if (! self::isLocationQualifier($suffix, $centre['districts'] ?? [])) {
                continue;
            }
            if ($centre !== null) {
                return self::located($centre['point'], true);
            }

            $locality = $centres['localityAliases'][$prefix] ?? $prefix;
            if (array_key_exists($locality, $places)) {
                return $places[$locality] === null
                    ? self::unlocated('ambiguous')
                    : self::located($places[$locality]);
            }
        }

        return self::unlocated('unknown');
    }

    private static function located(array $place, bool $cityCentre = false): array
    {
        [$x, $y] = self::project($place[2], $place[3]);
        if (! is_finite($x) || ! is_finite($y) || $x < 0 || $x > 160 || $y < 0 || $y > 176) {
            return self::unlocated('unknown');
        }

        return [
            'state' => 'located',
            'label' => $cityCentre ? 'Stadtmitte · ungefähr' : 'Ortslage · ungefähr',
            'place' => $place[1],
            'x' => round($x, 2),
            'y' => round($y, 2),
        ];
    }

    public static function viewBox(): string
    {
        return '0 0 160 176';
    }

    public static function outlinePath(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }

        $paths = [];
        foreach (self::outline()['coordinates'] as $ring) {
            $points = [];
            foreach ($ring as [$longitude, $latitude]) {
                [$x, $y] = self::project($latitude, $longitude);
                // Decimal dot is independent of the worker's active locale.
                $points[] = number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
            }
            $paths[] = 'M'.implode('L', $points).'Z';
        }

        return self::$path = implode('', $paths);
    }

    private static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        // Invalid supplied values are not missing fields: they must not enable
        // a fallback to another location or an unspecified country.
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 200) {
            return null;
        }

        // Preserve punctuation and spelling. Only reviewed aliases and bounded
        // location qualifiers below may relax exact-name matching.
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 'UTF-8');
    }

    private static function isLocationQualifier(string $suffix, array $districts): bool
    {
        foreach ($districts as $district) {
            if ($suffix === $district) {
                return true;
            }
            if (str_starts_with($suffix, $district.' ') || str_starts_with($suffix, $district.'-')) {
                return self::isLocationQualifier(substr($suffix, strlen($district) + 1), []);
            }
        }

        // Deliberately not free text: another city, a route, street address or
        // foreign-country suffix must not silently inherit a German marker.
        $qualifier = '(?:hbf\.?|bf\.?|bhf\.?|gbf\.?|rbf\.?|bahnhof|hauptbahnhof|güterbahnhof|gueterbahnhof|rangierbahnhof|hafen|nord|süd|sued|ost|west|mitte|zentrum|depot|terminal)';

        return preg_match('/^'.$qualifier.'(?:[ -]'.$qualifier.'){0,2}$/uD', $suffix) === 1
            || $suffix === 'hafen (rail one)';
    }

    private static function unlocated(string $state): array
    {
        return [
            'state' => $state,
            'label' => match ($state) {
                'outside' => 'Außerhalb Deutschlands',
                'ambiguous' => 'Ort nicht eindeutig',
                default => 'Standort nicht verortet',
            },
        ];
    }

    private static function places(): array
    {
        // Cache public immutable data only; no Shift/Order or user input survives
        // in this static cache, including in long-running application workers.
        return self::$places ??= json_decode(
            file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-places.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private static function cityCentres(): array
    {
        if (self::$cityCentres !== null) {
            return self::$cityCentres;
        }

        $data = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-city-centres.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $names = [];
        foreach ($data['cities'] as $city) {
            foreach ($city['names'] as $name) {
                $names[$name] = ['point' => $city['point'], 'districts' => $city['districts']];
            }
        }

        return self::$cityCentres = ['names' => $names, 'localityAliases' => $data['localityAliases']];
    }

    private static function outline(): array
    {
        return self::$outline ??= json_decode(
            file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-outline.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array{float, float} */
    private static function project(float $latitude, float $longitude): array
    {
        $projection = self::outline()['projection'];
        $cosine = cos(deg2rad($projection['standard_parallel']));
        $mapWidth = ($projection['east'] - $projection['west']) * $cosine;
        $mapHeight = $projection['north'] - $projection['south'];
        $scale = min(
            ($projection['width'] - 2 * $projection['padding']) / $mapWidth,
            ($projection['height'] - 2 * $projection['padding']) / $mapHeight,
        );

        return [
            ($projection['width'] - $mapWidth * $scale) / 2 + ($longitude - $projection['west']) * $cosine * $scale,
            ($projection['height'] - $mapHeight * $scale) / 2 + ($projection['north'] - $latitude) * $scale,
        ];
    }
}
