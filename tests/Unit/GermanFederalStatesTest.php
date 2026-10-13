<?php

namespace Tests\Unit;

use App\Support\Operations\GermanFederalStates;
use App\Support\Operations\GermanyMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GermanFederalStatesTest extends TestCase
{
    public function test_state_paths_are_exactly_the_existing_dashboard_geometry(): void
    {
        $root = dirname(__DIR__, 2);
        $data = json_decode(file_get_contents($root.'/resources/data/maps/germany-federal-states.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(hash_file('sha256', $root.'/'.$data['source']), $data['source_sha256']);
        $svg = new \DOMDocument;
        $svg->load($root.'/'.$data['source']);
        $xpath = new \DOMXPath($svg);
        $groups = $xpath->query('//*[local-name()="g" and @data-state]');
        $paths = GermanFederalStates::paths();
        $this->assertCount(16, $paths);
        $this->assertSame(16, $groups->length);
        $this->assertEqualsCanonicalizing(array_keys(GermanFederalStates::options()), array_keys($paths));
        $renderOrder = [];
        foreach ($groups as $group) {
            $renderOrder[] = $group->getAttribute('data-state');
            $path = $xpath->query('./*[local-name()="path"]', $group)->item(0);
            $this->assertSame($path->getAttribute('d'), $paths[$group->getAttribute('data-state')]);
            $this->assertMatchesRegularExpression('/\A[MLZ0-9.\s-]+\z/', $paths[$group->getAttribute('data-state')]);
            $this->assertSame('evenodd', $path->getAttribute('fill-rule'));
        }
        $this->assertSame($renderOrder, array_keys($paths));
        $this->assertGreaterThan(array_search('DE-NI', $renderOrder, true), array_search('DE-HB', $renderOrder, true));
        $this->assertSame('Hamburg', GermanFederalStates::options()['DE-HH']);
        $this->assertSame('Thüringen', GermanFederalStates::options()['DE-TH']);
        $this->assertSame('0 0 1154 1363', $data['view_box']);
    }

    #[DataProvider('interiorLocations')]
    public function test_known_interior_places_resolve_in_all_sixteen_states(float $lat, float $lon, string $expected): void
    {
        $this->assertSame($expected, GermanFederalStates::containing($lat, $lon));
    }

    public static function interiorLocations(): array
    {
        return [
            'Stuttgart' => [48.7758, 9.1829, 'DE-BW'],
            'München' => [48.1372, 11.5755, 'DE-BY'],
            'Berlin' => [52.52, 13.405, 'DE-BE'],
            'Potsdam' => [52.3989, 13.0657, 'DE-BB'],
            'Bremen' => [53.0793, 8.8017, 'DE-HB'],
            'Hamburg' => [53.5511, 9.9937, 'DE-HH'],
            'Wiesbaden' => [50.0826, 8.24, 'DE-HE'],
            'Schwerin' => [53.6294, 11.4139, 'DE-MV'],
            'Hannover' => [52.3759, 9.732, 'DE-NI'],
            'Düsseldorf' => [51.2277, 6.7735, 'DE-NW'],
            'Mainz' => [49.9929, 8.2473, 'DE-RP'],
            'Saarbrücken' => [49.2402, 6.9969, 'DE-SL'],
            'Dresden' => [51.0504, 13.7373, 'DE-SN'],
            'Magdeburg' => [52.1205, 11.6276, 'DE-ST'],
            'Kiel' => [54.3233, 10.1228, 'DE-SH'],
            'Erfurt' => [50.9848, 11.0299, 'DE-TH'],
            'Bremerhaven multipart' => [53.5413, 8.5809, 'DE-HB'],
            'Brandenburg beyond Berlin hole' => [52.7667, 13.25, 'DE-BB'],
            'Niedersachsen beyond Bremen hole' => [52.915, 8.822, 'DE-NI'],
        ];
    }

    #[DataProvider('unassignedLocations')]
    public function test_invalid_foreign_and_water_locations_are_not_guessed(float $lat, float $lon): void
    {
        $this->assertNull(GermanFederalStates::containing($lat, $lon));
    }

    public static function unassignedLocations(): array
    {
        return [
            'nan' => [NAN, 10.0], 'infinite' => [53.0, INF],
            'equator' => [0.0, 10.0], 'invalid longitude' => [53.0, 190.0],
            'Prague' => [50.0755, 14.4378], 'Salzburg' => [47.8095, 13.055],
            'Netherlands' => [52.2215, 6.8937], 'North Sea' => [54.0, 6.4],
        ];
    }

    public function test_exact_and_near_state_boundaries_remain_unknown(): void
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/data/maps/germany-federal-states.json'), true, 512, JSON_THROW_ON_ERROR);
        [$x, $y] = $data['states']['DE-BE']['rings'][0][0];
        foreach ([[$x, $y], [$x + 0.3, $y + 0.3], [$x - 0.3, $y - 0.3]] as $point) {
            [$lat, $lon] = $this->unproject(...$point);
            $this->assertNull(GermanFederalStates::containing($lat, $lon));
        }
    }

    public function test_full_precision_overlay_projection_matches_existing_pin_contract_without_clipping(): void
    {
        $point = GermanyMap::projectCoordinates(53.5511, 9.9937);
        $pin = GermanyMap::fromPreview(['state' => 'located', 'latitude' => 53.5511, 'longitude' => 9.9937]);
        $this->assertSame(round($point['left'], 1), $pin['left']);
        $this->assertSame(round($point['top'], 1), $pin['top']);
        $this->assertEqualsWithDelta($point['left'] / 100 * 1154, $point['x'], 0.00001);
        $this->assertEqualsWithDelta($point['top'] / 100 * 1363, $point['y'], 0.00001);
        $this->assertGreaterThan(100, GermanyMap::projectCoordinates(47.0, 20.0)['left']);
        $this->assertLessThan(0, GermanyMap::projectCoordinates(60.0, 10.0)['top']);
    }

    public function test_every_radius_vertex_is_at_the_requested_geodesic_distance(): void
    {
        foreach ([1, 80, 350, 1000, 2000] as $radius) {
            $path = GermanyMap::radiusPath(53.5511, 9.9937, $radius);
            $this->assertStringStartsWith('M ', $path);
            $this->assertStringEndsWith(' Z', $path);
            preg_match_all('/[ML] (-?\d+\.\d+) (-?\d+\.\d+)/', $path, $matches, PREG_SET_ORDER);
            $this->assertCount(120, $matches);
            foreach ($matches as $match) {
                [$lat, $lon] = $this->unproject((float) $match[1], (float) $match[2]);
                $a = sin(deg2rad($lat - 53.5511) / 2) ** 2
                    + cos(deg2rad($lat)) * cos(deg2rad(53.5511)) * sin(deg2rad($lon - 9.9937) / 2) ** 2;
                $actual = 6371.0088 * 2 * asin(sqrt($a));
                $this->assertEqualsWithDelta($radius, $actual, 0.02);
            }
        }
    }

    #[DataProvider('invalidRadii')]
    public function test_invalid_radius_inputs_are_rejected(float $lat, float $lon, float $radius): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GermanyMap::radiusPath($lat, $lon, $radius);
    }

    public static function invalidRadii(): array
    {
        return [[NAN, 10.0, 80.0], [90.0, 10.0, 80.0], [53.0, INF, 80.0], [53.0, 10.0, 0.0], [53.0, 10.0, -1.0], [53.0, 10.0, NAN], [53.0, 10.0, 2001.0]];
    }

    private function unproject(float $x, float $y): array
    {
        $mercator = fn (float $lat): float => log(tan(M_PI / 4 + deg2rad($lat) / 2));
        $lon = 5.866 + ($x - 96.1) / (1057.9 - 96.1) * (15.042 - 5.866);
        $mercatorLat = $mercator(55.058) - ($y - 28) / (1335 - 28) * ($mercator(55.058) - $mercator(47.270));

        return [rad2deg(2 * atan(exp($mercatorLat)) - M_PI / 2), $lon];
    }
}
