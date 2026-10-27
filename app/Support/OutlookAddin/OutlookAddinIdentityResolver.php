<?php

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use App\Services\DeviceManagement\EmployeeWorkplaceProvisioner;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class OutlookAddinIdentityResolver
{
    /** Account settings never activate employees or interpret the selected message's sender. */
    public function resolveOwnAccount(VerifiedEntraIdentity $identity, string $mailboxAddress): array
    {
        $mailboxAddress = strtolower(trim($mailboxAddress));
        $tenant = strtolower(trim((string) config('outlook_addin.entra.tenant_id')));
        if (! Str::isUuid($tenant) || ! Str::isUuid($identity->objectId)
            || ! hash_equals($tenant, strtolower($identity->tenantId))
            || ! Schema::hasTable('employee_identity_accounts')) {
            throw $this->notLinked();
        }
        $accounts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->whereRaw('LOWER(external_id) = ?', [strtolower($identity->objectId)])->with('user')->get();
        $account = $accounts->count() === 1 ? $accounts->first() : null;
        $user = $account?->user;
        if (! $account instanceof EmployeeIdentityAccount || ! $user instanceof User
            || ! hash_equals($tenant, strtolower((string) $account->tenant_id))
            || ! $user->isActive() || $user->email_verified_at === null
            || ! in_array($user->role, ['admin', 'staff'], true)) {
            throw $this->notLinked();
        }
        $trusted = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)), [$account->principal, $account->email],
        ), static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)));
        if (filter_var($mailboxAddress, FILTER_VALIDATE_EMAIL) === false
            || ! in_array(strtolower($identity->principal), $trusted, true)
            || ! in_array($mailboxAddress, $trusted, true)) {
            throw new OutlookAddinException('Die Microsoft-Anmeldung gehört nicht zum geöffneten Outlook-Postfach.', 403, 'outlook_addin_mailbox_mismatch');
        }

        return ['user' => $user, 'binding' => ['schema' => 1, 'mailboxAddress' => $mailboxAddress,
            'senderAddress' => $mailboxAddress, 'allowedSenderAddresses' => $trusted]];
    }

    /**
     * @return array{user: User, binding: array{schema: int, mailboxAddress: string, senderAddress: string, allowedSenderAddresses: list<string>}}
     */
    public function resolve(VerifiedEntraIdentity $identity, string $mailboxAddress, string $senderAddress): array
    {
        $mailboxAddress = strtolower(trim($mailboxAddress));
        if (filter_var($mailboxAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new OutlookAddinException(
                'Das aktuelle Outlook-Postfach konnte nicht sicher bestimmt werden.',
                403,
                'outlook_addin_mailbox_unavailable',
            );
        }
        $senderAddress = strtolower(trim($senderAddress));
        if (filter_var($senderAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new OutlookAddinException(
                'Der aktuelle Outlook-Absender konnte nicht sicher bestimmt werden.',
                403,
                'outlook_addin_sender_unavailable',
            );
        }

        $account = $this->identityAccount($identity);
        $user = $account?->user;

        if (! $account instanceof EmployeeIdentityAccount
            || ! $user instanceof User
            || ($account->tenant_id !== null
                && ! hash_equals(strtolower($identity->tenantId), strtolower((string) $account->tenant_id)))) {
            throw $this->notLinked();
        }

        $trustedAddresses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            // Nur ausdruecklich diesem Microsoft-Konto zugeordnete Adressen.
            // Die Profil-E-Mail kann privat sein und ist kein Postfach-Alias.
            [$account->principal, $account->email],
        ), static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)));

        if (! in_array($identity->principal, $trustedAddresses, true)
            || ! in_array($mailboxAddress, $trustedAddresses, true)) {
            throw new OutlookAddinException(
                'Die Microsoft-Anmeldung gehört nicht zum geöffneten Outlook-Postfach.',
                403,
                'outlook_addin_mailbox_mismatch',
            );
        }
        if (! in_array($senderAddress, $trustedAddresses, true)) {
            throw new OutlookAddinException(
                'Der aktuelle Absender ist nicht fuer dieses RailTime-Microsoft-Konto freigeschaltet.',
                403,
                'outlook_addin_sender_mismatch',
            );
        }

        // Only a validated token and a matching mailbox/sender reach activation.
        // Legacy inactive accounts without an explicit workplace approval remain blocked.
        app(EmployeeWorkplaceProvisioner::class)->activate($identity);
        $user = $user->fresh();
        if (! $user->isActive() || $user->email_verified_at === null) {
            throw $this->notLinked();
        }

        return [
            'user' => $user,
            'binding' => [
                'schema' => 1,
                'mailboxAddress' => $mailboxAddress,
                'senderAddress' => $senderAddress,
                'allowedSenderAddresses' => $trustedAddresses,
            ],
        ];
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
