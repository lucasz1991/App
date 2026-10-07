<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ProbeAiDispositionWorker implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $nonce)
    {
        $this->onConnection('ai_disposition')->onQueue('ai-disposition');
    }

    public function handle(): void
    {
        if (Cache::get('ai-disposition:worker-probe') === $this->nonce) {
            Cache::put('ai-disposition:worker', ['checked_at' => now()->utc()->toIso8601String(), 'connection' => 'ai_disposition', 'queue' => 'ai-disposition'], 300);
            Cache::forget('ai-disposition:worker-probe');
        }
    }
}
