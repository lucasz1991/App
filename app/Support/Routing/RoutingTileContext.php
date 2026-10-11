<?php

namespace App\Support\Routing;

use Illuminate\Http\Request;

/** Opaque client-cache partition; contains no browser-visible credentials or IDs. */
final class RoutingTileContext
{
    public static function forBrowser(?Request $request = null): array
    {
        $request ??= request();
        $user = $request->user();
        if (! $user || ! $request->hasSession() || $request->session()->getId() === '' || ! is_string(config('app.key')) || config('app.key') === '') {
            return ['enabled' => false, 'scope' => ''];
        }
        $settings = TomTomSettings::all();
        $key = TomTomSettings::apiKey();
        $enabled = (bool) ($settings['tile_cache_enabled'] ?? true);
        $minutes = min(1440, max(0, (int) ($settings['tile_cache_minutes'] ?? 60)));
        $context = json_encode([
            'v' => 1, 'session' => $request->session()->getId(), 'user' => (string) $user->getAuthIdentifier(),
            'enabled' => (bool) $settings['enabled'], 'tile_cache_enabled' => $enabled, 'tile_cache_minutes' => $minutes,
            'credential' => $key === null ? null : hash('sha256', $key),
        ], JSON_THROW_ON_ERROR);

        return [
            'enabled' => $enabled && $minutes > 0 && (bool) $settings['enabled'] && $key !== null && RoutingSchema::ready(),
            'scope' => hash_hmac('sha256', $context, config('app.key')),
        ];
    }
}
