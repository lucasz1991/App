<?php

namespace App\Support\OutlookAddin;

use App\Models\EmployeeIdentityAccount;
use App\Models\User;

final class OutlookAddinIdentityResolver
{
    /** Account settings never activate employees or interpret the selected message's sender. */
    public function resolveOwnAccount(VerifiedEntraIdentity $identity, string $mailboxAddress): array
    {
        $mailboxAddress = strtolower(trim($mailboxAddress));
        $eligibility = app(OutlookAddinEmployeeEligibility::class);
        $account = $eligibility->accountForIdentity($identity);
        $user = $account?->user;
        if (! $account instanceof EmployeeIdentityAccount || ! $user instanceof User) {
            throw $this->notLinked();
        }
        $trusted = $eligibility->trustedAddresses($account);
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

        $eligibility = app(OutlookAddinEmployeeEligibility::class);
        $account = $eligibility->accountForIdentity($identity);
        $user = $account?->user;

        if (! $account instanceof EmployeeIdentityAccount
            || ! $user instanceof User) {
            throw $this->notLinked();
        }

        $trustedAddresses = $eligibility->trustedAddresses($account);

        if (! in_array(strtolower($identity->principal), $trustedAddresses, true)
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

        // Outlook access never completes registration or activates the RailTime account.
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

    private function notLinked(): OutlookAddinException
    {
        return new OutlookAddinException(
            'Für diese Microsoft-Identität fehlt eine eindeutige RailTime-Mitarbeiterzuordnung.',
            403,
            'outlook_addin_identity_not_linked',
        );
    }
}
