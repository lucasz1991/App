<?php

namespace App\Services\Routing;

use App\Support\Routing\RoutingSchema;
use App\Support\Routing\TileCachePolicy;
use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class TomTomRoutingService
{
    /** Only alive during one explicit planning operation, including nested services. */
    private static ?array $operation = null;

    public function __construct(private readonly float $durationBudgetSeconds = 9.0) {}

    public static function withinOperation(callable $callback, float $seconds = 9.0): mixed
    {
        if (self::$operation !== null) {
            return $callback();
        }
        self::$operation = ['deadline' => microtime(true) + min(10.0, max(0.01, $seconds)), 'results' => []];
        try {
            return $callback();
        } finally {
            // No route facts survive this operation, even with long-lived workers
            // or exceptions. Display and binding phases have separate identities.
            self::$operation = null;
        }
    }

    public function summaryMany(array $requests): array
    {
        return $this->calculateMany($requests, false);
    }

    /** Planned display only; binding and employee-location callers use summaryMany(). */
    public function summaryManyForDisplay(array $requests): array
    {
        return $this->calculateMany($requests, false, false, true);
    }

    public function geometry(array $origin, array $destination, mixed $departure): array
    {
        return $this->calculateMany(['route' => compact('origin', 'destination', 'departure')], true)['route'];
    }

    public function geometryForDisplay(array $origin, array $destination, mixed $departure): array
    {
        return $this->calculateMany(['route' => compact('origin', 'destination', 'departure')], true, false, true)['route'];
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
        $configuration = TomTomSettings::serverConfiguration();
        $reason = $this->unavailable(false, $configuration);
        if ($reason !== null) {
            return self::unknown($reason);
        }
        $apiKey = $configuration['api_key'];
        if ($apiKey === null) {
            return self::unknown('missing_key');
        }
        $url = 'https://api.tomtom.com/map/1/tile/basic/main/'.$z.'/'.$x.'/'.$y.'.png';
        $query = ['key' => $apiKey, 'tileSize' => 256];
        $identity = $this->cacheIdentity($url, $query);
        $cache = app(ServerRoutingCache::class);
        $load = function () use ($apiKey, $url, $query, $identity, $cache): array {
            $configuration = TomTomSettings::serverConfiguration();
            if (($reason = $this->unavailable(false, $configuration)) !== null || $apiKey !== $configuration['api_key']) {
                return self::unknown($reason ?? 'settings_changed');
            }
            if (($cached = $this->cached('tile', $identity, $cache, $configuration['settings'])) !== null) {
                $fresh = TomTomSettings::serverConfiguration();

                return ($reason = $this->unavailable(false, $fresh)) !== null || $apiKey !== $fresh['api_key'] || ! $this->cacheEnabled('tile', $fresh['settings'])
                    ? self::unknown($reason ?? 'settings_changed') : $cached;
            }
            if (! app(RoutingUsageService::class)->reserve('map_tiles')) {
                return self::unknown('budget_exhausted');
            }

            return $this->fetchTile($url, $query, $identity, $apiKey, $cache);
        };

        return $this->cacheEnabled('tile', $configuration['settings'])
            ? $cache->synchronized('tile', $identity, $load, function () use ($cache, $identity, $apiKey): array {
                $configuration = TomTomSettings::serverConfiguration();
                if (($reason = $this->unavailable(false, $configuration)) !== null || $apiKey !== $configuration['api_key']) {
                    return self::unknown($reason ?? 'settings_changed');
                }

                $cached = $this->cached('tile', $identity, $cache, $configuration['settings']);
                $fresh = TomTomSettings::serverConfiguration();

                return ($reason = $this->unavailable(false, $fresh)) !== null || $apiKey !== $fresh['api_key'] || ! $this->cacheEnabled('tile', $fresh['settings'])
                    ? self::unknown($reason ?? 'settings_changed') : ($cached ?? self::unknown('calculation_pending'));
            }) : $load();
    }

    private function fetchTile(string $url, array $query, string $identity, string $apiKey, ServerRoutingCache $cache): array
    {
        try {
            $startedAt = microtime(true);
            $response = Http::connectTimeout(2)->timeout(6)->get($url, $query);
            $receivedAt = microtime(true);
            $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
            if (! $response->successful() || ! in_array($contentType, ['image/png', 'image/jpeg'], true) || $response->body() === '') {
                return self::unknown('provider_error');
            }
            $configuration = TomTomSettings::serverConfiguration();
            if (($reason = $this->unavailable(false, $configuration)) !== null || $apiKey !== $configuration['api_key']) {
                return self::unknown($reason ?? 'settings_changed');
            }
            $result = [
                'status' => 'known', 'body' => $response->body(), 'content_type' => $contentType,
            ];
            $seconds = $this->sharedTileSeconds($response, $startedAt, $receivedAt, $configuration['settings']);
            if ($seconds > 0 && $this->cacheEnabled('tile', $configuration['settings'])) {
                $cache->put('tile', $identity, $result, $seconds);
            }

            return $result;
        } catch (Throwable) {
            return self::unknown('provider_unavailable');
        }
    }

    private function calculateMany(array $requests, bool $geometry, bool $test = false, bool $display = false): array
    {
        $deadline = microtime(true) + min(10.0, max(0.01, $this->durationBudgetSeconds));
        if (self::$operation !== null) {
            $deadline = min($deadline, self::$operation['deadline']);
        }
        $results = [];
        $unique = [];
        $links = [];
        $kind = $geometry ? 'geometry' : 'summary';
        $cache = $display ? app(ServerRoutingCache::class) : null;
        $configuration = TomTomSettings::serverConfiguration();
        $unavailable = $this->unavailable($test, $configuration);
        $apiKey = $unavailable === null ? $configuration['api_key'] : null;
        $unavailable ??= $apiKey === null ? 'missing_key' : null;
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
            $unique[$key]['identity'] = $this->cacheIdentity($unique[$key]['url'], $unique[$key]['query']);
        }
        $calculated = [];
        $operationKeys = [];
        $pending = $unique;
        if (self::$operation !== null && ! $test) {
            $generation = $display && $this->cacheEnabled($kind, $configuration['settings']) ? $cache->generation() : null;
            $policy = hash('sha256', json_encode([$display, $configuration['settings'], $generation], JSON_THROW_ON_ERROR));
            foreach ($unique as $key => $request) {
                $operationKeys[$key] = $policy.':'.$request['identity'];
                if (isset(self::$operation['results'][$operationKeys[$key]])) {
                    $calculated[$key] = self::$operation['results'][$operationKeys[$key]];
                    unset($pending[$key]);
                }
            }
        }
        // Three simultaneous requests bound provider pressure and response memory.
        foreach (array_chunk($pending, 3, true) as $chunk) {
            $produce = function () use ($chunk, $display, $kind, $deadline, $test, $geometry, $apiKey, $cache, &$calculated): void {
                $configuration = TomTomSettings::serverConfiguration();
                if (($reason = $this->unavailable($test, $configuration)) !== null || $apiKey !== $configuration['api_key']) {
                    foreach ($chunk as $key => $request) {
                        $calculated[$key] = self::unknown($reason ?? 'settings_changed');
                    }

                    return;
                }
                foreach ($chunk as $key => $request) {
                    if ($display && ($cached = $this->cached($kind, $request['identity'], $cache, $configuration['settings'])) !== null) {
                        $calculated[$key] = $cached;
                        unset($chunk[$key]);
                    }
                }
                if ($chunk === []) {
                    return;
                }
                if (microtime(true) >= $deadline) {
                    foreach ($chunk as $key => $request) {
                        $calculated[$key] = self::unknown('provider_timeout');
                    }

                    return;
                }
                $reserved = [];
                foreach ($chunk as $key => $request) {
                    if (! app(RoutingUsageService::class)->reserve('routing_transactions')) {
                        $calculated[$key] = self::unknown('budget_exhausted');

                        continue;
                    }
                    $reserved[$key] = $request;
                }
                if ($reserved === []) {
                    return;
                }
                try {
                    $timeout = min(6.0, max(0.05, $deadline - microtime(true)));
                    $responses = Http::pool(function (Pool $pool) use ($reserved, $timeout): array {
                        $calls = [];
                        foreach ($reserved as $key => $request) {
                            $calls[] = $pool->as($key)->connectTimeout(min(2.0, $timeout))->timeout($timeout)->get($request['url'], $request['query']);
                        }

                        return $calls;
                    });
                    $configuration = TomTomSettings::serverConfiguration();
                    $reason = $this->unavailable($test, $configuration);
                    foreach ($reserved as $key => $request) {
                        if ($reason !== null || $apiKey !== $configuration['api_key']) {
                            $calculated[$key] = self::unknown($reason ?? 'settings_changed');

                            continue;
                        }
                        $result = $this->parse($responses[$key] ?? null, $geometry);
                        if ($display && $result['status'] === 'known') {
                            $result += ['cache_hit' => false, 'calculated_at' => CarbonImmutable::now()->utc()->toIso8601String(), 'expires_at' => null];
                            if ($this->cacheEnabled($kind, $configuration['settings'])) {
                                $payload = array_diff_key($result, array_flip(['cache_hit', 'calculated_at', 'expires_at']));
                                if ($cache->put($kind, $request['identity'], $payload, $this->cacheSeconds($kind, $configuration['settings']))) {
                                    $entry = $cache->get($kind, $request['identity']);
                                    $result['expires_at'] = $entry['expires_at'] ?? null;
                                }
                            }
                        }
                        $calculated[$key] = $result;
                    }
                } catch (Throwable) {
                    // Exception messages can contain URL credentials: never report/log them.
                    foreach ($reserved as $key => $request) {
                        $calculated[$key] = self::unknown('provider_unavailable');
                    }
                }
            };
            if ($display && microtime(true) < $deadline && $this->cacheEnabled($kind, $configuration['settings'])) {
                $cache->synchronizedMany($kind, array_column($chunk, 'identity'), $produce, function () use ($chunk, $kind, $cache, &$calculated): void {
                    $settings = TomTomSettings::all();
                    foreach ($chunk as $key => $request) {
                        $calculated[$key] = $this->cached($kind, $request['identity'], $cache, $settings) ?? self::unknown('calculation_pending');
                    }
                });
            } else {
                $produce();
            }
        }
        $fresh = $unique === [] ? $configuration : TomTomSettings::serverConfiguration();
        $reason = $unique === [] ? $unavailable : $this->unavailable($test, $fresh);
        if (self::$operation !== null && ! $test && $reason === null && $apiKey === $fresh['api_key']) {
            foreach ($operationKeys as $key => $operationKey) {
                $value = $calculated[$key] ?? null;
                if ($value !== null && ($value['reason'] ?? null) !== 'calculation_pending' && count(self::$operation['results']) < 6000) {
                    self::$operation['results'][$operationKey] = $value;
                }
            }
        }
        foreach ($links as $id => $key) {
            $result = $calculated[$key] ?? self::unknown('provider_unavailable');
            $results[$id] = $reason !== null || $apiKey !== $fresh['api_key'] || (($result['cache_hit'] ?? false) && ! $this->cacheEnabled($kind, $fresh['settings']))
                ? self::unknown($reason ?? 'settings_changed') : $result;
        }

        return $results;
    }

    private function cacheIdentity(string $url, array $query): string
    {
        $credential = hash('sha256', (string) ($query['key'] ?? ''));
        unset($query['key']);
        ksort($query);

        return hash('sha256', json_encode([$url, $query, $credential], JSON_THROW_ON_ERROR));
    }

    private function cacheEnabled(string $kind, array $settings): bool
    {
        return ($settings['server_cache_enabled'] ?? true) === true
            && ($settings[$kind === 'tile' ? 'server_tile_cache_enabled' : 'server_route_cache_enabled'] ?? true) === true;
    }

    private function cacheSeconds(string $kind, array $settings): int
    {
        return match ($kind) {
            'tile' => max(1, min(1440, (int) ($settings['server_tile_cache_minutes'] ?? 60))) * 60,
            'geometry' => max(1, min(60, (int) ($settings['server_geometry_cache_minutes'] ?? 15))) * 60,
            default => max(1, min(15, (int) ($settings['server_route_cache_minutes'] ?? 5))) * 60,
        };
    }

    private function cached(string $kind, string $identity, ServerRoutingCache $cache, array $settings): ?array
    {
        if (! $this->cacheEnabled($kind, $settings)) {
            return null;
        }
        $entry = $cache->get($kind, $identity);
        if ($entry === null) {
            return null;
        }
        $expires = CarbonImmutable::parse($entry['expires_at']);
        $ceiling = CarbonImmutable::parse($entry['fetched_at'])->addSeconds($this->cacheSeconds($kind, $settings));
        $expires = $expires->min($ceiling);
        if ($expires->lte(CarbonImmutable::now())) {
            return null;
        }
        $result = $entry['data'];
        if ($kind !== 'tile') {
            $result += ['cache_hit' => true, 'calculated_at' => $entry['fetched_at'], 'expires_at' => $expires->toIso8601String()];
        }

        return $result;
    }

    private function sharedTileSeconds(Response $response, float $startedAt, float $receivedAt, array $settings): int
    {
        $control = strtolower($response->header('Cache-Control'));
        if (preg_match('/(?:^|,)\s*private(?:\s*=|\s*,|\s*$)/', $control)) {
            return 0;
        }
        $seconds = TileCachePolicy::remainingSeconds($response->headers(), $this->cacheSeconds('tile', $settings), $startedAt, $receivedAt);
        $shared = null;
        $hasMaxAge = false;
        $directives = [];
        foreach (explode(',', $control) as $directive) {
            $parts = explode('=', trim($directive), 2);
            $name = trim($parts[0]);
            if ($name === 'max-age') {
                $hasMaxAge = true;

                continue;
            }
            if ($name === 's-maxage') {
                if ($shared !== null || count($parts) !== 2 || ! preg_match('/^(?:[0-9]+|"[0-9]+")$/D', trim($parts[1]))) {
                    return 0;
                }
                $shared = trim($parts[1]);

                continue;
            }
            $directives[] = $directive;
        }
        if ($shared !== null) {
            $headers = $response->headers();
            foreach (array_keys($headers) as $name) {
                if (strtolower($name) === 'cache-control') {
                    unset($headers[$name]);
                }
            }
            $headers['Cache-Control'] = [implode(',', array_merge($directives, ['max-age='.$shared]))];
            $sharedSeconds = TileCachePolicy::remainingSeconds($headers, $this->cacheSeconds('tile', $settings), $startedAt, $receivedAt);
            $seconds = $hasMaxAge ? min($seconds, $sharedSeconds) : $sharedSeconds;
        }

        return $seconds;
    }

    private function unavailable(bool $test = false, ?array $configuration = null): ?string
    {
        // This guard applies even when nested callers forgot their outer preflight.
        if (DB::transactionLevel() > 0) {
            return 'transaction_requires_preflight';
        }
        $configuration ??= TomTomSettings::serverConfiguration();
        if (! $test && ! $configuration['settings']['enabled']) {
            return 'disabled';
        }
        if ($configuration['api_key'] === null) {
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
