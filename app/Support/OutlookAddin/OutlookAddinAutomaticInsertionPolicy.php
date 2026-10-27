<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
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
        return $this->allEnabled($this->preferencesForUser($user));
    }

    /** @return array{schema:int,newMessages:array{signature:bool,template:bool},replies:array{signature:bool,template:bool},revision:string} */
    public function preferencesForUser(User $user): array
    {
        return $this->preferencesValue($user, Setting::getValueUncached(self::GROUP, $this->key($user)));
    }

    /** Profile self-service uses the authenticated RailTime session, never an invented Entra identity. */
    public function preferencesForSession(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $employee = $this->sessionEmployee($user);
            $this->sessionIdentity($employee);

            return $this->preferencesForUser($employee);
        }, 3);
    }

    public function updateForSession(User $user, string $group, string $field, bool $enabled, string $revision): array
    {
        $this->validateChange($group, $field, $revision);

        return DB::transaction(function () use ($user, $group, $field, $enabled, $revision): array {
            $employee = $this->sessionEmployee($user);
            $account = $this->sessionIdentity($employee);

            return $this->writePreference($employee, $account, $group, $field, $enabled, $revision);
        }, 3);
    }

    public function allEnabled(array $preferences): bool
    {
        return $preferences['newMessages']['signature'] && $preferences['newMessages']['template']
            && $preferences['replies']['signature'] && $preferences['replies']['template'];
    }

    public function automaticSelection(array $preferences, string $composeType): array
    {
        $composeType = in_array($composeType, ['newMail', 'reply', 'forward'], true) ? $composeType : 'unknown';
        $selected = $composeType === 'unknown' ? ['signature' => false, 'template' => false]
            : $preferences[$composeType === 'reply' ? 'replies' : 'newMessages'];

        return ['schema' => 2, 'composeType' => $composeType, 'signature' => $selected['signature'], 'template' => $selected['template']];
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
            $preferences = $this->preferencesValue($employee, $setting?->value);
            $previous = $this->allEnabled($preferences);
            $changed = $preferences['newMessages'] !== ['signature' => $enabled, 'template' => $enabled]
                || $preferences['replies'] !== ['signature' => $enabled, 'template' => $enabled];
            if ($changed) {
                $preferences['newMessages'] = $preferences['replies'] = ['signature' => $enabled, 'template' => $enabled];
                Setting::setValue(self::GROUP, $this->key($employee), $this->storedValue($preferences, (array) $setting?->value));
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

    /** Only the fresh, verified Microsoft self can update these four preferences. */
    public function updateForIdentity(User $user, VerifiedEntraIdentity $verified, array $binding, string $group, string $field, bool $enabled, string $revision): array
    {
        $this->validateChange($group, $field, $revision);

        return DB::transaction(function () use ($user, $verified, $binding, $group, $field, $enabled, $revision): array {
            $employee = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $employee instanceof User || ! $employee->isActive() || $employee->email_verified_at === null
                || ! in_array($employee->role, ['admin', 'staff'], true)) {
                throw new OutlookAddinException('Die Mitarbeiterfreigabe ist nicht mehr gültig.', 403, 'outlook_addin_identity_not_linked');
            }
            $accounts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
                ->whereRaw('LOWER(external_id) = ?', [strtolower($verified->objectId)])->lockForUpdate()->get();
            $account = $accounts->count() === 1 ? $accounts->first() : null;
            $tenant = strtolower(trim((string) config('outlook_addin.entra.tenant_id')));
            $trusted = $account ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $value): string => strtolower(trim((string) $value)), [$account->principal, $account->email],
            ), static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false))) : [];
            $previousTrusted = $binding['allowedSenderAddresses'] ?? [];
            sort($trusted);
            sort($previousTrusted);
            if (! $account instanceof EmployeeIdentityAccount || (int) $account->user_id !== (int) $employee->getKey()
                || ! Str::isUuid($tenant) || ! Str::isUuid($verified->objectId)
                || ! hash_equals($tenant, strtolower($verified->tenantId))
                || ! hash_equals($tenant, strtolower((string) $account->tenant_id))
                || $previousTrusted !== $trusted
                || ! in_array(strtolower($verified->principal), $trusted, true)
                || ! in_array(strtolower((string) ($binding['mailboxAddress'] ?? '')), $trusted, true)
                || ! in_array(strtolower((string) ($binding['senderAddress'] ?? '')), $trusted, true)) {
                throw new OutlookAddinException('Die Microsoft-Zuordnung ist nicht mehr gültig.', 403, 'outlook_addin_identity_not_linked');
            }

            return $this->writePreference($employee, $account, $group, $field, $enabled, $revision);
        }, 3);
    }

    private function sessionEmployee(User $user): User
    {
        if (! Auth::user() instanceof User || (int) Auth::id() !== (int) $user->getKey()) {
            throw $this->sessionUnavailable();
        }
        $employee = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
        if (! $employee instanceof User || ! $employee->isActive() || $employee->email_verified_at === null
            || ! in_array($employee->role, ['admin', 'staff'], true)) {
            throw $this->sessionUnavailable();
        }

        return $employee;
    }

    private function sessionIdentity(User $employee): EmployeeIdentityAccount
    {
        $accounts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->where('user_id', $employee->getKey())->lockForUpdate()->get();
        if ($accounts->count() !== 1) {
            throw $this->sessionUnavailable();
        }
        $account = $accounts->first();
        try {
            $existing = $this->existingIdentity((string) $account->principal, lock: true);
        } catch (ValidationException) {
            throw $this->sessionUnavailable();
        }
        $objects = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->whereRaw('LOWER(external_id) = ?', [strtolower((string) $account->external_id)])->lockForUpdate()->get();
        if ((int) $existing->getKey() !== (int) $account->getKey()
            || (int) $existing->user_id !== (int) $employee->getKey() || $objects->count() !== 1) {
            throw $this->sessionUnavailable();
        }

        return $account;
    }

    private function sessionUnavailable(): OutlookAddinException
    {
        return new OutlookAddinException('Für Outlook werden ein aktives, bestätigtes Mitarbeiterkonto und eine eindeutige Microsoft-Zuordnung benötigt.', 403, 'outlook_addin_identity_not_linked');
    }

    private function validateChange(string $group, string $field, string $revision): void
    {
        if (! in_array($group, ['newMessages', 'replies'], true) || ! in_array($field, ['signature', 'template'], true)
            || preg_match('/\A[0-9a-f]{64}\z/', $revision) !== 1) {
            throw ValidationException::withMessages(['preferences' => 'Die Outlook-Einstellung ist ungültig.']);
        }
    }

    /** Called only after each entry point revalidates its own principal inside the user/identity transaction. */
    private function writePreference(User $employee, EmployeeIdentityAccount $account, string $group, string $field, bool $enabled, string $revision): array
    {
        $setting = Setting::query()->where('type', self::GROUP)->where('key', $this->key($employee))->lockForUpdate()->first();
        $preferences = $this->preferencesValue($employee, $setting?->value);
        if (! hash_equals($preferences['revision'], $revision)) {
            throw new OutlookAddinException('Die Einstellung wurde inzwischen geändert. Bitte den aktuellen Stand verwenden.', 409, 'outlook_addin_preferences_conflict');
        }
        if ($preferences[$group][$field] === $enabled) {
            return $preferences;
        }
        $previous = $preferences[$group][$field];
        $preferences[$group][$field] = $enabled;
        $stored = $this->storedValue($preferences, (array) $setting?->value);
        Setting::setValue(self::GROUP, $this->key($employee), $stored);
        activity('outlook-addin')->causedBy($employee)->performedOn($employee)->withProperties([
            'employee_id' => (int) $employee->getKey(), 'identity_id' => (int) $account->getKey(),
            'group' => $group, 'field' => $field, 'enabled_before' => $previous, 'enabled_after' => $enabled,
        ])->log('outlook_addin_automatic_preference_changed');

        return $this->preferencesValue($employee, $stored);
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

    private function preferencesValue(User $user, mixed $value): array
    {
        if (is_array($value) && array_key_exists('schema', $value) && $value['schema'] !== 2) {
            throw new RuntimeException('Die persönliche Outlook-Einstellung ist ungültig.');
        }
        if (is_array($value) && ($value['schema'] ?? null) === 2) {
            foreach (['newMessages', 'replies'] as $group) {
                if (! is_array($value[$group] ?? null) || ! is_bool($value[$group]['signature'] ?? null) || ! is_bool($value[$group]['template'] ?? null)) {
                    throw new RuntimeException('Die persönliche Outlook-Einstellung ist ungültig.');
                }
            }
            $groups = [
                'newMessages' => array_intersect_key($value['newMessages'], ['signature' => true, 'template' => true]),
                'replies' => array_intersect_key($value['replies'], ['signature' => true, 'template' => true]),
            ];
        } elseif ($value === null || (is_array($value) && is_bool($value['enabled'] ?? null))) {
            $enabled = $value === null ? true : $value['enabled'];
            $groups = ['newMessages' => ['signature' => $enabled, 'template' => $enabled], 'replies' => ['signature' => $enabled, 'template' => $enabled]];
        } else {
            throw new RuntimeException('Die persönliche Outlook-Einstellung ist ungültig.');
        }

        return ['schema' => 2] + $groups + ['revision' => hash('sha256', json_encode([(int) $user->getKey(), $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    private function storedValue(array $preferences, array $previous): array
    {
        unset($preferences['revision']);

        return array_replace($previous, $preferences, ['enabled' => $this->allEnabled($preferences), 'nonce' => Str::random(32)]);
    }
}
