<?php

namespace App\Services\DeviceManagement\Support;

final class SafeProviderData
{
    /** @var list<string> */
    private const SAFE_SCALAR_KEYS = [
        'status',
        'message',
        'code',
        'version',
        'remote_url',
        'retry_after',
        'completed_at',
    ];

    /**
     * Keep only explicitly harmless scalar connector output. Inventories,
     * credentials, recovery keys, locations and arbitrary nested provider data
     * never enter RailTime through this helper.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    public static function summary(array $data): array
    {
        $safe = [];

        foreach (self::SAFE_SCALAR_KEYS as $key) {
            $value = $data[$key] ?? null;
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $safe[$key] = is_string($value)
                ? mb_substr(trim($value), 0, 500)
                : $value;
        }

        return $safe;
    }

    public static function error(string $message): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/([?&](?:token|key|secret|signature|password)=)[^&\s]+/i', '$1[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(token|secret|password|authorization)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message) ?? $message;

        return mb_substr(trim($message), 0, 1000);
    }
}
