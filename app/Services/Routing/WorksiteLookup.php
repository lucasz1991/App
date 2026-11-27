<?php

namespace App\Services\Routing;

use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Display-only results. A confirmed routing snapshot can only come from SiteSnapshotService. */
final class WorksiteLookup
{
    public static function forRecord(Order|Shift|OperationInquiry $record): array
    {
        $name = is_string($record->location_name) ? trim($record->location_name) : '';
        $order = $record instanceof Order ? $record : null;
        if ($record instanceof Shift && $record->relationLoaded('order')) {
            $parent = $record->order;
            if ($parent && ($name === '' || strcasecmp($name, trim((string) $parent->location_name)) === 0)) {
                $order = $parent;
                $name = trim((string) $parent->location_name);
            }
        }

        return self::input($name, $order);
    }

    public static function input(string $name, ?Order $order = null): array
    {
        $city = is_string($order?->city) ? trim($order->city) : '';
        $postal = is_string($order?->postal_code) ? trim($order->postal_code) : '';
        $street = is_string($order?->street) ? trim($order->street) : '';
        $parts = array_unique(array_filter([$street !== '' ? $street : $name, trim($postal.' '.$city)], 'strlen'));
        $query = implode(', ', $parts);
        $country = is_string($order?->country) ? strtoupper(trim($order->country)) : '';

        return compact('query', 'country', 'city', 'postal', 'street');
    }

    /** With the shared cache disabled a lookup belongs only to the current session. */
    private static function cacheKey(array $input): string
    {
        if (! request()->attributes->has('worksite_shared_cache')) {
            $settings = \App\Support\Routing\TomTomSettings::all();
            request()->attributes->set('worksite_shared_cache', (bool) $settings['server_cache_enabled']);
        }
        return self::key($input).(request()->attributes->get('worksite_shared_cache') ? '' :
            ':session:'.hash('sha256', session()->getId()));
    }

    public static function cached(array $input): ?array
    {
        return Cache::get(self::cacheKey($input));
    }

    public static function key(array $input): string
    {
        ksort($input);
        return 'worksite:v2:'.hash_hmac('sha256', json_encode(array_map(fn (string $s) => mb_strtolower(Str::squish($s)), $input), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public static function needsLookup(array $local, array $input): bool
    {
        return ! ($local['confirmed'] ?? false) && $local['state'] !== 'multiple'
            && ($local['state'] !== 'located' || ($input['street'] ?? '') !== '')
            && mb_strlen($input['query']) >= 3 && mb_strlen($input['query']) <= 200
            && ($input['country'] === '' || in_array($input['country'], TomTomAddressSearchService::COUNTRIES, true));
    }

    public static function signature(array $input): string
    {
        return hash_hmac('sha256', auth()->id().'|'.self::key($input), config('app.key'));
    }

    public static function preview(array $local, array $input): array
    {
        if (! self::needsLookup($local, $input)) {
            return $local;
        }
        if (! auth()->check()) {
            return $local;
        }
        $request = request();
        if (!$request->attributes->has('worksite_search_enabled')) {
            $settings = \App\Support\Routing\TomTomSettings::all();
            $request->attributes->set('worksite_shared_cache', (bool) $settings['server_cache_enabled']);
            $request->attributes->set('worksite_search_enabled', $settings['enabled'] && $settings['has_api_key']);
        }
        if (!$request->attributes->get('worksite_search_enabled')) {
            return $local + ['lookupDisabled' => true];
        }
        $saved = self::cached($input);
        if ($saved === null) {
            return $local + ['lookup' => $input + ['signature' => self::signature($input)]];
        }
        // Older cache entries still contain the ordered list presented by the previous picker.
        $point = $saved['point'] ?? $saved['alternatives'][0] ?? null;
        if ($point !== null) {
            return $point;
        }
        return $local['state'] === 'located'
            ? $local + ['lookupMessage' => $saved['label']]
            : array_replace($local, ['label' => $saved['label']]);
    }

    public static function point(array $row): array
    {
        $place = trim(($row['name'] ?? '').' '.$row['label']);
        return [
            'state' => $row['country'] === 'DE' ? 'located' : 'outside',
            'place' => $place, 'label' => 'TomTom · ungefähre '.match ($row['precision']) {
                'poi' => 'Anlagenlage', 'address', 'interpolated' => 'Adresslage', 'street' => 'Straßenlage', default => 'Ortslage',
            }, 'latitude' => $row['latitude'], 'longitude' => $row['longitude'],
            'estimated' => true, 'source' => 'tomtom',
        ];
    }

    /** The lock covers the provider call too, so parallel views share a single reservation. */
    public function resolve(array $input): void
    {
        $key = self::cacheKey($input);
        if (Cache::has($key)) {
            return;
        }
        Cache::lock($key.':lock', 12)->block(6, function () use ($key, $input) {
            if (Cache::has($key)) {
                return;
            }
            $response = app(TomTomAddressSearchService::class)->suggest($input['query'], $input['country'], true);
            if ($response['status'] !== 'known') {
                Cache::put($key, ['label' => 'TomTom-Suche derzeit nicht verfügbar'], 60);
                return;
            }
            $rows = array_values(array_filter($response['results'], fn (array $row) =>
                ($input['city'] === '' || self::words($row['city']) === self::words($input['city']))
                && ($input['postal'] === '' || $row['postal'] === $input['postal'])));

            $value = $rows ? ['point' => self::point($rows[0])] : ['label' => 'Kein passender TomTom-Treffer'];
            $ttl = request()->attributes->get('worksite_shared_cache') ? ($rows ? 86400 : 14400) : 600;
            // Store map fields only; private form searches never enter this cache.
            Cache::put($key, $value, $ttl);
        });
    }

    private static function words(string $value): array
    {
        $value = strtolower(Str::ascii($value, 'de'));
        // Wackerwerk and Wacker Werk describe the same plant; keep both name and type.
        $value = preg_replace('/([a-z]{3,})(werk)\b/', '$1 $2', $value);
        $value = str_replace(['hauptbahnhof', 'rangierbahnhof'], ['hbf', 'rbf'], $value);
        return array_values(array_unique(preg_split('/[^a-z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY)));
    }
}
