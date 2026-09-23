<?php

namespace App\Jobs\Dropbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

class ProbeDropboxQueue implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 10;

    public function __construct(public string $queueName) {}

    public function handle(): void
    {
        Cache::store(config('dropbox.lock_store'))->put('dropbox-heartbeat:'.$this->queueName, now()->timestamp, 300);
    }
}
