<?php

namespace App\Jobs;

use App\Services\DeviceManagement\MicrosoftDeviceRuntime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/** Pure transport check: no Microsoft configuration, Graph or employee access. */
final class ProbeMicrosoftDeviceWorker implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId)
    {
        $this->onConnection(SyncMicrosoftDevices::CONNECTION);
        $this->onQueue(SyncMicrosoftDevices::QUEUE);
    }

    public function handle(MicrosoftDeviceRuntime $runtime): void
    {
        if ($runtime->claim($this->runId, $this->job ? (string) $this->job->getJobId() : null, 'probe', $this->job instanceof DatabaseJob ? $this->job : null)) {
            $runtime->finish($this->runId, 'worker_acknowledged', probe: true);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MicrosoftDeviceRuntime::class)->fail($this->runId, $exception instanceof TimeoutExceededException);
    }
}
