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

    /**
     * Callers authorize the actor, throttle input and discard outdated query results.
     * Search shares the existing RailTime routing request cap, not a provider tariff.
     * No provider response or search query is persisted by this service.
     *
     * @return array{status:string,reason:?string,results:array}
     */
    public function search(string $query): array
    {
        if (! mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/u', $query)) {
            return $this->unknown('invalid_query');
        }
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if (mb_strlen($query) < 3) {
            return $this->unknown('query_too_short');
        }
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
                ->get('https://api.tomtom.com/search/2/search/'.rawurlencode($query).'.json', [
                    'key' => $apiKey, 'typeahead' => 'true', 'limit' => 6,
                    'countrySet' => 'DE', 'language' => 'de-DE', 'idxSet' => 'PAD,Addr,Str,Geo',
                ]);
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
            $rows = $response->json('results');
            if (! is_array($rows) || ! array_is_list($rows)) {
                return $this->unknown('invalid_response');
            }
            $results = [];
            $seen = [];
            foreach (array_slice($rows, 0, 30) as $row) {
                if (! is_array($row) || ($result = $this->result($row)) === null) {
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
        } catch (Throwable) {
            // Never log the request URL, credential, search text or provider payload.
            return $this->unknown('provider_unavailable');
        }
    }

    private function result(array $row): ?array
    {
        $type = match ($row['type'] ?? null) {
            'PointAddress' => 'Point Address',
            'AddressRange' => 'Address Range',
            default => $row['type'] ?? null,
        };
        $address = $row['address'] ?? null;
        $position = $row['position'] ?? null;
        if (! is_string($type) || ! isset(self::PRECISIONS[$type]) || ! is_array($address) || ! is_array($position)
            || ($address['countryCode'] ?? null) !== 'DE') {
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
        if ($latitude < 47.0 || $latitude > 56.0 || $longitude < 5.0 || $longitude > 16.0) {
            return null;
        }

        return [
            'label' => trim($label), 'latitude' => $latitude, 'longitude' => $longitude,
            'provider' => 'tomtom', 'provider_id' => $id, 'type' => $type, 'precision' => self::PRECISIONS[$type],
        ];
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
