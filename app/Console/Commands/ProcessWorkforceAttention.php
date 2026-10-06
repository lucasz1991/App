<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationsDutyMonitorService;
use App\Services\Operations\OperationsReminderService;
use App\Services\Operations\PersonnelEnhancementService;
use Illuminate\Console\Command;

class ProcessWorkforceAttention extends Command
{
    protected $signature = 'operations:attention-run';

    protected $description = 'Verarbeitet interne Erinnerungen, Dienstprüffälle und Personalfristen.';

    public function handle(OperationsReminderService $reminders, OperationsDutyMonitorService $monitor, PersonnelEnhancementService $personnel): int
    {
        $counts = ['reminders' => $reminders->runScheduled(), 'monitor' => $monitor->runScheduled()];
        if ($personnel->ready()) {
            $counts['workflows'] = $personnel->escalateWorkflows();
            $counts['reports'] = $personnel->runScheduledReports();
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
