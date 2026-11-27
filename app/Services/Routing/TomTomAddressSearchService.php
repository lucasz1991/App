<?php

namespace App\Services\Routing;

use App\Support\Routing\RoutingSchema;
use App\Support\Routing\TomTomSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Server-only address suggestions; selection and confirmation belong to the caller. */
class TomTomAddressSearchService
{
    private const PRECISIONS = [
        'Point Address' => 'address',
        'Address Range' => 'interpolated',
        'Street' => 'street',
        'Geography' => 'locality',
    ];

    public const COUNTRIES = ['DE', 'AT', 'CH', 'NL', 'PL', 'CZ'];

    public function search(string $query): array
    {
        return $this->lookup($query, 'DE', false, false);
    }

    public function suggest(string $query, string $country, bool $places = false): array
    {
        return $this->lookup($query, $country, $places, true);
    }

    private function lookup(string $query, string $country, bool $places, bool $structured): array
    {
        if ($country !== '' && ! in_array($country, self::COUNTRIES, true)) {
            return $this->unknown('unsupported_country');
        }
        if (! mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $query)) {
            return $this->unknown('invalid_query');
        }
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 3) {
            return $this->unknown('query_too_short');
        }
        $response = $this->request('search/'.rawurlencode($query), [
            'typeahead' => $places ? 'false' : 'true', 'limit' => 6,
            'countrySet' => $country ?: implode(',', self::COUNTRIES), 'language' => 'de-DE',
            'idxSet' => $places ? 'POI,PAD,Addr,Str,Geo' : 'PAD,Addr,Str,Geo',
        ]);
        if (isset($response['reason'])) {
            return $response;
        }
        $rows = $response['body']['results'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return $this->unknown('invalid_response');
        }
        $results = [];
        $seen = [];
        foreach (array_slice($rows, 0, 30) as $row) {
            if (! is_array($row) || ($result = $this->result($row, $country, $places, $structured)) === null) {
                continue;
            }
            if (isset($seen[$result['provider_id']])) {
                continue;
            }
            $seen[$result['provider_id']] = true;
            $results[] = $result;
            if (count($results) === 6) {
                break;
            }
        }

        return ['status' => 'known', 'reason' => null, 'results' => $results];
    }

    private function request(string $path, array $parameters): array
    {
        $configuration = TomTomSettings::serverConfiguration();
        if (($reason = $this->unavailable($configuration)) !== null) {
            return $this->unknown($reason);
        }
        $apiKey = $configuration['api_key'];
        if (! app(RoutingUsageService::class)->reserve('routing_transactions')) {
            return $this->unknown('budget_exhausted');
        }
        // Recheck uncached settings after reservation; key changes never reuse credentials.
        if (($reason = $this->changed($apiKey)) !== null) {
            return $this->unknown($reason);
        }
        try {
            $response = Http::acceptJson()->connectTimeout(2)->timeout(4)
                ->withOptions(['allow_redirects' => false])
                ->get('https://api.tomtom.com/search/2/'.$path.'.json', ['key' => $apiKey] + $parameters);
            if (($reason = $this->changed($apiKey)) !== null) {
                return $this->unknown($reason);
            }
            if (! $response->successful()) {
                return $this->unknown(match ($response->status()) {
                    401, 403 => 'provider_forbidden',
                    429 => 'provider_limit',
                    default => 'provider_error',
                });
            }
            if (strlen($response->body()) > 262144) {
                return $this->unknown('invalid_response');
            }
            return ['body' => $response->json()];
        } catch (Throwable) {
            // Never log the request URL, credential, search text or provider payload.
            return $this->unknown('provider_unavailable');
        }
    }

    private function result(array $row, string $country, bool $places, bool $structured): ?array
    {
        $type = match ($row['type'] ?? null) {
            'PointAddress' => 'Point Address',
            'AddressRange' => 'Address Range',
            default => $row['type'] ?? null,
        };
        $address = $row['address'] ?? null;
        $position = $row['position'] ?? null;
        if (! is_string($type) || (! isset(self::PRECISIONS[$type]) && ! ($places && $type === 'POI')) || ! is_array($address) || ! is_array($position)
            || ! in_array($address['countryCode'] ?? null, $country === '' ? self::COUNTRIES : [$country], true)) {
            return null;
        }
        $label = $address['freeformAddress'] ?? null;
        $id = $row['id'] ?? null;
        if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 200
            || ! is_string($id) || trim($id) === '' || mb_strlen($id) > 200
            || ! mb_check_encoding($label, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $label)
            || preg_match('/[\x00-\x1F\x7F]/', $id)) {
            return null;
        }
        $point = ['latitude' => $position['lat'] ?? null, 'longitude' => $position['lon'] ?? null];
        if (! TomTomRoutingService::validPoint($point)) {
            return null;
        }
        $latitude = (float) $point['latitude'];
        $longitude = (float) $point['longitude'];
        // Conservative Germany bounds supplement countrySet and the response country.
        if ($address['countryCode'] === 'DE' && ($latitude < 47.0 || $latitude > 56.0 || $longitude < 5.0 || $longitude > 16.0)) {
            return null;
        }

        return [
            'label' => trim($label), 'latitude' => $latitude, 'longitude' => $longitude,
            'provider' => 'tomtom', 'provider_id' => $id, 'type' => $type, 'precision' => self::PRECISIONS[$type] ?? 'poi',
        ] + ($structured ? $this->addressFields($address) + ['name' => $this->field($row['poi']['name'] ?? null)] : []);
    }

    private function field(mixed $value): string
    {
        return is_string($value) && mb_strlen($value) <= 200 && ! preg_match('/[[:cntrl:]]/u', $value) ? trim($value) : '';
    }

    private function addressFields(array $address): array
    {
        return [
            'street' => trim($this->field($address['streetName'] ?? null).' '.$this->field($address['streetNumber'] ?? null)),
            'postal' => $this->field($address['postalCode'] ?? null),
            'city' => $this->field($address['municipality'] ?? null),
            'country' => $this->field($address['countryCode'] ?? null),
        ];
    }

    public function reverse(float $latitude, float $longitude): array
    {
        if (! TomTomRoutingService::validPoint(compact('latitude', 'longitude'))) {
            return $this->unknown('invalid_point');
        }
        $response = $this->request(sprintf('reverseGeocode/%.6F,%.6F', $latitude, $longitude), ['language' => 'de-DE']);
        if (isset($response['reason'])) {
            return $response;
        }
        $address = $response['body']['addresses'][0]['address'] ?? null;
        $label = is_array($address) ? $this->field($address['freeformAddress'] ?? null) : '';
        return ['status' => 'known', 'results' => $label === '' ? [] : [
            $this->addressFields($address) + compact('latitude', 'longitude', 'label') + ['precision' => 'gps'],
        ]];
    }

    private function unavailable(array $configuration): ?string
    {
        if (DB::transactionLevel() > 0) {
            return 'transaction_requires_preflight';
        }
        if (! ($configuration['settings']['enabled'] ?? false)) {
            return 'disabled';
        }
        if ($configuration['api_key'] === null) {
            return 'missing_key';
        }

        return RoutingSchema::ready() ? null : 'schema_missing';
    }

    private function changed(string $apiKey): ?string
    {
        $configuration = TomTomSettings::serverConfiguration();

        return $this->unavailable($configuration) ?? ($configuration['api_key'] !== $apiKey ? 'settings_changed' : null);
    }

    private function unknown(string $reason): array
    {
        return ['status' => 'unknown', 'reason' => $reason, 'results' => []];
    }
}
