<?php

namespace App\Support\Operations;

/** A confirmed search selection, never an arbitrary client coordinate payload. */
final class RegionalOriginPoint
{
    public const PRECISIONS = [
        'Point Address' => 'address',
        'Address Range' => 'interpolated',
        'Street' => 'street',
        'Geography' => 'locality',
    ];

    private const KEYS = ['label', 'latitude', 'longitude', 'provider', 'provider_id', 'type', 'precision'];

    public static function valid(mixed $point, string $label): bool
    {
        if (! is_array($point) || count($point) !== count(self::KEYS) || array_diff(self::KEYS, array_keys($point)) !== []
            || ! is_string($point['label']) || trim($point['label']) === '' || $point['label'] !== trim($point['label'])
            || mb_strlen($point['label']) > 200 || $point['label'] !== $label
            || $point['provider'] !== 'tomtom'
            || ! is_string($point['provider_id']) || trim($point['provider_id']) === '' || mb_strlen($point['provider_id']) > 200
            || ! is_string($point['type']) || ! array_key_exists($point['type'], self::PRECISIONS)
            || $point['precision'] !== self::PRECISIONS[$point['type']]) {
            return false;
        }

        foreach (['latitude' => [47, 56], 'longitude' => [5, 16]] as $key => [$minimum, $maximum]) {
            if ((! is_int($point[$key]) && ! is_float($point[$key])) || ! is_finite((float) $point[$key])
                || $point[$key] < $minimum || $point[$key] > $maximum) {
                return false;
            }
        }

        return true;
    }

    public static function description(array $point): string
    {
        return match ($point['precision']) {
            'address' => 'bestätigter Adresse',
            'interpolated' => 'bestätigter Adresslage (interpoliert)',
            'street' => 'bestätigter Straße (ungefähre Lage)',
            'locality' => 'bestätigter Ortslage (ungefähr)',
        };
    }
}
