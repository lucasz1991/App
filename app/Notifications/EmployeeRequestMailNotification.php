<?php

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/** Uses the native Mail model, compiler and template after its receipt commits. */
class EmployeeRequestMailNotification extends MailNotification implements ShouldQueueAfterCommit {}
