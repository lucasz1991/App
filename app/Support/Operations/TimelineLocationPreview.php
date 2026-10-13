<?php

namespace App\Support\Operations;

use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;

final class TimelineLocationPreview
{
    /** @var array<string, array{int, string, float, float}|null>|null */
    private static ?array $places = null;

    private static ?array $outline = null;

    private static ?array $cityCentres = null;

    private static ?array $locationAliases = null;

    private static ?array $postcodes = null;

    private static ?string $path = null;

    /**
     * Own confirmed worksite snapshot, otherwise an explicitly labelled estimate.
     * The caller already loads order; preview rendering must not trigger queries.
     *
     * @return array{state: string, label: string, confirmed?: bool, place?: string, x?: float, y?: float, latitude?: float, longitude?: float}
     */
    public static function fromShift(Shift $shift): array
    {
        return self::shiftPreview($shift, true);
    }

    /** Map-only fallbacks must not change persisted regional exclusion policy. */
    public static function forRegionalAssessment(Shift $shift): array
    {
        return self::shiftPreview($shift, false);
    }

    public static function fromOrder(Order $order): array
    {
        if (($snapshot = self::confirmedSnapshot($order->getAttribute('location_snapshot'))) !== null) {
            return $snapshot;
        }

        return self::structuredDisplayLocation($order, [
            $order->getAttribute('title'), $order->getAttribute('description'),
        ]);
    }

    private static function structuredDisplayLocation(Order $order, array $texts): array
    {
        $base = self::structuredLocation(
            $order->getAttribute('location_name'),
            $order->getAttribute('city'),
            $order->getAttribute('postal_code'),
            $order->getAttribute('country'),
        );

        // Only a completely missing structured worksite may be recovered solely
        // from a full city-qualified name. Malformed/foreign/conflicting supplied
        // city and postcode values remain closed to descriptive fallbacks.
        $missing = self::normalize($order->getAttribute('location_name')) === ''
            && self::normalize($order->getAttribute('city')) === ''
            && self::normalize($order->getAttribute('postal_code')) === ''
            && in_array(self::normalize($order->getAttribute('country')), ['', 'de', 'deu', 'deutschland', 'germany'], true);

        $base = self::refineLocation($base, [$order->getAttribute('location_name')], $texts, $missing);
        $location = self::normalize($order->getAttribute('location_name'));
        $city = self::normalize($order->getAttribute('city'));
        $postal = self::normalize($order->getAttribute('postal_code'));
        $country = self::normalize($order->getAttribute('country'));
        if ($location === null || $city === null || $postal === null || $country === null
            || ! in_array($country, ['', 'de', 'deu', 'deutschland', 'germany'], true)
            || ($postal !== '' && preg_match('/^\d{5}$/D', $postal) !== 1)) {
            return $base;
        }
        $postalCities = $postal !== '' ? array_column(self::postcodes()[$postal] ?? [], 0) : [];

        return self::displayLocation($location !== '' ? $location : $city, $base, [
            'city' => $city, 'postal_cities' => $postalCities, 'postal_points' => $postal !== '' ? (self::postcodes()[$postal] ?? []) : [], 'texts' => $texts,
        ]);
    }

    public static function fromInquiry(OperationInquiry $inquiry): array
    {
        // The inquiry's explicit worksite is separate from contact/customer and
        // linked order addresses; none of those relationships are consulted.
        $location = self::normalize($inquiry->getAttribute('location_name'));

        return $location === null ? self::unlocated('unknown') : self::displayLocation($location, self::mapLocation($location));
    }

    private static function shiftPreview(Shift $shift, bool $mapFallbacks): array
    {
        if ($mapFallbacks && ($snapshot = self::confirmedSnapshot($shift->getAttribute('location_snapshot'))) !== null) {
            return $snapshot;
        }
        $location = self::normalize($shift->getAttribute('location_name'));
        if ($location === null) {
            return self::unlocated('unknown');
        }

        $order = $shift->relationLoaded('order') ? $shift->getRelation('order') : null;

        // A shift can override its order's location. Never mix the override with
        // an unrelated order city/country, and never inspect customer addresses.
        if ($order instanceof Order && ($location === '' || $location === self::normalize($order->getAttribute('location_name')))) {
            if ($mapFallbacks) {
                // Saved shifts never borrow a later order snapshot. A newly
                // confirmed order must not move a historical unconfirmed duty.
                return self::structuredDisplayLocation($order, [
                    $shift->getAttribute('title'), $order->getAttribute('title'), $order->getAttribute('description'),
                ]);
            }
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

        return $mapFallbacks && $location !== null
            ? self::displayLocation($location, self::refineLocation(self::mapLocation($location), [$location], [$shift->getAttribute('title')], $location === ''), [
                'texts' => [$shift->getAttribute('title'), $shift->getAttribute('role_name')],
            ])
            : self::exactLocation($location);
    }

    /** Display-only candidates and explicit multiple places never enter the regional pathway. */
    private static function displayLocation(string $location, array $base, array $context = [], bool $allowMultiple = true): array
    {
        if ($location === '' || ($base['confirmed'] ?? false) || ($base['detail'] ?? false) || $base['state'] === 'outside' || $base['label'] === 'Bahnhofslage · ungefähr') {
            return $base;
        }
        if ($base['state'] === 'located' && isset(self::cityCentres()['names'][$location])
            && (($context['city'] ?? '') === '' || LocationCandidateResolver::normalize($context['city']) === LocationCandidateResolver::normalize($base['place']))
            && ($context['postal_cities'] ?? []) === []) {
            return $base;
        }
        if ($base['state'] === 'located' && isset(self::places()[$location])
            && ($context['postal_cities'] ?? []) === [] && ($context['city'] ?? '') === ''
            && array_filter($context['texts'] ?? [], fn ($value) => $value !== null && $value !== '') === []) {
            return $base;
        }
        if ($base['state'] === 'located' && LocationCandidateResolver::normalize($location) === LocationCandidateResolver::normalize($base['place'])
            && ($context['postal_cities'] ?? []) === [] && ($context['city'] ?? '') === ''
            && array_filter($context['texts'] ?? [], fn ($value) => $value !== null && $value !== '') === []) {
            return $base;
        }
        // A reviewed city-qualified label is an existing geographic anchor;
        // an identically named station in another municipality cannot replace it.
        if ($base['state'] === 'located' && $base['label'] === 'Stadtmitte · ungefähr' && ($context['city'] ?? '') === '') {
            $context['city'] = $base['place'];
        }
        if ($base['state'] === 'located' && $base['label'] === 'Stadtmitte · ungefähr') {
            $context['anchor'] = [$base['latitude'], $base['longitude']];
        }
        $candidate = LocationCandidateResolver::resolve($location, $context);
        if (($candidate['blocked'] ?? false) === true) {
            return ($candidate['reason'] ?? '') === 'service_context' && $base['state'] === 'located' ? $base : self::unlocated('unknown');
        }
        if ($candidate !== null) {
            // Preserve established exact city/locality precision and labels.
            if ($base['state'] === 'located' && LocationCandidateResolver::normalize($location) === LocationCandidateResolver::normalize($base['place'])
                && ($context['postal_cities'] ?? []) === [] && ($context['city'] ?? '') === '') {
                return $base;
            }
            $point = $candidate['point'];
            if ($base['state'] === 'located' && abs($base['latitude'] - $point[2]) < 0.000001 && abs($base['longitude'] - $point[3]) < 0.000001) {
                return $base;
            }
            $preview = self::located($point);
            if ($preview['state'] !== 'located') {
                return $base;
            }
            $preview['source'] = $candidate['source'];
            $preview['geonames_id'] = $point[0];
            $preview['place'] = $candidate['display_name'];
            if ($candidate['estimated']) {
                $preview['label'] = 'Ortsvorschlag · ungefähr';
                $preview['estimated'] = true;
                $preview['candidate_count'] = $candidate['candidate_count'];
            } elseif (in_array($point[4], ['RSTN', 'RSTP', 'RYD'], true)) {
                $preview['label'] = $point[4] === 'RYD' ? 'Bahnanlage · ungefähr' : 'Bahnhofslage · ungefähr';
                $preview['detail'] = true;
            } elseif ($point[4] === 'PPLX') {
                $preview['label'] = 'Stadtteillage · ungefähr';
                $preview['detail'] = true;
            }

            return $preview;
        }
        if (! $allowMultiple || preg_match('/(?:\/|;|\bund\b)/ui', $location) !== 1) {
            return $base;
        }
        // Full names such as Frankfurt/Main and Heidelberg-Kirchheim/Rohrbach
        // were tried first; only unresolved explicit separators form a list.
        $parts = preg_split('/\s*(?:\/|;|\bund\b)\s*/ui', $location) ?: [];
        if (count($parts) < 2 || count($parts) > 4 || in_array('', $parts, true)) {
            return self::unlocated('unknown');
        }
        $points = [];
        $unresolved = 0;
        foreach ($parts as $part) {
            $point = self::displayLocation($part, self::mapLocation($part), $context, false);
            if ($point['state'] !== 'located') {
                $unresolved++;

                continue;
            }
            $key = number_format($point['latitude'], 6, '.', '').','.number_format($point['longitude'], 6, '.', '');
            $points[$key] = $point;
        }
        if ($points === []) {
            return $base;
        }
        if (count($points) === 1 && $unresolved === 0) {
            return reset($points);
        }

        return [
            'state' => 'multiple', 'place' => implode(' / ', array_column($points, 'place')),
            'label' => $unresolved ? 'Mehrere genannte Orte · teilweise verortet' : 'Mehrere genannte Orte · ungefähr',
            'points' => array_values($points), 'unresolved_count' => $unresolved,
            'estimated' => count(array_filter($points, fn (array $point): bool => $point['estimated'] ?? false)) > 0,
        ];
    }

    private static function refineLocation(array $base, array $locations, array $texts, bool $missing): array
    {
        $detail = LocationDetailResolver::resolve($base, $locations, $texts, $missing);
        if ($detail === null) {
            return $base;
        }
        $preview = self::located($detail['point']);
        if ($preview['state'] !== 'located') {
            return $base;
        }
        $preview['label'] = $detail['point'][4] === 'station' ? 'Bahnhofslage · ungefähr' : 'Stadtteillage · ungefähr';
        $preview['detail'] = true;
        $preview['source'] = $detail['source'];

        return $preview;
    }

    /** Pure display validation: no directory lookups, GPS sources or routing. */
    private static function confirmedSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot) || ($snapshot['confirmed'] ?? false) !== true) {
            return null;
        }
        $name = $snapshot['name'] ?? null;
        $latitude = $snapshot['latitude'] ?? null;
        $longitude = $snapshot['longitude'] ?? null;
        if (self::normalize($name) === null || ! is_string($name) || trim($name) === ''
            || ! is_numeric($latitude) || ! is_numeric($longitude)
            || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return self::unlocated('unknown');
        }

        $preview = self::located([0, trim($name), (float) $latitude, (float) $longitude]);
        if ($preview['state'] !== 'located') {
            return ['state' => 'outside', 'label' => 'Bestätigter Einsatzstandort außerhalb der Deutschlandkarte', 'confirmed' => true, 'place' => trim($name)];
        }

        $preview['label'] = 'Bestätigter Einsatzstandort';
        $preview['confirmed'] = true;

        return $preview;
    }

    private static function exactLocation(?string $location): array
    {
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

    private static function structuredLocation(mixed $location, mixed $city, mixed $postalCode, mixed $country): array
    {
        $location = self::normalize($location);
        $city = self::normalize($city);
        $postalCode = self::normalize($postalCode);
        $country = self::normalize($country);
        if ($location === null || $city === null || $postalCode === null || $country === null) {
            return self::unlocated('unknown');
        }
        if ($country !== '' && ! in_array($country, ['de', 'deu', 'deutschland', 'germany'], true)) {
            return self::unlocated('outside');
        }
        if ($postalCode !== '') {
            if (preg_match('/^\d{5}$/D', $postalCode) !== 1) {
                return self::unlocated('unknown');
            }
            $postalCity = $city;
            if ($city === '' && $location !== '') {
                $namedLocation = self::mapLocation($location);
                if ($namedLocation['state'] === 'located') {
                    $postalCity = self::normalize($namedLocation['place']);
                } elseif ($namedLocation['state'] === 'ambiguous') {
                    $postalCity = $location;
                }
            }
            $postal = self::postalLocation($postalCode, $postalCity);
            if ($postal !== null) {
                return $postal;
            }
        }

        // A supplied city remains authoritative: conflicting/unknown city text
        // must never borrow a known location name or a postcode-only guess.
        return self::mapLocation($city !== '' ? $city : $location);
    }

    private static function mapLocation(string $location): array
    {
        $exact = self::exactLocation($location);
        if ($exact['state'] === 'located' || ($exact['state'] === 'ambiguous' && array_key_exists($location, self::places()))) {
            return $exact;
        }
        if (preg_match('/^(?:de[- ]?)?(\d{5})[ ,]+(.+)$/uD', $location, $matches) === 1) {
            return self::postalLocation($matches[1], $matches[2]) ?? self::unlocated('unknown');
        }

        $name = self::mapName($location);
        $aliases = self::locationAliases();
        if (array_key_exists($name, $aliases)) {
            return self::aliasLocation($aliases[$name]);
        }
        // A source-backed district/station alias can carry a bounded railway
        // qualifier. Free text, routes, addresses and foreign suffixes cannot.
        preg_match_all('/[\s,–—-]+/u', $name, $separators, PREG_OFFSET_CAPTURE);
        foreach (array_reverse($separators[0]) as [$separator, $offset]) {
            $prefix = substr($name, 0, $offset);
            $suffix = substr($name, $offset + strlen($separator));
            if (array_key_exists($prefix, $aliases) && self::isLocationQualifier($suffix, [])) {
                return self::aliasLocation($aliases[$prefix]);
            }
        }

        // Recognize railway label prefixes only around an exact known locality;
        // arbitrary words before a city still cannot create a marker.
        if (preg_match('/^(?:bahnhof|hauptbahnhof|hbf\.?|bf\.?|bhf\.?|rbf\.?|gbf\.?) (.+)$/uD', $location, $matches) === 1) {
            return self::exactLocation($matches[1]);
        }

        return $exact;
    }

    private static function postalLocation(string $postalCode, string $city): ?array
    {
        $candidates = self::postcodes()[$postalCode] ?? null;
        if ($candidates === null) {
            return null;
        }
        $preview = $city !== '' ? self::exactLocation($city) : self::unlocated('unknown');
        $key = self::mapName($preview['state'] === 'located' ? self::normalize($preview['place']) : $city);
        if ($city !== '') {
            $candidates = array_values(array_filter($candidates, fn (array $candidate): bool => self::mapName(self::normalize($candidate[0])) === $key));
            if ($candidates === []) {
                return self::unlocated('unknown');
            }
            if ($preview['state'] === 'located') {
                return $preview;
            }
        }
        if (count($candidates) !== 1) {
            return self::unlocated('ambiguous');
        }
        [$name, $latitude, $longitude] = $candidates[0];
        $located = self::located([0, $name, $latitude, $longitude]);
        if ($located['state'] === 'located') {
            $located['label'] = 'PLZ-Ortslage · ungefähr';
        }

        return $located;
    }

    private static function aliasLocation(?array $alias): array
    {
        if ($alias === null) {
            return self::unlocated('ambiguous');
        }
        $located = self::located($alias, $alias[4] === 'city');
        if ($located['state'] === 'located' && $alias[4] === 'station') {
            $located['label'] = 'Bahnhofslage · ungefähr';
        }

        return $located;
    }

    private static function mapName(string $name): string
    {
        $name = str_replace(['–', '—'], '-', $name);

        return preg_replace(['/\s*-\s*/u', '/\s*\(\s*/u', '/\s*\)\s*/u', '/\)(?=\pL)/u'], ['-', '(', ')', ') '], $name) ?? $name;
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
            'latitude' => (float) $place[2],
            'longitude' => (float) $place[3],
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

    private static function locationAliases(): array
    {
        if (self::$locationAliases === null) {
            $data = json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-location-aliases.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$locationAliases = [];
            foreach ($data as $name => $point) {
                $key = self::mapName($name);
                if (array_key_exists($key, self::$locationAliases) && self::$locationAliases[$key] !== $point) {
                    self::$locationAliases[$key] = null;
                } else {
                    self::$locationAliases[$key] = $point;
                }
            }
        }

        return self::$locationAliases;
    }

    private static function postcodes(): array
    {
        return self::$postcodes ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/data/maps/germany-postcodes.json'), true, 512, JSON_THROW_ON_ERROR);
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
