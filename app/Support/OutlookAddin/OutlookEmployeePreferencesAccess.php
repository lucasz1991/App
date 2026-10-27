<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/** Explicit employee-management context; never impersonates the target account. */
final class OutlookEmployeePreferencesAccess
{
    public function canManage(?User $actor, User $target): bool
    {
        if (! $actor instanceof User) {
            return false;
        }
        try {
            $this->resolve($actor, $target);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @return array{0:User,1:User} Call inside the writer transaction when locking. */
    public function resolve(User $actor, User $target, bool $lock = false): array
    {
        if (! Auth::user() instanceof User || (int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }
        $query = User::query()->whereIn('id', [$actor->getKey(), $target->getKey()])->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $users = $query->get()->keyBy('id');
        $actor = $users->get($actor->getKey());
        $target = $users->get($target->getKey());
        if (! $actor instanceof User || ! $target instanceof User || ! $actor->isActive()
            || $actor->email_verified_at === null || ! in_array($actor->role, ['staff', 'admin', 'superadmin'], true)
            || ! $actor->canViewManagementDashboard()
            || ! in_array($target->role, ['staff', 'admin'], true)
            || (($target->isAdmin() || $target->isSuperAdmin()) && ! $actor->isAdmin())) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor)->authorize('employees.view');
        Gate::forUser($actor)->authorize('users.edit');

        return [$actor, $target];
    }

    public function requireEligibleTarget(User $target): void
    {
        if (! $target->isActive() || $target->email_verified_at === null) {
            throw new OutlookAddinException('Die Outlook-Einstellungen benötigen ein aktives, bestätigtes Mitarbeiterkonto.', 403, 'outlook_addin_employee_not_eligible');
        }
    }
}
