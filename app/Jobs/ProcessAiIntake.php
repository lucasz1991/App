<?php

namespace App\Jobs;

use App\Services\Operations\AiIntakeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAiIntake implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 660;

    public int $uniqueFor = 700;

    public function __construct(public int $intakeId)
    {
        $this->onConnection('ai_disposition')->onQueue('ai-disposition');
    }

    public function uniqueId(): string
    {
        return 'analyze:'.$this->intakeId;
    }

    public function handle(AiIntakeService $service): void
    {
        $service->analyze($this->intakeId);
    }
}
