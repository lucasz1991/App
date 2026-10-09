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

    public function test_unreviewed_ambiguous_places_remain_unlocated(): void
    {
        foreach (['Neustadt', 'Sulzdorf', 'Sulzdorf Bahnhof'] as $name) {
            self::assertSame(['state' => 'ambiguous', 'label' => 'Ort nicht eindeutig'], $this->preview($name));
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
            ['München Milbertshofen', 'München'],
            ['Muenchen-Milbertshofen', 'München'],
            ['München Freimann', 'München'],
            ['München Laim', 'München'],
            ['München Nord', 'München'],
            ['München-Milbertshofen Bahnhof', 'München'],
            ['München Hbf.', 'München'],
            ['München, Nord', 'München'],
            ['Nürnberghafen', 'Nürnberg'],
            ['Nürnberg Hafen (Rail One)', 'Nürnberg'],
            ['Nuernberg Hafen', 'Nürnberg'],
            ['Nuremberg', 'Nürnberg'],
            ['Berlin', 'Berlin'],
            ['Berlin Hbf', 'Berlin'],
            ['Neumünster', 'Neumünster'],
            ['Neumuenster Bahnhof', 'Neumünster'],
            ['Schwandorf', 'Schwandorf'],
            ['Schwandorf in Bayern', 'Schwandorf'],
            ['Hamburg-Eidelstedt', 'Hamburg'],
            ['Hamburg Hasselbrook', 'Hamburg'],
            ['Leipzig-Plagwitz', 'Leipzig'],
            ['Köln-Eifeltor', 'Köln'],
            ['Bahnhof München', 'München'],
        ];
    }

    public function test_known_locality_with_station_suffix_or_reviewed_spelling_is_still_only_a_locality_estimate(): void
    {
        $preview = $this->preview('Treuchtlingen');

        self::assertSame('Ortslage · ungefähr', $preview['label']);
        foreach (['Treuchtlingen Bahnhof', 'Treuchtlingen Rbf.', 'Treuchlingen', 'Treuchlingen Bahnhof'] as $location) {
            self::assertSame($preview, $this->preview($location));
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
            'conflicting cities' => ['München / Hamburg'],
            'conflicting city suffix' => ['München Hamburg'],
            'two cities and station' => ['München Hamburg Bahnhof'],
            'foreign city prefix' => ['Wien München'],
            'foreign country suffix' => ['München Österreich'],
            'qualifier foreign suffix' => ['München Hbf Österreich'],
            'country code suffix' => ['Hamburg AT'],
            'arbitrary railway prefix' => ['Bahnstrecke München'],
            'other full name sharing prefix' => ['Berlinchen'],
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
        foreach (['München-Pasing', 'München Pasing', 'München-Pasing Bahnhof', 'Bahnhof München-Pasing', 'Muenchen-Pasing', 'München – Pasing', 'München Berg am Laim'] as $location) {
            self::assertSame($this->preview('München'), $this->preview($location), $location);
        }
        self::assertSame($this->preview('Hamburg'), $this->preview('Hamburg-Harburg'));
        self::assertSame($this->preview('Berlin'), $this->preview('Berlin Lichtenberg'));
        $station = $this->preview('Ingolstadt Hbf');
        self::assertSame('located', $station['state']);
        self::assertSame('Bahnhofslage · ungefähr', $station['label']);
        self::assertSame('Ingolstadt Hbf', $station['place']);
        self::assertSame($station, $this->preview('Ingolstadt Hauptbahnhof'));
        self::assertSame($station, $this->preview('Ingolstadt Hbf.'));
        foreach (['München-Pasing Wien', 'München-Pasing / Hamburg', 'München-Pasingstraße 1', 'Bahnhof Wien München', 'München Unbekannter Stadtteil'] as $location) {
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
        self::assertSame('ambiguous', $this->preview('Frankfurt')['state']);
        self::assertNotSame($this->preview('Frankfurt am Main')['x'], $this->preview('Frankfurt (Oder)')['x']);
        self::assertSame('unknown', $this->preview('Frankfurt (Main) / Frankfurt (Oder)')['state']);
        self::assertSame('located', $this->preview('Hanau Hbf')['state']);
    }

    public function test_postcode_disambiguates_only_matching_city_and_keeps_precision_label(): void
    {
        self::assertSame('ambiguous', $this->preview('Rain')['state']);
        $order = new Order(['location_name' => 'Depot', 'city' => 'Rain', 'postal_code' => '86641', 'country' => 'DE']);
        $preview = TimelineLocationPreview::fromOrder($order);
        self::assertSame('located', $preview['state']);
        self::assertSame('Rain', $preview['place']);
        self::assertSame('PLZ-Ortslage · ungefähr', $preview['label']);
        self::assertSame($preview, $this->preview('86641 Rain'));
        self::assertSame($preview, $this->preview('DE-86641 Rain'));
        self::assertSame('unknown', $this->preview('10115 Rain')['state']);
        self::assertSame('unknown', $this->preview('86641 Wien')['state']);
        self::assertSame('unknown', $this->preview('86641 Rain / Hamburg')['state']);
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
        self::assertSame('ambiguous', $this->preview('Rain', $order)['state']);
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
        self::assertSame($this->preview('München'), TimelineLocationPreview::fromInquiry($inquiry));
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
        self::assertSame(['state' => 'ambiguous', 'label' => 'Ort nicht eindeutig'], TimelineLocationPreview::fromShift($shift));
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
