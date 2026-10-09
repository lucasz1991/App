<?php

namespace App\Jobs;

use App\Services\Operations\StaffingAutomationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunStaffingAutomation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 180;

    public function __construct(public int $shiftId, public int $planRevision)
    {
        $this->onConnection('ai_disposition')->onQueue('ai-disposition');
    }

    public function uniqueId(): string
    {
        return 'staffing:'.$this->shiftId.':'.$this->planRevision;
    }

    public function handle(StaffingAutomationService $service): void
    {
        $service->process($this->shiftId, $this->planRevision);
    }
}
