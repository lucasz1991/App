<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Private, expiring pilot evidence; never a published mail template or HTML preview. */
final class OutlookUniversalDiagnosticFixtureStore
{
    public const MAX_HTML_BYTES = 196608;

    public const MAX_FIXTURES = 3;

    public const MAX_REQUEST_BYTES = 262144;

    public static function allowed(User $user, ?string $mailbox = null): bool
    {
        return OutlookUniversalPayloadPresentation::isPilot($user)
            && strtolower(trim($mailbox ?? (string) $user->email)) === 'it-technik@rail-time.de';
    }

    public function put(User $user, array $fixture, array $metadata, ?string $mailbox = null): ?array
    {
        if (! self::allowed($user, $mailbox) || ! OutlookUniversalDiagnosticFixtureValidator::valid($fixture)) {
            return null;
        }
        // Retain only safe diagnostic metadata, never the HTTP request/binding.
        $boundary = $fixture['format'] === OutlookUniversalDiagnosticFixtureValidator::BOUNDARY_FORMAT;
        $metadata = array_intersect_key($metadata, array_flip(['host', 'code', 'phase', 'sourceLength', 'bodyLength', 'checkpoint', 'difference',
            ...($boundary ? ['step', 'composeType'] : [])]));
        $allowedCode = $boundary
            ? ($metadata['code'] ?? null) === 'U2E_NEW_DRAFT_REQUIRED' && ($metadata['phase'] ?? null) === 'preflight'
                && ($metadata['step'] ?? null) === 'snapshot.region'
                && in_array($metadata['composeType'] ?? null, ['reply', 'forward'], true)
                && in_array($metadata['host'] ?? null, ['OutlookIOS', 'OutlookAndroid', 'OutlookWebApp'], true)
            : ($metadata['code'] ?? null) === 'SIGNATURE_LAYOUT_UNCONFIRMED';
        if (! in_array($metadata['host'] ?? null, OutlookUniversalDiagnostics::HOSTS, true)
            || ! $allowedCode
            || ! in_array($metadata['phase'] ?? null, ['preflight', 'claim', 'header', 'write', 'readback', 'complete'], true)
            || ! OutlookUniversalDiagnosticDetails::valid(array_intersect_key($metadata, array_flip(['checkpoint', 'difference'])))) {
            return null;
        }
        foreach (['sourceLength', 'bodyLength'] as $key) {
            if (! array_key_exists($key, $metadata) || $metadata[$key] !== null && (! is_int($metadata[$key]) || $metadata[$key] < 0 || $metadata[$key] > 1000000)) {
                return null;
            }
        }
        try {
            $artifacts = $this->artifactsFor($user);
            foreach ($artifacts as $artifact) {
                $entry = $artifact['capture'];
                if ($entry['sha256'] === $fixture['sha256'] && $entry['expectedSha256'] === $fixture['expectedSha256']) {
                    return $entry;
                }
            }
            $id = bin2hex(random_bytes(16));
            $expires = now()->addDays(7);
            $entry = ['id' => $id, 'sha256' => $fixture['sha256'], 'expectedSha256' => $fixture['expectedSha256'],
                'sourceLength' => $fixture['sourceLength'], 'bytes' => strlen($fixture['html']) + strlen($fixture['expectedHtml']),
                'at' => now()->toIso8601String(), 'expiresAt' => $expires->toIso8601String()];
            // Three fixed slots bound storage under concurrent requests. Each
            // artifact has its own TTL; a later upload cannot extend an older one.
            $free = array_values(array_diff(range(0, self::MAX_FIXTURES - 1), array_column($artifacts, '_slot')));
            $slot = $free[0] ?? $artifacts[count($artifacts) - 1]['_slot'];
            $sequence = 1 + max([0, ...array_column($artifacts, 'sequence')]);
            if (Cache::put($this->key().':'.$slot, ['fixture' => $fixture, 'metadata' => $metadata, 'capture' => $entry, 'sequence' => $sequence], $expires) !== true) {
                return null;
            }

            return $entry;
        } catch (Throwable) {
            return null;
        }
    }

    public function listingFor(User $user): array
    {
        return array_map(static fn (array $artifact): array => $artifact['capture'], $this->artifactsFor($user));
    }

    public function get(User $user, string $id): ?array
    {
        if (! preg_match('/\A[0-9a-f]{32}\z/', $id)) {
            return null;
        }
        foreach ($this->artifactsFor($user) as $artifact) {
            if ($artifact['capture']['id'] === $id && is_array($artifact['fixture'] ?? null) && OutlookUniversalDiagnosticFixtureValidator::valid($artifact['fixture'])) {
                return array_intersect_key($artifact, array_flip(['fixture', 'metadata', 'capture']));
            }
        }

        return null;
    }

    /** Touch only the three known slots so FileStore removes expired files. */
    public function purgeExpired(): bool
    {
        try {
            for ($slot = 0; $slot < self::MAX_FIXTURES; $slot++) {
                Cache::get($this->key().':'.$slot);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function artifactsFor(User $user): array
    {
        if (! self::allowed($user)) {
            return [];
        }
        try {
            $artifacts = [];
            for ($slot = 0; $slot < self::MAX_FIXTURES; $slot++) {
                $artifact = Cache::get($this->key().':'.$slot);
                if (is_array($artifact) && is_array($artifact['capture'] ?? null) && preg_match('/\A[0-9a-f]{32}\z/', $artifact['capture']['id'] ?? '')
                    && is_string($artifact['capture']['expiresAt'] ?? null) && strtotime($artifact['capture']['expiresAt']) > now()->getTimestamp()) {
                    $artifacts[] = $artifact + ['_slot' => $slot];
                }
            }
            usort($artifacts, static fn (array $a, array $b): int => ($b['sequence'] ?? 0) <=> ($a['sequence'] ?? 0));

            return $artifacts;
        } catch (Throwable) {
            return [];
        }
    }

    private function key(): string
    {
        // A constant pilot namespace also permits expiry cleanup after an
        // account is renamed or deleted, without enumerating users or caches.
        return 'outlook:u2e:fixtures:v1:'.hash('sha256', 'it-technik@rail-time.de');
    }
}
