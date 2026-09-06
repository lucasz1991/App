<?php

namespace App\Services\SystemHealth;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/** Private, host-local cache. Never uses or flushes the cache being diagnosed. */
class SystemHealthStore
{
    private Repository $cache;

    public function __construct()
    {
        $path = config('system_health.path', storage_path('framework/cache/system-health'));
        $store = new FileStore(new Filesystem, $path, 0600, false);
        $store->setLockDirectory($path.'/locks');
        $this->cache = new Repository($store);
    }

    public function assertWritable(): void
    {
        $key = 'storage-probe:'.Str::uuid();
        try {
            $this->put($key, ['probe' => $key], 30);
            if (($this->get($key)['probe'] ?? null) !== $key) {
                throw new RuntimeException('Diagnostic store unavailable.');
            }
        } finally {
            if (! $this->cache->forget($key)) {
                throw new RuntimeException('Diagnostic store cleanup failed.');
            }
        }
    }

    public function get(string $key): ?array
    {
        $result = $this->cache->get($key);

        return is_array($result) ? $result : null;
    }

    public function put(string $key, array $value, int $seconds = 86400): void
    {
        if (! $this->cache->put($key, $value, $seconds)) {
            throw new RuntimeException('Diagnostic store unavailable.');
        }
    }

    public function lock(string $key, int $seconds = 180): Lock
    {
        return $this->cache->getStore()->lock($key, $seconds);
    }

    /** All writers share a short mutation lock, including after a probe lease expires. */
    public function putResult(string $id, array $record, ?string $expectedRun = null): bool
    {
        return (bool) $this->lock('state:'.$id, 10)->block(2, function () use ($id, $record, $expectedRun): bool {
            if ($expectedRun !== null && ($this->get('result:'.$id)['row']['run_id'] ?? null) !== $expectedRun) {
                return false;
            }
            $this->put('result:'.$id, $record);

            return true;
        });
    }
}
