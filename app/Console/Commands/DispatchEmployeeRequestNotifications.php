<?php

namespace App\Console\Commands;

use App\Models\EmployeeRequestNotification;
use App\Services\Operations\EmployeeRequestNotificationService;
use Illuminate\Console\Command;
use Throwable;

class DispatchEmployeeRequestNotifications extends Command
{
    protected $signature = 'operations:dispatch-employee-requests {--limit=100 : Maximum number of pending notification receipts}';

    protected $description = 'Retry only notification receipts created by new employee request transitions; never backfill historical requests.';

    public function handle(EmployeeRequestNotificationService $service): int
    {
        if (! $service->ready()) {
            $this->info('Benachrichtigungsreceipts sind noch nicht verfügbar.');

            return self::SUCCESS;
        }
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $ids = EmployeeRequestNotification::whereNull('enqueued_at')->whereNull('skipped_at')->orderBy('id')->limit($limit)->pluck('id');
        $failures = 0;
        foreach ($ids as $id) {
            try {
                $service->dispatchReceipt((int) $id);
            } catch (Throwable $exception) {
                $failures++;
                $this->warn('Receipt #'.$id.': Queue nicht verfügbar ('.$exception::class.').');
            }
        }
        $this->info($ids->count().' vorhandene Benachrichtigungsreceipts zur Queue angeboten.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
