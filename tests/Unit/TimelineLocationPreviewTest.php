<?php

namespace Tests\Unit;

use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Support\Operations\TimelineLocationPreview;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TimelineLocationPreviewTest extends TestCase
{
    public function test_shift_service_name_refines_hamburg_to_hasselbrook_without_queries_or_mutations(): void
    {
        $shift = new class extends Shift
        {
            public function order(): BelongsTo
            {
                throw new RuntimeException('Detail recognition must not query relationships.');
            }
        };
        $shift->forceFill(['order_id' => 42, 'location_name' => 'Hamburg', 'title' => 'Hasselbrook Nachtdienst']);
        $attributes = $shift->getAttributes();
        $preview = TimelineLocationPreview::fromShift($shift);
        self::assertSame('Hamburg-Hasselbrook', $preview['place']);
        self::assertSame('Bahnhofslage · ungefähr', $preview['label']);
        self::assertSame(53.5649, $preview['latitude']);
        self::assertSame(10.05697, $preview['longitude']);
        self::assertTrue($preview['detail']);
        self::assertSame('service_text', $preview['source']);
        self::assertArrayNotHasKey('confirmed', $preview);
        self::assertFalse($shift->relationLoaded('order'));
        self::assertSame($attributes, $shift->getAttributes());
        self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::forRegionalAssessment($shift));
    }

    public function test_aligned_order_title_and_public_description_supply_display_details(): void
    {
        foreach ([['title' => 'Hasselbrook Nachtdienst'], ['description' => '<p>Leistung:</p><p>Nachtwache am Bahnhof Hasselbrook.</p>']] as $fields) {
            $order = new Order($fields + ['location_name' => 'Hamburg', 'city' => 'Hamburg', 'postal_code' => '20095', 'country' => 'DE']);
            $shift = new Shift(['location_name' => 'Hamburg', 'title' => 'Nachtdienst']);
            $shift->setRelation('order', $order);
            self::assertSame('Hamburg-Hasselbrook', TimelineLocationPreview::fromShift($shift)['place']);
            self::assertSame('Hamburg-Hasselbrook', TimelineLocationPreview::fromOrder($order)['place']);
            self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::forRegionalAssessment($shift));
        }
    }

    #[DataProvider('explicitDetailLocations')]
    public function test_source_backed_explicit_detail_outranks_city_centre(string $location, string $city, string $place, string $label): void
    {
        $preview = $this->preview($location);
        self::assertSame($place, $preview['place']);
        self::assertSame($label, $preview['label']);
        self::assertTrue($preview['detail']);
        self::assertSame('location', $preview['source']);
        self::assertNotSame($this->preview($city)['latitude'], $preview['latitude']);
        $order = new Order(['city' => $city, 'location_name' => $location, 'title' => 'Nachtdienst', 'country' => 'DE']);
        self::assertSame($preview, TimelineLocationPreview::fromOrder($order));
    }

    public static function explicitDetailLocations(): array
    {
        return [
            ['Hamburg Hasselbrook', 'Hamburg', 'Hamburg-Hasselbrook', 'Bahnhofslage · ungefähr'],
            ['Hamburg-Hasselbrook', 'Hamburg', 'Hamburg-Hasselbrook', 'Bahnhofslage · ungefähr'],
            ['München Milbertshofen', 'München', 'München-Milbertshofen', 'Stadtteillage · ungefähr'],
            ['Muenchen-Milbertshofen', 'München', 'München-Milbertshofen', 'Stadtteillage · ungefähr'],
            ['München-Milbertshofen Bahnhof', 'München', 'München-Milbertshofen', 'Stadtteillage · ungefähr'],
            ['Köln-Eifeltor', 'Köln', 'Köln-Eifeltor', 'Bahnhofslage · ungefähr'],
        ];
    }

    public function test_explicit_detail_is_not_replaced_by_descriptive_mentions(): void
    {
        $order = new Order(['location_name' => 'Hasselbrook', 'city' => 'Hamburg', 'description' => 'Material aus Eidelstedt']);
        self::assertSame('Hamburg-Hasselbrook', TimelineLocationPreview::fromOrder($order)['place']);
        self::assertSame('location', TimelineLocationPreview::fromOrder($order)['source']);
    }

    #[DataProvider('conflictingDetailTexts')]
    public function test_conflicting_negated_or_unrecognized_details_preserve_city_fallback(string $title, ?string $description = null): void
    {
        $order = new Order(['location_name' => 'Hamburg', 'city' => 'Hamburg', 'description' => $description]);
        $shift = new Shift(['location_name' => 'Hamburg', 'title' => $title]);
        $shift->setRelation('order', $order);
        self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::fromShift($shift));
    }

    public static function conflictingDetailTexts(): array
    {
        return [
            ['Hasselbrook / Eidelstedt Nachtdienst'],
            ['Hasselbrook Nachtdienst', 'Ablösung in Köln-Eifeltor'],
            ['Hasselbrook Nachtdienst', 'Einsatz in Essen'],
            ['Hasselbrook Wien'],
            ['Hasselbrook Nachtdienst', 'Einsatz in Wien.'],
            ['Hasselbrook Nachtdienst', 'Ablösung in Zürich'],
            ['Nicht Hasselbrook, Nachtdienst'],
            ['Hasselbrook oder Eidelstedt'],
            ['Von Hasselbrook nach Eidelstedt'],
            ['Nachtdienst', 'Kein Einsatz in Hasselbrook'],
            ['Hasselbrookstraße 12 Nachtdienst'],
            ['Hasselbrookhausen Nachtdienst'],
            ['Nachtdienst Atlantis'],
            ['Hasselbrook Nachtdienst', str_repeat('x', 5001)],
            ['Hasselbrook Nachtdienst', "\xFF"],
        ];
    }

    public function test_override_never_uses_unrelated_order_descriptions_or_another_citys_details(): void
    {
        $shift = new Shift(['location_name' => 'Berlin', 'title' => 'Nachtdienst']);
        $shift->setRelation('order', new Order(['location_name' => 'Hamburg', 'city' => 'Hamburg', 'title' => 'Hasselbrook Nachtdienst', 'description' => 'Hasselbrook']));
        self::assertSame($this->preview('Berlin'), TimelineLocationPreview::fromShift($shift));
        $shift->location_name = 'München';
        $shift->title = 'Hasselbrook Nachtdienst';
        self::assertSame($this->preview('München'), TimelineLocationPreview::fromShift($shift));
    }

    public function test_details_cannot_repair_foreign_malformed_conflicting_or_ambiguous_structured_locations(): void
    {
        foreach ([
            ['city' => 'Hamburg', 'country' => 'AT'],
            ['city' => 'Hamburg', 'postal_code' => '10115'],
            ['city' => 'Hamburg', 'postal_code' => 'invalid'],
            ['city' => 'Wien'],
            ['city' => 'Hamburg / München'],
            ['city' => 'Neustadt'],
            ['city' => str_repeat('x', 201)],
            ['country' => str_repeat('x', 201)],
            ['country' => ['DE']],
        ] as $fields) {
            $base = TimelineLocationPreview::fromOrder(new Order($fields));
            $order = new Order($fields + ['title' => 'Hamburg Hasselbrook Nachtdienst', 'description' => 'Hasselbrook']);
            self::assertSame($base, TimelineLocationPreview::fromOrder($order));
        }
    }

    public function test_missing_worksite_requires_one_fully_qualified_detail_name(): void
    {
        $shift = new Shift(['title' => 'Hamburg-Hasselbrook Nachtdienst']);
        self::assertSame('Hamburg-Hasselbrook', TimelineLocationPreview::fromShift($shift)['place']);
        self::assertSame('unknown', TimelineLocationPreview::forRegionalAssessment($shift)['state']);
        $shift->title = 'Hasselbrook Nachtdienst';
        self::assertSame('unknown', TimelineLocationPreview::fromShift($shift)['state']);
        $shift->title = 'Hamburg-Hasselbrook und Köln-Eifeltor';
        self::assertSame('unknown', TimelineLocationPreview::fromShift($shift)['state']);
        $shift->title = 'Hamburg-Hasselbrook Nachtdienst';
        $shift->location_name = 'Unbekannter Einsatzort';
        self::assertSame('unknown', TimelineLocationPreview::fromShift($shift)['state']);
    }

    public function test_confirmed_and_malformed_snapshots_win_over_all_detail_text(): void
    {
        $shift = new Shift(['location_name' => 'Hamburg', 'title' => 'Hasselbrook Nachtdienst', 'location_snapshot' => $this->snapshot()]);
        self::assertSame('Depot München', TimelineLocationPreview::fromShift($shift)['place']);
        $shift->location_snapshot = $this->snapshot(['latitude' => null]);
        self::assertSame('unknown', TimelineLocationPreview::fromShift($shift)['state']);
    }

    public function test_private_notes_and_customer_text_are_never_detail_sources(): void
    {
        $shift = new Shift(['location_name' => 'Hamburg', 'title' => 'Nachtdienst', 'notes' => 'Hasselbrook']);
        $order = new Order(['location_name' => 'Hamburg', 'city' => 'Hamburg', 'notes' => 'Hasselbrook']);
        $order->setRelation('customer', new Order(['title' => 'Hasselbrook', 'description' => 'Hasselbrook']));
        $shift->setRelation('order', $order);
        self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::fromShift($shift));
    }

    public function test_confirmed_shift_uses_its_own_snapshot_without_querying_or_borrowing_order(): void
    {
        $shift = new class extends Shift
        {
            public function order(): BelongsTo
            {
                throw new RuntimeException('Confirmed previews must not query the order.');
            }
        };
        $shift->forceFill(['location_name' => 'Hamburg', 'order_id' => 42, 'location_snapshot' => $this->snapshot()]);
        $preview = TimelineLocationPreview::fromShift($shift);
        self::assertSame('located', $preview['state']);
        self::assertSame('Depot München', $preview['place']);
        self::assertSame('Bestätigter Einsatzstandort', $preview['label']);
        self::assertTrue($preview['confirmed']);
        self::assertSame(48.13512, $preview['latitude']);
        self::assertSame(11.58198, $preview['longitude']);
        self::assertFalse($shift->relationLoaded('order'));

        $shift->setRelation('order', new Order(['location_snapshot' => $this->snapshot(['name' => 'Depot Berlin', 'latitude' => 52.52001, 'longitude' => 13.40495])]));
        self::assertSame($preview, TimelineLocationPreview::fromShift($shift));
    }

    public function test_order_preview_uses_its_own_confirmed_snapshot(): void
    {
        $order = new Order(['location_name' => 'Hamburg', 'city' => 'Hamburg', 'country' => 'DE', 'location_snapshot' => $this->snapshot()]);
        $preview = TimelineLocationPreview::fromOrder($order);
        self::assertSame('Depot München', $preview['place']);
        self::assertSame(48.13512, $preview['latitude']);
        self::assertTrue($preview['confirmed']);
    }

    public function test_legacy_or_unconfirmed_shift_never_adopts_a_later_order_snapshot(): void
    {
        foreach ([null, $this->snapshot(['confirmed' => false])] as $snapshot) {
            $shift = new Shift(['location_name' => 'Hamburg', 'location_snapshot' => $snapshot]);
            $shift->setRelation('order', new Order(['location_name' => 'Hamburg', 'city' => 'Hamburg', 'country' => 'DE', 'location_snapshot' => $this->snapshot()]));
            self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::fromShift($shift));
        }
    }

    #[DataProvider('invalidConfirmedSnapshots')]
    public function test_invalid_confirmed_snapshot_does_not_guess_a_city_marker(array $overrides): void
    {
        $snapshot = $this->snapshot($overrides);
        foreach ([new Shift(['location_name' => 'Hamburg', 'location_snapshot' => $snapshot]), new Order(['city' => 'Hamburg', 'location_snapshot' => $snapshot])] as $record) {
            $preview = $record instanceof Shift ? TimelineLocationPreview::fromShift($record) : TimelineLocationPreview::fromOrder($record);
            self::assertSame(['state' => 'unknown', 'label' => 'Standort nicht verortet'], $preview);
        }
    }

    public static function invalidConfirmedSnapshots(): array
    {
        return [
            'missing latitude' => [['latitude' => null]],
            'invalid number' => [['longitude' => 'NaN']],
            'non-finite number' => [['latitude' => '1e999']],
            'invalid latitude range' => [['latitude' => 91]],
            'invalid longitude range' => [['longitude' => -181]],
            'empty name' => [['name' => ' ']],
            'invalid name type' => [['name' => ['Depot']]],
            'oversized name' => [['name' => str_repeat('a', 201)]],
        ];
    }

    public function test_confirmed_point_outside_svg_remains_explicit_and_does_not_clamp_or_guess(): void
    {
        $shift = new Shift(['location_name' => 'Berlin', 'location_snapshot' => $this->snapshot(['name' => 'Depot Madrid', 'latitude' => 40.4168, 'longitude' => -3.7038])]);
        self::assertSame([
            'state' => 'outside', 'label' => 'Bestätigter Einsatzstandort außerhalb der Deutschlandkarte', 'confirmed' => true, 'place' => 'Depot Madrid',
        ], TimelineLocationPreview::fromShift($shift));
    }

    public function test_snapshot_display_does_not_change_existing_regional_assessment(): void
    {
        $shift = new Shift(['location_name' => 'Hamburg', 'location_snapshot' => $this->snapshot()]);
        self::assertSame('Depot München', TimelineLocationPreview::fromShift($shift)['place']);
        self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::forRegionalAssessment($shift));
        $shift->setRelation('order', new Order(['city' => 'Hamburg', 'location_name' => 'Hamburg', 'country' => 'DE', 'location_snapshot' => $this->snapshot()]));
        self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::forRegionalAssessment($shift));
    }

    private function snapshot(array $overrides = []): array
    {
        return array_replace(['confirmed' => true, 'name' => 'Depot München', 'latitude' => 48.13512, 'longitude' => 11.58198, 'revision' => 1], $overrides);
    }

    public function test_exact_locality_resolves_with_canonical_name_and_approximate_label(): void
    {
        $preview = $this->preview('  TREUCHTLINGEN  ');

        self::assertSame('located', $preview['state']);
        self::assertSame('Treuchtlingen', $preview['place']);
        self::assertSame('Ortslage · ungefähr', $preview['label']);
        self::assertIsFloat($preview['x']);
        self::assertIsFloat($preview['y']);
        self::assertIsFloat($preview['latitude']);
        self::assertIsFloat($preview['longitude']);
        self::assertGreaterThan(48, $preview['latitude']);
        self::assertLessThan(50, $preview['latitude']);
        self::assertGreaterThan(10, $preview['longitude']);
        self::assertLessThan(12, $preview['longitude']);
    }

    public function test_reviewed_german_city_aliases_resolve_to_the_same_city_centre(): void
    {
        self::assertSame($this->preview('Köln'), $this->preview('Koeln'));
        self::assertSame('Köln', $this->preview('KÖLN')['place']);
        self::assertSame($this->preview('Köln'), $this->preview('Koln'));
        self::assertSame($this->preview('Köln'), $this->preview('Cologne'));
        self::assertSame('München', $this->preview('Munich')['place']);

        foreach (['München', 'Munchen', 'Muenchen'] as $name) {
            self::assertSame($this->preview('Munich'), $this->preview($name));
        }
        self::assertSame('Stadtmitte · ungefähr', $this->preview('München')['label']);
    }

    public function test_ambiguous_places_have_explicit_display_proposals_only(): void
    {
        foreach (['Neustadt', 'Sulzdorf', 'Sulzdorf Bahnhof'] as $name) {
            self::assertTrue($this->preview($name)['estimated']);
            self::assertSame('Ortsvorschlag · ungefähr', $this->preview($name)['label']);
            self::assertSame('ambiguous', TimelineLocationPreview::forRegionalAssessment(new Shift(['location_name' => $name]))['state']);
        }
    }

    #[DataProvider('cityCentreLocations')]
    public function test_reviewed_cities_and_bounded_location_qualifiers_use_approximate_city_centre(string $location, string $city): void
    {
        $preview = $this->preview($location);

        self::assertSame($this->preview($city), $preview);
        self::assertSame('located', $preview['state']);
        self::assertSame($city, $preview['place']);
        self::assertSame('Stadtmitte · ungefähr', $preview['label']);
    }

    public static function cityCentreLocations(): array
    {
        return [
            ['München Nord', 'München'],
            ['München Hbf.', 'München'],
            ['München, Nord', 'München'],
            ['Nürnberghafen', 'Nürnberg'],
            ['Nürnberg Hafen (Rail One)', 'Nürnberg'],
            ['Nuernberg Hafen', 'Nürnberg'],
            ['Nuremberg', 'Nürnberg'],
            ['Berlin', 'Berlin'],
            ['Berlin Hbf', 'Berlin'],
            ['Neumünster', 'Neumünster'],
            ['Schwandorf', 'Schwandorf'],
            ['Schwandorf in Bayern', 'Schwandorf'],
            ['Bahnhof München', 'München'],
        ];
    }

    public function test_known_locality_with_station_suffix_or_reviewed_spelling_is_still_only_a_locality_estimate(): void
    {
        $preview = $this->preview('Treuchtlingen');

        self::assertSame('Ortslage · ungefähr', $preview['label']);
        foreach (['Treuchlingen', 'Treuchlingen Bahnhof'] as $location) {
            self::assertSame($preview, $this->preview($location));
        }
        foreach (['Treuchtlingen Bahnhof', 'Treuchtlingen Rbf.'] as $location) {
            self::assertSame(11972780, $this->preview($location)['geonames_id']);
            self::assertSame('Bahnhofslage · ungefähr', $this->preview($location)['label']);
        }
    }

    #[DataProvider('unknownLocations')]
    public function test_unknown_location_has_no_marker_or_reflected_input(?string $location): void
    {
        self::assertSame(['state' => 'unknown', 'label' => 'Standort nicht verortet'], $this->preview($location));
    }

    public static function unknownLocations(): array
    {
        return [
            'missing' => [null],
            'empty' => ['   '],
            'unreviewed typo' => ['Treutlingen'],
            'arbitrary suffix' => ['Treuchtlingen Irgendwo'],
            'address' => ['Treuchtlingen, Bahnhofstraße 1'],
            'conflicting city suffix' => ['München Hamburg'],
            'two cities and station' => ['München Hamburg Bahnhof'],
            'foreign city prefix' => ['Wien München'],
            'foreign country suffix' => ['München Österreich'],
            'qualifier foreign suffix' => ['München Hbf Österreich'],
            'country code suffix' => ['Hamburg AT'],
            'arbitrary railway prefix' => ['Bahnstrecke München'],
            'unreviewed district' => ['München Unbekannter Stadtteil'],
            'unknown place with station qualifier' => ['Atlantis Bahnhof'],
            'coordinates' => ['48.95473,10.90833'],
            'script' => ['<script>alert("Hamburg")</script>'],
            'svg' => ['<svg onload="alert(1)">Hamburg</svg>'],
            'remote address' => ['https://example.org/Hamburg'],
            'oversize' => [str_repeat('a', 201)],
        ];
    }

    public function test_matching_order_location_uses_its_structured_city(): void
    {
        $order = ['location_name' => 'Depot Nord', 'city' => 'Köln', 'country' => 'DE'];

        self::assertSame($this->preview('Köln'), $this->preview('  depot   NORD ', $order));
        self::assertSame($this->preview('Köln'), $this->preview(null, $order));
    }

    public function test_missing_structured_city_falls_back_to_exact_order_location(): void
    {
        foreach (['DE', 'deu', 'Deutschland', 'Germany', null] as $country) {
            self::assertSame($this->preview('Treuchtlingen'), $this->preview(null, [
                'location_name' => 'Treuchtlingen',
                'city' => '',
                'country' => $country,
            ]));
        }
    }

    public function test_source_backed_city_districts_and_railway_names_resolve_without_fuzzy_matching(): void
    {
        foreach (['München-Pasing', 'München Pasing', 'Muenchen-Pasing', 'München – Pasing'] as $location) {
            self::assertSame(2855334, $this->preview($location)['geonames_id'], $location);
            self::assertSame('Stadtteillage · ungefähr', $this->preview($location)['label']);
        }
        self::assertSame(2867691, $this->preview('München-Pasing Bahnhof')['geonames_id']);
        self::assertSame('München-Pasing', $this->preview('Bahnhof München-Pasing')['place']);
        self::assertSame('Bahnhofslage · ungefähr', $this->preview('Bahnhof München-Pasing')['label']);
        self::assertSame('München-Berg am Laim', $this->preview('München Berg am Laim')['place']);
        self::assertSame(2911289, $this->preview('Hamburg-Harburg')['geonames_id']);
        self::assertSame(11973036, $this->preview('Berlin Lichtenberg')['geonames_id']);
        $station = $this->preview('Ingolstadt Hbf');
        self::assertSame('located', $station['state']);
        self::assertSame('Bahnhofslage · ungefähr', $station['label']);
        self::assertSame('Ingolstadt Hbf', $station['place']);
        self::assertSame($station, $this->preview('Ingolstadt Hauptbahnhof'));
        self::assertSame($station, $this->preview('Ingolstadt Hbf.'));
        foreach (['München-Pasing Wien', 'München-Pasingstraße 1', 'Bahnhof Wien München', 'München Unbekannter Stadtteil'] as $location) {
            self::assertSame('unknown', $this->preview($location)['state'], $location);
        }
    }

    public function test_parenthetical_rail_city_qualifiers_preserve_main_oder_disambiguation(): void
    {
        foreach (['Frankfurt (Main) Hbf', 'Frankfurt(Main)Hbf.', 'Frankfurt (Main) Hauptbahnhof'] as $location) {
            self::assertSame($this->preview('Frankfurt am Main'), $this->preview($location));
        }
        foreach (['Frankfurt(Oder)', 'Frankfurt (Oder) Hbf', 'Frankfurt(Oder)Hbf'] as $location) {
            self::assertSame($this->preview('Frankfurt (Oder)'), $this->preview($location));
        }
        self::assertTrue($this->preview('Frankfurt')['estimated']);
        self::assertNotSame($this->preview('Frankfurt am Main')['x'], $this->preview('Frankfurt (Oder)')['x']);
        self::assertCount(2, $this->preview('Frankfurt (Main) / Frankfurt (Oder)')['points']);
        self::assertSame('located', $this->preview('Hanau Hbf')['state']);
    }

    public function test_districts_in_the_same_rural_county_do_not_borrow_another_municipality_city(): void
    {
        // Public GeoNames municipality codes differ despite the shared county:
        // Lörrach 08336050 / Wyhlen 08336105; Pirna 14628270 / Zinnwald 14628010.
        foreach (['Lörrach Wyhlen', 'Lörrach-Wyhlen', 'Pirna Zinnwald', 'Schwandorf Zeitlarn'] as $location) {
            self::assertSame('unknown', $this->preview($location)['state'], $location);
        }
    }

    public function test_postcode_disambiguates_only_matching_city_and_keeps_precision_label(): void
    {
        self::assertTrue($this->preview('Rain')['estimated']);
        $order = new Order(['location_name' => 'Depot', 'city' => 'Rain', 'postal_code' => '86641', 'country' => 'DE']);
        $preview = TimelineLocationPreview::fromOrder($order);
        self::assertSame('located', $preview['state']);
        self::assertSame('Rain', $preview['place']);
        self::assertSame('PLZ-Ortslage · ungefähr', $preview['label']);
        self::assertSame($preview, $this->preview('86641 Rain'));
        self::assertSame($preview, $this->preview('DE-86641 Rain'));
        self::assertSame('unknown', $this->preview('10115 Rain')['state']);
        self::assertSame('unknown', $this->preview('86641 Wien')['state']);
        self::assertCount(2, $this->preview('86641 Rain / Hamburg')['points']);
        self::assertSame('outside', TimelineLocationPreview::fromOrder(new Order(['city' => 'Rain', 'postal_code' => '86641', 'country' => 'AT']))['state']);
    }

    public function test_postcode_does_not_overwrite_conflicting_explicit_city_or_location(): void
    {
        foreach ([['city' => 'Hamburg'], ['location_name' => 'Hamburg'], ['location_name' => 'Bahnhof Hamburg'], ['city' => 'Wien'], ['city' => 'München / Hamburg']] as $fields) {
            self::assertSame('unknown', TimelineLocationPreview::fromOrder(new Order($fields + ['postal_code' => '10115', 'country' => 'DE']))['state']);
        }
        self::assertSame($this->preview('Hamburg'), TimelineLocationPreview::fromOrder(new Order(['city' => 'Hamburg', 'postal_code' => '20095', 'country' => 'DE'])));
        self::assertSame('unknown', TimelineLocationPreview::fromOrder(new Order(['city' => 'Hamburg', 'postal_code' => 'AT-1010']))['state']);
        self::assertSame('ambiguous', TimelineLocationPreview::fromOrder(new Order(['postal_code' => '01067', 'country' => 'DE']))['state']);
        self::assertSame('located', TimelineLocationPreview::fromOrder(new Order(['location_name' => 'Depot', 'postal_code' => '80331', 'country' => 'DE']))['state']);
        self::assertSame('unknown', TimelineLocationPreview::fromOrder(new Order(['postal_code' => str_repeat('1', 201), 'city' => 'München']))['state']);
    }

    public function test_postcode_and_map_aliases_never_borrow_an_unrelated_order_worksite(): void
    {
        $order = ['location_name' => 'Depot', 'city' => 'München', 'postal_code' => '80331', 'country' => 'DE'];
        self::assertSame($this->preview('München'), $this->preview(null, $order));
        self::assertSame($this->preview('Hamburg'), $this->preview('Hamburg', $order));
        self::assertSame('unknown', $this->preview('Unbekannter Einsatzort', $order)['state']);
        self::assertSame($this->preview('Rain'), $this->preview('Rain', $order));
    }

    public function test_inquiry_uses_only_explicit_worksite_and_does_not_query_relationships(): void
    {
        $inquiry = new class extends OperationInquiry
        {
            public function order(): BelongsTo
            {
                throw new RuntimeException('Inquiry preview must not query order.');
            }
        };
        $inquiry->forceFill(['location_name' => 'München-Pasing', 'contact_name' => 'Hamburg', 'original' => 'Hamburg', 'order_id' => 42]);
        self::assertSame($this->preview('München-Pasing'), TimelineLocationPreview::fromInquiry($inquiry));
        self::assertFalse($inquiry->relationLoaded('order'));
        $inquiry->location_name = null;
        $inquiry->setRelation('order', new Order(['location_name' => 'Hamburg']));
        self::assertSame('unknown', TimelineLocationPreview::fromInquiry($inquiry)['state']);
    }

    public function test_map_fallbacks_leave_original_regional_location_policy_unchanged(): void
    {
        foreach (['München-Pasing', 'Hamburg-Harburg', 'Bahnhof München', '86641 Rain', 'Ingolstadt Hbf'] as $name) {
            $shift = new Shift(['location_name' => $name]);
            self::assertSame('located', TimelineLocationPreview::fromShift($shift)['state'], $name);
            self::assertContains(TimelineLocationPreview::forRegionalAssessment($shift)['state'], ['unknown', 'ambiguous'], $name);
        }
        $shift = new Shift;
        $shift->setRelation('order', new Order(['city' => 'Rain', 'postal_code' => '86641', 'country' => 'DE']));
        self::assertSame('located', TimelineLocationPreview::fromShift($shift)['state']);
        self::assertSame('ambiguous', TimelineLocationPreview::forRegionalAssessment($shift)['state']);
        self::assertSame($this->preview('München'), TimelineLocationPreview::forRegionalAssessment(new Shift(['location_name' => 'München Milbertshofen'])));
    }

    public function test_shift_override_does_not_borrow_order_city_or_foreign_country(): void
    {
        $order = ['location_name' => 'Depot West', 'city' => 'Treuchtlingen', 'country' => 'AT'];

        self::assertSame($this->preview('Hamburg'), $this->preview('Hamburg', $order));
        self::assertSame('unknown', $this->preview('Unbekannter Einsatzort', $order)['state']);
    }

    public function test_order_without_location_name_does_not_override_a_named_shift(): void
    {
        self::assertSame($this->preview('Hamburg'), $this->preview('Hamburg', [
            'location_name' => null,
            'city' => 'Treuchtlingen',
            'country' => 'AT',
        ]));
    }

    public function test_invalid_supplied_fields_are_not_treated_as_missing_fallback_fields(): void
    {
        $order = ['location_name' => 'Treuchtlingen', 'city' => 'Treuchtlingen', 'country' => 'DE'];
        self::assertSame('unknown', $this->preview(str_repeat('a', 201), $order)['state']);
        self::assertSame('unknown', $this->preview("\xFF", $order)['state']);
        self::assertSame('unknown', $this->preview(null, array_replace($order, ['city' => str_repeat('a', 201)]))['state']);
        self::assertSame('unknown', $this->preview(null, array_replace($order, ['country' => str_repeat('a', 201)]))['state']);
    }

    public function test_foreign_country_blocks_a_german_homonym_before_matching(): void
    {
        foreach (['AT', 'Austria', 'CH', 'FR'] as $country) {
            self::assertSame(['state' => 'outside', 'label' => 'Außerhalb Deutschlands'], $this->preview('Hamburg', [
                'location_name' => 'Hamburg',
                'city' => 'Hamburg',
                'country' => $country,
            ]));
            self::assertSame('outside', $this->preview('München Milbertshofen', [
                'location_name' => 'München Milbertshofen', 'city' => 'München', 'country' => $country,
            ])['state']);
        }
    }

    public function test_unknown_or_foreign_structured_city_never_falls_back_to_german_location(): void
    {
        $order = ['location_name' => 'Treuchtlingen', 'city' => 'Wien', 'country' => null];

        self::assertSame('unknown', $this->preview(null, $order)['state']);
        self::assertSame('outside', $this->preview(null, array_replace($order, ['country' => 'AT']))['state']);
    }

    public function test_unloaded_order_is_not_queried_or_loaded(): void
    {
        $shift = new class extends Shift
        {
            public function order(): BelongsTo
            {
                throw new RuntimeException('The preview must not query relationships.');
            }
        };
        $shift->forceFill(['location_name' => 'Treuchtlingen', 'order_id' => 42]);

        self::assertSame('located', TimelineLocationPreview::fromShift($shift)['state']);
        self::assertFalse($shift->relationLoaded('order'));
        $shift->location_name = null;
        self::assertSame('unknown', TimelineLocationPreview::fromShift($shift)['state']);
    }

    public function test_customer_address_street_and_private_notes_are_not_location_sources(): void
    {
        $shift = new Shift(['notes' => 'Treuchtlingen']);
        $order = new Order(['street' => 'Hamburg', 'notes' => 'Treuchtlingen']);
        $order->setRelation('customer', new Order(['city' => 'Köln', 'location_name' => 'Köln']));
        $shift->setRelation('order', $order);

        self::assertSame(['state' => 'unknown', 'label' => 'Standort nicht verortet'], TimelineLocationPreview::fromShift($shift));
    }

    public function test_shared_data_cache_does_not_retain_previous_shift_location(): void
    {
        $shift = new Shift(['location_name' => 'Treuchtlingen']);
        self::assertSame('Treuchtlingen', TimelineLocationPreview::fromShift($shift)['place']);

        $shift->location_name = 'Hamburg';
        self::assertSame('Hamburg', TimelineLocationPreview::fromShift($shift)['place']);

        $shift->location_name = 'Neustadt';
        self::assertTrue(TimelineLocationPreview::fromShift($shift)['estimated']);
        self::assertSame('ambiguous', TimelineLocationPreview::forRegionalAssessment($shift)['state']);
    }

    public function test_shared_projection_places_treuchtlingen_northwest_of_munich(): void
    {
        $treuchtlingen = $this->preview('Treuchtlingen');
        $munich = $this->preview('Munich');
        $hamburg = $this->preview('Hamburg');

        self::assertLessThan($munich['x'], $treuchtlingen['x']);
        self::assertLessThan($munich['y'], $treuchtlingen['y']);
        self::assertLessThan($treuchtlingen['y'], $hamburg['y']);
        self::assertSame('0 0 160 176', TimelineLocationPreview::viewBox());
        self::assertMatchesRegularExpression('/^M[0-9.,L]+Z$/', TimelineLocationPreview::outlinePath());

        preg_match_all('/([0-9.]+),([0-9.]+)/', TimelineLocationPreview::outlinePath(), $points, PREG_SET_ORDER);
        self::assertGreaterThan(50, count($points));
        self::assertGreaterThanOrEqual(12, min(array_column($points, 1)));
        self::assertLessThanOrEqual(148, max(array_column($points, 1)));
        self::assertGreaterThanOrEqual(12, min(array_column($points, 2)));
        self::assertLessThanOrEqual(164, max(array_column($points, 2)));
    }

    public function test_all_shipped_places_have_finite_markers_inside_the_viewbox(): void
    {
        $path = dirname(__DIR__, 2).'/resources/data/maps/germany-places.json';
        $places = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $invalid = [];
        $count = 0;
        foreach ($places as $name => $place) {
            if ($place === null) {
                continue;
            }
            $count++;
            $preview = $this->preview($name);
            if ($preview['state'] !== 'located' || ! is_finite($preview['x']) || ! is_finite($preview['y'])
                || $preview['x'] < 0 || $preview['x'] > 160 || $preview['y'] < 0 || $preview['y'] > 176) {
                $invalid[] = $name;
            }
        }

        self::assertGreaterThan(9000, $count);
        self::assertSame([], $invalid);
        self::assertLessThan(700_000, filesize($path));
    }

    public function test_reviewed_city_centre_data_contains_unique_names_and_finite_markers(): void
    {
        $path = dirname(__DIR__, 2).'/resources/data/maps/germany-city-centres.json';
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $names = [];
        foreach ($data['cities'] as $city) {
            self::assertGreaterThan(0, $city['point'][0]);
            foreach ($city['names'] as $name) {
                self::assertNotContains($name, $names);
                $names[] = $name;
                $preview = $this->preview($name);
                self::assertSame('located', $preview['state']);
                self::assertSame('Stadtmitte · ungefähr', $preview['label']);
                self::assertGreaterThanOrEqual(0, $preview['x']);
                self::assertLessThanOrEqual(160, $preview['x']);
                self::assertGreaterThanOrEqual(0, $preview['y']);
                self::assertLessThanOrEqual(176, $preview['y']);
            }
        }
        self::assertLessThan(5000, filesize($path));
    }

    private function preview(?string $location, ?array $order = null): array
    {
        $shift = new Shift(['location_name' => $location]);
        if ($order !== null) {
            $shift->setRelation('order', new Order($order));
        }

        return TimelineLocationPreview::fromShift($shift);
    }
}
