<?php

namespace App\Services\Routing;

use App\Support\Routing\RoutingSchema;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class TomTomRoutingService
{
    public function summaryMany(array $requests): array
    {
        return $this->calculateMany($requests, false);
    }

    public function geometry(array $origin, array $destination, mixed $departure): array
    {
        return $this->calculateMany(['route' => compact('origin', 'destination', 'departure')], true)['route'];
    }

    public function testConnection(): array
    {
        // Uses the saved credential, a real counted route, and no employee coordinates.
        return $this->calculateMany(['test' => ['origin' => ['latitude' => 52.520008, 'longitude' => 13.404954], 'destination' => ['latitude' => 52.516275, 'longitude' => 13.377704], 'departure' => now()]], false, true)['test'];
    }

    public function tile(int $z, int $x, int $y): array
    {
        if ($z < 0 || $z > 20 || $x < 0 || $y < 0 || $x >= 2 ** $z || $y >= 2 ** $z) {
            return self::unknown('invalid_tile');
        }
        $reason = $this->unavailable();
        if ($reason !== null) {
            return self::unknown($reason);
        }
        if (! app(RoutingUsageService::class)->reserve('map_tiles')) {
            return self::unknown('budget_exhausted');
        }
        try {
            $response = Http::connectTimeout(2)->timeout(6)->get('https://api.tomtom.com/map/1/tile/basic/main/'.$z.'/'.$x.'/'.$y.'.png', ['key' => TomTomSettings::apiKey(), 'tileSize' => 256]);
            if (! $response->successful() || ! str_starts_with($response->header('Content-Type'), 'image/')) {
                return self::unknown('provider_error');
            }

            return ['status' => 'known', 'body' => $response->body(), 'content_type' => 'image/png'];
        } catch (Throwable) {
            return self::unknown('provider_unavailable');
        }
    }

    private function calculateMany(array $requests, bool $geometry, bool $test = false): array
    {
        $results = [];
        $unique = [];
        $links = [];
        $unavailable = $this->unavailable($test);
        $apiKey = $unavailable === null ? TomTomSettings::apiKey() : null;
        foreach ($requests as $id => $request) {
            $results[$id] = self::unknown($unavailable ?? 'invalid_input');
            if ($unavailable !== null || ! self::validPoint($request['origin'] ?? []) || ! self::validPoint($request['destination'] ?? [])) {
                continue;
            }
            try {
                $departure = CarbonImmutable::parse($request['departure'])->utc()->toIso8601String();
            } catch (Throwable) {
                continue;
            }
            $origin = $this->coordinate($request['origin']);
            $destination = $this->coordinate($request['destination']);
            $key = $origin.':'.$destination.'@'.$departure;
            $links[$id] = $key;
            $unique[$key] = ['url' => 'https://api.tomtom.com/routing/1/calculateRoute/'.$origin.':'.$destination.'/json', 'query' => [
                'key' => $apiKey, 'travelMode' => 'car', 'routeType' => 'fastest', 'traffic' => 'true',
                'departAt' => $departure, 'routeRepresentation' => $geometry ? 'polyline' : 'summaryOnly',
            ]];
        }
        $calculated = [];
        // Three simultaneous requests bound provider pressure and response memory.
        foreach (array_chunk($unique, 3, true) as $chunk) {
            $reserved = [];
            foreach ($chunk as $key => $request) {
                if (! app(RoutingUsageService::class)->reserve('routing_transactions')) {
                    $calculated[$key] = self::unknown('budget_exhausted');

                    continue;
                }
                $reserved[$key] = $request;
            }
            if ($reserved === []) {
                continue;
            }
            try {
                $responses = Http::pool(function (Pool $pool) use ($reserved): array {
                    $calls = [];
                    foreach ($reserved as $key => $request) {
                        $calls[] = $pool->as($key)->connectTimeout(2)->timeout(6)->get($request['url'], $request['query']);
                    }

                    return $calls;
                });
                foreach ($reserved as $key => $request) {
                    $calculated[$key] = $this->parse($responses[$key] ?? null, $geometry);
                }
            } catch (Throwable) {
                // Exception messages can contain URL credentials: never report/log them.
                foreach ($reserved as $key => $request) {
                    $calculated[$key] = self::unknown('provider_unavailable');
                }
            }
        }
        foreach ($links as $id => $key) {
            $results[$id] = $calculated[$key] ?? self::unknown('provider_unavailable');
        }

        return $results;
    }

    private function unavailable(bool $test = false): ?string
    {
        // This guard applies even when nested callers forgot their outer preflight.
        if (DB::transactionLevel() > 0) {
            return 'transaction_requires_preflight';
        }
        if (! $test && ! TomTomSettings::all()['enabled']) {
            return 'disabled';
        }
        if (TomTomSettings::apiKey() === null) {
            return 'missing_key';
        }
        if (! RoutingSchema::ready()) {
            return 'schema_missing';
        }

        return null;
    }

    private function parse(mixed $response, bool $geometry): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return self::unknown($response instanceof Response && $response->status() === 429 ? 'provider_limit' : 'provider_error');
        }
        $route = $response->json('routes.0');
        $summary = $route['summary'] ?? [];
        if (! is_numeric($summary['travelTimeInSeconds'] ?? null) || ! is_numeric($summary['lengthInMeters'] ?? null)
            || ! is_finite((float) $summary['travelTimeInSeconds']) || ! is_finite((float) $summary['lengthInMeters'])
            || $summary['travelTimeInSeconds'] < 0 || $summary['lengthInMeters'] < 0
            || $summary['travelTimeInSeconds'] > PHP_INT_MAX || $summary['lengthInMeters'] > PHP_INT_MAX) {
            return self::unknown('invalid_response');
        }
        $result = ['status' => 'known', 'reason' => null, 'duration_seconds' => (int) ceil($summary['travelTimeInSeconds']), 'distance_meters' => (int) ceil($summary['lengthInMeters'])];
        if ($geometry) {
            $result['points'] = collect($route['legs'] ?? [])->flatMap(fn ($leg) => $leg['points'] ?? [])
                ->filter(fn ($point) => self::validPoint($point))->map(fn ($point) => [(float) $point['latitude'], (float) $point['longitude']])->values()->all();
            if (count($result['points']) < 2) {
                return self::unknown('geometry_missing');
            }
        }

        return $result;
    }

    public static function validPoint(array $point): bool
    {
        return is_numeric($point['latitude'] ?? null) && is_numeric($point['longitude'] ?? null)
            && is_finite((float) $point['latitude']) && is_finite((float) $point['longitude'])
            && abs((float) $point['latitude']) <= 90 && abs((float) $point['longitude']) <= 180;
    }

    private function coordinate(array $point): string
    {
        return number_format((float) $point['latitude'], 7, '.', '').','.number_format((float) $point['longitude'], 7, '.', '');
    }

    public static function unknown(string $reason): array
    {
        return ['status' => 'unknown', 'reason' => $reason, 'duration_seconds' => null, 'distance_meters' => null];
    }
}
