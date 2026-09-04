<?php

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Laravel\Jetstream\Contracts\DeletesTeams;

class DeleteTeam implements DeletesTeams
{
    public function __construct(
        private readonly OutlookAddinSnapshotRefreshScheduler $outlookSnapshots,
    ) {}

    /**
     * Delete the given team.
     */
    public function delete(Team $team): void
    {
        $userIds = $team->users()->pluck('users.id');
        if ($team->user_id) {
            $userIds->push((int) $team->user_id);
        }

        $team->purge();

        foreach ($userIds->unique()->values() as $userId) {
            $this->outlookSnapshots->scheduleForUser((int) $userId);
        }
    }
}
