<?php

namespace App\Models;

use Minishlink\WebPush\ContentEncoding;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

class PushSubscription extends BasePushSubscription
{
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'device_name',
        'platform',
        'browser',
        'user_agent_hash',
        'last_seen_at',
        'last_success_at',
        'last_failure_at',
        'failure_count',
        'revoked_at',
    ];

    protected $casts = [
        'endpoint' => 'encrypted',
        'public_key' => 'encrypted',
        'auth_token' => 'encrypted',
        'content_encoding' => ContentEncoding::class,
        'last_seen_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'revoked_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            if (filled($subscription->endpoint)) {
                $subscription->endpoint_hash = self::hashEndpoint($subscription->endpoint);
            }
        });
    }

    public static function findByEndpoint(string $endpoint): ?static
    {
        return static::firstWhere('endpoint_hash', static::hashEndpoint($endpoint));
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
