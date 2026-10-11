<?php

namespace App\Services\Routing;

use App\Support\Routing\TomTomSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** A dedicated encrypted cache shared by this application's PHP workers. */
final class ServerRoutingCache
{
    private const VERSION = 1;

    private const ENTRY_LIMITS = ['tile' => 1048576, 'summary' => 4194304, 'geometry' => 4194304];

    private const HEADER_KEYS = ['etag', 'last_modified', 'cache_control', 'date', 'expires', 'age'];

    public function __construct(private readonly ?string $directory = null) {}

    /** Returns provider data and UTC cache timestamps; never a expired or corrupt entry. */
    public function get(string $kind, string $identity): ?array
    {
        try {
            $settings = TomTomSettings::all();
            if (! $this->enabled($kind, $settings) || ! $this->validIdentity($identity)) {
                return null;
            }
            $root = $this->root(false);
            if ($root === null) {
                return null;
            }
            $entry = $this->read($this->path($root, $kind, $identity), $kind);
            if ($entry === null || $this->expired($entry, $this->ttl($kind, $settings))) {
                return null;
            }

            return $entry;
        } catch (Throwable) {
            // Never include decrypted payloads, request identities or file errors in logs.
            return null;
        }
    }

    /** Unknown responses, oversized entries and zero lifetimes never enter the cache. */
    public function put(string $kind, string $identity, array $data, int $ttlSeconds, array $metadata = []): bool
    {
        try {
            $settings = TomTomSettings::all();
            if (! $this->enabled($kind, $settings) || ! $this->validIdentity($identity)) {
                return false;
            }
            $ttlSeconds = min($ttlSeconds, $this->ttl($kind, $settings));
            $clean = $this->providerData($kind, $data);
            if ($ttlSeconds < 1 || $clean === null) {
                return false;
            }
            $root = $this->root(true);
            if ($root === null) {
                return false;
            }
            $now = CarbonImmutable::now('UTC');
            $entry = [
                'version' => self::VERSION, 'kind' => $kind, 'identity_hash' => hash('sha256', $identity),
                'fetched_at' => $now->toIso8601String(), 'expires_at' => $now->addSeconds($ttlSeconds)->toIso8601String(),
                'data' => $clean, 'metadata' => $this->headers($metadata),
            ];
            if ($kind === 'tile') {
                $entry['data']['body'] = base64_encode($entry['data']['body']);
            }
            $encrypted = Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR));
            if (strlen($encrypted) > self::ENTRY_LIMITS[$kind]) {
                return false;
            }

            return $this->namespaceLock($root, function () use ($root, $kind, $identity, $encrypted, $settings): bool {
                $target = $this->path($root, $kind, $identity);
                if (is_link($target)) {
                    return false;
                }
                $cap = min(1024, max(1, (int) ($settings['server_cache_max_mb'] ?? 128))) * 1048576;
                $inventory = $this->inventory($root);
                $bytes = 0;
                $live = [];
                foreach ($inventory as $item) {
                    if ($item['expired'] || $item['path'] === $target) {
                        if (! $this->remove($root, $item['path'])) {
                            return false;
                        }
                    } else {
                        $bytes += $item['bytes'];
                        $live[] = $item;
                    }
                }
                usort($live, fn (array $a, array $b): int => $a['fetched'] <=> $b['fetched']);
                foreach ($live as $item) {
                    if ($bytes + strlen($encrypted) <= $cap) {
                        break;
                    }
                    if (! $this->remove($root, $item['path'])) {
                        return false;
                    }
                    $bytes -= $item['bytes'];
                }
                if ($bytes + strlen($encrypted) > $cap) {
                    return false;
                }

                return $this->atomicWrite($root, $target, $encrypted);
            }, false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Consumers recheck get() inside this callback before making a provider request. */
    public function synchronized(string $kind, string $identity, Closure $callback): mixed
    {
        return $this->synchronizedMany($kind, [$identity], $callback);
    }

    /** Sorted stripe acquisition protects overlapping provider batches without deadlocks. */
    public function synchronizedMany(string $kind, array $identities, Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Routing cache producers require an outside-transaction preflight.');
        }
        $locks = [];
        try {
            $settings = TomTomSettings::all();
            $root = $this->enabled($kind, $settings) ? $this->root(true) : null;
            if ($root !== null && $identities !== []) {
                $stripes = [];
                foreach ($identities as $identity) {
                    if (! is_string($identity) || ! $this->validIdentity($identity)) {
                        $stripes = [];
                        break;
                    }
                    $stripes[(int) hexdec(substr(hash('sha256', $kind.':'.$identity), 0, 2)) % 64] = true;
                }
                ksort($stripes);
                $deadline = microtime(true) + 1.0;
                foreach (array_keys($stripes) as $stripe) {
                    $handle = $this->acquire($root.'/stripe-'.str_pad((string) $stripe, 2, '0', STR_PAD_LEFT).'.lock', $deadline);
                    if ($handle === null) {
                        $this->release($locks);
                        break;
                    }
                    $locks[] = $handle;
                }
            }
        } catch (Throwable) {
            $this->release($locks);
        }
        try {
            // Callback exceptions propagate and must never cause a second provider request.
            return $callback();
        } finally {
            $this->release($locks);
        }
    }

    public function forget(string $kind, string $identity): bool
    {
        try {
            $root = isset(self::ENTRY_LIMITS[$kind]) && $this->validIdentity($identity) ? $this->root(false) : null;

            return $root !== null && $this->namespaceLock($root, fn (): bool => $this->remove($root, $this->path($root, $kind, $identity)), false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Expiry denies reads immediately; this maintenance step removes the physical files. */
    public function purgeExpired(): int
    {
        return $this->maintain(false);
    }

    /** Removes entries only; unrelated application caches and persistent locks are untouched. */
    public function clear(): int
    {
        return $this->maintain(true);
    }

    /** Aggregate metadata only, with no paths, cache identities or provider coordinates. */
    public function stats(): array
    {
        $stats = ['entries' => 0, 'bytes' => 0, 'expired_entries' => 0];
        try {
            $root = $this->root(false);
            foreach ($root === null ? [] : $this->inventory($root) as $item) {
                $stats['entries']++;
                $stats['bytes'] += $item['bytes'];
                $stats['expired_entries'] += (int) $item['expired'];
            }
        } catch (Throwable) {
            // An inaccessible cache must not affect planning or settings access.
        }

        return $stats;
    }

    private function maintain(bool $all): int
    {
        try {
            $root = $this->root(false);
            if ($root === null) {
                return 0;
            }

            return $this->namespaceLock($root, function () use ($root, $all): int {
                $removed = 0;
                foreach ($this->inventory($root) as $item) {
                    if (($all || $item['expired']) && $this->remove($root, $item['path'])) {
                        $removed++;
                    }
                }

                return $removed;
            }, 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function enabled(string $kind, array $settings): bool
    {
        if (! isset(self::ENTRY_LIMITS[$kind]) || ! ($settings['server_cache_enabled'] ?? true)) {
            return false;
        }

        return (bool) ($settings[$kind === 'tile' ? 'server_tile_cache_enabled' : 'server_route_cache_enabled'] ?? true);
    }

    private function ttl(string $kind, array $settings): int
    {
        $key = match ($kind) {
            'tile' => 'server_tile_cache_minutes',
            'summary' => 'server_route_cache_minutes',
            'geometry' => 'server_geometry_cache_minutes',
        };
        $default = match ($kind) {
            'tile' => 60,
            'summary' => 5,
            'geometry' => 15,
        };

        return min(86400, max(0, (int) ($settings[$key] ?? $default)) * 60);
    }

    private function validIdentity(string $identity): bool
    {
        return $identity !== '' && strlen($identity) <= 32768;
    }

    private function providerData(string $kind, array $data): ?array
    {
        if (($data['status'] ?? null) !== 'known') {
            return null;
        }
        if ($kind === 'tile') {
            if (! is_string($data['body'] ?? null) || $data['body'] === '' || ! in_array($data['content_type'] ?? null, ['image/png', 'image/jpeg'], true)) {
                return null;
            }

            return ['status' => 'known', 'body' => $data['body'], 'content_type' => $data['content_type']];
        }
        foreach (['duration_seconds', 'distance_meters'] as $key) {
            if (! is_numeric($data[$key] ?? null) || ! is_finite((float) $data[$key]) || $data[$key] < 0 || $data[$key] > PHP_INT_MAX) {
                return null;
            }
        }
        $clean = ['status' => 'known', 'reason' => null, 'duration_seconds' => (int) ceil($data['duration_seconds']), 'distance_meters' => (int) ceil($data['distance_meters'])];
        if ($kind === 'geometry') {
            if (! is_array($data['points'] ?? null) || count($data['points']) < 2 || count($data['points']) > 50000) {
                return null;
            }
            $clean['points'] = [];
            foreach ($data['points'] as $point) {
                if (! is_array($point) || count($point) !== 2 || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])
                    || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1]) || abs((float) $point[0]) > 90 || abs((float) $point[1]) > 180) {
                    return null;
                }
                $clean['points'][] = [(float) $point[0], (float) $point[1]];
            }
        }

        return $clean;
    }

    private function headers(array $metadata): array
    {
        $headers = [];
        foreach (self::HEADER_KEYS as $key) {
            if (is_string($metadata[$key] ?? null) && strlen($metadata[$key]) <= 4096 && ! preg_match('/[\r\n]/', $metadata[$key])) {
                $headers[$key] = $metadata[$key];
            }
        }

        return $headers;
    }

    /** Resolve each component beneath framework; never follow a cache-directory symlink. */
    private function root(bool $create): ?string
    {
        $base = realpath(storage_path('framework'));
        if ($base === false) {
            return null;
        }
        $base = str_replace('\\', '/', $base);
        $candidate = str_replace('\\', '/', $this->directory ?? storage_path('framework/cache/railtime-routing'));
        if (str_contains($candidate, '/../') || str_contains($candidate, '/./') || ! $this->inside($candidate, $base)) {
            return null;
        }
        $relative = substr($candidate, strlen(rtrim($base, '/')) + 1);
        if ($relative === '') {
            return null;
        }
        $root = $base;
        foreach (explode('/', trim($relative, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
            $root .= '/'.$part;
            if (is_link($root)) {
                return null;
            }
            if (! is_dir($root)) {
                if (! $create || file_exists($root) || ! @mkdir($root, 0700)) {
                    return null;
                }
                @chmod($root, 0700);
            }
            $resolved = realpath($root);
            if ($resolved === false || ! $this->inside(str_replace('\\', '/', $resolved), $base)) {
                return null;
            }
        }

        return $root;
    }

    private function inside(string $path, string $base): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $base = strtolower($base);
        }

        return str_starts_with($path, rtrim($base, '/').'/');
    }

    private function path(string $root, string $kind, string $identity): string
    {
        return $root.'/entry-'.$kind.'-'.hash('sha256', $identity).'.cache';
    }

    private function read(string $path, string $kind): ?array
    {
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > self::ENTRY_LIMITS[$kind]) {
            return null;
        }
        $encrypted = @file_get_contents($path);
        if ($encrypted === false || strlen($encrypted) > self::ENTRY_LIMITS[$kind]) {
            return null;
        }
        try {
            $entry = json_decode(Crypt::decryptString($encrypted), true, 64, JSON_THROW_ON_ERROR);
            if (($entry['version'] ?? null) !== self::VERSION || ($entry['kind'] ?? null) !== $kind
                || basename($path) !== 'entry-'.$kind.'-'.($entry['identity_hash'] ?? '').'.cache'
                || ! is_array($entry['data'] ?? null) || ! is_array($entry['metadata'] ?? null)) {
                return null;
            }
            $fetched = CarbonImmutable::parse($entry['fetched_at'])->utc()->getTimestamp();
            $expiry = CarbonImmutable::parse($entry['expires_at'])->utc()->getTimestamp();
            if ($expiry <= $fetched || $expiry - $fetched > 86400) {
                return null;
            }
            if ($kind === 'tile') {
                $body = base64_decode($entry['data']['body'] ?? '', true);
                if ($body === false) {
                    return null;
                }
                $entry['data']['body'] = $body;
            }
            $data = $this->providerData($kind, $entry['data']);
            if ($data === null) {
                return null;
            }

            return [
                'kind' => $kind, 'fetched_at' => $entry['fetched_at'], 'expires_at' => $entry['expires_at'],
                'data' => $data, 'metadata' => $this->headers($entry['metadata']),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function expired(array $entry, ?int $currentTtl = null): bool
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $fetched = CarbonImmutable::parse($entry['fetched_at'])->getTimestamp();
        $expires = CarbonImmutable::parse($entry['expires_at'])->getTimestamp();

        return $fetched > $now || $now >= min($expires, $fetched + ($currentTtl ?? 86400));
    }

    private function inventory(string $root): array
    {
        $items = [];
        foreach (glob($root.'/entry-*.cache') ?: [] as $path) {
            if (! preg_match('/^entry-(tile|summary|geometry)-[a-f0-9]{64}\.cache$/', basename($path)) || is_link($path) || ! is_file($path)) {
                continue;
            }
            $kind = explode('-', basename($path))[1];
            $entry = $this->read($path, $kind);
            $items[] = [
                'path' => $path, 'bytes' => (int) (@filesize($path) ?: 0),
                'expired' => $entry === null || $this->expired($entry),
                'fetched' => $entry === null ? 0 : CarbonImmutable::parse($entry['fetched_at'])->getTimestamp(),
            ];
        }

        return $items;
    }

    private function remove(string $root, string $path): bool
    {
        if (! $this->inside(str_replace('\\', '/', $path), $root) || dirname($path) !== $root || is_link($path)) {
            return false;
        }

        return ! file_exists($path) || (is_file($path) && @unlink($path));
    }

    private function atomicWrite(string $root, string $target, string $encrypted): bool
    {
        $temporary = $root.'/write-'.bin2hex(random_bytes(12)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            return false;
        }
        @chmod($temporary, 0600);
        $offset = 0;
        try {
            while ($offset < strlen($encrypted)) {
                $written = @fwrite($handle, substr($encrypted, $offset));
                if ($written === false || $written < 1) {
                    return false;
                }
                $offset += $written;
            }
            if (! @fflush($handle)) {
                return false;
            }
            fclose($handle);
            $handle = null;
            $written = @rename($temporary, $target);
            if ($written) {
                @chmod($target, 0600);
            }

            return $written;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function namespaceLock(string $root, Closure $callback, mixed $fallback): mixed
    {
        $lock = $this->acquire($root.'/.namespace.lock', microtime(true) + 1.0);
        if ($lock === null) {
            return $fallback;
        }
        try {
            return $callback();
        } finally {
            $locks = [$lock];
            $this->release($locks);
        }
    }

    private function acquire(string $path, float $deadline): mixed
    {
        if (is_link($path)) {
            return null;
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            return null;
        }
        @chmod($path, 0600);
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        fclose($handle);

        return null;
    }

    private function release(array &$locks): void
    {
        foreach (array_reverse($locks) as $handle) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        $locks = [];
    }
}
