<?php

namespace App\Support\Operations;

/** Local planning geometry from the same state paths as the Dashboard map. */
final class GermanFederalStates
{
    /** Around 0.5 km on this generalized map; borders are not cadastral facts. */
    private const BOUNDARY_TOLERANCE = 0.75;

    private static ?array $data = null;

    /** @return array<string, string> ISO 3166-2 code => German label */
    public static function options(): array
    {
        return array_map(fn (array $state): string => $state['name'], self::states());
    }

    /** @return array<string, string> Safe local SVG paths in viewBox 0 0 1154 1363 */
    public static function paths(): array
    {
        $paths = [];
        foreach (self::data()['render_order'] as $code) {
            $paths[$code] = self::states()[$code]['path'];
        }

        return $paths;
    }

    /** Null means outside, malformed, or too close to a generalized boundary. */
    public static function containing(float $latitude, float $longitude): ?string
    {
        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < 47 || $latitude > 56 || $longitude < 5 || $longitude > 16) {
            return null;
        }

        $point = GermanyMap::projectCoordinates($latitude, $longitude);
        $matches = [];
        foreach (self::states() as $code => $state) {
            [$minX, $minY, $maxX, $maxY] = $state['bounds'];
            $tolerance = self::BOUNDARY_TOLERANCE;
            if ($point['x'] < $minX - $tolerance || $point['x'] > $maxX + $tolerance
                || $point['y'] < $minY - $tolerance || $point['y'] > $maxY + $tolerance) {
                continue;
            }

            $inside = false;
            foreach ($state['rings'] as $ring) {
                $previous = $ring[count($ring) - 1];
                foreach ($ring as $current) {
                    if (self::nearSegment($point['x'], $point['y'], $previous, $current)) {
                        return null;
                    }
                    [$x1, $y1] = $previous;
                    [$x2, $y2] = $current;
                    // Even-odd crossing includes separate islands and subtracts
                    // city-state holes, exactly as fill-rule="evenodd" on the map.
                    if (($y1 > $point['y']) !== ($y2 > $point['y'])
                        && $point['x'] < ($x2 - $x1) * ($point['y'] - $y1) / ($y2 - $y1) + $x1) {
                        $inside = ! $inside;
                    }
                    $previous = $current;
                }
            }

            if ($inside) {
                $matches[] = $code;
            }
        }

        // The source explicitly draws Bremerhaven's OSM polygon over the BKG
        // Niedersachsen polygon; that older outline has no Bremerhaven hole.
        // Preserve this exact source layering, never pick the nearest capital.
        sort($matches);
        if ($matches === ['DE-HB', 'DE-NI']) {
            return 'DE-HB';
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function nearSegment(float $x, float $y, array $a, array $b): bool
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lengthSquared = $dx * $dx + $dy * $dy;
        $fraction = $lengthSquared > 0 ? max(0, min(1, (($x - $a[0]) * $dx + ($y - $a[1]) * $dy) / $lengthSquared)) : 0;

        return ($x - $a[0] - $fraction * $dx) ** 2 + ($y - $a[1] - $fraction * $dy) ** 2 <= self::BOUNDARY_TOLERANCE ** 2;
    }

    private static function states(): array
    {
        return self::data()['states'];
    }

    private static function data(): array
    {
        return self::$data ??= json_decode(
            file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-federal-states.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
