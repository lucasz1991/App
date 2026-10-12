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

    private array $producerGenerations = [];

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
            if ($entry === null) {
                $this->discardUnreadable($root, $kind, $identity);

                return null;
            }
            if ($this->expired($entry, $this->ttl($kind, $settings))) {
                return null;
            }

            return $entry;
        } catch (Throwable) {
            // Never include decrypted payloads, request identities or file errors in logs.
            return null;
        }
    }

    /** Unknown responses, oversized entries and zero lifetimes never enter the cache. */
    public function put(string $kind, string $identity, array $data, int $ttlSeconds, array $metadata = [], ?string $expectedGeneration = null): bool
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
            $expectedGeneration ??= $this->producerGenerations !== [] ? end($this->producerGenerations) : $this->generation();
            if ($expectedGeneration === null) {
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
            $header = array_intersect_key($entry, array_flip(['version', 'kind', 'identity_hash', 'fetched_at', 'expires_at']));
            $encrypted = Crypt::encryptString(json_encode($header, JSON_THROW_ON_ERROR))."\n".Crypt::encryptString(json_encode($entry, JSON_THROW_ON_ERROR));
            if (strlen($encrypted) > self::ENTRY_LIMITS[$kind]) {
                return false;
            }

            return $this->namespaceLock($root, function () use ($root, $kind, $identity, $encrypted, $expectedGeneration, $entry): bool {
                $settings = TomTomSettings::all();
                $state = $this->state($root);
                if ($state === null || ! hash_equals($state['generation'], $expectedGeneration) || ! $this->enabled($kind, $settings)) {
                    return false;
                }
                $target = $this->path($root, $kind, $identity);
                if (is_link($target)) {
                    return false;
                }
                $cap = min(1024, max(1, (int) ($settings['server_cache_max_mb'] ?? 128))) * 1048576;
                if (! $this->markDirty($root)) {
                    return false;
                }
                $oldSize = is_file($target) ? (int) filesize($target) : 0;
                if ($cap < $state['bytes'] - $oldSize + strlen($encrypted) || ($state['next_expiry'] > 0 && $state['next_expiry'] <= CarbonImmutable::now('UTC')->getTimestamp())) {
                    $live = [];
                    foreach ($this->inventory($root) as $item) {
                        if ($item['expired']) {
                            if (! $this->remove($root, $item['path'])) {
                                return false;
                            }
                        } else {
                            $live[] = $item;
                        }
                    }
                    $state = $this->aggregate($live, $state['generation']);
                    $oldSize = is_file($target) ? (int) filesize($target) : 0;
                    if ($cap < $state['bytes'] - $oldSize + strlen($encrypted)) {
                        // Evict a batch to avoid a full directory scan for each subsequent tile.
                        $lowWater = max(strlen($encrypted), (int) floor($cap * 0.9));
                        usort($live, fn (array $a, array $b): int => $a['fetched'] <=> $b['fetched']);
                        foreach ($live as $index => $item) {
                            if ($lowWater >= $state['bytes'] - $oldSize + strlen($encrypted)) {
                                break;
                            }
                            if ($item['path'] === $target) {
                                continue;
                            }
                            if (! $this->remove($root, $item['path'])) {
                                return false;
                            }
                            unset($live[$index]);
                            $state['bytes'] -= $item['bytes'];
                            $state['entries']--;
                        }
                        $state = $this->aggregate($live, $state['generation']);
                    }
                }
                if ($cap < $state['bytes'] - $oldSize + strlen($encrypted)) {
                    return false;
                }
                $existed = is_file($target);
                if (! $this->atomicWrite($root, $target, $encrypted)) {
                    // A dirty marker makes the next worker rebuild aggregates after any partial write.
                    return false;
                }
                $state['bytes'] += strlen($encrypted) - $oldSize;
                $state['entries'] += $existed ? 0 : 1;
                $expiry = CarbonImmutable::parse($entry['expires_at'])->getTimestamp();
                $state['next_expiry'] = $state['next_expiry'] > 0 ? min($state['next_expiry'], $expiry) : $expiry;

                return $this->saveState($root, $state);
            }, false);
        } catch (Throwable) {
            return false;
        }
    }

    /** Consumers recheck get() inside this callback before making a provider request. */
    public function synchronized(string $kind, string $identity, Closure $callback, ?Closure $onContention = null): mixed
    {
        return $this->synchronizedMany($kind, [$identity], $callback, $onContention);
    }

    /** Sorted stripe acquisition protects overlapping provider batches without deadlocks. */
    public function synchronizedMany(string $kind, array $identities, Closure $callback, ?Closure $onContention = null): mixed
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Routing cache producers require an outside-transaction preflight.');
        }
        $locks = [];
        $generation = null;
        $contended = false;
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
                        $contended = true;
                        break;
                    }
                    $locks[] = $handle;
                }
                $generation = $this->generation();
            }
        } catch (Throwable) {
            $this->release($locks);
        }
        if ($contended && $onContention !== null) {
            // A second reader may reuse the completed entry, but must not start
            // another paid request while its producer still owns this stripe.
            return $onContention();
        }
        $this->producerGenerations[] = $generation;
        try {
            // Callback exceptions propagate and must never cause a second provider request.
            return $callback();
        } finally {
            array_pop($this->producerGenerations);
            $this->release($locks);
        }
    }

    /** A clear changes this token, rejecting responses from already-running producers. */
    public function generation(): ?string
    {
        try {
            $root = $this->root(true);

            return $root === null ? null : $this->namespaceLock($root, fn (): ?string => $this->state($root)['generation'] ?? null, null);
        } catch (Throwable) {
            return null;
        }
    }

    public function forget(string $kind, string $identity): bool
    {
        try {
            $root = isset(self::ENTRY_LIMITS[$kind]) && $this->validIdentity($identity) ? $this->root(false) : null;

            return $root !== null && $this->namespaceLock($root, function () use ($root, $kind, $identity): bool {
                $state = $this->state($root);
                $path = $this->path($root, $kind, $identity);
                if ($state === null || ! $this->markDirty($root)) {
                    return false;
                }
                if (is_file($path) && ! is_link($path)) {
                    $state['bytes'] -= (int) filesize($path);
                    $state['entries']--;
                }
                if (! $this->remove($root, $path)) {
                    return false;
                }

                return $this->saveState($root, $state);
            }, false);
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
            if ($root !== null) {
                $stats = $this->namespaceLock($root, function () use ($root, $stats): array {
                    $state = $this->state($root);
                    if ($state === null) {
                        return $stats;
                    }
                    $stats['entries'] = $state['entries'];
                    $stats['bytes'] = $state['bytes'];
                    if ($state['next_expiry'] > 0 && $state['next_expiry'] <= CarbonImmutable::now('UTC')->getTimestamp()) {
                        foreach ($this->inventory($root) as $item) {
                            $stats['expired_entries'] += (int) $item['expired'];
                        }
                    }

                    return $stats;
                }, $stats);
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
                $state = $this->state($root);
                if ($state === null || ! $this->markDirty($root)) {
                    return 0;
                }
                $removed = 0;
                $remaining = [];
                foreach ($this->inventory($root) as $item) {
                    if (($all || $item['expired']) && $this->remove($root, $item['path'])) {
                        $removed++;
                    } else {
                        $remaining[] = $item;
                    }
                }
                // Interrupted atomic writes are never reused and have no active writer under this lock.
                foreach (glob($root.'/write-*.tmp') ?: [] as $path) {
                    if (preg_match('/^write-[a-f0-9]{24}\.tmp$/', basename($path))) {
                        $this->remove($root, $path);
                    }
                }
                $this->saveState($root, $this->aggregate($remaining, $all ? bin2hex(random_bytes(16)) : $state['generation']));

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
        $declaredBase = str_replace('\\', '/', storage_path('framework'));
        $candidate = $this->directory === null ? $base.'/cache/railtime-routing' : str_replace('\\', '/', $this->directory);
        if ($this->inside($candidate, $declaredBase)) {
            // Laravel may legitimately place its whole storage directory on a shared deployment volume.
            $candidate = $base.substr($candidate, strlen(rtrim($declaredBase, '/')));
        }
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
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $line = fgets($handle, 4096);
            $header = $this->decodeHeader($line, $path, $kind);
            $encrypted = stream_get_contents($handle, self::ENTRY_LIMITS[$kind] + 1);
            if ($header === null || $encrypted === false || strlen($encrypted) > self::ENTRY_LIMITS[$kind]) {
                return null;
            }
            $entry = json_decode(Crypt::decryptString($encrypted), true, 64, JSON_THROW_ON_ERROR);
            if (($entry['version'] ?? null) !== self::VERSION || ($entry['kind'] ?? null) !== $kind
                || basename($path) !== 'entry-'.$kind.'-'.($entry['identity_hash'] ?? '').'.cache'
                || array_intersect_key($entry, $header) !== $header
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
        } finally {
            fclose($handle);
        }
    }

    /** Maintenance reads a tiny authenticated header rather than decrypting provider bodies. */
    private function readHeader(string $path, string $kind): ?array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            return $this->decodeHeader(fgets($handle, 4096), $path, $kind);
        } finally {
            fclose($handle);
        }
    }

    private function decodeHeader(string|false $line, string $path, string $kind): ?array
    {
        if ($line === false || ! str_ends_with($line, "\n")) {
            return null;
        }
        try {
            $header = json_decode(Crypt::decryptString(trim($line)), true, 16, JSON_THROW_ON_ERROR);
            if (($header['version'] ?? null) !== self::VERSION || ($header['kind'] ?? null) !== $kind
                || basename($path) !== 'entry-'.$kind.'-'.($header['identity_hash'] ?? '').'.cache') {
                return null;
            }
            $fetched = CarbonImmutable::parse($header['fetched_at'])->getTimestamp();
            $expiry = CarbonImmutable::parse($header['expires_at'])->getTimestamp();

            return $expiry > $fetched && $expiry - $fetched <= 86400 ? $header : null;
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
        $settings = TomTomSettings::all();
        foreach (glob($root.'/entry-*.cache') ?: [] as $path) {
            if (! preg_match('/^entry-(tile|summary|geometry)-[a-f0-9]{64}\.cache$/', basename($path)) || is_link($path) || ! is_file($path)) {
                continue;
            }
            $kind = explode('-', basename($path))[1];
            $bytes = (int) (@filesize($path) ?: 0);
            $entry = $bytes > 0 && $bytes <= self::ENTRY_LIMITS[$kind] ? $this->readHeader($path, $kind) : null;
            $items[] = [
                'path' => $path, 'bytes' => $bytes,
                'expired' => $entry === null || $this->expired($entry, $this->ttl($kind, $settings)),
                'fetched' => $entry === null ? 0 : CarbonImmutable::parse($entry['fetched_at'])->getTimestamp(),
                'expiry' => $entry === null ? CarbonImmutable::now('UTC')->getTimestamp() : min(CarbonImmutable::parse($entry['expires_at'])->getTimestamp(), CarbonImmutable::parse($entry['fetched_at'])->addSeconds($this->ttl($kind, $settings))->getTimestamp()),
            ];
        }

        return $items;
    }

    /** The tiny aggregate state makes normal insertions and status reads constant work. */
    private function state(string $root): ?array
    {
        $path = $root.'/.state';
        if (is_link($path) || is_link($root.'/.dirty')) {
            return null;
        }
        $state = null;
        try {
            if (is_file($path) && filesize($path) <= 4096) {
                $state = json_decode(Crypt::decryptString(file_get_contents($path)), true, 16, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable) {
            $state = null;
        }
        if (! is_array($state) || ! preg_match('/^[a-f0-9]{32}$/', $state['generation'] ?? '')
            || ! is_int($state['entries'] ?? null) || $state['entries'] < 0
            || ! is_int($state['bytes'] ?? null) || $state['bytes'] < 0
            || ! is_int($state['next_expiry'] ?? null)) {
            $state = null;
        }
        if ($state === null || file_exists($root.'/.dirty')) {
            $state = $this->aggregate($this->inventory($root), $state['generation'] ?? bin2hex(random_bytes(16)));
            if (! $this->saveState($root, $state)) {
                return null;
            }
        }

        return $state;
    }

    private function aggregate(array $items, string $generation): array
    {
        $state = ['generation' => $generation, 'entries' => count($items), 'bytes' => 0, 'next_expiry' => 0];
        foreach ($items as $item) {
            $state['bytes'] += $item['bytes'];
            $state['next_expiry'] = $state['next_expiry'] > 0 ? min($state['next_expiry'], $item['expiry']) : $item['expiry'];
        }

        return $state;
    }

    private function markDirty(string $root): bool
    {
        $path = $root.'/.dirty';
        if (is_link($path)) {
            return false;
        }
        $written = @file_put_contents($path, '1', LOCK_EX);
        if ($written !== false) {
            @chmod($path, 0600);
        }

        return $written === 1;
    }

    private function saveState(string $root, array $state): bool
    {
        $encrypted = Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR));
        if (! $this->atomicWrite($root, $root.'/.state', $encrypted)) {
            return false;
        }

        return ! file_exists($root.'/.dirty') || (! is_link($root.'/.dirty') && @unlink($root.'/.dirty'));
    }

    private function remove(string $root, string $path): bool
    {
        if (! $this->inside(str_replace('\\', '/', $path), $root) || dirname($path) !== $root || is_link($path)) {
            return false;
        }

        return ! file_exists($path) || (is_file($path) && @unlink($path));
    }

    private function discardUnreadable(string $root, string $kind, string $identity): void
    {
        $path = $this->path($root, $kind, $identity);
        if (! is_file($path) || is_link($path)) {
            return;
        }
        $this->namespaceLock($root, function () use ($root, $kind, $path): void {
            // Another worker may already have replaced the failed read with a valid entry.
            if ($this->read($path, $kind) === null && $this->markDirty($root) && $this->remove($root, $path)) {
                $this->state($root);
            }
        }, null);
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
            if (is_link($target) || (file_exists($target) && (! is_file($target) || ! @unlink($target)))) {
                return false;
            }
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
