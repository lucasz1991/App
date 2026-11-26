<?php

namespace App\Services\Personnel;

use App\Actions\Jetstream\DeleteUser;
use App\Models\User;
use App\Services\Operations\PersonnelScopeService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Konto aktivieren, deaktivieren und löschen – eine Regel für die
 * Mitarbeiterliste (Employees\Index) und das Profil (Employees\Profile).
 *
 * - Aktivieren/Deaktivieren: Fähigkeit employees.create, Löschen: employees.delete.
 * - Der Akteur muss aktiv sein; das Ziel muss im eigenen Zuständigkeitsbereich
 *   liegen (PersonnelScopeService::authorizeTarget, auch beim Löschen).
 * - Admin-Konten verwalten nur Admins.
 * - Das eigene Konto kann weder deaktiviert noch gelöscht werden; der primäre
 *   Systemadministrator wird nie gelöscht und nur von einem Super-Admin deaktiviert.
 *
 * Rechteverstöße enden mit 403 (bzw. AuthorizationException beim Gate),
 * fachliche Ablehnungen kommen als Ergebnis mit Toast-Text zurück.
 *
 * @phpstan-type Result array{status: string, type: string, text: string}
 */
final class EmployeeAccountStatusService
{
    public const ACTIVATED = 'activated';

    public const ALREADY_ACTIVE = 'already_active';

    public const DEACTIVATED = 'deactivated';

    public const ALREADY_INACTIVE = 'already_inactive';

    public const DELETED = 'deleted';

    public const NOT_FOUND = 'not_found';

    public const SELF = 'self';

    public const SUPER_ADMIN = 'super_admin';

    public const FAILED = 'failed';

    public function __construct(private readonly PersonnelScopeService $scope) {}

    /** @return Result */
    public function activate(?User $actor, User|int|null $target): array
    {
        $actor = $this->authorizeActor($actor, 'employees.create');
        $user = $this->resolve($target);
        if (! $user) {
            return $this->result(self::NOT_FOUND, 'error', 'app.user_not_found');
        }
        $this->authorizeTarget($actor, $user);

        if ($user->isActive()) {
            return $this->result(self::ALREADY_ACTIVE, 'info', 'app.user_already_active');
        }

        $user->update(['status' => true]);

        return $this->result(self::ACTIVATED, 'success', 'app.user_activated');
    }

    /** @return Result */
    public function deactivate(?User $actor, User|int|null $target): array
    {
        $actor = $this->authorizeActor($actor, 'employees.create');
        $user = $this->resolve($target);
        if (! $user) {
            return $this->result(self::NOT_FOUND, 'error', 'app.user_not_found');
        }
        $this->authorizeTarget($actor, $user);

        if ((int) $user->id === (int) $actor->id) {
            return $this->result(self::SELF, 'error', 'app.cannot_deactivate_self');
        }
        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return $this->result(self::SUPER_ADMIN, 'error', 'app.cannot_deactivate_super_admin');
        }
        if (! $user->isActive()) {
            return $this->result(self::ALREADY_INACTIVE, 'info', 'app.user_already_inactive');
        }

        $user->update(['status' => false]);

        return $this->result(self::DEACTIVATED, 'success', 'app.user_deactivated');
    }

    /** @return Result */
    public function delete(?User $actor, User|int|null $target): array
    {
        $actor = $this->authorizeActor($actor, 'employees.delete');
        $user = $this->resolve($target);
        if (! $user) {
            return $this->result(self::NOT_FOUND, 'error', 'app.user_not_found');
        }
        $this->authorizeTarget($actor, $user);

        if ((int) $user->id === (int) $actor->id) {
            return $this->result(self::SELF, 'error', 'app.cannot_delete_self');
        }
        if ($user->isSuperAdmin()) {
            return $this->result(self::SUPER_ADMIN, 'error', 'app.cannot_delete_super_admin');
        }

        try {
            app(DeleteUser::class)->delete($user);
        } catch (\Throwable $e) {
            Log::error('Benutzer konnte nicht geloescht werden.', [
                'user_id' => $user->id,
                'deleted_by' => $actor->id,
                'error' => $e->getMessage(),
            ]);

            return $this->result(self::FAILED, 'error', 'app.user_delete_failed');
        }

        return $this->result(self::DELETED, 'success', 'app.user_deleted');
    }

    private function authorizeActor(?User $actor, string $ability): User
    {
        abort_unless($actor instanceof User && $actor->isActive(), 403);
        Gate::forUser($actor)->authorize($ability);

        return $actor;
    }

    private function authorizeTarget(User $actor, User $user): void
    {
        // Admin-Konten duerfen nur von Admins verwaltet werden.
        abort_if($user->isAdmin() && ! $actor->isAdmin(), 403);
        $this->scope->authorizeTarget($actor, $user);
    }

    private function resolve(User|int|null $target): ?User
    {
        if ($target instanceof User) {
            return $target;
        }

        return $target === null ? null : User::find($target);
    }

    /** @return Result */
    private function result(string $status, string $type, string $key): array
    {
        return ['status' => $status, 'type' => $type, 'text' => __($key)];
    }
}
