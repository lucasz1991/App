<?php

namespace App\Support\Routing;

use DateTimeImmutable;
use DateTimeZone;

/** Permission for a private, volatile client cache; never a shared-server cache. */
final class TileCachePolicy
{
    public static function remainingSeconds(array $headers, int $ceilingSeconds, float $requestStartedAt, float $receivedAt): int
    {
        $ceilingSeconds = min(86400, max(0, $ceilingSeconds));
        if ($ceilingSeconds === 0 || ! is_finite($requestStartedAt) || ! is_finite($receivedAt) || $receivedAt < $requestStartedAt) {
            return 0;
        }
        $normalized = [];
        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                if (! is_string($value)) {
                    return 0;
                }
                $normalized[strtolower($name)][] = trim($value);
            }
        }
        $controls = $normalized['cache-control'] ?? [];
        if ($controls === [] || str_contains(strtolower(implode(',', $normalized['pragma'] ?? [])), 'no-cache')
            || preg_match('/(?:^|,)\s*\*(?:\s*,|$)/', implode(',', $normalized['vary'] ?? []))) {
            return 0;
        }
        $maxAge = null;
        foreach (explode(',', implode(',', $controls)) as $directive) {
            $parts = explode('=', trim($directive), 2);
            $name = strtolower(trim($parts[0]));
            if ($name === '' || in_array($name, ['no-store', 'no-cache'], true)) {
                return 0;
            }
            if ($name !== 'max-age') {
                continue;
            }
            if ($maxAge !== null || count($parts) !== 2 || ! preg_match('/^(?:([0-9]+)|"([0-9]+)")$/D', trim($parts[1]), $match)) {
                return 0;
            }
            $value = $match[1] !== '' ? $match[1] : $match[2];
            if (strlen($value) > 10 || (float) $value > 2147483647) {
                return 0;
            }
            $maxAge = (int) $value;
        }
        if ($maxAge === null || $maxAge === 0) {
            return 0;
        }
        $age = 0;
        if (isset($normalized['age'])) {
            if (count($normalized['age']) !== 1 || ! preg_match('/^[0-9]{1,10}$/D', $normalized['age'][0]) || (float) $normalized['age'][0] > 2147483647) {
                return 0;
            }
            $age = (int) $normalized['age'][0];
        }
        $date = $receivedAt;
        if (isset($normalized['date'])) {
            $date = count($normalized['date']) === 1 ? self::httpDate($normalized['date'][0]) : null;
            if ($date === null || $date > $receivedAt + 1) {
                return 0;
            }
        }
        // RFC 9111 age calculation includes upstream age and our response delay.
        $currentAge = max(0, $receivedAt - $date, $age + ($receivedAt - $requestStartedAt));
        $remaining = min($ceilingSeconds - ($receivedAt - $requestStartedAt), $maxAge - $currentAge);
        if (isset($normalized['expires'])) {
            $expires = count($normalized['expires']) === 1 ? self::httpDate($normalized['expires'][0]) : null;
            if ($expires === null) {
                return 0;
            }
            $remaining = min($remaining, $expires - $date - $currentAge);
        }

        return max(0, (int) floor($remaining));
    }

    private static function httpDate(string $value): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \\G\\M\\T', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('D, d M Y H:i:s \\G\\M\\T') === $value ? $date->getTimestamp() : null;
    }
}
