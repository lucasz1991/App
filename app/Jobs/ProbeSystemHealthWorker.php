<?php

namespace App\Jobs;

use App\Services\SystemHealth\BoundedInfrastructureConnections;
use App\Services\SystemHealth\SystemHealthStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\DatabaseJob;

/** No business side effect: a genuinely reserved worker job acknowledges one private nonce. */
class ProbeSystemHealthWorker implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $probeKey,
        public readonly string $nonce,
        public readonly string $expectedConnection,
        public readonly string $expectedQueue,
    ) {
        $this->onConnection($expectedConnection)->onQueue($expectedQueue);
    }

    public function handle(SystemHealthStore $store): void
    {
        // A direct handle(), sync dispatch, or another queue is not a worker receipt.
        if (! $this->job instanceof DatabaseJob
            || $this->job->getConnectionName() !== $this->expectedConnection
            || $this->job->getQueue() !== $this->expectedQueue
            || ! $this->job->getJobId() || $this->job->attempts() < 1) {
            return;
        }
        $lock = $store->lock($this->probeKey.':mutation', 60);
        $lock->block(5);
        try {
            $probe = $store->get($this->probeKey);
            if (! $probe || ! hash_equals($probe['nonce'] ?? '', $this->nonce)
                || ($probe['deadline'] ?? 0) <= now()->timestamp || ! empty($probe['acknowledged_at'])
                || ($probe['job_id'] ?? null) !== (string) $this->job->getJobId()) {
                return;
            }
            $configuration = config('queue.connections.'.$this->expectedConnection);
            if (($configuration['driver'] ?? null) !== 'database') {
                return;
            }
            $reserved = app(BoundedInfrastructureConnections::class)->database(
                $configuration['connection'] ?? null,
                fn (Connection $database): bool => $database->table($configuration['table'] ?? 'jobs')
                    ->where('id', $probe['job_id'])->where('queue', $this->expectedQueue)
                    ->whereNotNull('reserved_at')->where('attempts', '>=', 1)->exists(),
            );
            if ($reserved !== true || ($probe['deadline'] ?? 0) <= now()->timestamp) {
                return;
            }
            $probe['acknowledged_at'] = now()->toIso8601String();
            $store->put($this->probeKey, $probe, 900);
        } finally {
            $lock->release();
        }
    }
}
