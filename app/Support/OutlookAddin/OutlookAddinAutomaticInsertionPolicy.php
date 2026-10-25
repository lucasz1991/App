<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Individual event-insertion preference; published/manual documents stay intact. */
final class OutlookAddinAutomaticInsertionPolicy
{
    public const GROUP = 'outlook_addin';

    public function enabledForUser(User $user): bool
    {
        return $this->enabledValue(Setting::getValueUncached(self::GROUP, $this->key($user)));
    }

    /** @return array{userId:int,identityId:int,enabled:bool} */
    public function forMailbox(string $mailbox): array
    {
        $identity = $this->existingIdentity($mailbox);

        return [
            'userId' => (int) $identity->user_id,
            'identityId' => (int) $identity->getKey(),
            'enabled' => $this->enabledForUser($identity->user),
        ];
    }

    /** @return array{userId:int,identityId:int,enabled:bool,previousEnabled:bool,changed:bool} */
    public function setForMailbox(string $mailbox, bool $enabled, User $actor): array
    {
        $identity = $this->existingIdentity($mailbox);
        $expectedUserId = (int) $identity->user_id;

        return DB::transaction(function () use ($mailbox, $enabled, $actor, $expectedUserId): array {
            // Existing employee/binding writers lock the user before identity.
            // Lock both users deterministically and re-resolve the exact link.
            $users = User::query()->whereIn('id', [$actor->getKey(), $expectedUserId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $users->get($actor->getKey());
            if (! $currentActor instanceof User || ! $currentActor->isActive() || $currentActor->email_verified_at === null || ! $currentActor->isAdmin()) {
                throw new AuthorizationException('Nur aktive Administratoren dürfen die automatische Outlook-Einfügung ändern.');
            }
            Gate::forUser($currentActor)->authorize('devices.accounts.manage');
            $identity = $this->existingIdentity($mailbox, lock: true);
            if ((int) $identity->user_id !== $expectedUserId) {
                throw ValidationException::withMessages(['mailbox' => 'Die Microsoft-Zuordnung wurde inzwischen geändert. Bitte erneut prüfen.']);
            }
            $employee = $users->get($expectedUserId);
            if (! $employee instanceof User || ! $employee->isActive() || ! in_array($employee->role, ['admin', 'staff'], true)) {
                throw ValidationException::withMessages(['mailbox' => 'Das verknüpfte Mitarbeiterkonto ist nicht aktiv.']);
            }
            $setting = Setting::query()->where('type', self::GROUP)->where('key', $this->key($employee))->lockForUpdate()->first();
            $previous = $this->enabledValue($setting?->value);
            $changed = $previous !== $enabled;
            if ($changed) {
                Setting::setValue(self::GROUP, $this->key($employee), array_replace((array) $setting?->value, ['enabled' => $enabled]));
                activity('outlook-addin')
                    ->causedBy($currentActor)
                    ->performedOn($employee)
                    ->withProperties([
                        'employee_id' => $expectedUserId,
                        'identity_id' => (int) $identity->getKey(),
                        'enabled_before' => $previous,
                        'enabled_after' => $enabled,
                    ])
                    ->log('outlook_addin_automatic_insertion_changed');
            }

            return [
                'userId' => $expectedUserId,
                'identityId' => (int) $identity->getKey(),
                'enabled' => $enabled,
                'previousEnabled' => $previous,
                'changed' => $changed,
            ];
        }, 3);
    }

    private function existingIdentity(string $mailbox, bool $lock = false): EmployeeIdentityAccount
    {
        $mailbox = mb_strtolower(trim($mailbox));
        if (filter_var($mailbox, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['mailbox' => 'Bitte einen vollständigen Microsoft-Anmeldenamen angeben.']);
        }
        $query = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->whereRaw('LOWER(principal) = ?', [$mailbox])->with('user')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $identities = $query->get();
        $identity = $identities->count() === 1 ? $identities->first() : null;
        $tenant = strtolower(trim((string) config('outlook_addin.entra.tenant_id')));
        if (! $identity instanceof EmployeeIdentityAccount
            || ! Str::isUuid((string) $identity->external_id)
            || ! Str::isUuid($tenant)
            || ! Str::isUuid((string) $identity->tenant_id)
            || ! hash_equals($tenant, strtolower((string) $identity->tenant_id))
            || ! $identity->user instanceof User
            || ! $identity->user->isActive()
            || ! in_array($identity->user->role, ['admin', 'staff'], true)) {
            throw ValidationException::withMessages(['mailbox' => 'Für diesen Microsoft-Anmeldenamen fehlt eine eindeutige aktive Mitarbeiterzuordnung im konfigurierten Mandanten.']);
        }

        return $identity;
    }

    private function key(User $user): string
    {
        if ((int) $user->getKey() < 1) {
            throw new RuntimeException('Die Outlook-Einstellung benötigt ein bestehendes Mitarbeiterkonto.');
        }

        return 'automatic_insertion_user_'.(int) $user->getKey();
    }

    private function enabledValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (! is_array($value) || ! is_bool($value['enabled'] ?? null)) {
            throw new RuntimeException('Die persönliche Outlook-Einstellung ist ungültig.');
        }

        return $value['enabled'];
    }
}
