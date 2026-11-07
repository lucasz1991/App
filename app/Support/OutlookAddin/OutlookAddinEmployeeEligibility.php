<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Outlook access is a Microsoft binding, not a RailTime registration or activation. */
final class OutlookAddinEmployeeEligibility
{
    public function employee(?User $user, bool $lock = false): ?User
    {
        if ($user === null || ! $user->exists || (int) $user->getKey() < 1) {
            return null;
        }
        $query = User::query()->whereKey($user->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $current = $query->first();

        return $current instanceof User && in_array($current->role, ['staff', 'admin'], true) ? $current : null;
    }

    public function permits(?User $user): bool
    {
        return $this->accountForUser($user) !== null;
    }

    /** Preserve existing private offline preparation; newly eligible staff may prepare it too. */
    public function canPrepareSnapshot(?User $user): bool
    {
        $employee = $this->employee($user);

        return $employee !== null && ($employee->isActive() || $this->permits($employee));
    }

    public function accountForUser(?User $user, bool $lock = false): ?EmployeeIdentityAccount
    {
        $employee = $this->employee($user, $lock);
        $tenant = strtolower(trim((string) config('outlook_addin.entra.tenant_id')));
        if ($employee === null || ! Str::isUuid($tenant) || ! Schema::hasTable('employee_identity_accounts')) {
            return null;
        }
        $query = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->where('user_id', $employee->getKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $accounts = $query->get();
        $account = $accounts->count() === 1 ? $accounts->first() : null;
        if (! $account instanceof EmployeeIdentityAccount
            || ! Str::isUuid((string) $account->external_id)
            || ! hash_equals($tenant, strtolower(trim((string) $account->tenant_id)))
            || filter_var((string) $account->principal, FILTER_VALIDATE_EMAIL) === false
            || str_contains(strtolower((string) $account->principal), '#ext#')
            || ($account->email !== null && trim((string) $account->email) !== ''
                && filter_var((string) $account->email, FILTER_VALIDATE_EMAIL) === false)) {
            return null;
        }
        $trusted = $this->trustedAddresses($account);
        // One OID or trusted address must never select two employee records.
        $conflicts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->where(function ($query) use ($account, $trusted): void {
                $query->whereRaw('LOWER(external_id) = ?', [strtolower((string) $account->external_id)]);
                foreach ($trusted as $address) {
                    $query->orWhereRaw('LOWER(principal) = ?', [$address])->orWhereRaw('LOWER(email) = ?', [$address]);
                }
            });
        if ($lock) {
            $conflicts->lockForUpdate();
        }
        $matches = $conflicts->get();
        if ($matches->count() !== 1 || (int) $matches->first()->getKey() !== (int) $account->getKey()) {
            return null;
        }

        return $account->setRelation('user', $employee);
    }

    public function accountForIdentity(VerifiedEntraIdentity $identity): ?EmployeeIdentityAccount
    {
        $tenant = strtolower(trim((string) config('outlook_addin.entra.tenant_id')));
        if (! Str::isUuid($tenant) || ! Str::isUuid($identity->objectId)
            || ! hash_equals($tenant, strtolower($identity->tenantId))
            || ! Schema::hasTable('employee_identity_accounts')) {
            return null;
        }
        $accounts = EmployeeIdentityAccount::query()->forProvider(AccountProvider::Microsoft365)->active()
            ->whereRaw('LOWER(external_id) = ?', [strtolower($identity->objectId)])->with('user')->get();
        if ($accounts->count() !== 1) {
            return null;
        }
        $account = $this->accountForUser($accounts->first()->user);

        return $account !== null && (int) $account->getKey() === (int) $accounts->first()->getKey() ? $account : null;
    }

    /** Only server-stored Microsoft addresses; never the employee's possibly private profile email. */
    public function trustedAddresses(EmployeeIdentityAccount $account): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)), [$account->principal, $account->email],
        ), static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)));
    }
}
