<?php

namespace App\Support\Pwa;

use InvalidArgumentException;

final class PwaIcon
{
    /**
     * All icons referenced by the manifest, PWA head or push payload.
     *
     * @var array<string, int>
     */
    public const DIMENSIONS = [
        'pwa-192.png' => 192,
        'pwa-512.png' => 512,
        'pwa-maskable-512.png' => 512,
        'apple-touch-icon-180.png' => 180,
        'push-badge-96.png' => 96,
    ];

    public static function supports(string $fileName): bool
    {
        return array_key_exists($fileName, self::DIMENSIONS);
    }

    public static function fallback(string $fileName): string
    {
        if (! self::supports($fileName)) {
            throw new InvalidArgumentException('Unsupported PWA icon.');
        }

        $size = self::DIMENSIONS[$fileName];
        $isBadge = $fileName === 'push-badge-96.png';
        $rawImage = '';

        for ($y = 0; $y < $size; $y++) {
            $rawImage .= "\x00";

            for ($x = 0; $x < $size; $x++) {
                [$red, $green, $blue, $alpha] = self::pixel($x, $y, $size, $isBadge);
                $rawImage .= pack('C4', $red, $green, $blue, $alpha);
            }
        }

        $header = pack('N2C5', $size, $size, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', $header)
            .self::chunk('IDAT', gzcompress($rawImage, 9))
            .self::chunk('IEND', '');
    }

    /**
     * @return array{int, int, int, int}
     */
    private static function pixel(int $x, int $y, int $size, bool $isBadge): array
    {
        $normalizedX = $x / $size;
        $normalizedY = $y / $size;
        $mark = self::isRailtimeMark($normalizedX, $normalizedY);

        if ($isBadge) {
            return $mark
                ? [255, 255, 255, 255]
                : [255, 255, 255, 0];
        }

        if ($mark) {
            return [255, 255, 255, 255];
        }

        $highlight = max(0, 1 - hypot($normalizedX - 0.25, $normalizedY - 0.2) * 1.9);

        return [
            min(255, 205 + (int) round($highlight * 39)),
            (int) round($highlight * 10),
            38 + (int) round($highlight * 18),
            255,
        ];
    }

    private static function isRailtimeMark(float $x, float $y): bool
    {
        $stem = $x >= 0.27 && $x <= 0.37 && $y >= 0.2 && $y <= 0.8;
        $top = $x >= 0.32 && $x <= 0.59 && $y >= 0.2 && $y <= 0.3;
        $middle = $x >= 0.32 && $x <= 0.57 && $y >= 0.45 && $y <= 0.55;
        $bowl = $x >= 0.55 && $x <= 0.67 && $y >= 0.28 && $y <= 0.47;
        $legCenter = 0.36 + (($y - 0.5) * 0.72);
        $leg = $y >= 0.5 && $y <= 0.81 && abs($x - $legCenter) <= 0.055;

        return $stem || $top || $middle || $bowl || $leg;
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data))
            .$type
            .$data
            .pack('N', crc32($type.$data));
    }
}
