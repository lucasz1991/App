<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

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
        $eligibility = app(OutlookAddinEmployeeEligibility::class);
        $account = $eligibility->accountForUser($employee, lock: true);
        if (! $employee instanceof User || ! $account instanceof EmployeeIdentityAccount) {
            throw new OutlookAddinException('Die Mitarbeiterfreigabe ist nicht mehr gültig.', 403, 'outlook_addin_identity_not_linked');
        }
        $tenant = strtolower(trim((string) config('outlook_addin.entra.tenant_id')));
        $trusted = $eligibility->trustedAddresses($account);
        $previousTrusted = $binding['allowedSenderAddresses'] ?? [];
        sort($trusted);
        sort($previousTrusted);
        if (! $account instanceof EmployeeIdentityAccount || (int) $account->user_id !== (int) $employee->getKey()
            || ! Str::isUuid($tenant) || ! Str::isUuid($verified->objectId)
            || ! hash_equals(strtolower((string) $account->external_id), strtolower($verified->objectId))
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
