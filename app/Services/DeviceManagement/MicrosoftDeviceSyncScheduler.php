<?php

namespace App\Services\DeviceManagement;

use App\Enums\AccountProvider;
use App\Models\EmployeeIdentityAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutlookAddin\VerifiedEntraIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** The interactive Microsoft request only schedules work; Graph runs in a worker. */
final class MicrosoftDeviceSyncScheduler
{
    public function __construct(
        private readonly MicrosoftDeviceSettings $settings,
        private readonly MicrosoftDeviceRuntime $runtime,
    ) {}

    public function queue(bool $force = false): bool
    {
        $snapshot = $this->settings->snapshot();
        $configuration = $snapshot['configuration'];
        if (! ($configuration['enabled'] ?? false)) {
            return false;
        }

        $tenantId = strtolower(trim((string) ($configuration['tenant_id'] ?? '')));
        if (! Str::isUuid($tenantId)) {
            throw new RuntimeException('Bitte zuerst eine gueltige Microsoft-Entra-Tenant-ID speichern.');
        }

        $fingerprint = $snapshot['fingerprint'];

        return DB::transaction(function () use ($tenantId, $fingerprint, $force): bool {
            Setting::query()->where('type', MicrosoftDeviceSettings::GROUP)
                ->where('key', MicrosoftDeviceSettings::KEY)->lockForUpdate()->first();
            $currentSnapshot = $this->settings->snapshot();
            $current = $currentSnapshot['configuration'];
            if (! ($current['enabled'] ?? false)
                || ! hash_equals($fingerprint, $currentSnapshot['fingerprint'])
                || ! hash_equals($tenantId, strtolower((string) ($current['tenant_id'] ?? '')))) {
                return false;
            }

            // Ledger and actual database queue row commit together, including
            // an enclosing sign-in transaction. Workers cannot see either early.
            return $this->runtime->queueSync($currentSnapshot, $force);
        }, 3);
    }

    public function afterMicrosoftSignIn(VerifiedEntraIdentity $identity, User $user): void
    {
        try {
            $configuration = $this->settings->snapshot()['configuration'];
            if (! ($configuration['enabled'] ?? false)
                || ! ($configuration['sync_on_sign_in'] ?? false)
                || ! hash_equals(strtolower((string) ($configuration['tenant_id'] ?? '')), strtolower($identity->tenantId))
                || ! Str::isUuid($identity->tenantId)
                || ! Str::isUuid($identity->objectId)
                || ! Schema::hasColumn('employee_identity_accounts', 'tenant_id')) {
                return;
            }

            $currentUser = $user->fresh();
            if (! $currentUser?->isActive() || $currentUser->email_verified_at === null) {
                return;
            }

            DB::transaction(function () use ($identity, $currentUser): void {
                $setting = Setting::query()->where('type', MicrosoftDeviceSettings::GROUP)
                    ->where('key', MicrosoftDeviceSettings::KEY)->lockForUpdate()->first();
                $current = $this->settings->snapshot()['configuration'];
                if (! $setting || ! ($current['enabled'] ?? false)
                    || ! ($current['sync_on_sign_in'] ?? false)
                    || ! hash_equals(strtolower($identity->tenantId), strtolower((string) ($current['tenant_id'] ?? '')))) {
                    return;
                }

                $account = EmployeeIdentityAccount::query()
                    ->forProvider(AccountProvider::Microsoft365)
                    ->active()
                    ->where('external_id', strtolower($identity->objectId))
                    ->where('user_id', $currentUser->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $account) {
                    return;
                }

                $lockedUser = User::query()->whereKey($currentUser->getKey())->lockForUpdate()->first();
                if (! $lockedUser?->isActive() || $lockedUser->email_verified_at === null) {
                    return;
                }

                $addresses = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), [
                    $account->principal, $account->email,
                ]);
                if (! in_array(strtolower(trim($identity->principal)), $addresses, true)) {
                    return;
                }

                $tenantId = strtolower($identity->tenantId);
                if ($account->tenant_id === null) {
                    // Existing pre-provisioned accounts predate the tenant column.
                    // Only the verified, same configured Add-in tenant may bind them.
                    if (! hash_equals($tenantId, strtolower((string) config('outlook_addin.entra.tenant_id', '')))) {
                        return;
                    }

                    $account->forceFill(['tenant_id' => $tenantId])->save();

                } elseif (! hash_equals($tenantId, strtolower((string) $account->tenant_id))) {
                    return;
                }

                // Identity binding and the durable queue insert commit together.
                $this->queue();
            });
        } catch (Throwable) {
            $this->logDispatchFailure();
        }
    }

    private function logDispatchFailure(): void
    {
        // Queue exceptions can contain connection credentials or serialized payloads.
        Log::warning('Microsoft-Geraetesync konnte nicht eingeplant werden. Asynchrone Geraete-Queue und Einstellungen pruefen.', [
            'error_code' => 'microsoft_device_sync_dispatch_failed',
        ]);
    }
}
