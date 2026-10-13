<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/** Uses the native Mail model, compiler and template after its receipt commits. */
class EmployeeRequestMailNotification extends MailNotification implements ShouldQueueAfterCommit
{
    public int $tries = 5;

    public array $backoff = [30, 120, 600, 1800];
}
