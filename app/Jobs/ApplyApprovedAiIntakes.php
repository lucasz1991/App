<?php

namespace App\Jobs;

use App\Services\Operations\AiIntakeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ApplyApprovedAiIntakes implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 660;

    public function __construct(public ?int $intakeId = null)
    {
        $this->onConnection('ai_disposition')->onQueue('ai-disposition');
    }

    public function uniqueId(): string
    {
        return 'apply-approved:'.($this->intakeId ?? 'all');
    }

    public function handle(AiIntakeService $service): void
    {
        $service->applyApprovedProposals($this->intakeId);
    }
}
