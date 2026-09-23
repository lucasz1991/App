<?php

namespace App\Services\Dropbox;

use App\Jobs\Dropbox\ProbeDropboxQueue;
use Illuminate\Support\Facades\Cache;

class SyncHealth
{
    public const QUEUES = ['dropbox-events', 'dropbox-import', 'dropbox-export'];

    public function probe(): void
    {
        foreach (self::QUEUES as $queue) {
            ProbeDropboxQueue::dispatch($queue)->onConnection(config('dropbox.queue_connection'))->onQueue($queue);
        }
    }

    public function read(): array
    {
        try {
            $store = Cache::store(config('dropbox.lock_store'));
            $lock = $store->lock('dropbox-health-probe', 5);
            $ok = $lock->get();
            if ($ok) {
                $lock->release();
            }
            $workers = [];
            foreach (self::QUEUES as $queue) {
                $stamp = $store->get('dropbox-heartbeat:'.$queue);
                $workers[$queue] = $stamp && $stamp >= now()->subMinutes(3)->timestamp;
            }

            return ['redis' => true, 'workers' => $workers, 'ready' => ! in_array(false, $workers, true)];
        } catch (\Throwable) {
            return ['redis' => false, 'workers' => array_fill_keys(self::QUEUES, false), 'ready' => false];
        }
    }
}
