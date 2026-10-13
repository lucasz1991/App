<?php

namespace App\Jobs;

use App\Services\Operations\EmployeeRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class DeliverEmployeeRequestNotification implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $receiptId) {}

    public function uniqueId(): string
    {
        return 'employee-request:'.$this->receiptId;
    }

    public function handle(EmployeeRequestNotificationService $service): void
    {
        $service->deliver($this->receiptId);
    }
}
