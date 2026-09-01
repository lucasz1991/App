<?php

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class OutlookAddinIdentityResolver
{
    public function resolve(VerifiedEntraIdentity $identity, string $mailboxAddress): User
    {
        $mailboxAddress = strtolower(trim($mailboxAddress));
        if (filter_var($mailboxAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->notLinked();
        }

        $account = $this->identityAccount($identity);
        $user = $account?->user;

        if (! $account instanceof EmployeeIdentityAccount
            || ! $user instanceof User
            || ! $user->isActive()
            || $user->email_verified_at === null) {
            throw $this->notLinked();
        }

        $trustedAddresses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            [$account->principal, $account->email, $user->email],
        ))));

        if (! in_array($identity->principal, $trustedAddresses, true)
            || ! in_array($mailboxAddress, $trustedAddresses, true)) {
            throw new OutlookAddinException(
                'Die Microsoft-Anmeldung gehört nicht zum geöffneten Outlook-Postfach.',
                403,
                'outlook_addin_mailbox_mismatch',
            );
        }

        return $user;
    }

    private function identityAccount(VerifiedEntraIdentity $identity): ?EmployeeIdentityAccount
    {
        if (! Schema::hasTable('employee_identity_accounts')) {
            return null;
        }

        return EmployeeIdentityAccount::query()
            ->forProvider(AccountProvider::Microsoft365)
            ->active()
            ->where('external_id', $identity->objectId)
            ->whereNotNull('user_id')
            ->with('user')
            ->first();
    }

    private function notLinked(): OutlookAddinException
    {
        return new OutlookAddinException(
            'Für diese Microsoft-Identität ist kein aktiver RailTime-Mitarbeiter freigeschaltet.',
            403,
            'outlook_addin_identity_not_linked',
        );
    }
}
