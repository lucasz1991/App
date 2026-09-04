<?php

namespace App\Listeners;

use App\Models\EmployeeIdentityAccount;
use App\Models\Team;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\OutlookAddin\OutlookAddinSnapshotRefreshScheduler;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/** Ein zentraler Modellbeobachter fuer alle persoenlichen Outlook-Eingaben. */
final class OutlookAddinSnapshotObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly OutlookAddinSnapshotRefreshScheduler $snapshots,
    ) {}

    public function saved(Model $model): void
    {
        if ($model instanceof User) {
            if (! $model->isActive() || ! in_array($model->role, ['admin', 'staff'], true)) {
                $this->snapshots->forgetForUser($model);

                return;
            }

            if ($model->wasRecentlyCreated || $model->wasChanged([
                'name',
                'email',
                'email_verified_at',
                'role',
                'status',
                'current_team_id',
            ])) {
                $this->snapshots->scheduleForUser($model);
            }

            return;
        }

        if ($model instanceof UserProfile) {
            if ($model->wasRecentlyCreated || $model->wasChanged([
                'user_id',
                'first_name',
                'last_name',
                'phone',
                'mobile',
                'position',
            ])) {
                $this->scheduleForCurrentAndPreviousUser($model);
            }

            return;
        }

        if ($model instanceof EmployeeIdentityAccount) {
            if ($model->wasRecentlyCreated || $model->wasChanged([
                'user_id',
                'provider',
                'external_id',
                'principal',
                'email',
                'lifecycle_status',
            ])) {
                $this->scheduleForCurrentAndPreviousUser($model);
            }

            return;
        }

        if ($model instanceof Team
            && ($model->wasRecentlyCreated || $model->wasChanged(['name', 'personal_team']))) {
            $this->snapshots->scheduleForTeam($model);
        }
    }

    public function deleted(Model $model): void
    {
        if ($model instanceof User) {
            $this->snapshots->forgetForUser((int) $model->getKey());

            return;
        }

        if ($model instanceof UserProfile || $model instanceof EmployeeIdentityAccount) {
            $this->snapshots->scheduleForUser((int) $model->user_id);
        }
    }

    /**
     * Aktualisiert bei einer Neuzuordnung sowohl das bisherige als auch das
     * aktuelle Benutzerkonto. Nach dem Commit ist getOriginal() bereits auf
     * den neuen Stand synchronisiert; getPrevious() behaelt dagegen den Wert
     * unmittelbar vor dem letzten Speichern.
     */
    private function scheduleForCurrentAndPreviousUser(Model $model): void
    {
        $userIds = [];

        if ($model->wasChanged('user_id')) {
            $previous = $model->getPrevious();
            $userIds[] = (int) ($previous['user_id'] ?? 0);
        }

        $userIds[] = (int) $model->getAttribute('user_id');

        foreach (array_unique($userIds) as $userId) {
            if ($userId > 0) {
                $this->snapshots->scheduleForUser($userId);
            }
        }
    }
}
