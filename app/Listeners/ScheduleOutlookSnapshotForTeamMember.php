<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Events\TeamMemberUpdated;

final class ScheduleOutlookSnapshotForTeamMember implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly OutlookAddinSnapshotRefreshScheduler $snapshots,
    ) {}

    public function handle(TeamMemberAdded|TeamMemberRemoved|TeamMemberUpdated $event): void
    {
        if ($event->user instanceof User) {
            $this->snapshots->scheduleForUser($event->user);
        }
    }
}
