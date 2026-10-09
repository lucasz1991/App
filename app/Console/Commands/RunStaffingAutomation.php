<?php

namespace App\Console\Commands;

use App\Jobs\RunStaffingAutomation as RunStaffingAutomationJob;
use App\Services\Operations\StaffingAutomationService;
use Illuminate\Console\Command;

class RunStaffingAutomation extends Command
{
    protected $signature = 'operations:staffing-automation';

    protected $description = 'Dispatch bounded native portal personnel invitation waves when explicitly enabled';

    public function handle(StaffingAutomationService $service): int
    {
        foreach ($service->scheduledTargets() as $target) {
            RunStaffingAutomationJob::dispatch((int) $target['shift_id'], (int) $target['plan_revision']);
        }

        return self::SUCCESS;
    }
}
