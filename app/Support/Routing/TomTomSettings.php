<?php

namespace App\Support\Routing;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class TomTomSettings
{
    public const DEFAULTS = [
        'enabled' => false, 'reserve_minutes' => 5, 'routing_monthly_limit' => 18000,
        'tile_monthly_limit' => 180000, 'use_employee_locations' => false,
        'server_cache_enabled' => true, 'server_tile_cache_enabled' => true, 'server_tile_cache_minutes' => 60,
        'server_route_cache_enabled' => true, 'server_route_cache_minutes' => 5,
        'server_geometry_cache_minutes' => 15, 'server_cache_max_mb' => 128,
    ];

    /** Public configuration deliberately excludes the encrypted credential. */
    public static function all(): array
    {
        return self::serverConfiguration()['settings'];
    }

    /** Internal request-phase snapshot; never expose this credential-bearing array to Livewire. */
    public static function serverConfiguration(): array
    {
        $stored = self::stored();
        $key = self::decryptKey($stored['api_key_encrypted'] ?? null);

        return [
            'settings' => array_intersect_key($stored, self::DEFAULTS) + self::DEFAULTS + [
                'has_api_key' => $key !== null,
                'key_mask' => $key === null ? '' : '••••••••'.substr($key, -4),
            ],
            'api_key' => $key,
        ];
    }

    public static function save(array $values, ?string $newKey = null, bool $deleteKey = false): void
    {
        $validated = Validator::make($values, [
            'enabled' => ['required', 'boolean'], 'reserve_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'routing_monthly_limit' => ['required', 'integer', 'min:0', 'max:10000000'],
            'tile_monthly_limit' => ['required', 'integer', 'min:0', 'max:100000000'],
            'use_employee_locations' => ['required', 'boolean'],
            'server_cache_enabled' => ['sometimes', 'boolean'],
            'server_tile_cache_enabled' => ['sometimes', 'boolean'],
            'server_tile_cache_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'server_route_cache_enabled' => ['sometimes', 'boolean'],
            'server_route_cache_minutes' => ['sometimes', 'integer', 'min:1', 'max:15'],
            'server_geometry_cache_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'server_cache_max_mb' => ['sometimes', 'integer', 'min:16', 'max:1024'],
        ])->validate();
        $stored = self::stored();
        unset($stored['tile_cache_enabled'], $stored['tile_cache_minutes']);
        $newKey = trim($newKey ?? '');
        if ($newKey !== '') {
            Validator::make(['key' => $newKey], ['key' => ['string', 'min:8', 'max:512', 'regex:/^[A-Za-z0-9_-]+$/']])->validate();
            $stored['api_key_encrypted'] = Crypt::encryptString($newKey);
        }
        if ($deleteKey) {
            unset($stored['api_key_encrypted']);
            $validated['enabled'] = false;
        }
        if (! isset($stored['api_key_encrypted'])) {
            $validated['enabled'] = false;
        }
        Setting::setValue('routing', 'tomtom', array_replace($stored, $validated));
    }

    public static function apiKey(): ?string
    {
        return self::decryptKey(self::stored()['api_key_encrypted'] ?? null);
    }

    private static function decryptKey(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    private static function stored(): array
    {
        if (! Schema::hasTable('settings')) {
            return [];
        }

        return Setting::getValueUncached('routing', 'tomtom') ?? [];
    }
}
