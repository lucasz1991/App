<?php

namespace App\Support;

final class MarketingBrandAssets
{
    /** @var array<string, string> */
    private const MANIFEST = [
        '/rt-brand/rt-logo.svg' => 'image/svg+xml',
        '/rt-brand/img/logo-txt.png' => 'image/png',
        '/rt-brand/img/logo-horizontal.png' => 'image/png',
        '/rt-brand/img/logo-horizontal-darkbg.png' => 'image/png',
        '/rt-brand/img/hero-railtime.jpg' => 'image/jpeg',
        '/rt-brand/img/wagenmeister-pruefung.jpg' => 'image/jpeg',
        '/rt-brand/img/wagenmeister-team.webp' => 'image/webp',
        '/rt-brand/img/deutschland-netzwerk.png' => 'image/png',
        '/rt-brand/fonts/manrope-latin.woff2' => 'font/woff2',
        '/rt-brand/fonts/space-mono-700-latin.woff2' => 'font/woff2',
    ];

    /** @return array<string, string> */
    public static function manifest(): array
    {
        return self::MANIFEST;
    }

    public static function allows(string $publicPath): bool
    {
        return array_key_exists($publicPath, self::MANIFEST);
    }

    public static function absolutePath(string $publicPath): ?string
    {
        if (! self::allows($publicPath)) {
            return null;
        }

        return public_path(ltrim($publicPath, '/'));
    }
}
