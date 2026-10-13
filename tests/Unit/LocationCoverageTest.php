<?php

namespace Tests\Unit;

use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Support\Operations\TimelineLocationPreview as Preview;
use PHPUnit\Framework\TestCase;

final class LocationCoverageTest extends TestCase
{
    public function test_public_candidates_cover_small_places_and_qualified_spelling(): void
    {
        foreach (['Neumarkt (Oberpfalz)', 'Neumarkt(Oberpfalz)', 'Neumarkt i.d.OPf.', 'Berlinchen', 'Maschen', 'München Laim', 'München Freimann'] as $name) {
            $point = Preview::fromShift(new Shift(['location_name' => $name]));
            self::assertSame('located', $point['state'], $name);
            self::assertArrayNotHasKey('confirmed', $point);
            self::assertStringContainsString('ungefähr', $point['label']);
        }
        $village = Preview::fromInquiry((new OperationInquiry)->forceFill(['location_name' => 'Berlinchen']));
        self::assertSame(2950156, $village['geonames_id']);
        self::assertSame(53.21865, $village['latitude']);
    }

    public function test_ambiguous_proposals_are_source_backed_and_never_persisted(): void
    {
        foreach (['Sulzdorf' => 2824770, 'Kirchheim' => 2890485, 'Rheinhausen' => 2847631] as $name => $id) {
            $shift = new Shift(['location_name' => $name, 'title' => $name.' · WGM']);
            $before = $shift->getAttributes();
            $regional = Preview::forRegionalAssessment($shift);
            $point = Preview::fromShift($shift);
            self::assertSame($id, $point['geonames_id']);
            self::assertSame('Ortsvorschlag · ungefähr', $point['label']);
            self::assertTrue($point['estimated']);
            self::assertGreaterThan(1, $point['candidate_count']);
            self::assertArrayNotHasKey('confirmed', $point);
            self::assertSame($before, $shift->getAttributes());
            self::assertSame($regional, Preview::forRegionalAssessment($shift));
        }
    }

    public function test_explicit_rail_kind_selects_real_yard_without_moving_a_known_city_to_a_homonym(): void
    {
        $yard = Preview::fromShift(new Shift(['location_name' => 'Maschen Rbf']));
        self::assertSame(3336907, $yard['geonames_id']);
        self::assertSame(53.40595, $yard['latitude']);
        self::assertSame(10.05597, $yard['longitude']);
        foreach (['München Hbf.', 'Bahnhof München'] as $name) {
            self::assertSame('München', Preview::fromShift(new Shift(['location_name' => $name]))['place']);
        }
    }

    public function test_municipality_description_and_postcode_override_population_ranking(): void
    {
        foreach ([
            [['location_name' => 'Sulzdorf', 'description' => 'Einsatz in Schwäbisch Hall'], 2824771],
            [['location_name' => 'Sulzdorf', 'description' => 'Nicht Giebelstadt sondern Schwäbisch Hall'], 2824771],
            [['location_name' => 'Sulzdorf', 'city' => 'Schwäbisch Hall'], 2824771],
            [['location_name' => 'Sulzdorf', 'postal_code' => '74523'], 2824771],
            [['location_name' => 'Kirchheim', 'postal_code' => '73230'], 2890473],
            [['location_name' => 'Rheinhausen', 'postal_code' => '79365'], 10375764],
            [['location_name' => 'Rheinhausen', 'postal_code' => '47226'], 2847631],
        ] as [$fields, $id]) {
            self::assertSame($id, Preview::fromOrder(new Order($fields))['geonames_id'], json_encode($fields));
        }
        $negated = Preview::fromOrder(new Order(['location_name' => 'Sulzdorf', 'description' => 'Einsatz nicht in Giebelstadt']));
        self::assertNotSame(2824770, $negated['geonames_id'] ?? null);
        foreach (['ohne Personal', 'kein Nachtdienst'] as $description) {
            self::assertSame(2824770, Preview::fromOrder(new Order(['location_name' => 'Sulzdorf', 'description' => $description]))['geonames_id']);
        }
    }

    public function test_explicit_combinations_have_honest_points_without_a_centroid(): void
    {
        foreach (['Eidelstedt/Maschen', 'Plagwitz/Zeitz Gbf.', 'München / Hamburg', 'Frankfurt (Main) / Frankfurt (Oder)'] as $name) {
            $shift = new Shift(['location_name' => $name, 'title' => $name.' · RB']);
            $regional = Preview::forRegionalAssessment($shift);
            $preview = Preview::fromShift($shift);
            self::assertSame('multiple', $preview['state'], $name);
            self::assertCount(2, $preview['points']);
            self::assertSame(0, $preview['unresolved_count']);
            self::assertArrayNotHasKey('latitude', $preview);
            self::assertArrayNotHasKey('longitude', $preview);
            self::assertSame($regional, Preview::forRegionalAssessment($shift));
        }
        self::assertTrue(Preview::fromShift(new Shift(['location_name' => 'Plagwitz/Zeitz Gbf.']))['estimated']);
        $partial = Preview::fromInquiry((new OperationInquiry)->forceFill(['location_name' => 'Eidelstedt / Unbekannter Einsatzort']));
        self::assertCount(1, $partial['points']);
        self::assertSame(1, $partial['unresolved_count']);
        self::assertSame('Mehrere genannte Orte · teilweise verortet', $partial['label']);
        self::assertStringNotContainsString('Unbekannter', $partial['place']);
        self::assertSame('located', Preview::fromShift(new Shift(['location_name' => 'Hamburg / Hamburg']))['state']);
        self::assertCount(4, Preview::fromShift(new Shift(['location_name' => 'Berlin; Hamburg; München und Köln']))['points']);
        self::assertSame('unknown', Preview::fromShift(new Shift(['location_name' => 'Berlin; Hamburg; München; Köln; Leipzig']))['state']);
    }

    public function test_complete_source_names_with_slashes_are_single_points(): void
    {
        foreach (['Frankfurt/Main', 'Halle/Saale', 'Heidelberg-Kirchheim/Rohrbach'] as $name) {
            $preview = Preview::fromShift(new Shift(['location_name' => $name]));
            self::assertSame('located', $preview['state'], $name);
            self::assertArrayNotHasKey('points', $preview);
        }
    }

    public function test_structured_contradictions_foreign_and_malformed_fields_remain_closed(): void
    {
        foreach ([['location_name' => 'Sulzdorf', 'city' => 'Wien'], ['location_name' => 'Kirchheim', 'postal_code' => '10115'], ['location_name' => 'Rheinhausen', 'postal_code' => 'AT-1010'], ['location_name' => 'Sulzdorf', 'country' => ['DE']]] as $fields) {
            self::assertSame('unknown', Preview::fromOrder(new Order($fields))['state']);
        }
        self::assertSame('outside', Preview::fromOrder(new Order(['location_name' => 'Eidelstedt/Maschen', 'country' => 'AT']))['state']);
        foreach (['Einsatz in Wien', 'Einsatz in Zürich', 'Schwäbisch Hall und Giebelstadt'] as $description) {
            self::assertSame('unknown', Preview::fromOrder(new Order(['location_name' => 'Sulzdorf', 'description' => $description]))['state']);
        }
        $snapshot = ['confirmed' => true, 'name' => 'Eigener Standort', 'latitude' => 53.5, 'longitude' => 10.0];
        $shift = new Shift(['location_name' => 'Eidelstedt/Maschen', 'location_snapshot' => $snapshot]);
        self::assertTrue(Preview::fromShift($shift)['confirmed']);
        self::assertArrayNotHasKey('points', Preview::fromShift($shift));
        $shift->location_snapshot = array_replace($snapshot, ['latitude' => null]);
        self::assertSame('unknown', Preview::fromShift($shift)['state']);
    }

    public function test_override_ignores_later_order_snapshot_and_unrelated_description(): void
    {
        $shift = new Shift(['location_name' => 'Sulzdorf']);
        $shift->setRelation('order', new Order(['location_name' => 'Hamburg', 'description' => 'Einsatz in Schwäbisch Hall', 'city' => 'Hamburg', 'location_snapshot' => ['confirmed' => true, 'name' => 'Depot', 'latitude' => 53.5, 'longitude' => 10.0]]));
        self::assertSame(2824770, Preview::fromShift($shift)['geonames_id']);
        self::assertArrayNotHasKey('confirmed', Preview::fromShift($shift));
    }

    public function test_shards_match_public_manifest_hashes_and_remain_bounded(): void
    {
        $directory = dirname(__DIR__, 2).'/resources/data/maps/germany-location-candidates';
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ab09c027d3b5d1e97edfb30181842d5801fb31f7ee90bcc00e3c2120897e264d', $manifest['source_sha256']);
        self::assertCount(64, $manifest['shards']);
        self::assertGreaterThan(80000, $manifest['point_count']);
        foreach ($manifest['shards'] as $name => $file) {
            self::assertSame($file['sha256'], hash_file('sha256', $directory.'/'.$name));
            self::assertLessThan(400000, $file['bytes']);
        }
    }
}
