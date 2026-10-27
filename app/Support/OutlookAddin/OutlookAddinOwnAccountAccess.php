<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use Illuminate\Support\Str;

/** Fresh own-account proof inside the caller's user/identity transaction; no activation or session impersonation. */
final class OutlookAddinOwnAccountAccess
{
    /** @return array{0:User,1:EmployeeIdentityAccount} */
    public function employee(User $user, VerifiedEntraIdentity $verified, array $binding): array
    {
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

        return [$employee, $account];
    }
}
